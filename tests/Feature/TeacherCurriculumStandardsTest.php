<?php

namespace Tests\Feature;

use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Type-to-find over the school's pacing guide, for the lesson plan's Standard
 * field. Al-Razi's teachers reported retyping standards the guide already had.
 *
 * What is pinned: a code is found however it is spelled; words find a standard
 * only from the start of a word; the form's grade and subject rank first
 * without hiding the rest; and one school never sees another's guide.
 */
class TeacherCurriculumStandardsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Masjid $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->newSchool('Al-Razi Test');
        $this->otherSchool = $this->newSchool('Other School');

        $teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);
        $class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => '3rd Grade', 'slug' => '3rd-grade',
        ]);
        $class->staff()->attach($teacher->id, [
            'masjid_id' => $this->school->id,
            'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $this->guide($this->school->id, [
            ['Grade 3', 'Mathematics', 20, 'NC.3.NF.1', 'Understand a fraction as a part of a whole'],
            ['Grade 3', 'Mathematics', 21, 'NC.3.NF.1', 'Understand a fraction as a part of a whole'],
            ['Grade 3', 'Mathematics', 22, 'NC.3.NF.3', 'Compare fractions with the same numerator'],
            ['Grade 3', 'Mathematics', 30, 'MP1', 'Cumulative review: OA, NBT'],
            ['Grade 3', 'Mathematics', 31, 'MP1', 'Make sense of problems and persevere'],
            ['Grade 4', 'Mathematics', 20, 'NC.4.NF.1', 'Equivalent fractions using visual models'],
            ['Grade 3', 'English Language Arts', 30, 'RF.3.4', 'Read fluently with expression'],
            ['Grade 3', 'English Language Arts', 8, 'SL.3.1', 'Set goals for a collaborative discussion'],
            ['Grade 3', 'Qur’an & Islamic Studies', 4, null, 'Wudu: the steps in order'],
            ['Grade 3', 'Mathematics', 12, 'NC.3.G.1', 'Right angles and rhombuses'],
            ['Grade 3', 'English Language Arts', 5, 'RI.3.1', 'Ask and answer questions about a text'],
            ['Kindergarten', 'Mathematics', 3, 'NC.K.CC.1', 'Count to 100 by ones and tens'],
            ['Kindergarten', 'Mathematics', 30, 'NC.K.OA.5', 'Add & subtract within 10'],
            // The guide's own transliterations, as Al-Razi's is spelled.
            ['Grade 5', 'Qur’an & Islamic Studies', 1, null, 'Tajwīd review; begin Surah Al-Mulk ḥifẓ'],
            ['Pre-Kindergarten', 'Healthful Living', 2, 'HPD-2', 'ṭahāra: washing hands before eating'],
            ['Pre-Kindergarten', 'Qur’an & Islamic Studies', 9, null, 'Letter sound ʿayn'],
        ]);

        // Another school's guide, with a code the query below would match.
        $this->guide($this->otherSchool->id, [
            ['Grade 3', 'Mathematics', 20, 'NC.3.NF.2', 'Their fractions, not ours'],
        ]);

        Sanctum::actingAs($teacher, ['staff']);
    }

    #[Test]
    public function a_code_is_found_however_it_is_spelled(): void
    {
        foreach (['NC.3.NF.1', 'nc.3.nf.1', '3.NF.1', '3nf1', 'NF 1'] as $typed) {
            $codes = $this->codes(['q' => $typed, 'grade' => 'Grade 3', 'subject' => 'Mathematics']);

            $this->assertSame('NC.3.NF.1', $codes[0] ?? null, "typed: {$typed}");
        }
    }

    #[Test]
    public function a_match_fills_the_whole_standard_and_repeated_weeks_collapse_into_one(): void
    {
        $matches = $this->search(['q' => 'NC.3.NF.1']);

        $this->assertCount(1, $matches, 'weeks 20 and 21 carry the same wording: one suggestion');
        $this->assertSame([
            'standard_code' => 'NC.3.NF.1',
            'focus' => 'Understand a fraction as a part of a whole',
            'grade_label' => 'Grade 3',
            'subject' => 'Mathematics',
            'weeks' => [20, 21],
            'week_no' => 20,
            'assessment_formative' => 'Exit ticket',
            'prefill_source' => 'Test pacing guide',
            'in_scope' => true,
        ], $matches[0]);
    }

    #[Test]
    public function a_pick_lands_on_the_forms_own_week_when_the_guide_teaches_it_then(): void
    {
        $matches = $this->search(['q' => 'NC.3.NF.1', 'week' => '21']);

        $this->assertSame(21, $matches[0]['week_no']);
    }

    #[Test]
    public function a_code_used_with_different_wordings_offers_each_wording(): void
    {
        $focuses = array_column($this->search(['q' => 'MP1']), 'focus');

        $this->assertSame([
            'Cumulative review: OA, NBT',
            'Make sense of problems and persevere',
        ], $focuses);
    }

    #[Test]
    public function words_find_a_standard_from_the_start_of_a_word_only(): void
    {
        $this->assertSame(['NC.3.NF.3'], $this->codes(['q' => 'compare fract']));
        $this->assertSame(['RF.3.4'], $this->codes(['q' => 'fluently']));

        // "oal" is inside "goals" and starts no word of the guide, so the
        // discussion standard is not offered for it.
        $this->assertSame([], $this->codes(['q' => 'oal']));
    }

    #[Test]
    public function the_forms_grade_and_subject_rank_first_without_hiding_the_rest(): void
    {
        $this->assertSame(
            ['NC.3.NF.1', 'NC.4.NF.1'],
            $this->codes(['q' => 'nf1', 'grade' => 'Grade 3', 'subject' => 'Mathematics'])
        );
        $this->assertSame(
            ['NC.4.NF.1', 'NC.3.NF.1'],
            $this->codes(['q' => 'nf1', 'grade' => 'Grade 4', 'subject' => 'Mathematics'])
        );
    }

    #[Test]
    public function a_code_the_teacher_typed_outranks_words_its_letters_happen_to_start(): void
    {
        // A Grade 3 Maths form citing an ELA code: "ri" starts "Right" in the
        // form's own subject, and must not push the typed code down or appear.
        $codes = $this->codes(['q' => 'RI.3.1', 'grade' => 'Grade 3', 'subject' => 'Mathematics']);

        $this->assertSame(['RI.3.1'], $codes);
    }

    #[Test]
    public function the_forms_grade_ranks_its_other_subjects_above_other_grades(): void
    {
        // "Arabic" is typed by hand (not in the guide), so only the grade can
        // place anything — it must still place Grade 3 first.
        $matches = $this->search(['q' => 'nf1', 'grade' => 'Grade 3', 'subject' => 'Arabic']);

        $this->assertSame(['NC.3.NF.1', 'NC.4.NF.1'], array_column($matches, 'standard_code'));
        $this->assertSame([false, false], array_column($matches, 'in_scope'));
    }

    #[Test]
    public function plain_typing_finds_the_guides_transliterations(): void
    {
        $this->assertSame(['Tajwīd review; begin Surah Al-Mulk ḥifẓ'], array_column($this->search(['q' => 'hifz']), 'focus'));
        $this->assertSame(['Tajwīd review; begin Surah Al-Mulk ḥifẓ'], array_column($this->search(['q' => 'tajwid']), 'focus'));
        $this->assertSame(['HPD-2'], $this->codes(['q' => 'tahara']));
        $this->assertSame(['Letter sound ʿayn'], array_column($this->search(['q' => 'ayn']), 'focus'));
    }

    #[Test]
    public function other_forms_of_a_word_and_filler_words_still_find_it(): void
    {
        $this->assertSame(['NC.K.CC.1'], $this->codes(['q' => 'counting']));
        $this->assertSame(['NC.K.OA.5'], $this->codes(['q' => 'subtraction']));
        $this->assertSame(['RF.3.4'], $this->codes(['q' => 'fluency']));
        $this->assertSame(['RF.3.4'], $this->codes(['q' => 'the fluency with expression']));
    }

    #[Test]
    public function a_long_or_malformed_query_is_refused_rather_than_scanned(): void
    {
        $url = "/api/teacher/masjids/{$this->school->id}/curriculum/standards?";

        $this->getJson($url . http_build_query(['q' => str_repeat('fraction ', 8)]))->assertStatus(422);
        $this->getJson($url . 'q[]=NF')->assertStatus(422);
        $this->getJson($url . 'q=NF&grade[]=x')->assertStatus(422);
        $this->getJson($url . 'q=NF&week=soon')->assertStatus(422);
    }

    #[Test]
    public function an_uncoded_row_is_found_by_its_words_and_the_curly_apostrophe_does_not_matter(): void
    {
        $matches = $this->search(['q' => 'quran wudu']);

        $this->assertCount(1, $matches);
        $this->assertNull($matches[0]['standard_code']);
        $this->assertSame('Qur’an & Islamic Studies', $matches[0]['subject']);
    }

    #[Test]
    public function fewer_than_two_characters_asks_for_nothing(): void
    {
        $this->assertSame([], $this->search(['q' => 'N']));
        $this->assertSame([], $this->search(['q' => ' . ']));
    }

    #[Test]
    public function another_schools_guide_is_never_offered(): void
    {
        $this->assertNotContains('NC.3.NF.2', $this->codes(['q' => 'NF']));
        $this->assertSame([], $this->search(['q' => 'their fractions']));
    }

    #[Test]
    public function a_teacher_of_another_school_is_refused(): void
    {
        $this->getJson("/api/teacher/masjids/{$this->otherSchool->id}/curriculum/standards?q=NF")
            ->assertForbidden();
    }

    /** @return array<int, array<string, mixed>> */
    private function search(array $query): array
    {
        return $this->getJson(
            "/api/teacher/masjids/{$this->school->id}/curriculum/standards?" . http_build_query($query)
        )->assertOk()->json('data.matches');
    }

    /** @return array<int, string|null> */
    private function codes(array $query): array
    {
        return array_column($this->search($query), 'standard_code');
    }

    private function guide(int $masjidId, array $rows): void
    {
        app(TenantContext::class)->runWithout(function () use ($masjidId, $rows): void {
            foreach ($rows as [$grade, $subject, $week, $code, $focus]) {
                CurriculumWeek::create([
                    'masjid_id' => $masjidId, 'grade_label' => $grade, 'subject' => $subject,
                    'week_no' => $week, 'standard_code' => $code, 'focus' => $focus,
                    'assessment_note' => 'Exit ticket', 'source_label' => 'Test pacing guide',
                ]);
            }
        });
    }

    private function newSchool(string $name): Masjid
    {
        return Masjid::create([
            'name' => $name . ' ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }
}
