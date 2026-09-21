<?php

namespace App\Support;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;

/**
 * The three-word marking scale: Excellent, Good, Needs work.
 *
 * Offered only where a SuperAdmin switched on `simple_marking`
 * (App\Support\SchoolSettings). A weekly school marks a Sunday's work on this
 * scale because a points total over one lesson a week says more than the
 * teacher measured (owner, 2026-09-21: "Teacher picks per assignment").
 *
 * ---------------------------------------------------------------------------
 * STORED AS 3 / 2 / 1, READ ONLY AS A WORD
 * ---------------------------------------------------------------------------
 *
 * A mark on this scale lives in `assignment_scores.points_earned`, the same
 * column the four performance levels use, with `points_possible` forced to 3.
 * The number is a storage code and nothing more:
 *
 *   - no summary puts it over a denominator. The points summary is filtered to
 *     `scale = points` and the levels summary to `scale = levels`, so a 2
 *     ("Good") can never become 67%;
 *   - no summary averages it. "Good-and-a-third" is not something a teacher
 *     said. A child's simple marks are reported as a count of each word;
 *   - every payload that carries one carries its word (`mark_label`), so no
 *     screen has to turn the number back into a word itself.
 */
final class SimpleMark
{
    public const EXCELLENT = 3;
    public const GOOD = 2;
    public const NEEDS_WORK = 1;

    /** Best first, the way a teacher reads the buttons. */
    public const ALL = [
        self::EXCELLENT,
        self::GOOD,
        self::NEEDS_WORK,
    ];

    public const MAX = self::EXCELLENT;

    /** @var array<int, string> */
    private const LABELS = [
        self::EXCELLENT => 'Excellent',
        self::GOOD => 'Good',
        self::NEEDS_WORK => 'Needs work',
    ];

    /**
     * The scale as data, served with every payload that carries such a mark.
     *
     * @return array<int, array{value:int, label:string}>
     */
    public static function key(): array
    {
        return array_map(fn (int $value): array => [
            'value' => $value,
            'label' => self::LABELS[$value],
        ], self::ALL);
    }

    public static function isValid(int|float|string|null $value): bool
    {
        return is_numeric($value)
            && (float) $value == (int) $value
            && in_array((int) $value, self::ALL, true);
    }

    public static function label(int|float|string|null $value): ?string
    {
        return self::isValid($value) ? self::LABELS[(int) $value] : null;
    }

    /**
     * One child's Excellent / Good / Needs work marks: how many of each, and
     * how many were not handed in. NO mean and NO percentage, on purpose.
     *
     * Aggregated in SQL over every mark, joined to live work only, exactly as
     * the points and levels summaries are. Shared by the teacher's and the
     * family's grades endpoints so the two can never disagree about a child.
     *
     * @return array{recorded:int, counted:int, missing:int, distribution:array<int, array{value:int, label:string, count:int}>}
     */
    public static function summaryFor(int $membershipId): array
    {
        $rows = AssignmentScore::query()
            ->where('assignment_scores.group_membership_id', $membershipId)
            ->join('class_assignments', 'class_assignments.id', '=', 'assignment_scores.class_assignment_id')
            ->whereNull('class_assignments.deleted_at')
            ->where('class_assignments.scale', ClassAssignment::SCALE_SIMPLE)
            ->whereIn('assignment_scores.status', AssignmentScore::COUNTS_TOWARD_AVERAGE)
            ->groupBy('assignment_scores.status', 'assignment_scores.points_earned')
            ->selectRaw('assignment_scores.status as status')
            ->selectRaw('assignment_scores.points_earned as mark')
            ->selectRaw('COUNT(*) as n')
            ->get();

        $scored = $rows->where('status', AssignmentScore::STATUS_SCORED);
        $missing = (int) $rows->where('status', AssignmentScore::STATUS_MISSING)->sum('n');
        $counted = (int) $scored->sum('n');

        return [
            'recorded' => $counted + $missing,
            'counted' => $counted,
            'missing' => $missing,
            // Every word is present even at zero, so "no Excellent yet" is
            // visible rather than absent.
            'distribution' => array_map(fn (int $value): array => [
                'value' => $value,
                'label' => self::LABELS[$value],
                'count' => (int) $scored->filter(fn ($r) => (int) $r->mark === $value)->sum('n'),
            ], self::ALL),
        ];
    }
}
