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
 * after `auth:sanctum`. It must NOT touch the public mobile API (routes/api.php)
 * or SuperAdmin cross-masjid views:
 *
 *   - MasjidAdmin  -> bind the context to the admin's own masjid, so every
 *                     BelongsToMasjid model auto-filters to that masjid.
 *   - SuperAdmin   -> leave UNBOUND, so cross-masjid dashboards keep working.
 *   - public/guest -> never reaches here (no auth), so the context stays unbound
 *                     and the mobile endpoints that pass masjid_id in the URL
 *                     are unaffected.
 */
class ResolveMasjidTenant
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->type === 'MasjidAdmin') {
            // Tenant is SERVER-DERIVED from the authenticated user, never from
            // the request. Prefer a users.masjid_id attribute if one ever
            // exists; otherwise resolve the masjid the admin owns
            // (masjids.user_id -> User::masjid()).
            $masjidId = $user->masjid_id ?? $user->masjid?->id;

            if ($masjidId !== null) {
                $this->tenant->set((int) $masjidId);
            }
        }

        return $next($request);
    }
}
