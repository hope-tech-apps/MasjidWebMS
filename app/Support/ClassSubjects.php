<?php

namespace App\Support;

use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\SchoolSubject;

/**
 * The subjects a class's work and lesson plans may be filed under.
 *
 * (T-001.3; RECON-PLAN W3: "the gradebook `subjects` list is the catalogue, else
 * the guide's subjects, else `[]`".)
 *
 *  1. The SCHOOL'S LIST (`school_subjects`), the subjects the office keeps in the
 *     Subjects screen, limited to the ones taught at a grade someone in this
 *     class is in;
 *  2. otherwise the subjects of the school's pacing guide for those grades;
 *  3. otherwise none, and the field is free text (a school with neither has told
 *     us nothing to check against).
 *
 * "Someone in this class is in": a class can combine grades (Al-Razi's two
 * combined classrooms span Pre-K to 2nd), so a subject applies when it is taught
 * at ANY current student's grade. A class whose roster carries no grade label at
 * all is offered every subject: an unknown grade never hides one.
 *
 * Comparing is by KEY (App\Support\SubjectKey), so the guide's "Qur’an" and the
 * list's "Qur'an" are one entry. The list's spelling wins where both exist.
 */
final class ClassSubjects
{
    /**
     * @return list<array{name:string, key:string}>  in list order, then guide order
     */
    public static function offered(Group $group): array
    {
        if (ClassSubjectMode::enabled($group->masjid_id)) {
            return self::offeredWithClassSubjects($group);
        }

        $grades = $group->memberships()->participants()->current()
            ->pluck('grade_label')
            ->map(fn ($g) => is_string($g) && trim($g) !== '' ? trim($g) : null)
            ->all();

        $known = array_values(array_filter($grades));
        // A child with no grade label means "unknown", which must not hide a
        // subject: every subject applies to this class.
        $unknownGradePresent = in_array(null, $grades, true) || $grades === [];

        $out = [];

        $catalogue = SchoolSubject::query()->orderBy('position')->orderBy('name')->get();

        foreach ($catalogue as $subject) {
            $applies = $unknownGradePresent || collect($known)->contains(fn ($g) => $subject->appliesToGrade($g));

            if ($applies) {
                self::push($out, $subject->name);
            }
        }

        if ($catalogue->isEmpty()) {
            $rows = CurriculumWeek::query()->select('grade_label', 'subject')->distinct()->orderBy('subject')->get();

            foreach ($rows as $row) {
                if ($unknownGradePresent || GradeLevel::in($row->grade_label, $known)) {
                    self::push($out, $row->subject);
                }
            }
        }

        return array_values($out);
    }

    public static function offeredWithClassSubjects(Group $group): array
    {
        if (SubjectFence::usesClassSubjects($group)) {
            return \App\Models\ClassSubject::where('group_id', $group->id)->whereNull('hidden_at')->orderBy('position')->orderBy('id')->get()
                ->map(fn ($s) => ['name' => $s->name, 'key' => $s->name_key])->all();
        }

        $grades = $group->memberships()->participants()->current()
            ->pluck('grade_label')
            ->map(fn ($g) => is_string($g) && trim($g) !== '' ? trim($g) : null)
            ->all();

        $known = array_values(array_filter($grades));
        // A child with no grade label means "unknown", which must not hide a
        // subject: every subject applies to this class.
        $unknownGradePresent = in_array(null, $grades, true) || $grades === [];

        $out = [];

        $catalogue = SchoolSubject::query()->orderBy('position')->orderBy('name')->get();

        foreach ($catalogue as $subject) {
            $applies = $unknownGradePresent || collect($known)->contains(fn ($g) => $subject->appliesToGrade($g));

            if ($applies) {
                self::push($out, $subject->name);
            }
        }

        if ($catalogue->isEmpty()) {
            $rows = CurriculumWeek::query()->select('grade_label', 'subject')->distinct()->orderBy('subject')->get();

            foreach ($rows as $row) {
                if ($unknownGradePresent || GradeLevel::in($row->grade_label, $known)) {
                    self::push($out, $row->subject);
                }
            }
        }

        return array_values($out);
    }

    /**
     * Whether `$name` is one of `$offered`, by key. An empty offered list means
     * the school has said nothing to check against, so any name passes.
     *
     * @param  list<array{name:string, key:string}>  $offered
     */
    public static function accepts(array $offered, string $name): bool
    {
        if ($offered === []) {
            return true;
        }

        $key = SubjectKey::for($name);

        return collect($offered)->contains(fn (array $s) => $s['key'] === $key);
    }

    /**
     * The entries a teacher with these limits may use (NULL: all of them).
     *
     * @param  list<array{name:string, key:string}>  $offered
     * @param  list<string>|null  $limits
     * @return list<array{name:string, key:string}>
     */
    public static function fenced(array $offered, ?array $limits): array
    {
        if ($limits === null) {
            return $offered;
        }

        return array_values(array_filter($offered, fn (array $s) => SubjectFence::allows($limits, $s['key'])));
    }

    /**
     * The one entry a form should start on: only when the teacher is limited to a
     * single staff subject AND exactly one offered subject covers it (a Qur'an
     * teacher opening a new piece of work sees "Qur'an", not a blank to fill).
     * NULL otherwise: never a guess.
     *
     * @param  list<array{name:string, key:string}>  $fenced
     * @param  list<string>|null  $limits
     */
    public static function defaultFor(array $fenced, ?array $limits): ?string
    {
        if ($limits !== null && array_key_exists('class_subject_ids', $limits)) {
            return self::defaultForWithClassSubjects($fenced, $limits);
        }

        if ($limits === null || count($limits) !== 1) {
            return null;
        }

        // Prefer the catalogue's own entry for the staff subject; the combined
        // guide column is never a default (it is two subjects).
        $wanted = SubjectKey::for(SubjectKey::STAFF_CATALOGUE_NAMES[$limits[0]] ?? '');

        foreach ($fenced as $s) {
            if ($s['key'] === $wanted) {
                return $s['name'];
            }
        }

        return null;
    }

    public static function defaultForWithClassSubjects(array $fenced, ?array $limits): ?string
    {
        if ($limits !== null && array_key_exists('class_subject_ids', $limits)) {
            return count($limits['class_subject_ids']) === 1 && count($fenced) === 1 ? $fenced[0]['name'] : null;
        }

        if ($limits === null || count($limits) !== 1) {
            return null;
        }

        // Prefer the catalogue's own entry for the staff subject; the combined
        // guide column is never a default (it is two subjects).
        $wanted = SubjectKey::for(SubjectKey::STAFF_CATALOGUE_NAMES[$limits[0]] ?? '');

        foreach ($fenced as $s) {
            if ($s['key'] === $wanted) {
                return $s['name'];
            }
        }

        return null;
    }

    /** @param  list<array{name:string, key:string}>  $out */
    private static function push(array &$out, string $name): void
    {
        $clean = SubjectKey::clean($name);

        if ($clean === null) {
            return;
        }

        $key = SubjectKey::for($clean);

        if (! isset($out[$key])) {
            $out[$key] = ['name' => $clean, 'key' => $key];
        }
    }
}
