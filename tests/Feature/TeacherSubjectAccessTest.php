<?php

namespace Tests\Feature;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
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

        // By id: rewrite and remove.
        $this->putJson($this->url("/lesson-plans/{$arabic->id}"), ['session_date' => $day, 'subject' => 'Arabic Language', 'body' => 'Hijacked.'])
            ->assertForbidden();
        $this->deleteJson($this->url("/lesson-plans/{$arabic->id}"))->assertForbidden();

        // Moving their own plan INTO Arabic is the same refusal.
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

        // The old address falls back to "the day's only plan", which is Arabic's:
        // it must be refused rather than silently rewritten under a Qur'an label.
        $this->putJson($this->url('/lesson-plans'), ['session_date' => $day, 'subject' => "Qur'an", 'body' => 'Overwritten.'])
            ->assertForbidden();
        $this->deleteJson($this->url('/lesson-plans?date='.$day))->assertForbidden();

        $this->assertSame('Body.', $arabic->fresh()->body);
        $this->assertNotNull(LessonPlan::query()->find($arabic->id));
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

        $this->getJson($this->url("/assignments/{$arabic->id}"))
            ->assertForbidden()->assertJsonPath('message', 'You do not teach Arabic Language in this class.');
        $this->putJson($this->url("/assignments/{$arabic->id}"), $this->body(['title' => 'Hijacked', 'subject' => 'Arabic Language']))->assertForbidden();
        $this->deleteJson($this->url("/assignments/{$arabic->id}"))->assertForbidden();
        $this->putJson($scoresUrl, ['scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 9]]])
            ->assertForbidden();

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
