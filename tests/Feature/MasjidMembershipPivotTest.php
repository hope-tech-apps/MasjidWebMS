<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveMasjidTenant;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * S2 — the `masjid_user` membership pivot (docs/multi-tenant-admin-design.md).
 *
 * The slice is "table + backfill + `User::memberships()`, no behaviour change",
 * so this file has to prove three separate things, and the third is the
 * acceptance bar:
 *
 *   1. **The backfill reproduces TODAY's ownership.** S3's resolver can only be
 *      byte-identical to the current middleware if every admin who resolves to a
 *      masjid today already has exactly one default membership naming that same
 *      masjid. The fixture is deliberately the realistic one — a SuperAdmin, an
 *      owner, an unowned masjid, a trashed masjid — because each of those is a row
 *      the backfill must NOT invent access for.
 *   2. **One default per user is a database fact**, not a convention, on whichever
 *      driver is running. Anything weaker leaves the S3 fallback picking an
 *      arbitrary row — exactly the non-determinism S0 removed from
 *      `masjids.user_id`.
 *   3. **Nothing changed.** Tenant resolution still runs off `masjids.user_id`; a
 *      membership in another masjid grants nothing yet. If any assertion in the
 *      last section starts failing, S3 has leaked into this slice.
 *
 * The backfill is exercised by rolling THE REAL MIGRATION down and back up over a
 * fixture, not by re-implementing its INSERT here — a re-implementation would
 * happily agree with itself while production got different rows. Rolling it down
 * first also exercises `down()`, which CI's migrations-mysql job does against the
 * real engine.
 *
 * Sqlite-in-memory + RefreshDatabase per the testing convention.
 */
class MasjidMembershipPivotTest extends TestCase
{
    use RefreshDatabase;

    /** Must match `create_masjid_user_table`. */
    private const MIGRATION = 'migrations/2026_08_12_030000_create_masjid_user_table.php';

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Same idiom as TenantIsolationTest: this suite must never need a network
        // DB to run.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();
    }

    // ------------------------------------------------------------------ backfill

    /**
     * The whole point of the slice, on the fixture that mirrors production:
     * exactly the live owned masjids become memberships, and nothing else does.
     */
    #[Test]
    public function the_backfill_reproduces_todays_ownership_and_invents_no_access(): void
    {
        $superAdmin = $this->superAdmin();
        $member = $this->plainUser();

        $ownerA = $this->masjidAdmin();
        $masjidA = $this->makeMasjid(['user_id' => $ownerA->id]);

        $ownerB = $this->masjidAdmin();
        $masjidB = $this->makeMasjid(['user_id' => $ownerB->id]);

        $unowned = $this->makeMasjid(['user_id' => null]);

        $formerOwner = $this->masjidAdmin();
        $trashed = $this->makeMasjid(['user_id' => $formerOwner->id]);
        $trashed->delete();

        $this->rerunTheMembershipMigration();

        // Two live owned masjids in, two membership rows out — no row for the
        // SuperAdmin, the plain user, the unowned masjid or the trashed one.
        $this->assertSame(2, MasjidUser::count());

        foreach ([[$ownerA, $masjidA], [$ownerB, $masjidB]] as [$owner, $masjid]) {
            $memberships = $owner->memberships()->get();

            $this->assertCount(1, $memberships, 'Every current admin gets exactly ONE membership.');
            $this->assertSame($masjid->id, (int) $memberships[0]->masjid_id, 'The membership must name the masjid the admin resolves to today.');
            $this->assertTrue($memberships[0]->is_default, 'The single backfilled membership must be the default, or S3 has nothing to fall back to.');
            $this->assertSame('masjid-admin', $memberships[0]->role);
        }

        $this->assertSame(
            0,
            $superAdmin->memberships()->count(),
            'SuperAdmins are bound from the route, not from ownership — a membership would invent access they do not have.'
        );
        $this->assertSame(0, $member->memberships()->count());
        $this->assertSame(0, $formerOwner->memberships()->count(), 'A trashed masjid grants nothing today and must not start granting it here.');
        $this->assertSame(0, $unowned->memberships()->count(), 'NULL user_id is "no owner", not a user.');
        $this->assertSame(0, $trashed->memberships()->count());
    }

    /**
     * The backfilled role is the EXISTING bridged role name, not a new vocabulary.
     * The migration hardcodes the literal on purpose (a migration is a historical
     * record); this is the assertion that stops the two drifting apart silently if
     * `TYPE_ROLE_MAP` is ever renamed.
     */
    #[Test]
    public function the_backfilled_role_is_the_bridged_masjid_admin_role(): void
    {
        $owner = $this->masjidAdmin();
        $this->makeMasjid(['user_id' => $owner->id]);

        $this->rerunTheMembershipMigration();

        $this->assertSame(
            User::TYPE_ROLE_MAP['MasjidAdmin'],
            $owner->memberships()->value('role'),
            'pivot.role mirrors the global bridged role — it is advisory in this slice and must not introduce a new name.'
        );
    }

    /**
     * `down()` genuinely drops the table (CI rolls the last migration back and
     * forward against MySQL), and re-running `up()` rebuilds a table that still
     * enforces its constraint — a rollback that left the index behind would pass a
     * plain "table exists" check and fail on the next deploy.
     */
    #[Test]
    public function the_migration_rolls_back_cleanly_and_forward_again(): void
    {
        $owner = $this->masjidAdmin();
        $this->makeMasjid(['user_id' => $owner->id]);

        $migration = $this->membershipMigration();

        $migration->down();
        $this->assertFalse(Schema::hasTable('masjid_user'), 'down() must drop the table — the design promises a genuinely reversible slice.');

        $migration->up();
        $this->assertTrue(Schema::hasTable('masjid_user'));
        $this->assertSame(1, MasjidUser::count(), 'Re-running up() must backfill again from ownership.');

        // The constraint came back with the table, not just the columns.
        $this->assertTrue($this->secondDefaultIsRejectedFor($owner));
    }

    // ---------------------------------------------------------- one default rule

    /**
     * The invariant the design pins ("two `is_default` rows → DB rejects"),
     * enforced by the partial index on SQLite and by the `default_key` generated
     * column on MySQL. Application-level enforcement would leave the same three
     * holes — seeder, artisan command, hand-written UPDATE — that made
     * validation-only ownership uniqueness worthless before S0.
     */
    #[Test]
    public function the_database_refuses_a_second_default_membership_for_one_user(): void
    {
        $user = $this->masjidAdmin();
        $this->membership($user, $this->makeMasjid(), ['is_default' => true]);

        $this->assertTrue(
            $this->secondDefaultIsRejectedFor($user),
            'A second is_default row was accepted — the fallback membership is non-deterministic again.'
        );
        $this->assertSame(1, $user->memberships()->where('is_default', true)->count());
    }

    /** Holding memberships in several organisations is the entire point. */
    #[Test]
    public function a_user_may_hold_many_non_default_memberships(): void
    {
        $user = $this->masjidAdmin();

        $this->membership($user, $this->makeMasjid(), ['is_default' => true]);
        $this->membership($user, $this->makeMasjid(), ['is_default' => false]);
        $this->membership($user, $this->makeMasjid(), ['is_default' => false]);

        $this->assertSame(3, $user->memberships()->count());
    }

    /**
     * Switching which organisation is the default must be an ordinary update.
     * On MySQL the generated column recomputes on UPDATE, so clearing the old
     * default first is what keeps this out of a constraint violation; asserting it
     * here stops a future slice from concluding the index makes switching
     * impossible.
     */
    #[Test]
    public function the_default_membership_can_be_moved_to_another_masjid(): void
    {
        $user = $this->masjidAdmin();
        $first = $this->membership($user, $this->makeMasjid(), ['is_default' => true]);
        $second = $this->membership($user, $this->makeMasjid(), ['is_default' => false]);

        DB::transaction(function () use ($first, $second) {
            $first->update(['is_default' => false]);
            $second->update(['is_default' => true]);
        });

        $this->assertSame(
            $second->id,
            (int) $user->memberships()->where('is_default', true)->value('id')
        );
    }

    #[Test]
    public function the_database_refuses_two_memberships_for_the_same_pair(): void
    {
        $user = $this->masjidAdmin();
        $masjid = $this->makeMasjid();

        $this->membership($user, $masjid);

        $rejected = false;

        try {
            $this->membership($user, $masjid, ['is_default' => false, 'role' => 'member']);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'One person may hold only one membership row per organisation.');
    }

    // -------------------------------------------------------------- relations

    #[Test]
    public function memberships_are_readable_from_both_sides(): void
    {
        $user = $this->masjidAdmin();
        $masjid = $this->makeMasjid();
        $membership = $this->membership($user, $masjid, ['is_default' => true]);

        $this->assertTrue($user->memberships->contains($membership));
        $this->assertTrue($masjid->memberships->contains($membership));
        $this->assertSame($user->id, $membership->user->id);
        $this->assertSame($masjid->id, $membership->masjid->id);
    }

    /**
     * The MySQL-only `default_key` shadow column must never reach a payload, so
     * the `memberships[]` S4 returns is identical on both drivers. Trivially true
     * on the SQLite the suite runs on — where the column does not exist — which is
     * precisely why it is asserted rather than assumed.
     */
    #[Test]
    public function the_generated_default_key_column_is_never_serialized(): void
    {
        $membership = $this->membership($this->masjidAdmin(), $this->makeMasjid(), ['is_default' => true]);

        $this->assertArrayNotHasKey('default_key', $membership->fresh()->toArray());
    }

    /**
     * A membership is an authorization grant: hard-deleting either side must take
     * it with them, or a reused id inherits someone else's access. Soft deletes —
     * `moveToTrash` — deliberately keep it, so a restored masjid comes back with
     * its admins.
     */
    #[Test]
    public function memberships_cascade_on_hard_delete_and_survive_a_soft_delete(): void
    {
        $user = $this->masjidAdmin();
        $masjid = $this->makeMasjid();
        $this->membership($user, $masjid);

        $masjid->delete();
        $this->assertSame(1, MasjidUser::count(), 'Trashing a masjid must not revoke its admins.');

        $masjid->forceDelete();
        $this->assertSame(0, MasjidUser::count(), 'A hard-deleted masjid must not leave a dangling grant behind.');

        $other = $this->makeMasjid();
        $this->membership($user, $other);
        $user->forceDelete();
        $this->assertSame(0, MasjidUser::count());
    }

    // ------------------------------------------------------- NO behaviour change

    /**
     * The acceptance bar for S2. The pivot exists, the admin has a membership in
     * masjid B, and tenant resolution still binds the masjid they OWN — because
     * `ResolveMasjidTenant` is untouched and reads `masjids.user_id` exactly as it
     * did before this slice. Consuming the pivot is S3.
     */
    #[Test]
    public function tenant_resolution_still_binds_the_owned_masjid_and_ignores_memberships(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);
        $other = $this->makeMasjid();

        $this->membership($admin, $owned, ['is_default' => true]);
        $this->membership($admin, $other, ['is_default' => false]);

        $this->handleThrough($this->makeAuthedRequest($admin));

        $this->assertTrue($this->tenant->hasTenant());
        $this->assertSame($owned->id, $this->tenant->get());
    }

    /**
     * The other half: a membership grants NOTHING today. Naming the other masjid
     * in the route is still the same 403 with the same message, even though the
     * admin now holds a pivot row for it. When S3 lands, this becomes a 200 — and
     * it must be S3 that changes it, deliberately, not this slice by accident.
     */
    #[Test]
    public function a_membership_in_another_masjid_still_403s_that_masjids_routes(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);
        $other = $this->makeMasjid();

        $this->membership($admin, $owned, ['is_default' => true]);
        $this->membership($admin, $other, ['is_default' => false]);

        $status = null;
        $message = null;

        try {
            $this->handleThrough($this->makeAuthedRequest($admin, $other->id));
        } catch (HttpException $e) {
            $status = $e->getStatusCode();
            $message = $e->getMessage();
        }

        $this->assertSame(403, $status, 'The membership must not open the route it names — that is S3.');
        $this->assertSame('You are not authorized to access this masjid.', $message);
        $this->assertNotSame($other->id, $this->tenant->get());
    }

    /**
     * A SuperAdmin is still left unbound on a route that names no masjid. The
     * backfill gave them no rows precisely so that this stays true.
     */
    #[Test]
    public function a_super_admin_is_still_unbound_without_a_route_masjid(): void
    {
        $super = $this->superAdmin();

        $this->handleThrough($this->makeAuthedRequest($super));

        $this->assertFalse($this->tenant->hasTenant());
        $this->assertSame(0, $super->memberships()->count());
    }

    /** `User::masjid()` is untouched: the ownership pointer still resolves as before. */
    #[Test]
    public function the_ownership_has_one_is_unchanged_by_the_pivot(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);
        $this->membership($admin, $this->makeMasjid(), ['is_default' => false]);

        $this->assertSame($owned->id, $admin->fresh()->masjid?->id);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Roll the REAL migration down and back up, so the assertions above are about
     * the INSERT that production will run — not a copy of it living in this file.
     */
    private function rerunTheMembershipMigration(): void
    {
        $migration = $this->membershipMigration();

        $migration->down();
        $migration->up();
    }

    /**
     * The migration object itself. Migrations are anonymous classes returned by
     * their file, so `require` (not `require_once`) hands back a fresh instance
     * without any redeclaration problem.
     */
    private function membershipMigration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    /** Try to give $user a second default membership; true when the DB refuses. */
    private function secondDefaultIsRejectedFor(User $user): bool
    {
        try {
            $this->membership($user, $this->makeMasjid(), ['is_default' => true]);
        } catch (QueryException) {
            return true;
        }

        return false;
    }

    private function membership(User $user, Masjid $masjid, array $overrides = []): MasjidUser
    {
        return MasjidUser::create(array_merge([
            'masjid_id' => $masjid->id,
            'user_id' => $user->id,
            'role' => 'masjid-admin',
            'is_default' => false,
        ], $overrides));
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

    private function plainUser(): User
    {
        return $this->user('User');
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
     * masjid in the route the way the admin SPA does (/masjids/{masjid_id}/...) —
     * `ResolveMasjidTenant` reads that parameter, so the route has to be bound for
     * the 403 path to be exercised at all.
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
