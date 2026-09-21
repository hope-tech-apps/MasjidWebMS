<?php

namespace App\Support;

use App\Models\ClassAssignment;
use App\Models\Masjid;

/**
 * THE ONE READER of the per-organisation school settings.
 *
 * Three grants in config/capabilities.php, all OFF for every organisation until
 * a SuperAdmin switches one on (PATCH .../capabilities/{key}, SuperAdmin only,
 * audited in masjid_capability_changes). Off is exactly what every school did
 * before these settings existed, so Al-Razi (org 14) is unchanged. They were
 * made for Burlington Islamic Sunday School (org 18), a school that meets once
 * a week (owner, 2026-09-21; DECISIONS.md 2026-09-21).
 *
 *   report_card_core_subjects  Report cards carry ReportCardTemplate's CORE
 *                              only (Qur'an, Islamic Studies, Arabic Language)
 *                              at every grade. Learning Behaviours stay.
 *   short_lesson_plan          The lesson plan drops the standard, the
 *                              Differentiation section, the STEM line and the
 *                              exit ticket (HIDDEN_LESSON_PLAN_FIELDS).
 *   simple_marking             A teacher may mark a piece of work Excellent /
 *                              Good / Needs work (SimpleMark) instead of points.
 *
 * Read through Masjid::hasCapability(), which FAILS CLOSED: a stale config
 * cache during a deploy reads every setting as off, which is today's
 * behaviour. For org 18 that window means a report card prepared in it would
 * gain the grade-band rows (they are never deleted afterwards, by design of
 * ReportCardService::ensureRows). BISS prepares no report card before its
 * first quarter ends, so the window costs nothing there.
 *
 * A null organisation (not found) reads as every setting off.
 */
final class SchoolSettings
{
    public const REPORT_CARD_CORE_SUBJECTS = 'report_card_core_subjects';
    public const SHORT_LESSON_PLAN = 'short_lesson_plan';
    public const SIMPLE_MARKING = 'simple_marking';

    /**
     * What the shorter lesson plan leaves out (owner, 2026-09-21: "Differentiation
     * section, STEM line, Exit ticket" plus the standards). Reflection, subject
     * integration and Islamic integration stay.
     *
     * Hidden means not shown AND not written: LessonPlanController::save leaves
     * these columns as they are rather than taking them from the request, so
     * nothing a client sends can fill them, nothing is required, and a plan
     * written before the setting was switched on keeps what it had.
     */
    public const HIDDEN_LESSON_PLAN_FIELDS = [
        'standard_code',
        'standard_description',
        'differentiation_support',
        'differentiation_extension',
        'differentiation_learning_styles',
        'differentiation_ell_aal',
        'differentiation_sen',
        'cross_integration_stem',
        'assessment_exit_ticket',
    ];

    /** Today's choices, in the order the teacher's select has always listed them. */
    private const DEFAULT_SCALES = [ClassAssignment::SCALE_LEVELS, ClassAssignment::SCALE_POINTS];

    /**
     * With simple marking: a score, or the three words (owner: "either a score
     * or a simple scale"). The four performance levels are Al-Razi's rubric
     * scale and are not offered alongside.
     */
    private const SIMPLE_SCALES = [ClassAssignment::SCALE_POINTS, ClassAssignment::SCALE_SIMPLE];

    public static function org(int|string|null $masjidId): ?Masjid
    {
        return $masjidId === null ? null : Masjid::find((int) $masjidId);
    }

    public static function reportCardCoreOnly(?Masjid $masjid): bool
    {
        return (bool) $masjid?->hasCapability(self::REPORT_CARD_CORE_SUBJECTS);
    }

    /** @return list<string> */
    public static function hiddenLessonPlanFields(?Masjid $masjid): array
    {
        return $masjid?->hasCapability(self::SHORT_LESSON_PLAN) ? self::HIDDEN_LESSON_PLAN_FIELDS : [];
    }

    public static function simpleMarking(?Masjid $masjid): bool
    {
        return (bool) $masjid?->hasCapability(self::SIMPLE_MARKING);
    }

    /**
     * The scales a teacher may choose for NEW work here.
     *
     * @return list<string>
     */
    public static function gradingScales(?Masjid $masjid): array
    {
        return self::simpleMarking($masjid) ? self::SIMPLE_SCALES : self::DEFAULT_SCALES;
    }

    /**
     * The scale an assignment takes when the request names none. Everywhere
     * else it is config('groups.default_grading_scale'), as before; where that
     * scale is not on offer, the first one that is.
     */
    public static function defaultScale(?Masjid $masjid): string
    {
        $configured = (string) config('groups.default_grading_scale', ClassAssignment::SCALE_POINTS);
        $offered = self::gradingScales($masjid);

        return in_array($configured, $offered, true) ? $configured : $offered[0];
    }
}
