<?php

namespace App\Http\Middleware;

use App\Models\Masjid;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds App\Support\TenantContext for the UNAUTHENTICATED half of the family
 * realm — the two sign-in endpoints (alias `family.guest`, T-015d).
 *
 * ---------------------------------------------------------------------------
 * Why this exists at all
 * ---------------------------------------------------------------------------
 *
 * `ResolveFamilyTenant` (`family.tenant`) binds from the TOKEN's contact, which
 * is the right source and the reason a parent cannot read another organisation.
 * At sign-in there is no token yet — that is the point of the endpoint — so the
 * only thing naming an organisation is the URL.
 *
 * The dangerous shortcut would be to bind nothing and let the controller filter
 * by hand. .claude/rules/tenant-scoping.md is unambiguous about what unbound
 * means: NO filter. A `Contact` lookup with an unbound context searches every
 * masjid in the database, so "which family does parent@example.com belong to?"
 * would be answered across tenants — and since a matching contact gets a mail
 * and a non-matching one does not, that is a cross-tenant existence oracle
 * delivered by SMTP. Binding here means the lookup is scoped by the same global
 * scope everything else in the application uses.
 *
 * ---------------------------------------------------------------------------
 * Bind or abort — the property this class shares with `family.tenant`
 * ---------------------------------------------------------------------------
 *
 * There is no path through `handle()` that reaches `$next` with the context
 * unbound. Anything added here must preserve that.
 *
 * A `{masjid_id}` naming no organisation is a 404, which is what an unknown id
 * means everywhere else in this application and discloses nothing new: masjid
 * ids are already public — `routes/api.php` serves prayer times, announcements
 * and the mobile feed by id to anyone. What is NOT public is who is on a
 * roster, and that is decided further down, silently.
 */
class ResolveFamilyGuestTenant
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $routeMasjidId = $request->route('masjid_id');

        // Never a header and never the body: the route parameter is the only
        // place this is read from, mirroring ResolveMasjidTenant::routeMasjidId().
        if ($routeMasjidId === null || (int) $routeMasjidId <= 0) {
            abort(Response::HTTP_NOT_FOUND, 'Route not found.');
        }

        // Masjid is the tenant itself and carries no global scope; findOrFail
        // would be a ModelNotFoundException, which this app's renderer turns
        // into a 404 anyway — spelled out so the intent is legible.
        $masjid = Masjid::find((int) $routeMasjidId);

        if ($masjid === null) {
            abort(Response::HTTP_NOT_FOUND, 'Route not found.');
        }

        $this->tenant->set((int) $masjid->id);

        return $next($request);
    }
}
