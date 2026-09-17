<?php

namespace App\Support\Canary;

use Illuminate\Http\Request;

/**
 * The header that marks a request as one of our own synthetic probes.
 *
 * `tenancy:canary` sends `X-Canary: tenancy` on every probe so that anything
 * measuring REAL traffic can leave it out. Spelled once, here, because the
 * sender (App\Console\Commands\TenancyCanary::headers()) and the readers
 * (App\Http\Middleware\CountLegacyFeaturesHit) sit in different corners of
 * the codebase. If each spelled it for itself, a rename on one side would
 * leave the other filtering on a header that no longer arrives, and nothing
 * would fail.
 *
 * Any non-empty value marks a probe, not only `tenancy`. A canary added later
 * with its own value is still not a phone. No shipped app sends this header.
 *
 * Client-supplied, so it is never a reason to GRANT anything. It is only ever a
 * reason to leave a request out of a count. Someone who spoofs it can hide their
 * own hits, and that is all.
 */
final class CanaryHeader
{
    public const NAME = 'X-Canary';

    /** The value `tenancy:canary` sends. */
    public const TENANCY = 'tenancy';

    public static function isPresent(Request $request): bool
    {
        return trim((string) $request->headers->get(self::NAME, '')) !== '';
    }
}
