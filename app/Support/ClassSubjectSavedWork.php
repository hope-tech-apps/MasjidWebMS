<?php

namespace App\Support;

use App\Models\ClassSubject;
use App\Models\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClassSubjectSavedWork
{
    /** The two saved-work authorities matched by subject key; deleted gradework is retained. */
    public static function counts(int $masjidId, int $groupId, ?array $keys = null, array $excluded = []): array
    {
        $counts = [];
        // Use gradebook's native WHERE IN comparison, including the database collation.
        foreach (DB::table('class_assignments')->where('masjid_id', $masjidId)->where('group_id', $groupId)
            ->whereNotNull('subject_key')->where('subject_key', '<>', '')
            ->when($keys !== null, fn ($q) => $q->whereIn('subject_key', $keys))
            ->select('subject_key')->cursor() as $row) {
            // Detail fences use exact keys. SQL GROUP BY/NOT IN can fold distinct
            // keys together and wrongly treat another subject's details as already owned.
            if (in_array($row->subject_key, $excluded, true)) continue;
            $counts[$row->subject_key] = ($counts[$row->subject_key] ?? 0) + 1;
        }
        // Lesson plans retain an older storage key; their access fence folds the subject itself.
        foreach (DB::table('lesson_plans')->where('masjid_id', $masjidId)->where('group_id', $groupId)
            ->select('subject')->cursor() as $row) {
            $key = SubjectKey::for($row->subject);
            if ($key === '' || ($keys !== null && ! in_array($key, $keys, true)) || in_array($key, $excluded, true)) continue;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    public static function check(ClassSubject $row, bool $attachOrphans = false): array
    {
        $current = $row->exists ? ClassSubject::whereKey($row->getKey())->firstOrFail() : null;
        if ($current !== null && ((int) $current->group_id !== (int) $row->group_id || (int) $current->masjid_id !== (int) $row->masjid_id)) {
            throw ValidationException::withMessages(['name' => ['A class subject cannot be moved to another class or school.']]);
        }
        $before = $current?->matchingKeys() ?? [];
        $added = array_diff($row->matchingKeys(), $before);
        if ($added === []) return [];
        $group = Group::withTrashed()->findOrFail($row->group_id);
        $orgId = ! $row->exists ? (app(TenantContext::class)->get() ?? $row->masjid_id ?? $group->masjid_id) : ($row->masjid_id ?? $group->masjid_id);
        if ((int) $orgId !== (int) $group->masjid_id) {
            throw ValidationException::withMessages(['name' => ['Choose a class in this school.']]);
        }
        $others = ClassSubject::where('masjid_id', $orgId)->where('group_id', $group->id)
            ->when($row->exists, fn ($q) => $q->where('id', '<>', $row->id))->get();
        $claimed = $others->flatMap(fn ($s) => $s->matchingKeys())->all();
        foreach ($added as $key) {
            if (in_array($key, $claimed, true)) {
                throw ValidationException::withMessages(['name' => ["Subject key \"{$key}\" already belongs to another subject in this class."]]);
            }
        }
        $attachments = self::counts((int) $orgId, (int) $group->id, array_values($added), $before);
        foreach (array_keys($attachments) as $key) {
            if (in_array($key, $claimed, true)) {
                throw ValidationException::withMessages(['name' => ["Saved work uses subject key \"{$key}\". This subject cannot claim it."]]);
            }
        }
        if ($attachments !== [] && ($row->exists || ! $attachOrphans)) {
            $key = array_key_first($attachments);
            throw ValidationException::withMessages(['name' => ["Saved work uses subject key \"{$key}\". This subject cannot claim it."]]);
        }
        return $attachments;
    }
}
