<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantBindingProbeJob;
use Tests\TestCase;

/**
 * S1 — the tenant binding must not survive from one queued job to the next.
 *
 * `TenantContext` was a container singleton, and `forgetTenant()` had no
 * production callers. In `queue:work` — one process, many jobs — that means a
 * job that binds masjid A leaves masjid A bound for whatever job the worker
 * picks up next. BelongsToMasjid's global scope then filters and stamps that
 * second job's rows with the first job's tenant, with no error anywhere.
 *
 * These tests exercise the REAL `Illuminate\Queue\Worker` against the REAL
 * `database` queue driver rather than `Queue::fake()` or `dispatchSync()`,
 * because the bug is a property of the process lifetime and a fake has no
 * process lifetime. The suite's own connection is `sync` (phpunit.xml), which
 * is exactly the configuration that CANNOT reproduce it — so each test that
 * needs a worker switches the default connection to `database` first.
 *
 * Read together, the four tests pin both halves of the fix and the one place it
 * must NOT reach:
 *   - the container binding is `scoped`, so the worker's own scope reset clears it;
 *   - the `JobProcessing` listener clears it on the worker paths that never reset
 *     the scope;
 *   - the full `daemon()` loop — literally what `queue:work` runs — starts every
 *     job unbound;
 *   - a `sync` job still sees, and still leaves intact, the binding of the
 *     request that dispatched it.
 */
class QueueTenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same idiom as TenantIsolationTest: this suite must never need a
        // network DB to run.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        TenantBindingProbeJob::forget();
        app(TenantContext::class)->forgetTenant();
    }

    protected function tearDown(): void
    {
        TenantBindingProbeJob::forget();

        parent::tearDown();
    }

    /**
     * The container binding itself. `queue:work`'s per-iteration reset is
     * `$app->forgetScopedInstances()` (see the `$resetScope` closure in
     * Illuminate\Queue\QueueServiceProvider::registerWorker) — a call that a
     * `singleton()` binding shrugs off entirely. This asserts the exact
     * mechanism, so reverting `scoped()` to `singleton()` fails here even if
     * every other test still passed via the listener.
     */
    #[Test]
    public function the_tenant_context_is_a_scoped_binding_not_a_singleton(): void
    {
        $first = app(TenantContext::class);
        $first->set(41);

        $this->assertSame(
            41,
            app(TenantContext::class)->get(),
            'Within one scope every resolution must be the same instance — a request binds once and every model reads it.'
        );

        // Exactly what the queue worker does between jobs.
        $this->app->forgetScopedInstances();

        $this->assertNotSame(
            $first,
            app(TenantContext::class),
            'TenantContext survived forgetScopedInstances(), so it is still registered as a singleton.'
        );
        $this->assertNull(
            app(TenantContext::class)->get(),
            'A container-scope reset must produce an UNBOUND tenant context.'
        );
    }

    /**
     * The listener, isolated. `Worker::runNextJob()` — the path behind
     * `queue:work --once` and `queue:listen` — does NOT reset the container
     * scope, so nothing here is doing the work except
     * ResetTenantContextBetweenJobs. Two jobs, two different masjids, one
     * process, in sequence: the second must not inherit the first's binding.
     */
    #[Test]
    public function a_queued_job_does_not_inherit_the_previous_jobs_tenant_binding(): void
    {
        $this->useDatabaseQueue();

        TenantBindingProbeJob::dispatch(1, 101);
        TenantBindingProbeJob::dispatch(2, 202);

        $worker = $this->app->make('queue.worker');
        $options = new WorkerOptions(sleep: 0, maxTries: 1);

        $worker->runNextJob('database', 'default', $options);
        $worker->runNextJob('database', 'default', $options);

        $this->assertBothJobsRanCleanly();

        $this->assertSame(
            [null, null],
            TenantBindingProbeJob::inherited(),
            'Job 2 must start with an unbound tenant. Seeing 101 means job 1 leaked masjid A into masjid B\'s job.'
        );
    }

    /**
     * The whole `queue:work` loop, unmodified: `Worker::daemon()` with
     * `--stop-when-empty`, which is the same method the production worker runs
     * and the only path that exercises the container-scope reset. Bounded by
     * `maxJobs` and a zero sleep so it cannot hang the suite.
     *
     * `memory` is raised well above `--memory`'s 128MB default deliberately.
     * `daemon()` re-checks `memoryExceeded()` after every job, and a PHPUnit
     * process that has already run a thousand tests is comfortably past 128MB —
     * so the default quietly stops the worker after ONE job, and this test would
     * pass or fail depending on where it landed in the suite.
     */
    #[Test]
    public function the_real_worker_loop_starts_every_job_with_an_unbound_tenant(): void
    {
        $this->useDatabaseQueue();

        TenantBindingProbeJob::dispatch(1, 303);
        TenantBindingProbeJob::dispatch(2, 404);

        $this->app->make('queue.worker')->daemon('database', 'default', new WorkerOptions(
            memory: 4096,
            sleep: 0,
            maxTries: 1,
            stopWhenEmpty: true,
            maxJobs: 2,
        ));

        $this->assertBothJobsRanCleanly();

        $this->assertSame(
            [null, null],
            TenantBindingProbeJob::inherited(),
            'Under the real queue:work loop, every job must begin unbound.'
        );
    }

    /**
     * The exemption, and the acceptance bar for this slice: behaviour under the
     * `sync` driver — what the whole test suite and any worker-less environment
     * runs on — is unchanged. A sync job executes inside the dispatching
     * request, so it must still SEE that request's tenant, and must still leave
     * it bound afterwards. A reset that did not check the driver would silently
     * unbind the rest of the request and turn its scoped queries into
     * unfiltered ones.
     */
    #[Test]
    public function a_sync_job_neither_loses_nor_clears_the_dispatchers_tenant(): void
    {
        config(['queue.default' => 'sync']);

        $tenant = app(TenantContext::class);
        $tenant->set(7);

        TenantBindingProbeJob::dispatch(1, 7);

        $this->assertSame(
            [7],
            TenantBindingProbeJob::inherited(),
            'A synchronously dispatched job runs inside the request and must see the request\'s tenant.'
        );
        $this->assertSame(
            7,
            app(TenantContext::class)->get(),
            'Dispatching a sync job must not unbind the request that dispatched it.'
        );
    }

    /** Push onto the real database queue instead of running jobs inline. */
    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
    }

    /**
     * Guard against the assertions above passing (or failing) for the wrong
     * reason: the worker swallows job exceptions into `failed_jobs`, so a job
     * that threw would otherwise show up only as a missing observation.
     */
    private function assertBothJobsRanCleanly(): void
    {
        $this->assertSame(
            0,
            DB::table('failed_jobs')->count(),
            'A probe job failed; the tenant assertions below would be meaningless.'
        );
        $this->assertCount(
            2,
            TenantBindingProbeJob::$observations,
            'Expected the worker to run exactly two probe jobs.'
        );
        $this->assertSame([1, 2], array_column(TenantBindingProbeJob::$observations, 'job'));
    }
}
