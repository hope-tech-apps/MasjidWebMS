<?php

namespace Tests\Feature;

use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolSubject;
use App\Models\User;
use App\Support\SubjectKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a teacher and the lesson-plan form see once the school's separated Qur'an,
 * Arabic and Islamic Studies weeks (Pre-K to Grade 2, Quarter 1) are loaded over
 * its July guide, through the importer exactly as production does it: the base
 * file, then the split file.
 *
 * The school's Focus Skill, Objective and Learning Outcome each land in the field
 * of the same name, nothing joined. The picker de-duplicates a standard by its
 * wording, and the school's plan repeats a code and focus in two weeks with a
 * different Objective each time, so the objective is part of that wording.
 */
class TeacherCurriculumSplitGuideTest extends TestCase
{
    use RefreshDatabase;

    private const COMBINED = "Qur\u{2019}an & Islamic Studies";

    private Masjid $school;

    private User $teacher;

    private Group $class;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->storage = sys_get_temp_dir() . '/curriculum-split-test-' . uniqid();
        mkdir($this->storage, 0777, true);
        $this->app->useStoragePath($this->storage);

        $this->school = new Masjid;
        $this->school->forceFill([
            'id' => 14, 'name' => 'Al-Razi Test ' . uniqid(), 'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ])->save();

        $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => 14, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);

        $this->class = Group::factory()->create(['masjid_id' => 14, 'kind' => Group::KIND_CLASS, 'name' => 'Kindergarten', 'slug' => 'kg']);

        foreach (['al-razi-pacing-2026-27.json', 'al-razi-qai-split-2026-27-q1.json'] as $file) {
            $this->assertSame(0, Artisan::call('curriculum:import', ['masjid' => 14, 'file' => base_path("database/curriculum/{$file}")]), Artisan::output());
        }

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->storage));

        parent::tearDown();
    }

    private function guide(array $query): array
    {
        return $this->getJson('/api/teacher/masjids/14/curriculum?' . http_build_query($query))->assertOk()->json('data');
    }

    /** @return list<array<string, mixed>> */
    private function search(array $query): array
    {
        return $this->getJson('/api/teacher/masjids/14/curriculum/standards?' . http_build_query($query))->assertOk()->json('data.matches');
    }

    // ------------------------------------------------------------------ subjects and weeks

    #[Test]
    public function pre_k_offers_each_separated_subject_once_beside_the_combined_column(): void
    {
        foreach (['Mathematics', 'Arabic Language', "Qur'an", 'Islamic Studies'] as $name) {
            SchoolSubject::create(['masjid_id' => 14, 'name' => $name]);
        }

        $subjects = $this->guide(['grade' => 'Pre-Kindergarten'])['subjects'];

        foreach (["Qur'an", 'Islamic Studies', 'Arabic Language', self::COMBINED] as $name) {
            $this->assertSame(1, count(array_keys($subjects, $name, true)), "{$name} once");
        }

        $keys = array_map(fn (string $s): string => SubjectKey::for($s), $subjects);
        $this->assertSame($keys, array_values(array_unique($keys)), 'the catalogue adds no duplicate by subject key');
    }

    #[Test]
    public function grade_3_is_unchanged_by_the_split(): void
    {
        $subjects = $this->guide(['grade' => 'Grade 3'])['subjects'];

        $this->assertContains(self::COMBINED, $subjects);
        $this->assertNotContains("Qur'an", $subjects);
        $this->assertNotContains('Islamic Studies', $subjects);
        $this->assertNotContains('Arabic Language', $subjects);

        $weeks = $this->guide(['grade' => 'Grade 3', 'subject' => self::COMBINED])['weeks'];
        $this->assertSame(range(1, 36), array_column($weeks, 'week_no'));
    }

    #[Test]
    public function the_weeks_lists_are_the_separated_eight_and_the_combined_remainder(): void
    {
        $quran = $this->guide(['grade' => 'Kindergarten', 'subject' => "Qur'an"])['weeks'];

        $this->assertSame(range(1, 8), array_column($quran, 'week_no'));
        $this->assertSame('Memorize Surah Al-Ikhlāṣ', $quran[3]['objective']);
        $this->assertSame('Memorization', $quran[3]['focus']);
        $this->assertSame('K.QUR.MEM.1', $quran[3]['standard_code']);

        $combined = $this->guide(['grade' => 'Grade 1', 'subject' => self::COMBINED])['weeks'];
        $this->assertSame(range(9, 36), array_column($combined, 'week_no'));
        $this->assertArrayNotHasKey('objective', $combined[0], 'a July row has no objective key');
    }

    // ------------------------------------------------------------------ the prefill cell

    #[Test]
    public function the_kindergarten_quran_week_four_cell_is_the_schools_own_words(): void
    {
        $cell = $this->guide(['grade' => 'Kindergarten', 'subject' => "Qur'an", 'week' => 4])['cell'];

        $this->assertSame('K.QUR.MEM.1', $cell['standard_code']);
        $this->assertSame('Memorize Surah Al-Ikhlāṣ', $cell['objective']);
        $this->assertSame('Recite independently', $cell['learning_outcome']);
        $this->assertNull($cell['assessment_formative']);
        $this->assertSame(4, $cell['curriculum_week_no']);
        $this->assertSame('Al-Razi Detailed Pacing Plan, Pre-K to Grade 2, Quarter 1 (school document of 2026-09-07)', $cell['prefill_source']);

        $siblings = array_column($cell['siblings'], 'subject');
        $this->assertContains('Arabic Language', $siblings);
        $this->assertContains('Islamic Studies', $siblings);
        $this->assertNotContains(self::COMBINED, $siblings, 'week 4 is separated; the combined cell is gone');
    }

    #[Test]
    public function a_july_cell_answers_as_it_always_did_with_no_learning_outcome_key(): void
    {
        $cell = $this->guide(['grade' => 'Grade 1', 'subject' => 'English Language Arts', 'week' => 3])['cell'];

        $this->assertSame($cell['objective'], CurriculumWeek::query()
            ->where('grade_label', 'Grade 1')->where('subject', 'English Language Arts')->where('week_no', 3)->value('focus'));
        $this->assertArrayNotHasKey('learning_outcome', $cell);
        $this->assertSame(
            ['standard_code', 'objective', 'assessment_formative', 'curriculum_week_no', 'subject', 'grade_label', 'prefill_source', 'siblings'],
            array_keys($cell)
        );
    }

    // ------------------------------------------------------------------ standards search

    #[Test]
    public function a_code_the_plan_repeats_offers_each_objective_and_names_its_week(): void
    {
        $all = $this->search(['q' => 'K.QUR.MEM.1', 'grade' => 'Kindergarten', 'subject' => "Qur'an"]);

        // "PK.QUR.MEM.1" ends with the typed "K.QUR.MEM.1", so the Pre-K row is offered too, below the
        // in-scope rows; the plan's own two weeks are the ones that carry the flag.
        $this->assertSame([true, true, false], array_column($all, 'in_scope'));
        $matches = array_values(array_filter($all, fn (array $m): bool => $m['in_scope']));

        $this->assertCount(2, $matches);
        $this->assertSame([[4], [7]], array_column($matches, 'weeks'));
        $this->assertCount(2, array_unique(array_column($matches, 'objective')));
        $this->assertSame('Memorize Surah Al-Ikhlāṣ', $matches[0]['objective']);
        $this->assertSame('Recite independently', $matches[0]['learning_outcome']);
        $this->assertSame('Memorization', $matches[0]['focus']);
    }

    #[Test]
    public function the_schools_objective_words_are_searchable(): void
    {
        $matches = $this->search(['q' => 'ikhlas', 'grade' => 'Kindergarten', 'subject' => "Qur'an"]);

        $this->assertNotEmpty($matches);
        $first = $matches[0];
        $this->assertTrue($first['in_scope']);
        $this->assertSame([4], $first['weeks']);
        $this->assertSame('Memorize Surah Al-Ikhlāṣ', $first['objective']);
    }

    #[Test]
    public function arabic_now_has_standards_and_repeated_alphabet_weeks_are_two_suggestions(): void
    {
        $matches = $this->search(['q' => 'PK.AAL.ALPH.1', 'grade' => 'Pre-Kindergarten', 'subject' => 'Arabic Language']);

        $this->assertSame([[3], [6]], array_column($matches, 'weeks'));
        $this->assertSame('Arabic Language', $matches[0]['subject']);
        $this->assertTrue($matches[0]['in_scope']);
    }

    #[Test]
    public function a_july_standard_keeps_its_exact_payload_with_no_new_keys(): void
    {
        $matches = $this->search(['q' => 'NC.3.NF.1']);

        $this->assertNotEmpty($matches);
        foreach ($matches as $m) {
            $this->assertArrayNotHasKey('objective', $m);
            $this->assertArrayNotHasKey('learning_outcome', $m);
        }
    }

    // ------------------------------------------------------------------ the subject fence

    #[Test]
    public function a_limited_teacher_sees_only_their_subjects_and_the_combined_column(): void
    {
        foreach (['quran' => 'Qur\'an and the combined column', 'arabic' => 'Arabic'] as $subject => $label) {
            $this->class->staff()->detach($this->teacher->id);
            $this->class->staff()->attach($this->teacher->id, [
                'masjid_id' => 14, 'role' => GroupStaff::ROLE_TEACHER, 'subjects' => [$subject], 'assigned_at' => now(),
            ]);

            $subjects = $this->guide(['grade' => 'Pre-Kindergarten', 'group_id' => $this->class->id])['subjects'];

            if ($subject === 'quran') {
                $this->assertContains("Qur'an", $subjects, $label);
                $this->assertContains(self::COMBINED, $subjects, $label);
                $this->assertNotContains('Arabic Language', $subjects, $label);
                $this->assertNotContains('Islamic Studies', $subjects, $label);
            } else {
                $this->assertContains('Arabic Language', $subjects, $label);
                $this->assertNotContains("Qur'an", $subjects, $label);

                $weeks = $this->guide(['grade' => 'Pre-Kindergarten', 'subject' => 'Arabic Language', 'group_id' => $this->class->id])['weeks'];
                $this->assertSame(range(1, 8), array_column($weeks, 'week_no'));
            }
        }
    }

    // ------------------------------------------------------------------ the gradebook's standard check

    #[Test]
    public function a_pick_from_the_separated_guide_saves_and_an_invented_code_is_refused(): void
    {
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => 14, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
        $child = Contact::factory()->create(['masjid_id' => 14, 'first_name' => 'Amina']);
        GroupMembership::create([
            'masjid_id' => 14, 'group_id' => $this->class->id, 'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER, 'grade_label' => 'KG',
        ]);

        $url = "/api/teacher/masjids/14/groups/{$this->class->id}/assignments";
        $body = ['title' => 'Al-Ikhlas', 'scale' => 'points', 'points_possible' => 10, 'assigned_on' => now()->toDateString()];

        $this->postJson($url, $body + ['standard_code' => 'K.QUR.MEM.1', 'curriculum_focus' => 'Memorization', 'curriculum_week_no' => 7])
            ->assertCreated()
            ->assertJsonPath('data.standard_code', 'K.QUR.MEM.1')
            ->assertJsonPath('data.curriculum_week_no', 7);

        $this->postJson($url, $body + ['standard_code' => 'K.QUR.MEM.9', 'curriculum_focus' => 'Memorization', 'curriculum_week_no' => 7])
            ->assertStatus(422);

        $this->assertSame(1, ClassAssignment::query()->count());
    }
}
