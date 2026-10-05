<?php

namespace App\Support;

/**
 * One grade level, however it was spelled.
 *
 * Three vocabularies disagree about the same child: roster memberships say
 * "Pre-K", "KG", "1st"; the weekly guide says "Pre-Kindergarten", "Grade 1"; the
 * school's Drive curriculum says "Kindergarten", "1st Grade". A subject the
 * office limits to certain grades (school_subjects.grade_labels) is compared
 * through this key, so "Grade 1" and "1st" are the same grade and neither list
 * has to be rewritten to match the other.
 *
 * An unrecognised label keys as its own letters and digits, so it still equals
 * itself; it just cannot be matched with a differently-spelled twin, which is the
 * failure direction that shows a subject too widely rather than hiding one.
 */
final class GradeLevel
{
    /**
     * The levels the Subjects screen offers, in teaching order, spelled the way
     * roster memberships spell them.
     *
     * @var list<string>
     */
    public const LEVELS = [
        'Pre-K', 'KG', '1st', '2nd', '3rd', '4th', '5th', '6th',
        '7th', '8th', '9th', '10th', '11th', '12th',
    ];

    private const WORDS = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6,
        'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10, 'eleventh' => 11, 'twelfth' => 12,
    ];

    /** NULL for a blank label. */
    public static function key(?string $label): ?string
    {
        $flat = (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim((string) $label)));

        if ($flat === '') {
            return null;
        }

        if (in_array($flat, ['prek', 'prekindergarten', 'pk'], true)) {
            return 'prek';
        }

        if (in_array($flat, ['kg', 'k', 'kindergarten'], true)) {
            return 'kg';
        }

        // "1st", "1st grade", "grade 1", "grade1st", "1".
        if (preg_match('/^(?:grade)?(\d{1,2})(?:st|nd|rd|th)?(?:grade)?$/', $flat, $m)) {
            return (string) (int) $m[1];
        }

        // "first", "first grade", "grade first".
        if (preg_match('/^(?:grade)?([a-z]+?)(?:grade)?$/', $flat, $m) && isset(self::WORDS[$m[1]])) {
            return (string) self::WORDS[$m[1]];
        }

        return $flat;
    }

    /**
     * The level after this one in teaching order, spelled as LEVELS spells it:
     * what "move each grade up one" gives a student when a whole class is moved
     * (App\Support\RosterClassMove).
     *
     * NULL when there is no next level to give: a blank label, a label that is
     * not one of the levels (a school's own word for a grade), or the last
     * level. The caller keeps the label the student has and says so; a guess
     * here would write a grade nobody chose.
     */
    public static function next(?string $label): ?string
    {
        $key = self::key($label);

        if ($key === null) {
            return null;
        }

        foreach (self::LEVELS as $i => $level) {
            if (self::key($level) === $key) {
                return self::LEVELS[$i + 1] ?? null;
            }
        }

        return null;
    }

    /**
     * Whether `$level` is one of `$labels`, compared by key.
     *
     * @param  list<string>  $labels
     */
    public static function in(?string $level, array $labels): bool
    {
        $key = self::key($level);

        if ($key === null) {
            return false;
        }

        foreach ($labels as $label) {
            if (self::key($label) === $key) {
                return true;
            }
        }

        return false;
    }
}
