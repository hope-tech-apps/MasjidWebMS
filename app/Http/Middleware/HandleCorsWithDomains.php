<?php

namespace App\Http\Middleware;

use App\Models\MasjidDomain;
use Closure;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CORS with the static CORS_ALLOWED_ORIGINS list as the base, plus the origin of
 * every `masjid_domains` row we have seen serving our own site (Manara Studio
 * W1, S9; R3).
 *
 * Why: until this, a new client host could call the API from the browser only
 * after someone edited production's `.env` (MEC's and Al-Razi's hosts were
 * blocked until the 2026-09-24 hotfix). Now a host is admitted the moment its
 * row is confirmed serving, and never before: MasjidDomain::corsOrigins() reads
 * corsAdmitted() only, so a reserved, pending, provisioning, awaiting,
 * failed or unconfirmed row, or a trashed organisation's, admits nothing.
 *
 * The table only ADDS. Whenever the static list already answers the question,
 * the request is handed to Laravel's HandleCors untouched and nothing is read
 * from the table or the cache: no Origin (the mobile apps and the renderer's
 * SSR), an Origin already on the list (every live origin today), a path CORS
 * does not cover, or a `*` list. That is what makes S9 invisible for every live
 * origin, headers and `Vary` included, and it matters because this runs on
 * every request and production's cache store is the database.
 *
 * The merged list is seen by the CORS decision and nothing else. HandleCors
 * reads config('cors') into the CorsService before calling the rest of the
 * stack, so the static list is put back before the stack runs: other code that
 * trusts config('cors.allowed_origins') (the lunch page's Stripe return,
 * JummahLunchOrdersController::returnUrlsFor) keeps reading exactly the list it
 * read before S9, and a confirmed host of one organisation cannot become a
 * payment return for another. Restored again afterwards, so a long-lived
 * application (a test, a queue worker handling a request) never carries one
 * request's list into the next.
 *
 * Reading the table can never fail a request: any Throwable is logged at
 * warning and the static list is used alone.
 */
class HandleCorsWithDomains extends HandleCors
{
    public function handle($request, Closure $next)
    {
        $static = (array) config('cors.allowed_origins', []);

        if (in_array('*', $static, true) || ! $this->needsDomainOrigins($request, $static)) {
            return parent::handle($request, $next);
        }

        $extra = $this->domainOrigins();

        if ($extra === []) {
            return parent::handle($request, $next);
        }

        config(['cors.allowed_origins' => array_values(array_unique(array_merge($static, $extra)))]);

        try {
            return parent::handle($request, function ($request) use ($next, $static) {
                config(['cors.allowed_origins' => $static]);

                return $next($request);
            });
        } finally {
            config(['cors.allowed_origins' => $static]);
        }
    }

    /**
     * Whether HandleCors would decide this request on its origin list AND the
     * static list cannot admit it. Checked in HandleCors' own order (skip
     * callbacks, then paths) so a request the parent ignores costs no read.
     *
     * @param  array<int, mixed>  $static
     */
    private function needsDomainOrigins($request, array $static): bool
    {
        foreach (static::$skipCallbacks as $callback) {
            if ($callback($request)) {
                return false;
            }
        }

        if (! $this->hasMatchingPath($request)) {
            return false;
        }

        $origin = $request->headers->get('Origin');

        if (! is_string($origin) || $origin === '') {
            return false;
        }

        // Loose, as CorsService::isOriginAllowed compares: an origin the parent
        // would admit on the static list alone never reaches the table.
        return ! in_array($origin, $static);
    }

    /** @return list<string> */
    private function domainOrigins(): array
    {
        try {
            return MasjidDomain::corsOrigins();
        } catch (Throwable $e) {
            Log::warning('CORS could not read masjid_domains origins; using the static list only.', [
                'exception' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return [];
        }
    }
}
