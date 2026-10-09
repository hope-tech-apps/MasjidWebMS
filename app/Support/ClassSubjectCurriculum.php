<?php

namespace App\Support;

use App\Models\{CurriculumWeek, Group};
use Illuminate\Support\Collection;

/** Curriculum names and grade labels remain the school's verbatim words. */
final class ClassSubjectCurriculum
{
    /** Office hints contain only grades with current students in this class. */
    public static function choices(Group $group): array
    {
        $grades = $group->memberships()->participants()->current()->pluck('grade_label')->map(fn ($label) => GradeLevel::key($label))->filter(fn ($key) => $key !== null)->unique()->all();
        $rows = CurriculumWeek::select('subject', 'grade_label')->distinct()->orderBy('subject')->orderBy('grade_label')->get();
        $choices = [];
        foreach ($rows->groupBy('subject') as $name => $entries) {
            $choices[$name] = $entries->filter(fn ($row) => in_array(GradeLevel::key($row->grade_label), $grades, true))
                ->unique(fn ($row) => GradeLevel::key($row->grade_label))->sortBy(fn ($row) => GradeLevel::key($row->grade_label), SORT_NATURAL)
                ->pluck('grade_label')->values()->all();
        }
        return $choices;
    }

    /** Pure report over bulk-read rows; hidden subjects do not cover a class's curriculum. */
    public static function coverage(Collection $subjects, Collection $guide, Collection $roster): array
    {
        $grades = $roster->map(fn ($row) => GradeLevel::key($row->grade_label))->filter(fn ($key) => $key !== null)->unique()->all();
        $names = $guide->filter(fn ($row) => in_array(GradeLevel::key($row->grade_label), $grades, true))->pluck('subject')->unique()->sort()->values();
        $visible = $subjects->whereNull('hidden_at');
        $followed = $visible->flatMap(fn ($subject) => $subject->followedGuideSubjects())->unique()->all();
        return ['unfollowed_subjects' => $names->isEmpty() ? [] : $visible->filter(fn ($subject) => $subject->followedGuideSubjects() === [])->pluck('name')->values()->all(),
            'uncovered_guides' => $names->reject(fn ($name) => in_array($name, $followed, true))->values()->all()];
    }
}
