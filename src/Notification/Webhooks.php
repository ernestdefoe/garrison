<?php

namespace ErnestDefoe\Garrison\Notification;

use ErnestDefoe\Garrison\Model\Server;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Posts incidents to Discord, or to any URL that accepts JSON.
 *
 * 🚨 Where the community actually is. A Flarum notification reaches somebody
 * who opens the forum; an outage at 3am is noticed by whoever has Discord on
 * their phone, and that is the difference between a twenty-minute outage and a
 * twenty-hour one.
 */
class Webhooks
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $log
    ) {
    }

    public function serverIncident(Server $server, string $state, ?string $summary): void
    {
        $url = trim((string) $this->settings->get('ernestdefoe-garrison.webhook_url'));

        if ($url === '') {
            return;
        }

        $text = $this->line($server, $state, $summary);

        /*
         * Discord's own shape when it is a Discord URL, a plain envelope
         * otherwise. Detected from the host rather than asking the operator to
         * tell us which it is — a dropdown they can set wrongly is a support
         * thread waiting to happen.
         */
        $body = str_contains($url, 'discord.com') || str_contains($url, 'discordapp.com')
            ? ['content' => $text]
            : [
                'event' => 'server.' . $state,
                'server' => $server->name,
                'serverId' => $server->id,
                'summary' => $summary,
                'text' => $text,
            ];

        $this->post($url, $body);
    }

    protected function line(Server $server, string $state, ?string $summary): string
    {
        return match ($state) {
            'unready' => '⚠️ **' . $server->name . '** is running but players cannot join'
                . ($summary ? ' — ' . $summary : ''),
            'down' => '🔴 **' . $server->name . '** has stopped',
            'recovered' => '✅ **' . $server->name . '** is back',
            'abandoned' => '🚨 **' . $server->name . '** did not come back after repeated restarts. '
                . 'Automatic restarts have stopped and it needs a person.',
            default => '**' . $server->name . '**: ' . $state,
        };
    }

    protected function post(string $url, array $body): void
    {
        $payload = json_encode($body);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nUser-Agent: Garrison/1.0\r\n",
                'content' => $payload,
                // 🚨 Short, and failures are swallowed below. A webhook is a
                // courtesy: an unreachable Discord must never hold up the
                // scheduled tick that is trying to FIX the server, which is
                // the actually important thing happening at that moment.
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            // Logged, not thrown. Nothing about a failed notification should
            // stop remediation.
            $this->log->info('garrison: webhook post failed');
        }
    }
}
