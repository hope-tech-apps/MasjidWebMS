<?php

namespace Tests\Feature;

use App\Http\Middleware\EchoResolvedTenant;
use App\Http\Middleware\ResolveMasjidTenant;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\TenantResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantBindingProbeJob;
use Tests\TestCase;

/**
 * The nine invariants of docs/multi-tenant-admin-design.md ("Invariants tests
 * must pin"), one test each, measured over S0-S5 together.
 *
 * ------------------------------------------------------------------------------
 * Why a separate file when S0-S3 each shipped with their own suite
 * ------------------------------------------------------------------------------
 *
 * MasjidOwnershipUniquenessTest (S0), QueueTenantContextTest (S1),
 * MasjidMembershipPivotTest (S2) and TenantResolverTest (S3) each prove their
 * own slice and each is the more thorough treatment of the invariant it owns.
 * None of them can prove the property the DESIGN is about, because that property
 * is a statement over all five slices at once: with `tenancy.multi_membership`
 * false, an admin request resolves exactly as it did before any of this landed,
 * and every branch that cannot name one verified masjid refuses instead of
 * falling through.
 *
 * So this file is deliberately the short, whole-system restatement: nine tests
 * named for the nine numbered invariants, each failing on its own if the
 * behaviour it describes regresses, plus the byte-identical section that is the
 * acceptance bar for shipping S4 and S5 dark. Where a slice's own suite already
 * proves something more deeply, the test here says so and still asserts it —
 * an invariant that is only pinned somewhere else is an invariant that can be
 * deleted by someone who never read this list.
 *
 * ------------------------------------------------------------------------------
 * Invariant 7 lives in its own file
 * ------------------------------------------------------------------------------
 *
 * "All the `*TenantIsolationTest` files still pass with the actor holding
 * memberships in BOTH organisations" is a statement about thirty-one other
 * files, and it needs the roster and the fixture they share. It is
 * tests/Feature/DualMembershipIsolationTest.php.
 *
 * Sqlite-in-memory + RefreshDatabase per the testing convention.
 */
class MultiTenantAdminInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

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

        // Seeded BEFORE any user is created, because UserObserver bridges
        // `users.type` onto a spatie role at save time and silently skips when
        // the role does not exist yet — an admin created first would hold no
        // CRM permission and every HTTP assertion below would 403 for a reason
        // that has nothing to do with tenancy.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();
    }

    // ------------------------------------------------------------ invariant 1

    /**
     * **1. No membership and no route masjid -> 403, never unbound.**
     *
     * The distinction this test exists for: an unbound context is not a cautious
     * default, it is NO FILTER (there is no row-level security under MySQL). A
     * principal the resolver cannot place must therefore be refused, not allowed
     * through to a query that returns every organisation's rows.
     */
    #[Test]
    public function invariant_1_an_admin_with_no_grant_and_no_route_masjid_is_refused(): void
    {
        $stray = $this->masjidAdmin();

        // Somebody else's organisation exists and is full of rows, so a fall
        // through to "unbound" would be visible rather than vacuous.
        $someoneElses = $this->makeMasjid(['user_id' => $this->masjidAdmin()->id]);
        Contact::factory()->count(2)->create(['masjid_id' => $someoneElses->id]);

        $refusal = $this->refusalFrom($this->makeAuthedRequest($stray));

        $this->assertSame(403, $refusal['status']);
        $this->assertSame(ResolveMasjidTenant::FORBIDDEN_MESSAGE, $refusal['message']);
        $this->assertFalse(
            $this->tenant->hasTenant(),
            'a refused request must leave NOTHING bound — an unbound context is an unfiltered one'
        );
    }

    /**
     * The teeth behind invariant 1: the one list that can turn "403" into
     * "unbound" must never cover a tenant-scoped route.
     *
     * `TenantResolver::UNSCOPED_ADMIN_ROUTES` is the single allowlist on which a
     * principal the resolver cannot place is let through with no binding. That
     * is correct for `/api/admin/user` — it reads the caller's own `users` row —
     * and catastrophic for anything that touches a `BelongsToMasjid` model,
     * because unbound means no filter. A single wildcard entry
     * (`api/admin/masjids/*`) would be enough, and it would look like a
     * convenience rather than a leak.
     *
     * So the list is asserted against real tenant-scoped paths rather than
     * against its own text: whatever it is spelled as, it must not match any of
     * these.
     */
    #[Test]
    public function invariant_1_the_unbound_allowlist_never_covers_a_tenant_scoped_route(): void
    {
        /** @var list<string> $allowlist */
        $allowlist = (new ReflectionClass(TenantResolver::class))->getConstant('UNSCOPED_ADMIN_ROUTES');

        $this->assertIsArray($allowlist, 'UNSCOPED_ADMIN_ROUTES must still exist and still be a list of path patterns.');

        $tenantScopedPaths = [
            'api/admin/masjids/5/contacts',
            'api/admin/masjids/5/contacts/9',
            'api/admin/masjids/5/donations',
            'api/admin/masjids/5/funds',
            'api/admin/masjids/5/groups/2/posts',
            'api/admin/masjids/5/properties',
            'api/admin/masjids/5/registrations',
        ];

        foreach ($tenantScopedPaths as $path) {
            $this->assertFalse(
                Str::is($allowlist, $path),
                "{$path} reads tenant-scoped rows, so allowlisting it hands every organisation's to an admin the resolver could not place."
            );
        }
    }

    // ------------------------------------------------------------ invariant 2

    /**
     * **2. A route masjid the user holds no pivot row for -> 403, for BOTH roles.**
     *
     * "Both roles" is read as *every principal whose binding is
     * membership-derived*, whatever their advisory `pivot.role`: MasjidAdmin,
     * Teacher and LunchStaff all reach `ResolveMasjidTenant::applyVerdict()`.
     * The design was written when MasjidAdmin was the only one; the realms grew
     * and the invariant is about the mechanism, not about the count.
     *
     * A SuperAdmin is deliberately NOT in the list and that is asserted
     * separately below, because their binding is route-derived by design and
     * folding them in here would 403 them out of every organisation they are not
     * a member of — which is all of them.
     */
    #[Test]
    public function invariant_2_a_route_masjid_with_no_pivot_row_is_refused_for_every_membership_derived_realm(): void
    {
        foreach (['MasjidAdmin', 'Teacher', User::TYPE_LUNCH_STAFF] as $type) {
            // Each realm starts from nothing, so an assertion about one cannot
            // be satisfied (or spoiled) by the binding left behind by another.
            $this->tenant->forgetTenant();

            $principal = $this->user($type);
            $theirs = $this->makeMasjid();
            $this->membership($principal, $theirs, ['is_default' => true, 'role' => User::TYPE_ROLE_MAP[$type]]);

            $stranger = $this->makeMasjid();

            $refusal = $this->refusalFrom($this->makeAuthedRequest($principal, $stranger->id));

            $this->assertSame(403, $refusal['status'], "{$type} must be refused a masjid they hold no membership in.");
            $this->assertSame(ResolveMasjidTenant::FORBIDDEN_MESSAGE, $refusal['message'], 'the refusal never says WHICH check refused.');
            $this->assertFalse($this->tenant->hasTenant());

            // And holding a grant somewhere else does not soften it: the id in
            // the URL is answered yes or no, never substituted for one they do
            // hold.
            $this->assertNotSame($theirs->id, $this->tenant->get());
        }
    }

    /**
     * The same refusal over the real HTTP stack, where the SPA meets it — and
     * with the neighbour's rows actually present, so a scope that failed open
     * would return a 200 full of them rather than an empty 200.
     *
     * The STATUS is asserted and the body is not. `abort(403)`'s message reaches
     * the client only in debug (bootstrap/app.php renders through
     * `Errors::publicMessage`, which substitutes a generic sentence when
     * `app.debug` is false), so asserting it here would pin the test to whatever
     * APP_DEBUG the machine running the suite happens to have. The message itself
     * is pinned byte-for-byte where it is produced, in the middleware-level tests
     * above.
     */
    #[Test]
    public function invariant_2_a_foreign_masjid_in_the_url_is_a_403_over_http(): void
    {
        $admin = $this->masjidAdmin();
        $this->makeMasjid(['user_id' => $admin->id, 'crm_enabled' => true]);

        $foreign = $this->makeMasjid(['crm_enabled' => true]);
        Contact::factory()->count(3)->create(['masjid_id' => $foreign->id]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/masjids/{$foreign->id}/contacts")->assertStatus(403);
    }

    /**
     * The counterpart that keeps invariant 2 from being over-applied: a
     * SuperAdmin holds no membership anywhere and is still bound to the masjid
     * the route names.
     *
     * Asserted here rather than left to TenantResolverTest because the two
     * statements are one decision — "a route masjid without a pivot row is a
     * 403" is true only of the membership-derived realms, and a reader who took
     * it as universal would move the SuperAdmin branch and lock every Manara
     * operator out of every organisation.
     */
    #[Test]
    public function invariant_2_does_not_apply_to_a_super_admin_who_binds_from_the_route(): void
    {
        $super = $this->superAdmin();
        $masjid = $this->makeMasjid();

        $this->assertSame(0, $super->memberships()->count(), 'fixture: S2 deliberately gave SuperAdmins no rows');

        $this->handleThrough($this->makeAuthedRequest($super, $masjid->id));

        $this->assertSame($masjid->id, $this->tenant->get());
        $this->assertNull($this->tenant->membership(), 'a route-derived binding carries no grant, and must say so');
    }

    // ------------------------------------------------------------ invariant 3

    /**
     * **3. Two `is_default` rows for one user -> the DATABASE rejects it.**
     *
     * Application-level enforcement would leave the three holes that made
     * validation-only ownership uniqueness worthless before S0: a seeder, an
     * artisan command, a hand-written UPDATE. On SQLite the guarantee is a
     * partial unique index; on MySQL it is the `default_key` STORED generated
     * column (.claude/rules/migrations.md). Either way the assertion is the
     * same: the write fails.
     *
     * Written with a RAW `MasjidUser::create()` rather than this file's
     * `membership()` helper, and on masjids with no owner. The helper clears any
     * other default first (because the owner-membership hook may have written
     * one), which is exactly the tidying that would make this test pass while
     * asserting nothing; and an owned masjid would bring a hook-written row with
     * it.
     */
    #[Test]
    public function invariant_3_the_database_refuses_a_second_default_membership(): void
    {
        $admin = $this->masjidAdmin();

        MasjidUser::create([
            'masjid_id' => $this->makeMasjid()->id,
            'user_id' => $admin->id,
            'role' => 'masjid-admin',
            'is_default' => true,
        ]);

        $rejected = false;

        try {
            MasjidUser::create([
                'masjid_id' => $this->makeMasjid()->id,
                'user_id' => $admin->id,
                'role' => 'masjid-admin',
                'is_default' => true,
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'A second is_default row was accepted, so "which organisation does this admin open in?" is non-deterministic again.'
        );
        $this->assertSame(1, $admin->memberships()->where('is_default', true)->count());
    }

    // ------------------------------------------------------------ invariant 4

    /**
     * **4. `TenantContext` is null at the start AND the end of every job, under
     * a real queue worker, not the `sync` driver.**
     *
     * `Worker::runNextJob()` is the path behind `queue:work --once` and
     * `queue:listen`, and it does NOT reset the container scope — so nothing
     * here is keeping jobs apart except
     * `App\Listeners\ResetTenantContextBetweenJobs`. Two jobs, two different
     * masjids, one process: the second must start from nothing.
     *
     * THE END-OF-JOB HALF IS ASSERTED AS THE STRONGEST THING THAT IS ACTUALLY
     * TRUE, and the difference matters. Laravel resets the scope at the TOP of
     * the worker's next iteration (`Worker::daemon()`), and the listener fires on
     * `JobProcessing` — both are *before* a job, not after one. So a job that
     * binds a tenant and returns is still bound when `JobProcessed` fires, and
     * asserting a literal null there would fail. What the design is protecting —
     * and what is asserted — is that no job ever ENDS holding a tenant it did not
     * bind itself, i.e. nothing survives from one job into the next.
     *
     * The residue is real and is deliberately NOT pinned as if it were intended.
     * The exposed window is everything that runs between a job's return and the
     * NEXT job's `JobProcessing`: a `JobProcessed` or `JobFailed` listener, and
     * the worker's own bookkeeping. This application registers no such listener
     * today (`ProcessFlyerCutout::failed()` does read a `BelongsToMasjid` model,
     * but `failed()` runs INSIDE its own job's iteration, after that job's
     * `JobProcessing` cleared the context, so it is correctly scoped). Closing
     * the window properly is a second listener in app/Listeners, which is not
     * this slice's file to write.
     *
     * QueueTenantContextTest owns the deeper treatment (the `scoped()` binding
     * itself, the full `daemon()` loop, and the `sync` exemption). This is the
     * invariant restated so it cannot be lost with that file.
     */
    #[Test]
    public function invariant_4_no_queued_job_starts_or_ends_holding_another_jobs_tenant(): void
    {
        TenantBindingProbeJob::forget();

        // The suite runs `sync` (phpunit.xml), which is exactly the driver that
        // CANNOT reproduce the leak: a sync job runs inside the dispatching
        // request. A real queue is the whole point of the test.
        config(['queue.default' => 'database']);

        /** @var list<int|null> $endedHolding */
        $endedHolding = [];
        Event::listen(JobProcessed::class, function () use (&$endedHolding): void {
            $endedHolding[] = app(TenantContext::class)->get();
        });

        TenantBindingProbeJob::dispatch(1, 101);
        TenantBindingProbeJob::dispatch(2, 202);

        $worker = $this->app->make('queue.worker');
        $options = new WorkerOptions(sleep: 0, maxTries: 1);

        $worker->runNextJob('database', 'default', $options);
        $worker->runNextJob('database', 'default', $options);

        // The worker swallows a job exception into `failed_jobs`, so a job that
        // threw would show up only as a missing observation and every assertion
        // below would pass for the wrong reason.
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'a probe job failed; the tenant assertions are meaningless.');
        $this->assertCount(2, TenantBindingProbeJob::$observations, 'expected the worker to run exactly two probe jobs.');

        $this->assertSame(
            [null, null],
            TenantBindingProbeJob::inherited(),
            'Every job must START unbound. Seeing 101 in the second slot means job 1 leaked masjid A into masjid B\'s job.'
        );

        $this->assertSame(
            [101, 202],
            $endedHolding,
            'A job must END holding at most the tenant IT bound. 101 in the second slot is job 1\'s binding outliving job 1.'
        );

        TenantBindingProbeJob::forget();
    }

    // ------------------------------------------------------------ invariant 5

    /**
     * **5. A request issued immediately after a switch cannot return the
     * previous organisation's rows.**
     *
     * The server half, which is the half that produces rows. A switch is, on the
     * wire, nothing more than the next request naming a different `{masjid_id}`
     * — so the invariant is that consecutive requests from ONE authenticated
     * session, in one process, are scoped independently. If a binding could
     * survive between them (the bug `scoped()` fixed for the queue), the second
     * request would return the first organisation's rows with a 200 and no error
     * anywhere.
     *
     * Asserted with the gate OPEN, because with it shut there is nothing to
     * switch between and the test would pass vacuously.
     *
     * Every response also carries `X-Tenant-Id`, and the assertions read it: it
     * is what lets the SPA notice a mis-scoped payload instead of painting one
     * organisation's name over another's rows. The client half of this invariant
     * — dropping responses that were already on the wire when the user clicked —
     * lives in resources/vue-app/core/tenancy/tenantRequests.ts and cannot be
     * executed here; see tests/Feature/TenantApiSurfaceTest.php for the part of
     * it that IS checkable from PHP (the header name is a contract between the
     * two files).
     */
    #[Test]
    public function invariant_5_a_request_after_a_switch_never_returns_the_previous_organisations_rows(): void
    {
        $this->openTheMultiMembershipGate();

        $admin = $this->masjidAdmin();
        $a = $this->makeMasjid(['user_id' => $admin->id, 'crm_enabled' => true]);
        $b = $this->makeMasjid(['crm_enabled' => true]);

        $this->membership($admin, $a, ['is_default' => true]);
        $this->membership($admin, $b);

        $inA = Contact::factory()->count(3)->create(['masjid_id' => $a->id])->pluck('id')->sort()->values()->all();
        $inB = Contact::factory()->count(2)->create(['masjid_id' => $b->id])->pluck('id')->sort()->values()->all();

        Sanctum::actingAs($admin);

        // The organisation the admin was in.
        $first = $this->getJson("/api/admin/masjids/{$a->id}/contacts")->assertOk();
        $this->assertSame($inA, $this->returnedContactIds($first));
        $first->assertHeader(EchoResolvedTenant::TENANT_HEADER, (string) $a->id);

        // The switch: the very next request names the other organisation.
        $second = $this->getJson("/api/admin/masjids/{$b->id}/contacts")->assertOk();
        $this->assertSame(
            $inB,
            $this->returnedContactIds($second),
            'The request after the switch returned the previous organisation\'s rows — the binding survived the request that made it.'
        );
        $second->assertHeader(EchoResolvedTenant::TENANT_HEADER, (string) $b->id);

        // And back, so the assertion is about independence rather than about
        // "the second request happens to win".
        $third = $this->getJson("/api/admin/masjids/{$a->id}/contacts")->assertOk();
        $this->assertSame($inA, $this->returnedContactIds($third));
        $third->assertHeader(EchoResolvedTenant::TENANT_HEADER, (string) $a->id);
    }

    /**
     * The echo is read from what the server BOUND, never from the URL — which is
     * the only reason it is worth anything.
     *
     * A refused request resolved no tenant, so it must not come back carrying the
     * organisation it asked for. Echoing the route parameter would confirm to the
     * SPA whatever the SPA just asked for — a mirror, not a check — and the
     * mismatch notice could never fire.
     *
     * TWO OUTCOMES SATISFY THAT, and the assertion allows both on purpose. The
     * header is absent if the refusal really unwinds past EchoResolvedTenant, and
     * reads `unbound` if it does not — and on a ROUTE middleware it does not:
     * `Illuminate\Routing\Pipeline::carry()` wraps every pipe in its own
     * try/catch, so `tenant`'s `abort(403)` is rendered into a response INSIDE
     * the pipeline and handed back up to EchoResolvedTenant as an ordinary
     * return value, which stamps it with the (null) binding. The middleware's own
     * docblock says "rendered unstamped"; that sentence describes the intent
     * rather than the framework. Either behaviour is safe — neither names the
     * refused organisation — so this test pins the property and not the
     * mechanism, and stays green whichever way that comment is reconciled.
     */
    #[Test]
    public function invariant_5_a_refused_request_is_not_stamped_with_the_organisation_it_asked_for(): void
    {
        $admin = $this->masjidAdmin();
        $this->makeMasjid(['user_id' => $admin->id, 'crm_enabled' => true]);
        $foreign = $this->makeMasjid(['crm_enabled' => true]);

        Sanctum::actingAs($admin);

        $refused = $this->getJson("/api/admin/masjids/{$foreign->id}/contacts")->assertStatus(403);

        $echoed = $refused->headers->get(EchoResolvedTenant::TENANT_HEADER);

        $this->assertTrue(
            $echoed === null || $echoed === EchoResolvedTenant::UNBOUND,
            "A refused request came back echoing '{$echoed}'. A 403 bound nothing, so the only honest echoes are none at all and '"
                . EchoResolvedTenant::UNBOUND . "'."
        );
        $this->assertNotSame(
            (string) $foreign->id,
            $echoed,
            'A 403 must never echo the masjid the caller named; that is the URL talking back, not the server.'
        );
    }

    // ------------------------------------------------------------ invariant 6

    /**
     * **6. The role x permission matrix is byte-identical before and after;
     * `Permission::count() === 8`.**
     *
     * The design ships `pivot.role` ADVISORY: authorization stays on the global
     * `users.type` bridge, spatie's `teams` feature stays off (flipping it ALTERs
     * `model_has_roles`' primary key and is not reversible alongside this work),
     * and no slice of this initiative mints a permission. The matrix is written
     * out in full rather than counted, because "still 8" would survive one
     * permission being renamed into another.
     */
    #[Test]
    public function invariant_6_the_role_permission_matrix_is_unchanged_and_there_are_still_eight(): void
    {
        $expected = [
            'super-admin' => [
                'manage contacts', 'manage donations', 'manage funds', 'manage properties',
                'view contacts', 'view donations', 'view donor pii', 'view properties',
            ],
            'masjid-admin' => [
                'manage contacts', 'manage donations', 'manage funds', 'manage properties',
                'view contacts', 'view donations', 'view donor pii', 'view properties',
            ],
            'member' => [],
            'teacher' => [],
            'lunch-staff' => [],
        ];

        $this->assertSame(8, Permission::count(), 'this initiative mints no permission — the set is 8 and stays 8.');

        foreach ($expected as $role => $permissions) {
            $actual = Role::where('name', $role)->firstOrFail()
                ->permissions->pluck('name')->sort()->values()->all();

            $this->assertSame($permissions, $actual, "the {$role} row of the matrix changed.");
        }

        $this->assertFalse(
            (bool) config('permission.teams'),
            'spatie teams stays OFF — per-tenant roles are explicitly deferred to a separate initiative.'
        );
    }

    /**
     * ...and `pivot.role` really does authorize nothing, end to end.
     *
     * An admin whose bridged spatie role has been stripped holds no CRM
     * permission. Giving them a `masjid_user` row that SAYS `masjid-admin` must
     * not give it back. If it ever does, the pivot has quietly become an
     * authorization source and the matrix above is no longer the whole story.
     */
    #[Test]
    public function invariant_6_a_pivot_role_grants_no_permission(): void
    {
        $admin = $this->masjidAdmin();
        $masjid = $this->makeMasjid(['user_id' => $admin->id, 'crm_enabled' => true]);
        $this->membership($admin, $masjid, ['is_default' => true, 'role' => 'masjid-admin']);

        // syncRoles only touches pivots (no user save), so UserObserver does not
        // re-bridge the role back on.
        $admin->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($admin);

        // The tenant binds (the membership is real), and the permission gate
        // still refuses: the pivot role is a label, not a grant.
        $this->getJson("/api/admin/masjids/{$masjid->id}/contacts")->assertStatus(403);

        $this->assertCount(0, $admin->fresh()->getAllPermissions());
    }

    // ------------------------------------------------------------ invariant 8

    /**
     * **8. A `masjid_id` in the request body or header is ignored while a tenant
     * is bound.**
     *
     * Three layers have to hold together and all three are exercised here: the
     * resolver reads the ROUTE parameter and nothing else
     * (`ResolveMasjidTenant::routeMasjidId()`), the controller passes only
     * validated fields to `create()`, and `BelongsToMasjid`'s creating hook
     * overrides `masjid_id` from the bound tenant regardless. Any one of them
     * alone would make this pass; the assertion is on the ROW that landed, so
     * losing two of the three still fails the test.
     */
    #[Test]
    public function invariant_8_a_masjid_id_in_the_body_or_a_header_is_ignored_while_bound(): void
    {
        $admin = $this->masjidAdmin();
        $mine = $this->makeMasjid(['user_id' => $admin->id, 'crm_enabled' => true]);
        $elsewhere = $this->makeMasjid(['crm_enabled' => true]);

        Sanctum::actingAs($admin);

        $created = $this->postJson(
            "/api/admin/masjids/{$mine->id}/contacts",
            [
                'first_name' => 'Forged',
                'last_name' => 'Tenant',
                // The forgery, in every place a client can put it.
                'masjid_id' => $elsewhere->id,
            ],
            ['X-Masjid-Id' => (string) $elsewhere->id],
        )->assertCreated();

        $this->assertDatabaseHas('contacts', [
            'id' => $created->json('data.id'),
            'masjid_id' => $mine->id,
        ]);
        $this->assertDatabaseMissing('contacts', ['masjid_id' => $elsewhere->id]);

        // ...and on the way out. A query string and a header naming the other
        // organisation must not widen a read either.
        $read = $this->getJson(
            "/api/admin/masjids/{$mine->id}/contacts?masjid_id={$elsewhere->id}",
            ['X-Masjid-Id' => (string) $elsewhere->id],
        )->assertOk();

        $read->assertHeader(EchoResolvedTenant::TENANT_HEADER, (string) $mine->id);
        $this->assertSame([$created->json('data.id')], $this->returnedContactIds($read));
    }

    // ------------------------------------------------------------ invariant 9

    /**
     * **9. `masjids.user_id` is unique among non-deleted rows.**
     *
     * S0, and everything downstream still rests on it: `User::masjid()` is a
     * `hasOne`, so two live owned masjids would not error — they would return one
     * ARBITRARY row, and `TenantResolver::soleOwnedMembership()` would bind
     * whichever the database felt like returning. That is the non-determinism the
     * whole design calls sand.
     *
     * MasjidOwnershipUniquenessTest owns the full treatment (the trashed-owner
     * exception, the update path, the reconcile command). This is the one-line
     * restatement, so the invariant cannot vanish with that file.
     */
    #[Test]
    public function invariant_9_the_database_refuses_a_second_live_masjid_for_one_owner(): void
    {
        $owner = $this->masjidAdmin();
        $this->makeMasjid(['user_id' => $owner->id]);

        $rejected = false;

        try {
            $this->makeMasjid(['user_id' => $owner->id]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'Two live masjids for one owner makes User::masjid() return an arbitrary row.');
        $this->assertSame(1, Masjid::where('user_id', $owner->id)->count());
    }

    // ------------------------------- the property the whole slice rests on ----

    /**
     * The shipped configuration, asserted before anything claims to be measured
     * under it. Every "a second membership grants nothing" assertion below is
     * only meaningful because this one holds.
     */
    #[Test]
    public function the_multi_membership_gate_ships_shut(): void
    {
        $shipped = require config_path('tenancy.php');

        $this->assertFalse($shipped['multi_membership'], 'config/tenancy.php must ship with the gate shut — it is S5\'s lever.');
        $this->assertNull(env('TENANCY_MULTI_MEMBERSHIP'), 'nothing may set TENANCY_MULTI_MEMBERSHIP; production runs on the shipped default.');
        $this->assertFalse(config('tenancy.multi_membership'));
    }

    /**
     * BYTE-IDENTICAL, the owner case: an admin who owns an organisation and also
     * holds a membership row in another binds the one they OWN and is refused the
     * other — over the real HTTP stack, with S4's echo and S5's payload in place.
     *
     * This is the production shape. If it ever changes without the flag changing,
     * S4 or S5 has leaked out of the dark.
     */
    #[Test]
    public function with_the_gate_shut_an_owners_second_membership_changes_nothing_over_http(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id, 'crm_enabled' => true]);
        $other = $this->makeMasjid(['crm_enabled' => true]);

        $this->membership($admin, $owned, ['is_default' => true]);
        $this->membership($admin, $other);

        $mine = Contact::factory()->count(2)->create(['masjid_id' => $owned->id])->pluck('id')->sort()->values()->all();
        Contact::factory()->count(4)->create(['masjid_id' => $other->id]);

        Sanctum::actingAs($admin);

        $ok = $this->getJson("/api/admin/masjids/{$owned->id}/contacts")->assertOk();
        $this->assertSame($mine, $this->returnedContactIds($ok));
        $ok->assertHeader(EchoResolvedTenant::TENANT_HEADER, (string) $owned->id);

        // Status only, for the APP_DEBUG reason given on the HTTP test above.
        $this->getJson("/api/admin/masjids/{$other->id}/contacts")->assertStatus(403);
    }

    /**
     * BYTE-IDENTICAL, the non-owner case the task names: a second membership row
     * leaves a non-owner administrator AMBIGUOUS, and ambiguity is refused rather
     * than resolved by picking one.
     *
     * A non-owner administrator — the "second person in the office", created by
     * TeamController or AdministratorsController — owns no masjid, so
     * `TenantResolver::grantsFor()` falls through to `staffMemberships()` and a
     * second row really does produce two grants with the gate shut. On a route
     * with nothing to choose between them the verdict must be `denied`: binding
     * the `is_default` row, or the first one the database returned, is exactly
     * the silent mis-scoping this slice exists to remove.
     *
     * The comparison case is asserted alongside it, because a test that only
     * shows the refusal cannot tell a working gate from a broken realm: ONE
     * membership must still bind, or every second-office admin in production is
     * locked out.
     */
    #[Test]
    public function with_the_gate_shut_a_non_owner_with_two_memberships_is_refused_as_ambiguous(): void
    {
        // The comparison case first: one membership, no ownership, binds.
        $settled = $this->masjidAdmin();
        $office = $this->makeMasjid();
        $this->membership($settled, $office, ['is_default' => true]);

        $this->assertNull($settled->fresh()->masjid, 'fixture: a second-office admin owns nothing');

        $this->handleThrough($this->makeAuthedRequest($settled));
        $this->assertSame($office->id, $this->tenant->get(), 'a single-membership non-owner must still bind — this is live today.');

        $this->tenant->forgetTenant();

        // And the ambiguous one.
        $ambiguous = $this->masjidAdmin();
        $first = $this->makeMasjid();
        $second = $this->makeMasjid();
        $this->membership($ambiguous, $first, ['is_default' => true]);
        $this->membership($ambiguous, $second);

        $refusal = $this->refusalFrom($this->makeAuthedRequest($ambiguous));

        $this->assertSame(403, $refusal['status']);
        $this->assertFalse(
            $this->tenant->hasTenant(),
            'the default membership must NOT have been bound — a guess here scopes a request to an organisation nobody chose.'
        );
    }

    /**
     * ...and on the routes that are genuinely not about one organisation, the
     * same principal is left UNBOUND rather than bound to a guess.
     *
     * `/api/admin/user` is on `TenantResolver::UNSCOPED_ADMIN_ROUTES`: it reads
     * the caller's own `users` row and touches no `BelongsToMasjid` model, so
     * unbound is the honest answer there. The echo says so in as many words, and
     * the SPA reads the literal `unbound` as "no organisation was resolved"
     * rather than as a missing header.
     */
    #[Test]
    public function an_ambiguous_admin_reaches_their_own_account_route_unbound_and_the_echo_says_so(): void
    {
        $ambiguous = $this->masjidAdmin();
        $this->membership($ambiguous, $this->makeMasjid(), ['is_default' => true]);
        $this->membership($ambiguous, $this->makeMasjid());

        Sanctum::actingAs($ambiguous);

        $this->getJson('/api/admin/user')
            ->assertOk()
            ->assertHeader(EchoResolvedTenant::TENANT_HEADER, EchoResolvedTenant::UNBOUND);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Open the gate the way S5 will. Deliberately a helper with a loud name:
     * every test that calls it is exercising a path production cannot reach.
     */
    private function openTheMultiMembershipGate(): void
    {
        config(['tenancy.multi_membership' => true]);
    }

    /** The contact ids a paginated index response actually returned, sorted. */
    private function returnedContactIds(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->json('data.data'))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Run the middleware and return the refusal, failing the test if the request
     * was ALLOWED — otherwise a resolver that stopped refusing would quietly turn
     * these into vacuous passes.
     *
     * @return array{status:int,message:string}
     */
    private function refusalFrom(Request $request): array
    {
        try {
            $this->handleThrough($request);
        } catch (HttpException $e) {
            return ['status' => $e->getStatusCode(), 'message' => $e->getMessage()];
        }

        $this->fail('Expected the request to be refused, but it was allowed through.');
    }

    /**
     * Give $user a membership in $masjid, whatever the fixture already holds.
     *
     * `updateOrCreate`, and any default elsewhere is cleared first, because the
     * row may ALREADY exist by the time this runs: setting `masjids.user_id` now
     * writes the owner's membership through a model hook
     * (`MasjidUser::ensureOwnerMembership`, called from `Masjid::booted()`), so
     * every factory and seeder produces one and not just the controllers that
     * remembered to. A plain `create()` would then die on the
     * `(masjid_id, user_id)` unique index, and a plain `is_default => true` on
     * the one-default-per-user index — as a QueryException inside a fixture,
     * which reads like a broken test rather than a fixture that has been
     * overtaken.
     *
     * The invariant-3 test deliberately does NOT go through here: it is about
     * the database refusing a second default, and a helper that tidies the first
     * one out of the way would make it pass without asserting anything.
     */
    private function membership(User $user, Masjid $masjid, array $overrides = []): MasjidUser
    {
        $attributes = array_merge(['role' => 'masjid-admin', 'is_default' => false], $overrides);

        if ($attributes['is_default']) {
            MasjidUser::where('user_id', $user->id)
                ->where('masjid_id', '!=', $masjid->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        return MasjidUser::updateOrCreate(
            ['masjid_id' => $masjid->id, 'user_id' => $user->id],
            $attributes,
        );
    }

    /** Create a Masjid row with the minimum columns the schema requires. */
    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    private function masjidAdmin(): User
    {
        return $this->user('MasjidAdmin');
    }

    private function superAdmin(): User
    {
        return $this->user('SuperAdmin');
    }

    private function user(string $type): User
    {
        return User::factory()->create([
            'type' => $type,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    /**
     * Build a request whose authenticated user is $user, optionally naming a
     * masjid in the route the way the admin SPA does (/masjids/{masjid_id}/...).
     * ResolveMasjidTenant reads that ROUTE parameter, so the route has to be
     * bound for the masjid-scoped paths to be exercised at all.
     */
    private function makeAuthedRequest(User $user, ?int $routeMasjidId = null): Request
    {
        $uri = $routeMasjidId === null
            ? '/api/admin/contacts'
            : "/api/admin/masjids/{$routeMasjidId}/contacts";

        $request = Request::create($uri, 'GET');
        $request->setUserResolver(fn () => $user);

        if ($routeMasjidId !== null) {
            $route = new RoutingRoute(['GET'], '/api/admin/masjids/{masjid_id}/contacts', fn () => null);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
        }

        return $request;
    }

    /** Run ResolveMasjidTenant (resolved with the shared TenantContext). */
    private function handleThrough(Request $request): void
    {
        app(ResolveMasjidTenant::class)->handle($request, fn () => response('ok'));
    }
}
