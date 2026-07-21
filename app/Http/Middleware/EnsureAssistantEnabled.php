<?php

namespace App\Http\Middleware;

use App\Models\Masjid;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the Masjid Assistant behind the per-masjid `masjids.assistant_enabled`
 * flag (alias `assistant` in bootstrap/app.php). Mirrors EnsureCrmEnabled.
 *
 * Runs AFTER `tenant` (ResolveMasjidTenant), so the masjid the request targets
 * is already resolved; this reads that masjid and 403s unless the assistant is
 * switched on. OFF by default (the column defaults to false); only a SuperAdmin
 * can enable it via the assistant-access toggle, which is deliberately NOT
 * behind this gate.
 *
 * This is the OUTERMOST of the assistant's three permission layers — the other
 * two (the masjid's enabled features, and the admin's own spatie permissions)
 * are enforced per-tool when the tool surface is built and again at execution.
 * A masjid admin holding every permission still gets a 403 here while their
 * masjid's assistant is disabled.
 */
class EnsureAssistantEnabled
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Every assistant route is /masjids/{masjid_id}/..., and `tenant` has
        // already bound the context; fall back to the route param so the gate
        // never depends on middleware binding order.
        $masjidId = $this->tenant->get() ?? $request->route('masjid_id');

        $masjid = $masjidId !== null ? Masjid::find($masjidId) : null;

        if ($masjid === null || ! $masjid->assistant_enabled) {
            abort(Response::HTTP_FORBIDDEN, 'The Masjid Assistant is not enabled for this masjid.');
        }

        return $next($request);
    }
}
