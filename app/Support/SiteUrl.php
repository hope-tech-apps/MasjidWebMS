<?php

namespace App\Support;

/**
 * Absolute URLs built against the CONFIGURED host, never the request's.
 *
 * ==========================================================================
 * WHY THIS EXISTS
 * ==========================================================================
 *
 * Laravel's `url()`, `asset()` and `route()` resolve against the INCOMING
 * request's Host header. That is the right default for a page's own links —
 * a person on portal.alrazischool.org should get portal.alrazischool.org
 * assets — and it is the wrong default for any string that OUTLIVES the
 * request that produced it.
 *
 * Two ways a request-shaped URL escapes its request in this application:
 *
 *   1. THE SHARED CACHE. Every public/mobile payload is wrapped in
 *      `Cache::remember` under a key that contains the organisation id and no
 *      host (App\Support\MobileCache). Whoever warms the entry decides the URL
 *      that every later caller is served, for the whole TTL. The Host is
 *      attacker-chosen: this box's nginx is `default_server` on :80 and :443,
 *      so any Host lands on the app, and the origin answers requests that never
 *      passed through Cloudflare. One unauthenticated GET, repeated each time
 *      the entry expires, holds it indefinitely.
 *
 *   2. A PAGE SOMEBODY ELSE ACTS ON. A form `action` is followed after the page
 *      is rendered. `/account-deletion` asks for an email address and then a
 *      mailed code; a form posting to a chosen host is a phishing page with a
 *      correct-looking script, served by us, over our own TLS.
 *
 * Neither needs an attacker to fire. This deploy serves several hostnames —
 * masjid.hopetechapps.com, manara.hopetechapps.com, portal.alrazischool.org —
 * so a crawler or health check arriving on one of them caches ITS host into
 * another organisation's payload.
 *
 * The rule this class names, and the reason it is a class rather than a
 * repeated `rtrim(config('app.url'), '/')`:
 *
 *      NO FIELD OF A CACHED PUBLIC PAYLOAD, AND NO FORM ACTION ON A PUBLIC
 *      PAGE, MAY BE A FUNCTION OF THE REQUEST. Every value is a function of
 *      the organisation.
 *
 * ==========================================================================
 * WHAT DELIBERATELY DOES *NOT* USE THIS
 * ==========================================================================
 *
 * A page's own same-origin decoration — `asset()` in vue-app-index.blade.php,
 * App\Support\Avatar's image URLs. Those are consumed inside the very response
 * that generated them, so they never outlive the request, and pinning them
 * would BREAK the secondary hostnames: SecurityHeaders sends `default-src
 * 'self'`, widened to name config('app.url') only on the proxied paths
 * (`/portal`, `/jummah-lunch`). Pin `asset()` and every bundle, font and icon
 * on manara.hopetechapps.com becomes cross-origin against a `'self'` policy and
 * is refused by the browser — the failure recorded in this repo as "assets
 * pinned to one host". Same-origin decoration stays same-origin; anything that
 * escapes its request comes through here.
 *
 * The middleware App\Http\Middleware\TrustedHosts closes the same class at the
 * door. This class is what keeps the payloads correct on the hosts the door
 * legitimately admits — and while the door is still in report-only mode.
 */
final class SiteUrl
{
    /**
     * An absolute URL on the configured host.
     *
     * Trailing/leading slashes are normalised so that APP_URL with or without a
     * trailing slash produces the same string — a double slash after the host is
     * a different URL to a cache, an ETag and a string comparison.
     */
    public static function to(string $path = ''): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $path = ltrim($path, '/');

        return $path === '' ? $base : $base.'/'.$path;
    }

    /**
     * A named route as an absolute URL on the configured host.
     *
     * `absolute: false` makes Laravel emit the PATH only; the host is then ours
     * to choose rather than the request's. Query strings and route parameters
     * behave exactly as `route()`.
     */
    public static function route(string $name, array $parameters = []): string
    {
        return self::to(route($name, $parameters, absolute: false));
    }

    /** The configured host with no scheme, port or path — for comparisons. */
    public static function host(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
