<?php

namespace ErnestDefoe\Garrison\Console;

use Carbon\Carbon;
use ErnestDefoe\Garrison\Model\Command;
use ErnestDefoe\Garrison\Model\Server;
use Flarum\Console\AbstractCommand;

/**
 * Queues a scheduled backup for every server that wants one.
 *
 * 🚨 Queues, rather than backing up directly. The forum has no access to a
 * game host's disk and must not have any — the agent is the only thing that
 * touches files, and a scheduled backup is the same verb, through the same
 * audit log, as one somebody clicked.
 */
class BackupCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->setName('garrison:backup')
            ->setDescription('Queue a backup for every server on a backup schedule');
    }

    protected function fire(): int
    {
        $due = 0;

        Server::query()
            ->where('backup_every_hours', '>', 0)
            ->each(function (Server $server) use (&$due) {
                if (! $this->isDue($server)) {
                    return;
                }

                /*
                 * 🚨 Skipped, not queued, when one is already waiting.
                 *
                 * An agent that is offline or slow leaves its commands queued.
                 * Without this check a nightly schedule on a host that has been
                 * down for a week comes back to seven backups running at once,
                 * on a disk that is probably why it was down.
                 */
                $pending = Command::query()
                    ->where('agent_id', $server->agent_id)
                    ->where('server_ref', $server->ref)
                    ->where('verb', 'backup.create')
                    ->whereIn('status', ['queued', 'delivered'])
                    ->exists();

                if ($pending) {
                    return;
                }

                $command = new Command();
                $command->agent_id = $server->agent_id;
                $command->server_ref = $server->ref;
                $command->verb = 'backup.create';
                $command->status = 'queued';
                $command->source = 'schedule';
                $command->created_at = Carbon::now();
                $command->save();

                $server->backup_queued_at = Carbon::now();
                $server->save();

                $this->info('queued a backup for ' . $server->ref);
                $due++;
            });

        if ($due === 0) {
            $this->info('No backups due.');
        }

        return static::SUCCESS;
    }

    protected function isDue(Server $server): bool
    {
        if ($server->backup_queued_at === null) {
            return true;
        }

        return $server->backup_queued_at->lt(
            Carbon::now()->subHours((int) $server->backup_every_hours)
        );
    }
}
