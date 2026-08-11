<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\TenantContext;
use App\Support\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds App\Support\TenantContext for AUTHENTICATED admin requests.
 *
 * Registered on the admin route group only (alias `tenant` in bootstrap/app.php),
 * after `auth:sanctum` and `admin`. It must NOT touch the public mobile API
 * (routes/api.php).
 *
 * What binds, and when:
 *
 *   - MasjidAdmin  -> bound to a masjid they hold a VERIFIED MEMBERSHIP in,
 *                     decided by App\Support\TenantResolver from the
 *                     `masjid_user` pivot. Naming a masjid they hold no
 *                     membership for is a 403, not a filter. Since S3 the
 *                     answer comes from a membership row rather than from a
 *                     raw id, so the binding carries its own provenance.
 *   - SuperAdmin   -> bound to the masjid the ROUTE names. A SuperAdmin may act
 *                     on any masjid, but only one at a time: /masjids/5/... reads
 *                     and writes masjid 5. Left UNBOUND only when the route names
 *                     no masjid at all (the masjid list, the portal) — which is
 *                     what keeps genuinely cross-masjid views working.
 *   - anyone else  -> 403. See "fails closed" below.
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
 *
 * ------------------------------------------------------------------------------
 * The branch ORDER is MasjidAdmin first, SuperAdmin second — do not swap it
 * ------------------------------------------------------------------------------
 *
 * A proposal during the S3 design review described the SuperAdmin branch as
 * "kept verbatim and first". It is not first and never was, and reordering is
 * not cosmetic: it changes behaviour for a SuperAdmin who also holds a
 * membership, quietly moving them from route-derived binding to membership-
 * derived binding. The design records the correction
 * (docs/multi-tenant-admin-design.md); this is the code it refers to.
 *
 * ------------------------------------------------------------------------------
 * It fails closed
 * ------------------------------------------------------------------------------
 *
 * There is no row-level security underneath this. An unbound context is not a
 * safe default — it is NO FILTER — so every path that cannot name exactly one
 * verified masjid aborts instead of falling through. That includes the
 * principal who is neither a MasjidAdmin nor a SuperAdmin: before S3 such a
 * user (`users.type === 'User'`, or a non-User authenticatable reaching this
 * middleware through some other guard) fell straight through to an unbound
 * context and therefore to unfiltered reads. `UserAdminMiddleware` already 401s
 * them one layer earlier on every route this middleware is registered on, so
 * closing it changes no reachable behaviour — it removes the second layer's
 * dependence on the first, which is the same reasoning T-015a applied to the
 * `instanceof User` checks (.claude/rules/auth-permissions.md).
 */
class ResolveMasjidTenant
{
    /**
     * The refusal, unchanged since before S3 and asserted verbatim by
     * tests/Feature/MasjidMembershipPivotTest.php. The admin SPA surfaces this
     * string, so every fail-closed branch answers with exactly this one — a
     * caller is never told WHICH check refused them.
     */
    public const FORBIDDEN_MESSAGE = 'You are not authorized to access this masjid.';

    public function __construct(
        private TenantContext $tenant,
        private TenantResolver $resolver,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $routeMasjidId = $this->routeMasjidId($request);

        if ($user instanceof User && $user->type === 'MasjidAdmin') {
            // The admin SPA addresses a specific masjid via the route, matching
            // the existing convention (/masjids/{masjid_id}/...). The resolver
            // answers from `masjid_user`, and with the one-membership gate
            // closed (config/tenancy.php) its verdict is the pre-S3 one for
            // every user who can exist in production: bind the masjid they own,
            // 403 any other id in the URL.
            $resolution = $this->resolver->resolve($user, $routeMasjidId, $request->path());

            if ($resolution->isDenied()) {
                abort(403, self::FORBIDDEN_MESSAGE);
            }

            // A null membership here is the resolver's third verdict — "this
            // route is not about one masjid" — not a failure to answer. It
            // cannot arise for a single-membership admin, so nothing about
            // today's binding changes.
            if ($resolution->membership() !== null) {
                $this->tenant->setFromMembership($resolution->membership());
            }
        } elseif ($user instanceof User && $user->type === 'SuperAdmin') {
            // UNCHANGED. A SuperAdmin holds no memberships (S2's backfill gave
            // them none, deliberately) and is bound from the route instead:
            // any masjid, one at a time. No route masjid -> unbound, which is
            // what cross-masjid views rely on.
            if ($routeMasjidId !== null) {
                $this->tenant->set($routeMasjidId);
            }
        } else {
            // Fail closed. Falling through here would leave the context unbound
            // and hand an unfiltered view of every masjid to a principal that
            // is not an admin at all.
            abort(403, self::FORBIDDEN_MESSAGE);
        }

        return $next($request);
    }

    /**
     * The masjid named by the ROUTE, and nothing else.
     *
     * Deliberately not `$request->input('masjid_id')` and not a header: those
     * are client-supplied and would let a caller nominate its own tenant, which
     * is the design's invariant 8 ("masjid_id in body or header is ignored
     * while bound") and the reason the whole resolver takes a route parameter
     * rather than a request. A present-but-unparseable id casts to 0, matches
     * no membership and is therefore refused — same as before S3.
     */
    private function routeMasjidId(Request $request): ?int
    {
        $value = $request->route('masjid_id');

        return $value === null ? null : (int) $value;
    }
}
