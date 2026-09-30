<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * `curriculum_weeks.objective` and `.learning_outcome`: additive and nullable,
 * and the migration will not throw the school's words away on the way down.
 */
class CurriculumWeeksObjectiveMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_05_100000_add_objective_and_learning_outcome_to_curriculum_weeks_table.php';

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
        return require base_path(self::MIGRATION);
    }

    private function insertRow(?string $objective = null, ?string $outcome = null): void
    {
        $masjid = DB::table('masjids')->first()?->id ?? $this->makeMasjid();

        DB::table('curriculum_weeks')->insert([
            'masjid_id' => $masjid, 'grade_label' => 'Grade 1', 'subject' => 'Science', 'week_no' => 1,
            'focus' => 'Plants', 'objective' => $objective, 'learning_outcome' => $outcome,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeMasjid(): int
    {
        $m = new \App\Models\Masjid;
        $m->forceFill([
            'name' => 'Al-Razi Test', 'email' => 'm-' . uniqid() . '@test.local', 'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ])->save();

        return $m->id;
    }

    #[Test]
    public function both_columns_exist_and_an_old_row_reads_null(): void
    {
        $this->assertTrue(Schema::hasColumns('curriculum_weeks', ['objective', 'learning_outcome']));

        $this->insertRow();
        $row = DB::table('curriculum_weeks')->first();

        $this->assertNull($row->objective);
        $this->assertNull($row->learning_outcome);
    }

    #[Test]
    public function down_refuses_while_a_row_holds_the_schools_words_and_drops_the_columns_when_none_does(): void
    {
        $this->insertRow('Memorize Surah Al-Ikhlāṣ', null);

        try {
            $this->migration()->down();
            $this->fail('down() should refuse while a row has an objective');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('curriculum:import', $e->getMessage());
            $this->assertStringContainsString('1 row(s)', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumns('curriculum_weeks', ['objective', 'learning_outcome']), 'nothing was dropped');

        DB::table('curriculum_weeks')->update(['objective' => null, 'learning_outcome' => 'Recite independently']);

        $this->expectException(RuntimeException::class);
        $this->migration()->down();
    }

    #[Test]
    public function down_drops_both_columns_when_no_row_holds_anything_and_up_puts_them_back(): void
    {
        $this->insertRow();

        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('curriculum_weeks', 'objective'));
        $this->assertFalse(Schema::hasColumn('curriculum_weeks', 'learning_outcome'));
        $this->assertSame(1, DB::table('curriculum_weeks')->count(), 'the row survives');

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumns('curriculum_weeks', ['objective', 'learning_outcome']));
    }
}
