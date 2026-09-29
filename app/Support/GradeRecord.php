<?php

namespace App\Support;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use Illuminate\Support\Collection;

/**
 * One child's marks across the term, summarised. THE ONE COPY.
 *
 * `Teacher\GradebookController::forMember()` and `Family\GradesController::
 * forMember()` used to compute this block separately, held together only by
 * `FamilyGradesTest::the_parents_summary_is_the_same_arithmetic_the_teacher_
 * sees`. A parent's average and a teacher's average disagreeing about one child
 * is the defect that is discovered in a meeting, so both controllers now call
 * this and there is nothing left to drift. The parity test stays: it is what
 * proves the two ENDPOINTS still hand the same block to their clients.
 *
 * ---------------------------------------------------------------------------
 * THE RULES, all of them older than this class
 * ---------------------------------------------------------------------------
 *
 *  - AGGREGATED IN SQL, OVER EVERY MARK, never over a page. The average used to
 *    come from a `limit(200)` with no ordering, so past 200 marks it was taken
 *    over an arbitrary subset with nothing on screen to say so.
 *  - THE JOIN IS WHAT KEEPS WITHDRAWN WORK OUT. `class_assignments`
 *    soft-deletes, so a score can outlive a resolvable parent; every query here
 *    joins the assignment and filters `deleted_at`. The table prefixes on every
 *    column are load-bearing: `AssignmentScore` is BelongsToMasjid, and under
 *    this join an unqualified `masjid_id` in the global scope would be
 *    ambiguous.
 *  - GROUPED BY SCALE AS WELL AS STATUS. A spelling quiz out of 10 and a rubric
 *    marked 1-4 are never added together.
 *  - POINTS work only reaches a numerator over a denominator. A levels mark
 *    rendered as 75% turns "Meets Expectations" into a C, which is precisely
 *    what a standards scale exists to stop.
 *  - `missing` counts in full on points work and is EXCLUDED from a levels mean
 *    (1 is "Needs Support", a judgement nobody made, not "nothing").
 *    `excused` counts in neither half.
 */
final class GradeRecord
{
    /**
     * @return array{
     *   recorded:int, counted:int, excused:int,
     *   points_earned:float, points_possible:float, points_counted:int,
     *   levels:array<string,mixed>, simple:array<string,mixed>
     * }
     */
    public static function summaryFor(int $membershipId): array
    {
        $totals = self::totals($membershipId);
        $recorded = (int) $totals->sum('n');
        $countingRows = $totals->whereIn('status', AssignmentScore::COUNTS_TOWARD_AVERAGE);

        $pointRows = $countingRows->where('scale', ClassAssignment::SCALE_POINTS);

        return [
            'recorded' => $recorded,
            'counted' => (int) $countingRows->sum('n'),
            'excused' => (int) $totals->where('status', AssignmentScore::STATUS_EXCUSED)->sum('n'),
            // Points work only.
            'points_earned' => round((float) $pointRows->sum('earned'), 2),
            'points_possible' => round((float) $pointRows->sum('possible'), 2),
            'points_counted' => (int) $pointRows->sum('n'),
            // Levels work, reported as levels: a distribution and a mean level
            // to one decimal. Never a percentage.
            'levels' => self::levelSummary($membershipId),
            // Excellent / Good / Needs work: a count of each word. No mean and
            // no percentage (App\Support\SimpleMark).
            'simple' => SimpleMark::summaryFor($membershipId),
        ];
    }

    /**
     * Every mark this child has, counted by status and by scale.
     *
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    private static function totals(int $membershipId): Collection
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
     * One child's performance levels: how many of each, and the mean.
     *
     * @return array{recorded:int, counted:int, missing:int, mean:float|null, mean_label:string|null, distribution:array<int, array{level:int, label:string, short_label:string, count:int}>}
     */
    private static function levelSummary(int $membershipId): array
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
}
