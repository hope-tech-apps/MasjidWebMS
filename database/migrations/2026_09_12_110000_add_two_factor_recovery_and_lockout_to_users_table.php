<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery codes, replay protection and a durable lockout counter for admin 2FA.
 *
 * Every column is NULLABLE or DEFAULTED, so an existing row is untouched and an
 * admin who never enrolled is bit-for-bit the admin they were before this ran.
 * Nothing here is read outside `User::hasTwoFactorEnabled()` being true — see
 * `.claude/rules/auth-permissions.md`: the 2FA layer is strictly additive and a
 * migration that changed the shape of an unenrolled login would be wrong.
 *
 * ## `two_factor_recovery_codes` — and why it is ENCRYPTED, not HASHED
 *
 * "Why not hash them like passwords?" is the first review question, so: because
 * the screen has to show them again. A recovery code the user cannot re-read is
 * a one-shot printout, not a recovery path — and the whole reason this column
 * exists is that today an admin who loses their phone has NO way back in
 * (`disable` itself demands a live code). A hash would let us verify a code but
 * never re-display the set, which turns "I lost the paper" into the same dead
 * end.
 *
 * Encrypted-at-rest is the same treatment `two_factor_secret` already gets on
 * this very table, and the secret is strictly MORE dangerous than the codes it
 * backs up — it mints an unlimited stream of valid codes, where these are eight
 * single-use strings. So this introduces no new class of exposure: an attacker
 * who can decrypt this column already holds the secret next to it. TEXT, not
 * string: Laravel's `encrypted:array` cast stores base64 JSON of a serialized
 * payload, and eight codes plus the envelope overshoot 255 bytes comfortably.
 *
 * ## `two_factor_last_code_hash` + `two_factor_last_used_at` — replay
 *
 * A TOTP code stays valid for its whole time step, and `verifyKey()` accepts a
 * ±1 step drift window on top of that — roughly 90 seconds in which the SAME six
 * digits are accepted again. A code read over a shoulder, screenshotted in a
 * support chat, or captured by a phishing proxy is therefore replayable for a
 * minute and a half unless a used code is burned. These two columns burn it.
 *
 * The stored value is `hash_hmac('sha256', $code, APP_KEY)`, NOT a bare digest:
 * a plain sha256 of six digits is reversible by anyone holding the table (a
 * million-row rainbow table is a few seconds of work), so "hashed" would be
 * decoration. Same reasoning as `contact_login_codes.code_hash`.
 *
 * ## `two_factor_failed_attempts` + `two_factor_locked_until` — brute force
 *
 * `throttle:login` already limits attempts, but it is keyed on email+IP and
 * lives in the cache: an attacker who holds a stolen password and rotates
 * source addresses is not slowed by it at all, and a cache flush re-arms them
 * instantly. A counter on the ROW cannot be flushed away and cannot be
 * side-stepped by changing IP — the same argument `.claude/rules` makes for
 * `contact_login_codes.attempts`. Both layers are kept; neither replaces the
 * other.
 *
 * The lock is ALWAYS time-bounded (`two_factor_locked_until`) and never a flag
 * an operator has to come and clear. A permanent lock on the second factor is a
 * denial-of-service an attacker can trigger for free by typing rubbish at
 * somebody else's account, and this platform's answer to "who unlocks me?" has
 * to be "the clock", not "nobody".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
            $table->string('two_factor_last_code_hash', 64)->nullable()->after('two_factor_recovery_codes');
            $table->timestamp('two_factor_last_used_at')->nullable()->after('two_factor_last_code_hash');
            $table->unsignedSmallInteger('two_factor_failed_attempts')->default(0)->after('two_factor_last_used_at');
            $table->timestamp('two_factor_locked_until')->nullable()->after('two_factor_failed_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_recovery_codes',
                'two_factor_last_code_hash',
                'two_factor_last_used_at',
                'two_factor_failed_attempts',
                'two_factor_locked_until',
            ]);
        });
    }
};
