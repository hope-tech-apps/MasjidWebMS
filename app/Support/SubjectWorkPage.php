<?php

namespace App\Support;

use App\Models\{ClassSubject, CurriculumWeek, Group, LessonPlan, SubjectNote, SubjectPiece, SubjectPieceMark};
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Bulk reads only: class size and the number of guide entries do not add queries. */
final class SubjectWorkPage
{
    public static function planTitle(LessonPlan $plan): string
    {
        return $plan->session_date->toDateString().': '.(filled($plan->objective) ? $plan->objective : (filled($plan->title) ? $plan->title : $plan->body));
    }

    public static function notes(ClassSubject $subject): array
    {
        // Explicit projection: the dormant sharing field is neither read nor served.
        return SubjectNote::where('subject_notes.class_subject_id', $subject->id)
            ->leftJoin('group_memberships as student', 'student.id', '=', 'subject_notes.group_membership_id')
            ->leftJoin('contacts as child', 'child.id', '=', 'student.contact_id')
            ->leftJoin('users as author', 'author.id', '=', 'subject_notes.author_user_id')
            ->select(['subject_notes.id', 'subject_notes.class_subject_id', 'subject_notes.group_membership_id', 'subject_notes.body', 'subject_notes.created_at', 'subject_notes.updated_at', 'author.name as author_name', 'child.first_name', 'child.last_name'])
            ->orderByDesc('subject_notes.created_at')->orderByDesc('subject_notes.id')->get()
            ->map(fn ($note) => [
                'id' => (int) $note->id, 'class_subject_id' => (int) $subject->id,
                'group_membership_id' => $note->group_membership_id,
                'student_name' => $note->group_membership_id === null ? 'Whole class' : (trim($note->first_name.' '.$note->last_name) ?: 'Former student'),
                'author_name' => $note->author_name ?? 'Former staff', 'body' => $note->body,
                'created_at' => $note->created_at, 'updated_at' => $note->updated_at,
            ])->all();
    }

    public static function data(Group $group, ClassSubject $subject, Request $request): array
    {
        $students = $group->memberships()->participants()->current()->with('contact:id,first_name,last_name')
            ->orderBy('id')->get()->map(fn ($member) => [
                'id' => (int) $member->id, 'name' => trim($member->contact?->first_name.' '.$member->contact?->last_name),
                'grade_label' => $member->grade_label, 'grade_key' => GradeLevel::key($member->grade_label),
            ]);
        $pieces = SubjectPiece::where('class_subject_id', $subject->id)->orderByDesc('created_at')->orderByDesc('id')->get();
        // Keep counts for ALL marks for deletion confirmation, while offering only current students for editing.
        $marks = SubjectPieceMark::whereIn('subject_piece_id', $pieces->pluck('id'))
            ->select(['id', 'subject_piece_id', 'group_membership_id', 'level', 'comment', 'updated_at'])->orderBy('group_membership_id')->get();
        $visibleIds = $students->pluck('id')->all();
        $pieceData = function (SubjectPiece $piece, ?array $gradeIds = null) use ($marks, $visibleIds): array {
            $all = $marks->where('subject_piece_id', $piece->id);
            $editable = $all->whereIn('group_membership_id', $gradeIds ?? $visibleIds);
            return [
                'piece_id' => (int) $piece->id, 'source' => $piece->source, 'title' => $piece->title, 'detail' => $piece->detail,
                'grade_label' => $piece->grade_label, 'week_no' => $piece->week_no, 'quarter' => $piece->quarter,
                'standard_code' => $piece->standard_code, 'lesson_plan_id' => $piece->lesson_plan_id,
                'mark_count' => $all->count(), 'marks' => $editable->map(fn ($mark) => [
                    'group_membership_id' => (int) $mark->group_membership_id, 'level' => $mark->level, 'comment' => $mark->comment,
                ])->values()->all(),
            ];
        };

        $blocks = [];
        if ($subject->guide_subject !== null) {
            $guide = CurriculumWeek::where('subject', $subject->guide_subject)->orderBy('week_no')->orderBy('id')->get();
            foreach ($students->groupBy('grade_key') as $key => $gradeStudents) {
                if ((string) $key === '') continue;
                $gradePieces = $pieces->where('source', 'guide')->filter(fn ($p) => GradeLevel::key($p->grade_label) === (string) $key);
                $entries = [];
                foreach ($guide->filter(fn ($g) => GradeLevel::key($g->grade_label) === (string) $key) as $entry) {
                    $piece = $gradePieces->firstWhere('week_no', $entry->week_no);
                    $entries[$entry->week_no] = $piece ? $pieceData($piece, $gradeStudents->pluck('id')->all()) : [
                        'piece_id' => null, 'source' => 'guide', 'title' => $entry->focus, 'detail' => $entry->assessment_note,
                        'grade_label' => $entry->grade_label, 'week_no' => $entry->week_no, 'quarter' => $entry->quarter,
                        'standard_code' => $entry->standard_code, 'lesson_plan_id' => null, 'mark_count' => 0, 'marks' => [],
                    ];
                }
                // A reimport may remove an entry; its saved work is still reachable by piece_id.
                foreach ($gradePieces as $piece) $entries[$piece->week_no] ??= $pieceData($piece, $gradeStudents->pluck('id')->all());
                ksort($entries, SORT_NUMERIC);
                if ($entries === []) continue;
                // A piece exists only after a meaningful mark/comment. Its timestamp keeps
                // the last marked entry even when a teacher later clears every mark row.
                $recent = $gradePieces->sort(function ($a, $b) {
                    return [$b->updated_at->format('Y-m-d H:i:s.u'), (int) $b->id] <=> [$a->updated_at->format('Y-m-d H:i:s.u'), (int) $a->id];
                })->first();
                $opening = $recent?->week_no ?? array_key_first($entries);
                $selected = $opening;
                if ($request->filled('week_no') && GradeLevel::key($request->input('grade_label')) === (string) $key) {
                    $selected = (int) $request->input('week_no');
                    if (! isset($entries[$selected])) throw ValidationException::withMessages(['week_no' => ['Choose an entry for this grade.']]);
                }
                $blocks[] = ['grade_label' => $gradeStudents->first()['grade_label'], 'grade_key' => (string) $key,
                    'students' => $gradeStudents->values()->all(), 'entries' => array_values($entries),
                    'opening_week_no' => $opening, 'selected_week_no' => $selected];
            }
        }
        if ($request->filled('week_no') && ! collect($blocks)->contains(fn ($b) => $b['grade_key'] === GradeLevel::key($request->input('grade_label')))) {
            throw ValidationException::withMessages(['grade_label' => ['Choose a current grade with curriculum entries.']]);
        }

        $plans = LessonPlan::where('group_id', $group->id)->where('class_subject_id', $subject->id)->orderByDesc('session_date')->orderByDesc('id')->get();
        $planData = $plans->map(function ($plan) use ($pieces, $pieceData) {
            $piece = $pieces->where('source', 'plan')->firstWhere('lesson_plan_id', $plan->id);
            return $piece ? $pieceData($piece) : ['piece_id' => null, 'source' => 'plan', 'lesson_plan_id' => (int) $plan->id,
                'title' => self::planTitle($plan), 'detail' => null, 'mark_count' => 0, 'marks' => []];
        });
        foreach ($pieces->where('source', 'plan')->whereNull('lesson_plan_id') as $piece) $planData->push($pieceData($piece));
        $planData = $planData->sortByDesc(fn ($p) => substr($p['title'], 0, 10))->values(); // Title begins with the copied ISO date, including deleted plans.

        return ['subject' => $subject, 'levels' => PerformanceLevel::key(), 'students' => $students->all(),
            'curriculum' => $blocks, 'lesson_plans' => $planData->all(),
            'own_pieces' => $pieces->where('source', 'own')->map(fn ($piece) => $pieceData($piece))->values()->all(), 'notes' => self::notes($subject)];
    }
}
