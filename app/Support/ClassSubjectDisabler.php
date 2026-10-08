<?php

namespace App\Support;

use App\Models\{GroupStaff, Masjid};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A deliberate return to legacy authority, preserving every stored ID and link. */
final class ClassSubjectDisabler
{
    private static bool $disabling = false;

    public static function assertAllowed(Masjid $org): void
    {
        if (! self::$disabling && (SchoolSettings::classSubjects($org) || ! empty(($org->capability_overrides ?? [])[ClassSubjectInitializer::MARKER]))) {
            throw ValidationException::withMessages(['capability' => ['Use class-subjects:disable --masjid='.$org->id.' to review legacy access before disabling.']]);
        }
    }

    public static function run(Masjid $org, bool $dryRun = false, array $accept = []): array
    {
        return app(TenantContext::class)->runWithout(function () use ($org, $dryRun, $accept) {
            if ($dryRun) return self::report(Masjid::whereKey($org->id)->firstOrFail(), $accept);
            return DB::transaction(function () use ($org, $accept) {
                $locked = Masjid::whereKey($org->id)->lockForUpdate()->firstOrFail();
                $report = self::report($locked, $accept, true);
                if ($report['blocked'] !== [] || ! $report['enabled']) return $report;
                foreach ($report['assignments'] as $row) {
                    DB::table('group_staff')->where('masjid_id', $locked->id)->where('id', $row['id'])
                        ->update(['subjects' => $row['raw_legacy']]);
                }
                self::$disabling = true;
                try { CapabilityWriter::apply($locked, ['class_subjects' => false], null); }
                finally { self::$disabling = false; }
                return $report;
            });
        });
    }

    /** Shared report; the dry-run branch invokes only ordinary SELECTs. */
    private static function report(Masjid $org, array $accept, bool $lock = false): array
    {
        if ($org->orgType() !== Masjid::ORG_TYPE_SCHOOL) throw ValidationException::withMessages(['masjid' => ['Class subjects disabling requires a school.']]);
        $report = ['enabled' => SchoolSettings::classSubjects($org), 'assignments' => [], 'blocked' => []];
        if (! $report['enabled']) {
            if ($accept !== []) $report['blocked'][] = 'The school is already OFF; no unrestricted acceptance applies.';
            return $report;
        }
        $rows = GroupStaff::where('masjid_id', $org->id)->orderBy('id')->get();
        if ($lock) $rows = $rows->map(fn ($row) => GroupStaff::where('masjid_id', $org->id)->whereKey($row->id)->lockForUpdate()->firstOrFail());
        $inexpressible = [];
        foreach ($rows as $row) {
            [$expressible, $legacy, $rawLegacy] = self::legacyChoice($row);
            $accepted = ! $expressible && in_array((int) $row->id, $accept, true);
            if (! $expressible) {
                $inexpressible[] = (int) $row->id;
                if (! $accepted) $report['blocked'][] = "Assignment #{$row->id}: this restriction cannot be expressed in legacy subjects without widening; explicitly accept unrestricted access.";
            }
            $report['assignments'][] = ['id' => (int) $row->id, 'class_id' => (int) $row->group_id, 'teacher_id' => (int) $row->user_id,
                'ids' => $row->class_subject_ids, 'legacy' => $legacy, 'raw_legacy' => $rawLegacy, 'expressible' => $expressible, 'accepted' => $accepted];
        }
        // Reject stale, foreign-school and unnecessary acceptances, as well as omissions.
        foreach (array_diff($accept, $inexpressible) as $id) $report['blocked'][] = "Assignment #{$id}: acceptance does not name an inexpressible restriction in this school.";
        return $report;
    }

    /** Unedited translated rows return to their exact source; other restrictions need acceptance. */
    private static function legacyChoice(GroupStaff $row): array
    {
        $raw = $row->getRawOriginal('class_subjects_translated_from');
        if ($row->class_subjects_mapped_at !== null && $row->class_subject_ids_edited_at === null && $raw !== null) {
            $legacy = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return [true, $legacy, $legacy === null ? null : $raw];
        }
        // SQL NULL means no recorded source; JSON null is a recorded legacy NULL.
        // No equivalence guess may widen an edited/created restricted assignment silently.
        return [$row->class_subject_ids === null, null, null];
    }
}
