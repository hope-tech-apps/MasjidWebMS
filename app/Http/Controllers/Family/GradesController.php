<?php

namespace App\Http\Controllers\Family;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Support\PerformanceLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A parent reads their own child's marks.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS: the platform was already telling families about marks it
 * gave them nowhere to read
 * ---------------------------------------------------------------------------
 *
 * `Teacher\GradebookController::announceMarks()` dispatches
 * `GroupNotificationEvent::GRADE_POSTED` per affected child the moment a teacher
 * saves a mark, and `SendGroupNotificationJob` mails that child's guardians a
 * nudge whose only link is the family sign-in page. Until this endpoint existed,
 * a parent who followed that link arrived in a portal with report cards, awards,
 * ḥifẓ and letters — and no marks. The mail was a promise the product could not
 * keep, which is worse than sending no mail at all.
 *
 * This is a GET, and it is the only thing added to this realm. The family
 * realm's counted write list (routes/family.php, and
 * `FamilyPortalTest::the_family_realm_writes_exactly_ten_things`) is therefore
 * untouched — there is no "mark as seen", no acknowledgement, no reply. A parent
 * reading their child's marks writes nothing.
 *
 * ---------------------------------------------------------------------------
 * TWO GATES, BOTH REQUIRED — the same pair awards and ḥifẓ use
 * ---------------------------------------------------------------------------
 *
 * 1. The ENDPOINT asks the ward edge through `FamilyController::subject()`,
 *    which is `GroupAudience::mayReceiveAwardsAbout()` — leader, the student
 *    themselves, or THAT child's guardian, and no fourth way in. A parent who
 *    addresses the other family's child in the same classroom gets an honest
 *    403 rather than a confusing empty page. There is deliberately no rule of
 *    our own here: a second answer to "is this your child?" is a second answer
 *    that can drift from the first.
 * 2. The QUERIES are constrained to `assignment_scores.group_membership_id =
 *    $membership->id` — the ONE membership `subject()` has already proved is
 *    this caller's ward. Every figure on this payload, including both
 *    aggregates, is arithmetically incapable of containing another child's mark:
 *    there is no filter to forget because there is no wider set to narrow.
 *
 * .claude/rules/groups.md requires both halves. The 403 is what is honest to a
 * parent who mistyped an id; the constraint is what makes the honesty cost
 * nothing.
 *
 * CONSENT IS NOT CONSULTED, deliberately. The feed and media scopes gate
 * BROADCASTS of a child's data to the guardian audience; a parent reading their
 * own child's academic record is not a broadcast. Requiring feed consent here
 * would lock a parent out of the record most obviously theirs, and would
 * contradict the awards and ḥifẓ surfaces standing beside it.
 *
 * ---------------------------------------------------------------------------
 * THERE IS NO GROUP-WIDE VARIANT, AND NO CLASS FACT IN THIS PAYLOAD
 * ---------------------------------------------------------------------------
 *
 * `Teacher\GradebookController::index()` serves `scored` and `roster` counts per
 * assignment. Those are facts about the CLASS — how many other children have
 * been marked, and how many are in the room — and they must never reach a
 * family. Neither may an average, a rank or a position. What a parent receives
 * is one child's own work measured against its own denominator, which is the
 * only comparison this module makes.
 *
 * ---------------------------------------------------------------------------
 * WHAT A PARENT IS SHOWN, AND WHAT IS HELD BACK
 * ---------------------------------------------------------------------------
 *
 * There is no publication flag on a mark and there must not be one. The
 * `class_assignments` migration is explicit: "No `scored` / `is_published` /
 * `average` column: all three are derived, and a stored copy is a second source
 * of truth that goes stale the first time a score is corrected." A report card
 * has a draft state because it is a document a teacher composes over days; a
 * mark does not, because `announceMarks()` has already told the family the
 * moment it was saved. Withholding it here would mean mailing a parent about a
 * mark and then hiding it from them.
 *
 * The teacher's working copy is therefore excluded by the two things that
 * actually model it in this schema, and both are SCOPES rather than checks
 * applied after the fetch:
 *
 *   - WITHDRAWN WORK. `class_assignments` soft-deletes, so a score can outlive a
 *     resolvable parent. Every query below joins the assignment and filters
 *     `whereNull('class_assignments.deleted_at')`, so an assignment a teacher
 *     dropped stops counting and stops showing — while the mark itself survives,
 *     because a conversation may already have happened about it.
 *   - AN UNENTERED MARK. Not-yet-scored is the ABSENCE of a row, never a status
 *     and never a zero (see `AssignmentScore`). A child a teacher has not
 *     reached yet has nothing here, which is the truth.
 *
 * ---------------------------------------------------------------------------
 * THIS ARITHMETIC IS A SECOND COPY, AND A TEST HOLDS THE TWO TOGETHER
 * ---------------------------------------------------------------------------
 *
 * `Teacher\GradebookController::forMember()` computes the same summary from the
 * same rows, and the right end-state is one `App\Support\GradeRecord` both
 * controllers call — a parent's average and a teacher's average disagreeing
 * about the same child is the kind of defect that is discovered in a meeting.
 * That extraction touches the teacher realm and is deliberately not made here.
 * Until it is, `FamilyGradesTest::the_parents_summary_is_the_same_arithmetic_the
 * _teacher_sees` calls BOTH endpoints for the same child and asserts the two
 * `summary` blocks are identical, so a change to either copy that does not move
 * the other is a failing build rather than a discrepancy on a screen.
 *
 * The serialisation is NOT shared and should not be. The teacher's payload is
 * built through their realm's names-only boundary and is pinned byte-for-byte by
 * their own tests; a parent's is a narrower disclosure, and least disclosure is
 * a property of the payload.
 */
class GradesController extends FamilyController
{
    /**
     * GET .../groups/{group_id}/members/{membership_id}/grades
     *
     * One child's marks across the term: the summary, then the most recent page
     * of the marks it was computed from.
     */
    public function forMember(Request $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = $this->group($group_id);
        $membership = $this->subject($group, $membership_id);
        $membership->loadMissing('contact');

        $totals = $this->totals((int) $membership->id);
        $recorded = (int) $totals->sum('n');
        $countingRows = $totals->whereIn('status', AssignmentScore::COUNTS_TOWARD_AVERAGE);

        // Points work only. A levels mark must never reach a numerator over a
        // denominator: 3 out of 4 rendered as 75% turns "Meets Expectations"
        // into a C, which is precisely what a standards scale exists to stop.
        $pointRows = $countingRows->where('scale', ClassAssignment::SCALE_POINTS);

        $scores = $this->scores((int) $membership->id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'student' => $this->student($membership),
                // Aggregated over the whole term, never over the page below.
                'summary' => [
                    'recorded' => $recorded,
                    'counted' => (int) $countingRows->sum('n'),
                    'excused' => (int) $totals->where('status', AssignmentScore::STATUS_EXCUSED)->sum('n'),
                    // Points work only — see above.
                    'points_earned' => round((float) $pointRows->sum('earned'), 2),
                    'points_possible' => round((float) $pointRows->sum('possible'), 2),
                    'points_counted' => (int) $pointRows->sum('n'),
                    // Levels work, reported as levels: a distribution and a mean
                    // level to one decimal. Never a percentage.
                    'levels' => $this->levelSummary((int) $membership->id),
                ],
                'scores' => $scores->map(fn (AssignmentScore $s): array => [
                    'assignment' => $s->assignment ? $this->assignment($s->assignment) : null,
                    // The WORD, not a number. The client renders `missing` as
                    // "Not handed in" and `excused` as "Excused"; a
                    // `points_earned` of null is never drawn as a zero.
                    'status' => $s->status,
                    'points_earned' => $s->points_earned !== null ? (float) $s->points_earned : null,
                    // The teacher's own words about this piece of work, and the
                    // reason a parent opens the screen at all.
                    'note' => $s->note,
                ])->values(),
                'scores_shown' => $scores->count(),
                'scores_truncated' => $recorded > $scores->count(),
            ],
            // THE KEY, a SIBLING of `data` rather than a member of it, matching
            // ReportCardsController::show() and the teacher's copy — the client
            // reads `res.data.performance_levels`. Served with the payload so no
            // screen hardcodes "4 means Exceeds", and so "what does a 3 mean?"
            // is answerable in the school's own words without emailing them.
            'performance_levels' => PerformanceLevel::key(),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /**
     * Every mark this child has, counted by status and by scale.
     *
     * AGGREGATED IN SQL, OVER THE WHOLE TERM — never over the page served
     * below. The teacher's copy of this query carries the incident that made it
     * so: the average used to be computed from a `limit(200)` with NO ordering
     * applied before the limit, so past 200 marks a child's average was taken
     * over an arbitrary database-order subset with nothing on screen to say so.
     * A wrong number in front of a parent is worse than a missing one, and this
     * is the surface where the parent actually is.
     *
     * GROUPED BY SCALE AS WELL AS STATUS. A class can hold both kinds of work —
     * a spelling quiz out of 10 and a rubric marked 1-4 — and adding those
     * denominators together produces a figure that is wrong in a way nobody can
     * see. The two scales are summarised separately and never combined.
     *
     * The join is also what keeps withdrawn work out, and the table prefixes on
     * every column are load-bearing: `AssignmentScore` is BelongsToMasjid, and
     * under this join an unqualified `masjid_id` in the global scope's predicate
     * would be ambiguous.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    private function totals(int $membershipId): \Illuminate\Support\Collection
    {
        return AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membershipId)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->groupBy('assignment_scores.status', 'class_assignments.scale')
            ->selectRaw('assignment_scores.status as status')
            ->selectRaw('class_assignments.scale as scale')
            ->selectRaw('COUNT(*) as n')
            ->selectRaw('SUM(COALESCE(assignment_scores.points_earned, 0)) as earned')
            ->selectRaw('SUM(class_assignments.points_possible) as possible')
            ->get();
    }

    /**
     * The most recent marks, ORDERED BEFORE THE LIMIT so the list is honestly
     * "the newest N" rather than whichever rows the database returned first.
     *
     * `scores_truncated` above compares this count against the term total, so a
     * short list under a full average says so instead of quietly implying the
     * average was computed from what is visible.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AssignmentScore>
     */
    private function scores(int $membershipId): \Illuminate\Database\Eloquent\Collection
    {
        return AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membershipId)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->orderByDesc('class_assignments.assigned_on')
            ->orderByDesc('class_assignments.id')
            ->select('assignment_scores.*')
            ->with('assignment')
            ->limit((int) config('groups.records_page_size', 200))
            ->get();
    }

    /**
     * One child's performance levels: how many of each, and the mean.
     *
     * `missing` is deliberately EXCLUDED from the mean rather than counted as a
     * 1. On the points scale, work not handed in scores zero and that is fair —
     * zero out of ten is a real statement about a real denominator. There is no
     * equivalent on this scale: 1 is not "nothing", it is "Needs Support", which
     * is a judgement about a child's understanding that nobody made. So a
     * missing piece of levels work is counted and shown, and left out of the
     * average.
     *
     * @return array{recorded:int, counted:int, missing:int, mean:float|null, mean_label:string|null, distribution:array<int, array{level:int, label:string, short_label:string, count:int}>}
     */
    private function levelSummary(int $membershipId): array
    {
        $rows = AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membershipId)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->where('class_assignments.scale', ClassAssignment::SCALE_LEVELS)
            ->whereIn('assignment_scores.status', AssignmentScore::COUNTS_TOWARD_AVERAGE)
            ->groupBy('assignment_scores.status', 'assignment_scores.points_earned')
            ->selectRaw('assignment_scores.status as status')
            ->selectRaw('assignment_scores.points_earned as level')
            ->selectRaw('COUNT(*) as n')
            ->get();

        $scored = $rows->where('status', AssignmentScore::STATUS_SCORED);
        $missing = (int) $rows->where('status', AssignmentScore::STATUS_MISSING)->sum('n');

        $counted = (int) $scored->sum('n');
        $sum = (float) $scored->sum(fn ($r) => (float) $r->level * (int) $r->n);
        $mean = $counted > 0 ? round($sum / $counted, 1) : null;

        return [
            'recorded' => $counted + $missing,
            'counted' => $counted,
            'missing' => $missing,
            'mean' => $mean,
            // The WORD for the mean, which the client prints beside the number
            // and never instead of it — see PerformanceLevel::labelForMean.
            'mean_label' => PerformanceLevel::labelForMean($mean),
            // Every level is present even at zero, so the shape of the
            // distribution does not change as a child's marks come in, and "no
            // 4s yet" is visible rather than absent.
            'distribution' => array_map(fn (int $level): array => [
                'level' => $level,
                'label' => PerformanceLevel::label($level),
                'short_label' => PerformanceLevel::shortLabel($level),
                'count' => (int) $scored->where('level', $level)->sum('n'),
            ], PerformanceLevel::ALL),
        ];
    }

    /**
     * The piece of work a mark is a mark OF, as a parent sees it.
     *
     * `created_by_user_id` and the soft-delete clock are staff provenance and
     * are dropped. What is left is what a family needs to recognise the work:
     * what it was called, when it was set, and which of the two scales it was
     * marked on — the client needs `scale` to know whether to draw "8 / 10" or
     * a level's word.
     *
     * @return array<string,mixed>
     */
    private function assignment(ClassAssignment $a): array
    {
        return [
            'id' => (int) $a->id,
            'title' => $a->title,
            'points_possible' => (int) $a->points_possible,
            'scale' => $a->scale,
            'assigned_on' => $a->assigned_on->toDateString(),
        ];
    }
}
