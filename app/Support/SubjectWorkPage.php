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
                'guide_subject' => $piece->guide_subject, 'grade_label' => $piece->grade_label, 'week_no' => $piece->week_no, 'quarter' => $piece->quarter,
                'standard_code' => $piece->standard_code, 'lesson_plan_id' => $piece->lesson_plan_id,
                'mark_count' => $all->count(), 'marks' => $editable->map(fn ($mark) => [
                    'group_membership_id' => (int) $mark->group_membership_id, 'level' => $mark->level, 'comment' => $mark->comment,
                    'updated_at' => $mark->updated_at?->toISOString(),
                ])->values()->all(),
            ];
        };

        $blocks = [];
        $followed = $subject->followedGuideSubjects();
        // One guide read regardless of how many subjects the office follows.
        $guide = $followed === [] ? collect() : CurriculumWeek::whereIn('subject', $followed)->orderBy('week_no')->orderBy('id')->get();
        $identity = fn ($name, $number) => json_encode([$name, (int) $number]);
        foreach ($students->groupBy('grade_key') as $key => $gradeStudents) {
            if ((string) $key === '') continue;
            $gradePieces = $pieces->where('source', 'guide')->filter(fn ($p) => GradeLevel::key($p->grade_label) === (string) $key);
            $gradeGuide = $guide->filter(fn ($g) => GradeLevel::key($g->grade_label) === (string) $key);
            $entries = [];
            foreach ($followed as $name) {
                foreach ($gradeGuide->where('subject', $name) as $entry) {
                    $entryKey = $identity($name, $entry->week_no);
                    $piece = $gradePieces->where('guide_subject', $name)->firstWhere('week_no', $entry->week_no);
                    // A marked entry keeps the words it was marked against.
                    $entries[$entryKey] = $piece ? $pieceData($piece, $gradeStudents->pluck('id')->all()) + [
                        'wording_changed' => trim((string) $piece->title) !== trim((string) $entry->focus) || trim((string) $piece->detail) !== trim((string) $entry->assessment_note) || trim((string) $piece->standard_code) !== trim((string) $entry->standard_code),
                        'marked_against_date' => $piece->created_at?->format('M j, Y'),
                    ] : [
                        'piece_id' => null, 'source' => 'guide', 'guide_subject' => $name, 'title' => $entry->focus, 'detail' => $entry->assessment_note,
                        'grade_label' => $entry->grade_label, 'week_no' => $entry->week_no, 'quarter' => $entry->quarter,
                        'standard_code' => $entry->standard_code, 'lesson_plan_id' => null, 'mark_count' => 0, 'marks' => [],
                    ];
                }
            }
            // Removed rows and unfollowed columns retain saved work after the current choices.
            foreach ($gradePieces as $piece) $entries[$identity($piece->guide_subject, $piece->week_no)] ??= $pieceData($piece, $gradeStudents->pluck('id')->all()) + [
                'wording_changed' => true, 'marked_against_date' => $piece->created_at?->format('M j, Y'),
            ];
            // Sort removed entries within a still-followed subject by number too. Unfollowed saved
            // subjects follow current choices, in their first-piece order, then entry number.
            $order = array_values(array_unique([...$followed, ...$gradePieces->sortBy('id')->pluck('guide_subject')->all()]));
            uasort($entries, fn ($a, $b) => [array_search($a['guide_subject'], $order, true), $a['week_no']] <=> [array_search($b['guide_subject'], $order, true), $b['week_no']]);
            if ($entries === []) continue;
            $recent = $gradePieces->sort(function ($a, $b) {
                return [$b->updated_at->format('Y-m-d H:i:s.u'), (int) $b->id] <=> [$a->updated_at->format('Y-m-d H:i:s.u'), (int) $a->id];
            })->first();
            $opening = $recent ? $entries[$identity($recent->guide_subject, $recent->week_no)] : reset($entries);
            $selected = $opening;
            if ($request->filled('week_no') && GradeLevel::key($request->input('grade_label')) === (string) $key) {
                $candidates = collect($entries)->where('week_no', (int) $request->input('week_no'));
                if ($request->filled('guide_subject')) $candidates = $candidates->where('guide_subject', $request->input('guide_subject'));
                if ($candidates->count() !== 1) throw ValidationException::withMessages(['week_no' => ['Choose an unambiguous entry and curriculum subject for this grade.']]);
                $selected = $candidates->first();
            }
            // Prefer the first office-followed column's own label, then the roster when no live rows exist.
            $guideLabel = null;
            foreach ($followed as $name) {
                $row = $gradeGuide->firstWhere('subject', $name);
                if ($row) { $guideLabel = $row->grade_label; break; }
            }
            $blocks[] = ['grade_label' => $guideLabel ?? $gradeStudents->first()['grade_label'], 'grade_key' => (string) $key,
                'students' => $gradeStudents->values()->all(), 'entries' => array_values($entries),
                'opening_week_no' => $opening['week_no'], 'selected_week_no' => $selected['week_no'],
                'opening_guide_subject' => $opening['guide_subject'], 'selected_guide_subject' => $selected['guide_subject']];
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
        // A marked plan stays on the subject it was marked under: its piece is listed even after the
        // plan is deleted (no plan id) or linked to another subject (a plan id this subject no longer lists).
        $listed = $plans->pluck('id')->all();
        foreach ($pieces->where('source', 'plan') as $piece) {
            if ($piece->lesson_plan_id === null || ! in_array($piece->lesson_plan_id, $listed)) $planData->push($pieceData($piece));
        }
        $planData = $planData->sortByDesc(fn ($p) => substr($p['title'], 0, 10))->values(); // Title begins with the copied ISO date, including deleted plans.

        return ($request->user()->type !== 'Teacher' && $followed === []
            ? ['curriculum_empty_message' => 'This subject follows no curriculum. Choose one under Class subjects.'] : []) + ['subject' => $subject, 'levels' => PerformanceLevel::key(), 'students' => $students->all(),
            'curriculum' => $blocks, 'lesson_plans' => $planData->all(),
            'own_pieces' => $pieces->where('source', 'own')->map(fn ($piece) => $pieceData($piece))->values()->all(), 'notes' => self::notes($subject)];
    }
}
