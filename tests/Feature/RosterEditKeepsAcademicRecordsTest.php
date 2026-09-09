<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\ReportCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A roster edit must never destroy a child's academic record.
 *
 * This test exists because the opposite was true and shipped. `group_memberships`
 * does not soft-delete and six academic tables carried ON DELETE CASCADE against
 * it, so "remove from the roster" — whose success message is "Removed from the
 * roster" — also deleted every register mark, score, report card, ḥifẓ entry and
 * behaviour award for that child in that class. Reproduced 2026-09-09 on a seeded
 * child: one attendance row, one report card, one delete, both gone.
 *
 * The foreign keys are RESTRICT now, so the database refuses regardless of the
 * caller. These assertions are about the CONTROLLER, which must refuse first and
 * say what it is holding — a bare constraint violation is not something a school
 * office can act on.
 */
class RosterEditKeepsAcademicRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Group $class;
    private GroupMembership $student;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeMasjid();

        $admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->school->user_id = $admin->id;
        $this->school->save();
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $admin->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Combined A', 'slug' => 'combined-a-' . uniqid(),
        ]);

        $child = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Aalaa']);
        $this->student = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        Sanctum::actingAs($admin, ['*']);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function url(GroupMembership $m): string
    {
        return "/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/members/{$m->id}";
    }

    #[Test]
    public function a_child_with_school_records_cannot_be_removed_from_the_roster(): void
    {
        AttendanceRecord::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'group_membership_id' => $this->student->id,
            'session_date' => '2026-09-01', 'status' => 'present',
        ]);
        ReportCard::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'group_membership_id' => $this->student->id,
            'type' => 'report_card', 'school_year' => '2026-2027', 'term' => 1,
        ]);

        $res = $this->deleteJson($this->url($this->student))->assertStatus(409);

        // The message has to be actionable: an office deciding what to do needs
        // to know whether this is a stray row or a term's work.
        $message = $res->json('data.membership.0');
        $this->assertStringContainsString('1 register marks', $message);
        $this->assertStringContainsString('1 report cards', $message);

        // THE POINT: nothing was destroyed.
        $this->assertSame(1, AttendanceRecord::count(), 'the register survived');
        $this->assertSame(1, ReportCard::count(), 'the report card survived');
        $this->assertDatabaseHas('group_memberships', ['id' => $this->student->id]);
    }

    /**
     * The common legitimate case must still work: a mis-typed enrolment removed
     * the same afternoon, before anyone marked anything.
     */
    #[Test]
    public function a_child_with_no_records_can_still_be_removed(): void
    {
        $this->deleteJson($this->url($this->student))->assertOk();

        $this->assertDatabaseMissing('group_memberships', ['id' => $this->student->id]);
    }
}
