<?php

namespace App\Support;

use App\Models\Masjid;
use App\Models\MasjidPointsSetting;

/**
 * WHEN a school's weekly points report goes out (T-003.3): a weekday and a time on
 * the school's own clock.
 *
 * Owner, 2026-09-28: "Friday afternoon in the school's time zone; BISS (Sundays
 * only) gets it Sunday evening." So the DEFAULT is Friday 15:00 (Al-Razi's
 * "Friday afternoon"; its dismissal time is Unknown, needs investigation, which is
 * why the time is settable), and a school that needs another moment has a row in
 * masjid_points_settings, written by a SuperAdmin (BISS: Sunday 18:00, seeded by
 * 2026_10_02_130000). Each column falls back on its own, so setting only a time
 * keeps the default weekday.
 *
 * DELIBERATELY NOT DERIVED from the school calendar. A school year models one
 * weekly meeting day, and a school that meets five days a week and later enters a
 * calendar would have its report silently move to "its meeting day". A schedule that
 * changes because somebody filled in a different screen is not a setting.
 *
 * Validation lives here so the SuperAdmin endpoint and the command agree on what a
 * usable value is; a stored value that is somehow not usable (a hand-edited row) is
 * read as the default rather than crashing the hourly sweep.
 */
final class PointsReportSchedule
{
    /** Friday. 0 = Sunday, the numbering SchoolCalendar uses. */
    public const DEFAULT_WEEKDAY = 5;

    /** 15:00 on the school's clock. */
    public const DEFAULT_TIME = '15:00';

    /**
     * @return array{weekday:int,time:string,weekday_source:string,time_source:string}
     */
    public static function for(Masjid $masjid): array
    {
        $row = MasjidPointsSetting::withoutMasjidScope()->where('masjid_id', $masjid->id)->first();

        $weekday = $row?->report_weekday;
        $time = $row?->report_time;

        $weekdayOk = is_int($weekday) && self::validWeekday($weekday);
        $timeOk = is_string($time) && self::validTime($time);

        return [
            'weekday' => $weekdayOk ? $weekday : self::DEFAULT_WEEKDAY,
            'time' => $timeOk ? $time : self::DEFAULT_TIME,
            'weekday_source' => $weekdayOk ? 'set' : 'default',
            'time_source' => $timeOk ? 'set' : 'default',
        ];
    }

    public static function validWeekday(mixed $weekday): bool
    {
        return is_int($weekday) && $weekday >= 0 && $weekday <= 6;
    }

    /** 'H:i', 00:00 to 23:59, zero padded. */
    public static function validTime(mixed $time): bool
    {
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }
}
