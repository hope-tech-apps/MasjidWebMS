<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Private switch dates also identify schools with retained period records after disabling. */
final class PeriodAttendanceMode
{
    public const CAPABILITY = 'period_attendance';
    public const DAILY_DATES = 'period_attendance_daily_dates';
    public const WINDOWS = 'period_attendance_windows';
    public static function school(int $id): ?Masjid { return ClassSubjectMode::loadedOrganisation($id) ?? Masjid::find($id); }
    public static function enabled(?Masjid $school): bool { return $school !== null && ! $school->trashed() && $school->isSchool() && $school->hasCapability(self::CAPABILITY); }
    public static function held(int $id, ?Masjid $loaded = null): bool { return ! empty(($loaded ?? self::school($id))?->capability_overrides[self::WINDOWS]); }
    public static function usesPeriods(int $id, string $date, ?Masjid $school = null): bool
    {
        $school ??= self::school($id);
        if (! self::enabled($school)) return false;
        foreach ($school->capability_overrides[self::WINDOWS] ?? [] as $window) if ($date >= $window['from'] && ($window['to'] === null || $date <= $window['to'])) return true;
        return false;
    }
    /** Called only for the new key. Other keys retain CapabilityWriter's frozen path and ledger. */
    public static function apply(Masjid $org, array $changes, ?int $actor): array
    {
        $enabled = $changes[self::CAPABILITY];
        if (! is_bool($enabled)) throw new \InvalidArgumentException('Period attendance must be a boolean.');
        return DB::transaction(function () use ($org,$changes,$actor,$enabled) {
            $locked=Masjid::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $timetable=$changes['school_timetable'] ?? SchoolSettings::timetable($locked);
            if ($enabled && ! $timetable) throw ValidationException::withMessages(['capability'=>'Switch on the school timetable before switching on period attendance.']);
            if ($enabled && ! $locked->isSchool()) throw ValidationException::withMessages(['capability'=>'Period attendance is for schools.']);
            $other=$changes; unset($other[self::CAPABILITY]);
            $result=$other===[]?['changed'=>[],'unchanged'=>[]]:CapabilityWriter::apply($locked,$other,$actor);
            $locked->refresh(); $before=self::enabled($locked);
            // Hidden OFF no-op: the same policy as absence emails, with no override or ledger row.
            if (! $before && ! $enabled) { $result['unchanged'][]=self::CAPABILITY; return $result; }
            $overrides=$locked->capability_overrides ?? []; $overrideBefore=$overrides[self::CAPABILITY]??null;
            $windows=$overrides[self::WINDOWS]??[]; $today=SchoolCalendarReaders::for((int)$org->id)->today();
            if ($enabled && ! $before) {
                $windows[]=['from'=>$today,'to'=>null];
                foreach ($overrides[self::DAILY_DATES] ?? [] as $group=>$dates) $overrides[self::DAILY_DATES][$group]=array_values(array_diff($dates,[$today]));
            }
            if (! $enabled && $before && $windows!==[]) $windows[count($windows)-1]['to']=$today;
            $overrides[self::WINDOWS]=$windows; $overrides[self::CAPABILITY]=$enabled;
            $locked->forceFill(['capability_overrides'=>$overrides,'updated_by'=>$actor])->save();
            CapabilityLedger::record($locked,self::CAPABILITY,$before,$enabled,$overrideBefore,$actor);
            ClassSubjectMode::forget((int)$org->id); SchoolCalendarRequestMode::forget((int)$org->id);
            DB::afterCommit(fn()=>MobileCache::flushFamily($locked));
            $result[$enabled===$before?'unchanged':'changed'][]=self::CAPABILITY;
            return $result;
        });
    }
}
