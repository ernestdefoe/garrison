<?php

/*
 * Garrison — run your game servers from the forum the players already live in.
 */

use ErnestDefoe\Garrison\Api\Controller\AgentPollController;
use ErnestDefoe\Garrison\Api\Controller\ListServersController;
use ErnestDefoe\Garrison\Api\Controller\QueueCommandController;
use ErnestDefoe\Garrison\Console\HealthCommand;
use ErnestDefoe\Garrison\Console\PairCommand;
use ErnestDefoe\Garrison\GarrisonServiceProvider;
use Flarum\Extend;

$extenders = [
    (new Extend\ServiceProvider())->register(GarrisonServiceProvider::class),

    (new Extend\Locales(__DIR__ . '/resources/locale')),

    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less')

        /*
         * 🚨 The status page needs registering on the PHP side as well as in
         * the JS router. Without this, clicking through to /garrison inside
         * the app works — Mithril handles it client-side — and loading the
         * same URL directly, or refreshing on it, or following a link anybody
         * shared, returns a bare 404 from the server, because nothing there
         * knows to serve the forum shell for that path.
         *
         * The kind of bug that never shows up while you are developing,
         * because you always arrive by clicking.
         */
        ->route('/garrison', 'garrison'),

    (new Extend\Console())
        ->command(PairCommand::class)
        ->command(HealthCommand::class)

        /*
         * 🚨 Every minute, not every five. The whole argument for this feature
         * is the gap between a server breaking and somebody noticing — on the
         * outage that prompted it, twenty hours. A ladder that needs three
         * consecutive unready readings before acting already waits three
         * minutes; a five-minute schedule would make that fifteen.
         */
        ->schedule(HealthCommand::class, fn ($event) => $event->everyMinute()->withoutOverlapping()),

    /*
     * 🚨 The agent's route is exempt from CSRF, through core's own extender.
     *
     * CSRF protects a BROWSER session from being driven by another site. An
     * agent has no session and no cookie to ride on — it presents a bearer
     * token and nothing else — so there is no cross-site request to forge and
     * the check can only ever reject it. Predicted in AgentPollController's
     * docblock and still shipped broken: the first live agent polled for two
     * minutes getting `csrf_token_mismatch` every time.
     *
     * Exempting ONE named route, not removing the middleware. Removing it
     * would disarm CSRF for the whole forum to fix one endpoint.
     */
    (new Extend\Csrf())->exemptRoute('garrison.agent.poll'),

    (new Extend\Routes('api'))
        /*
         * 🚨 The agent route is NOT an api resource and does not sit behind
         * the machinery built for browsers. An agent has no session, no cookie
         * and no CSRF token; routing it through that would mean either
         * weakening it or teaching the agent to pretend to be a browser.
         */
        ->post('/garrison/agent/poll', 'garrison.agent.poll', AgentPollController::class)

        ->get('/garrison/servers', 'garrison.servers', ListServersController::class)
        ->post('/garrison/servers/{id}/command', 'garrison.command', QueueCommandController::class),

    /*
     * Four permissions, and console is separate from control on purpose.
     *
     * 🚨 Restarting a server is an operational act. Sending a line to a game
     * console is arbitrary in-game authority — op, ban, give, teleport — and
     * an operator may very reasonably want somebody who can do the first and
     * not the second. Folding them together is a decision that cannot be
     * undone by configuration.
     */
    (new Extend\Policy()),
];

/*
 * 🚨 Widget hosts are OPTIONAL COLLABORATORS, registered conditionally.
 *
 * Garrison must work with none of the four installed and with all four
 * installed. The JS side resolves fof/forum-widgets-core and Bespoke through
 * their own runtime registries; only Page Builder needs a PHP extender,
 * because it is the one whose contract has a server half.
 *
 * class_exists rather than an extension-status lookup: this file is evaluated
 * before the extension manager is usable, and a hard reference to a class that
 * may not be installed is a fatal at boot for everybody.
 */
if (class_exists(\Ernestdefoe\PageBuilder\Extend\PageBuilderBlock::class)) {
    $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(
        \ErnestDefoe\Garrison\Widget\ServerStatusBlock::class
    );
}

return $extenders;
