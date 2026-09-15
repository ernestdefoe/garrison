<?php

namespace ErnestDefoe\Garrison\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Garrison\Agent\TokenGuard;
use ErnestDefoe\Garrison\Game\Artwork;
use ErnestDefoe\Garrison\Game\Catalog;
use ErnestDefoe\Garrison\Model\GarrisonAgent;
use ErnestDefoe\Garrison\Model\Identity;
use ErnestDefoe\Garrison\Model\Incident;
use ErnestDefoe\Garrison\Model\Schedule;
use ErnestDefoe\Garrison\Model\Server;
use ErnestDefoe\Garrison\Health\Heartbeat;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Everything the admin screen needs, behind one permission.
 *
 * 🚨 Every method starts by asserting `garrison.manage`, and none of them take
 * the permission as a parameter or infer it from the route. A controller that
 * checks in some branches and not others is the shape this class exists to
 * avoid — and it manages pairing tokens, which are the keys to every game host
 * a forum controls.
 */
class AdminController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected Factory $filesystem,
        protected Artwork $artwork,
        protected Heartbeat $heartbeat
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertPermission($actor->hasPermission('garrison.manage'));

        $action = (string) ($request->getAttribute('routeName') ?? '');

        return match ($action) {
            'garrison.admin.state' => $this->state(),
            'garrison.admin.pair' => $this->pair($request),
            'garrison.admin.unpair' => $this->unpair($request),
            'garrison.admin.server' => $this->updateServer($request),
            'garrison.admin.icon' => $this->uploadIcon($request),
            'garrison.admin.fetchIcon' => $this->fetchIcon($request),
            'garrison.admin.scheduleCreate' => $this->createSchedule($request),
            'garrison.admin.scheduleUpdate' => $this->updateSchedule($request),
            'garrison.admin.scheduleDelete' => $this->deleteSchedule($request),
            'garrison.admin.unlink' => $this->unlinkIdentity($request),
            default => new JsonResponse(['errors' => [['code' => 'not_found']]], 404),
        };
    }

    /** Everything on one request, for the same reason the forum side does. */
    protected function state(): ResponseInterface
    {
        return new JsonResponse([
            'agents' => GarrisonAgent::query()->orderBy('name')->get()->map(fn (GarrisonAgent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'version' => $a->version,
                'os' => $a->os,
                'arch' => $a->arch,
                'drivers' => $a->driverList(),
                'lastSeenAt' => $a->last_seen_at?->toIso8601String(),
                'late' => $a->isLate(),
                'servers' => $a->servers()->count(),
            ])->values()->all(),

            'servers' => Server::query()->orderBy('name')->get()->map(fn (Server $s) => [
                'id' => $s->id,
                'agentId' => $s->agent_id,
                'ref' => $s->ref,
                'name' => $s->name,
                'game' => $s->game,
                'driver' => $s->driver,
                'state' => $s->state,
                'health' => $s->health_state,
                'isPublic' => (bool) $s->is_public,
                'autoRemediate' => (bool) $s->auto_remediate,
                'needsAttention' => (bool) $s->needs_attention,
                'joinGroupId' => $s->join_group_id,
                'joinAddress' => $s->join_address,
                'joinPassword' => $s->join_password,
                'joinCode' => $s->join_code,
                'iconUrl' => $s->icon_url,
                'backupEveryHours' => (int) $s->backup_every_hours,
                'gameName' => Catalog::name($s->game),
                'canFetchLogo' => Catalog::artworkCandidates($s->game) !== [],
            ])->values()->all(),

            /*
             * 🚨 Whether Garrison's machinery is running at all, measured
             * rather than assumed. See Heartbeat: a forum with no scheduler
             * runs none of this and looks entirely normal, and a forum with no
             * queue worker loses every notification in total silence. The
             * panel would otherwise stay green through the outage this product
             * exists to catch.
             */
            'health' => $this->heartbeat->report(),

            'schedules' => Schedule::query()->orderBy('server_id')->orderBy('at_minute')->get()
                ->map(fn (Schedule $s) => $this->scheduleRow($s))->values()->all(),

            /*
             * 🚨 Who is linked to whom, because somebody has to be able to
             * undo it.
             *
             * A member can unlink their OWN character, and that covers the
             * honest cases. What it does not cover is the one an operator
             * actually gets asked about: somebody who linked a character,
             * left the community, and whose name the next player now has —
             * or a link made in error by somebody who has since lost their
             * forum account. Without this, the answer to "can you unlink
             * that?" is no, and the only fix is the database.
             *
             * Unverified claims are included and marked. They are pending
             * rather than wrong, and an operator looking at this list is
             * usually trying to work out why somebody's link did not take.
             */
            'identities' => Identity::query()->with('user')->orderBy('server_id')->get()
                ->map(fn (Identity $i) => [
                    'id' => $i->id,
                    'serverId' => $i->server_id,
                    'player' => $i->player,
                    'verified' => $i->isVerified(),
                    'userId' => $i->user?->id,
                    'username' => $i->user?->username,
                    'displayName' => $i->user?->display_name,
                    'since' => $i->verified_at?->toIso8601String(),
                ])->values()->all(),

            'incidents' => Incident::query()->latest('id')->limit(25)->get()->map(fn (Incident $i) => [
                'id' => $i->id,
                'serverId' => $i->server_id,
                'status' => $i->status,
                'cause' => $i->cause,
                'restarts' => $i->restarts,
                'startedAt' => $i->started_at?->toIso8601String(),
                'resolvedAt' => $i->resolved_at?->toIso8601String(),
                'actions' => $i->actionList(),
            ])->values()->all(),
        ]);
    }

    /**
     * Pair a host: create the agent and mint its token.
     *
     * 🚨 The plaintext token is returned EXACTLY ONCE, here, and stored
     * nowhere. The screen has to say so, because an operator who assumes they
     * can come back for it later will close the dialog and have to re-pair.
     */
    protected function pair(ServerRequestInterface $request): ResponseInterface
    {
        $name = trim((string) (($request->getParsedBody() ?? [])['name'] ?? ''));

        if ($name === '') {
            throw new ValidationException([
                'name' => $this->translator->trans('ernestdefoe-garrison.api.errors.name_required'),
            ]);
        }

        $agent = new GarrisonAgent();
        $agent->name = $name;
        $agent->token_hash = '';
        $agent->created_at = Carbon::now();
        $agent->save();

        // The token embeds the row id, so it cannot be minted until the row
        // exists. Saving twice is the honest cost; guessing the next id would
        // race anybody else pairing at the same moment.
        [$plaintext, $hash] = TokenGuard::mint($agent->id);
        $agent->token_hash = $hash;
        $agent->save();

        return new JsonResponse(['id' => $agent->id, 'name' => $agent->name, 'token' => $plaintext], 201);
    }

    /**
     * 🚨 Unpairing deletes the agent's SERVERS from the forum's cache too, and
     * says so on screen. Leaving them behind would show an operator a list of
     * servers that can never update again and that no button can affect — the
     * worst kind of stale, because it looks live.
     */
    protected function unpair(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $agent = GarrisonAgent::query()->find($id);

        if ($agent === null) {
            return new JsonResponse(['errors' => [['code' => 'not_found']]], 404);
        }

        Server::query()->where('agent_id', $agent->id)->delete();
        $agent->delete();

        return new JsonResponse(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function scheduleRow(Schedule $s): array
    {
        return [
            'id' => $s->id,
            'serverId' => $s->server_id,
            'kind' => $s->kind,
            'atMinute' => (int) $s->at_minute,
            'days' => $s->days,
            'timezone' => $s->timezone,
            'payload' => $s->payload,
            'warnMinutes' => (int) $s->warn_minutes,
            'warnPayload' => $s->warn_payload,
            'enabled' => (bool) $s->enabled,
            'lastRunAt' => $s->last_run_at?->toIso8601String(),
        ];
    }

    protected function createSchedule(ServerRequestInterface $request): ResponseInterface
    {
        $server = Server::query()->find((int) ($request->getQueryParams()['id'] ?? 0));

        if ($server === null) {
            return new JsonResponse(['errors' => [['code' => 'not_found']]], 404);
        }

        $schedule = new Schedule();
        $schedule->server_id = $server->id;

        /*
         * 🚨 Created with a real default rather than empty, and DISABLED.
         *
         * A new row with at_minute 0 and no days would be a schedule that does
         * nothing, or worse fires at midnight because the mask defaulted to
         * every day — before the operator has finished filling it in. So it
         * arrives as a sensible 05:00 daily restart, switched off, and nothing
         * happens until somebody turns it on having read what it says.
         */
        $schedule->kind = 'restart';
        $schedule->at_minute = 5 * 60;
        $schedule->days = '1111111';
        $schedule->timezone = $this->defaultTimezone();
        $schedule->warn_minutes = 0;
        $schedule->enabled = false;
        $schedule->created_at = Carbon::now();
        $schedule->updated_at = Carbon::now();
        $schedule->save();

        return new JsonResponse($this->scheduleRow($schedule), 201);
    }

    /**
     * 🚨 The forum's own timezone if it has one, and UTC if it does not —
     * never the PHP process's, which on a shared host is whatever the provider
     * set and is not something the operator can see. A new schedule that
     * quietly defaults to a zone nobody chose is how a restart lands at the
     * wrong hour and reads as a bug.
     */
    protected function defaultTimezone(): string
    {
        $tz = (string) resolve(\Flarum\Settings\SettingsRepositoryInterface::class)->get('ernestdefoe-garrison.timezone');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    protected function updateSchedule(ServerRequestInterface $request): ResponseInterface
    {
        $schedule = Schedule::query()->find((int) ($request->getQueryParams()['id'] ?? 0));

        if ($schedule === null) {
            return new JsonResponse(['errors' => [['code' => 'not_found']]], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        /**
         * 🚨 VALIDATED HERE, not only in the browser.
         *
         * Every one of these fields ends up in date arithmetic that decides
         * when somebody's server restarts. A minute of 9999 or a mask of
         * "yes please" does not throw — it produces a schedule that never
         * fires, or fires at a time nobody can explain, and the admin screen
         * shows it sitting there looking configured. The browser's own checks
         * are for helpfulness; these are the ones that hold.
         */
        if (array_key_exists('kind', $body)) {
            $kind = (string) $body['kind'];
            $schedule->kind = in_array($kind, Schedule::KINDS, true) ? $kind : $schedule->kind;
        }

        if (array_key_exists('atMinute', $body)) {
            $schedule->at_minute = max(0, min(24 * 60 - 1, (int) $body['atMinute']));
        }

        if (array_key_exists('days', $body)) {
            $days = preg_replace('/[^01]/', '', (string) $body['days']);
            // A mask of the wrong length would index out of range in
            // runsOn(); a mask of all zeroes is a schedule that can never
            // fire, which is what `enabled = false` is for and is not what
            // somebody dragging day toggles meant.
            $schedule->days = strlen($days) === 7 && str_contains($days, '1') ? $days : $schedule->days;
        }

        if (array_key_exists('timezone', $body)) {
            $tz = (string) $body['timezone'];
            $schedule->timezone = in_array($tz, timezone_identifiers_list(), true) ? $tz : $schedule->timezone;
        }

        if (array_key_exists('warnMinutes', $body)) {
            // Capped at four hours: a warning further out than that is not a
            // warning, it is an announcement, and it would sit in a chat log
            // long enough that the restart it mentions is a surprise anyway.
            $schedule->warn_minutes = max(0, min(240, (int) $body['warnMinutes']));
        }

        foreach (['payload' => 'payload', 'warnPayload' => 'warn_payload'] as $in => $column) {
            if (array_key_exists($in, $body)) {
                $value = trim((string) $body[$in]);
                $schedule->$column = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('enabled', $body)) {
            $schedule->enabled = (bool) $body['enabled'];
        }

        $schedule->updated_at = Carbon::now();
        $schedule->save();

        return new JsonResponse($this->scheduleRow($schedule));
    }

    protected function deleteSchedule(ServerRequestInterface $request): ResponseInterface
    {
        $schedule = Schedule::query()->find((int) ($request->getQueryParams()['id'] ?? 0));

        if ($schedule !== null) {
            $schedule->delete();
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * 🚨 Removes the link, and nothing else.
     *
     * It does not delete the play sessions: those record what happened in the
     * game, which is true whether or not a forum account is attached to it.
     * Deleting them would rewrite the server's leaderboard because somebody's
     * forum link was wrong, and the next person to link that name would find
     * their history had been thrown away.
     */
    protected function unlinkIdentity(ServerRequestInterface $request): ResponseInterface
    {
        $identity = Identity::query()->find((int) ($request->getQueryParams()['id'] ?? 0));

        if ($identity !== null) {
            $identity->delete();
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Server settings an operator owns — as opposed to the ones the agent
     * reports, which are never editable here.
     */
    protected function updateServer(ServerRequestInterface $request): ResponseInterface
    {
        $server = Server::query()->find((int) ($request->getQueryParams()['id'] ?? 0));

        if ($server === null) {
            return new JsonResponse(['errors' => [['code' => 'not_found']]], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        /**
         * 🚨 An allowlist, not a mass assign. `state`, `health_state`, `ref`
         * and `agent_id` are the agent's to report — letting an admin screen
         * write them would put the forum's idea of a server permanently out of
         * step with the host, with no way to tell which was lying.
         */
        foreach (['name', 'join_address', 'join_password', 'join_code'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = trim((string) $body[$field]);
                $server->$field = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('is_public', $body)) {
            $server->is_public = (bool) $body['is_public'];
        }

        if (array_key_exists('auto_remediate', $body)) {
            $server->auto_remediate = (bool) $body['auto_remediate'];
        }

        if (array_key_exists('backup_every_hours', $body)) {
            // Clamped rather than trusted: a typo of 0.5 or 100000 should not
            // become a backup every few seconds or one every eleven years.
            $hours = (int) $body['backup_every_hours'];
            $server->backup_every_hours = max(0, min(24 * 30, $hours));
        }

        if (array_key_exists('join_group_id', $body)) {
            $group = $body['join_group_id'];
            $server->join_group_id = ($group === null || $group === '') ? null : (int) $group;
        }

        /**
         * 🚨 Clearing needs_attention is a DELIBERATE act with a button behind
         * it, and the only way it ever gets cleared. The ladder never re-arms
         * itself: it gave up because restarting did not help, and a server
         * that happens to look fine one poll later has not proved the fault is
         * gone. A person says "I have looked".
         */
        if (! empty($body['clear_attention'])) {
            $server->needs_attention = false;
            $server->unready_polls = 0;
            $server->unready_since = null;
            $server->last_remediation_at = null;
        }

        $server->updated_at = Carbon::now();
        $server->save();

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Fetch the game's real logo now, on request.
     *
     * The same service the scheduler uses, so a manual fetch and an automatic
     * one cannot drift apart — one of them being subtly different is how a
     * "try again" button becomes the only one that works.
     */
    protected function fetchIcon(ServerRequestInterface $request): ResponseInterface
    {
        $server = Server::query()->find((int) ($request->getQueryParams()['id'] ?? 0));

        if ($server === null) {
            return new JsonResponse(['errors' => [['code' => 'not_found']]], 404);
        }

        // A manual fetch clears the attempt count: the operator is explicitly
        // asking, and refusing because an automatic attempt failed yesterday
        // would be a button that does nothing.
        $server->icon_attempts = 0;
        $server->save();

        /**
         * An operator-supplied URL covers every game Garrison has no catalogue
         * entry for — Minecraft included, which has no Steam page and is the
         * most common dedicated server there is. Downloaded and kept, exactly
         * like the catalogue path: never a hotlink.
         */
        $given = trim((string) (($request->getParsedBody() ?? [])['url'] ?? ''));

        if ($given !== '') {
            $reason = null;
            $url = $this->artwork->fetchFrom($server, $given, $reason);

            if ($url === null) {
                throw new ValidationException([
                    'icon' => $this->translator->trans('ernestdefoe-garrison.api.errors.' . ($reason ?: 'fetch_failed')),
                ]);
            }

            return new JsonResponse(['iconUrl' => $url]);
        }

        $url = $this->artwork->fetch($server);

        if ($url === null) {
            throw new ValidationException([
                'icon' => $this->translator->trans('ernestdefoe-garrison.api.errors.fetch_failed'),
            ]);
        }

        return new JsonResponse(['iconUrl' => $url]);
    }

    /**
     * Upload an icon for a server.
     *
     * 🚨 An UPLOAD, never a URL field. Every image setting that only accepts a
     * URL ends up pointing at somebody else's server, and it rots — the image
     * vanishes months later and the forum owner has no idea why. This one
     * takes a file and keeps it.
     */
    protected function uploadIcon(ServerRequestInterface $request): ResponseInterface
    {
        $server = Server::query()->find((int) ($request->getQueryParams()['id'] ?? 0));

        if ($server === null) {
            return new JsonResponse(['errors' => [['code' => 'not_found']]], 404);
        }

        /** @var UploadedFileInterface|null $file */
        $file = ($request->getUploadedFiles()['icon'] ?? null);

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException([
                'icon' => $this->translator->trans('ernestdefoe-garrison.api.errors.upload_failed'),
            ]);
        }

        /**
         * 🚨 The type is taken from the FILE, never from the client's
         * Content-Type or the filename's extension. Both are attacker-supplied
         * strings; a real image survives getimagesize and a disguised script
         * does not.
         */
        $temp = $file->getStream()->getMetadata('uri');
        $info = is_string($temp) ? @getimagesize($temp) : false;

        $allowed = [
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];

        if ($info === false || ! isset($allowed[$info[2]])) {
            throw new ValidationException([
                'icon' => $this->translator->trans('ernestdefoe-garrison.api.errors.not_an_image'),
            ]);
        }

        if ($file->getSize() > 512 * 1024) {
            throw new ValidationException([
                'icon' => $this->translator->trans('ernestdefoe-garrison.api.errors.too_large'),
            ]);
        }

        return new JsonResponse([
            'iconUrl' => $this->artwork->keep($server, $file->getStream()->getContents(), $allowed[$info[2]]),
        ]);
    }
}
