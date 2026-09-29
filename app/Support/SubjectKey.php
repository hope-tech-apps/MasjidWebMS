<?php

namespace App\Support;

use App\Models\GroupStaff;

/**
 * What makes two spellings of a subject the same subject.
 *
 * A subject is typed by an office ("Qur’an" with a typographic apostrophe from a
 * word processor), imported from the weekly guide, and picked from a dropdown by
 * a teacher on a phone ("Qur'an"). Those are one subject, so everything that
 * groups, de-duplicates or fences by subject compares KEYS, never names:
 * `school_subjects.name_key`, `class_assignments.subject_key`, the per-subject
 * blocks of a child's grades, and the teacher subject fence.
 *
 * The fold is deliberately small: trim, collapse runs of whitespace, lower-case,
 * and DROP every apostrophe-like mark (U+0027, U+2018, U+2019, U+02BB, U+02BC,
 * U+02BE, U+02BF), so "Qur’an", "Qur'an", "Quran" and "QUR'AN" are one key. It
 * only ever removes or lowers code points one for one, so a 64-character name
 * cannot make a 65-character key for a 64-character column (the reason
 * LessonPlan::subjectKeyFor uses MB_CASE_LOWER_SIMPLE rather than mb_strtolower).
 *
 * ## LessonPlan keeps its own key, on purpose
 *
 * The plan for this wave named LessonPlan::subjectKeyFor as a second caller. It
 * is NOT, because `lesson_plans.subject_key` carries a UNIQUE index with live
 * rows in it: changing how that key is derived would leave every existing plan
 * under its old key, so the by-day save would fail to find "the same subject's
 * plan" and add a duplicate, and re-keying would need a data migration with a
 * collision pre-flight on rows a teacher wrote. Recorded in DECISIONS.md
 * (2026-09-29, W3). The lesson-plan fence uses THIS key to decide which staff
 * subject a plan belongs to; only the stored unique key stays as it was.
 *
 * ## Staff subjects
 *
 * A teacher's assignment lists what they teach as `quran`, `arabic` and
 * `islamic_studies` (GroupStaff::SUBJECTS). The catalogue and the guide name
 * subjects in words. `staffKeys()` maps the second to the first; a subject that
 * maps to none (Mathematics) belongs to no staff subject, so a teacher limited
 * to some subjects does not teach it.
 */
final class SubjectKey
{
    private const APOSTROPHES = ["'", "\u{2018}", "\u{2019}", "\u{02BB}", "\u{02BC}", "\u{02BE}", "\u{02BF}"];

    /**
     * The folded key names of the subjects a staff subject covers. Keys, not
     * names: `quran & islamic studies` is what "Qur’an & Islamic Studies" folds
     * to, and it belongs to BOTH staff subjects, because the school's combined
     * weekly column is taught by whoever teaches either.
     *
     * @var array<string, list<string>>
     */
    private const STAFF_SUBJECTS = [
        'quran' => [GroupStaff::SUBJECT_QURAN],
        'islamic studies' => [GroupStaff::SUBJECT_ISLAMIC_STUDIES],
        'quran & islamic studies' => [GroupStaff::SUBJECT_QURAN, GroupStaff::SUBJECT_ISLAMIC_STUDIES],
        'quran and islamic studies' => [GroupStaff::SUBJECT_QURAN, GroupStaff::SUBJECT_ISLAMIC_STUDIES],
        'arabic' => [GroupStaff::SUBJECT_ARABIC],
        'arabic language' => [GroupStaff::SUBJECT_ARABIC],
    ];

    /**
     * The catalogue name a single-subject teacher's form starts on, so the
     * dropdown's default is a real entry and not a guess. The three names are the
     * ones the report card uses (ReportCardTemplate::CORE).
     *
     * @var array<string, string>
     */
    public const STAFF_CATALOGUE_NAMES = [
        GroupStaff::SUBJECT_QURAN => "Qur'an",
        GroupStaff::SUBJECT_ISLAMIC_STUDIES => 'Islamic Studies',
        GroupStaff::SUBJECT_ARABIC => 'Arabic Language',
    ];

    /** Trimmed, runs of whitespace collapsed to one space; NULL when nothing is left. */
    public static function clean(?string $name): ?string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

        return $clean === '' ? null : $clean;
    }

    /** The comparison key. '' means "no subject". */
    public static function for(?string $name): string
    {
        $clean = (string) self::clean($name);

        return mb_convert_case(str_replace(self::APOSTROPHES, '', $clean), MB_CASE_LOWER_SIMPLE, 'UTF-8');
    }

    /**
     * The staff subjects (`quran`, `arabic`, `islamic_studies`) a subject key
     * belongs to; `[]` for a subject no staff subject covers.
     *
     * @return list<string>
     */
    public static function staffKeys(string $subjectKey): array
    {
        return self::STAFF_SUBJECTS[$subjectKey] ?? [];
    }
}
