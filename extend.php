<?php

/*
 * Garrison — run your game servers from the forum the players already live in.
 */

use ErnestDefoe\Garrison\Api\Controller\AgentPollController;
use Flarum\Extend;

$extenders = [
    (new Extend\Locales(__DIR__ . '/resources/locale')),

    /*
     * 🚨 The agent route lives OUTSIDE the api resource layer, on purpose.
     * See AgentPollController: an agent has no session, no cookie and no CSRF
     * token, and routing it through the machinery built for browsers would
     * mean either weakening that machinery or teaching the agent to pretend
     * to be a browser.
     */
    (new Extend\Routes('api'))
        ->post('/garrison/agent/poll', 'garrison.agent.poll', AgentPollController::class),

    (new Extend\Policy()),

    /*
     * Three permissions, not one.
     *
     * 🚨 console.send is separated from control deliberately. Restarting a
     * server is an operational act; sending a line to a game console is
     * arbitrary in-game authority — op, ban, give — and an operator may very
     * reasonably want somebody who can do the first and not the second.
     */
    (new Extend\ApiSerializer(\Flarum\Api\Serializer\ForumSerializer::class))
        ->attributes(function () {
            return [
                'garrisonCanView' => true,
            ];
        }),
];

/*
 * 🚨 Widget hosts are OPTIONAL COLLABORATORS and are registered conditionally.
 *
 * Garrison must work with none of them installed and with all four installed.
 * The JS side resolves fof/forum-widgets-core and Bespoke at runtime through
 * their own registries; only Page Builder needs a PHP extender, because it is
 * the one whose contract has a server half.
 *
 * class_exists rather than an extension-status lookup: this file is evaluated
 * before the extension manager is usable, and a hard reference to a class that
 * may not be installed is a fatal error at boot for everybody.
 */
if (class_exists(\Ernestdefoe\PageBuilder\Extend\PageBuilderBlock::class)) {
    $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(
        \ErnestDefoe\Garrison\Widget\ServerStatusBlock::class
    );
}

return $extenders;
