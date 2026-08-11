<?php

namespace App\Support;

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
 */
class TenantContext
{
    /** Current tenant's masjid_id, or null when the context is unbound. */
    private ?int $masjidId = null;

    /** Bind the context to a single masjid. */
    public function set(int $masjidId): void
    {
        $this->masjidId = $masjidId;
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
        $this->masjidId = null;

        try {
            return $callback();
        } finally {
            $this->masjidId = $previous;
        }
    }
}
