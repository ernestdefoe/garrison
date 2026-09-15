<?php

namespace ErnestDefoe\Garrison\Api\Controller;

use ErnestDefoe\Garrison\Game\Marks;
use ErnestDefoe\Garrison\Model\Server;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The one endpoint every surface reads.
 *
 * 🚨 ONE query, one response, for the whole page — whatever is on it. The
 * status page, the sidebar widget, a Bespoke widget and a Page Builder block
 * can all be on screen together, and they must cost one request between them,
 * not one each. A request per rendered item is not a theoretical worry here:
 * it exhausted a database connection cap and 500'd a whole forum once already.
 */
class ListServersController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        $query = Server::query()->with('agent')->orderBy('name');

        /**
         * 🚨 `is_public` on the server is the ONLY thing that decides whether
         * a server can be seen to exist — by anybody, guests included. There
         * is deliberately no second permission gating the list.
         *
         * Two switches for one outcome is how a control ends up doing nothing:
         * an operator ticks "public" on a server, sees no change because a
         * permission they never heard of is unset, and concludes the feature
         * is broken. One switch, in the place they were already looking.
         *
         * `garrison.view` therefore means something narrower and honest: see
         * the servers that are NOT public. Staff.
         *
         * Filtered in the QUERY, not in the view — a private server is then
         * never in the payload at all, so no later template bug can leak one.
         */
        if (! $actor->hasPermission('garrison.view')) {
            $query->where('is_public', true);
        }

        $servers = $query->get()->map(function (Server $server) use ($actor) {
            $row = [
                'id' => $server->id,
                'name' => $server->name,
                'driver' => $server->driver,
                'state' => $server->state,
                'detail' => $server->state_detail,
                'stale' => $server->isStale(),
                'playersOnline' => $server->players_online,
                'playersMax' => $server->players_max,
                'runningSince' => $server->running_since?->toIso8601String(),
                'lastStatusAt' => $server->last_status_at?->toIso8601String(),
                'agentLate' => $server->agent?->isLate() ?? true,

                // 🚨 Resolved server-side, once. Every widget host and the
                // status page then draw the same thing without each
                // re-implementing the "custom, else mark, else monogram"
                // ladder — which is how three surfaces end up disagreeing
                // about what a server looks like.
                /**
                 * 🚨 Health is a SEPARATE field from state, and that is the
                 * product's whole thesis in one line of JSON. "running" and
                 * "players can actually get in" are different facts; conflating
                 * them is what let a server sit unjoinable for twenty hours
                 * while every dashboard showed green.
                 */
                'health' => $server->health_state,
                'healthSummary' => $server->health_summary,
                'needsAttention' => (bool) $server->needs_attention,
                'autoRemediate' => (bool) $server->auto_remediate,

                'game' => $server->game,
                'iconUrl' => $server->icon_url,
                'mark' => Marks::forGame($server->game) ?? Marks::FALLBACK,
                'monogram' => Marks::monogram($server->name),
            ];

            // 🚨 Stats only where the source says they are the kernel's own
            // accounting. `ps` numbers double-count shared pages across a
            // process tree, and a figure presented as exact when it is an
            // estimate is worse than no figure — somebody will size a host
            // from it.
            if ($server->cpu_percent !== null) {
                $row['cpuPercent'] = round($server->cpu_percent, 2);
                $row['memoryBytes'] = $server->memory_bytes;
                $row['memoryLimit'] = $server->memory_limit;
                $row['statsSource'] = $server->stats_source;
                $row['statsApproximate'] = $server->stats_source === 'ps';
            }

            /**
             * 🚨 Probe DETAIL is staff-only. A failing check says things like
             * "UDP 2457 has 9600 bytes queued" — port numbers, log patterns,
             * internal addresses. Useful to an operator, and reconnaissance to
             * anybody else. The health STATE is public; the reasons are not.
             */
            if ($actor->hasPermission('garrison.view') || $actor->hasPermission('garrison.manage')) {
                $row['healthChecks'] = $server->failingChecks();
            }

            if ($server->joinDetailsVisibleTo($actor)) {
                $row['joinAddress'] = $server->join_address;
                $row['joinPassword'] = $server->join_password;
                $row['joinCode'] = $server->join_code;
            }

            $row['canControl'] = $actor->hasPermission('garrison.control') || $actor->hasPermission('garrison.manage');
            $row['canConsole'] = $actor->hasPermission('garrison.console') || $actor->hasPermission('garrison.manage');

            return $row;
        })->values()->all();

        return new JsonResponse(['data' => $servers]);
    }
}
