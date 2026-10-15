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
 * One lesson plan per class, per day, per subject, PER GRADE — the schema half.
 *
 * The HTTP behaviour (each grade of a combined-grade class gets its own plan of
 * a subject, and no address writes one grade's plan over another's) is in
 * LessonPlanPerGradeTest. This pins the key itself, the backfill of the rows
 * production already holds (graded, and ungraded from before grades were
 * keyed), and the way down refusing rather than aborting half-way.
 */
class LessonPlanGradeKeyTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_15_120000_key_lesson_plans_by_grade.php';

    private Masjid $school;
    private Group $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Masjid::create([
            'name' => 'Combined Grade School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@example.invalid',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Practice St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Combined Grades', 'slug' => 'combined-grades-' . uniqid(),
        ]);
    }

    #[Test]
    public function the_unique_key_is_class_day_subject_and_grade_and_the_per_subject_key_is_gone(): void
    {
        $indexes = collect(Schema::getIndexes('lesson_plans'))->keyBy('name');

        $this->assertTrue($indexes->has('lesson_plan_class_day_subject_grade_unique'));
        $this->assertTrue($indexes['lesson_plan_class_day_subject_grade_unique']['unique']);
        $this->assertSame(['group_id', 'session_date', 'subject_key', 'grade_key'],
            $indexes['lesson_plan_class_day_subject_grade_unique']['columns'],
            'it leads with group_id, so it backs that foreign key on MySQL');
        $this->assertFalse($indexes->has('lesson_plan_class_day_subject_unique'),
            'the per-subject index would refuse the second grade');
        $this->assertLessThanOrEqual(64, strlen('lesson_plan_class_day_subject_grade_unique'), 'MySQL refuses longer names');

        // NOT NULL, defaulting to '': a NULL never collides in a MySQL unique
        // index, so a nullable key would admit two ungraded plans of a subject.
        $key = collect(Schema::getColumns('lesson_plans'))->firstWhere('name', 'grade_key');
        $this->assertFalse($key['nullable']);
        $this->assertStringContainsString('varchar', strtolower($key['type']));
        $this->assertSame("''", $key['default']);
    }

    #[Test]
    public function one_grade_however_it_is_spelled_is_one_key_and_a_blank_grade_is_no_grade(): void
    {
        foreach (['Pre-K', 'PreK', 'Pre-Kindergarten', ' pre-kindergarten '] as $label) {
            $this->assertSame('prek', LessonPlan::gradeKeyFor($label), $label);
        }
        foreach (['K', 'KG', 'Kindergarten'] as $label) {
            $this->assertSame('kg', LessonPlan::gradeKeyFor($label), $label);
        }
        $this->assertSame('1', LessonPlan::gradeKeyFor('Grade 1'));
        $this->assertSame(LessonPlan::gradeKeyFor('1st'), LessonPlan::gradeKeyFor('Grade 1'));
        $this->assertNotSame(LessonPlan::gradeKeyFor('Grade 1A'), LessonPlan::gradeKeyFor('Grade 1B'));

        $this->assertSame('', LessonPlan::gradeKeyFor(null));
        $this->assertSame('', LessonPlan::gradeKeyFor("  \t "));

        // A label GradeLevel reduces to nothing is still a grade, never "no grade".
        $this->assertSame('الروضة', LessonPlan::gradeKeyFor('الروضة'));
        $this->assertNotSame(LessonPlan::gradeKeyFor('الروضة'), LessonPlan::gradeKeyFor('التمهيدي'));

        // The request allows 32 characters and the column is 32 wide.
        $this->assertLessThanOrEqual(32, mb_strlen(LessonPlan::gradeKeyFor(str_repeat('İ', 32))));
        $this->assertLessThanOrEqual(32, mb_strlen(LessonPlan::gradeKeyFor(str_repeat('ب', 32))));

        $plan = $this->plan('2026-10-19', 'Mathematics', 'Pre-K');
        $this->assertSame('prek', $plan->getRawOriginal('grade_key'));
        $this->assertArrayNotHasKey('grade_key', $plan->toArray(), 'the key is index plumbing, not payload');
    }

    #[Test]
    public function the_index_holds_one_plan_per_subject_per_grade_whatever_the_spelling(): void
    {
        $this->plan('2026-10-19', 'Mathematics', 'Pre-Kindergarten');
        $this->plan('2026-10-19', 'Mathematics', 'Kindergarten');
        $this->plan('2026-10-19', 'Mathematics', null);
        $this->assertSame(3, LessonPlan::count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->plan('2026-10-19', 'mathematics', 'Pre-K');
    }

    #[Test]
    public function rolling_forward_backfills_the_key_for_graded_and_ungraded_rows(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('lesson_plans', 'grade_key'));
        $this->assertContains('lesson_plan_class_day_subject_unique', collect(Schema::getIndexes('lesson_plans'))->pluck('name'));

        // Rows as production holds them: a class's Pre-K and Kindergarten plans
        // (one subject per day while the per-subject key stood) and an older
        // ungraded plan, written as the cast writes dates.
        foreach ([
            ['2026-10-12 00:00:00', 'Mathematics', 'mathematics', 'Pre-Kindergarten'],
            ['2026-10-13 00:00:00', 'Mathematics', 'mathematics', 'Kindergarten'],
            ['2026-10-12 00:00:00', 'Arabic', 'arabic', null],
        ] as [$day, $subject, $subjectKey, $grade]) {
            DB::table('lesson_plans')->insert([
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
                'session_date' => $day, 'subject' => $subject, 'subject_key' => $subjectKey,
                'grade_label' => $grade, 'body' => 'Circle time.', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->migration()->up();

        $this->assertSame(['prek', 'kg', ''], DB::table('lesson_plans')->orderBy('id')->pluck('grade_key')->all());
        $this->assertContains('lesson_plan_class_day_subject_grade_unique', collect(Schema::getIndexes('lesson_plans'))->pluck('name'));

        // And the day that held Pre-K Mathematics can now hold Kindergarten's too.
        $this->plan('2026-10-12', 'Mathematics', 'Kindergarten');
        $this->assertSame(2, LessonPlan::whereDate('session_date', '2026-10-12')->where('subject_key', 'mathematics')->count());
    }

    #[Test]
    public function rolling_back_refuses_while_a_subject_is_planned_for_two_grades_on_one_day_and_changes_nothing(): void
    {
        $preK = $this->plan('2026-10-19', 'Mathematics', 'Pre-Kindergarten');
        $kg = $this->plan('2026-10-19', 'Mathematics', 'Kindergarten');
        $this->plan('2026-10-19', 'Arabic', 'Kindergarten');

        try {
            $this->migration()->down();
            $this->fail('down() must refuse while one subject has two grades\' plans on a day');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("{$preK->id}, {$kg->id})", $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('lesson_plans', 'grade_key'));
        $this->assertContains('lesson_plan_class_day_subject_grade_unique', collect(Schema::getIndexes('lesson_plans'))->pluck('name'));
        $this->assertSame(3, LessonPlan::count());
    }

    #[Test]
    public function rolling_back_restores_the_per_subject_key_when_no_subject_has_two_grades_on_a_day(): void
    {
        $this->plan('2026-10-19', 'Mathematics', 'Pre-Kindergarten');
        $this->plan('2026-10-20', 'Mathematics', 'Kindergarten');

        $this->migration()->down();

        $names = collect(Schema::getIndexes('lesson_plans'))->pluck('name');
        $this->assertContains('lesson_plan_class_day_subject_unique', $names);
        $this->assertNotContains('lesson_plan_class_day_subject_grade_unique', $names);
        $this->assertFalse(Schema::hasColumn('lesson_plans', 'grade_key'));
        $this->assertSame(2, DB::table('lesson_plans')->count());
    }

    private function plan(string $day, ?string $subject, ?string $grade): LessonPlan
    {
        return LessonPlan::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'session_date' => $day, 'subject' => $subject, 'grade_label' => $grade, 'body' => 'Circle time.',
        ]);
    }

    private function migration(): object
    {
        return include database_path('migrations/' . self::MIGRATION);
    }
}
