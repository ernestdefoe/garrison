<?php

namespace ErnestDefoe\Garrison\Agent;

use Carbon\Carbon;
use ErnestDefoe\Garrison\Model\Command;
use ErnestDefoe\Garrison\Model\Server;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;

/**
 * Queues a command for an agent, having first decided whether it may be asked
 * for at all.
 *
 * 🚨 This class is the forum's half of the security boundary and it repeats
 * the agent's verb set on purpose.
 *
 * The agent refuses anything outside its closed set no matter what reaches it —
 * that is the guarantee, and it does not depend on this file being correct.
 * But a forum that will happily queue `shell.exec` and let the agent reject it
 * has already lost something worth keeping: the audit log fills with attempts
 * nobody can distinguish from bugs, and the UI can offer controls that cannot
 * work. Refusing here too means the two halves agree, and a disagreement
 * between them is a signal rather than noise.
 */
class Dispatcher
{
    /**
     * Verbs the FORUM may queue. Deliberately a subset of what the agent
     * implements: agent.ping and agent.info are the agent's own housekeeping
     * and are not things a person clicks.
     */
    public const QUEUEABLE = [
        'server.status',
        'server.start',
        'server.stop',
        'server.restart',
        'server.stats',
        'console.tail',
        'console.send',
    ];

    /**
     * Verbs that change the world, as opposed to reading it. They need a
     * heavier permission and they are the ones the audit log exists for.
     */
    public const MUTATING = [
        'server.start',
        'server.stop',
        'server.restart',
        'console.send',
    ];

    public function __construct(
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * Queue one command, or throw.
     */
    public function queue(User $actor, Server $server, string $verb, array $params = [], string $source = 'user'): Command
    {
        if (! in_array($verb, self::QUEUEABLE, true)) {
            // 🚨 Not an assertion or a 500. A verb the forum does not queue is
            // a refusal with a reason, because the same path is reachable from
            // the API by anybody with an account.
            throw new ValidationException([
                'verb' => $this->translator->trans('ernestdefoe-garrison.api.errors.unknown_verb'),
            ]);
        }

        $this->assertPermitted($actor, $server, $verb);

        $command = new Command();
        $command->agent_id = $server->agent_id;
        $command->server_ref = $server->ref;
        $command->verb = $verb;
        $command->params = $params === [] ? null : json_encode($params);
        $command->status = 'queued';
        $command->actor_id = $actor->id;
        $command->source = $source;
        $command->created_at = Carbon::now();
        $command->save();

        return $command;
    }

    /**
     * 🚨 Written as one method, called from one place, so there is no surface
     * that can queue a command without passing through it. The commonest bug
     * of this kind is a second call site added later that forgets the check —
     * and it is invisible, because the feature works.
     */
    protected function assertPermitted(User $actor, Server $server, string $verb): void
    {
        $needed = in_array($verb, self::MUTATING, true)
            ? 'garrison.control'
            : 'garrison.view';

        if ($actor->hasPermission('garrison.manage')) {
            return;
        }

        if (! $actor->hasPermission($needed)) {
            throw new ValidationException([
                'verb' => $this->translator->trans('ernestdefoe-garrison.api.errors.not_permitted'),
            ]);
        }

        // console.send deserves its own gate rather than riding along with
        // restart. Sending a line to a game console is arbitrary in-game
        // authority — ban, op, give items — and an operator may reasonably
        // want somebody who can restart a server but not do that.
        if ($verb === 'console.send' && ! $actor->hasPermission('garrison.console')) {
            throw new ValidationException([
                'verb' => $this->translator->trans('ernestdefoe-garrison.api.errors.not_permitted'),
            ]);
        }
    }

    /**
     * Commands that were delivered but never answered.
     *
     * An agent that takes a command and then dies leaves a row that would
     * otherwise say "delivered" for ever, which reads as "in progress" on a
     * dashboard and makes an operator wait for something that will never
     * happen. Called from the scheduler.
     */
    public function expireStale(int $olderThanSeconds = 300): int
    {
        return Command::query()
            ->where('status', 'delivered')
            ->where('delivered_at', '<', Carbon::now()->subSeconds($olderThanSeconds))
            ->update([
                'status' => 'expired',
                'error_code' => 'timeout',
                'completed_at' => Carbon::now(),
            ]);
    }
}
