<?php

namespace App\Support;

use App\Models\{Masjid, MasjidPointsSetting};

/** OFF grant and clock time, read only for schools that have requested absence emails. */
final class AttendanceAbsenceSettings
{
    public const CAPABILITY = 'attendance_absence_emails';
    public const DEFAULT_TIME = '10:00';

    public static function enabled(?Masjid $school): bool
    {
        return $school !== null && ! $school->trashed() && $school->isSchool() && $school->hasCapability(self::CAPABILITY);
    }

    public static function time(int $school): string
    {
        $value = MasjidPointsSetting::query()->where('masjid_id', $school)->value('attendance_absence_send_time');
        return PointsReportSchedule::validTime($value) ? $value : self::DEFAULT_TIME;
    }
}
