<?php

namespace Tests\Feature;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\ClassGradeWeight;
use App\Models\Contact;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\LessonPlan;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolSubject;
use App\Models\User;
use App\Support\SubjectFence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A teacher sees — and may use — only the parts of a class their subject owns.
 *
 * Owner, 2026-09-21, promised at the BISS teacher meeting: "they'll have only
 * access specific to the subject they're teaching." Arabic owns the letters and
 * the daily Arabic notes; Qur'an owns hifdh; Islamic Studies owns neither. Every
 * other part of a class belongs to whoever teaches it at all.
 *
 * Extended 2026-09-29 (W3): the same promise now reaches LESSON PLANS and the
 * GRADEBOOK. A BISS teacher limited to Qur'an used to list, set, edit and mark
 * Arabic work, because grades sat outside both fences. They cannot now, and the
 * office (which reads the same controllers through the admin realm) still sees
 * everything.
 *
 * Refused on the SERVER, not only hidden: a hidden tab is not a boundary. And an
 * assignment with no subjects recorded teaches everything, because that is every
 * assignment that existed before this and every full-time teacher — the case the
 * first test pins, since breaking it would lock Al-Razi's teachers out of hifdh.
 */
class TeacherSubjectAccessTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
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

        $this->school = Masjid::create([
            'name' => 'BISS Test '.uniqid(), 'email' => 'biss-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => '1st & 2nd Grade', 'slug' => 'first-second',
        ]);

        $child = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Esraa']);
        $this->student = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    #[Test]
    public function a_teacher_with_no_subjects_recorded_keeps_the_whole_class(): void
    {
        // Every assignment before 2026-09-21, and every full-time teacher.
        $this->assign(null);

        $this->getJson($this->url('/letters'))->assertOk();
        $this->getJson($this->url('/hifz'))->assertOk();
        $this->getJson($this->url('/members/'.$this->student->id.'/arabic-notes'))->assertOk();
        $this->getJson($this->url('/'))->assertOk()->assertJsonPath('data.my_subjects', null);
    }

    #[Test]
    public function an_arabic_teacher_gets_the_letters_and_not_the_quran(): void
    {
        $this->assign([GroupStaff::SUBJECT_ARABIC]);

        $this->getJson($this->url('/letters'))->assertOk();
        $this->getJson($this->url('/members/'.$this->student->id.'/arabic-notes'))->assertOk();

        $this->getJson($this->url('/hifz'))
            ->assertForbidden()
            ->assertJsonPath('message', "You do not teach Qur'an in this class.");
        $this->postJson($this->url('/hifz'), [])->assertForbidden();
    }

    #[Test]
    public function a_quran_teacher_gets_hifdh_and_not_the_letters(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);

        $this->getJson($this->url('/hifz'))->assertOk();

        $this->getJson($this->url('/letters'))->assertForbidden();
        $this->putJson($this->url('/members/'.$this->student->id.'/letters'), [])->assertForbidden();
        $this->getJson($this->url('/members/'.$this->student->id.'/arabic-notes'))->assertForbidden();
    }

    #[Test]
    public function an_islamic_studies_teacher_gets_neither_but_keeps_everything_shared(): void
    {
        $this->assign([GroupStaff::SUBJECT_ISLAMIC_STUDIES]);

        $this->getJson($this->url('/letters'))->assertForbidden();
        $this->getJson($this->url('/hifz'))->assertForbidden();

        // The shared parts of the class are theirs.
        $this->getJson($this->url('/'))->assertOk()
            ->assertJsonPath('data.my_subjects', [GroupStaff::SUBJECT_ISLAMIC_STUDIES]);
        $this->getJson($this->url('/awards'))->assertOk();
        $this->getJson($this->url('/posts'))->assertOk();
    }

    #[Test]
    public function a_teacher_of_two_subjects_gets_both(): void
    {
        $this->assign([GroupStaff::SUBJECT_ARABIC, GroupStaff::SUBJECT_QURAN]);

        $this->getJson($this->url('/letters'))->assertOk();
        $this->getJson($this->url('/hifz'))->assertOk();
    }

    #[Test]
    public function an_empty_list_means_everything_so_no_one_is_locked_out_of_their_own_class(): void
    {
        $this->assign([]);

        $this->getJson($this->url('/letters'))->assertOk();
        $this->getJson($this->url('/hifz'))->assertOk();
    }

    // ============================================================ LESSON PLANS

    #[Test]
    public function a_quran_teacher_lists_only_the_plans_in_subjects_they_teach_and_the_general_plan(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $day = now()->toDateString();

        foreach ([
            null, "Qur'an", 'Qur’an & Islamic Studies', 'Arabic Language', 'Islamic Studies', 'Mathematics',
        ] as $subject) {
            $this->plan($day, $subject);
        }

        $subjects = collect($this->getJson($this->url('/lesson-plans?from='.$day.'&to='.$day))
            ->assertOk()->json('data.plans'))->pluck('subject')->all();

        // The general plan (no subject) stays: fencing it would strand every plan
        // BISS has. The combined guide column belongs to both staff subjects.
        $this->assertEqualsCanonicalizing([null, "Qur'an", 'Qur’an & Islamic Studies'], $subjects);
    }

    #[Test]
    public function an_unrestricted_teacher_lists_every_plan(): void
    {
        $this->assign(null);
        $day = now()->toDateString();
        foreach ([null, "Qur'an", 'Arabic Language', 'Mathematics'] as $subject) {
            $this->plan($day, $subject);
        }

        $this->assertCount(4, $this->getJson($this->url('/lesson-plans?from='.$day.'&to='.$day))->assertOk()->json('data.plans'));
    }

    #[Test]
    public function a_quran_teacher_cannot_write_a_plan_in_another_subject(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $day = now()->addDay()->toDateString();

        $this->postJson($this->url('/lesson-plans'), ['session_date' => $day, 'subject' => 'Arabic Language', 'body' => 'Alif.'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not teach Arabic Language in this class.');

        $this->postJson($this->url('/lesson-plans'), ['session_date' => $day, 'subject' => 'Mathematics', 'body' => 'Sums.'])
            ->assertForbidden();

        $this->assertSame(0, LessonPlan::query()->count());

        $this->postJson($this->url('/lesson-plans'), ['session_date' => $day, 'subject' => "Qur'an", 'body' => 'Fatiha.'])->assertOk();
        $this->postJson($this->url('/lesson-plans'), ['session_date' => now()->addDays(2)->toDateString(), 'body' => 'General.'])->assertOk();
    }

    #[Test]
    public function a_quran_teacher_cannot_rewrite_move_or_remove_another_subjects_plan(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $day = now()->addDay()->toDateString();
        $arabic = $this->plan($day, 'Arabic Language');
        $mine = $this->plan($day, "Qur'an");

        // By id: rewrite and remove. Another subject's plan is not there for them: a 404, not a
        // refusal that names Arabic Language (review F4).
        $this->putJson($this->url("/lesson-plans/{$arabic->id}"), ['session_date' => $day, 'subject' => 'Arabic Language', 'body' => 'Hijacked.'])
            ->assertNotFound();
        $this->deleteJson($this->url("/lesson-plans/{$arabic->id}"))->assertNotFound();

        // Moving their own plan INTO Arabic is a refusal: the subject is theirs to name, and it is named back.
        $this->putJson($this->url("/lesson-plans/{$mine->id}"), ['session_date' => $day, 'subject' => 'Arabic Language', 'body' => 'Moved.'])
            ->assertForbidden();

        $this->assertSame('Body.', $arabic->fresh()->body);
        $this->assertSame("Qur'an", $mine->fresh()->subject);
    }

    #[Test]
    public function the_by_day_save_and_delete_cannot_be_used_to_reach_another_subjects_only_plan(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $day = now()->addDay()->toDateString();
        $arabic = $this->plan($day, 'Arabic Language');

        // The old address used to fall back to "the day's only plan", which is Arabic's. It must
        // never be rewritten under a Qur'an label (and, review F4, must not 403 either: see next test).
        $this->putJson($this->url('/lesson-plans'), ['session_date' => $day, 'subject' => "Qur'an", 'body' => 'Mine.'])->assertOk();

        // Delete-by-day on a day whose only plan is Arabic's: nothing of theirs is there.
        LessonPlan::query()->where('subject', "Qur'an")->delete();
        $this->deleteJson($this->url('/lesson-plans?date='.$day))->assertNotFound();

        $this->assertSame('Body.', $arabic->fresh()->body);
        $this->assertSame('Arabic Language', $arabic->fresh()->subject);
        $this->assertNotNull(LessonPlan::query()->find($arabic->id));
    }

    // ---- review F4 (2026-09-29): the by-day save creates the teacher's own plan; refusals name nothing

    #[Test]
    public function a_limited_teachers_by_day_save_on_a_day_holding_only_another_subjects_plan_creates_their_own(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $day = now()->addDay()->toDateString();
        $arabic = $this->plan($day, 'Arabic Language');
        $before = $arabic->fresh()->only(['subject', 'body', 'title', 'author_user_id', 'updated_at']);

        $created = $this->putJson($this->url('/lesson-plans'), ['session_date' => $day, 'subject' => "Qur'an", 'body' => 'Fatiha.'])
            ->assertOk()->assertJsonPath('data.subject', "Qur'an")->assertJsonPath('data.body', 'Fatiha.');

        $this->assertNotSame($arabic->id, $created->json('data.id'), 'a new plan, not the Arabic one renamed');
        $this->assertSame(2, LessonPlan::query()->count());
        $this->assertEquals($before, $arabic->fresh()->only(['subject', 'body', 'title', 'author_user_id', 'updated_at']), "Arabic's plan is untouched");

        // Saving again corrects THEIR plan; it does not add a third.
        $this->putJson($this->url('/lesson-plans'), ['session_date' => $day, 'subject' => "Qur'an", 'body' => 'Fatiha, corrected.'])
            ->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(2, LessonPlan::query()->count());
        $this->assertSame('Body.', $arabic->fresh()->body);

        // The teacher's own view shows theirs alone.
        $this->assertSame(
            ["Qur'an"],
            collect($this->getJson($this->url('/lesson-plans?from='.$day.'&to='.$day))->assertOk()->json('data.plans'))->pluck('subject')->all()
        );
    }

    #[Test]
    public function a_limited_teachers_by_day_save_ignores_every_plan_they_may_not_touch_however_many_there_are(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $day = now()->addDay()->toDateString();
        $arabic = $this->plan($day, 'Arabic Language');
        $maths = $this->plan($day, 'Mathematics');

        // Two plans they may not touch, none of theirs: still a plan of their own, never a 403.
        $this->putJson($this->url('/lesson-plans'), ['session_date' => $day, 'subject' => "Qur'an", 'body' => 'Mine.'])->assertOk();
        $this->assertSame(3, LessonPlan::query()->count());
        $this->assertSame('Body.', $arabic->fresh()->body);
        $this->assertSame('Body.', $maths->fresh()->body);

        // The day's general plan is open to every teacher, and it is the only plan THEY see on this day
        // besides their own, so it is theirs to rename the way the old screen meant it. Arabic is still not.
        $other = now()->addDays(2)->toDateString();
        $hidden = $this->plan($other, 'Arabic Language');
        $general = $this->plan($other, null);
        $this->putJson($this->url('/lesson-plans'), ['session_date' => $other, 'subject' => "Qur'an", 'body' => 'Renamed.'])
            ->assertOk()->assertJsonPath('data.id', $general->id);
        $this->assertSame('Arabic Language', $hidden->fresh()->subject);
        $this->assertSame('Body.', $hidden->fresh()->body);
    }

    #[Test]
    public function a_limited_teacher_still_cannot_save_a_plan_in_a_subject_they_do_not_teach_by_day_either(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $day = now()->addDay()->toDateString();

        // With no Arabic plan that day, and then with one: the refusal is the same sentence, in the words the
        // teacher typed (lower case here), so it says nothing about what the day holds.
        $none = $this->putJson($this->url('/lesson-plans'), ['session_date' => $day, 'subject' => 'arabic language', 'body' => 'x'])
            ->assertForbidden();
        $this->plan($day, 'Arabic Language');
        $some = $this->putJson($this->url('/lesson-plans'), ['session_date' => $day, 'subject' => 'arabic language', 'body' => 'x'])
            ->assertForbidden();

        $this->assertSame($none->getContent(), $some->getContent());
        $this->assertSame('You do not teach arabic language in this class.', $some->json('message'));
        $this->assertSame(1, LessonPlan::query()->count());
    }

    #[Test]
    public function another_subjects_plan_by_id_answers_exactly_what_an_id_that_names_no_plan_answers(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $day = now()->addDay()->toDateString();
        $arabic = $this->plan($day, 'Arabic Language');
        $missing = $arabic->id + 1000;
        // What production sends: the sanitised message. (Debug mode echoes the model and the id.)
        config(['app.debug' => false]);

        $put = ['session_date' => $day, 'subject' => "Qur'an", 'body' => 'x'];

        $hiddenPut = $this->putJson($this->url("/lesson-plans/{$arabic->id}"), $put);
        $missingPut = $this->putJson($this->url("/lesson-plans/{$missing}"), $put);
        $hiddenDelete = $this->deleteJson($this->url("/lesson-plans/{$arabic->id}"));
        $missingDelete = $this->deleteJson($this->url("/lesson-plans/{$missing}"));

        foreach ([[$hiddenPut, $missingPut], [$hiddenDelete, $missingDelete]] as [$hidden, $absent]) {
            $this->assertSame(404, $hidden->getStatusCode());
            $this->assertSame($absent->getStatusCode(), $hidden->getStatusCode());
            $this->assertSame($absent->getContent(), $hidden->getContent(), 'byte for byte');
            $this->assertStringNotContainsString('Arabic', $hidden->getContent());
        }

        // Debug mode adds the model and the id the caller sent, and nothing else: the same words for both.
        config(['app.debug' => true]);
        $a = $this->deleteJson($this->url("/lesson-plans/{$arabic->id}"))->json('message');
        $b = $this->deleteJson($this->url("/lesson-plans/{$missing}"))->json('message');
        $this->assertSame(str_replace((string) $missing, '#', $b), str_replace((string) $arabic->id, '#', $a));

        $this->assertNotNull(LessonPlan::query()->find($arabic->id));
    }

    #[Test]
    public function the_by_day_delete_removes_only_a_plan_the_teacher_may_touch_and_never_counts_a_hidden_one(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $day = now()->addDay()->toDateString();
        $arabic = $this->plan($day, 'Arabic Language');
        $maths = $this->plan($day, 'Mathematics');
        $mine = $this->plan($day, "Qur'an");

        // Three plans in the table, one of them theirs: that is "the day's plan", it goes, the others stay
        // (this used to 409, telling them the day held more than one subject).
        $this->deleteJson($this->url('/lesson-plans?date='.$day))->assertOk();
        $this->assertNull(LessonPlan::query()->find($mine->id));
        $this->assertNotNull(LessonPlan::query()->find($arabic->id));
        $this->assertNotNull(LessonPlan::query()->find($maths->id));

        // Two plans of THEIRS is a real 409, and deletes nothing.
        $one = $this->plan($day, "Qur'an");
        $two = $this->plan($day, null);
        $this->deleteJson($this->url('/lesson-plans?date='.$day))->assertStatus(409);
        $this->assertNotNull(LessonPlan::query()->find($one->id));
        $this->assertNotNull(LessonPlan::query()->find($two->id));
        $this->assertNotNull(LessonPlan::query()->find($arabic->id));
    }

    #[Test]
    public function a_by_day_delete_with_nothing_of_the_teachers_on_the_day_is_the_same_404_whether_the_day_is_empty_or_holds_another_subjects_plan(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $empty = now()->addDay()->toDateString();
        $held = now()->addDays(2)->toDateString();
        $arabic = $this->plan($held, 'Arabic Language');

        $nothing = $this->deleteJson($this->url('/lesson-plans?date='.$empty))->assertNotFound();
        $hidden = $this->deleteJson($this->url('/lesson-plans?date='.$held))->assertNotFound();

        $this->assertSame($nothing->getContent(), $hidden->getContent(), 'byte for byte: the two cannot be told apart');
        $this->assertStringNotContainsString('Arabic', $hidden->getContent());
        $this->assertNotNull(LessonPlan::query()->find($arabic->id));
    }

    #[Test]
    public function an_unrestricted_teachers_by_day_delete_is_unchanged(): void
    {
        $this->assign(null);
        $empty = now()->addDay()->toDateString();
        $day = now()->addDays(2)->toDateString();

        // An empty day is still a harmless 200; two plans still 409; one plan goes.
        $this->deleteJson($this->url('/lesson-plans?date='.$empty))->assertOk();
        $arabic = $this->plan($day, 'Arabic Language');
        $mine = $this->plan($day, "Qur'an");
        $this->deleteJson($this->url('/lesson-plans?date='.$day))->assertStatus(409);
        $this->deleteJson($this->url("/lesson-plans/{$mine->id}"))->assertOk();
        $this->deleteJson($this->url('/lesson-plans?date='.$day))->assertOk();
        $this->assertNull(LessonPlan::query()->find($arabic->id));
    }

    #[Test]
    public function the_office_is_never_fenced_even_when_it_also_holds_a_class_assignment(): void
    {
        // An office administrator who is ALSO on the class's staff with one
        // subject: acting as the office they read everything.
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->school->user_id = $admin->id;
        $this->school->save();
        $this->class->staff()->attach($admin->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER,
            'subjects' => [GroupStaff::SUBJECT_QURAN], 'assigned_at' => now(),
        ]);
        $day = now()->toDateString();
        $this->plan($day, 'Arabic Language');
        $this->plan($day, "Qur'an");
        ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => 'Arabic quiz',
            'points_possible' => 10, 'scale' => 'points', 'subject' => 'Arabic Language', 'assigned_on' => $day,
        ]);

        \Illuminate\Support\Facades\Auth::forgetGuards();
        app(\App\Support\TenantContext::class)->forgetTenant();
        Sanctum::actingAs($admin);
        $base = "/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}";

        $this->assertCount(2, $this->getJson("{$base}/lesson-plans?from={$day}&to={$day}")->assertOk()->json('data.plans'));
        $this->assertCount(1, $this->getJson("{$base}/assignments")->assertOk()->json('data'));
    }

    #[Test]
    public function the_lesson_plan_subject_dropdown_is_limited_the_same_way(): void
    {
        foreach (['Mathematics', 'Arabic Language', "Qur'an"] as $name) {
            SchoolSubject::create(['masjid_id' => $this->school->id, 'name' => $name]);
        }
        CurriculumWeek::create([
            'masjid_id' => $this->school->id, 'grade_label' => '2nd', 'subject' => 'Qur’an & Islamic Studies', 'week_no' => 1, 'focus' => 'x',
        ]);
        $this->assign([GroupStaff::SUBJECT_QURAN]);

        $base = "/api/teacher/masjids/{$this->school->id}/curriculum?grade=2nd";

        $fenced = $this->getJson($base.'&group_id='.$this->class->id)->assertOk()->json('data.subjects');
        $this->assertEqualsCanonicalizing(["Qur'an", 'Qur’an & Islamic Studies'], $fenced);

        // Without a class named it is the whole list: reference data, and the
        // write is the boundary.
        $all = $this->getJson($base)->assertOk()->json('data.subjects');
        $this->assertContains('Mathematics', $all);
    }

    // ================================================================ GRADEBOOK

    #[Test]
    public function a_quran_teacher_lists_only_quran_work_and_never_untagged_or_other_subjects(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        foreach ([["Qur'an"], ['Qur’an & Islamic Studies'], ['Arabic Language'], ['Islamic Studies'], ['Mathematics'], [null]] as [$subject]) {
            $this->work($subject ?? 'Untagged', $subject);
        }

        $titles = collect($this->getJson($this->url('/assignments'))->assertOk()->json('data'))->pluck('title')->all();

        $this->assertEqualsCanonicalizing(["Qur'an", 'Qur’an & Islamic Studies'], $titles);
    }

    #[Test]
    public function an_unrestricted_teacher_lists_all_work_including_the_untagged(): void
    {
        $this->assign(null);
        foreach (["Qur'an", 'Arabic Language', null] as $subject) {
            $this->work($subject ?? 'Untagged', $subject);
        }

        $this->assertCount(3, $this->getJson($this->url('/assignments'))->assertOk()->json('data'));
    }

    #[Test]
    public function a_quran_teacher_cannot_read_edit_withdraw_or_mark_arabic_work(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $arabic = $this->work('Arabic quiz', 'Arabic Language');
        $scoresUrl = $this->url("/assignments/{$arabic->id}/scores");

        // Not there for them (review F4): the same 404 untagged work gets, never a sentence naming Arabic.
        $this->getJson($this->url("/assignments/{$arabic->id}"))->assertNotFound();
        $this->putJson($this->url("/assignments/{$arabic->id}"), $this->body(['title' => 'Hijacked', 'subject' => 'Arabic Language']))->assertNotFound();
        $this->deleteJson($this->url("/assignments/{$arabic->id}"))->assertNotFound();
        $this->putJson($scoresUrl, ['scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 9]]])
            ->assertNotFound();

        $this->assertSame('Arabic quiz', $arabic->fresh()->title);
        $this->assertNull(ClassAssignment::query()->find($arabic->id)?->deleted_at);
        $this->assertSame(0, AssignmentScore::query()->count(), 'a refused mark writes nothing');
    }

    #[Test]
    public function untagged_work_is_invisible_to_a_limited_teacher_not_merely_refused(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $old = $this->work('Old work', null);

        $this->getJson($this->url("/assignments/{$old->id}"))->assertNotFound();
        $this->putJson($this->url("/assignments/{$old->id}/scores"), [
            'scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 5]],
        ])->assertNotFound();
        $this->deleteJson($this->url("/assignments/{$old->id}"))->assertNotFound();
    }

    #[Test]
    public function another_subjects_work_is_refused_exactly_as_untagged_work_is_status_and_body(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $arabic = $this->work('Arabic quiz', 'Arabic Language');
        $untagged = $this->work('Old work', null);

        $scores = ['scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 9]]];
        $edit = $this->body(['title' => 'Hijacked', 'subject' => "Qur'an"]);

        $calls = [
            'show' => fn (ClassAssignment $w) => $this->getJson($this->url("/assignments/{$w->id}")),
            'update' => fn (ClassAssignment $w) => $this->putJson($this->url("/assignments/{$w->id}"), $edit),
            'scores' => fn (ClassAssignment $w) => $this->putJson($this->url("/assignments/{$w->id}/scores"), $scores),
            'destroy' => fn (ClassAssignment $w) => $this->deleteJson($this->url("/assignments/{$w->id}")),
        ];

        // Both with debug on (the test default: the message is the exception's own) and off (production's).
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);

            foreach ($calls as $verb => $call) {
                $other = $call($arabic);
                $none = $call($untagged);

                $this->assertSame(404, $other->getStatusCode(), "{$verb}: another subject's work");
                $this->assertSame($none->getStatusCode(), $other->getStatusCode(), $verb);
                $this->assertSame($none->getContent(), $other->getContent(), "{$verb}: byte for byte, debug ".json_encode($debug));
                $this->assertStringNotContainsString('Arabic', $other->getContent(), $verb);
            }
        }

        $this->assertSame('Arabic quiz', $arabic->fresh()->title);
        $this->assertSame(0, AssignmentScore::query()->count());
    }

    #[Test]
    public function a_limited_teacher_must_name_a_subject_they_teach_when_setting_work(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $url = $this->url('/assignments');

        // Leaving it blank is not a way round the fence.
        $this->postJson($url, $this->body(['title' => 'Blank']))
            ->assertStatus(422)->assertJsonPath('data.subject.0', 'Choose the subject this work is for.');

        $this->postJson($url, $this->body(['title' => 'Arabic', 'subject' => 'Arabic Language']))->assertForbidden();
        $this->postJson($url, $this->body(['title' => 'Maths', 'subject' => 'Mathematics']))->assertForbidden();
        $this->postJson($url, $this->body(['title' => 'Islamic', 'subject' => 'Islamic Studies']))->assertForbidden();
        $this->assertSame(0, ClassAssignment::query()->count());

        $this->postJson($url, $this->body(['title' => 'Quran', 'subject' => "Qur'an"]))->assertCreated();
        $this->postJson($url, $this->body(['title' => 'Combined', 'subject' => 'Qur’an & Islamic Studies']))->assertCreated();
        $this->assertSame(2, ClassAssignment::query()->count());
    }

    #[Test]
    public function a_limited_teacher_cannot_move_their_own_work_into_another_subject_or_clear_its_subject(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $mine = $this->work('Recitation', "Qur'an");
        $url = $this->url("/assignments/{$mine->id}");

        $this->putJson($url, $this->body(['title' => 'Recitation', 'subject' => 'Arabic Language']))->assertForbidden();
        $this->putJson($url, $this->body(['title' => 'Recitation', 'subject' => null]))->assertStatus(422);
        $this->assertSame("Qur'an", $mine->fresh()->subject);

        // Editing the title without naming the subject keeps it, and works.
        $this->putJson($url, $this->body(['title' => 'Recitation 2']))->assertOk()->assertJsonPath('data.subject', "Qur'an");
    }

    #[Test]
    public function a_teacher_of_two_subjects_gets_work_in_both_and_the_dropdown_offers_only_their_own(): void
    {
        foreach (["Qur'an", 'Arabic Language', 'Islamic Studies', 'Mathematics'] as $name) {
            SchoolSubject::create(['masjid_id' => $this->school->id, 'name' => $name]);
        }
        $this->assign([GroupStaff::SUBJECT_QURAN, GroupStaff::SUBJECT_ARABIC]);
        $this->work('Quran work', "Qur'an");
        $this->work('Arabic work', 'Arabic Language');
        $this->work('Islamic work', 'Islamic Studies');

        $body = $this->getJson($this->url('/assignments'))->assertOk()->json();

        $this->assertEqualsCanonicalizing(['Quran work', 'Arabic work'], collect($body['data'])->pluck('title')->all());
        $this->assertEqualsCanonicalizing(["Qur'an", 'Arabic Language'], array_column($body['subjects'], 'name'));
        $this->assertNull($body['default_subject'], 'two subjects: the teacher chooses, the form does not guess');
        $this->assertSame(['quran', 'arabic'], $body['my_subjects']);
    }

    #[Test]
    public function a_single_subject_teachers_form_starts_on_their_subject(): void
    {
        foreach (["Qur'an", 'Arabic Language', 'Mathematics'] as $name) {
            SchoolSubject::create(['masjid_id' => $this->school->id, 'name' => $name]);
        }
        $this->assign([GroupStaff::SUBJECT_ARABIC]);

        $body = $this->getJson($this->url('/assignments'))->assertOk()->json();

        $this->assertSame('Arabic Language', $body['default_subject']);
        $this->assertSame(['Arabic Language'], array_column($body['subjects'], 'name'));
    }

    #[Test]
    public function a_childs_summary_and_marks_carry_only_the_subjects_the_teacher_teaches(): void
    {
        $quran = $this->work("Qur'an recitation", "Qur'an", 10);
        $arabic = $this->work('Arabic dictation', 'Arabic Language', 10);
        $this->mark($quran, 8);
        $this->mark($arabic, 2);

        $grades = fn () => $this->getJson($this->url("/members/{$this->student->id}/grades"))->assertOk()->json('data');

        // Qur'an teacher: the Qur'an mark only, and no trace of the Arabic one
        // in the totals, the subject blocks or the list.
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $q = $grades();
        $this->assertEquals(8, $q['summary']['points_earned']);
        $this->assertEquals(10, $q['summary']['points_possible']);
        $this->assertSame(1, $q['summary']['recorded']);
        $this->assertSame(["Qur'an"], array_column($q['summary']['by_subject'], 'subject'));
        $this->assertSame(["Qur'an recitation"], array_column(array_column($q['scores'], 'assignment'), 'title'));
        $this->assertStringNotContainsString('Arabic', json_encode($q));

        // The Arabic teacher's picture is the mirror image.
        GroupStaff::query()->where('group_id', $this->class->id)->update(['subjects' => json_encode([GroupStaff::SUBJECT_ARABIC])]);
        $a = $grades();
        $this->assertEquals(2, $a['summary']['points_earned']);
        $this->assertStringNotContainsString('Qur', json_encode($a));

        // Unrestricted: everything, pooled as always.
        GroupStaff::query()->where('group_id', $this->class->id)->update(['subjects' => null]);
        $all = $grades();
        $this->assertEquals(10, $all['summary']['points_earned']);
        $this->assertEquals(20, $all['summary']['points_possible']);
        $this->assertCount(2, $all['summary']['by_subject']);
    }

    #[Test]
    public function the_fence_reaches_levels_and_simple_marks_in_a_childs_summary_too(): void
    {
        $levels = ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => 'Arabic rubric',
            'points_possible' => 4, 'scale' => 'levels', 'subject' => 'Arabic Language', 'assigned_on' => now()->toDateString(),
        ]);
        $simple = ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => 'Arabic words',
            'points_possible' => 3, 'scale' => 'simple', 'subject' => 'Arabic Language', 'assigned_on' => now()->toDateString(),
        ]);
        $this->mark($levels, 4);
        $this->mark($simple, 3);
        $this->assign([GroupStaff::SUBJECT_QURAN]);

        $summary = $this->getJson($this->url("/members/{$this->student->id}/grades"))->assertOk()->json('data.summary');

        $this->assertSame(0, $summary['levels']['recorded']);
        $this->assertNull($summary['levels']['mean']);
        $this->assertSame(0, $summary['simple']['recorded']);
        $this->assertSame(0, $summary['recorded']);
    }

    #[Test]
    public function the_family_and_the_office_read_every_subject_regardless_of_the_teachers_fence(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $arabic = $this->work('Arabic dictation', 'Arabic Language', 10);
        $this->mark($arabic, 2);

        // The FAMILY endpoint has no fence: a parent reads their own child's
        // whole record, whichever teacher wrote it.
        $parent = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'login_email' => 'p-'.uniqid().'@test.local', 'login_enabled_at' => now(),
        ]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $this->student->contact_id,
            'confirmed_at' => now(), 'consent_granted_at' => now(), 'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        app(\App\Support\TenantContext::class)->forgetTenant();

        $this->withHeader('Authorization', 'Bearer '.$parent->refresh()->createFamilyToken()->plainTextToken)
            ->getJson("/api/family/masjids/{$this->school->id}/groups/{$this->class->id}/members/{$this->student->id}/grades")
            ->assertOk()
            ->assertJsonPath('data.summary.recorded', 1)
            ->assertJsonPath('data.scores.0.assignment.subject', 'Arabic Language');
    }

    #[Test]
    public function a_teacher_of_another_class_is_fenced_by_that_class_not_this_one(): void
    {
        // Limited in THIS class, unrestricted in the other: the fence is per
        // assignment (class, teacher), never a property of the person.
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $other = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4', 'slug' => 'g4',
        ]);
        $other->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'subjects' => null, 'assigned_at' => now(),
        ]);
        ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $other->id, 'title' => 'Arabic there',
            'points_possible' => 10, 'scale' => 'points', 'subject' => 'Arabic Language', 'assigned_on' => now()->toDateString(),
        ]);

        $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$other->id}/assignments")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url('/assignments'))->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_quran_teacher_cannot_refile_another_subjects_work_into_their_own_subject(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $arabic = $this->work('Arabic quiz', 'Arabic Language');
        $url = $this->url("/assignments/{$arabic->id}");

        // The subject it is BECOMING is one they teach, so the only thing between
        // them and Arabic's work is the fence on the work AS IT IS.
        $this->putJson($url, $this->body(['title' => 'Hijacked', 'subject' => "Qur'an", 'points_possible' => 99]))
            ->assertNotFound();
        // Naming no subject keeps the existing one, which is Arabic.
        $this->putJson($url, $this->body(['title' => 'Hijacked', 'points_possible' => 99]))->assertNotFound();

        $arabic->refresh();
        $this->assertSame('Arabic quiz', $arabic->title);
        $this->assertSame('Arabic Language', $arabic->subject);
        $this->assertSame(10, (int) $arabic->points_possible, 'a refused edit writes nothing');
    }

    #[Test]
    public function two_teachers_in_one_class_are_each_fenced_by_their_own_row_whichever_row_came_first(): void
    {
        $free = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $free->id, 'role' => 'teacher', 'is_default' => true]);

        foreach ([true, false] as $limitedFirst) {
            $class = Group::factory()->create([
                'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
                'name' => 'Order '.($limitedFirst ? 'A' : 'B'), 'slug' => 'order-'.($limitedFirst ? 'a' : 'b'),
            ]);
            $attach = fn (User $u, ?array $subjects) => $class->staff()->attach($u->id, [
                'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER,
                'subjects' => $subjects, 'assigned_at' => now(),
            ]);

            // One class, two staff rows: Qur'an only, and everything.
            if ($limitedFirst) {
                $attach($this->teacher, [GroupStaff::SUBJECT_QURAN]);
                $attach($free, null);
            } else {
                $attach($free, null);
                $attach($this->teacher, [GroupStaff::SUBJECT_QURAN]);
            }

            foreach ([["Qur'an", 'Qur an work'], ['Arabic Language', 'Arabic work']] as [$subject, $title]) {
                ClassAssignment::create([
                    'masjid_id' => $this->school->id, 'group_id' => $class->id, 'title' => $title,
                    'points_possible' => 10, 'scale' => 'points', 'subject' => $subject, 'assigned_on' => now()->toDateString(),
                ]);
            }

            $this->assertSame([GroupStaff::SUBJECT_QURAN], SubjectFence::assigned($class->id, $this->teacher->id));
            $this->assertNull(SubjectFence::assigned($class->id, $free->id), 'the other row is not this teacher\'s limit');

            $list = function (User $as) use ($class): array {
                \Illuminate\Support\Facades\Auth::forgetGuards();
                app(\App\Support\TenantContext::class)->forgetTenant();
                Sanctum::actingAs($as, ['staff']);

                return collect($this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$class->id}/assignments")
                    ->assertOk()->json('data'))->pluck('title')->all();
            };

            $this->assertSame(['Qur an work'], $list($this->teacher), 'the limited teacher reads only Qur\'an, whichever row is first');
            $this->assertEqualsCanonicalizing(['Qur an work', 'Arabic work'], $list($free), 'the unrestricted teacher is not locked to the other row\'s subjects');
        }
    }

    #[Test]
    public function a_limited_teachers_summary_says_it_counts_only_their_subjects_and_an_unrestricted_one_does_not(): void
    {
        $this->mark($this->work("Qur'an recitation", "Qur'an"), 8);
        $this->mark($this->work('Arabic dictation', 'Arabic Language'), 2);
        $grades = fn () => $this->getJson($this->url("/members/{$this->student->id}/grades"))->assertOk()->json('data');

        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $this->assertTrue($grades()['fenced'], 'the family sees Arabic too, so this teacher\'s figures are a subset and must say so');

        GroupStaff::query()->where('group_id', $this->class->id)->update(['subjects' => null]);
        $this->assertFalse($grades()['fenced']);
    }

    // ---- review F5 (2026-09-29): the class's weights are for a teacher of ALL subjects, and the office

    private const WEIGHTS = ['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10];

    /** @return array<string,int> the class's stored weights by type */
    private function storedWeights(): array
    {
        return ClassGradeWeight::query()->where('group_id', $this->class->id)->pluck('weight', 'assignment_type')->map(fn ($w) => (int) $w)->all();
    }

    #[Test]
    public function a_subject_limited_teacher_cannot_set_the_weights_and_nothing_is_written(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);

        $this->putJson($this->url('/grade-weights'), ['weights' => self::WEIGHTS])
            ->assertForbidden()
            ->assertJsonPath('message', "The class's weights decide how much each type of work counts in every subject's average, so only a teacher of all the subjects in this class, or the office, can change them.");

        $this->assertSame([], $this->storedWeights(), 'a refused set writes nothing');
    }

    #[Test]
    public function a_subject_limited_teacher_cannot_replace_or_clear_weights_that_are_already_set(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $this->weigh();
        $mine = $this->work("Qur'an project", "Qur'an");
        $arabic = $this->work('Arabic project', 'Arabic Language');
        $mine->update(['weight' => 20]);
        $arabic->update(['weight' => 60]);
        $before = $this->storedWeights();

        // Re-weighting what parents read for every subject: refused.
        $this->putJson($this->url('/grade-weights'), ['weights' => ['test' => 5, 'quiz' => 5, 'homework' => 80, 'classwork' => 5, 'other' => 5]])
            ->assertForbidden();
        // Clearing: refused whether or not another subject's work carries a weight of its own (it used to
        // be refused only while it did), and with only their own subject's overrides too.
        $this->putJson($this->url('/grade-weights'), ['clear' => true])->assertForbidden();
        $arabic->update(['weight' => null]);
        $this->putJson($this->url('/grade-weights'), ['clear' => true])->assertForbidden();

        $this->assertEquals($before, $this->storedWeights(), 'the weights are unchanged');
        $this->assertSame(20, $mine->fresh()->weight, 'and so is a piece of work\'s own weight');
    }

    #[Test]
    public function every_kind_of_limit_is_refused_and_only_an_unlimited_teacher_of_the_class_is_allowed(): void
    {
        // Two subjects is still a limit: it is the list, not the count, that fences a teacher.
        foreach ([[GroupStaff::SUBJECT_QURAN, GroupStaff::SUBJECT_ARABIC], [GroupStaff::SUBJECT_ISLAMIC_STUDIES]] as $subjects) {
            GroupStaff::query()->where('group_id', $this->class->id)->delete();
            $this->assign($subjects);
            $this->putJson($this->url('/grade-weights'), ['weights' => self::WEIGHTS])->assertForbidden();
        }
        $this->assertSame([], $this->storedWeights());

        // NULL and an empty list both mean "everything" (GroupStaff::teaches): allowed, set and cleared.
        foreach ([null, []] as $subjects) {
            GroupStaff::query()->where('group_id', $this->class->id)->delete();
            $this->assign($subjects);

            $this->putJson($this->url('/grade-weights'), ['weights' => self::WEIGHTS])
                ->assertOk()->assertJsonPath('data.weighting_enabled', true);
            $this->assertEquals(self::WEIGHTS, $this->storedWeights());

            $this->putJson($this->url('/grade-weights'), ['clear' => true])
                ->assertOk()->assertJsonPath('data.weighting_enabled', false);
            $this->assertSame([], $this->storedWeights());
        }
    }

    #[Test]
    public function in_one_class_the_limited_teacher_is_refused_and_the_all_subjects_teacher_is_not(): void
    {
        $this->assign([GroupStaff::SUBJECT_QURAN]);
        $free = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $free->id, 'role' => 'teacher', 'is_default' => true]);
        $this->class->staff()->attach($free->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'subjects' => null, 'assigned_at' => now(),
        ]);

        $this->putJson($this->url('/grade-weights'), ['weights' => self::WEIGHTS])->assertForbidden();
        $this->assertSame([], $this->storedWeights());

        \Illuminate\Support\Facades\Auth::forgetGuards();
        app(\App\Support\TenantContext::class)->forgetTenant();
        Sanctum::actingAs($free, ['staff']);

        $this->putJson($this->url('/grade-weights'), ['weights' => self::WEIGHTS])->assertOk();
        $this->assertEquals(self::WEIGHTS, $this->storedWeights());
        $this->assertSame($free->id, (int) ClassGradeWeight::query()->where('group_id', $this->class->id)->value('updated_by_user_id'));
    }

    #[Test]
    public function the_office_is_never_limited_on_the_weights_even_when_it_also_holds_a_limited_class_assignment(): void
    {
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        // An administrator who is ALSO on the class's staff with one subject: acting as the office they are not fenced.
        $this->class->staff()->attach($admin->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER,
            'subjects' => [GroupStaff::SUBJECT_QURAN], 'assigned_at' => now(),
        ]);
        $this->assign([GroupStaff::SUBJECT_QURAN]);

        $this->assertTrue(SubjectFence::mayWeighClass($admin, $this->class->id));
        $this->assertTrue(SubjectFence::mayWeighClass($super, $this->class->id));
        $this->assertFalse(SubjectFence::mayWeighClass($this->teacher, $this->class->id));

        // And the teacher controller's own verb lets the office through, called directly with a validated request:
        // the office's own route (AdminGradeWeightsTest) does not go through this gate, so this is the one place
        // that pins that the gate itself has never been a limit on anyone but a limited Teacher.
        foreach ([$admin, $super] as $office) {
            \Illuminate\Support\Facades\Auth::forgetGuards();
            app(\App\Support\TenantContext::class)->forgetTenant();
            app(\App\Support\TenantContext::class)->set($this->school->id);
            \Illuminate\Support\Facades\Auth::setUser($office);

            $request = \App\Http\Requests\Teacher\SaveGradeWeightsRequest::create('/grade-weights', 'PUT', ['weights' => self::WEIGHTS]);
            $request->setContainer(app())->setRedirector(app('redirect'))->setUserResolver(fn () => $office)->validateResolved();

            $response = app(\App\Http\Controllers\Teacher\GradebookController::class)
                ->saveWeights($request, $this->school->id, $this->class->id);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertEquals(self::WEIGHTS, $this->storedWeights());
            ClassGradeWeight::query()->where('group_id', $this->class->id)->delete();
        }
    }

    private function plan(string $day, ?string $subject): LessonPlan
    {
        return LessonPlan::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'session_date' => $day,
            'subject' => $subject, 'body' => 'Body.',
        ]);
    }

    private function work(string $title, ?string $subject, int $outOf = 10): ClassAssignment
    {
        return ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => $title,
            'points_possible' => $outOf, 'scale' => ClassAssignment::SCALE_POINTS, 'subject' => $subject,
            'assigned_on' => now()->toDateString(),
        ]);
    }

    private function mark(ClassAssignment $work, int|float $points): AssignmentScore
    {
        return AssignmentScore::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'class_assignment_id' => $work->id, 'group_membership_id' => $this->student->id,
            'status' => 'scored', 'points_earned' => $points,
        ]);
    }

    /** @param array<string,mixed> $over */
    private function body(array $over = []): array
    {
        return $over + [
            'title' => 'Work', 'scale' => 'points', 'points_possible' => 10, 'assigned_on' => now()->toDateString(),
        ];
    }

    private function weigh(): void
    {
        foreach (['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10] as $type => $weight) {
            ClassGradeWeight::create([
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
                'assignment_type' => $type, 'weight' => $weight,
            ]);
        }
    }

    private function assign(?array $subjects): void
    {
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id,
            'role' => GroupStaff::ROLE_TEACHER,
            'subjects' => $subjects,
            'assigned_at' => now(),
        ]);
    }

    private function url(string $path): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}".rtrim($path, '/');
    }
}
