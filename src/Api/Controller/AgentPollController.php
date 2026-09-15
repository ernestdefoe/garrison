<?php

namespace ErnestDefoe\Garrison\Api\Controller;

use ErnestDefoe\Garrison\Agent\Gateway;
use ErnestDefoe\Garrison\Agent\TokenGuard;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The endpoint an agent long-polls.
 *
 * 🚨 Deliberately NOT a Flarum API resource and not behind Flarum's session
 * middleware. An agent is not a user: it has no session, no CSRF token and no
 * cookie, and routing it through the machinery built for browsers would mean
 * either weakening that machinery or teaching the agent to pretend. It
 * authenticates with a bearer token, on its own route, and that is all.
 */
class AgentPollController implements RequestHandlerInterface
{
    public function __construct(
        protected TokenGuard $guard,
        protected Gateway $gateway
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $agent = $this->guard->authenticate($request);

        if ($agent === null) {
            // 🚨 No detail. "Unknown agent" and "wrong secret" must look
            // identical, or the response is an oracle for which agent ids
            // exist.
            return new JsonResponse(['error' => 'unauthorised'], 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        // The poll carries the agent's own report, so a healthy agent needs no
        // second request to stay current: one round trip is liveness, status
        // and stats together.
        $this->gateway->touch($agent, (array) ($body['info'] ?? []));

        foreach ((array) ($body['servers'] ?? []) as $report) {
            if (is_array($report)) {
                $this->gateway->recordStatus($agent, $report);
            }
        }

        foreach ((array) ($body['results'] ?? []) as $result) {
            if (! is_array($result) || ! isset($result['id'])) {
                continue;
            }

            $this->gateway->recordResult(
                $agent,
                (int) $result['id'],
                (bool) ($result['ok'] ?? false),
                isset($result['data']) && is_array($result['data']) ? $result['data'] : null,
                $result['errorCode'] ?? null,
                $result['errorMessage'] ?? null
            );
        }

        // Only now hold the connection. Reporting first means a restart is
        // recorded even if the poll window is cut short by a proxy.
        $commands = $this->gateway->awaitCommands($agent);

        return new JsonResponse([
            'commands' => array_map(static fn ($c) => [
                'id' => $c->id,
                'verb' => $c->verb,
                'server' => $c->server_ref,
                'params' => $c->paramsArray(),
            ], $commands),
            'pollSeconds' => Gateway::POLL_SECONDS,
        ]);
    }
}
