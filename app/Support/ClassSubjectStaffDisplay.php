<?php

namespace App\Support;

use App\Models\ClassSubject;
use App\Models\GroupStaff;
use Illuminate\Support\Collection;

/** Office-only ON display fields, batched by class and including retained hidden limits. */
class ClassSubjectStaffDisplay
{
    /** Read one tenant-scoped catalog for a page, serializer or export chunk. */
    public static function catalog(array $groupIds): Collection
    {
        return ClassSubject::whereIn('group_id', $groupIds)->orderBy('position')->orderBy('id')->get()->groupBy('group_id');
    }

    /** NULL means all; empty or unauthoritative assignments mean no subjects. */
    public static function fields(?GroupStaff $row, Collection $catalog): array
    {
        $ids = $row === null || ($row->class_subjects_mapped_at === null && $row->class_subject_ids_edited_at === null) ? [] : $row->class_subject_ids;
        $names = $ids === null ? null : $catalog->get($row?->group_id, collect())->whereIn('id', $ids)->map(fn ($s) => [
            'id' => (int) $s->id, 'name' => $s->name, 'position' => (int) $s->position, 'hidden_at' => $s->hidden_at?->toISOString(),
        ])->values()->all();
        return ['class_subject_ids' => $ids, 'class_subject_names' => $names];
    }

    /** Exact office label words, also used by the streamed staff CSV. */
    public static function text(array $fields): string
    {
        if ($fields['class_subject_ids'] === null) return 'All subjects';
        if ($fields['class_subject_ids'] === []) return 'No subjects';
        if ($fields['class_subject_names'] === []) return 'Subjects unavailable';
        return implode(', ', array_map(fn ($s) => $s['name'].($s['hidden_at'] ? ' (hidden)' : ''), $fields['class_subject_names']));
    }
}
