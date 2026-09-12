<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\GroupAudience;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A CHILD WHO LEAVES IS NEITHER PRESENT NOR ERASED.
 *
 * Before `left_on` a school had two options for a family withdrawing mid-year,
 * and both were wrong: leave the row, and the child keeps appearing on every
 * register and in every class list because those reads filter on ROLE alone; or
 * remove the row, which is refused outright once the child holds any academic
 * history — and for a child with none, destroys the enrolment, each parent's
 * guardian edge and the consent recorded on it, irreversibly.
 *
 * What these tests pin down is the shape of the third state:
 *
 *   - the class stops counting them from the day they left;
 *   - nothing that was recorded about them moves;
 *   - their guardians leave the class with them, so the family stops receiving
 *     a running account of a class they are no longer in — while still being
 *     able to open their own child's records;
 *   - and all of it reverses, because a leaving date is typed by a person.
 */
class StudentWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $admin;
    private User $teacher;
    private Group $class;
    private GroupMembership $leaver;      // the child who withdraws
    private GroupMembership $stays;       // the child who does not
    private GroupMembership $parentEdge;  // the leaver's guardian
    private Contact $parent;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = Masjid::create([
            'name' => 'Al-Razi Test '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->admin->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);
        $this->school->user_id = $this->admin->id;
        $this->school->save();

        $this->teacher = User::factory()->create([
            'type' => 'Teacher',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id,
            'kind' => Group::KIND_CLASS,
            'name' => '1st & 2nd Grade',
        ]);
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);

        $this->leaver = $this->enrol('Misha', 'Leaving');
        $this->stays = $this->enrol('Aalaa', 'Staying');

        $parentAddress = 'gouse-'.uniqid().'@test.local';
        $this->parent = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => 'Gouse', 'last_name' => 'Leaving',
            'email' => $parentAddress,
            // A guardian is reachable ONLY at their live family-login address —
            // the resolver skips a consented parent who never enabled a sign-in
            // rather than mailing a dead end.
            'login_email' => $parentAddress,
            // A parent only has standing anywhere while their portal sign-in is
            // live — GroupAudience re-checks it on every disclosure rather than
            // trusting the caller. Without this the feed assertions below would
            // pass for the wrong reason.
            'login_enabled_at' => now(),
        ]);

        $this->parentEdge = new GroupMembership([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'contact_id' => $this->parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->leaver->contact_id,
            // Consent on file, so the feed test below is about LEAVING and not
            // about a family that never consented in the first place.
            'consent_granted_at' => now()->subMonth(),
            'consent_scope' => GroupMembership::CONSENT_FEED,
        ]);
        $this->parentEdge->confirmedByStaff($this->admin)->save();

        app(TenantContext::class)->set($this->school->id);
    }

    // ===================================================== the class moves on

    #[Test]
    public function a_child_who_left_is_off_the_register_and_the_class_lists(): void
    {
        $this->withdraw($this->leaver)->assertOk();

        Sanctum::actingAs($this->teacher, ['staff']);

        $register = $this->getJson($this->teacherUrl('/attendance?date='.now()->toDateString()))->assertOk();
        $names = collect($register->json('data.students'))->pluck('membership_id')->map('intval');

        $this->assertFalse($names->contains($this->leaver->id), 'a child who left must not be on the register');
        $this->assertTrue($names->contains($this->stays->id), 'the rest of the class is untouched');

        // And the same answer wherever the class is counted.
        Sanctum::actingAs($this->admin);
        $groups = $this->getJson("/api/admin/masjids/{$this->school->id}/groups")->assertOk();
        $row = collect($groups->json('data.data'))->firstWhere('id', $this->class->id);
        $this->assertSame(1, (int) $row['participants_count'], 'class size counts who is in the class now');
    }

    #[Test]
    public function a_register_cannot_be_marked_for_a_child_who_left(): void
    {
        $this->withdraw($this->leaver)->assertOk();

        Sanctum::actingAs($this->teacher, ['staff']);

        $this->putJson($this->teacherUrl('/attendance'), [
            'session_date' => now()->toDateString(),
            'marks' => [['membership_id' => $this->leaver->id, 'status' => 'present']],
        ])->assertStatus(422);

        $this->assertSame(
            0,
            AttendanceRecord::where('group_membership_id', $this->leaver->id)->count(),
            'nothing may be written against a child who is no longer in the class'
        );
    }

    // ================================================ but the record does not

    #[Test]
    public function everything_recorded_about_them_stays_reachable(): void
    {
        // A mark from while they were still here.
        AttendanceRecord::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'group_membership_id' => $this->leaver->id,
            'session_date' => now()->subWeek()->toDateString(),
            'status' => 'present',
            'marked_by_user_id' => $this->teacher->id,
        ]);

        $this->withdraw($this->leaver, now()->subDay()->toDateString())->assertOk();

        Sanctum::actingAs($this->teacher, ['staff']);

        // The row itself still resolves, which is what every per-child endpoint
        // is addressed by — a report card, an award, a ḥifẓ entry.
        $summary = $this->getJson($this->teacherUrl("/members/{$this->leaver->id}/attendance"))->assertOk();
        $this->assertGreaterThan(0, (int) $summary->json('data.summary.present'));

        $this->assertDatabaseHas('attendance_records', [
            'group_membership_id' => $this->leaver->id,
        ]);
    }

    #[Test]
    public function the_roster_still_shows_them_with_the_date_they_left(): void
    {
        $this->withdraw($this->leaver, '2026-09-12')->assertOk();

        Sanctum::actingAs($this->admin);

        $roster = $this->getJson("/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/members")->assertOk();
        $row = collect($roster->json('data'))->firstWhere('id', $this->leaver->id);

        $this->assertNotNull($row, 'the office must still see who left, or it cannot undo it');
        $this->assertStringStartsWith('2026-09-12', (string) $row['left_on']);
    }

    // ============================================ the family leaves with them

    #[Test]
    public function a_child_leaving_takes_their_guardians_standing_with_them(): void
    {
        $audience = app(GroupAudience::class);

        // Before: consent on file, so the class-wide disclosure stands.
        $this->assertTrue($audience->mayReceive($this->parent, $this->class, GroupAudience::DISCLOSURE_FEED));

        $this->withdraw($this->leaver, '2026-09-12')->assertOk();

        $this->assertSame(
            '2026-09-12',
            $this->parentEdge->fresh()->left_on?->toDateString(),
            'the guardian edge leaves on the same day the child did'
        );

        // After: no class story, no class-wide thread, no handout, and none of
        // the emails about them.
        $this->assertFalse($audience->mayReceive($this->parent, $this->class, GroupAudience::DISCLOSURE_FEED));

        // But their own child's records are still theirs to read.
        $this->assertTrue(
            $audience->mayReceiveAwardsAbout($this->parent, $this->class, $this->leaver->fresh()),
            'leaving a class is not losing what the school recorded while they were in it'
        );

        // The consent record itself is untouched — it is evidence of what a
        // parent agreed to, not a switch this act may flip.
        $this->assertNotNull($this->parentEdge->fresh()->consent_granted_at);
    }

    #[Test]
    public function the_emails_about_the_class_stop_as_well(): void
    {
        // THE ONE THAT NOBODY WOULD SEE GO WRONG. Every other surface is a
        // screen a member of staff looks at; this one sends mail to the family's
        // own address, so a class story still arriving for a child who left
        // would be invisible to the school until a parent mentioned it.
        $resolver = app(\App\Services\Groups\GroupNotificationRecipientResolver::class);

        $this->assertTrue(
            $resolver->feedGuardians($this->class, null)->isNotEmpty(),
            'a consented guardian is on the class-story mailing list to begin with'
        );

        $this->withdraw($this->leaver)->assertOk();

        $this->assertTrue(
            $resolver->feedGuardians($this->class, null)->isEmpty(),
            'a family that left must stop receiving the class story by email'
        );
    }

    // ========================================================== and it undoes

    #[Test]
    public function putting_them_back_restores_the_row_and_the_guardian_edges(): void
    {
        $this->withdraw($this->leaver)->assertOk();

        Sanctum::actingAs($this->admin);
        $this->deleteJson($this->withdrawalUrl($this->leaver))->assertOk();

        $this->assertNull($this->leaver->fresh()->left_on);
        $this->assertNull($this->parentEdge->fresh()->left_on, 'the family comes back with the child');

        Sanctum::actingAs($this->teacher, ['staff']);
        $register = $this->getJson($this->teacherUrl('/attendance?date='.now()->toDateString()))->assertOk();

        $this->assertTrue(
            collect($register->json('data.students'))->pluck('membership_id')->map('intval')->contains($this->leaver->id),
            'a child who comes back is on the register again'
        );

        $this->assertTrue(
            app(GroupAudience::class)->mayReceive($this->parent, $this->class, GroupAudience::DISCLOSURE_FEED)
        );
    }

    // ============================================================== refusals

    #[Test]
    public function a_guardian_entry_cannot_be_withdrawn_on_its_own(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson($this->withdrawalUrl($this->parentEdge), [])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertNull($this->parentEdge->fresh()->left_on);
    }

    #[Test]
    public function a_leaving_date_cannot_be_in_the_future(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson($this->withdrawalUrl($this->leaver), ['left_on' => now()->addWeek()->toDateString()])
            ->assertStatus(422);

        $this->assertNull($this->leaver->fresh()->left_on);
    }

    #[Test]
    public function another_organisations_roster_row_is_a_miss(): void
    {
        $other = Masjid::create([
            'name' => 'Another School '.uniqid(),
            'email' => 'other-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '2 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
        ]);
        $theirClass = Group::factory()->create(['masjid_id' => $other->id, 'kind' => Group::KIND_CLASS]);
        $theirChild = Contact::factory()->create(['masjid_id' => $other->id]);
        $theirRow = new GroupMembership([
            'masjid_id' => $other->id, 'group_id' => $theirClass->id,
            'contact_id' => $theirChild->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $theirRow->confirmedByStaff(null)->save();

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/members/{$theirRow->id}/withdrawal", [])
            ->assertStatus(404);

        $this->assertNull($theirRow->fresh()->left_on);
    }

    #[Test]
    public function deleting_a_child_who_holds_records_is_still_refused(): void
    {
        // The guard this feature exists to make unnecessary, pinned so the new
        // verb cannot be mistaken for a way around it.
        AttendanceRecord::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'group_membership_id' => $this->leaver->id,
            'session_date' => now()->subWeek()->toDateString(),
            'status' => 'present',
            'marked_by_user_id' => $this->teacher->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/members/{$this->leaver->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('group_memberships', ['id' => $this->leaver->id]);
    }

    // ------------------------------------------------------------- internals

    private function enrol(string $first, string $last): GroupMembership
    {
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => $first, 'last_name' => $last, 'email' => null,
        ]);

        $row = new GroupMembership([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now()->subMonths(2)->toDateString(),
        ]);
        $row->confirmedByStaff($this->admin)->save();

        return $row;
    }

    private function withdraw(GroupMembership $membership, ?string $on = null)
    {
        Sanctum::actingAs($this->admin);

        return $this->putJson(
            $this->withdrawalUrl($membership),
            $on === null ? [] : ['left_on' => $on],
        );
    }

    private function withdrawalUrl(GroupMembership $membership): string
    {
        return "/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/members/{$membership->id}/withdrawal";
    }

    private function teacherUrl(string $path = ''): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}".$path;
    }
}
