<?php

namespace App\Support;

use App\Models\{ClassSubject, Contact, Group, SubjectNote, SubjectPieceMark};
use Illuminate\Support\Collection;

/** Three bulk reads, regardless of the number of children, subjects or shared items. Never cached. */
final class FamilySubjectWork
{
    public static function forChildren(Contact $contact, Group $group, Collection $children, GroupAudience $audience): array
    {
        $subjects = ClassSubject::where('group_id', $group->id)->whereNull('hidden_at')->orderBy('position')->orderBy('id')->get(['id', 'name']);
        $ids = $subjects->pluck('id');
        $marks = $audience->readableSubjectMarksQuery($contact, $group, SubjectPieceMark::query()
            ->join('subject_pieces as piece', 'piece.id', '=', 'subject_piece_marks.subject_piece_id')
            ->whereColumn('piece.masjid_id', 'subject_piece_marks.masjid_id')
            ->whereIn('piece.class_subject_id', $ids)->whereIn('subject_piece_marks.group_membership_id', $children->pluck('id'))
            ->where('subject_piece_marks.shared_with_family', true))
            ?->orderByDesc('subject_piece_marks.updated_at')->orderByDesc('subject_piece_marks.id')
            ->get(['subject_piece_marks.id', 'subject_piece_marks.group_membership_id', 'subject_piece_marks.level', 'subject_piece_marks.comment', 'subject_piece_marks.updated_at', 'piece.class_subject_id', 'piece.title']) ?? collect();
        $notes = $audience->readableSubjectNotesQuery($contact, $group, SubjectNote::query()
            ->whereIn('subject_notes.class_subject_id', $ids)->where('subject_notes.shared_with_family', true)
            ->where(fn ($q) => $q->whereIn('subject_notes.group_membership_id', $children->pluck('id'))->orWhereNull('subject_notes.group_membership_id')))
            ?->orderByDesc('subject_notes.created_at')->orderByDesc('subject_notes.id')
            ->get(['subject_notes.id', 'subject_notes.class_subject_id', 'subject_notes.group_membership_id', 'subject_notes.body', 'subject_notes.created_at', 'subject_notes.updated_at']) ?? collect();
        $levels = collect(PerformanceLevel::key())->keyBy('level');
        $result = [];
        foreach ($children as $child) {
            $result[$child->id] = $subjects->map(function ($subject) use ($child, $marks, $notes, $levels) {
                $childMarks = $marks->where('group_membership_id', $child->id)->where('class_subject_id', $subject->id);
                $childNotes = $notes->where('class_subject_id', $subject->id)->filter(fn ($note) => $note->group_membership_id === null || $note->group_membership_id === $child->id);
                if ($childMarks->isEmpty() && $childNotes->isEmpty()) return null;
                return ['id' => (int) $subject->id, 'name' => $subject->name,
                    'marks' => $childMarks->map(fn ($mark) => ['id' => (int) $mark->id, 'title' => $mark->title, 'level' => $mark->level,
                        'level_label' => $levels->get($mark->level)['short_label'] ?? null, 'comment' => $mark->comment, 'date' => $mark->updated_at?->toISOString()])->values()->all(),
                    'notes' => $childNotes->map(fn ($note) => ['id' => (int) $note->id, 'body' => $note->body, 'date' => $note->created_at?->toISOString(), 'translation_version' => hash('sha256', $note->body)])->values()->all()];
            })->filter()->values()->all();
        }
        return $result;
    }
}
