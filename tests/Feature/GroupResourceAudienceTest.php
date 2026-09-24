<?php

namespace Tests\Feature;

use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupResource;
use App\Models\GroupResourceRecipient;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WHO A CLASS FILE REACHES — the three audiences, over HTTP (2026-09-24).
 *
 * The fixture is the one the family-portal tests use and for the same reason:
 * TWO families in ONE classroom. Every dangerous property of this feature is a
 * statement about the SECOND family, and a one-family fixture cannot express a
 * single one of them.
 *
 * What is pinned here, worst-first:
 *
 *   1. A file addressed to one child is invisible to the other family in the
 *      same room — in the LISTING and in the DOWNLOAD, and the refusal leaks
 *      neither the title nor the filename.
 *   2. Taking a child off the roster NARROWS a targeted file's audience and
 *      never widens it; a file whose last recipient has gone is staff-only, not
 *      class-wide.
 *   3. A recipient id must be a current participant of THIS class: another
 *      class's roster row and a guardian edge are both refused, and nothing is
 *      written when they are.
 *   4. The whole-class and staff-only audiences behave exactly as they did.
 */
class GroupResourceAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private User $office;
    private Group $class;
    private Group $otherClass;

    private GroupMembership $kareem;
    private GroupMembership $sama;
    private GroupMembership $elsewhere;

    private Contact $kareemsParent;
    private Contact $samasParent;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Storage::fake('local');

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = Masjid::create([
            'name' => 'Al-Razi Files ' . uniqid(),
            'email' => 'files-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        // The office: the school's OWNER user, which is how the admin console's
        // own tests reach these routes (AdminSchoolOfficeReadsTest).
        $this->office = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->school->user_id = $this->office->id;
        $this->school->save();

        $this->teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Pre-K & Kindergarten', 'slug' => 'pre-k-' . uniqid(),
        ]);
        $this->otherClass = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => '1st & 2nd Grade', 'slug' => 'first-second-' . uniqid(),
        ]);

        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->class->masjid_id,
            'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $this->kareem = $this->enrol($this->class, 'Kareem');
        $this->sama = $this->enrol($this->class, 'Sama');
        $this->elsewhere = $this->enrol($this->otherClass, 'Aalaa');

        $this->kareemsParent = $this->guardianOf($this->class, $this->kareem, 'Huda');
        $this->samasParent = $this->guardianOf($this->class, $this->sama, 'Maryam');

        $this->asTeacher();
    }

    // --------------------------------------------------------- the three cases

    #[Test]
    public function a_whole_class_file_reaches_every_family(): void
    {
        $id = $this->upload(GroupResource::VISIBILITY_FAMILIES);

        foreach ([$this->kareemsParent, $this->samasParent] as $parent) {
            $listed = $this->asParent($parent)->getJson($this->familyUrl() . '/resources')
                ->assertOk()->json('data');

            $this->assertCount(1, $listed);
            $this->assertSame($id, (int) $listed[0]['id']);
            $this->asParent($parent)->get($this->familyUrl() . "/resources/{$id}/download")->assertOk();
        }
    }

    #[Test]
    public function a_staff_only_file_reaches_no_family_at_all(): void
    {
        $id = $this->upload();

        foreach ([$this->kareemsParent, $this->samasParent] as $parent) {
            $this->asParent($parent)->getJson($this->familyUrl() . '/resources')
                ->assertOk()->assertJsonCount(0, 'data');

            // Not a 403: that would confirm the id exists in their child's class.
            $this->asParent($parent)->get($this->familyUrl() . "/resources/{$id}/download")
                ->assertNotFound();
        }
    }

    /**
     * THE ONE THAT MATTERS. The second family is in the same classroom, holds a
     * live family login, and has consented — everything except being the child
     * the file names.
     */
    #[Test]
    public function a_targeted_file_reaches_only_the_named_childs_family(): void
    {
        $id = $this->upload(GroupResource::VISIBILITY_STUDENTS, [$this->kareem->id]);

        $listed = $this->asParent($this->kareemsParent)->getJson($this->familyUrl() . '/resources')
            ->assertOk()->json('data');
        $this->assertCount(1, $listed, 'the named child\'s parent must see it');
        $this->assertSame($id, (int) $listed[0]['id']);
        $this->asParent($this->kareemsParent)->get($this->familyUrl() . "/resources/{$id}/download")
            ->assertOk();

        // The other family in the SAME classroom.
        $this->asParent($this->samasParent)->getJson($this->familyUrl() . '/resources')
            ->assertOk()->assertJsonCount(0, 'data');

        $refusal = $this->asParent($this->samasParent)
            ->get($this->familyUrl() . "/resources/{$id}/download")
            ->assertNotFound();

        // A 404 that names the file is not a refusal, it is a disclosure.
        $body = $refusal->getContent();
        $this->assertStringNotContainsString('Kareem', $body);
        $this->assertStringNotContainsString('report-card.pdf', $body);
        $this->assertStringNotContainsString('Progress report', $body);
    }

    /** Guessing an id the listing never served must not be a way in. */
    #[Test]
    public function a_parent_guessing_another_childs_file_id_is_refused_even_in_a_class_they_are_in(): void
    {
        $hers = $this->upload(GroupResource::VISIBILITY_STUDENTS, [$this->sama->id]);
        $his = $this->upload(GroupResource::VISIBILITY_STUDENTS, [$this->kareem->id]);

        $this->asParent($this->samasParent)->get($this->familyUrl() . "/resources/{$his}/download")
            ->assertNotFound();
        $this->asParent($this->samasParent)->get($this->familyUrl() . "/resources/{$hers}/download")
            ->assertOk();
    }

    // ------------------------------------------------------- roster departures

    /**
     * WITHDRAWAL ENDS A CLAIM AND WIDENS NOTHING.
     *
     * `left_on` is the school's record that the child has gone. The recipient
     * row survives it — the file was addressed to that child and always was —
     * but the family's standing does not, so nothing reaches them from the day
     * they left, and no other family gains anything.
     */
    #[Test]
    public function withdrawing_a_student_ends_their_claim_and_widens_nothing(): void
    {
        $id = $this->upload(GroupResource::VISIBILITY_STUDENTS, [$this->kareem->id]);

        $this->kareem->forceFill(['left_on' => now()->subDay()->toDateString()])->save();

        // 403 on the listing, 404 on the file — the split the realm has always
        // made: the group is real and they are no longer entitled to it, and the
        // file is one the constrained query no longer returns at all.
        $this->asParent($this->kareemsParent)->getJson($this->familyUrl() . '/resources')
            ->assertForbidden();
        $this->asParent($this->kareemsParent)->get($this->familyUrl() . "/resources/{$id}/download")
            ->assertNotFound();

        $this->asParent($this->samasParent)->getJson($this->familyUrl() . '/resources')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->asTeacher();
        $this->assertSame(
            GroupResource::VISIBILITY_STUDENTS,
            GroupResource::find($id)->visibility,
            'a withdrawal must not rewrite the file\'s audience'
        );
        $this->assertDatabaseCount('group_resource_recipients', 1);
    }

    /**
     * REMOVING A ROSTER ROW NARROWS THE AUDIENCE TO NOTHING, NEVER TO EVERYONE.
     *
     * The FK cascades, which is what makes the file un-orphaned; and the empty
     * set is the EMPTY audience, which is what stops it quietly becoming a
     * whole-class handout.
     */
    #[Test]
    public function removing_a_student_cascades_the_claim_and_the_file_becomes_staff_only(): void
    {
        $id = $this->upload(GroupResource::VISIBILITY_STUDENTS, [$this->kareem->id]);

        $this->kareem->delete();

        $this->assertDatabaseCount('group_resource_recipients', 0);

        $this->asParent($this->samasParent)->getJson($this->familyUrl() . '/resources')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->asParent($this->samasParent)->get($this->familyUrl() . "/resources/{$id}/download")
            ->assertNotFound();

        $this->asTeacher();
        $this->assertNotNull(GroupResource::find($id), 'the file itself must survive');
        $this->getJson($this->url() . '/resources')->assertOk()
            ->assertJsonPath('data.0.visibility', GroupResource::VISIBILITY_STUDENTS)
            ->assertJsonPath('data.0.recipient_count', 0);
    }

    // ---------------------------------------------------- what may be named

    #[Test]
    public function another_classes_student_cannot_be_named_and_nothing_is_written(): void
    {
        $this->post($this->url() . '/resources', [
            'file' => UploadedFile::fake()->create('report-card.pdf', 12, 'application/pdf'),
            'title' => 'Progress report',
            'visibility' => GroupResource::VISIBILITY_STUDENTS,
            'recipient_membership_ids' => [$this->elsewhere->id],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // A refused upload must write no row.
        $this->assertDatabaseCount('group_resources', 0);
        $this->assertDatabaseCount('group_resource_recipients', 0);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'and no bytes');
    }

    #[Test]
    public function a_guardian_edge_cannot_be_named_as_a_recipient(): void
    {
        $edge = GroupMembership::where('contact_id', $this->kareemsParent->id)->firstOrFail();

        $this->post($this->url() . '/resources', [
            'file' => UploadedFile::fake()->create('report-card.pdf', 12, 'application/pdf'),
            'title' => 'Progress report',
            'visibility' => GroupResource::VISIBILITY_STUDENTS,
            'recipient_membership_ids' => [$edge->id],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseCount('group_resources', 0);
    }

    #[Test]
    public function a_targeted_file_must_name_somebody(): void
    {
        $this->post($this->url() . '/resources', [
            'file' => UploadedFile::fake()->create('report-card.pdf', 12, 'application/pdf'),
            'title' => 'Progress report',
            'visibility' => GroupResource::VISIBILITY_STUDENTS,
            'recipient_membership_ids' => [],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseCount('group_resources', 0);
    }

    #[Test]
    public function a_whole_class_file_may_not_carry_names(): void
    {
        $this->post($this->url() . '/resources', [
            'file' => UploadedFile::fake()->create('worksheet.pdf', 12, 'application/pdf'),
            'title' => 'Worksheet',
            'visibility' => GroupResource::VISIBILITY_FAMILIES,
            'recipient_membership_ids' => [$this->kareem->id],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseCount('group_resources', 0);
    }

    // ------------------------------------------------------------------ edits

    #[Test]
    public function moving_a_file_off_students_clears_the_claims_it_carried(): void
    {
        $id = $this->upload(GroupResource::VISIBILITY_STUDENTS, [$this->kareem->id]);
        $this->assertDatabaseCount('group_resource_recipients', 1);

        $this->putJson($this->url() . "/resources/{$id}", [
            'visibility' => GroupResource::VISIBILITY_STAFF,
        ])->assertOk()->assertJsonPath('data.recipient_count', 0);

        // A stale claim would be re-honoured in silence by any later flip back
        // to `students`.
        $this->assertDatabaseCount('group_resource_recipients', 0);

        $this->asParent($this->kareemsParent)->getJson($this->familyUrl() . '/resources')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function narrowing_a_targeted_files_recipients_takes_the_dropped_familys_access_with_it(): void
    {
        $id = $this->upload(GroupResource::VISIBILITY_STUDENTS, [$this->kareem->id, $this->sama->id]);

        $this->asParent($this->samasParent)->get($this->familyUrl() . "/resources/{$id}/download")->assertOk();

        $this->asTeacher();
        $this->putJson($this->url() . "/resources/{$id}", [
            'visibility' => GroupResource::VISIBILITY_STUDENTS,
            'recipient_membership_ids' => [$this->kareem->id],
        ])->assertOk()->assertJsonPath('data.recipient_count', 1);

        $this->asParent($this->samasParent)->get($this->familyUrl() . "/resources/{$id}/download")->assertNotFound();
        $this->asParent($this->kareemsParent)->get($this->familyUrl() . "/resources/{$id}/download")->assertOk();
    }

    // ------------------------------------------------------------- staff reads

    #[Test]
    public function the_teacher_and_the_office_both_see_every_file_and_who_it_was_for(): void
    {
        $this->upload(GroupResource::VISIBILITY_STUDENTS, [$this->kareem->id, $this->sama->id]);
        $this->upload(GroupResource::VISIBILITY_FAMILIES);
        $this->upload();

        $rows = $this->getJson($this->url() . '/resources')->assertOk()->json('data');
        $this->assertCount(3, $rows);

        $byVisibility = collect($rows)->keyBy('visibility');
        $this->assertSame(2, $byVisibility[GroupResource::VISIBILITY_STUDENTS]['recipient_count']);
        $this->assertCount(2, $byVisibility[GroupResource::VISIBILITY_STUDENTS]['recipient_membership_ids']);
        $this->assertSame(0, $byVisibility[GroupResource::VISIBILITY_FAMILIES]['recipient_count']);
        $this->assertSame([], $byVisibility[GroupResource::VISIBILITY_FAMILIES]['recipient_membership_ids']);
        $this->assertSame(0, $byVisibility[GroupResource::VISIBILITY_STAFF]['recipient_count']);

        // And the office console, which mounts the same controller.
        $this->asOffice();
        $office = $this->getJson(
            "/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/resources"
        )->assertOk()->json('data');
        $this->assertCount(3, $office, 'the office is staff and sees the whole shelf');
    }

    /** The family payload must never carry WHO ELSE a handout went to. */
    #[Test]
    public function the_family_payload_never_names_the_other_recipients(): void
    {
        $this->upload(GroupResource::VISIBILITY_STUDENTS, [$this->kareem->id, $this->sama->id]);

        $row = $this->asParent($this->kareemsParent)->getJson($this->familyUrl() . '/resources')
            ->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('recipient_membership_ids', $row);
        $this->assertArrayNotHasKey('recipient_count', $row);
    }

    // ---------------------------------------------------------------- consent

    #[Test]
    public function a_guardian_who_never_consented_is_refused_a_targeted_file_too(): void
    {
        $child = $this->enrol($this->class, 'Bilal');
        $parent = $this->guardianOf($this->class, $child, 'Noor', consented: false);

        $id = $this->upload(GroupResource::VISIBILITY_STUDENTS, [$child->id]);

        // 403 on the LISTING (the honest answer to "am I entitled to this
        // class's handouts at all"), 404 on the file (which says nothing about
        // whether that id names anything).
        $this->asParent($parent)->getJson($this->familyUrl() . '/resources')->assertForbidden();
        $this->asParent($parent)->get($this->familyUrl() . "/resources/{$id}/download")->assertNotFound();
    }

    // ---------------------------------------------------------- notifications

    #[Test]
    public function a_targeted_file_nudges_only_the_named_childs_guardians(): void
    {
        Queue::fake();

        $this->upload(GroupResource::VISIBILITY_STUDENTS, [$this->kareem->id]);

        Queue::assertPushed(SendGroupNotificationJob::class, 1);
        Queue::assertPushed(SendGroupNotificationJob::class,
            fn (SendGroupNotificationJob $job): bool => $job->aboutContactId === (int) $this->kareem->contact_id);
    }

    #[Test]
    public function a_staff_only_file_nudges_nobody_and_a_whole_class_file_nudges_the_class(): void
    {
        Queue::fake();

        $this->upload();
        Queue::assertNotPushed(SendGroupNotificationJob::class);

        $this->upload(GroupResource::VISIBILITY_FAMILIES);
        Queue::assertPushed(SendGroupNotificationJob::class,
            fn (SendGroupNotificationJob $job): bool => $job->aboutContactId === null);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<int,int> $recipients */
    private function upload(string $visibility = GroupResource::VISIBILITY_STAFF, array $recipients = []): int
    {
        $payload = [
            'file' => UploadedFile::fake()->create('report-card.pdf', 12, 'application/pdf'),
            'title' => 'Progress report',
            'visibility' => $visibility,
        ];

        if ($recipients !== []) {
            $payload['recipient_membership_ids'] = $recipients;
        }

        return (int) $this->post($this->url() . '/resources', $payload, ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
    }

    private function asTeacher(): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        $this->flushHeaders();
        Sanctum::actingAs($this->teacher, ['staff']);

        return $this;
    }

    /** The office console — the school's owner, not a class teacher. */
    private function asOffice(): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        $this->flushHeaders();
        Sanctum::actingAs($this->office);

        return $this;
    }

    private function asParent(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        $this->flushHeaders();

        return $this->withHeader(
            'Authorization',
            'Bearer ' . $parent->createFamilyToken()->plainTextToken
        );
    }

    private function enrol(Group $group, string $firstName): GroupMembership
    {
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => $firstName, 'last_name' => 'Test',
        ]);

        return GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $group->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
    }

    private function guardianOf(Group $group, GroupMembership $child, string $name, bool $consented = true): Contact
    {
        $parent = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => $name, 'last_name' => 'Test',
            'login_email' => 'parent-' . uniqid() . '@example.test',
            'login_enabled_at' => now(),
        ]);

        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $group->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->contact_id,
            'confirmed_at' => now(),
            'consent_granted_at' => $consented ? now() : null,
            'consent_scope' => $consented ? GroupMembership::CONSENT_MEDIA : null,
        ]);

        return $parent;
    }

    private function url(): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}";
    }

    private function familyUrl(): string
    {
        return "/api/family/masjids/{$this->school->id}/groups/{$this->class->id}";
    }
}
