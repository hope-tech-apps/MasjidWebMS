<?php

namespace App\Support;

use App\Models\{ClassSubject, GroupStaff, Masjid};
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
                        ->update(['subjects' => $row['legacy'] === null ? null : json_encode($row['legacy'])]);
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
        $subjects = ClassSubject::where('masjid_id', $org->id)->get()->groupBy('group_id');
        $rows = GroupStaff::where('masjid_id', $org->id)->orderBy('id')->get();
        if ($lock) $rows = $rows->map(fn ($row) => GroupStaff::where('masjid_id', $org->id)->whereKey($row->id)->lockForUpdate()->firstOrFail());
        $inexpressible = [];
        foreach ($rows as $row) {
            [$expressible, $legacy] = self::legacyChoice($row, $subjects->get($row->group_id, collect()));
            $accepted = ! $expressible && in_array((int) $row->id, $accept, true);
            if (! $expressible) {
                $inexpressible[] = (int) $row->id;
                if (! $accepted) $report['blocked'][] = "Assignment #{$row->id}: this restriction cannot be expressed in legacy subjects without widening; explicitly accept unrestricted access.";
            }
            $report['assignments'][] = ['id' => (int) $row->id, 'class_id' => (int) $row->group_id, 'teacher_id' => (int) $row->user_id,
                'ids' => $row->class_subject_ids, 'legacy' => $legacy, 'expressible' => $expressible, 'accepted' => $accepted];
        }
        // Reject stale, foreign-school and unnecessary acceptances, as well as omissions.
        foreach (array_diff($accept, $inexpressible) as $id) $report['blocked'][] = "Assignment #{$id}: acceptance does not name an inexpressible restriction in this school.";
        return $report;
    }

    private static function legacyChoice(GroupStaff $row, $subjects): array
    {
        if ($row->class_subjects_mapped_at === null && $row->class_subject_ids_edited_at === null) return [false, null];
        $ids = $row->class_subject_ids;
        if ($ids === null) return [true, null];
        if (! SubjectFence::validStoredIds($ids) || $ids === []) return [false, null];
        $available = [];
        foreach (['quran' => 'hifdh', 'arabic' => 'arabic_letters', 'islamic_studies' => null] as $key => $tool) {
            $matches = $tool === null ? $subjects->filter(fn ($subject) => in_array('islamic studies', $subject->matchingKeys(), true)) : $subjects->where('tool', $tool);
            if ($matches->count() === 1) $available[$key] = (int) $matches->first()->id;
        }
        $wanted = array_values(array_unique(array_map('intval', $ids)));
        $legacy = array_keys(array_filter($available, fn ($id) => in_array($id, $wanted, true)));
        $resolved = array_values(array_unique(array_intersect_key($available, array_flip($legacy))));
        return count($resolved) === count($wanted) && array_diff($wanted, $resolved) === [] ? [true, $legacy] : [false, null];
    }
}
