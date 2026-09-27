<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\LessonPlan;
use App\Models\Masjid;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One lesson plan per class, per day, PER SUBJECT — the schema half.
 *
 * The HTTP behaviour (a second subject is a second plan, the same subject is
 * refused by name) is in TeacherLessonsGradebookResourcesTest. This pins what
 * holds underneath it when the controller's own check is raced or bypassed, and
 * the migration's two directions: the key is backfilled on the way up, and the
 * way down refuses rather than aborting half-way over a day with two plans.
 */
class LessonPlanSubjectKeyTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_09_27_120000_key_lesson_plans_by_subject.php';

    private Masjid $school;
    private Group $class;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->school = Masjid::create([
            'name' => 'Al-Razi Test ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => '1st & 2nd Grade', 'slug' => '1st-2nd-grade-' . uniqid(),
        ]);
    }

    #[Test]
    public function the_unique_key_is_class_day_and_subject_key_and_the_per_day_key_is_gone(): void
    {
        $indexes = collect(Schema::getIndexes('lesson_plans'))->keyBy('name');

        $this->assertTrue($indexes->has('lesson_plan_class_day_subject_unique'));
        $this->assertTrue($indexes['lesson_plan_class_day_subject_unique']['unique']);
        $this->assertSame(['group_id', 'session_date', 'subject_key'], $indexes['lesson_plan_class_day_subject_unique']['columns']);
        $this->assertFalse($indexes->has('lesson_plan_class_day_unique'),
            'the one-plan-per-day index would refuse the second subject');

        // NOT NULL, defaulting to '': a NULL never collides in a MySQL unique
        // index, so a nullable key would admit two general plans on one day.
        $key = collect(Schema::getColumns('lesson_plans'))->firstWhere('name', 'subject_key');
        $this->assertFalse($key['nullable']);
        $this->assertStringContainsString('varchar', strtolower($key['type']));
        $this->assertSame("''", $key['default']);
    }

    #[Test]
    public function the_key_ignores_case_and_spacing_and_a_blank_subject_is_no_subject(): void
    {
        $this->assertSame('math facts', LessonPlan::subjectKeyFor("  Math \t Facts "));
        $this->assertSame('', LessonPlan::subjectKeyFor(null));
        $this->assertSame('', LessonPlan::subjectKeyFor('   '));

        $plan = $this->plan('2026-09-14', '   ');
        $this->assertNull($plan->subject, 'a subject of only spaces is saved as no subject');
        $this->assertSame('', $plan->getRawOriginal('subject_key'));
        $this->assertArrayNotHasKey('subject_key', $plan->toArray(), 'the key is index plumbing, not payload');
    }

    /**
     * The request allows a 64-character subject and the key column is 64 wide.
     * Full Unicode lower-casing can LENGTHEN a string ('İ' is 'i' plus a
     * combining dot), which MySQL refuses as too long — a 500 for a valid
     * subject — and SQLite stores without a word, so only this length check
     * can see it here.
     */
    #[Test]
    public function the_key_is_never_longer_than_the_subject_so_it_fits_its_column(): void
    {
        $longest = str_repeat('İ', 64);

        $this->assertSame(64, mb_strlen(LessonPlan::subjectKeyFor($longest)));
        $this->assertSame('islamic', LessonPlan::subjectKeyFor('İslamic'));
        // The same one-character-at-a-time rule the SPA's subjectKey() mirrors:
        // no word-final sigma.
        $this->assertSame('οδοσ', LessonPlan::subjectKeyFor('ΟΔΟΣ'));
    }

    #[Test]
    public function the_index_itself_refuses_a_second_plan_for_a_subject_whatever_its_case(): void
    {
        $this->plan('2026-09-14', 'Math');
        $this->plan('2026-09-14', 'Science');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->plan('2026-09-14', 'MATH');
    }

    #[Test]
    public function the_index_itself_refuses_a_second_general_plan_on_one_day(): void
    {
        $this->plan('2026-09-14', null);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->plan('2026-09-14', null);
    }

    #[Test]
    public function rolling_back_refuses_while_a_day_holds_two_plans_and_changes_nothing(): void
    {
        $math = $this->plan('2026-09-14', 'Math');
        $science = $this->plan('2026-09-14', 'Science');

        try {
            $this->migration()->down();
            $this->fail('down() must refuse while two plans share a day');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("{$math->id}, {$science->id}", $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('lesson_plans', 'subject_key'));
        $names = collect(Schema::getIndexes('lesson_plans'))->pluck('name');
        $this->assertContains('lesson_plan_class_day_subject_unique', $names);
        $this->assertSame(2, LessonPlan::count());
    }

    #[Test]
    public function rolling_back_and_forward_again_backfills_the_key_from_the_subject(): void
    {
        $this->plan('2026-09-14', 'Math');

        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('lesson_plans', 'subject_key'));
        $this->assertContains('lesson_plan_class_day_unique', collect(Schema::getIndexes('lesson_plans'))->pluck('name'));

        // A row written while the old shape stood, as production's are.
        DB::table('lesson_plans')->insert([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'session_date' => '2026-09-15 00:00:00', 'subject' => "Qur'an  Studies",
            'body' => 'Surah al-Fil.', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $keys = DB::table('lesson_plans')->orderBy('id')->pluck('subject_key')->all();
        $this->assertSame(['math', "qur'an studies"], $keys);
        $this->assertContains('lesson_plan_class_day_subject_unique', collect(Schema::getIndexes('lesson_plans'))->pluck('name'));
    }

    private function plan(string $day, ?string $subject): LessonPlan
    {
        return LessonPlan::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'session_date' => $day, 'subject' => $subject, 'body' => 'Circle time.',
        ]);
    }

    private function migration(): object
    {
        return include database_path('migrations/' . self::MIGRATION);
    }
}
