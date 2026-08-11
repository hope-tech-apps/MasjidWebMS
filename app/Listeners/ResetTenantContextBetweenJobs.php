<?php

namespace App\Listeners;

use App\Support\TenantContext;
use Illuminate\Queue\Events\JobProcessing;

/**
 * S1 — the queue half of the request-scoped tenant fix
 * (docs/multi-tenant-admin-design.md, "Binding, fail-closed").
 *
 * THE BUG. `TenantContext` was a container **singleton**. In a web request that
 * is harmless — PHP throws the whole process away at the end of it. A queue
 * worker is the opposite: `queue:work` is one long-lived process that runs job
 * after job after job in the same container. Anything a job binds stays bound.
 * So a job that binds masjid A — `ImpactMetrics::withTenant()` does, the demo
 * tenant seeder does, and any future job that resolves a tenant will — hands
 * its binding to the NEXT job off the queue, which may belong to masjid B.
 * That job then reads and writes through `BelongsToMasjid`'s global scope under
 * someone else's tenant, silently: no exception, no log line, wrong rows.
 *
 * `forgetTenant()` had zero production callers, so nothing was undoing it.
 *
 * THE FIX IS IN TWO PARTS, and they cover different processes:
 *
 *  1. `AppServiceProvider` binds `TenantContext` with `scoped()` instead of
 *     `singleton()`. `queue:work`'s daemon loop calls `forgetScopedInstances()`
 *     before reserving each job (`Illuminate\Queue\QueueServiceProvider`'s
 *     `$resetScope` callback), so a scoped binding is rebuilt from nothing every
 *     iteration. That alone closes the leak for the real worker.
 *  2. This listener, which resets the binding at `JobProcessing` — the moment a
 *     job is about to run, on every code path that runs one, including
 *     `Worker::runNextJob()` (`queue:work --once`, `queue:listen`), which does
 *     NOT reset the container scope. Belt and braces for the same invariant:
 *     a job starts UNBOUND unless it binds a tenant itself.
 *
 * SYNC IS DELIBERATELY EXEMPT, and that exemption is the whole reason this is a
 * listener rather than a blanket reset. `SyncQueue` raises `JobProcessing` too,
 * but a sync job is not a separate unit of work — it runs INSIDE the dispatching
 * request, on that request's stack, under that request's tenant. Clearing the
 * context there would silently unbind the caller for the remainder of the
 * request and turn a tenant-scoped query into an unfiltered one. The test suite
 * runs `QUEUE_CONNECTION=sync` (phpunit.xml), and so does any local `.env` that
 * has not set up a worker, so this is not a hypothetical. The design's own
 * invariant is phrased the same way: the context is null at the start of every
 * job "under `queue:work` (not `sync`)".
 *
 * Note that this class is registered TWICE and that is fine: explicitly in
 * `AppServiceProvider::boot()`, and again by Laravel's event discovery, which
 * `Application::configure()` turns on for everything under `app/Listeners` with
 * a typed `handle()`. The explicit registration stays because this is a
 * correctness invariant and should not depend on discovery still being enabled;
 * `forgetTenant()` is idempotent, so running it twice is a no-op.
 */
class ResetTenantContextBetweenJobs
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(JobProcessing $event): void
    {
        if ($this->runsInlineInTheDispatchingProcess($event->connectionName)) {
            return;
        }

        $this->tenant->forgetTenant();
    }

    /**
     * True when the job is being executed inline by whoever dispatched it, so
     * the surrounding tenant binding belongs to the caller and is not ours to
     * clear. Resolved by DRIVER, not by connection name, because a connection
     * using the sync driver may be called anything.
     */
    private function runsInlineInTheDispatchingProcess(?string $connectionName): bool
    {
        if ($connectionName === null || $connectionName === 'sync') {
            return true;
        }

        return config("queue.connections.{$connectionName}.driver") === 'sync';
    }
}
