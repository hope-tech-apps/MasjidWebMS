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
    public function the_weighted_average_is_the_weighted_mean_of_each_pieces_own_percentage(): void
    {
        $this->setWeights(['test' => 40, 'quiz' => 20, 'homework' => 10, 'classwork' => 10, 'other' => 10]);

        // Test 80/100 = 80%, quiz 10/10 = 100%, homework 10/20 = 50%.
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
    public function the_office_has_no_route_to_set_weights(): void
    {
        $verbs = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $route) {
            if (str_ends_with($route->uri(), 'grade-weights') && str_starts_with($route->uri(), 'api/admin')) {
                $verbs = array_merge($verbs, $route->methods());
            }
        }

        $this->assertSame([], $verbs, 'weights are a teacher policy; the office reads them and does not set them');
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

    private function levelsWork(string $title, ?string $type, int|float|null $level = null, string $status = 'scored'): ClassAssignment
    {
        $work = ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'title' => $title,
            'points_possible' => \App\Support\PerformanceLevel::MAX, 'scale' => ClassAssignment::SCALE_LEVELS,
            'type' => $type, 'assigned_on' => now()->toDateString(),
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
