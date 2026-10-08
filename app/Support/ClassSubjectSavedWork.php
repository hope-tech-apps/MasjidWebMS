<?php

namespace App\Support;

use App\Models\ClassSubject;
use App\Models\Group;
use Illuminate\Support\Facades\DB;

/** Activation/explicit office attachment only. Names never authorize saved work. */
final class ClassSubjectSavedWork
{
    public const TABLES = ['class_assignments', 'lesson_plans'];

    /** Unlinked rows, including withdrawn gradebook work; never include child prose. */
    public static function rows(Group $group, bool $unexaminedOnly = false): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            foreach (DB::table($table)->where('masjid_id', $group->masjid_id)->where('group_id', $group->id)
                ->whereNull('class_subject_id')->when($unexaminedOnly, fn ($q) => $q->whereNull('class_subject_link_checked_at'))
                ->orderBy('id')->get(['id', 'subject', 'subject_key']) as $row) {
                // Use stored keys exactly, without SQL collation or historical-name claims.
                $rows[] = ['table' => $table, 'id' => $row->id, 'key' => $row->subject_key,
                    'general' => $table === 'lesson_plans' && SubjectKey::clean($row->subject) === null];
            }
        }
        return $rows;
    }

    public static function matchingSubject(string $key, $subjects): ?ClassSubject
    {
        $matches = $subjects->filter(fn ($s) => in_array($key, ClassSubjectInitializer::aliases($s->name_key), true));
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** Every examined row is stamped, so an unmatched key is never implicitly tried again. */
    public static function linkAtActivation(Group $group, $subjects): void
    {
        foreach (self::rows($group, true) as $row) {
            $current = DB::table($row['table'])->where('masjid_id', $group->masjid_id)->where('group_id', $group->id)
                ->where('id', $row['id'])->lockForUpdate()->first(['subject', 'subject_key', 'class_subject_id', 'class_subject_link_checked_at']);
            if ($current === null || $current->class_subject_id !== null || $current->class_subject_link_checked_at !== null) continue;
            $general = $row['table'] === 'lesson_plans' && SubjectKey::clean($current->subject) === null;
            $subject = $general ? null : self::matchingSubject($current->subject_key, $subjects);
            DB::table($row['table'])->where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->where('id', $row['id'])
                ->whereNull('class_subject_id')->whereNull('class_subject_link_checked_at')
                ->update(['class_subject_id' => $subject?->id, 'class_subject_link_checked_at' => now()]);
        }
    }

    /** Caller holds school/class mutexes. Explicitly attach NULL links, never take another id's work. */
    public static function attach(ClassSubject $subject): array
    {
        $group = Group::withTrashed()->where('masjid_id', $subject->masjid_id)->findOrFail($subject->group_id);
        $subjects = ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->get();
        $counts = [];
        foreach (self::rows($group) as $row) {
            $current = DB::table($row['table'])->where('masjid_id', $group->masjid_id)->where('group_id', $group->id)
                ->where('id', $row['id'])->lockForUpdate()->first(['subject', 'subject_key', 'class_subject_id']);
            if ($current === null || $current->class_subject_id !== null
                || ($row['table'] === 'lesson_plans' && SubjectKey::clean($current->subject) === null)
                || self::matchingSubject($current->subject_key, $subjects)?->id !== $subject->id) continue;
            $count = DB::table($row['table'])->where('masjid_id', $group->masjid_id)->where('group_id', $group->id)
                ->where('id', $row['id'])->whereNull('class_subject_id')
                ->update(['class_subject_id' => $subject->id, 'class_subject_link_checked_at' => now()]);
            $counts[$current->subject_key] = ($counts[$current->subject_key] ?? 0) + $count;
        }
        return $counts;
    }
}
