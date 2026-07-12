<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin TOTP two-factor authentication columns.
 *
 * Both columns are NULLABLE and default to null, so every existing admin row is
 * "not enrolled" — login behavior is unchanged for them (no extra step). 2FA is
 * only requested at login once a user has CONFIRMED enrollment
 * (two_factor_confirmed_at is set). See .claude/rules/auth-permissions.md.
 *
 *   - two_factor_secret       — the base32 TOTP shared secret. Stored via the
 *                               model's `encrypted` cast, so the column holds
 *                               ciphertext (text, not string, for headroom).
 *   - two_factor_confirmed_at — set when the user proves possession with a valid
 *                               code; this is the flag that ENABLES 2FA at login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at']);
        });
    }
};
