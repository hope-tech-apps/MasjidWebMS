<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Closure;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper over pragmarx/google2fa (TOTP) + bacon/bacon-qr-code (QR render).
 *
 * Shared by the enrollment controller and the login flow so secret generation
 * and code verification live in exactly one place. Deliberately NOT Laravel
 * Fortify — Fortify would restructure the existing Sanctum auth; this is an
 * additive, self-contained helper. See .claude/rules/auth-permissions.md.
 */
class TwoFactorService
{
    /** How many single-use recovery codes a confirmed enrollment gets. */
    public const RECOVERY_CODE_COUNT = 8;

    /**
     * The alphabet recovery codes are drawn from.
     *
     * No 0/O, no 1/I/L, no 5/S, no 2/Z, no 8/B. These are read off a printout or
     * a photo, by somebody who is already locked out and probably in a hurry, and
     * every ambiguous glyph is a support call. 25 symbols still leaves 25^10 ≈
     * 9.5e13 possibilities per code, which is not the weak link in anything here.
     */
    public const RECOVERY_CODE_ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    /**
     * Consecutive bad codes before the second factor locks, and for how long.
     *
     * `throttle:login` (5/min, keyed email+IP, in the cache) is the first layer
     * and stays. This is the second: it lives on the ROW, so rotating source
     * addresses does not evade it and flushing the cache does not re-arm the
     * attacker. Both are needed; see the migration docblock.
     *
     * The lock EXPIRES on its own, always. A permanent lock on somebody else's
     * second factor is a denial of service any stranger who knows an email
     * address can trigger, and there would be no honest answer to "who unlocks
     * me?" — see the report accompanying this change.
     */
    public const MAX_FAILED_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 15;

    /**
     * How long a code stays burned after it is accepted.
     *
     * A TOTP code is valid for its whole 30-second step, and `verifyKey()`
     * accepts ±1 step of drift on top — about 90 seconds during which the same
     * six digits pass again. That is long enough for a code read over a
     * shoulder, pasted into a support chat, or captured by a phishing proxy to
     * be replayed. 120 seconds covers the window with slack; beyond it the same
     * six digits belong to a different time step and mean nothing.
     */
    public const REPLAY_WINDOW_SECONDS = 120;

    public function __construct(private Google2FA $engine)
    {
    }

    /** Generate a fresh base32 TOTP secret to hand to an enrolling user. */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * Build the standard otpauth:// URI (issuer + account label + secret) that
     * authenticator apps consume.
     */
    public function otpauthUri(string $holder, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            $this->issuer(),
            $holder,
            $secret,
        );
    }

    /**
     * Render the otpauth URI as an inline SVG data-URI (no external service, no
     * temp files) so the client can display the QR directly.
     */
    public function qrCodeDataUri(string $otpauthUri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200, 1),
            new SvgImageBackEnd(),
        );

        $svg = (new Writer($renderer))->writeString($otpauthUri);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Verify a submitted 6-digit code against the secret. verifyKey allows a
     * ±1 time-step window to tolerate small clock drift.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code) !== false;
    }

    /**
     * Verify a code FOR A USER and burn it, so it cannot be presented twice.
     *
     * This is the method every caller should reach for; bare verify() answers
     * only "are these the right six digits", which stays true for the whole
     * drift window. Confirming enrollment, disabling, regenerating codes and
     * logging in all go through here, so a code used for any one of them is
     * spent for all of them — a phished code that has already been used to log
     * in cannot then be replayed to turn the second factor OFF.
     *
     * Returns false for a wrong code and for a replayed one alike. The caller
     * says "invalid" either way: telling an attacker they guessed a code that
     * was correct but stale is telling them the code space is smaller than they
     * thought.
     */
    public function verifyAndConsume(User $user, string $code): bool
    {
        $code = trim($code);

        // Digits only before the code ever reaches the TOTP engine. Callers that
        // accept EITHER kind of code (disable(), which takes a recovery code
        // too) hand this method strings like "ACDEF-GHJKM", and handing a
        // non-numeric key to google2fa is a question it was never asked to
        // answer — cheaper and safer to say no here than to find out. Checked
        // BEFORE the lock so a stream of junk never queues on a row.
        if (empty($user->two_factor_secret) || ! preg_match('/^\d{4,10}$/', $code)) {
            return false;
        }

        // "Burned" has to mean burned even when two requests arrive at once —
        // see spendUnderRowLock(). Reading the secret, deciding the code is not
        // a replay and writing the burn were three separate statements on an
        // unlocked row, so two requests carrying the SAME phished code could
        // both pass the replay check before either had written it.
        return $this->spendUnderRowLock($user, function (User $fresh) use ($code): bool {
            $secret = $fresh->two_factor_secret;

            if (empty($secret) || ! $this->verify($secret, $code)) {
                return false;
            }

            if ($this->isReplay($fresh, $code)) {
                return false;
            }

            $fresh->two_factor_last_code_hash = $this->codeFingerprint($code);
            $fresh->two_factor_last_used_at = now();
            $fresh->save();

            return true;
        });
    }

    /**
     * Eight single-use recovery codes, formatted XXXXX-XXXXX.
     *
     * `random_int` (CSPRNG) per character rather than `Str::random`'s byte
     * stream, because the restricted alphabet needs rejection-free indexing and
     * a modulo over `random_bytes` would bias it. The hyphen is cosmetic —
     * consumeRecoveryCode() normalises it away — but it is what makes a
     * ten-character string readable off paper.
     */
    public function generateRecoveryCodes(int $count = self::RECOVERY_CODE_COUNT): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->randomChunk(5) . '-' . $this->randomChunk(5);
        }

        return $codes;
    }

    /**
     * Spend one recovery code. True if it matched and has now been removed.
     *
     * Single use is enforced by REMOVING the matched code from the stored set
     * and committing before the caller mints anything — a code that signs
     * somebody in is gone by the time they hold the token. The comparison walks
     * the whole set with `hash_equals` and no early return, so the time it
     * takes does not say how many codes are left or how far down the list a
     * guess landed.
     *
     * AND IT HAPPENS UNDER A ROW LOCK, which is the difference between single
     * use and single use for people who never click twice. Read-modify-write on
     * an unlocked row was not enough: two logins carrying the same printed code
     * that interleaved between the read and the save both found the code
     * present, both returned true, and AuthController::login minted a token for
     * each — one line of paper, two live sessions — while the losing write put
     * the other request's spent code back. See spendUnderRowLock().
     *
     * Normalisation is deliberate and generous: people re-type these from paper
     * in lower case, with the hyphen missing or a stray space in the middle. A
     * recovery path that rejects a correct code over punctuation is not a
     * recovery path.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $candidate = $this->normaliseRecoveryCode($code);

        if ($candidate === '') {
            return false;
        }

        return $this->spendUnderRowLock($user, function (User $fresh) use ($candidate): bool {
            $codes = $fresh->two_factor_recovery_codes;

            if (! is_array($codes) || $codes === []) {
                return false;
            }

            $matchedIndex = null;

            foreach ($codes as $index => $stored) {
                if (hash_equals($this->normaliseRecoveryCode((string) $stored), $candidate)) {
                    $matchedIndex = $index;
                }
            }

            if ($matchedIndex === null) {
                return false;
            }

            unset($codes[$matchedIndex]);

            $fresh->two_factor_recovery_codes = array_values($codes);
            $fresh->save();

            return true;
        });
    }

    /**
     * Spend a single-use credential against a FRESHLY READ, ROW-LOCKED copy of
     * the user, then put the result back on the caller's instance.
     *
     * Every "burn this credential" in this service is a read-modify-write, and
     * a credential two requests can read at the same time is not single use.
     * The transaction plus `lockForUpdate` makes the second request wait for
     * the first to commit and then read what it wrote, so the second sees a
     * spent code and says no.
     *
     * The re-read is the load-bearing half, not the lock: the caller's `$user`
     * was loaded before the request began (by the Sanctum guard, or by
     * `User::where('email')` in login) and its recovery-code array may already
     * be stale by the time we get here. Deciding from the instance in memory is
     * exactly the bug. `lockForUpdate` compiles to nothing on sqlite, so the CI
     * suite proves the re-read; MySQL adds the wait.
     *
     * The re-read goes through the default (non-trashed) scope, so an archived
     * account spends nothing. That matches every door: login resolves users the
     * same way, and a soft-deleted login cannot reach the authenticated ones.
     *
     * The three columns these mutations touch are copied back onto `$user`
     * afterwards, because callers keep using their instance — `confirm()` saves
     * it two lines later, and an instance still holding the pre-spend code list
     * would write the spent code back in.
     */
    private function spendUnderRowLock(User $user, Closure $spend): bool
    {
        return (bool) DB::transaction(function () use ($user, $spend) {
            /** @var User|null $fresh */
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $fresh instanceof User) {
                return false;
            }

            $spent = (bool) $spend($fresh);

            foreach (['two_factor_recovery_codes', 'two_factor_last_code_hash', 'two_factor_last_used_at'] as $column) {
                $user->setAttribute($column, $fresh->getAttribute($column));
            }

            return $spent;
        });
    }

    /**
     * True while this account's second factor is locked after repeated failures.
     *
     * Read this BEFORE verifying anything, so a locked account does not keep
     * feeding an attacker attempts, and never as a reason to refuse a user who
     * has not enrolled — nothing in this file may run for them at all.
     */
    public function isLockedOut(User $user): bool
    {
        return $user->two_factor_locked_until !== null
            && $user->two_factor_locked_until->isFuture();
    }

    /** Whole minutes left on a lock, floored at 1 so the message never says "0". */
    public function lockedForMinutes(User $user): int
    {
        if (! $this->isLockedOut($user)) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInSeconds($user->two_factor_locked_until, false) / 60));
    }

    /**
     * Count one failed second-factor attempt, locking the account when the run
     * reaches MAX_FAILED_ATTEMPTS.
     *
     * The counter resets when the lock is applied rather than accumulating, so
     * the punishment is the same fifteen minutes every time instead of growing
     * without bound. Only ever called for a user who has CONFIRMED 2FA.
     */
    public function registerFailure(User $user): void
    {
        $attempts = (int) $user->two_factor_failed_attempts + 1;

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $user->two_factor_failed_attempts = 0;
            $user->two_factor_locked_until = now()->addMinutes(self::LOCKOUT_MINUTES);
        } else {
            $user->two_factor_failed_attempts = $attempts;
        }

        $user->save();
    }

    /** A success clears the run of failures and any expired lock with it. */
    public function clearFailures(User $user): void
    {
        if ((int) $user->two_factor_failed_attempts === 0 && $user->two_factor_locked_until === null) {
            return;
        }

        $user->two_factor_failed_attempts = 0;
        $user->two_factor_locked_until = null;
        $user->save();
    }

    /**
     * Everything 2FA holds on this user, cleared in one place.
     *
     * Used by disable(). The recovery codes MUST go with the secret: leaving
     * them behind would leave eight strings that each still authenticate a login
     * whose second factor the user believes they just switched off.
     */
    public function forget(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_last_code_hash = null;
        $user->two_factor_last_used_at = null;
        $user->two_factor_failed_attempts = 0;
        $user->two_factor_locked_until = null;
        $user->save();
    }

    /** Has this exact code already been spent inside the drift window? */
    private function isReplay(User $user, string $code): bool
    {
        if (empty($user->two_factor_last_code_hash) || $user->two_factor_last_used_at === null) {
            return false;
        }

        if ($user->two_factor_last_used_at->lt(now()->subSeconds(self::REPLAY_WINDOW_SECONDS))) {
            return false;
        }

        return hash_equals((string) $user->two_factor_last_code_hash, $this->codeFingerprint($code));
    }

    /**
     * A keyed fingerprint of a code — never the code itself.
     *
     * HMAC, not a bare sha256: six digits is a million-entry rainbow table, so a
     * plain digest of a TOTP code in a database column is the code. Same
     * reasoning (and same key) as `contact_login_codes.code_hash`.
     */
    private function codeFingerprint(string $code): string
    {
        return hash_hmac('sha256', trim($code), (string) config('app.key'));
    }

    /** Upper-case, letters and digits only — how a code is compared, never stored. */
    private function normaliseRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function randomChunk(int $length): string
    {
        $alphabet = self::RECOVERY_CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $chunk = '';

        for ($i = 0; $i < $length; $i++) {
            $chunk .= $alphabet[random_int(0, $max)];
        }

        return $chunk;
    }

    private function issuer(): string
    {
        return config('app.name', 'Masjid');
    }
}
