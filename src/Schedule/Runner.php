<?php

namespace ErnestDefoe\Garrison\Schedule;

use Carbon\Carbon;
use ErnestDefoe\Garrison\Agent\Dispatcher;
use ErnestDefoe\Garrison\Model\Command;
use ErnestDefoe\Garrison\Model\Schedule;
use ErnestDefoe\Garrison\Model\Server;
use Flarum\User\User;
use Psr\Log\LoggerInterface;

/**
 * Turns due schedules into queued commands.
 *
 * 🚨 QUEUES, exactly as a person clicking would. It does not reach past the
 * dispatcher, so a scheduled restart is subject to the same closed verb set
 * and lands in the same audit log as a human one — "who restarted my server at
 * 5am" has an answer, and the answer names the schedule.
 */
class Runner
{
    public function __construct(
        protected Dispatcher $dispatcher,
        protected LoggerInterface $log
    ) {
    }

    /**
     * @return array<int, string> what was done, for the console command's output
     */
    public function run(User $actor, ?Carbon $now = null): array
    {
        $done = [];

        Schedule::query()
            ->where('enabled', true)
            ->with('server')
            ->each(function (Schedule $schedule) use ($actor, $now, &$done) {
                $server = $schedule->server;

                if ($server === null) {
                    return;
                }

                /*
                 * 🚨 The WARNING is checked first, and both can fire on the
                 * same tick without interfering — they have separate marks.
                 * Checking the action first and returning early would silently
                 * skip the warning on any tick where both were due.
                 */
                if ($schedule->isWarningDue($now)) {
                    if ($this->fire($actor, $server, $schedule, true)) {
                        $schedule->last_warned_at = Carbon::now();
                        $schedule->save();
                        $done[] = $server->ref . ': warned about ' . $schedule->kind;
                    }
                }

                if ($schedule->isDue($now)) {
                    if ($this->fire($actor, $server, $schedule, false)) {
                        $schedule->last_run_at = Carbon::now();
                        $schedule->save();
                        $done[] = $server->ref . ': queued ' . $schedule->kind;
                    }
                }
            });

        return $done;
    }

    /**
     * Queue one command for a schedule, or decide not to.
     *
     * Returns whether the schedule should be marked as having fired. A
     * schedule that could not produce a command is marked anyway — otherwise a
     * misconfigured row is retried every minute for the whole grace window,
     * filling the log with the same failure thirty times.
     */
    protected function fire(User $actor, Server $server, Schedule $schedule, bool $warning): bool
    {
        $command = $schedule->asCommand($warning);

        if ($command === null) {
            return true;
        }

        [$verb, $params] = $command;

        /*
         * 🚨 Skipped, not queued, when the same verb is already waiting.
         *
         * A host that has been offline for a week leaves its commands queued,
         * and without this check it comes back to seven nightly restarts at
         * once — on a machine that is probably still unwell. The same guard the
         * backup scheduler already had, generalised, because every kind of
         * scheduled work has the same failure.
         */
        $pending = Command::query()
            ->where('agent_id', $server->agent_id)
            ->where('server_ref', $server->ref)
            ->where('verb', $verb)
            ->whereIn('status', ['queued', 'delivered'])
            ->exists();

        if ($pending) {
            return false;
        }

        try {
            $this->dispatcher->queue($actor, $server, $verb, $params, 'schedule');
        } catch (\Throwable $e) {
            /*
             * 🚨 Caught, because the scheduler runs every minute and a throw
             * here takes every OTHER schedule on the forum down with it — and
             * silently, since a booted Flarum app swallows uncaught throwables
             * in the console with exit 255 and no output at all.
             *
             * The realistic cause is an actor who has lost a permission since
             * the schedule was made: a configuration problem worth a log line
             * and not worth stopping the world for.
             */
            $this->log->warning('Garrison: schedule {id} could not queue {verb}: {message}', [
                'id' => $schedule->id,
                'verb' => $verb,
                'message' => $e->getMessage(),
            ]);

            return true;
        }

        return true;
    }
}
