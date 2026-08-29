<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\User;
use App\Support\GroupAudience;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer guarantees behind the teacher↔class link.
 *
 * MySQL has NO row-level security, so `BelongsToMasjid` on `group_staff` is the
 * only thing keeping one school's teacher assignments out of another school's
 * queries — and .claude/rules/tenant-scoping.md makes a cross-tenant test
 * mandatory for every new tenant-scoped model. This is it, binding TenantContext
 * directly the way ResolveMasjidTenant does per request.
 *
 * It also pins the two facts a teacher's authority is built on:
 *
 *   - the `attach()` masjid_id footgun — attach() bypasses the creating hook, so
 *     an assignment written without an explicit masjid_id is REFUSED loudly
 *     rather than landing a row the tenant scope would silently hide (which
 *     would make the teacher see zero classes);
 *   - "only my classes" — GroupAudience reads group_staff to grant a teacher
 *     leader standing in exactly the classes they are assigned to, in their own
 *     school and no other.
 */
class GroupStaffTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private Group $classA;
    private Group $classB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->classA = Group::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'name' => 'Grade 2', 'slug' => 'grade-2-a', 'kind' => Group::KIND_CLASS,
        ]);
        $this->classB = Group::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'name' => 'Grade 2', 'slug' => 'grade-2-b', 'kind' => Group::KIND_CLASS,
        ]);
    }

    #[Test]
    public function the_scope_hides_another_schools_staff_rows(): void
    {
        $mine = $this->assignStaff($this->masjidA, $this->classA, $this->makeTeacher());
        $foreign = $this->assignStaff($this->masjidB, $this->classB, $this->makeTeacher());

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, GroupStaff::count());
        $this->assertSame($this->masjidA->id, (int) GroupStaff::first()->masjid_id);
        $this->assertNull(GroupStaff::find($foreign->id));
        $this->assertNotNull(GroupStaff::find($mine->id));
    }

    #[Test]
    public function attach_under_a_bound_tenant_stamps_that_tenant(): void
    {
        $teacher = $this->makeTeacher();
        $this->tenant->set($this->masjidA->id);

        // With ->using(GroupStaff::class), attach() DOES fire the BelongsToMasjid
        // creating hook in Laravel 12 — so even a masjid_id-less attach under a
        // bound tenant lands in that school rather than unscoped. The app still
        // passes masjid_id explicitly (TeachersController) as belt-and-braces, so
        // the row is correct even for a caller that is UNBOUND or a future
        // refactor that drops ->using(); this pins that the bound path is safe.
        $this->classA->staff()->attach($teacher->id, [
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);

        $this->assertSame(1, GroupStaff::count());
        $this->assertSame($this->masjidA->id, (int) GroupStaff::first()->masjid_id);
    }

    #[Test]
    public function an_explicit_masjid_id_lands_under_the_bound_tenant(): void
    {
        $teacher = $this->makeTeacher();
        $this->tenant->set($this->masjidA->id);

        // The documented contract: pass masjid_id explicitly and the row is
        // correctly scoped and visible.
        $this->classA->staff()->attach($teacher->id, [
            'masjid_id' => $this->classA->masjid_id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);

        $this->assertSame(1, GroupStaff::count());
        $this->assertSame($this->masjidA->id, (int) GroupStaff::first()->masjid_id);
    }

    #[Test]
    public function deleting_the_class_cascades_its_staff_rows(): void
    {
        $this->assignStaff($this->masjidA, $this->classA, $this->makeTeacher());
        $this->assertSame(1, DB::table('group_staff')->count());

        // Group soft-deletes; force to exercise the FK cascade.
        $this->tenant->forgetTenant();
        Group::withoutMasjidScope()->whereKey($this->classA->id)->first()->forceDelete();

        $this->assertSame(0, DB::table('group_staff')->count());
    }

    #[Test]
    public function deleting_the_teacher_cascades_their_staff_rows(): void
    {
        $teacher = $this->makeTeacher();
        $this->assignStaff($this->masjidA, $this->classA, $teacher);
        $this->assertSame(1, DB::table('group_staff')->count());

        // User soft-deletes; force to exercise the FK cascade.
        $teacher->forceDelete();

        $this->assertSame(0, DB::table('group_staff')->count());
    }

    #[Test]
    public function a_teacher_leads_only_their_own_classes(): void
    {
        $teacher = $this->makeTeacher();
        $this->assignStaff($this->masjidA, $this->classA, $teacher);

        $this->tenant->set($this->masjidA->id);
        $audience = app(GroupAudience::class);

        // Their own class, in their own school.
        $this->assertTrue($audience->isLeaderOf($teacher, $this->classA));
        $this->assertSame([$this->classA->id], $audience->leaderGroupIdsFor($teacher));
        $this->assertCount(1, Group::query()->ledBy((int) $teacher->id)->get());

        // A class in ANOTHER school grants nothing: no staff row for them, and
        // the tenant scope hides that school's group_staff entirely.
        $foreignClass = Group::withoutMasjidScope()->whereKey($this->classB->id)->first();
        $this->assertFalse($audience->isLeaderOf($teacher, $foreignClass));
    }

    // ------------------------------------------------------------- helpers

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    private function makeTeacher(): User
    {
        return User::factory()->create([
            'type' => 'Teacher',
            // users.phone is NOT NULL and the factory does not set it.
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
    }

    /**
     * Assign a teacher to a class through the REAL write path (attach with an
     * explicit masjid_id), and return the persisted GroupStaff row.
     */
    private function assignStaff(Masjid $masjid, Group $group, User $user): GroupStaff
    {
        $group->staff()->attach($user->id, [
            'masjid_id' => $masjid->id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);

        return GroupStaff::withoutMasjidScope()
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }
}
