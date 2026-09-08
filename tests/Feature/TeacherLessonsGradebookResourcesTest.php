<?php

namespace Tests\Feature;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupResource;
use App\Models\GroupStaff;
use App\Models\LessonPlan;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lesson plans, the gradebook and class resources, over HTTP.
 *
 * The properties pinned here, in order of how badly each would hurt if it broke:
 * a staff-only file must never reach a family; a withdrawn assignment must not
 * count against a child; an unmarked cell must not read as a zero; and none of
 * the three may be written across a class boundary.
 */
class TeacherLessonsGradebookResourcesTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private Group $mine;
    private Group $notMine;
    private GroupMembership $student;
    private GroupMembership $otherClassStudent;
    private Contact $parent;

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
            'name' => 'Al-Razi Test ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->mine = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Pre-K & Kindergarten', 'slug' => 'pre-k-kindergarten',
        ]);
        $this->notMine = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => '1st & 2nd Grade', 'slug' => '1st-2nd-grade',
        ]);

        $this->mine->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->mine->masjid_id,
            'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $this->student = $this->enrol($this->mine, 'Kareem');
        $this->otherClassStudent = $this->enrol($this->notMine, 'Aalaa');
        $this->parent = $this->guardianOf($this->mine, $this->student);

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    // ---------------------------------------------------------------- lessons

    #[Test]
    public function a_plan_is_saved_and_read_back_and_saving_twice_corrects_it(): void
    {
        $day = now()->addDays(3)->toDateString();

        $this->putJson($this->url() . '/lesson-plans', [
            'session_date' => $day, 'title' => 'Letter ba', 'body' => 'Trace and sound out.',
        ])->assertOk();

        $this->putJson($this->url() . '/lesson-plans', [
            'session_date' => $day, 'title' => 'Letter ba', 'body' => 'Corrected.',
        ])->assertOk();

        $this->assertDatabaseCount('lesson_plans', 1);
        $this->assertSame('Corrected.', LessonPlan::first()->body);

        $plans = $this->getJson($this->url() . "/lesson-plans?from={$day}&to={$day}")
            ->assertOk()->json('data.plans');
        $this->assertCount(1, $plans);
        $this->assertSame('Corrected.', $plans[0]['body']);
    }

    #[Test]
    public function a_plan_may_be_for_a_future_day_but_not_a_mistyped_year(): void
    {
        $this->putJson($this->url() . '/lesson-plans', [
            'session_date' => now()->addMonths(3)->toDateString(), 'body' => 'Next term.',
        ])->assertOk();

        $this->putJson($this->url() . '/lesson-plans', [
            'session_date' => now()->addYears(3)->toDateString(), 'body' => 'Typo.',
        ])->assertUnprocessable();
    }

    #[Test]
    public function a_plan_can_be_removed_because_its_body_cannot_be_blanked(): void
    {
        $day = now()->toDateString();
        $this->putJson($this->url() . '/lesson-plans', ['session_date' => $day, 'body' => 'Wrong day.'])->assertOk();

        $this->deleteJson($this->url() . "/lesson-plans?date={$day}")->assertOk();
        $this->assertDatabaseCount('lesson_plans', 0);
    }

    // -------------------------------------------------------------- gradebook

    #[Test]
    public function work_is_set_marked_and_read_back_with_unmarked_children_left_blank(): void
    {
        $second = $this->enrol($this->mine, 'Sama');
        $id = $this->newAssignment();

        $this->putJson($this->url() . "/assignments/{$id}/scores", [
            'scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 8]],
        ])->assertOk();

        $students = collect($this->getJson($this->url() . "/assignments/{$id}")->assertOk()->json('data.students'))
            ->mapWithKeys(fn ($s) => [$s['contact']['first_name'] => $s]);

        $this->assertSame(8.0, $students['Kareem']['points_earned']);
        $this->assertNull($students['Sama']['status'], 'an unmarked child must be blank, never a zero');
        $this->assertNull($students['Sama']['points_earned']);
        $this->assertSame($second->id, $students['Sama']['membership_id']);
    }

    #[Test]
    public function a_mark_above_the_maximum_is_refused(): void
    {
        $id = $this->newAssignment();

        $this->putJson($this->url() . "/assignments/{$id}/scores", [
            'scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 99]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('assignment_scores', 0);
    }

    #[Test]
    public function a_gradebook_cannot_name_a_child_from_another_class(): void
    {
        $id = $this->newAssignment();

        $this->putJson($this->url() . "/assignments/{$id}/scores", [
            'scores' => [['membership_id' => $this->otherClassStudent->id, 'status' => 'scored', 'points_earned' => 5]],
        ])->assertUnprocessable();
    }

    #[Test]
    public function lowering_the_maximum_below_a_mark_already_entered_is_refused(): void
    {
        $id = $this->newAssignment();
        $this->putJson($this->url() . "/assignments/{$id}/scores", [
            'scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 9]],
        ])->assertOk();

        $this->putJson($this->url() . "/assignments/{$id}", [
            'title' => 'Spelling', 'points_possible' => 5, 'assigned_on' => now()->toDateString(),
        ])->assertUnprocessable();

        $this->assertSame(10, ClassAssignment::find($id)->points_possible);
    }

    #[Test]
    public function withdrawn_work_stops_counting_but_its_marks_survive(): void
    {
        $id = $this->newAssignment();
        $this->putJson($this->url() . "/assignments/{$id}/scores", [
            'scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 9]],
        ])->assertOk();

        $this->deleteJson($this->url() . "/assignments/{$id}")->assertOk();

        $summary = $this->getJson($this->url() . "/members/{$this->student->id}/grades")
            ->assertOk()->json('data.summary');

        $this->assertSame(0, $summary['recorded'], 'withdrawn work must not count');
        $this->assertSame(1, AssignmentScore::count(), 'but the mark itself is retained');
    }

    #[Test]
    public function excused_work_is_excluded_from_both_halves_of_the_average(): void
    {
        $a = $this->newAssignment();
        $b = $this->newAssignment();

        $this->putJson($this->url() . "/assignments/{$a}/scores", [
            'scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 8]],
        ])->assertOk();
        $this->putJson($this->url() . "/assignments/{$b}/scores", [
            'scores' => [['membership_id' => $this->student->id, 'status' => 'excused']],
        ])->assertOk();

        $summary = $this->getJson($this->url() . "/members/{$this->student->id}/grades")
            ->assertOk()->json('data.summary');

        $this->assertSame(8.0, (float) $summary['points_earned']);
        $this->assertSame(10.0, (float) $summary['points_possible'], 'the excused work must not enlarge the denominator');
        $this->assertSame(1, $summary['excused']);
    }

    // -------------------------------------------------------------- resources

    #[Test]
    public function a_file_is_uploaded_listed_and_downloaded_and_defaults_to_staff_only(): void
    {
        $id = $this->upload();

        $this->assertSame(GroupResource::VISIBILITY_STAFF, GroupResource::find($id)->visibility,
            'a payload that does not say must produce a PRIVATE file');

        $this->getJson($this->url() . '/resources')->assertOk()->assertJsonCount(1, 'data');
        $this->get($this->url() . "/resources/{$id}/download")->assertOk();
    }

    #[Test]
    public function deleting_a_resource_takes_the_bytes_with_it(): void
    {
        $id = $this->upload();
        $path = GroupResource::find($id)->path;

        Storage::disk('local')->assertExists($path);
        $this->deleteJson($this->url() . "/resources/{$id}")->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    #[Test]
    public function a_family_sees_only_what_the_class_shared_and_a_staff_file_is_a_404(): void
    {
        $staffOnly = $this->upload();
        $shared = $this->upload(GroupResource::VISIBILITY_FAMILIES);

        $listed = $this->asParent()->getJson($this->familyUrl() . '/resources')->assertOk()->json('data');

        $this->assertCount(1, $listed, 'a staff-only file must not appear at all');
        $this->assertSame($shared, $listed[0]['id']);

        // Not a 403: that would confirm the id exists in their child's class.
        $this->asParent()->get($this->familyUrl() . "/resources/{$staffOnly}/download")->assertNotFound();
        $this->asParent()->get($this->familyUrl() . "/resources/{$shared}/download")->assertOk();
    }

    // ---------------------------------------------------------------- helpers

    private function newAssignment(): int
    {
        return (int) $this->postJson($this->url() . '/assignments', [
            'title' => 'Spelling', 'points_possible' => 10, 'assigned_on' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    private function upload(string $visibility = GroupResource::VISIBILITY_STAFF): int
    {
        return (int) $this->post($this->url() . '/resources', [
            'file' => UploadedFile::fake()->create('worksheet.pdf', 12, 'application/pdf'),
            'title' => 'Worksheet',
            'visibility' => $visibility,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    private function asParent(): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader(
            'Authorization',
            'Bearer ' . $this->parent->createFamilyToken()->plainTextToken
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

    private function guardianOf(Group $group, GroupMembership $child): Contact
    {
        $parent = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Huda', 'last_name' => 'Test',
            'login_email' => 'parent-' . uniqid() . '@example.test',
            'login_enabled_at' => now(),
        ]);

        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $group->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->contact_id,
            'confirmed_at' => now(),
            'consent_granted_at' => now(),
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        return $parent;
    }

    private function url(): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}";
    }

    private function familyUrl(): string
    {
        return "/api/family/masjids/{$this->school->id}/groups/{$this->mine->id}";
    }
}
