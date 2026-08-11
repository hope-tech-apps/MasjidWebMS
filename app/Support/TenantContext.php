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
 *   - `set(int)` — a raw id with NO provenance. It remains public because two
 *     callers legitimately have no membership to offer: ResolveMasjidTenant's
 *     SuperAdmin branch (a SuperAdmin is bound from the ROUTE, not from a
 *     grant — they hold no memberships at all) and system/reporting code that
 *     binds a masjid it already resolved server-side (ImpactMetrics::
 *     withTenant). It is `@internal`: a controller or FormRequest must never
 *     call it, because the id it would pass came from the client.
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
     * @internal Reserved for ResolveMasjidTenant's SuperAdmin (route-derived)
     * branch and for system/reporting code that resolved the masjid itself.
     * Request code that binds on behalf of an ADMIN must use
     * setFromMembership() so the id is one the server verified — see the class
     * docblock and .claude/rules/tenant-scoping.md.
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

    /** The bound masjid_id, or null when unbound. */
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
