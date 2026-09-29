<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\SchoolSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The guarded seed of the Subjects list for Al-Razi (14) and BISS (18)
 * (owner, 2026-09-28, B3): Qur'an, Islamic Studies and Arabic Language at every
 * grade, alongside Al-Razi's own guide subjects.
 *
 * The properties that matter: it writes only where the org id AND its name say it
 * is the right school; it never splits or hides the guide's combined weekly
 * column; it only inserts, so an office decision is never overwritten; and it
 * reverses only what nobody has since edited.
 */
class SeedSchoolSubjectsMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_03_100300_seed_school_subjects_for_alrazi_and_biss.php');
    }

    /** Named `makeMasjid` so TenantScopingCoverageTest recognises the two-tenant fixture. */
    private function makeMasjid(string $name, int $id, string $orgType = 'school'): Masjid
    {
        $m = Masjid::create([
            'name' => $name, 'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => $orgType,
        ]);
        DB::table('masjids')->where('id', $m->id)->update(['id' => $id]);

        return Masjid::find($id);
    }

    private int $week = 0;

    private function guide(int $masjidId, string $grade, string $subject): void
    {
        DB::table('curriculum_weeks')->insert([
            // A new week each time: one cell per (school, grade, subject, week).
            'masjid_id' => $masjidId, 'grade_label' => $grade, 'subject' => $subject, 'week_no' => ++$this->week,
            'focus' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<int,string> name by position order */
    private function names(int $masjidId): array
    {
        return SchoolSubject::withoutMasjidScope()->where('masjid_id', $masjidId)
            ->orderBy('position')->orderBy('name')->pluck('name')->all();
    }

    #[Test]
    public function biss_gets_the_three_subjects_and_nothing_else_at_every_grade(): void
    {
        $this->makeMasjid('Burlington Islamic Sunday School', 18);

        $this->migration()->up();

        $this->assertSame(["Qur'an", 'Islamic Studies', 'Arabic Language'], $this->names(18));
        $this->assertSame(
            [],
            SchoolSubject::withoutMasjidScope()->where('masjid_id', 18)->whereNotNull('grade_labels')->pluck('name')->all(),
            'NULL grades means every grade, including 9th to 11th'
        );
    }

    #[Test]
    public function al_razi_gets_the_three_plus_its_guide_subjects_but_never_the_combined_column(): void
    {
        $this->makeMasjid('Al-Razi School', 14);
        foreach (['Mathematics', 'Science', 'Qur’an & Islamic Studies', 'Mathematics', 'Social Studies'] as $subject) {
            $this->guide(14, 'Grade 3', $subject);
        }

        $this->migration()->up();

        $this->assertSame(
            ["Qur'an", 'Islamic Studies', 'Arabic Language', 'Mathematics', 'Science', 'Social Studies'],
            $this->names(14)
        );
        $this->assertNotContains('Qur’an & Islamic Studies', $this->names(14));

        // The guide itself is untouched: same rows, same combined column.
        $this->assertSame(1, DB::table('curriculum_weeks')->where('subject', 'Qur’an & Islamic Studies')->count());
    }

    #[Test]
    public function a_guide_subject_that_is_one_of_the_three_under_another_spelling_is_not_added_twice(): void
    {
        $this->makeMasjid('Al-Razi School', 14);
        $this->guide(14, 'Grade 3', 'Quran');
        $this->guide(14, 'Grade 3', 'Arabic language');

        $this->migration()->up();

        $this->assertSame(["Qur'an", 'Islamic Studies', 'Arabic Language'], $this->names(14));
    }

    #[Test]
    public function running_it_twice_changes_nothing_and_an_office_decision_is_never_overwritten(): void
    {
        $this->makeMasjid('Al-Razi School', 14);
        $this->guide(14, 'Grade 3', 'Mathematics');

        $this->migration()->up();

        // The office renames one, limits another to grades, and adds its own.
        SchoolSubject::withoutMasjidScope()->where('masjid_id', 14)->where('name', 'Mathematics')
            ->first()->update(['grade_labels' => ['3rd', '4th'], 'position' => 5]);
        SchoolSubject::withoutMasjidScope()->where('masjid_id', 14)->where('name', 'Arabic Language')
            ->first()->update(['name' => 'Arabic']);
        SchoolSubject::withoutMasjidScope()->create(['masjid_id' => 14, 'name' => 'Art']);

        $before = SchoolSubject::withoutMasjidScope()->where('masjid_id', 14)->orderBy('id')->get()->toArray();
        $this->migration()->up();
        $after = SchoolSubject::withoutMasjidScope()->where('masjid_id', 14)->orderBy('id')->get()->toArray();

        // 'Arabic Language' was renamed to Arabic, so its seed name is free again
        // and one row is added back; everything else is exactly as the office left it.
        $this->assertCount(count($before) + 1, $after);
        $math = collect($after)->firstWhere('name', 'Mathematics');
        $this->assertSame(['3rd', '4th'], $math['grade_labels']);
        $this->assertSame(5, $math['position']);
    }

    #[Test]
    public function it_writes_nothing_where_the_id_is_not_the_named_school(): void
    {
        $this->makeMasjid('Some Other Masjid', 14, 'masjid');
        $this->makeMasjid('Burlington Islamic Sunday School', 18, 'masjid');

        Log::spy();
        $this->migration()->up();

        $this->assertSame(0, SchoolSubject::withoutMasjidScope()->count());
        Log::shouldHaveReceived('warning')->with('School subjects not seeded: the organisation is not the expected school', \Mockery::type('array'))->twice();
    }

    #[Test]
    public function a_school_with_the_right_id_but_the_wrong_name_is_left_alone(): void
    {
        $this->makeMasjid('Hopeful Learning Academy', 14);

        $this->migration()->up();

        $this->assertSame(0, SchoolSubject::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_fresh_database_with_no_such_orgs_is_a_quiet_no_op(): void
    {
        $this->migration()->up();

        $this->assertSame(0, SchoolSubject::withoutMasjidScope()->count());
    }

    #[Test]
    public function another_school_is_never_touched(): void
    {
        $this->makeMasjid('Al-Razi School', 14);
        $other = $this->makeMasjid('Another School', 22);

        $this->migration()->up();

        $this->assertSame(0, SchoolSubject::withoutMasjidScope()->where('masjid_id', $other->id)->count());
    }

    #[Test]
    public function it_logs_one_warning_line_saying_what_it_wrote(): void
    {
        $this->makeMasjid('Burlington Islamic Sunday School', 18);

        Log::spy();
        $this->migration()->up();

        Log::shouldHaveReceived('warning')
            ->with('School subjects seeded', ['rows_written_by_masjid' => [18 => 3]])
            ->once();
    }

    #[Test]
    public function down_removes_only_the_rows_it_wrote_that_nobody_has_touched(): void
    {
        $this->makeMasjid('Burlington Islamic Sunday School', 18);
        $this->migration()->up();

        // The office edits one seeded row and adds its own.
        $this->travel(1)->minute();
        SchoolSubject::withoutMasjidScope()->where('masjid_id', 18)->where('name', 'Islamic Studies')
            ->first()->update(['grade_labels' => ['9th']]);
        SchoolSubject::withoutMasjidScope()->create(['masjid_id' => 18, 'name' => 'Seerah']);

        $this->migration()->down();

        $this->assertEqualsCanonicalizing(['Islamic Studies', 'Seerah'], $this->names(18));
    }

    #[Test]
    public function down_leaves_a_row_the_office_touched_even_if_it_looks_like_the_seed_again(): void
    {
        $this->makeMasjid('Burlington Islamic Sunday School', 18);
        $this->migration()->up();

        // The office limits a subject to a grade, then thinks better of it and
        // puts it back: the row is identical to the seed, but somebody decided
        // something about it, and the only trace is updated_at.
        $this->travel(1)->minute();
        $subject = SchoolSubject::withoutMasjidScope()->where('masjid_id', 18)->where('name', 'Arabic Language')->first();
        $subject->update(['grade_labels' => ['3rd']]);
        $subject->update(['grade_labels' => null]);

        $this->migration()->down();

        $this->assertSame(['Arabic Language'], $this->names(18), 'a touched row is not the migration\'s to delete');
    }

    #[Test]
    public function down_leaves_an_office_row_the_seed_skipped_even_though_it_reads_exactly_like_the_seed(): void
    {
        $this->makeMasjid('Burlington Islamic Sunday School', 18);

        // The code deploys first and the seed waits for the owner's yes. Meanwhile
        // the office adds "Qur'an" at the Subjects screen's defaults: position 0,
        // every grade, created and updated in the same instant. Column for column
        // that is the seed's own row, and the seed skips it because it exists.
        SchoolSubject::withoutMasjidScope()->create(['masjid_id' => 18, 'name' => "Qur'an", 'grade_labels' => null, 'position' => 0]);

        $this->migration()->up();
        $this->assertSame(["Qur'an", 'Islamic Studies', 'Arabic Language'], $this->names(18));

        // A rollback (a staging up/down/up rehearsal, or a real one) removes what
        // the seed wrote and never the row the office typed.
        $this->migration()->down();

        $this->assertSame(["Qur'an"], $this->names(18), "the seed never owned the office's Qur'an");
    }

    #[Test]
    public function down_leaves_a_seeded_name_the_office_deleted_and_typed_again_at_the_defaults(): void
    {
        $this->makeMasjid('Burlington Islamic Sunday School', 18);
        $this->migration()->up();

        SchoolSubject::withoutMasjidScope()->where('masjid_id', 18)->where('name', 'Arabic Language')->first()->delete();
        SchoolSubject::withoutMasjidScope()->create(['masjid_id' => 18, 'name' => 'Arabic Language', 'position' => 2]);

        $this->migration()->down();

        $this->assertSame(['Arabic Language'], $this->names(18), 'the re-typed row is the office\'s, whatever the seed once wrote');
    }

    #[Test]
    public function the_seed_marks_every_row_it_writes_and_the_office_screen_never_sends_the_mark(): void
    {
        $this->makeMasjid('Burlington Islamic Sunday School', 18);
        $this->migration()->up();

        $this->assertSame(
            ['2026_10_03_100300_seed_school_subjects_for_alrazi_and_biss'],
            SchoolSubject::withoutMasjidScope()->where('masjid_id', 18)->pluck('seeded_by')->unique()->values()->all()
        );
        $this->assertArrayNotHasKey('seeded_by', SchoolSubject::withoutMasjidScope()->where('masjid_id', 18)->first()->toArray());

        $office = SchoolSubject::withoutMasjidScope()->create(['masjid_id' => 18, 'name' => 'Seerah']);
        $this->assertNull($office->fresh()->seeded_by, 'a row typed in the Subjects screen carries no mark');
    }

    #[Test]
    public function another_orgs_guide_never_adds_a_subject_to_al_razi(): void
    {
        $this->makeMasjid('Al-Razi School', 14);
        $this->makeMasjid('Another School', 22);
        $this->guide(14, 'Grade 3', 'Mathematics');
        $this->guide(22, 'Grade 3', 'Woodwork');

        $this->migration()->up();

        $this->assertSame(["Qur'an", 'Islamic Studies', 'Arabic Language', 'Mathematics'], $this->names(14));
        $this->assertSame(0, SchoolSubject::withoutMasjidScope()->where('masjid_id', 22)->count());
    }

    #[Test]
    public function a_trashed_organisation_is_never_seeded(): void
    {
        $razi = $this->makeMasjid('Al-Razi School', 14);
        $biss = $this->makeMasjid('Burlington Islamic Sunday School', 18);
        $razi->delete();
        $biss->delete();

        $this->migration()->up();

        $this->assertSame(0, SchoolSubject::withoutMasjidScope()->count());
    }

    #[Test]
    public function rolling_the_whole_batch_back_refuses_while_the_office_keeps_a_list(): void
    {
        $this->makeMasjid('Burlington Islamic Sunday School', 18);
        $this->migration()->up();
        SchoolSubject::withoutMasjidScope()->create(['masjid_id' => 18, 'name' => 'Seerah']);

        $this->migration()->down();

        // The create-table migration's down() then refuses, naming the count.
        $create = require database_path('migrations/2026_10_03_100200_create_school_subjects_table.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to roll back: 1 school subject(s) exist');
        $create->down();
    }
}
