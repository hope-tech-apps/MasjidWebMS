<?php

namespace App\Support;

use App\Models\ClassAssignment;
use App\Models\Masjid;

/**
 * THE ONE READER of the per-organisation school settings.
 *
 * Grants in config/capabilities.php (the first three are the weekly-school settings;
 * `points_weekly_report`, T-003.3, is the Friday points report and `class_store`, T-003.4,
 * is the Manara Bucks class store; both are for any school),
 * all OFF for every organisation until
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
    /** The weekly points report (T-003.3): families and teachers are emailed. Off for everyone until a SuperAdmin decides. */
    public const POINTS_WEEKLY_REPORT = 'points_weekly_report';
    /** Multiple meeting days and dated terms; separate from office calendar access. */
    public const SCHOOL_CALENDAR_TERMS = 'school_calendar_terms';

    /** Only this reader activates dated calendar configuration; no SuperAdmin bypass. */
    public static function calendarTerms(?Masjid $school): bool
    {
        return $school?->hasCapability(self::SCHOOL_CALENDAR_TERMS) ?? false;
    }

    /** The class store (T-003.4): points become Manara Bucks a class store spends. Off for everyone until a SuperAdmin decides. */
    public const CLASS_STORE = 'class_store';

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

    /**
     * Whether this organisation's screens offer a standard on a lesson plan or a
     * piece of work. Off where `short_lesson_plan` is on (BISS teaches no pacing
     * guide), because the same setting hides `standard_code` on the plan.
     */
    public static function showsStandards(?Masjid $masjid): bool
    {
        return ! in_array('standard_code', self::hiddenLessonPlanFields($masjid), true);
    }

    public static function simpleMarking(?Masjid $masjid): bool
    {
        return (bool) $masjid?->hasCapability(self::SIMPLE_MARKING);
    }

    /**
     * Does this organisation send the weekly points report? Fails closed like every
     * other grant: an unknown organisation, or a stale config cache during a deploy,
     * reads as OFF, which sends nothing.
     */
    public static function pointsWeeklyReport(?Masjid $masjid): bool
    {
        return (bool) $masjid?->hasCapability(self::POINTS_WEEKLY_REPORT);
    }

    /**
     * Does this organisation run the class store (Manara Bucks minted from points, a store
     * the teachers run, a balance families read)? Fails closed like every other grant: an
     * unknown organisation, or a stale config cache during a deploy, reads as OFF, which
     * mints nothing and answers no store route.
     */
    public static function classSubjects(?Masjid $masjid): bool
    {
        return (bool) $masjid?->hasCapability('class_subjects');
    }

    /** Work stays dark unless both school grants are on, with no operator bypass. */
    public static function classSubjectWork(?Masjid $school): bool
    {
        return self::classSubjects($school) && (bool) $school?->hasCapability('class_subject_work');
    }

    /** Evaluate the intended map under the organisation mutex, before any writes. */
    public static function assertSubjectWorkChange(Masjid $school, array $changes): void
    {
        if (($changes['class_subject_work'] ?? false) === true
            && ! ($changes['class_subjects'] ?? self::classSubjects($school))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'capability' => ['Switch on class subjects before enabling subject notes and marks.'],
            ]);
        }
    }

    public static function classStore(?Masjid $masjid): bool
    {
        return (bool) $masjid?->hasCapability(self::CLASS_STORE);
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
