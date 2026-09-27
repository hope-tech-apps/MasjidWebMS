<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The standards search against Al-Razi's real pacing guide, as committed in
 * database/curriculum. Three reviews of the matcher each found words a teacher
 * would type that the fixture in TeacherCurriculumStandardsTest never held —
 * transliterations spelled two ways, word families, "1,000" beside "1000" —
 * so what they found is pinned here, on the data the teachers actually search.
 */
class TeacherCurriculumRealGuideTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;

    private const QS = 'Qur’an & Islamic Studies';

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
            'name' => 'Al-Razi Test ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $guide = json_decode(
            (string) file_get_contents(base_path('database/curriculum/al-razi-pacing-2026-27.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $now = now();
        $rows = array_map(fn (array $r): array => [
            'masjid_id' => $this->school->id,
            'grade_label' => $r['grade_label'],
            'subject' => $r['subject'],
            'week_no' => $r['week_no'],
            'quarter' => $r['quarter'] ?? null,
            'focus' => $r['focus'],
            'standard_code' => $r['standard_code'] ?? null,
            'assessment_note' => $r['assessment_note'] ?? null,
            'source_label' => $guide['source_label'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $guide['rows']);

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('curriculum_weeks')->insert($chunk);
        }

        Sanctum::actingAs($teacher, ['staff']);
    }

    #[Test]
    public function the_premise_the_whole_guide_is_loaded(): void
    {
        $this->assertSame(1512, DB::table('curriculum_weeks')->where('masjid_id', $this->school->id)->count());
    }

    #[Test]
    public function word_families_meet_their_own_forms_on_the_teachers_own_grade(): void
    {
        // [typed, grade, subject, a fragment the FIRST suggestion's focus must hold]
        $cases = [
            ['counting', 'Kindergarten', 'Mathematics', 'count'],
            ['addition', 'Grade 1', 'Mathematics', 'add'],
            ['subtraction', 'Grade 1', 'Mathematics', 'subtract'],
            ['multiplication', 'Grade 3', 'Mathematics', 'multipl'],
            ['fluency', 'Grade 2', 'English Language Arts', 'fluen'],
            ['rhyming', 'Kindergarten', 'English Language Arts', 'rhym'],
            ['composing', 'Kindergarten', 'Mathematics', 'compos'],
            ['revising', 'Grade 2', 'English Language Arts', 'revis'],
            ['investigate', 'Grade 4', 'Science', 'investigat'],
            ['predict', 'Pre-Kindergarten', 'Science', 'predict'],
            ['plants', 'Grade 1', 'Science', 'plant'],
        ];

        foreach ($cases as [$q, $grade, $subject, $fragment]) {
            $first = $this->search($q, $grade, $subject)[0] ?? null;

            $this->assertNotNull($first, "{$q}: nothing found");
            $this->assertTrue($first['in_scope'], "{$q}: first suggestion is not {$grade} {$subject}");
            $this->assertStringContainsStringIgnoringCase($fragment, $first['focus'], "{$q}: {$first['focus']}");
        }
    }

    #[Test]
    public function the_guides_two_spellings_of_a_term_meet_in_both_directions(): void
    {
        $cases = [
            ['hifz', 'Grade 5', 'ḥifẓ'],
            ['tajwid', 'Grade 1', 'tajweed'],
            ['tajweed', 'Grade 3', 'tajwid'],
            ['Al-Fatihah', 'Grade 4', 'fatiha'],
            ['fatiha', 'Grade 1', 'fatihah'],
            ['baa', 'Pre-Kindergarten', 'bā'],
        ];

        foreach ($cases as [$q, $grade, $fragment]) {
            $first = $this->search($q, $grade, self::QS)[0] ?? null;

            $this->assertNotNull($first, "{$q}: nothing found");
            $this->assertTrue($first['in_scope'], "{$q}: first suggestion is not {$grade}");
            $this->assertStringContainsStringIgnoringCase($fragment, $first['focus'], "{$q}: {$first['focus']}");
        }

        $first = $this->search('taharah', 'Pre-Kindergarten', 'Healthful Living')[0] ?? null;
        $this->assertTrue($first['in_scope'] ?? false, 'taharah finds the Pre-K ṭahāra weeks');
    }

    #[Test]
    public function one_thousand_is_one_thousand_with_or_without_a_comma(): void
    {
        $first = $this->search('within 1000', 'Grade 3', 'Mathematics')[0] ?? null;
        $this->assertTrue($first['in_scope'] ?? false, 'Grade 3 writes "1,000"');
        $this->assertStringContainsString('1,000', $first['focus']);

        $first = $this->search('1,000', 'Grade 2', 'Mathematics')[0] ?? null;
        $this->assertTrue($first['in_scope'] ?? false, 'Grade 2 writes "1000"');
        $this->assertStringContainsString('1000', $first['focus']);
    }

    #[Test]
    public function unrelated_words_that_merely_share_letters_do_not_meet(): void
    {
        // [typed, a word no suggestion may carry unless it also carries the typed topic]
        $cases = [
            ['plants', 'plan ', 'plant'],
            ['partition', 'parts', 'artition'],
            ['plane', 'plan ', 'plane'],
            ['seed', 'sides', 'seed'],
            ['fil', 'feeling', 'fil'],
            ['notation', 'not fair', 'notation'],
            ['predict', 'names', 'predict'],
        ];

        foreach ($cases as [$q, $wrong, $right]) {
            foreach ($this->search($q) as $m) {
                $focus = mb_strtolower($m['focus'] . ' ');
                if (str_contains($focus, $wrong)) {
                    $this->assertStringContainsString($right, $focus, "{$q} offered: {$m['focus']}");
                }
            }
        }

        $this->assertSame([], $this->search('students'), '"Studies" is a subject name, not a topic');
    }

    #[Test]
    public function the_forms_own_code_and_topic_rows_lead(): void
    {
        $codes = array_column($this->search('3.G.1', 'Grade 3', 'Mathematics'), 'standard_code');
        $this->assertSame('NC.3.G.1', $codes[0] ?? null);

        $first = $this->search('NC', 'Grade 4', 'Social Studies')[0] ?? null;
        $this->assertSame(['Grade 4', 'Social Studies'], [$first['grade_label'] ?? null, $first['subject'] ?? null]);

        // "quran" names the subject of every week; the weeks that cite the
        // Qur'an in their own words come first.
        $first = $this->search('quran', 'Grade 5', self::QS)[0] ?? null;
        $this->assertStringContainsStringIgnoringCase('qur', $first['focus'] ?? '');
    }

    /** @return array<int, array<string, mixed>> */
    private function search(string $q, string $grade = '', string $subject = ''): array
    {
        $query = array_filter(['q' => $q, 'grade' => $grade, 'subject' => $subject], fn ($v) => $v !== '');

        return $this->getJson(
            "/api/teacher/masjids/{$this->school->id}/curriculum/standards?" . http_build_query($query)
        )->assertOk()->json('data.matches');
    }
}
