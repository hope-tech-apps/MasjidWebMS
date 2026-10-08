<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupStaff;
use Illuminate\Validation\ValidationException;

final class ClassSubjectAssignmentResolution
{
    public static function officeFields(?GroupStaff $row): array
    {
        if ($row !== null && ClassSubjectInitializer::needsMapping($row)) {
            return ['class_subject_assignment_needs_attention' => true];
        }
        return ['class_subject_ids' => $row?->class_subject_ids];
    }

    /** A stale baseline may be replaced only with a deliberate choice of the list. */
    public static function validate(GroupStaff $row, array $fields, string $resolution): void
    {
        if (! in_array($resolution, ['confirm_legacy', 'allow_more'], true)) self::refuse();
        $ids = array_key_exists('class_subject_ids', $fields) ? $fields['class_subject_ids'] : $row->class_subject_ids;
        $group = Group::withTrashed()->where('masjid_id', $row->masjid_id)->findOrFail($row->group_id);
        $valid = \App\Models\ClassSubject::where('masjid_id', $row->masjid_id)->where('group_id', $row->group_id)->pluck('id')->all();
        if (! SubjectFence::validStoredIds($ids) || ($ids !== null && array_diff($ids, $valid) !== [])) self::refuse();
        // Use the persisted legacy allowance, before any incoming legacy edits.
        if ($resolution === 'confirm_legacy') {
            $allowed = ClassSubjectInitializer::mapLegacy($group, $row->getOriginal('subjects'));
            if ($allowed !== null && ($ids === null || array_diff($ids, $allowed) !== [])) {
                throw ValidationException::withMessages(['class_subject_ids' => ['This list grants more than the current legacy subjects. Explicitly choose allow_more to grant additional subjects.']]);
            }
        }
    }

    public static function refuse(): never
    {
        throw ValidationException::withMessages(['class_subject_ids' => ['This assignment needs attention because its legacy subjects changed. Explicitly confirm the intended subject list before saving it.']]);
    }
}
