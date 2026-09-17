<?php

namespace App\Http\Middleware;

use App\Support\AppClientHeader;
use App\Support\Canary\CanaryHeader;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts who is still reading the legacy `GET /mobile/masjids/{id}/features`.
 *
 * ---------------------------------------------------------------------------
 * THE DECISION THIS IS EVIDENCE FOR
 * ---------------------------------------------------------------------------
 * S3b deletes that endpoint. Deleting it is safe exactly when no handset still
 * calls it, and today nobody can say: Burlington's store build (v2.5 b44) calls
 * it on every launch, Play vc13 calls it and routes the rows BY NAME, and the
 * server keeps no record of either. This middleware is the record.
 *
 * Two buckets per organisation per day:
 *
 *   tagged     the caller sent a valid `X-Manara-App` — an R1 build, which
 *              reads /menu and falls back to this only when /menu is 404 or
 *              unreachable. A tagged hit is the KILL SWITCH working, or a
 *              network failure, not a stranded install.
 *   untagged   no usable header. Everything shipped before R1 — the builds
 *              that would actually break. Untagged hits on organisation 1 are
 *              the v2.5-b44 signal the plan gates S3b on.
 *
 * The distinction is the whole value of the counter. A single total would say
 * "still in use" for as long as the kill switch stays pulled, and would keep
 * saying it after every phone had updated.
 *
 * ---------------------------------------------------------------------------
 * IT RUNS AFTER THE RESPONSE AND IT CANNOT FAIL THE REQUEST
 * ---------------------------------------------------------------------------
 * `terminate()`, so the counting happens once the response has been sent — the
 * cache round trips are off the caller's clock, and there is no code path by
 * which this can change a body, a status or a header.
 *
 * And the whole of it is inside one try/catch that swallows everything. A cache
 * backend that is down, a store that cannot increment, a key that collides —
 * none of those may take out the list of features an installed app draws its
 * entire drawer from. Telemetry that can break the thing it is measuring is
 * worse than no telemetry: the 2026-08-28 incident was one null icon in this
 * same payload emptying the drawer on every phone.
 *
 * Deliberately a middleware `terminate()` rather than `app()->terminating()`:
 * Application::terminate() walks its terminating callbacks and never clears
 * them, so a callback registered per request re-runs on every later termination
 * in the same process — invisible under php-fpm, wrong under Octane, and it
 * would multiply the counts. That trap is already recorded in
 * App\Services\Family\FamilyLoginService; this is the same trap.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS COUNTED, AND WHAT IS NOT
 * ---------------------------------------------------------------------------
 * Only a response that actually SERVED the list (2xx). A 404 for an
 * organisation that does not exist and a 429 from the throttle are not a phone
 * reading the legacy list — counting them would put junk organisation ids in
 * the report and let one looping scanner look like a stranded congregation.
 *
 * Not our own canary either. `tenancy:canary` probes this endpoint as
 * organisation 1 on about six runs a day, with `X-Canary: tenancy` and no
 * `X-Manara-App`. Before this filter each of those probes counted as an
 * untagged hit on organisation 1, which is the exact number S3b is gated on.
 * Organisation 1 could never reach zero, however many phones had updated. A
 * request carrying App\Support\Canary\CanaryHeader is skipped before the bucket
 * is chosen, so a probe that also sent `X-Manara-App` is not counted either.
 *
 * `Cache::add(key, 0, 3 days)` then `Cache::increment(key)`: add() is the
 * atomic "create if absent" every store implements, so two concurrent first
 * hits cannot both seed and lose one. Three days is long enough for the daily
 * report to read yesterday even if it is late or the box was down, and short
 * enough that this never becomes storage anybody has to manage.
 */
class CountLegacyFeaturesHit
{
    /** `mobile.telemetry.features.{Y-m-d}.{org}.{tagged|untagged}` */
    public const PREFIX = 'mobile.telemetry.features';

    /** Long enough that a late or missed daily report still finds yesterday. */
    public const TTL_SECONDS = 3 * 24 * 60 * 60;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $response->isSuccessful()) {
                return;
            }

            // Our own probe, not a phone.
            if (CanaryHeader::isPresent($request)) {
                return;
            }

            $org = $request->route('masjid_id');

            // Route parameters are strings. Anything that is not a plain
            // positive integer never reached a real organisation's list.
            if (! is_string($org) && ! is_int($org)) {
                return;
            }

            $org = (int) $org;

            if ($org <= 0) {
                return;
            }

            $key = self::key(
                now()->toDateString(),
                $org,
                AppClientHeader::isTagged($request) ? 'tagged' : 'untagged'
            );

            Cache::add($key, 0, self::TTL_SECONDS);
            Cache::increment($key);
        } catch (\Throwable $e) {
            // Deliberately silent, and deliberately not even a log line: this
            // runs on the highest-traffic mobile endpoint there is, and a cache
            // outage would then write one log line per request per phone.
            // The absence of counts is itself visible in the daily report.
        }
    }

    /** The one place the key is spelled, shared with the report command. */
    public static function key(string $date, int $masjidId, string $bucket): string
    {
        return self::PREFIX . ".{$date}.{$masjidId}.{$bucket}";
    }
}
