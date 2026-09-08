<?php

namespace App\Support;

/**
 * WHAT A REPORT CARD ASKS ABOUT — the school's own subjects and criteria.
 *
 * Transcribed from Al-Razi's 26-27 training deck, where each subject already
 * has a rubric with named criteria marked on the four performance levels. The
 * report card is not a new assessment scheme; it is those rubrics collected for
 * one child at the end of a quarter, which is why the criteria below are the
 * deck's words rather than a fresh set invented here.
 *
 * ---------------------------------------------------------------------------
 * A TEMPLATE, NOT A SCHEMA
 * ---------------------------------------------------------------------------
 *
 * These strings are stored ON each mark row (`subject` + `criterion`), not
 * referenced by id. A report card issued in December must still say what it
 * said in December even if the school rewords a criterion in March — a parent
 * keeps that document, and a stored report that silently re-renders against
 * today's template is a record of nothing.
 *
 * The cost is that editing this list does not retroactively change issued
 * cards, which is the correct behaviour and worth stating out loud.
 *
 * ---------------------------------------------------------------------------
 * GRADE-SENSITIVE, because a Pre-K child is not marked on Grammar
 * ---------------------------------------------------------------------------
 *
 * Al-Razi runs two combined classrooms spanning Pre-K to 2nd, so one class's
 * roster holds children on different templates. `forGrade()` is what keeps a
 * four-year-old's card from carrying rows nobody can honestly mark.
 */
final class ReportCardTemplate
{
    /**
     * The universal core, marked for every child at every grade.
     *
     * Islamic Studies and Qur'an sit here rather than under a grade band on
     * purpose: they are the subjects the school exists for, and a template that
     * dropped them for the youngest children would say something the school
     * does not mean.
     *
     * @var array<string, array<int, string>>
     */
    private const CORE = [
        'Qur\'an' => [
            'Recitation',
            'Memorisation',
            'Tajweed',
        ],
        'Islamic Studies' => [
            'Understanding of concepts',
            'Applies manners (adab) independently',
            'Participation',
        ],
        'Arabic Language' => [
            'Reading Accuracy',
            'Writing',
            'Vocabulary Use',
        ],
    ];

    /**
     * Added from Kindergarten upward, where a child is reading and writing in
     * English and doing formal maths.
     *
     * @var array<string, array<int, string>>
     */
    private const ACADEMIC = [
        'English Language Arts' => [
            'Reading Fluency',
            'Reading Comprehension',
            'Writing',
        ],
        'Mathematics' => [
            'Number Sense',
            'Problem Solving',
            'Math Skills',
        ],
        'Science' => [
            'Scientific Knowledge',
            'Inquiry & Investigation',
            'Explanation & Reasoning',
        ],
    ];

    /**
     * Added from 1st grade, where the deck's Arabic rubric starts marking
     * grammar as its own criterion.
     *
     * @var array<string, array<int, string>>
     */
    private const UPPER = [
        'Arabic Language' => [
            'Grammar',
        ],
    ];

    /**
     * Marked for every child, but on EFFORT rather than achievement.
     *
     * Deliberately separated. Mixing "works well with others" into an academic
     * average is how a quiet child ends up with a lower grade in Mathematics
     * than their maths deserves. These are reported alongside, never inside.
     *
     * @var array<int, string>
     */
    public const LEARNING_BEHAVIOURS = [
        'Follows classroom expectations',
        'Completes work on time',
        'Works well with others',
        'Shows respect and good adab',
    ];

    /**
     * Grades that get ONLY the core subjects.
     *
     * Kindergarten is deliberately NOT here — a KG child is doing early reading
     * and number work and is marked on it. Pre-K is the line.
     */
    private const PRE_K_GRADES = ['Pre-K', 'PreK', 'Pre-Kindergarten'];

    /** Grades that get the academic subjects but not yet Arabic grammar. */
    private const KINDERGARTEN_GRADES = ['KG', 'Kindergarten'];

    /**
     * The subjects and criteria this child should be marked on.
     *
     * An unrecognised or missing grade label falls through to the FULLEST
     * template rather than the smallest. A card with a row the teacher leaves
     * blank is a visible gap they can act on; a card silently missing
     * Mathematics is one nobody notices until a parent asks.
     *
     * @return array<string, array<int, string>>
     */
    public static function forGrade(?string $gradeLabel): array
    {
        $subjects = self::CORE;

        if (self::isPreK($gradeLabel)) {
            return $subjects;
        }

        foreach (self::ACADEMIC as $subject => $criteria) {
            $subjects[$subject] = array_merge($subjects[$subject] ?? [], $criteria);
        }

        if (! self::isKindergarten($gradeLabel)) {
            foreach (self::UPPER as $subject => $criteria) {
                $subjects[$subject] = array_merge($subjects[$subject] ?? [], $criteria);
            }
        }

        return $subjects;
    }

    /**
     * The template as flat rows, which is the shape a report card is built and
     * stored in.
     *
     * @return array<int, array{subject: string, criterion: string}>
     */
    public static function rowsForGrade(?string $gradeLabel): array
    {
        $rows = [];

        foreach (self::forGrade($gradeLabel) as $subject => $criteria) {
            foreach ($criteria as $criterion) {
                $rows[] = ['subject' => $subject, 'criterion' => $criterion];
            }
        }

        return $rows;
    }

    private static function isPreK(?string $grade): bool
    {
        return $grade !== null && in_array(trim($grade), self::PRE_K_GRADES, true);
    }

    private static function isKindergarten(?string $grade): bool
    {
        return $grade !== null && in_array(trim($grade), self::KINDERGARTEN_GRADES, true);
    }
}
