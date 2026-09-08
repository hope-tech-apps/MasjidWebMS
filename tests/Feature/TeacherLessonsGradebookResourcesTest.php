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
use App\Jobs\SendGroupNotificationJob;
use App\Models\GroupThread;
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
    public function a_plan_carries_the_schools_whole_template(): void
    {
        $day = now()->addDay()->toDateString();

        $this->putJson($this->url() . '/lesson-plans', [
            'session_date' => $day,
            'body' => 'Count to five with objects.',
            'subject' => 'Mathematics',
            'grade_label' => 'Pre-K',
            'curriculum_week_no' => 5,
            'standard_code' => 'K.CC.A.1',
            'standard_description' => 'Count to 100 by ones.',
            'objective' => 'Students will count to five.',
            'learning_outcomes' => ['Counts to 5', 'One-to-one touch'],
            'differentiation_support' => 'Count to 3 with hand-over-hand.',
            'cross_integration_islamic' => 'Counting the blessings of Allah.',
            'teaching_methods' => ['modeling', 'hands_on_activity'],
            'assessment_formative' => 'Observe at the table.',
            'reflection_worked' => 'They loved the counting bears.',
        ])->assertOk();

        $plan = LessonPlan::first();
        $this->assertSame('Mathematics', $plan->subject);
        $this->assertSame(5, $plan->curriculum_week_no);
        $this->assertSame(['Counts to 5', 'One-to-one touch'], $plan->learning_outcomes);
        $this->assertSame(['modeling', 'hands_on_activity'], $plan->teaching_methods);

        // Read back whole: the client must be able to re-send the entire object.
        $read = $this->getJson($this->url() . "/lesson-plans?from={$day}&to={$day}")
            ->assertOk()->json('data.plans.0');
        $this->assertSame('K.CC.A.1', $read['standard_code']);
        $this->assertSame('Counting the blessings of Allah.', $read['cross_integration_islamic']);
        $this->assertArrayHasKey('reflection_improve', $read, 'every template field is present even when null');
    }

    /** An omitted field CLEARS — the endpoint is a whole-row upsert, not a patch. */
    #[Test]
    public function saving_without_a_section_clears_it_rather_than_keeping_stale_prose(): void
    {
        $day = now()->toDateString();

        $this->putJson($this->url() . '/lesson-plans', [
            'session_date' => $day, 'body' => 'First draft.', 'objective' => 'Typed by mistake.',
        ])->assertOk();

        $this->putJson($this->url() . '/lesson-plans', [
            'session_date' => $day, 'body' => 'Second draft.',
        ])->assertOk();

        $this->assertNull(LessonPlan::first()->objective);
    }

    #[Test]
    public function an_unknown_teaching_method_is_refused(): void
    {
        $this->putJson($this->url() . '/lesson-plans', [
            'session_date' => now()->toDateString(),
            'body' => 'Something.',
            'teaching_methods' => ['telepathy'],
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

        // (float) cast: JSON encodes 8.00 as 8, so compare numerically.
        $this->assertSame(8.0, (float) $students['Kareem']['points_earned']);
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

    /**
     * The average is over the whole term, not over the page of marks below it.
     *
     * The old code took a `limit()` with NO ordering at all and then sorted in
     * PHP, so past the page size the average was computed from whichever rows
     * the database happened to return first. Not truncated — arbitrary. A wrong
     * average on a screen a parent may be shown is worse than a missing one.
     */
    #[Test]
    public function the_term_average_is_aggregated_over_every_mark_not_over_the_page(): void
    {
        config(['groups.records_page_size' => 2]);

        foreach (range(1, 5) as $i) {
            $id = $this->newAssignment();
            $this->putJson($this->url() . "/assignments/{$id}/scores", [
                'scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 6]],
            ])->assertOk();
        }

        $data = $this->getJson($this->url() . "/members/{$this->student->id}/grades")
            ->assertOk()->json('data');

        $this->assertSame(5, $data['summary']['recorded'], 'the term, not the page');
        $this->assertSame(30.0, (float) $data['summary']['points_earned'], '5 marks of 6, not 2');
        $this->assertSame(50.0, (float) $data['summary']['points_possible'], '5 assignments of 10, not 2');

        $this->assertSame(2, $data['scores_shown']);
        $this->assertTrue($data['scores_truncated'], 'a short list under a full average must say so');
    }

    // ------------------------------------------- the four performance levels

    /**
     * THE POINT OF THE WHOLE SCALE: a level is never a percentage.
     *
     * A child who "Meets Expectations" on every criterion is at level 3. The
     * cheap implementation — points_possible = 4, run the existing average —
     * would render that as 3/4 = 75%, turning solid grade-level proficiency into
     * a C on a screen a parent may be shown. This asserts the levels summary
     * reports a MEAN LEVEL and a distribution, and that the points numerator and
     * denominator stay empty because no points work exists.
     */
    #[Test]
    public function a_levels_average_is_a_mean_level_and_never_a_percentage(): void
    {
        $this->mark($this->newLevelsAssignment('Reading'), 3);
        $this->mark($this->newLevelsAssignment('Writing'), 3);
        $this->mark($this->newLevelsAssignment('Math'), 4);
        $this->mark($this->newLevelsAssignment('Science'), 2);

        $summary = $this->getJson($this->url() . "/members/{$this->student->id}/grades")
            ->assertOk()->json('data.summary');

        $this->assertSame(3.0, (float) $summary['levels']['mean'], '(3+3+4+2)/4');
        $this->assertSame('Meets', $summary['levels']['mean_label']);
        $this->assertSame(4, $summary['levels']['counted']);

        // The points half stays EMPTY. A levels mark reaching the points
        // numerator is the bug this whole slice exists to prevent.
        $this->assertSame(0.0, (float) $summary['points_earned']);
        $this->assertSame(0.0, (float) $summary['points_possible']);
        $this->assertSame(0, $summary['points_counted']);

        $byLevel = collect($summary['levels']['distribution'])->keyBy('level');
        $this->assertSame(1, $byLevel[4]['count']);
        $this->assertSame(2, $byLevel[3]['count']);
        $this->assertSame(1, $byLevel[2]['count']);
        $this->assertSame(0, $byLevel[1]['count'], 'every level is present even at zero');
    }

    /**
     * A class may hold both kinds of work at once. Adding a 4-level denominator
     * to a 10-point one produces a number nobody can see is wrong.
     */
    #[Test]
    public function points_work_and_levels_work_are_summarised_separately(): void
    {
        $points = $this->newAssignment();
        $this->putJson($this->url() . "/assignments/{$points}/scores", [
            'scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 8]],
        ])->assertOk();

        $this->mark($this->newLevelsAssignment(), 4);

        $summary = $this->getJson($this->url() . "/members/{$this->student->id}/grades")
            ->assertOk()->json('data.summary');

        $this->assertSame(8.0, (float) $summary['points_earned']);
        $this->assertSame(10.0, (float) $summary['points_possible'], 'the level must not enlarge the denominator');
        $this->assertSame(4.0, (float) $summary['levels']['mean']);
        $this->assertSame(2, $summary['recorded'], 'both are still counted as recorded work');
    }

    /**
     * `missing` counts on the points scale as a real zero over a real
     * denominator. There is no equivalent here: 1 is not "nothing", it is "Needs
     * Support" — a judgement about a child's understanding that nobody made.
     */
    #[Test]
    public function missing_levels_work_is_shown_but_left_out_of_the_mean(): void
    {
        $this->mark($this->newLevelsAssignment('Done'), 4);

        $skipped = $this->newLevelsAssignment('Not handed in');
        $this->putJson($this->url() . "/assignments/{$skipped}/scores", [
            'scores' => [['membership_id' => $this->student->id, 'status' => 'missing']],
        ])->assertOk();

        $levels = $this->getJson($this->url() . "/members/{$this->student->id}/grades")
            ->assertOk()->json('data.summary.levels');

        $this->assertSame(1, $levels['missing']);
        $this->assertSame(2, $levels['recorded'], 'the missing piece is still shown');
        $this->assertSame(1, $levels['counted']);
        $this->assertSame(4.0, (float) $levels['mean'], 'and must not drag the mean toward 1');
    }

    #[Test]
    public function a_levels_assignment_only_accepts_the_four_whole_levels(): void
    {
        $id = $this->newLevelsAssignment();

        $this->assertSame(4, ClassAssignment::find($id)->points_possible, 'the maximum is implied, not asked for');

        foreach ([0, 5, 2.5] as $notALevel) {
            $this->putJson($this->url() . "/assignments/{$id}/scores", [
                'scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => $notALevel]],
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('assignment_scores', 0);
    }

    /**
     * The key travels WITH the data, so no screen hardcodes "4 means Exceeds"
     * and every surface says it in the school's own words.
     */
    #[Test]
    public function every_gradebook_payload_carries_the_key_to_the_levels(): void
    {
        $id = $this->newLevelsAssignment();

        foreach ([
            $this->url() . '/assignments',
            $this->url() . "/assignments/{$id}",
            $this->url() . "/members/{$this->student->id}/grades",
        ] as $url) {
            $key = $this->getJson($url)->assertOk()->json('performance_levels')
                ?? $this->getJson($url)->assertOk()->json('data.performance_levels');

            $this->assertCount(4, $key, "no key on {$url}");
            $this->assertSame(4, $key[0]['level'], 'highest first, as the school lays it out');
            $this->assertSame('Exceeds Expectations', $key[0]['label']);
            $this->assertStringContainsString('mastery', $key[0]['description']);
            $this->assertSame('Needs Support', $key[3]['label']);
        }
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

    // ------------------------------------------------- parent-opened threads

    #[Test]
    public function a_parent_opens_a_conversation_and_the_teacher_is_notified(): void
    {
        Queue::fake();

        $this->asParent()->postJson($this->familyUrl() . '/threads', [
            'subject' => 'Settling in',
            'about_membership_id' => $this->student->id,
            'body' => 'He has been talking about class all week.',
        ])->assertCreated();

        $thread = GroupThread::withoutGlobalScopes()->first();
        $this->assertSame(GroupThread::SCOPE_PARTICIPANT, $thread->scope);
        $this->assertSame((int) $this->parent->id, (int) $thread->created_by_contact_id);
        $this->assertNull($thread->created_by_user_id, 'a parent-opened thread has no staff creator');

        Queue::assertPushed(SendGroupNotificationJob::class);
    }

    #[Test]
    public function a_parent_cannot_open_a_conversation_about_another_familys_child(): void
    {
        $other = $this->enrol($this->mine, 'Sama');

        $this->asParent()->postJson($this->familyUrl() . '/threads', [
            'subject' => 'About that child',
            'about_membership_id' => $other->id,
            'body' => 'Not my child.',
        ])->assertForbidden();

        $this->assertDatabaseCount('group_threads', 0);
    }

    /** The audience is forced server-side; no payload can widen it. */
    #[Test]
    public function a_parent_cannot_open_a_class_wide_thread_even_by_asking(): void
    {
        $this->asParent()->postJson($this->familyUrl() . '/threads', [
            'subject' => 'Everyone should know',
            'scope' => 'group',
            'about_membership_id' => $this->student->id,
            'body' => 'Trying to reach the whole class.',
        ])->assertCreated();

        $this->assertSame(
            GroupThread::SCOPE_PARTICIPANT,
            GroupThread::withoutGlobalScopes()->first()->scope,
            'a scope in the payload must be ignored, never honoured'
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A POINTS assignment, out of 10.
     *
     * `scale` is stated EXPLICITLY even though it used to be absent. Since the
     * performance-level scale landed, an omitted scale takes
     * `config('groups.default_grading_scale')`, which is `levels` — and a levels
     * assignment forces points_possible to 4. Every points test below would then
     * have gone on passing while testing the other scale entirely, which is the
     * worst kind of green.
     */
    private function newAssignment(): int
    {
        return (int) $this->postJson($this->url() . '/assignments', [
            'title' => 'Spelling',
            'points_possible' => 10,
            'scale' => ClassAssignment::SCALE_POINTS,
            'assigned_on' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    /** A performance-LEVELS assignment, marked 4 down to 1. */
    private function newLevelsAssignment(string $title = 'Reading Fluency'): int
    {
        return (int) $this->postJson($this->url() . '/assignments', [
            'title' => $title,
            'scale' => ClassAssignment::SCALE_LEVELS,
            'assigned_on' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    private function mark(int $assignment, int $level): void
    {
        $this->putJson($this->url() . "/assignments/{$assignment}/scores", [
            'scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => $level]],
        ])->assertOk();
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
