<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds App\Support\TenantContext for AUTHENTICATED admin requests.
 *
 * Registered on the admin route group only (alias `tenant` in bootstrap/app.php),
 * after `auth:sanctum`. It must NOT touch the public mobile API (routes/api.php).
 *
 * What binds, and when:
 *
 *   - MasjidAdmin  -> ALWAYS bound to the admin's own masjid, so every
 *                     BelongsToMasjid model auto-filters to it. Naming a
 *                     different masjid in the URL is a 403, not a filter.
 *   - SuperAdmin   -> bound to the masjid the ROUTE names. A SuperAdmin may act
 *                     on any masjid, but only one at a time: /masjids/5/... reads
 *                     and writes masjid 5. Left UNBOUND only when the route names
 *                     no masjid at all (the masjid list, the portal) — which is
 *                     what keeps genuinely cross-masjid views working.
 *   - public/guest -> never reaches here (no auth), so the context stays unbound
 *                     and the mobile endpoints that pass masjid_id in the URL
 *                     are unaffected.
 *
 * The distinction that matters: UNBOUND means "this route is not about one
 * masjid", NOT "the caller is a SuperAdmin". An earlier version of this comment
 * said SuperAdmins were always unbound, which was true of a much earlier version
 * of the code. It cost a security review a false positive — a reader concluded
 * that a SuperAdmin's /masjids/{id}/donations/export would return every masjid's
 * rows. It does not, and tests/Feature/SuperAdminExportScopeTest.php holds that
 * line for the ledger, the stats header and the CSV.
 */
class ResolveMasjidTenant
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // The admin SPA addresses a specific masjid via the route, matching the
        // existing convention (/masjids/{masjid_id}/...). Bind the tenant to that
        // masjid so BelongsToMasjid models filter to it — for a SuperAdmin this is
        // how they operate on one masjid at a time; for a MasjidAdmin it must be
        // their own masjid. When the route isn't masjid-scoped, fall back to the
        // MasjidAdmin's own masjid (a SuperAdmin stays unbound only in that case —
        // no masjid in the route — which is what cross-masjid views rely on).
        $routeMasjidId = $request->route('masjid_id');
        $ownMasjidId = $user->masjid_id ?? $user->masjid?->id;

        if ($user->type === 'MasjidAdmin') {
            // A MasjidAdmin is confined to the one masjid they own. Targeting any
            // other masjid in the URL is forbidden — this both drives the tenant
            // scope and closes the pre-existing gap where nothing stopped a
            // MasjidAdmin from passing another masjid's id in the route.
            if ($routeMasjidId !== null && (int) $routeMasjidId !== (int) $ownMasjidId) {
                abort(403, 'You are not authorized to access this masjid.');
            }
            if ($ownMasjidId !== null) {
                $this->tenant->set((int) $ownMasjidId);
            }
        } elseif ($user->type === 'SuperAdmin' && $routeMasjidId !== null) {
            // SuperAdmin may act on any masjid; bind to the one the route targets
            // so tenant-scoped CRM models filter to it. No route masjid → unbound.
            $this->tenant->set((int) $routeMasjidId);
        }

        return $next($request);
    }
}
