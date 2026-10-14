<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Independent hidden OFF grants; no configuration or history is public while disabled. */
final class SchoolAdmissionsMode
{
    public const RECORDS = 'school_reenrolment_records';
    public const KEYS = ['school_reenrolment', 'school_applications'];
    public static function enabled(?Masjid $school, string $key): bool
    {
        return $school !== null && ! $school->trashed() && $school->isSchool() && $school->hasCapability($key);
    }
    /** The request already loaded this school; unused OFF readers add no SQL. */
    public static function recordsHeld(int $school): bool
    {
        return ! empty(ClassSubjectMode::loadedOrganisation($school)?->capability_overrides[self::RECORDS]);
    }

    /** A private retention hint survives disabling; it grants no feature or access. */
    public static function rememberRecords(int $school): void
    {
        DB::transaction(function () use ($school) {
            $org = Masjid::withTrashed()->whereKey($school)->lockForUpdate()->firstOrFail();
            $overrides = $org->capability_overrides ?? [];
            if (! empty($overrides[self::RECORDS])) return;
            $overrides[self::RECORDS] = true;
            $org->forceFill(['capability_overrides' => $overrides])->save();
        });
    }

    public static function apply(Masjid $org, array $changes, ?int $actor): array
    {
        return DB::transaction(function () use ($org, $changes, $actor) {
            $locked = Masjid::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $other = array_diff_key($changes, array_flip(self::KEYS));
            $result = $other ? CapabilityWriter::apply($locked, $other, $actor) : ['changed' => [], 'unchanged' => []];
            $locked->refresh();
            foreach (self::KEYS as $key) {
                if (! array_key_exists($key, $changes)) continue;
                $value = $changes[$key];
                if (! is_bool($value)) throw new \InvalidArgumentException('School admission switches must be boolean.');
                if ($value && ! $locked->isSchool()) throw ValidationException::withMessages(['capability' => 'Admissions and re-enrolment are for schools.']);
                $before = self::enabled($locked, $key);
                if (! $before && ! $value) { $result['unchanged'][] = $key; continue; }
                $overrides = $locked->capability_overrides ?? []; $previous = $overrides[$key] ?? null;
                $overrides[$key] = $value;
                $locked->forceFill(['capability_overrides' => $overrides, 'updated_by' => $actor])->save();
                CapabilityLedger::record($locked, $key, $before, $value, $previous, $actor);
                $result[$before === $value ? 'unchanged' : 'changed'][] = $key;
            }
            ClassSubjectMode::forget((int) $org->id);
            DB::afterCommit(fn () => MobileCache::flushFamily($locked));
            return $result;
        });
    }
}
