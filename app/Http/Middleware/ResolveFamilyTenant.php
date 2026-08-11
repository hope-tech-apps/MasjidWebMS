<?php

namespace App\Http\Middleware;

use App\Models\Contact;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds App\Support\TenantContext for parent/guardian requests, from the
 * AUTHENTICATED CONTACT and from nowhere else (alias `family.tenant`).
 *
 * ---------------------------------------------------------------------------
 * Why the family tree cannot reuse `tenant` (ResolveMasjidTenant)
 * ---------------------------------------------------------------------------
 *
 * `ResolveMasjidTenant` branches on `users.type`: MasjidAdmin first, SuperAdmin
 * `elseif`, and **anything else falls through unbound**. Unbound, per
 * .claude/rules/tenant-scoping.md, means NO FILTER — which is correct for the
 * public mobile API that passes `masjid_id` in the URL, and catastrophic for an
 * authenticated family route, where a `BelongsToMasjid` read would silently
 * span every tenant in the database. A non-staff principal reaching that
 * middleware gets exactly that fall-through.
 *
 * So this one is written the other way round: it either binds or it aborts.
 * There is no path through `handle()` that reaches `$next` with the context
 * unbound. That property is the point of the class, not an implementation
 * detail — anything added here must preserve it.
 *
 * ---------------------------------------------------------------------------
 * The tenant comes from the TOKEN, never from the URL
 * ---------------------------------------------------------------------------
 *
 * `$contact->masjid_id` is the binding. The `{masjid_id}` in the route is
 * treated purely as an ASSERTION the caller makes about themselves: if it names
 * a different organisation the request is refused, and it is never the source
 * of the binding even when it agrees. A header is never consulted at all.
 *
 * That ordering matters. Deriving the tenant from the URL and then checking the
 * contact against it puts the attacker's input in the load-bearing position; if
 * the check were ever skipped or reordered, the request would already be bound
 * to the masjid the attacker named. Deriving it from the tokenable means the
 * worst a wrong URL can do is 403.
 *
 * `EnsureCrmEnabled` (`crm`) runs after this and falls back to the route param
 * when the context is unbound. That fallback must not be mistaken for a second
 * binding mechanism: it gates a feature flag, it does not scope any query.
 *
 * See docs/t015-parent-identity-design.md §6 and invariant 3.
 */
class ResolveFamilyTenant
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // `family.active` has already established this, but the binding must be
        // impossible to obtain from a non-Contact principal even if the two are
        // ever mounted apart or reordered — a staff session satisfies
        // `auth:family` (see EnsureFamilyLoginActive), and binding a tenant off
        // a staff User here would hand the family tree a staff-shaped identity.
        $principal = Auth::user();

        if (! $principal instanceof Contact) {
            abort(Response::HTTP_FORBIDDEN, 'You are not authorized to access this masjid.');
        }

        $ownMasjidId = $principal->masjid_id;

        // A contact with no resolvable organisation is refused rather than left
        // unbound. `contacts.masjid_id` is NOT NULL in the schema, so this is
        // unreachable through the database today — which is exactly why it is
        // written down: the failure mode it prevents (falling through to an
        // unfiltered read of every tenant) is silent, and the cost of pinning
        // it shut is one branch.
        if ($ownMasjidId === null || (int) $ownMasjidId <= 0) {
            abort(Response::HTTP_FORBIDDEN, 'You are not authorized to access this masjid.');
        }

        $routeMasjidId = $request->route('masjid_id');

        // The caller named someone else's organisation. Refuse — do NOT quietly
        // serve their own instead, which would leave a client that got the id
        // wrong reading real data and believing it belonged to the masjid it
        // asked for.
        if ($routeMasjidId !== null && (int) $routeMasjidId !== (int) $ownMasjidId) {
            abort(Response::HTTP_FORBIDDEN, 'You are not authorized to access this masjid.');
        }

        $this->tenant->set((int) $ownMasjidId);

        return $next($request);
    }
}
