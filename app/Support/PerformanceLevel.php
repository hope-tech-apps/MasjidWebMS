<?php

namespace App\Support;

/**
 * The school's four performance levels — ONE definition, used everywhere.
 *
 * From Al-Razi's own "Universal Performance Levels (Used Across All Subjects)"
 * in the 26-27 training deck, transcribed rather than paraphrased. The wording
 * below is the school's, and it is the wording a teacher, a parent and a report
 * card must all see, because a level that means one thing on the gradebook and
 * a slightly different thing on the report card is worse than no scale at all.
 *
 * ---------------------------------------------------------------------------
 * A LEVEL IS NOT A PERCENTAGE, and this class exists to keep it that way
 * ---------------------------------------------------------------------------
 *
 * The gradebook already had points, and the cheap way to add this scale would
 * have been to set `points_possible = 4` and let the existing average run. That
 * silently converts a standards scale back into percentage grading: a child who
 * "Meets Expectations" on every single criterion would be shown 3/4 = 75%, and a
 * child needing support would read as 25%. Both numbers are meaningless, both
 * look authoritative, and the second one is the kind of thing a parent carries
 * around for a year.
 *
 * So a levels assignment reports a DISTRIBUTION and a mean level to one decimal
 * (2.8, not 70%), and `ClassAssignment::SCALE_LEVELS` is what tells the
 * gradebook which of the two it is holding.
 *
 * ---------------------------------------------------------------------------
 * The key is data, not a caption
 * ---------------------------------------------------------------------------
 *
 * `key()` is served with every payload that carries a level, so no screen has
 * to hardcode "4 means Exceeds". A teacher opening the gradebook, a parent
 * opening the portal and a printed report card all render the same four rows
 * from the same array. That is also what makes the descriptions translatable
 * later without hunting through templates.
 */
final class PerformanceLevel
{
    public const EXCEEDS = 4;
    public const MEETS = 3;
    public const APPROACHING = 2;
    public const NEEDS_SUPPORT = 1;

    /** Highest first, the way the school's own rubrics are laid out. */
    public const ALL = [
        self::EXCEEDS,
        self::MEETS,
        self::APPROACHING,
        self::NEEDS_SUPPORT,
    ];

    public const MIN = self::NEEDS_SUPPORT;
    public const MAX = self::EXCEEDS;

    /**
     * The school's labels, verbatim.
     *
     * @var array<int, string>
     */
    private const LABELS = [
        self::EXCEEDS => 'Exceeds Expectations',
        self::MEETS => 'Meets Expectations',
        self::APPROACHING => 'Approaching Expectations',
        self::NEEDS_SUPPORT => 'Needs Support',
    ];

    /**
     * The school's descriptions, verbatim. These are what "the key" means.
     *
     * @var array<int, string>
     */
    private const DESCRIPTIONS = [
        self::EXCEEDS => 'Consistently demonstrates mastery; applies skills independently; shows accuracy, depth, and confidence.',
        self::MEETS => 'Demonstrates grade-level proficiency; completes tasks with minimal support; shows solid understanding.',
        self::APPROACHING => 'Partial understanding; needs support or reminders; inconsistent performance.',
        self::NEEDS_SUPPORT => 'Limited understanding; requires significant guidance; skills not yet developed.',
    ];

    /**
     * A SHORT label for narrow columns — a gradebook cell, a phone screen.
     *
     * Deliberately a separate list rather than a truncation of LABELS: "Exceeds
     * Expectations" cut to fit becomes "Exceeds Expec…", which reads as a
     * rendering bug. Every one of these is a word the school already uses in the
     * column headers of its own subject rubrics.
     *
     * @var array<int, string>
     */
    private const SHORT_LABELS = [
        self::EXCEEDS => 'Exceeds',
        self::MEETS => 'Meets',
        self::APPROACHING => 'Approaching',
        self::NEEDS_SUPPORT => 'Needs Support',
    ];

    /**
     * THE KEY — the four rows every screen showing a level must be able to
     * render, so "what does a 3 mean?" is answerable without asking a teacher.
     *
     * @return array<int, array{level: int, label: string, short_label: string, description: string}>
     */
    public static function key(): array
    {
        return array_map(fn (int $level): array => [
            'level' => $level,
            'label' => self::LABELS[$level],
            'short_label' => self::SHORT_LABELS[$level],
            'description' => self::DESCRIPTIONS[$level],
        ], self::ALL);
    }

    public static function isValid(int|float|string|null $level): bool
    {
        return is_numeric($level)
            && (float) $level == (int) $level
            && in_array((int) $level, self::ALL, true);
    }

    public static function label(int $level): ?string
    {
        return self::LABELS[$level] ?? null;
    }

    public static function shortLabel(int $level): ?string
    {
        return self::SHORT_LABELS[$level] ?? null;
    }

    public static function describe(int $level): ?string
    {
        return self::DESCRIPTIONS[$level] ?? null;
    }

    /**
     * The label for a MEAN, which is usually not a whole number.
     *
     * Rounds to the nearest level for the WORD only — the number itself is
     * always shown alongside, never replaced. A 2.5 is reported as "2.5" with
     * the nearer word beside it, so a reader can see it sits between two levels
     * rather than being told it is one of them. Ties round DOWN, because
     * rounding a borderline child up is the direction that loses a child support
     * they are entitled to.
     */
    public static function labelForMean(?float $mean): ?string
    {
        if ($mean === null) {
            return null;
        }

        $level = (int) max(self::MIN, min(self::MAX, ceil($mean - 0.5)));

        return self::SHORT_LABELS[$level] ?? null;
    }
}
