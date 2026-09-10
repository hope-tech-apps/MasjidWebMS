<?php

namespace App\Support;

/**
 * "Which deployment is this?" — asked in one place, answered the same way
 * everywhere.
 *
 * Before T-040 the app read APP_ENV in three unrelated spots and drew a
 * different conclusion in each (force HTTPS, refuse a destructive seed, nothing
 * at all in the UI). Once a staging box exists that reasoning has to be shared,
 * because the interesting question is almost never "is this exactly production"
 * — it is "is this NOT production", which is what gates the noindex header, the
 * robots.txt refusal and the ribbon in the SPA.
 *
 * Reads `config('app.env')` rather than `env('APP_ENV')` on purpose: env() is
 * unreadable once `config:cache` has run (as it has on prod), and config() is
 * overridable in a test, which is the only way the not-production behaviour can
 * be asserted at all.
 */
final class Environment
{
    /**
     * The live, member-facing deployment — the one that must stay invisible to
     * this whole feature: no ribbon, no noindex, no robots refusal.
     */
    public static function isProduction(): bool
    {
        return self::name() === 'production';
    }

    /**
     * The raw environment name, lowercased and never empty.
     *
     * Falls back to `production` — the same default as `config/app.php` — so a
     * misconfigured box is treated as the live site and this feature stays
     * silent, rather than stamping a stray "STAGING" ribbon across production
     * because a variable went missing.
     */
    public static function name(): string
    {
        $env = strtolower(trim((string) config('app.env')));

        return $env !== '' ? $env : 'production';
    }

    /**
     * The human-facing badge text, e.g. "STAGING". Empty string in production,
     * so a caller can render `label()` unconditionally and get nothing there.
     */
    public static function label(): string
    {
        return self::isProduction() ? '' : strtoupper(self::name());
    }
}
