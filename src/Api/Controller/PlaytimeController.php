<?php

namespace ErnestDefoe\Garrison\Api\Controller;

use ErnestDefoe\Garrison\Model\Identity;
use ErnestDefoe\Garrison\Model\PlaySession;
use ErnestDefoe\Garrison\Model\Server;
use ErnestDefoe\Garrison\Players\Tracker;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What somebody has played, and who plays a server most.
 *
 * 🚨 AN ENDPOINT OF ITS OWN, NOT AN ATTRIBUTE ON THE USER RESOURCE.
 *
 * Hanging playtime off Flarum's user serializer is the obvious move and it is
 * the expensive one: every user in every payload carries one, and a discussion
 * page serializes dozens. That is a query per rendered item, which is exactly
 * the shape that once exhausted a database connection cap and 500'd a whole
 * forum. A profile is viewed one at a time; one request when somebody opens it
 * costs less than a join on every page of the site.
 */
class PlaytimeController implements RequestHandlerInterface
{
    /**
     * 🚨 Ten. A leaderboard is a thing people look at, not a report — and an
     * unbounded one on a busy server is a thousand rows nobody scrolls, sent
     * to every visitor.
     */
    public const LEADERBOARD_SIZE = 10;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        return match ((string) $request->getAttribute('routeName')) {
            'garrison.playtime.user' => $this->forUser($actor, $request),
            'garrison.playtime.server' => $this->forServer($actor, $request),
            default => new JsonResponse(['errors' => [['code' => 'not_found']]], 404),
        };
    }

    /**
     * One person's characters and what they have played.
     */
    protected function forUser(User $actor, ServerRequestInterface $request): ResponseInterface
    {
        $user = User::query()->find((int) ($request->getQueryParams()['id'] ?? 0));

        if ($user === null) {
            return new JsonResponse(['errors' => [['code' => 'not_found']]], 404);
        }

        /*
         * 🚨 Only servers the VIEWER may see, not the ones the subject linked.
         *
         * Somebody who verified on a private staff server must not have that
         * server's name appear on their public profile — the profile is read
         * by everybody, and the visibility rule belongs to the reader. Getting
         * this backwards would leak the existence of private servers through
         * the one page that is deliberately public.
         */
        $visible = Server::query()
            ->when(! $actor->hasPermission('garrison.view'), fn ($q) => $q->where('is_public', true))
            ->pluck('name', 'id');

        $identities = Identity::query()
            ->where('user_id', $user->id)
            ->verified()
            ->whereIn('server_id', $visible->keys())
            ->get();

        $rows = [];

        foreach ($identities as $identity) {
            $rows[] = [
                'serverId' => $identity->server_id,
                'serverName' => $visible[$identity->server_id] ?? null,
                'player' => $identity->player,
                'seconds' => $this->seconds($identity->server_id, $identity->player),
                'since' => $identity->verified_at?->toIso8601String(),
            ];
        }

        return new JsonResponse(['data' => $rows]);
    }

    /**
     * Who plays this server most.
     */
    protected function forServer(User $actor, ServerRequestInterface $request): ResponseInterface
    {
        $server = Server::query()->find((int) ($request->getQueryParams()['id'] ?? 0));

        if ($server === null || (! $server->is_public && ! $actor->hasPermission('garrison.view'))) {
            return new JsonResponse(['errors' => [['code' => 'not_found']]], 404);
        }

        /*
         * 🚨 One grouped query, not one per player.
         *
         * The naive version lists distinct players and then totals each — which
         * is fine with four of them and a hundred queries with a hundred. This
         * is the query the `seconds` column exists for: a SUM over an indexed
         * column rather than date arithmetic across every row.
         */
        $totals = PlaySession::query()
            ->where('server_id', $server->id)
            ->selectRaw('player, SUM(seconds) as total')
            ->groupBy('player')
            ->orderByDesc('total')
            ->limit(self::LEADERBOARD_SIZE)
            ->get();

        /*
         * 🚨 Plus whatever the people currently playing have played TODAY.
         *
         * `seconds` is written when a session closes, so a SUM alone counts an
         * open session as zero — and the person that understates is the one
         * currently in the game, who is the most visible name on the list and
         * the one somebody is most likely to check. "alice has played 40
         * minutes" while alice has been on for three hours is the panel
         * contradicting the game.
         *
         * One extra query for the open sessions, not one per row.
         */
        $live = $this->liveSeconds($server->id);

        foreach ($totals as $row) {
            $row->total = (int) $row->total + ($live[$row->player] ?? 0);
        }

        // Adding live time can reorder the board, and a ranking that is not
        // ranked is worse than no ranking.
        $totals = $totals->sortByDesc('total')->values();

        /*
         * 🚨 And one query for every linked account, not one per row. Same
         * reasoning; it is just easier to miss on the second list.
         */
        $linked = Identity::query()
            ->where('server_id', $server->id)
            ->verified()
            ->whereIn('player', $totals->pluck('player'))
            ->with('user')
            ->get()
            ->keyBy('player');

        $online = $server->playersOnline() ?? [];

        $rows = $totals->map(function ($row) use ($linked, $online) {
            $identity = $linked->get($row->player);

            return [
                'player' => $row->player,
                'seconds' => (int) $row->total,
                'online' => in_array($row->player, $online, true),

                /*
                 * The forum account, where the player linked one. This is the
                 * connection the whole feature exists to make — a leaderboard
                 * of in-game names is a thing any panel can show; one where the
                 * names are people you can reply to is not.
                 */
                'userId' => $identity?->user?->id,
                'username' => $identity?->user?->username,
                'displayName' => $identity?->user?->display_name,
            ];
        })->values()->all();

        return new JsonResponse(['data' => $rows]);
    }

    protected function seconds(int $serverId, string $player): int
    {
        $closed = (int) PlaySession::query()
            ->where('server_id', $serverId)
            ->where('player', $player)
            ->sum('seconds');

        return $closed + ($this->liveSeconds($serverId)[$player] ?? 0);
    }

    /**
     * Seconds played so far by everybody currently in a session.
     *
     * 🚨 Clamped the same way a closed session is. An agent that went silent
     * leaves sessions open, and the scheduler closes them — but between those
     * two moments this would report a week of playtime for somebody who played
     * twenty minutes, which is exactly the number that ruins a leaderboard.
     *
     * @return array<string, int>
     */
    protected function liveSeconds(int $serverId): array
    {
        $out = [];

        foreach (PlaySession::query()->where('server_id', $serverId)->open()->get() as $session) {
            $out[$session->player] = ($out[$session->player] ?? 0)
                + min($session->seconds(), Tracker::MAX_SESSION_SECONDS);
        }

        return $out;
    }
}
