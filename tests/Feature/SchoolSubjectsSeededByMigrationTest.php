<?php

namespace Tests\Feature;

use App\Models\Masjid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `school_subjects.seeded_by` arrives in its OWN migration, after the create-table
 * migration and before the seed that writes it (review F3, 2026-09-29).
 *
 * The column had been added by editing 2026_10_03_100200 after it was first
 * committed without it. A box that ran the earlier 100200 never re-runs it, so the
 * seed's insert would abort the deploy there on an unknown column. 100200 is back to
 * its first content and the column is a new migration; these tests pin both halves and
 * the box that already ran the old one.
 *
 * Deliberately does NOT use RefreshDatabase, like MigrationsBootTest: the migration
 * stack running is the thing under test, and a box that "already ran 100200" is
 * built by migrating, taking the column away, and migrating again.
 */
class SchoolSubjectsSeededByMigrationTest extends TestCase
{
    private const CREATE = '2026_10_03_100200_create_school_subjects_table';
    private const ADD = '2026_10_03_100250_add_seeded_by_to_school_subjects_table';
    private const SEED = '2026_10_03_100300_seed_school_subjects_for_alrazi_and_biss';

    /**
     * SHA-1 of 2026_10_03_100200 as commit 4ebd8d8d first wrote it, before `seeded_by`
     * was put into it. A migration that has run on any box is never edited: change
     * this number only by adding a new migration, not by editing that one.
     */
    private const CREATE_SHA1 = 'cecece5118087645ffe1c1dd6f68156cd6754abc';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    private function migration(string $name): object
    {
        return require database_path("migrations/{$name}.php");
    }

    private function makeSchool(int $id, string $name): void
    {
        $m = Masjid::create([
            'name' => $name, 'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
        DB::table('masjids')->where('id', $m->id)->update(['id' => $id]);
    }

    #[Test]
    public function a_fresh_database_gets_the_column_from_its_own_migration_in_the_right_order(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('school_subjects', 'seeded_by'));

        $ran = DB::table('migrations')->whereIn('migration', [self::CREATE, self::ADD, self::SEED])->pluck('id', 'migration')->all();
        $this->assertCount(3, $ran);
        $this->assertLessThan($ran[self::ADD], $ran[self::CREATE], 'the column comes after the table');
        $this->assertLessThan($ran[self::SEED], $ran[self::ADD], 'and before the seed that writes it');
    }

    #[Test]
    public function the_create_table_migration_is_what_it_first_said_and_nothing_that_uses_the_mark_runs_before_the_new_one(): void
    {
        // Byte for byte what 4ebd8d8d wrote: an applied migration is never edited.
        $create = database_path('migrations/'.self::CREATE.'.php');
        $this->assertSame(self::CREATE_SHA1, sha1_file($create));
        $this->assertStringNotContainsString('seeded_by', (string) file_get_contents($create));

        // Every other migration that so much as names the column sorts after the one that adds it,
        // so no box can reach a reader of the column without having it.
        $readers = [];
        foreach (glob(database_path('migrations/*.php')) as $file) {
            $name = basename($file, '.php');

            if ($name !== self::ADD && str_contains((string) file_get_contents($file), 'seeded_by')) {
                $readers[] = $name;
            }
        }

        $this->assertContains(self::SEED, $readers, 'the seed is a reader, so this guard is looking at something');

        foreach ($readers as $reader) {
            $this->assertGreaterThan(self::ADD, $reader, "{$reader} uses seeded_by and must run after the migration that adds it");
        }
    }

    #[Test]
    public function a_box_that_already_ran_the_first_100200_gains_the_column_and_the_seed_then_runs(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        // The box as it stood: 100200 applied exactly as first written (no seeded_by), the two
        // later migrations not yet run. The current 100200 file IS that first version, so dropping
        // the column it does not create reproduces the table it built, and nothing else touches it.
        Schema::table('school_subjects', fn ($t) => $t->dropColumn('seeded_by'));
        DB::table('migrations')->whereIn('migration', [self::ADD, self::SEED])->delete();
        $this->assertFalse(Schema::hasColumn('school_subjects', 'seeded_by'));
        $this->makeSchool(18, 'Burlington Islamic Sunday School');

        // What used to abort: the seed inserting `seeded_by` into a table without it.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('school_subjects', 'seeded_by'), 'the new migration added it');
        $this->assertSame(
            [self::SEED],
            DB::table('school_subjects')->where('masjid_id', 18)->pluck('seeded_by')->unique()->values()->all(),
            'and the seed ran after it, marking what it wrote'
        );
        $this->assertSame(3, DB::table('school_subjects')->where('masjid_id', 18)->count());
        $this->assertSame(
            2,
            DB::table('migrations')->whereIn('migration', [self::ADD, self::SEED])->count(),
            'both were recorded as run'
        );
    }

    #[Test]
    public function a_row_the_office_already_typed_survives_the_column_being_added(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->makeSchool(31, 'Some School');

        Schema::table('school_subjects', fn ($t) => $t->dropColumn('seeded_by'));
        DB::table('migrations')->where('migration', self::ADD)->delete();
        DB::table('school_subjects')->insert([
            'masjid_id' => 31, 'name' => 'Seerah', 'name_key' => 'seerah', 'position' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $row = DB::table('school_subjects')->where('masjid_id', 31)->first();
        $this->assertSame('Seerah', $row->name);
        $this->assertSame(4, (int) $row->position);
        $this->assertNull($row->seeded_by, 'an office row carries no mark');
    }

    #[Test]
    public function the_new_migration_is_safe_to_run_twice_and_to_roll_back_twice(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $migration = $this->migration(self::ADD);

        // The column is already there (this box just migrated): running up() again writes nothing new.
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('school_subjects', 'seeded_by'));

        // down() drops it only when present, so a second rollback has nothing to do and does not throw.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('school_subjects', 'seeded_by'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('school_subjects', 'seeded_by'));

        // And it comes back, once.
        $migration->up();
        $this->assertTrue(Schema::hasColumn('school_subjects', 'seeded_by'));
        $this->assertSame(1, count(array_filter(Schema::getColumnListing('school_subjects'), fn ($c) => $c === 'seeded_by')));
    }
}
