<?php

namespace App\Support;

use App\Models\{ClassSubject, Group, Masjid};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit repair of NULL links, including rows already examined at activation. */
final class ClassSubjectRelinker
{
    /** Dry runs are plain SELECTs; real runs recheck capability and work under the school mutex. */
    public static function run(Masjid $org, bool $dryRun = false): array
    {
        return app(TenantContext::class)->runWithout(function () use ($org, $dryRun) {
            if ($dryRun) return self::report(Masjid::whereKey($org->id)->firstOrFail(), false);
            return DB::transaction(function () use ($org) {
                // First statement: acquire the current school before establishing a read view.
                $locked = Masjid::whereKey($org->id)->lockForUpdate()->firstOrFail();
                return self::report($locked, true);
            });
        });
    }

    private static function report(Masjid $org, bool $write): array
    {
        if ($org->orgType() !== Masjid::ORG_TYPE_SCHOOL || ! SchoolSettings::classSubjects($org)) {
            throw ValidationException::withMessages(['masjid' => ['Relinking requires a school with class subjects ON.']]);
        }
        // Include archived classes and historical work whose group predates the class kind.
        $groups = Group::withTrashed()->where('masjid_id', $org->id)->where(function ($query) use ($org) {
            $query->where('kind', Group::KIND_CLASS);
            foreach (ClassSubjectSavedWork::TABLES as $table) {
                $query->orWhereIn('id', DB::table($table)->where('masjid_id', $org->id)->select('group_id'));
            }
        })->orderBy('id')->get();
        $report = [];
        foreach ($groups as $group) {
            if ($write) $group = Group::withTrashed()->where('masjid_id', $org->id)->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $subjects = $write ? ClassSubjectInitializer::currentSubjects($group)
                : ClassSubject::where('masjid_id', $org->id)->where('group_id', $group->id)->get();
            $row = ['class_id' => $group->id, 'class' => $group->name, 'linked' => [], 'unlinked' => []];
            foreach (ClassSubjectSavedWork::rows($group) as $work) {
                $query = DB::table($work['table'])->where('masjid_id', $org->id)->where('group_id', $group->id)->where('id', $work['id']);
                $text = $work['subject'];
                if ($write) {
                    $current = (clone $query)->lockForUpdate()->first(['subject', 'class_subject_id']);
                    if ($current === null || $current->class_subject_id !== null) continue;
                    $text = $current->subject;
                }
                $subject = ClassSubjectSavedWork::matchingSubject($text, $subjects);
                if ($subject === null) {
                    // JSON keeps NULL distinct from empty text and preserves exact spelling in the report.
                    $key = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $row['unlinked'][$key] = ($row['unlinked'][$key] ?? 0) + 1;
                    continue;
                }
                $count = $write ? $query->whereNull('class_subject_id')->update([
                    'class_subject_id' => $subject->id, 'class_subject_link_checked_at' => now(),
                ]) : 1;
                $row['linked'][$subject->id] ??= ['name' => $subject->name, 'count' => 0];
                $row['linked'][$subject->id]['count'] += $count;
            }
            $report[] = $row;
        }
        return $report;
    }
}
