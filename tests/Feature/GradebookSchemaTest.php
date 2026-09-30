<?php

namespace Tests\Feature;

use App\Models\ClassAssignment;
use App\Models\Group;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The tables and columns W3 adds have the shape their migrations document
 * (.claude/rules/migrations.md, "Assert column TYPES, not only values").
 *
 * SQLite enforces no VARCHAR length and no tinyint range, so the suite cannot
 * see the limits MySQL will enforce on production. What it CAN pin is that every
 * limit is enforced by the request layer at the same number the column has; the
 * boundary tests for that live in `GradebookCurriculumFieldsTest`.
 */
class GradebookSchemaTest extends TestCase
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

    #[Test]
    public function class_assignments_gains_exactly_the_curriculum_columns(): void
    {
        $columns = Schema::getColumnListing('class_assignments');

        foreach (['subject', 'subject_key', 'type', 'weight', 'standard_code', 'curriculum_focus', 'curriculum_week_no'] as $column) {
            $this->assertContains($column, $columns, "class_assignments.{$column} is missing");
        }

        // Nothing that is DERIVED is stored (the create migration's rule): no
        // average, no scored count, no published flag.
        foreach (['average', 'scored', 'is_published', 'weighted'] as $derived) {
            $this->assertNotContains($derived, $columns);
        }

        foreach (['subject', 'subject_key', 'type', 'standard_code', 'curriculum_focus'] as $text) {
            $this->assertContains(Schema::getColumnType('class_assignments', $text), ['varchar', 'string'], $text);
        }
        foreach (['weight', 'curriculum_week_no'] as $small) {
            $this->assertContains(Schema::getColumnType('class_assignments', $small), ['integer', 'tinyint', 'int'], $small);
        }
    }

    #[Test]
    public function existing_style_rows_stay_null_and_the_key_defaults_to_no_subject(): void
    {
        $masjid = Masjid::create([
            'name' => 'School '.uniqid(), 'email' => 's-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
        ]);
        $group = Group::factory()->create(['masjid_id' => $masjid->id, 'kind' => Group::KIND_CLASS, 'name' => 'C', 'slug' => 'c']);

        // The shape of a row written before this migration: none of the new
        // columns named at all.
        $id = \Illuminate\Support\Facades\DB::table('class_assignments')->insertGetId([
            'masjid_id' => $masjid->id, 'group_id' => $group->id, 'title' => 'Old work',
            'points_possible' => 10, 'scale' => 'points', 'assigned_on' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = \Illuminate\Support\Facades\DB::table('class_assignments')->find($id);

        foreach (['subject', 'type', 'weight', 'standard_code', 'curriculum_focus', 'curriculum_week_no'] as $column) {
            $this->assertNull($row->{$column}, "{$column} must stay NULL: blank is not a default");
        }
        $this->assertSame('', $row->subject_key);
    }

    #[Test]
    public function the_two_new_tables_have_the_documented_columns_and_short_unique_indexes(): void
    {
        $this->assertSame(
            ['id', 'masjid_id', 'group_id', 'assignment_type', 'weight', 'updated_by_user_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('class_grade_weights')
        );
        $this->assertSame(
            // `seeded_by` is its own later migration (2026_10_03_100250), so it lands after the timestamps.
            ['id', 'masjid_id', 'name', 'name_key', 'grade_labels', 'position', 'created_at', 'updated_at', 'seeded_by'],
            Schema::getColumnListing('school_subjects')
        );

        $indexNames = [];
        foreach (['class_grade_weights', 'school_subjects', 'class_assignments'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $indexNames[] = $index['name'];
            }
        }

        $this->assertContains('class_grade_weight_unique', $indexNames);
        $this->assertContains('school_subject_name_unique', $indexNames);

        // MySQL refuses an identifier over 64 characters; SQLite does not.
        foreach ($indexNames as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), "{$name} would abort a migration on MySQL");
        }
    }

    #[Test]
    public function the_curriculum_migration_refuses_to_roll_back_over_a_row_holding_only_a_week_number(): void
    {
        $masjid = Masjid::create([
            'name' => 'School '.uniqid(), 'email' => 's-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
        ]);
        $group = Group::factory()->create(['masjid_id' => $masjid->id, 'kind' => Group::KIND_CLASS, 'name' => 'C', 'slug' => 'c']);
        // Only the guide's week number is set: none of the five columns the guard used to look at.
        \Illuminate\Support\Facades\DB::table('class_assignments')->insert([
            'masjid_id' => $masjid->id, 'group_id' => $group->id, 'title' => 'Week 4 work', 'points_possible' => 10,
            'scale' => 'points', 'assigned_on' => now()->toDateString(), 'curriculum_week_no' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_03_100000_add_curriculum_fields_to_class_assignments_table.php');

        try {
            $migration->down();
            $this->fail('the rollback dropped a week number nobody had cleared');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to roll back: 1 piece(s) of work', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('class_assignments', 'curriculum_week_no'), 'the column, and the number in it, are still there');
        $this->assertSame(4, (int) \Illuminate\Support\Facades\DB::table('class_assignments')->value('curriculum_week_no'));
    }

    #[Test]
    public function the_type_constants_are_the_five_the_migration_documents(): void
    {
        $this->assertEqualsCanonicalizing(['quiz', 'homework', 'test', 'classwork', 'other'], ClassAssignment::TYPES);
        $this->assertSame(ClassAssignment::TYPES, array_keys(ClassAssignment::TYPE_LABELS));

        foreach (ClassAssignment::TYPES as $type) {
            $this->assertLessThanOrEqual(16, strlen($type), 'assignment type must fit varchar(16)');
        }
    }
}
