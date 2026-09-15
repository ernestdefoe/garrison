<?php

namespace ErnestDefoe\Garrison\Notification;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\Queue;

/**
 * Proves that an alert could actually be delivered, by delivering one.
 *
 * 🚨 THIS IS THE PRODUCT WATCHING ITSELF, AND IT IS NOT DECORATION.
 *
 * Garrison's entire argument is the gap between a server breaking and somebody
 * noticing. Every alert it sends travels the same road: a job pushed onto the
 * forum's queue, and a worker somewhere that picks it up. If that worker is not
 * running, `sync()` still returns cleanly, nothing throws, nothing is logged,
 * and not one notification is ever written. The panel stays green. The alert
 * that was supposed to save twenty hours is sitting in Redis.
 *
 * That is not hypothetical and it is not rare. It happened on this extension's
 * own dev forum for three days, and the cause was mundane — a worker that had
 * died at boot and been left FATAL by supervisor. A customer would have had no
 * way to tell: the health ladder acted, the incident was recorded, and the
 * notification simply did not exist. An operator's first clue would have been
 * the next outage nobody was told about, which is the exact failure they paid
 * to stop having.
 *
 * So Garrison refuses to assume. Once a minute it pushes a heartbeat job; when
 * a worker runs it, the job stamps the time. The admin screen compares that
 * stamp to now. What it reports is therefore not "a worker looks configured" —
 * it is "a job pushed N seconds ago has been run", which is the same sentence
 * an alert needs to be true.
 */
class DeliveryCheck
{
    /** When the heartbeat was last PUSHED. */
    public const PUSHED = 'ernestdefoe-garrison.queue_pushed_at';

    /** When a worker last RAN one. The two together are the whole signal. */
    public const RAN = 'ernestdefoe-garrison.queue_ran_at';

    /**
     * How far behind the worker may fall before Garrison says so.
     *
     * 🚨 Generous on purpose — five minutes against a one-minute heartbeat.
     * A worker restarting during a deploy, a long job holding the queue, a
     * host under momentary load: none of those mean alerting is broken, and a
     * warning that cries wolf on every deploy is a warning an operator learns
     * to scroll past. What this must catch is the worker that has been dead
     * since Tuesday.
     */
    public const STALE_SECONDS = 300;

    public function __construct(
        protected Queue $queue,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * Push one heartbeat. Called from the scheduled health command, so it runs
     * on the same tick as everything else rather than needing its own schedule.
     */
    public function beat(): void
    {
        $this->settings->set(self::PUSHED, Carbon::now()->toIso8601String());

        $this->queue->push(new QueueHeartbeatJob());
    }

    /**
     * What to tell the operator.
     *
     * @return array{state: string, pushedAt: ?string, ranAt: ?string, behindSeconds: ?int}
     */
    public function report(): array
    {
        $pushed = $this->at(self::PUSHED);
        $ran = $this->at(self::RAN);

        return [
            'state' => $this->state($pushed, $ran),
            'pushedAt' => $pushed?->toIso8601String(),
            'ranAt' => $ran?->toIso8601String(),
            /*
             * 🚨 Plain timestamp arithmetic, not Carbon's diffInSeconds.
             *
             * Carbon reversed the SIGN of a signed diff between v2 and v3, so
             * `$ran->diffInSeconds($pushed, false)` means opposite things on
             * the two versions a customer might have in vendor/. The first
             * cut of this line got it backwards and reported a worker twelve
             * minutes behind as zero seconds behind — a number that looks
             * healthy, which is the worst possible way for a health figure to
             * be wrong. Subtracting two integers has no opinion about which
             * Carbon is installed.
             */
            'behindSeconds' => $pushed && $ran ? max(0, $pushed->getTimestamp() - $ran->getTimestamp()) : null,
        ];
    }

    /**
     * ok | stalled | unknown
     *
     * 🚨 `unknown` is a real answer and deliberately not `stalled`.
     *
     * Before the first scheduled tick has run — a forum that installed Garrison
     * ninety seconds ago, or one whose CRON is itself not set up — there is no
     * evidence either way, and claiming alerting is broken on no evidence
     * teaches an operator that this warning means nothing. The admin screen
     * says so in those words rather than guessing.
     */
    protected function state(?Carbon $pushed, ?Carbon $ran): string
    {
        if ($pushed === null) {
            return 'unknown';
        }

        if ($ran === null) {
            // Pushed at least once and never run: give it the same grace as a
            // lagging worker before calling it, because the very first push
            // and the first run are a few seconds apart at best.
            return $pushed->lt(Carbon::now()->subSeconds(self::STALE_SECONDS)) ? 'stalled' : 'unknown';
        }

        return $ran->gt(Carbon::now()->subSeconds(self::STALE_SECONDS)) ? 'ok' : 'stalled';
    }

    protected function at(string $key): ?Carbon
    {
        $raw = $this->settings->get($key);

        if (empty($raw)) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            // A setting somebody edited by hand is not worth a 500 on the
            // admin page that would show them what they broke.
            return null;
        }
    }
}
