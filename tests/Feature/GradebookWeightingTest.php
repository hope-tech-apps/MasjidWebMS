<?php

namespace Tests\Feature;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\ClassGradeWeight;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-001.2: weights per type, an optional weight on one piece of work, and the
 * average they produce (owner, 2026-09-28: "per type with a per-assignment
 * override, relative, renormalised over the types with scored work").
 *
 * The arithmetic is pinned with numbers small enough to check by hand, and every
 * expected figure is written out beside the assertion so a failing test says
 * which sum was wrong rather than only that one was.
 *
 * The rule that matters most is the FIRST one: a class with no weights reads as it
 * always did, byte for byte. Weighting is additive, and this file is what stops a
 * later hand from making it the default.
 */
class GradebookWeightingTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private Group $class;
    private GroupMembership $child;

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
            'name' => 'Al-Razi Test '.uniqid(), 'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);

        $this->class = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 3', 'slug' => 'g3']);
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $contact = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Amina', 'last_name' => 'Test']);
        $this->child = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $contact->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    // ------------------------------------------------- unweighted = as before

    #[Test]
    public function a_class_with_no_weights_reads_exactly_as_it_always_did(): void
    {
        $this->work('Unit test', 100, 'test', 80);
        $this->work('Spelling', 10, 'quiz', 10);

        $summary = $this->summary();

        // The pooled points, untouched: 90 of 110.
        $this->assertFigure(90.0, (float) $summary['points_earned']);
        $this->assertFigure(110.0, (float) $summary['points_possible']);
        $this->assertSame(2, $summary['points_counted']);

        // And no weighted figure is invented.
        $this->assertFalse($summary['weighting']['enabled']);
        $this->assertNull($summary['weighting']['percent']);
        $this->assertNull($summary['weighting']['level_mean']);
        $this->assertSame(0, $summary['weighting']['untyped_excluded']);
        $this->assertSame([], (array) $summary['weighting']['weights']);
    }

    #[Test]
    public function an_unweighted_class_serialises_its_weights_as_an_object_not_a_list(): void
    {
        // `[]` and `{}` are different JSON; a client indexing `weights.test` on a
        // list would read undefined and one on an object reads it cleanly.
        $raw = $this->getJson($this->gradesUrl())->assertOk()->getContent();
        $this->assertStringContainsString('"weights":{}', $raw);

        $index = $this->getJson($this->url('/assignments'))->assertOk()->getContent();
        $this->assertStringContainsString('"weights":{}', $index);
    }

    // ---------------------------------------------------------- the arithmetic

    #[Test]
    public function the_weighted_average_is_the_weighted_mean_of_each_types_own_percentage(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        // One piece in each type here (the many-pieces cases are below): test
        // 80/100 = 80%, quiz 10/10 = 100%, homework 10/20 = 50%.
        $this->work('Unit test', 100, 'test', 80);
        $this->work('Spelling', 10, 'quiz', 10);
        $this->work('Reading log', 20, 'homework', 10);

        $summary = $this->summary();

        // (40*0.8 + 20*1.0 + 10*0.5) / (40 + 20 + 10) = 57 / 70 = 81.43 -> 81.4
        $this->assertFigure(81.4, $summary['weighting']['percent']);
        $this->assertSame(3, $summary['weighting']['points_pieces']);
        $this->assertTrue($summary['weighting']['enabled']);

        // The unweighted pooled figure is untouched beside it: 100 of 130.
        $this->assertFigure(100.0, (float) $summary['points_earned']);
        $this->assertFigure(130.0, (float) $summary['points_possible']);
    }

    #[Test]
    public function a_type_with_no_scored_work_drags_nothing_down(): void
    {
        // Renormalised over the work that HAS marks: classwork, homework and
        // other weigh 10 each and nobody has been marked on them.
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Spelling', 10, 'quiz', 9);

        // 20*0.9 / 20 = 90%, not 20*0.9 / 90 = 20%.
        $this->assertFigure(90.0, $this->summary()['weighting']['percent']);
    }

    #[Test]
    public function a_per_work_weight_replaces_its_types_weight_for_that_piece_only(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Unit test', 100, 'test', 80);
        $this->work('Spelling', 10, 'quiz', 10);
        $this->work('Big project', 20, 'homework', 10, weight: 30);

        // (40*0.8 + 20*1.0 + 30*0.5) / (40 + 20 + 30) = 67 / 90 = 74.44 -> 74.4
        $this->assertFigure(74.4, $this->summary()['weighting']['percent']);
    }

    #[Test]
    public function work_with_no_type_is_left_out_and_counted_so_the_screen_can_say_so(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Unit test', 100, 'test', 80);
        $this->work('Old work A', 10, null, 2);
        $this->work('Old work B', 10, null, 3);

        $weighting = $this->summary()['weighting'];

        $this->assertFigure(80.0, $weighting['percent'], 'the untyped 20% and 30% must not pull the figure down');
        $this->assertSame(1, $weighting['points_pieces']);
        $this->assertSame(2, $weighting['untyped_excluded']);
    }

    #[Test]
    public function untyped_work_with_its_own_weight_is_counted_because_it_has_something_to_derive_from(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Unit test', 100, 'test', 80);
        $this->work('Special', 10, null, 5, weight: 40);

        // (40*0.8 + 40*0.5) / 80 = 52 / 80 = 65%
        $weighting = $this->summary()['weighting'];
        $this->assertFigure(65.0, $weighting['percent']);
        $this->assertSame(0, $weighting['untyped_excluded']);
    }

    #[Test]
    public function a_zero_weight_type_counts_for_nothing_and_a_missing_piece_counts_as_zero(): void
    {
        $this->setWeights(['test' => 50, 'quiz' => 0, 'homework' => 50, 'classwork' => 0, 'other' => 0]);

        $this->work('Unit test', 10, 'test', 10);
        $this->work('Reading log', 10, 'homework', null, status: 'missing');
        $this->work('Pop quiz', 10, 'quiz', 0);

        // Test 100% and homework NOT HANDED IN = 0%, equal weight: 50%. The
        // quiz's weight of 0 keeps it out of the figure whatever its mark.
        $this->assertFigure(50.0, $this->summary()['weighting']['percent']);
    }

    #[Test]
    public function an_excused_mark_counts_in_neither_half_of_the_weighted_figure(): void
    {
        $this->setWeights(['test' => 50, 'quiz' => 50, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Unit test', 10, 'test', 10);
        $this->work('Away that day', 10, 'quiz', null, status: 'excused');

        $weighting = $this->summary()['weighting'];

        $this->assertFigure(100.0, $weighting['percent']);
        $this->assertSame(1, $weighting['points_pieces']);
    }

    #[Test]
    public function levels_work_gets_a_weighted_mean_level_and_never_a_percentage(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->levelsWork('Reading rubric', 'test', 4);
        $this->levelsWork('Writing rubric', 'quiz', 2);
        // Points work beside it, which must not leak into the levels figure.
        $this->work('Spelling', 10, 'quiz', 10);

        $weighting = $this->summary()['weighting'];

        // (40*4 + 20*2) / 60 = 200 / 60 = 3.33 -> 3.3
        $this->assertFigure(3.3, $weighting['level_mean']);
        $this->assertSame(2, $weighting['level_pieces']);
        $this->assertNotNull($weighting['level_mean_label']);
        // The points figure is the points work alone: one quiz at 100%.
        $this->assertFigure(100.0, $weighting['percent']);
        $this->assertSame(1, $weighting['points_pieces']);
    }

    #[Test]
    public function a_missing_levels_piece_stays_out_of_the_mean_and_is_not_called_untyped(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->levelsWork('Reading rubric', 'test', 4);
        $this->levelsWork('Not handed in', null, null, status: 'missing');

        $weighting = $this->summary()['weighting'];

        $this->assertFigure(4.0, $weighting['level_mean']);
        $this->assertSame(0, $weighting['untyped_excluded'], 'a missing piece is not in a levels mean whatever its type');
    }

    #[Test]
    public function simple_marks_are_never_averaged_or_weighted(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $work = ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => 'Recitation',
            'points_possible' => 3, 'scale' => ClassAssignment::SCALE_SIMPLE, 'type' => 'test',
            'assigned_on' => now()->toDateString(),
        ]);
        $this->mark($work, 'scored', 3);

        $summary = $this->summary();

        $this->assertNull($summary['weighting']['percent']);
        $this->assertNull($summary['weighting']['level_mean']);
        $this->assertSame(0, $summary['weighting']['points_pieces'] + $summary['weighting']['level_pieces']);
        $this->assertSame(1, $summary['simple']['counted'], 'the words are still counted, as before');
    }

    #[Test]
    public function withdrawn_work_leaves_the_weighted_figure(): void
    {
        $this->setWeights(['test' => 50, 'quiz' => 50, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Unit test', 10, 'test', 10);
        $withdrawn = $this->work('Bad quiz', 10, 'quiz', 0);

        $this->assertFigure(50.0, $this->summary()['weighting']['percent']);

        $withdrawn->delete();

        $this->assertFigure(100.0, $this->summary()['weighting']['percent']);
    }

    #[Test]
    public function the_per_type_block_is_there_with_or_without_weights(): void
    {
        $this->work('Unit test', 100, 'test', 80);
        $this->work('Spelling', 10, 'quiz', 5);

        $byType = collect($this->summary()['weighting']['by_type'])->keyBy('type');

        $this->assertFigure(80.0, $byType['test']['percent']);
        $this->assertNull($byType['test']['weight'], 'an unweighted class has no weight to show');
        $this->assertFigure(50.0, $byType['quiz']['percent']);

        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $byType = collect($this->summary()['weighting']['by_type'])->keyBy('type');
        $this->assertSame(40, $byType['test']['weight']);
        $this->assertSame(20, $byType['quiz']['weight']);
    }

    // ------------------------------------------------ a TYPE is one slot in the average

    #[Test]
    public function a_type_counts_once_however_many_pieces_are_in_it(): void
    {
        // The owner's model: "weights are per type ... renormalised over the types
        // that have scored work". Test 40 and Homework 10 make the Tests four
        // fifths of the grade, whatever the child has done more of.
        $this->setWeights(['test' => 40, 'quiz' => 10, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Unit test', 100, 'test', 90);
        foreach (range(1, 5) as $i) {
            $this->work("Homework {$i}", 10, 'homework', 10);
        }

        // 0.9 * 40/50 + 1.0 * 10/50 = 92.0. Per piece it would be
        // (40*0.9 + 5*10*1.0) / 90 = 95.6, with Homework 5/9 of the grade.
        $weighting = $this->summary()['weighting'];
        $this->assertFigure(92.0, $weighting['percent']);
        $this->assertSame(6, $weighting['points_pieces'], 'every counted piece is still in the count');

        // Five more Homework change nothing: the type is one slot.
        foreach (range(6, 10) as $i) {
            $this->work("Homework {$i}", 10, 'homework', 10);
        }
        $this->assertFigure(92.0, $this->summary()['weighting']['percent']);
    }

    #[Test]
    public function the_headline_can_be_rebuilt_from_the_by_type_rows_a_parent_reads(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Unit test', 100, 'test', 70);
        $this->work('Midterm', 50, 'test', 40);
        $this->work('Spelling', 10, 'quiz', 9);
        $this->work('Reading log', 20, 'homework', 10);
        $this->work('Reading log 2', 10, 'homework', 10);

        $weighting = $this->summary()['weighting'];

        $numerator = 0.0;
        $denominator = 0;
        foreach ($weighting['by_type'] as $row) {
            $numerator += $row['weight'] * $row['percent'];
            $denominator += $row['weight'];
        }

        // test 110/150 = 73.33, quiz 90, homework 20/30 = 66.67:
        // (40*73.33 + 20*90 + 10*66.67) / 70 = 77.14.
        $this->assertFigure(77.1, $weighting['percent']);
        $this->assertEqualsWithDelta($weighting['percent'], $numerator / $denominator, 0.1, 'weight x percent over the by-type rows IS the headline');
    }

    #[Test]
    public function pieces_inside_a_type_are_pooled_by_points_not_averaged_as_percentages(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 10, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        // 5 of 10 and 90 of 100, one type: 95 of 110 = 86.4. The mean of the two
        // percentages would be 70, and would not match the by-type row beside it.
        $this->work('Short log', 10, 'homework', 5);
        $this->work('Big project', 100, 'homework', 90);

        $weighting = $this->summary()['weighting'];

        $this->assertFigure(86.4, $weighting['percent']);
        $this->assertFigure(86.4, collect($weighting['by_type'])->firstWhere('type', 'homework')['percent']);
    }

    #[Test]
    public function a_piece_with_its_own_weight_is_a_slot_of_its_own_beside_its_types_pool(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Unit test', 100, 'test', 80);
        $this->work('Reading log', 10, 'homework', 10);
        $this->work('Spelling log', 10, 'homework', 0);
        $this->work('Big project', 20, 'homework', 10, weight: 30);

        $weighting = $this->summary()['weighting'];

        // test 80% x 40, the homework pool 10/20 = 50% x 10, the project 50% x 30:
        // (32 + 5 + 15) / 80 = 65.0.
        $this->assertFigure(65.0, $weighting['percent']);
        $this->assertSame(4, $weighting['points_pieces']);

        // The by-type row is the type's slot, so the project is not in it.
        $homework = collect($weighting['by_type'])->firstWhere('type', 'homework');
        $this->assertSame(2, $homework['pieces']);
        $this->assertFigure(50.0, $homework['percent']);
    }

    #[Test]
    public function a_per_work_weight_of_zero_keeps_that_piece_out_of_the_figure_and_out_of_the_pieces_it_is_across(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 10, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Unit test', 10, 'test', 10);
        // A poor mark the teacher keeps out of the average: an override of 0 is a
        // decision, not "no override" (0 is falsy and must not fall back to the
        // type's 10).
        $this->work('Optional extra', 10, 'homework', 0, weight: 0);

        $weighting = $this->summary()['weighting'];

        $this->assertFigure(100.0, $weighting['percent']);
        $this->assertSame(0, $weighting['untyped_excluded'], 'a weight of 0 is a weight');
        // "Across N pieces" counts the pieces that shaped the figure. This one shaped nothing (review,
        // optional fold; it used to be counted, which said 2 for a figure built from 1).
        $this->assertSame(1, $weighting['points_pieces']);
    }

    #[Test]
    public function a_slot_of_weight_zero_is_not_among_the_pieces_the_figure_is_across(): void
    {
        // A type set to 0 (Homework) and a piece given 0 of its own: neither moves the percentage.
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 0, 'classwork' => 10, 'other' => 10]);
        $this->work('Unit test', 10, 'test', 8);
        $this->work('Quiz', 10, 'quiz', 6);
        foreach (range(1, 3) as $i) {
            $this->work("Homework {$i}", 10, 'homework', 0);
        }
        $this->work('Extra credit', 10, 'classwork', 0, weight: 0);

        $weighting = $this->summary()['weighting'];

        // (40 x 0.8 + 20 x 0.6) / 60 = 73.3, and only the two pieces that made it are counted.
        $this->assertFigure(73.3, $weighting['percent']);
        $this->assertSame(2, $weighting['points_pieces'], 'three Homework at weight 0 and one piece at 0 shaped nothing');

        // Nothing but weight-zero work: no figure, and no pieces it could be "across".
        $only = ClassAssignment::query()->where('group_id', $this->class->id);
        $only->delete();
        $this->setWeights(['test' => 50, 'quiz' => 0, 'homework' => 50, 'classwork' => 0, 'other' => 0]);
        $this->work('Pop quiz', 10, 'quiz', 9);
        $this->work('Extra', 10, 'classwork', 9);

        $zero = $this->summary()['weighting'];
        $this->assertNull($zero['percent']);
        $this->assertSame(0, $zero['points_pieces'], 'a subject of weight-zero work no longer reads "across 2 pieces" beside no figure');
        $this->assertSame(0, $zero['untyped_excluded']);
    }

    #[Test]
    public function a_levels_slot_of_weight_zero_is_not_counted_in_level_pieces_either(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 0, 'homework' => 10, 'classwork' => 10, 'other' => 10]);
        $this->levelsWork('Rubric A', 'test', 3);
        $this->levelsWork('Rubric B', 'quiz', 1);

        $weighting = $this->summary()['weighting'];

        $this->assertFigure(3.0, $weighting['level_mean']);
        $this->assertSame(1, $weighting['level_pieces']);
    }

    #[Test]
    public function levels_pieces_in_one_type_average_first_and_then_weigh_by_type(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->levelsWork('Rubric A', 'test', 4);
        $this->levelsWork('Rubric B', 'test', 2);
        $this->levelsWork('Rubric C', 'quiz', 1);

        // test slot mean 3 x 40, quiz slot 1 x 20: (120 + 20) / 60 = 2.3.
        // Per piece it would be (160 + 80 + 20) / 100 = 2.6.
        $weighting = $this->summary()['weighting'];
        $this->assertFigure(2.3, $weighting['level_mean']);
        $this->assertSame(3, $weighting['level_pieces']);
    }

    #[Test]
    public function a_typed_missing_levels_piece_never_enters_the_level_mean(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->levelsWork('Reading rubric', 'quiz', 4);
        // A missing rubric carries a type and so has a weight to be averaged in
        // with; 1 is "Needs Support", a judgement nobody made.
        $this->levelsWork('Not handed in', 'test', null, status: 'missing');

        $weighting = $this->summary()['weighting'];

        $this->assertFigure(4.0, $weighting['level_mean']);
        $this->assertSame(1, $weighting['level_pieces']);
        $this->assertSame(0, $weighting['untyped_excluded']);
    }

    #[Test]
    public function excused_and_missing_work_is_handled_the_same_way_in_the_by_subject_and_by_type_blocks(): void
    {
        // Unweighted on purpose: both blocks exist whether or not the class is
        // weighted, and the parent screen prints both.
        $this->work('Quiz A', 10, 'quiz', null, status: 'excused', subject: 'Reading');
        $this->work('Quiz B', 10, 'quiz', 9, subject: 'Reading');
        $this->work('Quiz C', 10, 'quiz', null, status: 'missing', subject: 'Reading');
        $this->levelsWork('Rubric 1', null, 3, subject: 'Reading');
        $this->levelsWork('Rubric 2', null, null, status: 'missing', subject: 'Reading');

        $summary = $this->summary();
        $reading = collect($summary['by_subject'])->firstWhere('subject', 'Reading');

        // Five marks; four count (the excused one counts in neither half).
        $this->assertSame(5, $reading['recorded']);
        $this->assertSame(4, $reading['counted']);
        $this->assertSame(1, $reading['excused']);
        // Quiz B 9 of 10 and Quiz C not handed in 0 of 10: 9 of 20, never 9 of 30.
        $this->assertFigure(9.0, (float) $reading['points_earned']);
        $this->assertFigure(20.0, (float) $reading['points_possible']);
        $this->assertSame(2, $reading['points_counted']);
        $this->assertFigure(45.0, $reading['percent']);
        // Levels: the one scored rubric only; the missing one is no level at all.
        $this->assertSame(1, $reading['levels_counted']);
        $this->assertFigure(3.0, $reading['level_mean']);

        // By type: the same two counted quizzes, 9 of 20.
        $quiz = collect($summary['weighting']['by_type'])->firstWhere('type', 'quiz');
        $this->assertSame(2, $quiz['pieces'], 'the excused quiz is not a piece of the quiz row');
        $this->assertFigure(45.0, $quiz['percent']);
    }

    #[Test]
    public function withdrawn_levels_work_leaves_the_levels_block(): void
    {
        $this->levelsWork('Rubric A', 'test', 4);
        $withdrawn = $this->levelsWork('Rubric B', 'test', 1);

        $levels = $this->summary()['levels'];
        $this->assertSame(2, $levels['counted']);
        $this->assertFigure(2.5, $levels['mean']);

        $withdrawn->delete();

        $levels = $this->summary()['levels'];
        $this->assertSame(1, $levels['counted']);
        $this->assertSame(1, $levels['recorded']);
        $this->assertFigure(4.0, $levels['mean']);
        $counts = collect($levels['distribution'])->pluck('count', 'level')->all();
        $this->assertSame(0, $counts[1], 'the withdrawn 1 is gone from the distribution');
        $this->assertSame(1, $counts[4]);
    }

    // ------------------------------------------------------ one class is not another

    #[Test]
    public function weights_belong_to_one_class_and_reading_or_clearing_never_crosses_into_another(): void
    {
        [$b, $childB] = $this->classWithChild('Grade 6', 'g6');
        [$c, $childC] = $this->classWithChild('Grade 7', 'g7');

        // Class A (this one) and class B are both weighted, differently; B's are
        // written LAST so a read that forgot which class it was in would answer B.
        $this->setWeights(['test' => 40, 'quiz' => 10, 'homework' => 10, 'classwork' => 10, 'other' => 10]);
        $this->putJson("/api/teacher/masjids/{$this->school->id}/groups/{$b->id}/grade-weights", [
            'weights' => ['test' => 10, 'quiz' => 40, 'homework' => 10, 'classwork' => 10, 'other' => 10],
        ])->assertOk();

        // The same two marks in all three classes: a test 100%, a quiz 0%.
        foreach ([[$this->class, $this->child], [$b, $childB], [$c, $childC]] as [$class, $child]) {
            $this->markedWork($class, $child, 'Unit test', 10, 'test', 10);
            $this->markedWork($class, $child, 'Pop quiz', 10, 'quiz', 0);
        }

        $this->assertEquals(
            ['test' => 40, 'quiz' => 10, 'homework' => 10, 'classwork' => 10, 'other' => 10],
            ClassGradeWeight::forGroup($this->class->id)
        );
        $this->assertEquals(['test' => 10, 'quiz' => 40], array_intersect_key(ClassGradeWeight::forGroup($b->id), ['test' => 1, 'quiz' => 1]));
        $this->assertSame([], ClassGradeWeight::forGroup($c->id), 'an unweighted class reads as unweighted while its neighbours are weighted');

        // (40*1 + 10*0) / 50 = 80 in A; (10*1 + 40*0) / 50 = 20 in B.
        $this->assertFigure(80.0, $this->summaryOf($this->class, $this->child)['weighting']['percent']);
        $this->assertFigure(20.0, $this->summaryOf($b, $childB)['weighting']['percent']);
        $unweighted = $this->summaryOf($c, $childC)['weighting'];
        $this->assertFalse($unweighted['enabled']);
        $this->assertNull($unweighted['percent']);

        // Clearing A leaves B exactly as it was.
        $this->putJson($this->url('/grade-weights'), ['clear' => true])->assertOk();

        $this->assertSame([], ClassGradeWeight::forGroup($this->class->id));
        $this->assertSame(5, ClassGradeWeight::query()->where('group_id', $b->id)->count(), "clearing one class's weights is that class's only");
        $this->assertFigure(20.0, $this->summaryOf($b, $childB)['weighting']['percent']);
    }

    // --------------------------------------------------------------- by subject

    #[Test]
    public function marks_group_by_subject_and_two_spellings_of_a_subject_are_one_block(): void
    {
        $this->work('Alif to Yaa', 10, 'quiz', 8, subject: "Qur'an");
        $this->work('Surah Fatiha', 10, 'test', 6, subject: 'Qur’an');
        $this->work('Fractions', 10, 'test', 5, subject: 'Mathematics');

        $blocks = collect($this->summary()['by_subject'])->keyBy('subject');

        $this->assertCount(2, $blocks);
        // 8 + 6 of 20, the two spellings pooled into the first spelling met.
        $quran = $blocks->first(fn ($b) => str_contains(mb_strtolower($b['subject']), 'ur'));
        $this->assertFigure(14.0, (float) $quran['points_earned']);
        $this->assertFigure(20.0, (float) $quran['points_possible']);
        $this->assertFigure(70.0, $quran['percent']);
        $this->assertFigure(50.0, $blocks['Mathematics']['percent']);
    }

    #[Test]
    public function a_subject_block_uses_the_classs_weights_not_its_own(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 10, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->work('Unit test', 10, 'test', 10, subject: 'Mathematics');
        $this->work('Pop quiz', 10, 'quiz', 0, subject: 'Mathematics');
        $this->work('Spelling', 10, 'quiz', 10, subject: 'Science');

        $blocks = collect($this->summary()['by_subject'])->keyBy('subject');

        // (40*1.0 + 10*0.0) / 50 = 80%
        $this->assertFigure(80.0, $blocks['Mathematics']['weighted_percent']);
        $this->assertFigure(100.0, $blocks['Science']['weighted_percent']);
    }

    #[Test]
    public function work_with_no_subject_anywhere_produces_no_subject_blocks(): void
    {
        $this->work('Old work', 10, null, 5);

        $this->assertSame([], $this->summary()['by_subject']);

        // Once any piece names a subject, the unnamed work gets a block of its
        // own, last, rather than vanishing from the picture.
        $this->work('Fractions', 10, null, 5, subject: 'Mathematics');

        $blocks = $this->summary()['by_subject'];
        $this->assertCount(2, $blocks);
        $this->assertSame('Mathematics', $blocks[0]['subject']);
        $this->assertNull($blocks[1]['subject']);
    }

    // ------------------------------------------------------- setting the weights

    #[Test]
    public function weights_are_all_five_types_or_none(): void
    {
        $this->putJson($this->url('/grade-weights'), ['weights' => ['test' => 40, 'quiz' => 20]])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->putJson($this->url('/grade-weights'), ['weights' => [
            'test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10, 'bonus' => 5,
        ]])->assertStatus(422);

        $this->assertSame(0, ClassGradeWeight::query()->count(), 'a refused set writes nothing at all');
    }

    #[Test]
    public function a_weight_is_a_whole_number_from_zero_to_a_hundred_and_not_all_zero(): void
    {
        $base = ['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10];

        foreach ([101, -1, 'lots', 2.5] as $bad) {
            $this->putJson($this->url('/grade-weights'), ['weights' => ['test' => $bad] + $base])
                ->assertStatus(422);
        }

        $this->putJson($this->url('/grade-weights'), ['weights' => array_fill_keys(array_keys($base), 0)])
            ->assertStatus(422);

        $this->assertSame(0, ClassGradeWeight::query()->count());

        // The edges themselves are fine.
        $this->putJson($this->url('/grade-weights'), ['weights' => ['test' => 100] + array_fill_keys(['quiz', 'homework', 'classwork', 'other'], 0)])
            ->assertOk();
    }

    #[Test]
    public function saving_weights_twice_replaces_them_and_records_who_set_them(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);
        $this->setWeights(['test' => 50, 'quiz' => 25, 'homework' => 15, 'classwork' => 5, 'other' => 5]);

        $this->assertSame(5, ClassGradeWeight::query()->count());
        $this->assertSame(50, ClassGradeWeight::forGroup($this->class->id)['test']);
        $this->assertSame(
            [$this->teacher->id],
            ClassGradeWeight::query()->pluck('updated_by_user_id')->unique()->values()->all()
        );
    }

    #[Test]
    public function weights_arrive_in_the_clients_own_form_encoded_encoding(): void
    {
        // The SPA's global axios Content-Type is form-urlencoded. A test that only
        // ever uses postJson passes with the encoding the browser never sends.
        $this->put($this->url('/grade-weights'), [
            'weights' => ['test' => '40', 'quiz' => '20', 'homework' => '10', 'classwork' => '10', 'other' => '10'],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(40, ClassGradeWeight::forGroup($this->class->id)['test']);

        $this->work('Unit test', 10, 'test', 10);
        $this->work('Reading', 10, 'homework', 5, weight: 20);

        // "true" as a string, which Laravel's own `boolean` rule would refuse.
        $this->put($this->url('/grade-weights'), ['clear' => 'true'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.weighting_enabled', false)
            ->assertJsonPath('data.cleared_overrides', 1);

        $this->assertSame([], ClassGradeWeight::forGroup($this->class->id));
    }

    #[Test]
    public function clearing_the_weights_also_clears_every_per_work_override_in_the_class(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);
        $piece = $this->work('Big project', 20, 'homework', 10, weight: 30);
        $other = $this->otherClass();
        $foreign = ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $other->id, 'title' => 'Elsewhere',
            'points_possible' => 10, 'scale' => 'points', 'weight' => 77, 'assigned_on' => now()->toDateString(),
        ]);

        $this->putJson($this->url('/grade-weights'), ['clear' => true])
            ->assertOk()
            ->assertJsonPath('data.cleared_overrides', 1);

        $this->assertNull($piece->fresh()->weight, 'a dormant override would come back to life the day weights were re-enabled');
        $this->assertSame(77, $foreign->fresh()->weight, "another class's override is not this class's to clear");
    }

    #[Test]
    public function a_clear_request_may_not_also_carry_weights(): void
    {
        $this->putJson($this->url('/grade-weights'), [
            'clear' => true,
            'weights' => ['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10],
        ])->assertStatus(422);
    }

    #[Test]
    public function a_weight_on_one_piece_of_work_needs_a_weighted_class(): void
    {
        $this->postJson($this->url('/assignments'), [
            'title' => 'Project', 'points_possible' => 10, 'scale' => 'points',
            'assigned_on' => now()->toDateString(), 'type' => 'homework', 'weight' => 30,
        ])->assertStatus(422)->assertJsonPath('data.weight.0', "Set this class's weights first, then you can change the weight of one piece of work.");

        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $this->postJson($this->url('/assignments'), [
            'title' => 'Project', 'points_possible' => 10, 'scale' => 'points',
            'assigned_on' => now()->toDateString(), 'type' => 'homework', 'weight' => 30,
        ])->assertCreated()->assertJsonPath('data.weight', 30);
    }

    #[Test]
    public function the_assignments_list_serves_the_types_and_the_classs_weights(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        $body = $this->getJson($this->url('/assignments'))->assertOk()->json();

        $this->assertTrue($body['weighting_enabled']);
        $this->assertSame(40, $body['weights']['test']);
        $this->assertSame(100, $body['weight_max']);
        $this->assertSame(
            ['test', 'quiz', 'homework', 'classwork', 'other'],
            array_column($body['types'], 'key')
        );
        $this->assertSame('Homework', collect($body['types'])->firstWhere('key', 'homework')['label']);
    }

    // ------------------------------------------------------------ who may set them

    #[Test]
    public function a_teacher_who_does_not_lead_the_class_cannot_set_its_weights(): void
    {
        $notMine = $this->otherClass();

        $this->putJson("/api/teacher/masjids/{$this->school->id}/groups/{$notMine->id}/grade-weights", [
            'weights' => ['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10],
        ])->assertForbidden();

        $this->assertSame(0, ClassGradeWeight::query()->count());
    }

    #[Test]
    public function weights_have_exactly_two_doors_the_teachers_and_the_offices_and_the_office_can_use_its_own(): void
    {
        // This used to pin the ABSENCE of an office door ("weights are a teacher policy; the office reads them").
        // The rule moved (DECISIONS 2026-09-29, office route for grade weights): a class whose teachers are all
        // limited to some subjects could otherwise never set weights. It still pins WHICH doors exist, so a third
        // one, or a second office verb, is a decision and not a slip.
        $doors = [];
        $officeMiddleware = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $route) {
            if (! str_ends_with($route->uri(), 'grade-weights')) {
                continue;
            }
            foreach ($route->methods() as $verb) {
                if ($verb !== 'HEAD') {
                    $doors[] = $verb.' '.$route->uri();
                }
            }
            if (str_starts_with($route->uri(), 'api/admin')) {
                $officeMiddleware = $route->gatherMiddleware();
            }
        }
        sort($doors);

        $this->assertSame([
            'PUT api/admin/masjids/{masjid_id}/groups/{group_id}/grade-weights',
            'PUT api/teacher/masjids/{masjid_id}/groups/{group_id}/grade-weights',
        ], $doors);
        $this->assertContains('permission:manage contacts', $officeMiddleware, 'the office door is gated by the roster-writing permission, and mints no new one');

        // And it works for the office: the same rows the teacher's route writes, stamped with the office user.
        $office = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->school->user_id = $office->id;
        $this->school->save();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        app(\App\Support\TenantContext::class)->forgetTenant();
        Sanctum::actingAs($office);

        $this->putJson("/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/grade-weights", [
            'weights' => ['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10],
        ])->assertOk()->assertJsonPath('data.weighting_enabled', true);

        $this->assertSame(5, ClassGradeWeight::withoutMasjidScope()->where('group_id', $this->class->id)->count());
        $this->assertSame($office->id, (int) ClassGradeWeight::withoutMasjidScope()->where('group_id', $this->class->id)->value('updated_by_user_id'));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * A figure equals its expected value as a NUMBER. Laravel encodes 80.0 as
     * `80`, so the client (and this test) reads an integer back; comparing with
     * assertSame would fail on the encoding and say nothing about the sum.
     */
    private function assertFigure(float $expected, mixed $actual, string $message = ''): void
    {
        $this->assertNotNull($actual, $message ?: 'the figure is missing');
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.0001, $message);
    }

    private function otherClass(): Group
    {
        return Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 5', 'slug' => 'g5']);
    }

    /**
     * A second class in the same school, this teacher leading it, with one child.
     *
     * @return array{0: Group, 1: GroupMembership}
     */
    private function classWithChild(string $name, string $slug): array
    {
        $class = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => $name, 'slug' => $slug]);
        $class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $contact = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Child '.$slug, 'last_name' => 'Test']);

        return [$class, GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $class->id,
            'contact_id' => $contact->id, 'role' => GroupMembership::ROLE_MEMBER,
        ])];
    }

    private function markedWork(Group $class, GroupMembership $child, string $title, int $outOf, ?string $type, int|float $points): void
    {
        $work = ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $class->id, 'title' => $title,
            'points_possible' => $outOf, 'scale' => ClassAssignment::SCALE_POINTS, 'type' => $type,
            'assigned_on' => now()->toDateString(),
        ]);

        AssignmentScore::create([
            'masjid_id' => $this->school->id, 'group_id' => $class->id,
            'class_assignment_id' => $work->id, 'group_membership_id' => $child->id,
            'status' => 'scored', 'points_earned' => $points,
        ]);
    }

    /** @return array<string,mixed> */
    private function summaryOf(Group $class, GroupMembership $child): array
    {
        return $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$class->id}/members/{$child->id}/grades")
            ->assertOk()->json('data.summary');
    }

    /** @param array<string,int> $weights */
    private function setWeights(array $weights): void
    {
        $this->putJson($this->url('/grade-weights'), ['weights' => $weights])->assertOk();
    }

    /** @return array<string,mixed> the summary the teacher's realm serves for the child */
    private function summary(): array
    {
        return $this->getJson($this->gradesUrl())->assertOk()->json('data.summary');
    }

    private function work(
        string $title,
        int $outOf,
        ?string $type,
        int|float|null $points,
        ?int $weight = null,
        string $status = 'scored',
        ?string $subject = null,
    ): ClassAssignment {
        $work = ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => $title,
            'points_possible' => $outOf, 'scale' => ClassAssignment::SCALE_POINTS,
            'type' => $type, 'weight' => $weight, 'subject' => $subject,
            'assigned_on' => now()->toDateString(),
        ]);

        $this->mark($work, $status, $points);

        return $work;
    }

    private function levelsWork(string $title, ?string $type, int|float|null $level = null, string $status = 'scored', ?string $subject = null): ClassAssignment
    {
        $work = ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => $title,
            'points_possible' => \App\Support\PerformanceLevel::MAX, 'scale' => ClassAssignment::SCALE_LEVELS,
            'type' => $type, 'subject' => $subject, 'assigned_on' => now()->toDateString(),
        ]);

        $this->mark($work, $status, $level);

        return $work;
    }

    private function mark(ClassAssignment $work, string $status, int|float|null $points): AssignmentScore
    {
        return AssignmentScore::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'class_assignment_id' => $work->id, 'group_membership_id' => $this->child->id,
            'status' => $status, 'points_earned' => $points,
        ]);
    }

    private function url(string $path): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}".$path;
    }

    private function gradesUrl(): string
    {
        return $this->url("/members/{$this->child->id}/grades");
    }
}
