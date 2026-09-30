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

        // The surah and the specifics live in the Objective, so a sibling carries it.
        $byOrder = array_column($cell['siblings'], null, 'subject');
        $this->assertSame('Learn 4 colors', $byOrder['Arabic Language']['objective']);
        $this->assertSame('Respect others', $byOrder['Islamic Studies']['objective']);
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

        // A sibling from the base guide is exactly {subject, focus}: no objective key at all.
        $july = array_filter($cell['siblings'], fn (array $s): bool => in_array($s['subject'], ['Mathematics', 'Science', 'Social Studies', 'Healthful Living'], true));
        $this->assertCount(4, $july);
        foreach ($july as $s) {
            $this->assertSame(['subject', 'focus'], array_keys($s), $s['subject']);
        }
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

    // ------------------------------------------------------------------ weeks past the split

    #[Test]
    public function a_separated_subject_asked_for_a_week_past_the_split_gets_the_combined_line_labelled_as_such(): void
    {
        $rowsBefore = CurriculumWeek::query()->count();
        $combined = CurriculumWeek::query()->where('grade_label', 'Grade 1')->where('subject', self::COMBINED)->where('week_no', 12)->firstOrFail();

        foreach (["Qur'an", 'Islamic Studies', 'Arabic Language'] as $subject) {
            $cell = $this->guide(['grade' => 'Grade 1', 'subject' => $subject, 'week' => 12])['cell'];

            $this->assertNotNull($cell, $subject);
            $this->assertTrue($cell['from_combined_guide'], $subject);
            $this->assertSame(self::COMBINED, $cell['guide_subject'], $subject);
            $this->assertSame(self::COMBINED, $cell['subject'], "{$subject}: the row is the combined one, never a split row");
            $this->assertSame($combined->focus, $cell['objective'], $subject);
            $this->assertSame($combined->assessment_note, $cell['assessment_formative'], $subject);
            $this->assertSame(12, $cell['curriculum_week_no'], $subject);
            $this->assertArrayNotHasKey('learning_outcome', $cell, $subject);

            $siblings = array_column($cell['siblings'], 'subject');
            $this->assertNotContains(self::COMBINED, $siblings, "{$subject}: the combined line is the cell, not its own sibling");
            $this->assertContains('Mathematics', $siblings, $subject);
        }

        // Week 9 is the first week the split has no row for; week 8 is the last it has.
        $this->assertTrue($this->guide(['grade' => 'Kindergarten', 'subject' => "Qur'an", 'week' => 9])['cell']['from_combined_guide']);
        $eight = $this->guide(['grade' => 'Kindergarten', 'subject' => "Qur'an", 'week' => 8])['cell'];
        $this->assertArrayNotHasKey('from_combined_guide', $eight);
        $this->assertSame("Qur'an", $eight['subject']);

        $this->assertSame($rowsBefore, CurriculumWeek::query()->count(), 'no row was invented');
    }

    #[Test]
    public function other_subjects_and_weeks_the_guide_lacks_still_answer_nothing(): void
    {
        $this->assertNull($this->guide(['grade' => 'Grade 1', 'subject' => 'Mathematics', 'week' => 99])['cell']);
        $this->assertNull($this->guide(['grade' => 'Grade 1', 'subject' => "Qur'an", 'week' => 99])['cell'], 'no combined line that week either');

        // A July cell keeps the exact shape it always had: no label keys.
        $july = $this->guide(['grade' => 'Grade 1', 'subject' => self::COMBINED, 'week' => 12])['cell'];
        $this->assertArrayNotHasKey('from_combined_guide', $july);
        $this->assertArrayNotHasKey('guide_subject', $july);
    }

    #[Test]
    public function grades_the_split_does_not_cover_get_no_combined_fallback_for_any_separated_subject(): void
    {
        // Grades 3-5 have only the combined column: the school never separated them, so
        // asking for Arabic (which has no column there at all), Qur'an or Islamic Studies
        // gets no prefill, in a week the split covers elsewhere (3) and in one it does not (12).
        foreach (['Grade 3', 'Grade 4', 'Grade 5'] as $grade) {
            foreach (['Arabic', 'Arabic Language', "Qur'an", 'Islamic Studies'] as $subject) {
                foreach ([3, 12] as $week) {
                    $this->assertNull(
                        $this->guide(['grade' => $grade, 'subject' => $subject, 'week' => $week])['cell'],
                        "{$grade} {$subject} week {$week}"
                    );
                }
            }
        }

        // The combined column itself is still read as it always was.
        $this->assertNotNull($this->guide(['grade' => 'Grade 4', 'subject' => self::COMBINED, 'week' => 3])['cell']);

        // Pre-K to Grade 2 keep the fallback.
        $this->assertTrue($this->guide(['grade' => 'Grade 2', 'subject' => 'Arabic Language', 'week' => 12])['cell']['from_combined_guide']);
    }

    // ------------------------------------------------------------------ the fence on the guide reads

    private function limitTo(array $subjects): void
    {
        $this->class->staff()->detach($this->teacher->id);
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => 14, 'role' => GroupStaff::ROLE_TEACHER, 'subjects' => $subjects, 'assigned_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function searchSubjects(string $q, string $grade = 'Pre-Kindergarten'): array
    {
        $matches = $this->search(['q' => $q, 'grade' => $grade, 'group_id' => $this->class->id]);

        return array_values(array_unique(array_column($matches, 'subject')));
    }

    #[Test]
    public function a_quran_only_teacher_reads_quran_and_the_combined_column_and_nothing_else(): void
    {
        $this->limitTo(['quran']);
        $g = ['grade' => 'Pre-Kindergarten', 'group_id' => $this->class->id];

        // The standards search: the Qur'an codes answer, the Arabic and Islamic Studies codes do not.
        $this->assertSame(["Qur'an"], $this->searchSubjects('PK.QUR'));
        $this->assertSame([], $this->searchSubjects('PK.AAL'));
        $this->assertSame([], $this->searchSubjects('PK.IS'));
        $this->assertSame([self::COMBINED], $this->searchSubjects('Bismillah'));

        // The weeks and the cell.
        $this->assertSame(range(1, 8), array_column($this->guide($g + ['subject' => "Qur'an"])['weeks'], 'week_no'));
        $this->assertSame(range(9, 36), array_column($this->guide($g + ['subject' => self::COMBINED])['weeks'], 'week_no'));

        foreach (['Arabic Language', 'Islamic Studies'] as $refused) {
            $payload = $this->guide($g + ['subject' => $refused, 'week' => 3]);
            $this->assertSame([], $payload['weeks'], "{$refused}: no weeks");
            $this->assertNull($payload['cell'], "{$refused}: no cell");
        }

        // The siblings of a Qur'an cell are fenced only where a staff subject covers them: neither
        // Arabic Language nor Islamic Studies reaches the integration boxes, while the subjects no
        // staff subject covers (Mathematics, Science...) still do.
        $cell = $this->guide($g + ['subject' => "Qur'an", 'week' => 4])['cell'];
        $this->assertSame("Qur'an", $cell['subject']);
        $siblings = array_column($cell['siblings'], 'subject');
        $this->assertNotContains('Arabic Language', $siblings);
        $this->assertNotContains('Islamic Studies', $siblings);
        $this->assertContains('Mathematics', $siblings);

        // A week past the split: the combined line, which this teacher teaches.
        $late = $this->guide($g + ['subject' => "Qur'an", 'week' => 12])['cell'];
        $this->assertTrue($late['from_combined_guide']);
        $this->assertNotContains('Arabic Language', array_column($late['siblings'], 'subject'));
    }

    #[Test]
    public function an_islamic_studies_only_teacher_reads_islamic_studies_and_the_combined_column_and_nothing_else(): void
    {
        $this->limitTo(['islamic_studies']);
        $g = ['grade' => 'Pre-Kindergarten', 'group_id' => $this->class->id];

        $this->assertSame(['Islamic Studies'], $this->searchSubjects('PK.IS'));
        $this->assertSame([], $this->searchSubjects('PK.QUR'));
        $this->assertSame([], $this->searchSubjects('PK.AAL'));
        $this->assertSame([self::COMBINED], $this->searchSubjects('Bismillah'));

        $this->assertSame(range(1, 8), array_column($this->guide($g + ['subject' => 'Islamic Studies'])['weeks'], 'week_no'));
        $this->assertSame(range(9, 36), array_column($this->guide($g + ['subject' => self::COMBINED])['weeks'], 'week_no'));

        foreach (["Qur'an", 'Arabic Language'] as $refused) {
            $payload = $this->guide($g + ['subject' => $refused, 'week' => 3]);
            $this->assertSame([], $payload['weeks'], "{$refused}: no weeks");
            $this->assertNull($payload['cell'], "{$refused}: no cell");
        }

        $cell = $this->guide($g + ['subject' => 'Islamic Studies', 'week' => 4])['cell'];
        $this->assertSame('Respect others', $cell['objective']);
        $siblings = array_column($cell['siblings'], 'subject');
        $this->assertNotContains("Qur'an", $siblings);
        $this->assertNotContains('Arabic Language', $siblings);
        $this->assertContains('Mathematics', $siblings);
    }

    #[Test]
    public function an_arabic_only_teacher_reads_arabic_language_and_nothing_else(): void
    {
        $this->limitTo(['arabic']);
        $g = ['grade' => 'Pre-Kindergarten', 'group_id' => $this->class->id];

        $this->assertSame(['Arabic Language'], $this->searchSubjects('PK.AAL'));
        $this->assertSame([], $this->searchSubjects('PK.QUR'));
        $this->assertSame([], $this->searchSubjects('PK.IS'));
        $this->assertSame([], $this->searchSubjects('Bismillah'), 'the combined column is not Arabic');

        $this->assertSame(range(1, 8), array_column($this->guide($g + ['subject' => 'Arabic Language'])['weeks'], 'week_no'));

        foreach ([["Qur'an", 3], ['Islamic Studies', 3], [self::COMBINED, 12]] as [$refused, $week]) {
            $payload = $this->guide($g + ['subject' => $refused, 'week' => $week]);
            $this->assertSame([], $payload['weeks'], "{$refused}: no weeks");
            $this->assertNull($payload['cell'], "{$refused}: no cell");
        }

        $cell = $this->guide($g + ['subject' => 'Arabic Language', 'week' => 4])['cell'];
        $this->assertSame('Learn 3 colors', $cell['objective']);
        $siblings = array_column($cell['siblings'], 'subject');
        $this->assertNotContains("Qur'an", $siblings);
        $this->assertNotContains('Islamic Studies', $siblings);
        $this->assertContains('Mathematics', $siblings, 'a subject no staff subject covers is still an integration line');

        // Week 12: the combined line is not this teacher's to read, so there is no fallback.
        $this->assertNull($this->guide($g + ['subject' => 'Arabic Language', 'week' => 12])['cell']);
    }

    #[Test]
    public function an_unlimited_teacher_and_a_request_without_a_class_are_not_fenced(): void
    {
        $this->assertContains('Arabic Language', $this->searchSubjects('PK.AAL'), 'no subjects listed means all');

        $this->limitTo(['quran']);
        $matches = $this->search(['q' => 'PK.AAL', 'grade' => 'Pre-Kindergarten']);
        $this->assertNotEmpty($matches, 'no group_id: the search is the whole guide, as it always was');

        $cell = $this->guide(['grade' => 'Pre-Kindergarten', 'subject' => "Qur'an", 'week' => 4])['cell'];
        $this->assertContains('Arabic Language', array_column($cell['siblings'], 'subject'));
    }

    #[Test]
    public function in_scope_compares_subjects_by_key_so_the_curly_apostrophe_still_ranks_the_quran_rows_first(): void
    {
        $curly = "Qur\u{2019}an";
        $matches = $this->search(['q' => 'K.QUR.MEM.1', 'grade' => 'Kindergarten', 'subject' => $curly]);

        $this->assertSame([true, true, false], array_column($matches, 'in_scope'));
        $this->assertSame([[4], [7]], array_column(array_slice($matches, 0, 2), 'weeks'));
    }
}
