<?php

namespace ErnestDefoe\Garrison\Api\Controller;

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

        // Somebody who cannot administer Garrison sees public servers only.
        // Not a filter applied in the view — a filter applied in the query, so
        // a private server is never in the payload to be leaked by a template
        // bug later.
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
