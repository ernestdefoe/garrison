<?php

namespace ErnestDefoe\Garrison\Players;

use Carbon\Carbon;
use ErnestDefoe\Garrison\Agent\Dispatcher;
use ErnestDefoe\Garrison\Model\Identity;
use ErnestDefoe\Garrison\Model\Server;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\QueryException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;

/**
 * Links a forum account to an in-game player, by proving it in the game.
 *
 * 🚨 THE PROOF IS THE WHOLE FEATURE.
 *
 * A form that asks "what is your in-game name?" and believes the answer lets
 * anybody claim the community's best-known player — and inherit their playtime,
 * their rank, and whatever an operator has built on top of that. So Garrison
 * whispers a code to that player INSIDE THE GAME. Only somebody holding that
 * account can read it, and they type it back here.
 *
 * The forum never composes the console line. It sends a name and six characters
 * to the agent, which renders the operator's own template and refuses any
 * player it cannot currently see in the game. See players.VerifyLine — that is
 * where a console injection would otherwise live, and verification is something
 * ordinary members do.
 */
class Linker
{
    public function __construct(
        protected Dispatcher $dispatcher,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * 🚨 No 0/O, 1/I/L. A code is read off a game screen — often a small one,
     * often in a font nobody chose — and typed into a browser. Every
     * ambiguous pair removed is a support message that never gets sent.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const CODE_LENGTH = 6;

    /**
     * Start a claim: generate a code and ask the agent to whisper it.
     */
    public function claim(User $actor, Server $server, string $player): Identity
    {
        $player = trim($player);

        if ($player === '' || mb_strlen($player) > 64) {
            throw new ValidationException([
                'player' => $this->translator->trans('ernestdefoe-garrison.api.errors.bad_player_name'),
            ]);
        }

        /*
         * 🚨 Refused if somebody else has already PROVED this name.
         *
         * Not refused for an unverified claim: two people can honestly type
         * the same name — one of them mistakenly — and whoever completes the
         * proof is the one who gets it. Blocking on an unproved claim would
         * let anybody park a name they do not own by typing it once.
         */
        $takenBy = Identity::query()
            ->where('server_id', $server->id)
            ->where('player', $player)
            ->verified()
            ->where('user_id', '!=', $actor->id)
            ->exists();

        if ($takenBy) {
            throw new ValidationException([
                'player' => $this->translator->trans('ernestdefoe-garrison.api.errors.player_taken'),
            ]);
        }

        /*
         * 🚨 Somebody else's UNPROVED claim on this name is dropped.
         *
         * The database holds (server_id, player) unique, because two verified
         * links to one character would make the whole idea meaningless. But
         * this method deliberately allows two people to CLAIM the same name —
         * blocking on an unproved claim would let anybody park a name they do
         * not own by typing it once. Those two rules collide exactly here, and
         * without this the second claimant gets a raw integrity-constraint
         * violation: SQL, table names and a bcrypt hash, shown to a member.
         * Found that way, on dev, by a probe doing what a second person would.
         *
         * So the pending slot goes to the latest claimant and the proof
         * decides who keeps it. Nothing is lost: an unverified row confers
         * nothing at all.
         */
        Identity::query()
            ->where('server_id', $server->id)
            ->where('player', $player)
            ->where('user_id', '!=', $actor->id)
            ->whereNull('verified_at')
            ->delete();

        $identity = Identity::query()
            ->where('user_id', $actor->id)
            ->where('server_id', $server->id)
            ->first();

        if ($identity === null) {
            $identity = new Identity();
            $identity->user_id = $actor->id;
            $identity->server_id = $server->id;
            $identity->created_at = Carbon::now();
        }

        if ($identity->isVerified() && $identity->player === $player) {
            // Already done. Saying so is kinder than sending another code.
            return $identity;
        }

        /*
         * 🚨 Re-claiming a DIFFERENT name drops the verification.
         *
         * Somebody who verified as `alice` and now claims `bob` has to prove
         * `bob` too. Keeping the old proof while changing the name would make
         * the whole flow decorative: verify once as anybody, then rename to
         * whoever you like.
         */
        $identity->player = $player;
        $identity->verified_at = null;

        /*
         * 🚨 A cooldown, because the whisper lands on SOMEBODY ELSE.
         *
         * Claiming a name sends a message to whoever is playing under it. With
         * no cooldown, anybody with an account can repeatedly claim the same
         * player and have the server whisper them a code every few seconds —
         * harassment delivered by the game itself, which the victim cannot
         * turn off and would reasonably blame the server owner for.
         *
         * It also bounds console traffic from a feature ordinary members can
         * reach, which matters on a game that treats console spam badly.
         */
        if ($identity->code_expires_at !== null
            && $identity->code_expires_at->gt(Carbon::now()->addMinutes(Identity::CODE_TTL_MINUTES - 1))) {
            throw new ValidationException([
                'player' => $this->translator->trans('ernestdefoe-garrison.api.errors.code_too_soon'),
            ]);
        }

        $code = $this->code();

        $identity->code_hash = password_hash($code, PASSWORD_DEFAULT);
        $identity->code_expires_at = Carbon::now()->addMinutes(Identity::CODE_TTL_MINUTES);
        $identity->attempts = 0;

        /*
         * 🚨 And a last guard, because the check above is a read followed by a
         * write and two people can pass it at the same instant.
         *
         * The database is the only thing that can actually decide a race, and
         * what it produces when it does is an exception full of SQL. Turning
         * that into the same refusal the read path gives means a member never
         * sees a stack trace for doing something reasonable at an unlucky
         * moment.
         */
        try {
            $identity->save();
        } catch (QueryException) {
            throw new ValidationException([
                'player' => $this->translator->trans('ernestdefoe-garrison.api.errors.player_taken'),
            ]);
        }

        /*
         * 🚨 Queued through the Dispatcher like everything else, so a
         * verification is in the audit log beside every other thing anybody
         * asked a host to do. It is also the one place the permission check
         * lives — see below for why this verb's check is the unusual one.
         */
        $this->dispatcher->queue($actor, $server, 'player.verify', [
            'player' => $player,
            'code' => $code,
        ], 'identity');

        return $identity;
    }

    /**
     * Finish a claim: check the code the person read in the game.
     */
    public function confirm(User $actor, Server $server, string $code): Identity
    {
        /** @var Identity|null $identity */
        $identity = Identity::query()
            ->where('user_id', $actor->id)
            ->where('server_id', $server->id)
            ->first();

        if ($identity === null || ! $identity->codeIsLive()) {
            throw new ValidationException([
                'code' => $this->translator->trans('ernestdefoe-garrison.api.errors.code_expired'),
            ]);
        }

        /*
         * 🚨 The attempt is counted BEFORE the check, and saved.
         *
         * Counting afterwards means a wrong code that throws never increments
         * anything, and the cap becomes decorative — which is the version of
         * this bug that ships, because the happy path works perfectly.
         */
        $identity->attempts = $identity->attempts + 1;
        $identity->save();

        /*
         * 🚨 password_verify, not a string comparison. It is constant-time,
         * and the code is stored hashed for the reason the column comment
         * gives. The same call guards the agent tokens.
         */
        if (! password_verify(strtoupper(trim($code)), (string) $identity->code_hash)) {
            throw new ValidationException([
                'code' => $this->translator->trans('ernestdefoe-garrison.api.errors.code_wrong'),
            ]);
        }

        $identity->verified_at = Carbon::now();

        // Spent. Leaving a used code live would let it be replayed by anybody
        // who saw it over a shoulder.
        $identity->code_hash = null;
        $identity->code_expires_at = null;
        $identity->save();

        return $identity;
    }

    public function unlink(User $actor, Server $server): void
    {
        Identity::query()
            ->where('user_id', $actor->id)
            ->where('server_id', $server->id)
            ->delete();
    }

    /**
     * 🚨 random_int, never rand() or mt_rand().
     *
     * This is the secret the whole proof rests on. `mt_rand` is a Mersenne
     * Twister whose state can be recovered from a handful of outputs, so an
     * attacker who requests a few codes of their own can predict somebody
     * else's. `random_int` is the CSPRNG and the only correct choice.
     */
    protected function code(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
