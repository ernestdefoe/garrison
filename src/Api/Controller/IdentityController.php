<?php

namespace ErnestDefoe\Garrison\Api\Controller;

use ErnestDefoe\Garrison\Model\Identity;
use ErnestDefoe\Garrison\Model\PlaySession;
use ErnestDefoe\Garrison\Model\Server;
use ErnestDefoe\Garrison\Players\Linker;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Claiming, proving and dropping an in-game identity.
 *
 * 🚨 Every method acts on the ACTOR's own identity and takes no user id from
 * the request. There is deliberately no shape of call here that links somebody
 * else's account to a player — an admin who needs to fix a mistaken link
 * removes it, and the person re-proves it.
 */
class IdentityController implements RequestHandlerInterface
{
    public function __construct(
        protected Linker $linker
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $server = Server::query()->find((int) ($request->getQueryParams()['id'] ?? 0));

        if ($server === null) {
            return new JsonResponse(['errors' => [['code' => 'not_found']]], 404);
        }

        /*
         * 🚨 The same visibility rule as everywhere else, applied before the
         * route is even considered. Without it, a member could learn that a
         * private server exists by the difference between a 404 and a
         * validation error.
         */
        if (! $server->is_public && ! $actor->hasPermission('garrison.view')) {
            return new JsonResponse(['errors' => [['code' => 'not_found']]], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $action = (string) ($request->getAttribute('routeName') ?? '');

        return match ($action) {
            'garrison.identity.claim' => $this->claim($actor, $server, $body),
            'garrison.identity.confirm' => $this->confirm($actor, $server, $body),
            'garrison.identity.unlink' => $this->unlink($actor, $server),
            default => new JsonResponse(['errors' => [['code' => 'not_found']]], 404),
        };
    }

    protected function claim($actor, Server $server, array $body): ResponseInterface
    {
        $identity = $this->linker->claim($actor, $server, (string) ($body['player'] ?? ''));

        return new JsonResponse(['data' => $this->row($identity)], 202);
    }

    protected function confirm($actor, Server $server, array $body): ResponseInterface
    {
        $identity = $this->linker->confirm($actor, $server, (string) ($body['code'] ?? ''));

        return new JsonResponse(['data' => $this->row($identity)]);
    }

    protected function unlink($actor, Server $server): ResponseInterface
    {
        $this->linker->unlink($actor, $server);

        return new JsonResponse(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Identity $identity): array
    {
        return [
            'id' => $identity->id,
            'serverId' => $identity->server_id,
            'player' => $identity->player,
            'verified' => $identity->isVerified(),

            /*
             * 🚨 Whether a code is LIVE, never the code itself and never its
             * hash. The point of the flow is that the only place to read it is
             * inside the game; an API that handed it back would make the
             * proof a formality.
             */
            'awaitingCode' => ! $identity->isVerified() && $identity->codeIsLive(),
            'attemptsLeft' => max(0, Identity::MAX_ATTEMPTS - (int) $identity->attempts),

            // Playtime comes with the identity because the two are always shown
            // together, and asking for it separately would be a second request
            // for one number.
            'seconds' => $identity->isVerified()
                ? (int) PlaySession::query()
                    ->where('server_id', $identity->server_id)
                    ->where('player', $identity->player)
                    ->sum('seconds')
                : 0,
        ];
    }
}
