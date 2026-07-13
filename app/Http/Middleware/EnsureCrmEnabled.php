<?php

namespace App\Http\Middleware;

use App\Models\Masjid;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the CRM business endpoints behind the per-masjid `masjids.crm_enabled`
 * flag (alias `crm` in bootstrap/app.php).
 *
 * Applied ONLY to the CRM route group — contacts, funds, donations, Stripe
 * Connect (see routes/admin.php). It runs AFTER `tenant` (ResolveMasjidTenant),
 * so the masjid the request targets is already resolved; this reads that masjid
 * and 403s unless its CRM is switched on. The whole CRM is OFF by default (the
 * column defaults to false) and can be turned on only by a SuperAdmin via the
 * crm-access toggle, which is deliberately NOT behind this gate.
 *
 * A MasjidAdmin who holds every CRM permission still gets a 403 here while their
 * masjid's CRM is disabled — this gate is layered on top of the `permission:`
 * checks and short-circuits before them. Nothing else is affected: the 2FA
 * endpoints, the SuperAdmin toggle, and every pre-existing route sit outside it.
 */
class EnsureCrmEnabled
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Every CRM route is /masjids/{masjid_id}/..., and `tenant` has already
        // bound the context to that masjid; fall back to the route param so the
        // gate never depends on middleware binding order.
        $masjidId = $this->tenant->get() ?? $request->route('masjid_id');

        $masjid = $masjidId !== null ? Masjid::find($masjidId) : null;

        if ($masjid === null || ! $masjid->crm_enabled) {
            abort(Response::HTTP_FORBIDDEN, 'The CRM is not enabled for this masjid.');
        }

        return $next($request);
    }
}
