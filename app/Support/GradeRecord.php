<?php

namespace App\Support;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\ClassGradeWeight;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 *
 * ---------------------------------------------------------------------------
 * WEIGHTS (T-001.2) ARE A SEPARATE, ADDITIVE BLOCK
 * ---------------------------------------------------------------------------
 *
 * Every key that existed before weights is computed exactly as it was: pooled
 * points over pooled possible, a plain mean level, counts of words. Weights add
 * TWO keys beside them, `weighting` and `by_subject`, and change nothing else, so
 * a class with no weights reads byte for byte as it did.
 *
 * The weighted figure (owner, 2026-09-28: "per type with a per-assignment
 * override, relative, renormalised over the types with scored work"):
 *
 *  - THE TYPE IS THE UNIT, not the piece. Each type with counted work is ONE
 *    SLOT in the average, worth its class weight however many pieces are in it,
 *    so with Test at 40 and Homework at 10 the Tests are four fifths of the grade
 *    whether the child has done one Homework or ten. (An earlier build gave every
 *    piece its own copy of the type's weight, which made a type with many pieces
 *    swamp one with few; DECISIONS W3-1 has the correction.)
 *  - Inside a type the pieces are POOLED (points earned over points possible,
 *    the way the per-type block prints them), so the headline can be rebuilt from
 *    the by-type rows a parent reads: the sum of `weight x percent` over the rows,
 *    divided by the sum of their weights.
 *  - A piece with its own weight (`class_assignments.weight`, the "per-assignment
 *    override") is NOT in its type's pool: it is a slot of its own, worth exactly
 *    that number. That is what "override" has to mean for it to change anything
 *    when the type has one piece in it, and it is the reading a teacher gives
 *    "this project counts 30". Typed or not, a piece with an override counts.
 *  - A piece with neither an override nor a type is EXCLUDED and counted in
 *    `untyped_excluded`, so the screen can say "3 pieces of work have no type"
 *    and never quietly averages them in or out.
 *  - POINTS: each slot's percentage, weighted. LEVELS: each slot's mean LEVEL,
 *    weighted, never a percentage; the two scales are never mixed. `missing` stays
 *    out of a levels mean.
 *  - SIMPLE marks (Excellent / Good / Needs work) are never averaged and never
 *    weighted.
 *  - RENORMALISED: the sum divides by the weights of the slots that HAVE marks
 *    for this child, so a type nobody has been marked on yet drags nothing down
 *    and the weights need not add up to 100.
 *  - A class's weights are all-or-nothing (ClassGradeWeight). No rows means
 *    `enabled` is false and no weighted figure is produced, whatever a piece's
 *    own override says: an override is only meaningful against a weighted class.
 *
 * OFF `by_subject` groups by saved SubjectKey, as before. ON linked work groups
 * by class_subject_id with the current name as its heading; NULL links retain
 * saved-key grouping. Row labels remain snapshots. Weights are per class.
 *
 * ## The subject fence
 *
 * `$subjectKeys` is the allow-list of a subject-limited teacher
 * (App\Support\SubjectFence::allowedKeys), applied to EVERY query here so a
 * Qur'an-only teacher's summary of a child is arithmetically incapable of
 * containing an Arabic mark. NULL is no filter, and is what the family and the
 * office pass. ON callers supply IDs through summaryForClassSubjects; the
 * summaryFor dispatcher uses IDs when the capability is ON, including family reads.
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
    public static function summaryFor(int $membershipId, ?array $subjectKeys = null): array
    {
        $orgId = app(TenantContext::class)->get();
        if ($orgId === null && ! request()->attributes->get(ClassSubjectMode::HTTP)) {
            $orgId = \App\Models\GroupMembership::withoutMasjidScope()->whereKey($membershipId)->value('masjid_id');
        }
        if ($orgId !== null && ClassSubjectMode::enabled($orgId)) return self::summaryForClassSubjects($membershipId, $subjectKeys);

        $totals = self::totals($membershipId, $subjectKeys);
        $recorded = (int) $totals->sum('n');
        $countingRows = $totals->whereIn('status', AssignmentScore::COUNTS_TOWARD_AVERAGE);

        $pointRows = $countingRows->where('scale', ClassAssignment::SCALE_POINTS);

        $pieces = self::pieces($membershipId, $subjectKeys);
        $weights = self::weightsFor($membershipId);

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
            'levels' => self::levelSummary($membershipId, $subjectKeys),
            // Excellent / Good / Needs work: a count of each word. No mean and
            // no percentage (App\Support\SimpleMark).
            'simple' => SimpleMark::summaryFor($membershipId, $subjectKeys),
            // T-001.2: the class's weights applied. See the class docblock.
            'weighting' => self::weighting($pieces, $weights),
            // T-001.3: the same marks, one block per subject.
            'by_subject' => self::bySubject($pieces, $weights),
        ];
    }

    /**
     * Every mark this child has, counted by status and by scale.
     *
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    private static function totals(int $membershipId, ?array $subjectKeys): Collection
    {
        return AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membershipId)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->when($subjectKeys !== null, fn ($q) => $q->whereIn('class_assignments.subject_key', $subjectKeys))
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
    private static function levelSummary(int $membershipId, ?array $subjectKeys): array
    {
        $rows = AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membershipId)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->when($subjectKeys !== null, fn ($q) => $q->whereIn('class_assignments.subject_key', $subjectKeys))
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

    // ------------------------------------------------------------- weighting

    /**
     * Every mark this child has on live work, one row each, with what the
     * weighting and the per-subject blocks need. Unaggregated, and that is fine:
     * a child has one row per piece of work in a term, tens to low hundreds.
     *
     * @return Collection<int, object>
     */
    private static function pieces(int $membershipId, ?array $subjectKeys): Collection
    {
        return AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membershipId)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->when($subjectKeys !== null, fn ($q) => $q->whereIn('class_assignments.subject_key', $subjectKeys))
            ->select([
                'assignment_scores.status as status',
                'assignment_scores.points_earned as earned',
                'class_assignments.scale as scale',
                'class_assignments.points_possible as possible',
                'class_assignments.subject as subject',
                'class_assignments.subject_key as subject_key',
                'class_assignments.type as type',
                'class_assignments.weight as weight',
            ])
            ->get();
    }

    /**
     * The class's weights (`[type => weight]`), `[]` when it is unweighted. The
     * class is the membership's own, read straight from the table: the caller has
     * already proved the membership is theirs to read.
     *
     * @return array<string,int>
     */
    private static function weightsFor(int $membershipId): array
    {
        $groupId = DB::table('group_memberships')->where('id', $membershipId)->value('group_id');

        return $groupId === null ? [] : ClassGradeWeight::forGroup((int) $groupId);
    }

    /**
     * A piece's own override, else its type's class weight, else NULL (no way to
     * say how much it counts).
     *
     * @param  array<string,int>  $weights
     */
    private static function effectiveWeight(object $piece, array $weights): ?int
    {
        if ($piece->weight !== null) {
            return (int) $piece->weight;
        }

        if ($piece->type !== null && isset($weights[$piece->type])) {
            return (int) $weights[$piece->type];
        }

        return null;
    }

    /** Scored or missing: the two statuses that count toward an average. */
    private static function counts(object $piece): bool
    {
        return in_array($piece->status, AssignmentScore::COUNTS_TOWARD_AVERAGE, true);
    }

    /**
     * The weighted figures for a set of pieces. See the class docblock: a type is
     * one slot, a piece with its own weight is a slot of its own.
     *
     * @param  Collection<int, object>  $pieces
     * @param  array<string,int>  $weights
     * @return array{percent:float|null, points_pieces:int, level_mean:float|null, level_pieces:int, untyped_excluded:int}
     */
    private static function weighted(Collection $pieces, array $weights): array
    {
        // slot key => [scale, weight, earned, possible, n]. Points slots pool
        // earned over possible; levels slots average the level.
        $slots = [];
        $excluded = 0;
        $own = 0;

        foreach ($pieces as $piece) {
            $isPoints = $piece->scale === ClassAssignment::SCALE_POINTS && self::counts($piece);
            // `missing` stays out of a levels mean, so it is not "excluded" either.
            $isLevel = $piece->scale === ClassAssignment::SCALE_LEVELS
                && $piece->status === AssignmentScore::STATUS_SCORED;

            if (! $isPoints && ! $isLevel) {
                continue;
            }

            $weight = self::effectiveWeight($piece, $weights);

            if ($weight === null) {
                $excluded++;

                continue;
            }

            // A piece's own weight makes it a slot of its own; everything else
            // pools with the rest of its type.
            $key = $piece->weight !== null
                ? $piece->scale.'|own|'.($own++)
                : $piece->scale.'|type|'.$piece->type;

            $slots[$key] ??= ['scale' => $piece->scale, 'weight' => $weight, 'earned' => 0.0, 'possible' => 0.0, 'n' => 0];
            $slots[$key]['earned'] += (float) $piece->earned;
            $slots[$key]['possible'] += (float) $piece->possible;
            $slots[$key]['n']++;
        }

        $pointSum = 0.0;
        $pointWeight = 0;
        $pointPieces = 0;
        $levelSum = 0.0;
        $levelWeight = 0;
        $levelPieces = 0;

        foreach ($slots as $slot) {
            // A slot of weight 0 (a type set to 0, or a piece given a weight of 0) adds nothing to
            // the sum or the weight, so its pieces are not among those the figure is "across":
            // counting them made "across N pieces" say more than shaped the number (review, optional fold).
            $shapes = $slot['weight'] > 0;

            if ($slot['scale'] === ClassAssignment::SCALE_POINTS) {
                $pointSum += $slot['weight'] * ($slot['earned'] / max(1.0, $slot['possible']));
                $pointWeight += $slot['weight'];
                $pointPieces += $shapes ? $slot['n'] : 0;
            } else {
                $levelSum += $slot['weight'] * ($slot['earned'] / $slot['n']);
                $levelWeight += $slot['weight'];
                $levelPieces += $shapes ? $slot['n'] : 0;
            }
        }

        return [
            'percent' => $pointWeight > 0 ? round(100 * $pointSum / $pointWeight, 1) : null,
            'points_pieces' => $pointPieces,
            'level_mean' => $levelWeight > 0 ? round($levelSum / $levelWeight, 1) : null,
            'level_pieces' => $levelPieces,
            'untyped_excluded' => $excluded,
        ];
    }

    /**
     * The overall weighting block.
     *
     * @param  Collection<int, object>  $pieces
     * @param  array<string,int>  $weights
     * @return array<string,mixed>
     */
    private static function weighting(Collection $pieces, array $weights): array
    {
        $enabled = $weights !== [];
        $figures = $enabled
            ? self::weighted($pieces, $weights)
            : ['percent' => null, 'points_pieces' => 0, 'level_mean' => null, 'level_pieces' => 0, 'untyped_excluded' => 0];

        // The per-type block: present whether or not the class is weighted.
        // Points work only, POOLED, with the type's weight beside it when there
        // is one. In a weighted class it is the type's SLOT, so a piece that
        // carries its own weight (a slot of its own, see the class docblock) is
        // not in it: the rows then rebuild the headline figure exactly.
        $byType = [];

        foreach (ClassAssignment::TYPES as $type) {
            $rows = $pieces->filter(fn ($p) => $p->type === $type
                && $p->scale === ClassAssignment::SCALE_POINTS && self::counts($p)
                && (! $enabled || $p->weight === null));

            if ($rows->isEmpty()) {
                continue;
            }

            $possible = (float) $rows->sum('possible');

            $byType[] = [
                'type' => $type,
                'label' => ClassAssignment::TYPE_LABELS[$type],
                'weight' => $enabled ? ($weights[$type] ?? null) : null,
                'pieces' => $rows->count(),
                'percent' => $possible > 0 ? round(100 * (float) $rows->sum('earned') / $possible, 1) : null,
            ];
        }

        return [
            'enabled' => $enabled,
            'weights' => $enabled ? $weights : (object) [],
            'percent' => $figures['percent'],
            'points_pieces' => $figures['points_pieces'],
            'level_mean' => $figures['level_mean'],
            'level_mean_label' => PerformanceLevel::labelForMean($figures['level_mean']),
            'level_pieces' => $figures['level_pieces'],
            // How many counted pieces had nothing to derive a weight from (no
            // type and no override): "N pieces of work have no type". Always 0
            // in an unweighted class, where nothing is left out of anything.
            'untyped_excluded' => $figures['untyped_excluded'],
            'by_type' => $byType,
        ];
    }

    /**
     * One block per subject, in name order with "no subject" last. Empty when no
     * piece of work names a subject at all: a single anonymous block repeating
     * the headline would be noise.
     *
     * @param  Collection<int, object>  $pieces
     * @param  array<string,int>  $weights
     * @return list<array<string,mixed>>
     */
    private static function bySubject(Collection $pieces, array $weights, bool $byId = false): array
    {
        if ($pieces->every(fn ($p) => (string) $p->subject_key === '' && (! $byId || $p->class_subject_id === null))) {
            return [];
        }

        $enabled = $weights !== [];

        return $pieces
            ->groupBy(fn ($p) => $byId
                ? json_encode($p->class_subject_id !== null ? ['id', (int) $p->class_subject_id] : ['text', (string) $p->subject_key])
                : (string) $p->subject_key)
            ->map(function (Collection $rows, string $key) use ($weights, $enabled, $byId): array {
                $counted = $rows->filter(fn ($p) => self::counts($p));
                $points = $counted->where('scale', ClassAssignment::SCALE_POINTS);
                $possible = (float) $points->sum('possible');
                $scoredLevels = $rows->where('scale', ClassAssignment::SCALE_LEVELS)
                    ->where('status', AssignmentScore::STATUS_SCORED);
                $mean = $scoredLevels->isNotEmpty() ? round((float) $scoredLevels->avg('earned'), 1) : null;
                $weighted = $enabled ? self::weighted($rows, $weights) : null;

                return [
                    'subject' => $byId && $rows->first()->class_subject_id !== null
                        ? $rows->first()->current_subject_name
                        : ((string) $rows->first()->subject_key === '' ? null : (string) $rows->first(fn ($p) => $p->subject !== null)?->subject),
                    'recorded' => $rows->count(),
                    'counted' => $counted->count(),
                    'excused' => $rows->where('status', AssignmentScore::STATUS_EXCUSED)->count(),
                    'points_earned' => round((float) $points->sum('earned'), 2),
                    'points_possible' => round($possible, 2),
                    'points_counted' => $points->count(),
                    'percent' => $possible > 0 ? round(100 * (float) $points->sum('earned') / $possible, 1) : null,
                    'weighted_percent' => $weighted['percent'] ?? null,
                    'level_mean' => $mean,
                    'level_mean_label' => PerformanceLevel::labelForMean($mean),
                    'levels_counted' => $scoredLevels->count(),
                ];
            })
            ->sortBy(fn (array $b) => [$b['subject'] === null ? 1 : 0, mb_strtolower((string) $b['subject'])])
            ->values()
            ->all();
    }


    private static function levelSummaryClassSubjects(int $membershipId, ?array $subjectIds): array
    {
        $rows = AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membershipId)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->when($subjectIds !== null, fn ($q) => $q->whereIn('class_assignments.class_subject_id', $subjectIds))
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

    public static function summaryForClassSubjects(int $membershipId, ?array $subjectIds = null): array
    {
        $totals = self::totalsClassSubjects($membershipId, $subjectIds);
        $recorded = (int) $totals->sum('n');
        $countingRows = $totals->whereIn('status', AssignmentScore::COUNTS_TOWARD_AVERAGE);

        $pointRows = $countingRows->where('scale', ClassAssignment::SCALE_POINTS);

        $pieces = self::piecesClassSubjects($membershipId, $subjectIds);
        $weights = self::weightsFor($membershipId);

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
            'levels' => self::levelSummaryClassSubjects($membershipId, $subjectIds),
            // Excellent / Good / Needs work: a count of each word. No mean and
            // no percentage (App\Support\SimpleMark).
            'simple' => SimpleMark::summaryForClassSubjects($membershipId, $subjectIds),
            // T-001.2: the class's weights applied. See the class docblock.
            'weighting' => self::weighting($pieces, $weights),
            // T-001.3: the same marks, one block per subject.
            'by_subject' => self::bySubject($pieces, $weights, true),
        ];
    }

    private static function totalsClassSubjects(int $membershipId, ?array $subjectIds): Collection
    {
        return AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membershipId)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->when($subjectIds !== null, fn ($q) => $q->whereIn('class_assignments.class_subject_id', $subjectIds))
            ->groupBy('assignment_scores.status', 'class_assignments.scale')
            ->selectRaw('assignment_scores.status as status')
            ->selectRaw('class_assignments.scale as scale')
            ->selectRaw('COUNT(*) as n')
            ->selectRaw('SUM(COALESCE(assignment_scores.points_earned, 0)) as earned')
            ->selectRaw('SUM(class_assignments.points_possible) as possible')
            ->get();
    }

    private static function piecesClassSubjects(int $membershipId, ?array $subjectIds): Collection
    {
        return AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membershipId)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->leftJoin('class_subjects', function ($join) {
                $join->on('class_subjects.id', '=', 'class_assignments.class_subject_id')
                    ->on('class_subjects.masjid_id', '=', 'class_assignments.masjid_id')
                    ->on('class_subjects.group_id', '=', 'class_assignments.group_id');
            })
            ->when($subjectIds !== null, fn ($q) => $q->whereIn('class_assignments.class_subject_id', $subjectIds))
            ->select([
                'assignment_scores.status as status',
                'assignment_scores.points_earned as earned',
                'class_assignments.scale as scale',
                'class_assignments.points_possible as possible',
                'class_assignments.subject as subject',
                'class_assignments.class_subject_id as class_subject_id',
                'class_subjects.name as current_subject_name',
                'class_assignments.subject_key as subject_key',
                'class_assignments.type as type',
                'class_assignments.weight as weight',
            ])
            ->get();
    }
}
