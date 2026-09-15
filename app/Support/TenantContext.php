<?php

namespace App\Support;

use App\Models\MasjidUser;
use InvalidArgumentException;

/**
 * Request-scoped holder for the "current tenant" (masjid_id).
 *
 * Registered with `scoped()` in AppServiceProvider, so within one request the
 * ResolveMasjidTenant middleware, every BelongsToMasjid model, and any service
 * all read/write the same instance — and that instance does not outlive the
 * scope it was built in.
 *
 * IT USED TO BE A `singleton()`, AND THAT WAS A BUG. A singleton lives as long
 * as the container does, which in a web request is one request (fine) and in
 * `queue:work` is the entire life of the worker process (not fine): a job that
 * bound masjid A handed that binding to the next job off the queue, which might
 * belong to masjid B, and BelongsToMasjid's global scope would quietly filter
 * and stamp everything with the wrong tenant. `queue:work` calls
 * `forgetScopedInstances()` before reserving each job, so `scoped()` is what
 * makes each job start from nothing; App\Listeners\ResetTenantContextBetweenJobs
 * covers the worker paths that do not reset the scope. Nothing about a web
 * request changed — no code path calls `forgetScopedInstances()` mid-request.
 *
 * Do not "optimise" this back to `singleton()`.
 *
 * The context is either BOUND (a MasjidAdmin request -> filter to one masjid)
 * or UNBOUND (SuperAdmin, system jobs, and the public/unauthenticated mobile
 * API -> no auto-filter at all). "Unbound == no filter" is deliberate: it is
 * what preserves cross-masjid SuperAdmin views and the existing public
 * endpoints that pass masjid_id explicitly in the URL.
 *
 * ------------------------------------------------------------------------------
 * Two ways in, and they are not equivalent (S3)
 * ------------------------------------------------------------------------------
 *
 *   - `setFromMembership(MasjidUser)` — the binding an ADMIN REQUEST goes
 *     through. It takes the verified `masjid_user` row itself, so the binding
 *     carries its own provenance: which grant admitted this request, in which
 *     role, and whether it was that user's default. App\Support\TenantResolver
 *     is what produces that row, and it is the only thing that should.
 *   - `set(int)` — a raw id with NO provenance. It stays public because the
 *     admin realm is not the only realm: there are five kinds of caller with no
 *     `masjid_user` row to offer, listed on the method itself. It is
 *     `@internal`: a controller or FormRequest must never call it with an id
 *     that arrived from the client.
 */
class TenantContext
{
    /** Current tenant's masjid_id, or null when the context is unbound. */
    private ?int $masjidId = null;

    /**
     * The membership that admitted the current request, when the binding came
     * from one. Null for a route-derived (SuperAdmin) or system binding.
     */
    private ?MasjidUser $membership = null;

    /**
     * Bind the context to a single masjid from a raw id.
     *
     * @internal Request code that binds on behalf of an ADMIN must use
     * setFromMembership(), so the id is one App\Support\TenantResolver verified
     * against `masjid_user` — see the class docblock and
     * .claude/rules/tenant-scoping.md.
     *
     * ------------------------------------------------------------------------
     * Why this is still public, when the design said to make it internal
     * ------------------------------------------------------------------------
     *
     * docs/multi-tenant-admin-design.md ("Binding, fail-closed") asks for
     * `setFromMembership()` to be the ONLY public entry, so no future
     * controller can bind an id it merely received. That instruction was
     * written about the admin realm, and the admin realm is not the only realm
     * reaching this class. These callers hold a masjid they resolved
     * server-side and no membership row to name it with — a `masjid_user` grant
     * would be a fiction for every one of them:
     *
     *   1. ResolveMasjidTenant's SuperAdmin branch — bound from the ROUTE. A
     *      SuperAdmin holds no memberships at all (S2 gave them none on
     *      purpose), so requiring one would lock them out of every masjid.
     *   2. ResolveFamilyTenant — the family portal binds the organisation the
     *      authenticated CONTACT belongs to. A parent is not staff and has no
     *      pivot row; the grant here is the contact record itself.
     *   3. ResolveFamilyGuestTenant — binds the `{masjid_id}` in the URL after
     *      loading that Masjid, for the unauthenticated family surface.
     *   4. Save/restore round-trips around a temporary re-binding:
     *      ImpactMetrics::withTenant(), BroadcastDispatcher and
     *      AccountDeletionController::withTenant() each read get() and hand
     *      the same int back in a `finally`. They are restoring a binding this
     *      class produced, not asserting a new grant.
     *   5. Console/system code that resolved the masjid itself
     *      (ImportSchoolRoster, ImportCurriculumWeeks) — there is no request
     *      and no principal to hold a membership.
     *
     * So this is `@internal` BY CONVENTION, not by enforcement, and saying so
     * plainly is worth more than a comment that implies a guarantee the code
     * does not make. An earlier version of this docblock (and
     * .claude/rules/tenant-scoping.md, still) claimed "exactly two callers";
     * there are eleven call sites in the eight files named above, and a reader
     * who trusted the count would conclude the surface was already closed.
     *
     * Narrowing it for real is a separate, non-additive change — a named
     * entry point per realm (`setFromContact`, `setFromRoute`, `setFromSystem`)
     * plus the eight files edited in the same commit. Adding one of those names
     * WITHOUT editing the callers would buy nothing: this method would still be
     * public, and there would now be two ways to do the same thing.
     */
    public function set(int $masjidId): void
    {
        // A raw id carries no provenance, so any membership held alongside it
        // is dropped — UNLESS it names this very masjid. That exception is what
        // keeps the save/restore round-trips non-destructive: both
        // ImpactMetrics::withTenant() and runWithout() below re-bind the
        // previous id through this method, and neither of them is trying to
        // erase how the request was admitted.
        if ($this->membership !== null && (int) $this->membership->masjid_id !== $masjidId) {
            $this->membership = null;
        }

        $this->masjidId = $masjidId;
    }

    /**
     * Bind the context from a VERIFIED membership.
     *
     * The design's whole point (docs/multi-tenant-admin-design.md, "Binding,
     * fail-closed"): the tenant is a fact the server established about this
     * user, not a number that arrived with the request. Taking the row rather
     * than its `masjid_id` means a caller cannot bind an id it merely believes
     * in — it has to hold the grant.
     *
     * A membership with no masjid cannot admit anyone, so it is refused rather
     * than quietly binding nothing (which would read as UNBOUND, i.e. no filter
     * at all — the worst possible failure in a database with no row-level
     * security). The resolver never produces one; this is the backstop that
     * makes a future caller's mistake loud.
     */
    public function setFromMembership(MasjidUser $membership): void
    {
        $masjidId = (int) $membership->masjid_id;

        if ($masjidId <= 0) {
            throw new InvalidArgumentException(
                'TenantContext cannot bind a membership with no masjid_id; an unbound context means NO filter.'
            );
        }

        $this->masjidId = $masjidId;
        $this->membership = $membership;
    }

    /**
     * The membership that admitted the current request, or null when the
     * binding was route-derived (SuperAdmin) or set by system code.
     *
     * Read by nothing in S3. It exists because S4 has to echo the
     * server-resolved tenant on every response, and reconstructing "which grant
     * was this?" after the fact is exactly the guesswork this slice removed.
     */
    public function membership(): ?MasjidUser
    {
        return $this->membership;
    }

    /**
     * The bound masjid_id, or null when unbound.
     *
     * THIS IS THE ECHO. The design requires every admin response to carry the
     * server-resolved tenant and the SPA chrome to render THAT rather than its
     * own store, so that a switch which half-failed shows as the wrong org name
     * instead of the right name over the wrong rows. This method is the value
     * to echo, and it is only trustworthy after ResolveMasjidTenant has run:
     * read it, never `$request->route('masjid_id')`, which is what the caller
     * asked for rather than what the server granted (they differ on exactly the
     * requests that matter).
     *
     * `null` is a MEANING, not a missing value: "this response is not scoped to
     * one organisation" — a SuperAdmin's cross-masjid list, or a multi-tenant
     * admin on an account route. A client that treats null as "unchanged" will
     * keep painting the previously selected organisation over rows that came
     * from all of them. Echo the key with a null value; never omit it.
     */
    public function get(): ?int
    {
        return $this->masjidId;
    }

    /** True only when a tenant is bound (drives whether models auto-filter). */
    public function hasTenant(): bool
    {
        return $this->masjidId !== null;
    }

    /** Clear the binding (return to the unbound / no-filter state). */
    public function forgetTenant(): void
    {
        $this->masjidId = null;
        // The provenance goes with the binding: an unbound context was admitted
        // by nothing, and a stale membership hanging off it would let S4 report
        // a tenant the models are no longer filtering by.
        $this->membership = null;
    }

    /**
     * Run $callback with the tenant temporarily UNBOUND, then restore the
     * previous binding. Use for super-admin / system code that must reach
     * across masjids from inside a request where a tenant is already bound.
     *
     * @template TReturn
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public function runWithout(callable $callback): mixed
    {
        $previous = $this->masjidId;
        $previousMembership = $this->membership;

        $this->masjidId = null;
        $this->membership = null;

        try {
            return $callback();
        } finally {
            // Restore BOTH halves. Restoring the id alone would leave the
            // request bound but with no record of what admitted it, which is
            // indistinguishable from a system binding.
            $this->masjidId = $previous;
            $this->membership = $previousMembership;
        }
    }
}
