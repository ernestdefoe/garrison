<?php

namespace ErnestDefoe\Garrison\Console;

use ErnestDefoe\Garrison\Schedule\Runner;
use Flarum\Console\AbstractCommand;
use Flarum\User\User;

/**
 * Fires whatever is due: restarts, backups, and lines sent to a console.
 *
 * 🚨 Signature is `fire(): int` — Flarum's AbstractCommand declares it abstract
 * with that return type, and `: void` is a fatal at class load that kills the
 * ENTIRE console with exit 255 and no output, while the web keeps serving 200.
 * Learned the hard way on this extension's first install; every command class
 * in this directory repeats the note because every one of them can cause it.
 */
class ScheduleCommand extends AbstractCommand
{
    public function __construct(
        protected Runner $runner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('garrison:schedule')
            ->setDescription('Queue any scheduled restarts, backups or console commands that are due');
    }

    protected function fire(): int
    {
        /**
         * 🚨 Attributed to the forum's owner and marked source=schedule, the
         * same as the health ladder's actions. An automatic restart must be as
         * traceable as a human one: "who restarted my server at 5am" needs an
         * answer, and "nobody did, this schedule did" is that answer.
         */
        $actor = User::query()->where('id', 1)->first();

        if ($actor === null) {
            $this->error('No actor available to attribute scheduled actions to.');

            return static::FAILURE;
        }

        $done = $this->runner->run($actor);

        foreach ($done as $line) {
            $this->info($line);
        }

        if ($done === []) {
            $this->info('Nothing due.');
        }

        return static::SUCCESS;
    }
}
