<?php

namespace ErnestDefoe\Garrison\Console;

use ErnestDefoe\Garrison\Game\Artwork;
use ErnestDefoe\Garrison\Health\Ladder;
use ErnestDefoe\Garrison\Model\Server;
use Flarum\Console\AbstractCommand;
use Flarum\User\User;

/**
 * Runs the remediation ladder over every server.
 *
 * 🚨 Signature is `fire(): int` — Flarum's AbstractCommand declares it
 * abstract with that return type, and `: void` is a fatal at class load that
 * kills the ENTIRE console with exit 255 and no output, while the web keeps
 * serving 200. Learned the hard way on this extension's first install.
 */
class HealthCommand extends AbstractCommand
{
    public function __construct(
        protected Ladder $ladder,
        protected Artwork $artwork
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('garrison:health')
            ->setDescription('Check reported server health and act on it');
    }

    protected function fire(): int
    {
        /**
         * 🚨 The ladder's commands are attributed to the actor that owns the
         * forum, and marked source=health in the audit log. An automatic
         * restart must be as traceable as a human one — "who restarted my
         * server at 3am" has to have an answer, and "nobody, the system did,
         * here is why" is that answer.
         */
        $actor = User::query()->where('id', 1)->first();

        if ($actor === null) {
            $this->error('No actor available to attribute health actions to.');

            return static::FAILURE;
        }

        $acted = 0;

        Server::query()->each(function (Server $server) use ($actor, &$acted) {
            $what = $this->ladder->evaluate($server, $actor);

            if ($what !== null) {
                $this->info($server->ref . ': ' . $what);
                $acted++;
            }
        });

        /**
         * 🚨 Artwork rides on the same tick rather than having its own
         * schedule. A server that reports a known game gets its logo without
         * anybody finding a button — which is how every other game panel
         * behaves, and what an operator expects. Bounded by icon_attempts so a
         * game whose artwork 404s is not fetched every minute for ever.
         */
        $got = $this->artwork->backfill();

        if ($got > 0) {
            $this->info('fetched artwork for ' . $got . ' server(s)');
        }

        if ($acted === 0 && $got === 0) {
            $this->info('Nothing to do.');
        }

        return static::SUCCESS;
    }
}
