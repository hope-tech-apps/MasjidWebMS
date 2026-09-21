<?php

namespace Tests\Feature;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\LessonPlan;
use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidUser;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\SchoolSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The weekly-school settings (App\Support\SchoolSettings), made for Burlington
 * Islamic Sunday School (org 18) and OFF for every other organisation.
 *
 * Owner, 2026-09-21: report card "Reuse Al-Razi's", behaviours "Keep it",
 * lesson plan drops "Differentiation section, STEM line, Exit ticket" and the
 * standards, gradebook "Teacher picks per assignment", setup changes "Only you
 * for now".
 *
 * Every test runs the same request against a school with the settings (BISS)
 * and one without (Al-Razi), because "Al-Razi does not change" is half of what
 * was asked and it is the half nobody would notice breaking.
 */
class SundaySchoolSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $biss;
    private Masjid $alrazi;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->biss = $this->makeMasjid('Burlington Islamic Sunday School');
        $this->alrazi = $this->makeMasjid('Al-Razi School');

        $this->biss->forceFill(['capability_overrides' => [
            SchoolSettings::REPORT_CARD_CORE_SUBJECTS => true,
            SchoolSettings::SHORT_LESSON_PLAN => true,
            SchoolSettings::SIMPLE_MARKING => true,
        ]])->save();
    }

    // ------------------------------------------------------------ who decides

    #[Test]
    public function the_settings_are_off_for_every_organisation_until_a_superadmin_decides(): void
    {
        foreach (Masjid::ORG_TYPES as $type) {
            $org = $this->makeMasjid("Fresh {$type}", $type);

            foreach ([SchoolSettings::REPORT_CARD_CORE_SUBJECTS, SchoolSettings::SHORT_LESSON_PLAN, SchoolSettings::SIMPLE_MARKING] as $key) {
                $this->assertFalse($org->hasCapability($key), "{$key} is on for a fresh {$type}");
            }
        }

        // Al-Razi's own admin payload names them, off.
        Sanctum::actingAs($this->admin($this->alrazi));
        $caps = $this->getJson("/api/admin/masjids/{$this->alrazi->id}")->assertOk()->json('data.capabilities');

        $this->assertFalse($caps['report_card_core_subjects']);
        $this->assertFalse($caps['short_lesson_plan']);
        $this->assertFalse($caps['simple_marking']);
    }

    /** Owner: "Only you for now." The organisation's own admin is refused and nothing moves. */
    #[Test]
    public function only_a_superadmin_can_change_them_and_every_change_is_on_the_ledger(): void
    {
        Sanctum::actingAs($this->admin($this->biss));

        $this->patch("/api/admin/masjids/{$this->biss->id}/capabilities/simple_marking", ['enabled' => '0'], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->patch("/api/admin/masjids/{$this->alrazi->id}/capabilities/short_lesson_plan", ['enabled' => '1'], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertTrue($this->biss->fresh()->hasCapability('simple_marking'));
        $this->assertFalse($this->alrazi->fresh()->hasCapability('short_lesson_plan'));
        $this->assertSame(0, MasjidCapabilityChange::count());

        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000001'])->fresh();
        Sanctum::actingAs($super);

        $this->patch("/api/admin/masjids/{$this->alrazi->id}/capabilities/short_lesson_plan", ['enabled' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.capabilities.short_lesson_plan', true);

        $row = MasjidCapabilityChange::sole();
        $this->assertSame('short_lesson_plan', $row->capability);
        $this->assertFalse($row->enabled_before);
        $this->assertTrue($row->enabled_after);
        $this->assertSame((int) $super->id, $row->actor_user_id);
    }

    // ------------------------------------------------------------ report card

    #[Test]
    public function a_biss_report_card_carries_only_the_three_core_subjects_with_al_razis_criteria(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->biss);
        $child = $this->enrol($this->biss, $class, 'Maryam', '3rd');
        Sanctum::actingAs($teacher, ['staff']);

        $card = $this->getJson($this->teacherUrl($this->biss, $class) . "/members/{$child->id}/report-card?term=1&school_year=2026-2027")
            ->assertOk()->json('data');

        $this->assertSame([
            ['subject' => 'Qur\'an', 'criteria' => ['Recitation', 'Memorisation', 'Tajweed']],
            ['subject' => 'Islamic Studies', 'criteria' => ['Understanding of concepts', 'Applies manners (adab) independently', 'Participation']],
            // No Grammar: that is a grade-band row, not a core one.
            ['subject' => 'Arabic Language', 'criteria' => ['Reading Accuracy', 'Writing', 'Vocabulary Use']],
        ], collect($card['subjects'])->map(fn ($s) => [
            'subject' => $s['subject'],
            'criteria' => collect($s['criteria'])->pluck('criterion')->all(),
        ])->all());

        // "Keep it": the behaviours are still reported beside the subjects.
        $this->assertSame([
            'Follows classroom expectations',
            'Completes work on time',
            'Works well with others',
            'Shows respect and good adab',
        ], collect($card['learning_behaviours'])->pluck('criterion')->all());
    }

    #[Test]
    public function an_al_razi_report_card_still_carries_the_grade_band_subjects(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->alrazi);
        $child = $this->enrol($this->alrazi, $class, 'Yusuf', '3rd');
        Sanctum::actingAs($teacher, ['staff']);

        $card = $this->getJson($this->teacherUrl($this->alrazi, $class) . "/members/{$child->id}/report-card?term=1&school_year=2026-2027")
            ->assertOk()->json('data');

        $subjects = collect($card['subjects']);
        $this->assertSame(
            ['Qur\'an', 'Islamic Studies', 'Arabic Language', 'English Language Arts', 'Mathematics', 'Science'],
            $subjects->pluck('subject')->all()
        );
        $this->assertContains('Grammar', collect($subjects->firstWhere('subject', 'Arabic Language')['criteria'])->pluck('criterion'));
        $this->assertCount(4, $card['learning_behaviours']);
    }

    // ------------------------------------------------------------ lesson plan

    #[Test]
    public function a_biss_lesson_plan_neither_shows_nor_requires_nor_stores_the_left_out_fields(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->biss);
        Sanctum::actingAs($teacher, ['staff']);
        $day = now()->addDay()->toDateString();

        // Activities alone is a complete plan: nothing hidden is required.
        $this->putJson($this->teacherUrl($this->biss, $class) . '/lesson-plans', [
            'session_date' => $day,
            'body' => 'Surah al-Fil, verses 1-3.',
            // An old client still sending the hidden fields: they are NOT written.
            'standard_code' => 'K.CC.A.1',
            'standard_description' => 'Count to 100.',
            'differentiation_support' => 'Hand over hand.',
            'cross_integration_stem' => 'Counting elephants.',
            'assessment_exit_ticket' => 'Recite verse 1.',
            // What stays is written as always.
            'cross_integration_subject' => 'Arabic: the letter fa',
            'cross_integration_islamic' => 'Trust in Allah',
            'reflection_worked' => 'Call and response.',
            'assessment_formative' => 'Listen to each child.',
        ])->assertOk()
            ->assertJsonPath('data.standard_code', null)
            ->assertJsonPath('data.cross_integration_islamic', 'Trust in Allah');

        $plan = LessonPlan::sole();
        foreach (SchoolSettings::HIDDEN_LESSON_PLAN_FIELDS as $field) {
            $this->assertNull($plan->{$field}, "{$field} was stored although this school's plan leaves it out");
        }
        $this->assertSame('Arabic: the letter fa', $plan->cross_integration_subject);
        $this->assertSame('Call and response.', $plan->reflection_worked);
        $this->assertSame('Listen to each child.', $plan->assessment_formative);

        $data = $this->getJson($this->teacherUrl($this->biss, $class) . "/lesson-plans?from={$day}&to={$day}")
            ->assertOk()->json('data');

        $this->assertSame(SchoolSettings::HIDDEN_LESSON_PLAN_FIELDS, $data['hidden_fields']);
        $this->assertSame([
            'standard_code', 'standard_description',
            'differentiation_support', 'differentiation_extension', 'differentiation_learning_styles',
            'differentiation_ell_aal', 'differentiation_sen',
            'cross_integration_stem', 'assessment_exit_ticket',
        ], $data['hidden_fields'], 'exactly what the owner named, and nothing he kept');
    }

    /** A plan written before the switch keeps what it had: hidden is not deleted. */
    #[Test]
    public function saving_a_biss_plan_leaves_a_hidden_field_it_already_had_alone(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->biss);
        Sanctum::actingAs($teacher, ['staff']);
        $day = now()->addDay()->toDateString();

        LessonPlan::create([
            'masjid_id' => $this->biss->id, 'group_id' => $class->id, 'session_date' => $day,
            'body' => 'Before the switch.', 'standard_code' => 'OLD.1',
        ]);

        $this->putJson($this->teacherUrl($this->biss, $class) . '/lesson-plans', [
            'session_date' => $day, 'body' => 'After the switch.',
        ])->assertOk();

        $plan = LessonPlan::sole();
        $this->assertSame('After the switch.', $plan->body);
        $this->assertSame('OLD.1', $plan->standard_code);
    }

    #[Test]
    public function an_al_razi_lesson_plan_is_unchanged(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->alrazi);
        Sanctum::actingAs($teacher, ['staff']);
        $day = now()->addDay()->toDateString();

        $this->putJson($this->teacherUrl($this->alrazi, $class) . '/lesson-plans', [
            'session_date' => $day, 'body' => 'Count to five.',
            'standard_code' => 'K.CC.A.1', 'differentiation_support' => 'Count to 3.',
            'cross_integration_stem' => 'Blocks.', 'assessment_exit_ticket' => 'Count aloud.',
        ])->assertOk()->assertJsonPath('data.standard_code', 'K.CC.A.1');

        $plan = LessonPlan::sole();
        $this->assertSame('Count to 3.', $plan->differentiation_support);
        $this->assertSame('Blocks.', $plan->cross_integration_stem);
        $this->assertSame('Count aloud.', $plan->assessment_exit_ticket);

        $data = $this->getJson($this->teacherUrl($this->alrazi, $class) . "/lesson-plans?from={$day}&to={$day}")
            ->assertOk()->json('data');
        $this->assertSame([], $data['hidden_fields']);
        $this->assertNull($data['meeting_weekdays'], 'no calendar: the week grid stays Monday to Friday');
    }

    /** The calendar already knows a Sunday school meets on Sundays; the week grid is told. */
    #[Test]
    public function the_lesson_week_follows_the_school_calendars_meeting_day(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->biss);

        SchoolYear::create([
            'masjid_id' => $this->biss->id, 'label' => '2026-27',
            'first_day' => '2026-10-11', 'last_day' => '2027-05-30',   // a Sunday
        ]);

        Sanctum::actingAs($teacher, ['staff']);

        $this->getJson($this->teacherUrl($this->biss, $class) . '/lesson-plans?from=2026-10-11&to=2026-10-17')
            ->assertOk()
            ->assertJsonPath('data.meeting_weekdays', [0]);
    }

    // ------------------------------------------------------------- gradebook

    #[Test]
    public function a_biss_teacher_picks_a_score_or_the_three_words_per_piece_of_work(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->biss);
        Sanctum::actingAs($teacher, ['staff']);
        $url = $this->teacherUrl($this->biss, $class);

        $index = $this->getJson("{$url}/assignments")->assertOk();
        $this->assertSame(['points', 'simple'], $index->json('scales'));
        $this->assertSame('points', $index->json('default_scale'));
        $this->assertSame(
            [['value' => 3, 'label' => 'Excellent'], ['value' => 2, 'label' => 'Good'], ['value' => 1, 'label' => 'Needs work']],
            $index->json('simple_marks')
        );

        // A score, as today.
        $this->postJson("{$url}/assignments", [
            'title' => 'Spelling', 'scale' => 'points', 'points_possible' => 10, 'assigned_on' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.scale', 'points')->assertJsonPath('data.points_possible', 10);

        // The three words: the maximum is implied, whatever the client sent.
        $this->postJson("{$url}/assignments", [
            'title' => 'Surah al-Fil', 'scale' => 'simple', 'points_possible' => 50, 'assigned_on' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.scale', 'simple')->assertJsonPath('data.points_possible', 3);

        // Al-Razi's rubric scale is not one of this school's choices.
        $this->postJson("{$url}/assignments", [
            'title' => 'Rubric', 'scale' => 'levels', 'assigned_on' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonStructure(['data' => ['scale']]);

        $this->assertSame(['points', 'simple'], ClassAssignment::orderBy('id')->pluck('scale')->all());
    }

    #[Test]
    public function al_razi_cannot_set_work_on_the_three_words_and_keeps_its_scales(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->alrazi);
        Sanctum::actingAs($teacher, ['staff']);
        $url = $this->teacherUrl($this->alrazi, $class);

        $index = $this->getJson("{$url}/assignments")->assertOk();
        $this->assertSame(['levels', 'points'], $index->json('scales'));
        $this->assertSame('levels', $index->json('default_scale'));

        $this->postJson("{$url}/assignments", [
            'title' => 'Surah al-Fil', 'scale' => 'simple', 'assigned_on' => now()->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonPath('data.scale.0', 'That is not a grading scale this gradebook understands.');

        $this->assertSame(0, ClassAssignment::count());

        // And an omitted scale still takes the configured default (levels).
        $this->postJson("{$url}/assignments", ['title' => 'Reading', 'assigned_on' => now()->toDateString()])
            ->assertCreated()->assertJsonPath('data.scale', 'levels')->assertJsonPath('data.points_possible', 4);
    }

    #[Test]
    public function a_three_word_mark_is_one_of_three_and_is_read_back_as_its_word(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->biss);
        $child = $this->enrol($this->biss, $class, 'Maryam', '3rd');
        Sanctum::actingAs($teacher, ['staff']);
        $url = $this->teacherUrl($this->biss, $class);

        $id = $this->simpleWork($url, 'Surah al-Fil');

        foreach ([0, 4, 2.5] as $notAMark) {
            $this->putJson("{$url}/assignments/{$id}/scores", [
                'scores' => [['membership_id' => $child->id, 'status' => 'scored', 'points_earned' => $notAMark]],
            ])->assertUnprocessable();
        }
        $this->assertSame(0, AssignmentScore::count());

        $students = $this->putJson("{$url}/assignments/{$id}/scores", [
            'scores' => [['membership_id' => $child->id, 'status' => 'scored', 'points_earned' => 2]],
        ])->assertOk()->json('data.students');

        $this->assertSame('Good', $students[0]['mark_label']);
    }

    /**
     * THE POINT: a parent is shown "Good", and no percentage exists anywhere in
     * the payload to be shown instead.
     */
    #[Test]
    public function a_parent_sees_the_word_and_no_percentage_is_made_from_it(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->biss);
        $child = $this->enrol($this->biss, $class, 'Maryam', '3rd');
        $parent = $this->guardianOf($this->biss, $class, $child);
        Sanctum::actingAs($teacher, ['staff']);
        $url = $this->teacherUrl($this->biss, $class);

        $good = $this->simpleWork($url, 'Surah al-Fil');
        $excellent = $this->simpleWork($url, 'Wudu steps');
        $skipped = $this->simpleWork($url, 'Homework');
        $this->score($url, $good, $child, 'scored', 2);
        $this->score($url, $excellent, $child, 'scored', 3);
        $this->score($url, $skipped, $child, 'missing');

        $teacherView = $this->getJson("{$url}/members/{$child->id}/grades")->assertOk()->json('data');

        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        $family = $this->withHeader('Authorization', 'Bearer ' . $parent->createFamilyToken()->plainTextToken)
            ->getJson("/api/family/masjids/{$this->biss->id}/groups/{$class->id}/members/{$child->id}/grades")
            ->assertOk()->json();

        $summary = $family['data']['summary'];

        // Nothing reached the points half: no numerator, no denominator, no %.
        $this->assertSame(0.0, (float) $summary['points_earned']);
        $this->assertSame(0.0, (float) $summary['points_possible']);
        $this->assertSame(0, $summary['points_counted']);
        // Nor the levels half: no mean was made of the words.
        $this->assertSame(0, $summary['levels']['recorded']);
        $this->assertNull($summary['levels']['mean']);

        $this->assertSame([
            'recorded' => 3,
            'counted' => 2,
            'missing' => 1,
            'distribution' => [
                ['value' => 3, 'label' => 'Excellent', 'count' => 1],
                ['value' => 2, 'label' => 'Good', 'count' => 1],
                ['value' => 1, 'label' => 'Needs work', 'count' => 0],
            ],
        ], $summary['simple']);
        $this->assertArrayNotHasKey('mean', $summary['simple']);

        $words = collect($family['data']['scores'])->mapWithKeys(fn ($s) => [$s['assignment']['title'] => $s['mark_label']]);
        $this->assertSame('Good', $words['Surah al-Fil']);
        $this->assertSame('Excellent', $words['Wudu steps']);
        $this->assertNull($words['Homework'], 'not handed in is a status, not a word');

        // The parent and the teacher read the same arithmetic.
        $this->assertSame($teacherView['summary'], $summary);
    }

    /** "Good" re-read as 2 points (or as "Approaching") would be a mark nobody gave. */
    #[Test]
    public function marked_three_word_work_cannot_be_moved_to_another_scale(): void
    {
        [$class, $teacher] = $this->classWithTeacher($this->biss);
        $child = $this->enrol($this->biss, $class, 'Maryam', '3rd');
        Sanctum::actingAs($teacher, ['staff']);
        $url = $this->teacherUrl($this->biss, $class);

        $id = $this->simpleWork($url, 'Surah al-Fil');
        $this->score($url, $id, $child, 'scored', 2);

        $this->putJson("{$url}/assignments/{$id}", [
            'title' => 'Surah al-Fil', 'scale' => 'points', 'points_possible' => 10, 'assigned_on' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonStructure(['data' => ['scale']]);

        $this->assertSame('simple', ClassAssignment::find($id)->scale);
        $this->assertSame(3, ClassAssignment::find($id)->points_possible);

        // Renaming it is still fine.
        $this->putJson("{$url}/assignments/{$id}", [
            'title' => 'Surah al-Fil (verses 1-3)', 'scale' => 'simple', 'assigned_on' => now()->toDateString(),
        ])->assertOk()->assertJsonPath('data.title', 'Surah al-Fil (verses 1-3)');
    }

    // ------------------------------------------------------------- migration

    #[Test]
    public function the_migration_switches_org_18_on_once_and_respects_a_later_decision(): void
    {
        // Its own row (names are unique); the setUp school is not used here.
        $biss = $this->makeMasjid('Burlington Islamic Sunday School (org 18)');
        $this->moveTo($biss, 18);
        DB::table('masjids')->where('id', 18)->update([
            'capability_overrides' => json_encode(['school_calendar' => true, 'simple_marking' => false]),
        ]);

        $this->migration()->up();

        $org = Masjid::find(18);
        $this->assertTrue($org->hasCapability('report_card_core_subjects'));
        $this->assertTrue($org->hasCapability('short_lesson_plan'));
        $this->assertFalse($org->hasCapability('simple_marking'), 'a SuperAdmin decision already on the row is left alone');
        $this->assertTrue($org->hasCapability('school_calendar'), 'other overrides are kept');

        $rows = MasjidCapabilityChange::where('masjid_id', 18)->orderBy('id')->get();
        $this->assertSame(['report_card_core_subjects', 'short_lesson_plan'], $rows->pluck('capability')->all());
        $this->assertTrue($rows->every(fn ($r) => $r->actor_user_id === null && $r->override_before === null
            && $r->enabled_before === false && $r->enabled_after === true));

        // Idempotent: a second run writes nothing.
        $this->migration()->up();
        $this->assertSame(2, MasjidCapabilityChange::where('masjid_id', 18)->count());

        // Al-Razi and every other row untouched.
        $this->assertFalse($this->alrazi->fresh()->hasCapability('report_card_core_subjects'));
    }

    #[Test]
    public function the_migration_does_nothing_when_org_18_is_not_the_sunday_school(): void
    {
        $other = $this->makeMasjid('Some Other Masjid', 'masjid');
        $this->moveTo($other, 18);

        $this->migration()->up();

        $this->assertNull(DB::table('masjids')->where('id', 18)->value('capability_overrides'));
        $this->assertSame(0, MasjidCapabilityChange::count());
    }

    // --------------------------------------------------------------- fixtures

    /** Named `makeMasjid` so TenantScopingCoverageTest recognises the two-tenant fixture. */
    private function makeMasjid(string $name, string $orgType = 'school'): Masjid
    {
        return Masjid::create([
            'name' => $name,
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => $orgType,
        ]);
    }

    private function admin(Masjid $masjid): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        return $user->fresh();
    }

    /** @return array{0: Group, 1: User} */
    private function classWithTeacher(Masjid $masjid): array
    {
        $teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $masjid->id, 'user_id' => $teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $class = Group::factory()->create([
            'masjid_id' => $masjid->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Class ' . uniqid(), 'slug' => 'class-' . uniqid(),
        ]);
        $class->staff()->attach($teacher->id, [
            'masjid_id' => $masjid->id,
            'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        return [$class, $teacher];
    }

    private function enrol(Masjid $masjid, Group $class, string $firstName, string $grade): GroupMembership
    {
        $child = Contact::factory()->create([
            'masjid_id' => $masjid->id, 'first_name' => $firstName, 'last_name' => 'Test',
        ]);

        return GroupMembership::create([
            'masjid_id' => $masjid->id, 'group_id' => $class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
            'grade_label' => $grade,
        ]);
    }

    private function guardianOf(Masjid $masjid, Group $class, GroupMembership $child): Contact
    {
        $parent = Contact::factory()->create([
            'masjid_id' => $masjid->id, 'first_name' => 'Huda', 'last_name' => 'Test',
            'login_email' => 'parent-' . uniqid() . '@example.test',
            'login_enabled_at' => now(),
        ]);

        GroupMembership::create([
            'masjid_id' => $masjid->id, 'group_id' => $class->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->contact_id,
            'confirmed_at' => now(),
            'consent_granted_at' => now(),
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        return $parent;
    }

    private function simpleWork(string $url, string $title): int
    {
        return (int) $this->postJson("{$url}/assignments", [
            'title' => $title, 'scale' => 'simple', 'assigned_on' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    private function score(string $url, int $assignment, GroupMembership $child, string $status, ?int $mark = null): void
    {
        $row = ['membership_id' => $child->id, 'status' => $status];
        if ($mark !== null) {
            $row['points_earned'] = $mark;
        }

        $this->putJson("{$url}/assignments/{$assignment}/scores", ['scores' => [$row]])->assertOk();
    }

    private function teacherUrl(Masjid $masjid, Group $class): string
    {
        return "/api/teacher/masjids/{$masjid->id}/groups/{$class->id}";
    }

    private function moveTo(Masjid $masjid, int $id): void
    {
        DB::table('masjids')->where('id', $masjid->id)->update(['id' => $id]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_21_120000_switch_on_sunday_school_settings_for_biss.php');
    }
}
