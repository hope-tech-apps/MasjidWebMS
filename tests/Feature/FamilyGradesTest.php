<?php

namespace Tests\Feature;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-041j — a parent finally reads the marks the platform was already emailing
 * them about.
 *
 * ---------------------------------------------------------------------------
 * THE DEFECT THIS FILE CLOSES
 * ---------------------------------------------------------------------------
 *
 * `Teacher\GradebookController::announceMarks()` mails a child's guardians the
 * moment a mark is saved, and the only link in that mail is the family sign-in
 * page. Before this endpoint the parent who followed it arrived in a portal with
 * report cards, awards, ḥifẓ and letters — and nowhere to read the thing they
 * had just been told about. So the first guarantee below is simply that the
 * screen the mail promises now exists and answers.
 *
 * ---------------------------------------------------------------------------
 * THE FIXTURE IS THE TEST
 * ---------------------------------------------------------------------------
 *
 * ONE classroom, TWO families, marked on the SAME assignments — which is the
 * shape of a weekend school and the exact configuration `.claude/rules/groups.md`
 * says "guardian here never meant guardian of this child" about. A suite giving
 * each parent their own class would pass with the audience rule deleted, because
 * there would be nothing to leak. Sharing the assignments matters too: it is
 * what makes an id-keyed mistake (a translation key, a `whereIn`, a cache) able
 * to cross between the two children rather than merely being able to in theory.
 *
 * The teacher is a real User in the teacher realm rather than a roster row,
 * because one guarantee here spans both realms: the parent's summary and the
 * teacher's summary of the same child must be the same arithmetic. Until
 * `App\Support\GradeRecord` exists the two controllers compute it separately,
 * and this file is what stops the two copies drifting into two different
 * averages for one child.
 */
class FamilyGradesTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Group $class;
    private User $teacher;

    private Contact $parentA;
    private GroupMembership $childA;

    private Contact $parentB;
    private GroupMembership $childB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeSchool();

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        // ---- family A ----
        $this->childA = $this->enrol($this->class, 'Amina');
        $this->parentA = $this->guardianOf($this->class, $this->childA);

        // ---- family B, in the SAME classroom, marked on the SAME work ----
        $this->childB = $this->enrol($this->class, 'Bilal');
        $this->parentB = $this->guardianOf($this->class, $this->childB);

        // ---- the teacher who keeps the gradebook ----
        $this->teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id,
            'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
    }

    // ------------------------------------------------- the promise, kept

    #[Test]
    public function a_parent_reads_their_own_childs_marks(): void
    {
        $spelling = $this->pointsWork('Spelling week 2', 10);
        $this->mark($spelling, $this->childA, 'scored', 8, 'Lovely handwriting.');

        $data = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json();

        $this->assertSame('success', $data['status']);
        $this->assertSame('Amina', $data['data']['student']['contact']['first_name']);

        $this->assertSame(1, $data['data']['summary']['recorded']);
        $this->assertSame(8.0, (float) $data['data']['summary']['points_earned']);
        $this->assertSame(10.0, (float) $data['data']['summary']['points_possible']);

        $score = $data['data']['scores'][0];
        $this->assertSame('Spelling week 2', $score['assignment']['title']);
        $this->assertSame('scored', $score['status']);
        $this->assertSame(8.0, (float) $score['points_earned']);
        $this->assertSame('Lovely handwriting.', $score['note'], "the teacher's own words are the point of the screen");
    }

    /**
     * The envelope every other family endpoint carries, so the portal's chrome
     * (what a group is CALLED here, whether the translate button can work at
     * all) does not have to be guessed on this one screen.
     */
    #[Test]
    public function the_payload_carries_the_family_envelope_and_the_key_to_the_levels(): void
    {
        $this->mark($this->levelsWork('Reading'), $this->childA, 'scored', 3);

        $body = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('group_label', $body['meta']);
        $this->assertArrayHasKey('translation_available', $body['meta']);

        // A SIBLING of `data`, matching the report card and the teacher's copy —
        // the client reads `res.data.performance_levels`.
        $this->assertCount(4, $body['performance_levels']);
        $this->assertSame(4, $body['performance_levels'][0]['level'], 'highest first, as the school lays it out');
        $this->assertSame('Exceeds Expectations', $body['performance_levels'][0]['label']);
        $this->assertArrayNotHasKey('performance_levels', $body['data']);
    }

    // ------------------------------------- what is still the teacher's own

    /**
     * There is no publication flag on a mark — the migration refuses one
     * ("No `scored` / `is_published` / `average` column") and `announceMarks()`
     * has already told the family the moment it was saved. The teacher's working
     * copy is what the schema actually models, and both halves of it are held
     * back by SCOPES rather than by an `if` after the fetch: work the teacher
     * WITHDREW, and a mark they have not entered.
     */
    #[Test]
    public function withdrawn_work_is_absent_from_the_list_and_from_the_average(): void
    {
        $kept = $this->pointsWork('Kept', 10);
        $dropped = $this->pointsWork('Withdrawn quiz', 10);

        $this->mark($kept, $this->childA, 'scored', 7);
        $this->mark($dropped, $this->childA, 'scored', 2, 'We will do this one again.');

        $dropped->delete();

        $data = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['summary']['recorded'], 'withdrawn work must not count');
        $this->assertSame(7.0, (float) $data['summary']['points_earned']);
        $this->assertSame(10.0, (float) $data['summary']['points_possible'], 'nor enlarge the denominator');

        $this->assertCount(1, $data['scores']);
        $this->assertSame('Kept', $data['scores'][0]['assignment']['title']);

        $this->assertSame(2, AssignmentScore::count(), 'but the mark itself is retained, not destroyed');
    }

    /**
     * Not-yet-marked is the ABSENCE of a row, never a status and never a zero.
     * A child their teacher has not reached yet has an empty screen, which is
     * the truth, rather than a nought that reads as a mark.
     */
    #[Test]
    public function work_the_teacher_has_not_marked_yet_is_simply_absent(): void
    {
        $this->pointsWork('Set but unmarked', 10);

        $data = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['summary']['recorded']);
        $this->assertSame([], $data['scores']);
        $this->assertSame(0.0, (float) $data['summary']['points_possible'], 'unmarked work is not a zero out of ten');
        $this->assertFalse($data['scores_truncated']);
    }

    // --------------------------------------------------- the ward edge

    #[Test]
    public function a_parent_cannot_read_the_other_familys_childs_marks(): void
    {
        $work = $this->pointsWork('Spelling week 2', 10);
        $this->mark($work, $this->childB, 'scored', 9, 'Bilal has been working hard on this.');

        $response = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childB))
            ->assertForbidden();

        // BOTH HALVES. The 403 is honest to a parent who mistyped an id; the
        // obligation `.claude/rules/groups.md` adds is that the refusal itself
        // must not become the disclosure. A message naming Bilal, or a body
        // carrying his mark, would answer the question it refused.
        $body = $response->getContent();
        $this->assertStringNotContainsString('Bilal', $body);
        $this->assertStringNotContainsString('working hard', $body);
        $this->assertStringNotContainsString('Spelling week 2', $body);
    }

    /**
     * The other half of the same obligation, on the SUCCESS path: a parent
     * reading their own child must receive nothing of the child sitting beside
     * them, even though both are marked on the same assignments.
     */
    #[Test]
    public function the_payload_carries_no_other_childs_name_or_mark(): void
    {
        $shared = $this->pointsWork('Spelling week 2', 10);
        $this->mark($shared, $this->childA, 'scored', 6, 'Amina: watch the long vowels.');
        $this->mark($shared, $this->childB, 'scored', 10, 'Bilal: full marks again.');

        $response = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame(1, $data['summary']['recorded'], "only Amina's row is counted");
        $this->assertSame(6.0, (float) $data['summary']['points_earned'], "and Bilal's 10 is not in the numerator");
        $this->assertCount(1, $data['scores']);

        $body = $response->getContent();
        $this->assertStringNotContainsString('Bilal', $body);
        $this->assertStringNotContainsString('full marks again', $body);
    }

    /**
     * Invariant 7. Consent gates BROADCASTS of a child's data, not a parent's
     * view of their own child — the same rule awards and ḥifẓ apply, and the
     * reason `subject()` never asks about it. A family whose consent form is
     * still in a folder in the office must not be locked out of their own
     * child's marks.
     */
    #[Test]
    public function a_parent_with_no_consent_on_file_still_reads_their_own_childs_marks(): void
    {
        $this->withdrawConsent($this->parentA);

        $this->mark($this->pointsWork('Spelling', 10), $this->childA, 'scored', 8);

        $summary = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(8.0, (float) $summary['points_earned']);
        $this->assertSame(1, $summary['recorded']);
    }

    #[Test]
    public function a_membership_from_another_class_is_a_404(): void
    {
        $otherClass = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 5',
        ]);
        $stranger = $this->enrol($otherClass, 'Yusuf');

        // Addressed through THIS class, so the membership is not in the group —
        // a 404 rather than a 403, because the id names nothing here.
        $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($stranger))
            ->assertNotFound();
    }

    /**
     * `.claude/rules/tenant-scoping.md` makes a cross-tenant case mandatory for
     * every new endpoint. `FamilyController::group()` is a bare `findOrFail`
     * under `BelongsToMasjid`, so another school's class is a MISS rather than a
     * filtered row — there is no hand-written `where('masjid_id', …)` here that
     * could be forgotten.
     */
    #[Test]
    public function another_schools_class_is_invisible_rather_than_forbidden(): void
    {
        $otherSchool = $this->makeSchool();
        $tenant = app(TenantContext::class);

        [$foreignClass, $foreignChild] = $tenant->runWithout(function () use ($otherSchool) {
            $class = Group::factory()->create([
                'masjid_id' => $otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'Their Grade 3',
            ]);
            $child = Contact::factory()->create([
                'masjid_id' => $otherSchool->id, 'first_name' => 'Someone', 'last_name' => 'Else',
            ]);

            return [$class, GroupMembership::create([
                'masjid_id' => $otherSchool->id, 'group_id' => $class->id,
                'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
            ])];
        });

        $this->asParent($this->parentA)
            ->getJson("/api/family/masjids/{$this->school->id}/groups/{$foreignClass->id}"
                . "/members/{$foreignChild->id}/grades")
            ->assertNotFound();
    }

    // ------------------------------------------------- the arithmetic

    /**
     * `excused` counts in NEITHER half — the office accepted the absence, and
     * dividing by it would punish a family that did the right thing. `missing`
     * scores zero and counts in FULL, because work not handed in is work not
     * done. Three statuses because they are three different sentences.
     */
    #[Test]
    public function an_excused_mark_counts_in_neither_half_and_a_missing_one_counts_in_full(): void
    {
        $this->mark($this->pointsWork('Done', 10), $this->childA, 'scored', 8);
        $this->mark($this->pointsWork('Off sick', 10), $this->childA, 'excused');
        $this->mark($this->pointsWork('Not handed in', 10), $this->childA, 'missing');

        $summary = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(3, $summary['recorded']);
        $this->assertSame(2, $summary['counted'], 'the excused piece is recorded and not counted');
        $this->assertSame(1, $summary['excused']);
        $this->assertSame(8.0, (float) $summary['points_earned']);
        $this->assertSame(20.0, (float) $summary['points_possible'],
            'the missing work enlarges the denominator and the excused work does not');
    }

    /**
     * A `missing` row must reach the client as a WORD with a null number beside
     * it, never as a 0 the screen could print. It is a zero inside the average
     * and a sentence on the page, and the payload has to keep those apart —
     * a `points_earned` of 0 here would be indistinguishable from a child who
     * handed in an empty sheet.
     */
    #[Test]
    public function a_missing_mark_arrives_as_a_status_and_never_as_a_zero(): void
    {
        $this->mark($this->pointsWork('Not handed in', 10), $this->childA, 'missing');

        $score = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json('data.scores.0');

        $this->assertSame('missing', $score['status']);
        $this->assertNull($score['points_earned']);
    }

    /**
     * THE POINT OF THE WHOLE SCALE. A child who "Meets Expectations" on every
     * criterion is at level 3; rendered as 3/4 that becomes 75%, which turns
     * solid grade-level proficiency into a C on the one screen a parent is most
     * likely to keep. The levels half reports a mean level and a distribution,
     * and the points half stays empty because no points work exists.
     */
    #[Test]
    public function a_levels_mark_is_never_reported_as_a_percentage(): void
    {
        $this->mark($this->levelsWork('Reading'), $this->childA, 'scored', 3);
        $this->mark($this->levelsWork('Writing'), $this->childA, 'scored', 3);
        $this->mark($this->levelsWork('Math'), $this->childA, 'scored', 4);
        $this->mark($this->levelsWork('Science'), $this->childA, 'scored', 2);

        $summary = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(3.0, (float) $summary['levels']['mean'], '(3+3+4+2)/4');
        $this->assertSame('Meets', $summary['levels']['mean_label'], 'the word travels with the number');
        $this->assertSame(4, $summary['levels']['counted']);

        $this->assertSame(0.0, (float) $summary['points_earned'], 'a level must never reach the numerator');
        $this->assertSame(0.0, (float) $summary['points_possible'], 'nor the denominator');
        $this->assertSame(0, $summary['points_counted']);

        $byLevel = collect($summary['levels']['distribution'])->keyBy('level');
        $this->assertSame(1, $byLevel[4]['count']);
        $this->assertSame(2, $byLevel[3]['count']);
        $this->assertSame(1, $byLevel[2]['count']);
        $this->assertSame(0, $byLevel[1]['count'], 'every level is present even at zero');
    }

    /**
     * A class can hold both kinds of work at once — a spelling quiz out of 10
     * and a rubric marked 1-4 — and adding those denominators together produces
     * a figure that is wrong in a way nobody can see.
     */
    #[Test]
    public function points_work_and_levels_work_are_summarised_separately(): void
    {
        $this->mark($this->pointsWork('Spelling', 10), $this->childA, 'scored', 8);
        $this->mark($this->levelsWork('Reading'), $this->childA, 'scored', 4);

        $summary = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(8.0, (float) $summary['points_earned']);
        $this->assertSame(10.0, (float) $summary['points_possible'], 'the level must not enlarge the denominator');
        $this->assertSame(4.0, (float) $summary['levels']['mean']);
        $this->assertSame(2, $summary['recorded'], 'both are still counted as recorded work');
    }

    /**
     * The average is over the WHOLE TERM, not over the page of marks under it.
     * The teacher's copy of this query carries the incident that made it so — a
     * `limit()` with no ordering, so past the page size the average came from an
     * arbitrary subset — and this is the surface where the parent actually is.
     */
    #[Test]
    public function the_term_average_is_aggregated_over_every_mark_not_over_the_page(): void
    {
        config(['groups.records_page_size' => 2]);

        foreach (range(1, 5) as $i) {
            $this->mark($this->pointsWork("Week {$i}", 10), $this->childA, 'scored', 6);
        }

        $data = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json('data');

        $this->assertSame(5, $data['summary']['recorded'], 'the term, not the page');
        $this->assertSame(30.0, (float) $data['summary']['points_earned'], '5 marks of 6, not 2');
        $this->assertSame(50.0, (float) $data['summary']['points_possible']);

        $this->assertSame(2, $data['scores_shown']);
        $this->assertTrue($data['scores_truncated'], 'a short list under a full average must say so');
    }

    // ------------------------------------------- what may never be here

    /**
     * AN ALLOW-LIST, because the deny-list this replaced could not fail.
     *
     * The test that stood here named six strings — `roster`, `scored_count`,
     * `class_average`, `rank`, `position`, `percentile` — and asserted the
     * response contained none of them. Its own docblock said the way they would
     * arrive is "somebody reusing the teacher's serialiser for convenience", and
     * that is precisely the case it could not catch:
     * `Teacher\GradebookController::forMember()` emits `student`, `summary`,
     * `performance_levels`, `scores`, `scores_shown` and `scores_truncated` and
     * not one of the six. `scored` and `roster` live in that controller's
     * `index()`; `class_average`, `rank`, `position` and `percentile` appear
     * nowhere in the codebase at all. Paste the teacher's body into
     * `Family\GradesController::forMember()` line for line and the old test
     * stayed green — so it pinned nothing, while reading as though it pinned the
     * disclosure this whole module exists for.
     *
     * A deny-list can only refuse the leaks somebody thought of. This asserts
     * the opposite direction: the payload's key set, at every level, is EXACTLY
     * what the family serialiser emits. Anything a reuse or a convenience adds
     * fails here whatever it is called — the four shapes below among them, and
     * the first three are the ones the old list was actually reaching for:
     *
     *   - the teacher's `data` block, whose `performance_levels` sits INSIDE
     *     `data` rather than beside it;
     *   - the teacher's `student()`, which adds `grade_label`;
     *   - the teacher's `index()` assignment shape, with `scored` and `roster`;
     *   - a class average or a rank under any spelling anyone chooses.
     *
     * The values are asserted too, and that half is not decoration: a key set
     * cannot see a class figure arriving inside a key that is legitimately
     * there. Both children are marked on the SAME assignment — Amina 6, Bilal 10
     * — so every class-wide aggregate (the sum 16, the mean 8, the denominator
     * 20, the two marked) is a different number from the one asserted, and a
     * summary that quietly widened to the room would fail on the figure rather
     * than only on the key.
     */
    #[Test]
    public function the_family_payload_carries_exactly_its_own_keys_and_no_fact_about_the_class(): void
    {
        $shared = $this->pointsWork('Spelling', 10);
        $this->mark($shared, $this->childA, 'scored', 6);
        $this->mark($shared, $this->childB, 'scored', 10);

        $body = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json();

        // ---- the shape, top to bottom ----

        $this->assertKeysAre(['status', 'data', 'performance_levels', 'meta'], $body, 'the envelope');

        $this->assertKeysAre(
            ['student', 'summary', 'scores', 'scores_shown', 'scores_truncated'],
            $body['data'],
            'data'
        );

        $this->assertKeysAre(['membership_id', 'contact'], $body['data']['student'], 'data.student');
        $this->assertKeysAre(
            ['id', 'first_name', 'last_name', 'avatar'],
            $body['data']['student']['contact'],
            'data.student.contact'
        );

        $this->assertKeysAre(
            ['recorded', 'counted', 'excused', 'points_earned', 'points_possible', 'points_counted', 'levels'],
            $body['data']['summary'],
            'data.summary'
        );
        $this->assertKeysAre(
            ['recorded', 'counted', 'missing', 'mean', 'mean_label', 'distribution'],
            $body['data']['summary']['levels'],
            'data.summary.levels'
        );
        $this->assertKeysAre(
            ['level', 'label', 'short_label', 'count'],
            $body['data']['summary']['levels']['distribution'][0],
            'data.summary.levels.distribution[]'
        );

        $this->assertKeysAre(
            ['assignment', 'status', 'points_earned', 'note'],
            $body['data']['scores'][0],
            'data.scores[]'
        );
        $this->assertKeysAre(
            ['id', 'title', 'points_possible', 'scale', 'assigned_on'],
            $body['data']['scores'][0]['assignment'],
            'data.scores[].assignment'
        );

        // The chrome envelope, pinned for the same reason: `meta()` takes an
        // `$extra` array, and a class figure passed through it would be a fact
        // about the room arriving in the one part of the payload nothing else
        // guards. A legitimate new key here means updating this line on purpose.
        $this->assertKeysAre(['group_label', 'translation_available'], $body['meta'], 'meta');

        // ---- and the figures, which are Amina's alone ----

        $summary = $body['data']['summary'];
        $this->assertSame(1, $summary['recorded'], 'one mark, not the two on this assignment');
        $this->assertSame(1, $summary['counted']);
        $this->assertSame(1, $summary['points_counted']);
        $this->assertSame(6.0, (float) $summary['points_earned'], "not 16, which is the class's total");
        $this->assertSame(10.0, (float) $summary['points_possible'], 'not 20, which is the class denominator');

        $this->assertCount(1, $body['data']['scores']);
        $this->assertSame(1, $body['data']['scores_shown']);
    }

    /**
     * The realm is read-mostly and its writes are COUNTED — a GET adds nothing
     * to that list, which is why `FamilyPortalTest::the_family_realm_writes_
     * exactly_ten_things` needed no edit for this slice. Asserted here rather
     * than left implicit: the temptation on a screen like this is a "mark as
     * seen" POST, and that would be a deliberate edit to two files, not a
     * convenience.
     */
    #[Test]
    public function the_grades_route_is_a_get_and_adds_no_write_to_the_realm(): void
    {
        $verbs = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_ends_with($route->uri(), 'members/{membership_id}/grades')) {
                continue;
            }

            if (! str_starts_with($route->uri(), 'api/family')) {
                continue;
            }

            $verbs = array_values(array_diff($route->methods(), ['HEAD']));
        }

        $this->assertSame(['GET'], $verbs, 'the parent-side gradebook is a read and nothing else');
    }

    // ---------------------------------------- one child, one set of numbers

    /**
     * THE TEST THAT HOLDS THE TWO COPIES TOGETHER.
     *
     * `Family\GradesController` and `Teacher\GradebookController::forMember()`
     * compute the same summary from the same rows in two places, because the
     * extraction into `App\Support\GradeRecord` touches the teacher realm and
     * was not made in this slice. A parent's average and a teacher's average
     * disagreeing about one child is the kind of defect that is discovered in a
     * parent-teacher meeting, so the two blocks are asserted IDENTICAL: a change
     * to either copy that does not move the other fails here rather than on a
     * screen.
     *
     * It also pins that the teacher's own realm is unaffected by the family
     * read — the teacher still gets their own payload, with the student block
     * their realm builds, from the same URL they always used.
     */
    #[Test]
    public function the_parents_summary_is_the_same_arithmetic_the_teacher_sees(): void
    {
        // A term with one of everything in it, so the comparison covers both
        // scales and all three statuses rather than a single happy row.
        $this->mark($this->pointsWork('Spelling', 10), $this->childA, 'scored', 8, 'Nearly there.');
        $this->mark($this->pointsWork('Off sick', 10), $this->childA, 'excused');
        $this->mark($this->pointsWork('Not handed in', 10), $this->childA, 'missing');
        $this->mark($this->levelsWork('Reading'), $this->childA, 'scored', 3);
        $this->mark($this->levelsWork('Writing'), $this->childA, 'scored', 2);

        // THE TEACHER FIRST, and that order is load-bearing: `asParent()` pins a
        // family bearer token onto this test case's headers for every later
        // request, and `auth:sanctum` on the teacher route would be handed that
        // token and refuse it. Reversing these two lines fails with a 403 that
        // says nothing about the arithmetic being compared.
        $teacherSummary = $this->asTeacher()
            ->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}"
                . "/members/{$this->childA->id}/grades")
            ->assertOk()
            ->json('data.summary');

        $parentSummary = $this->asParent($this->parentA)
            ->getJson($this->gradesUrl($this->childA))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(
            $teacherSummary,
            $parentSummary,
            'one child has one average; the parent and the teacher must be reading the same one'
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * `$actual` carries exactly `$expected` as its keys — as a SET, not a
     * sequence.
     *
     * Both sides are sorted first on purpose. Key ORDER is not a property of a
     * JSON object and no client reads it, so pinning it would turn a harmless
     * reorder into a red build and teach whoever hit it that this assertion is
     * noise. What is being pinned is membership: nothing extra, nothing missing.
     *
     * @param  array<int,string>  $expected
     * @param  array<string,mixed>  $actual
     */
    private function assertKeysAre(array $expected, array $actual, string $where): void
    {
        sort($expected);

        $got = array_keys($actual);
        sort($got);

        $this->assertSame(
            $expected,
            $got,
            "`{$where}` must carry exactly the keys the family serialiser emits — "
            . 'an extra one is a disclosure nobody decided to make'
        );
    }

    private function makeSchool(): Masjid
    {
        return Masjid::create([
            'name' => 'Al-Razi Test ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function enrol(Group $class, string $firstName): GroupMembership
    {
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => $firstName, 'last_name' => 'Test',
        ]);

        return GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
    }

    /** A parent who can sign in, holding a guardian edge over exactly one child. */
    private function guardianOf(Group $class, GroupMembership $child): Contact
    {
        $parent = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'login_email' => 'parent-' . uniqid() . '@test.local',
            'login_enabled_at' => now(),
        ]);

        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $class->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->contact_id,
            'confirmed_at' => now(),
            'consent_granted_at' => now(),
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        return $parent->refresh();
    }

    /** Put a family back where most families start: no consent form on file. */
    private function withdrawConsent(Contact $parent): void
    {
        GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->class->id)
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->update(['consent_granted_at' => null, 'consent_scope' => null]);
    }

    private function pointsWork(string $title, int $outOf): ClassAssignment
    {
        return ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'title' => $title, 'points_possible' => $outOf,
            'scale' => ClassAssignment::SCALE_POINTS,
            'assigned_on' => now()->toDateString(),
        ]);
    }

    /** `points_possible` is the scale's MAXIMUM here and never a denominator. */
    private function levelsWork(string $title): ClassAssignment
    {
        return ClassAssignment::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'title' => $title, 'points_possible' => \App\Support\PerformanceLevel::MAX,
            'scale' => ClassAssignment::SCALE_LEVELS,
            'assigned_on' => now()->toDateString(),
        ]);
    }

    private function mark(
        ClassAssignment $work,
        GroupMembership $child,
        string $status,
        int|float|null $points = null,
        ?string $note = null
    ): AssignmentScore {
        return AssignmentScore::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'class_assignment_id' => $work->id, 'group_membership_id' => $child->id,
            'status' => $status, 'points_earned' => $points, 'note' => $note,
        ]);
    }

    private function gradesUrl(GroupMembership $child): string
    {
        return "/api/family/masjids/{$this->school->id}/groups/{$this->class->id}"
            . "/members/{$child->id}/grades";
    }

    /**
     * A real bearer token, not `Sanctum::actingAs()`.
     *
     * `actingAs` calls `setUser()` on the guard directly and never enters
     * `Laravel\Sanctum\Guard::__invoke()`, where the provider comparison keeping
     * the family and staff realms apart actually lives. The guards and the
     * tenant are dropped first so each call is an honest new request:
     * `RequestGuard::user()` memoizes, and `TenantContext` is a scoped binding
     * nothing clears mid-process.
     */
    private function asParent(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader(
            'Authorization',
            'Bearer ' . $parent->createFamilyToken()->plainTextToken
        );
    }

    /** The other realm, reached from the same test for the comparison above. */
    private function asTeacher(): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        Sanctum::actingAs($this->teacher, ['staff']);

        return $this->withHeader('Accept', 'application/json');
    }
}
