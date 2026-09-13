<?php

namespace App\Support;

use App\Models\SchoolClosure;
use App\Models\SchoolYear;

/**
 * The one hand-built calendar payload, shared by the admin screen and the
 * teacher and family reads (the OfferingPublicPayload pattern: never a model's
 * toArray(), so a column added later is not published by accident).
 *
 * It carries dates and office-written reasons only — no person, no child.
 */
final class SchoolCalendarPayload
{
    /** @return array{timezone:string,today:string,years:array<int,array<string,mixed>>} */
    public static function admin(SchoolCalendar $calendar): array
    {
        return [
            'timezone' => $calendar->timezone(),
            'today' => $calendar->today(),
            'years' => $calendar->years()->map(fn (SchoolYear $year): array => [
                'id' => $year->id,
                'label' => $year->label,
                'first_day' => $year->first_day->toDateString(),
                'last_day' => $year->last_day->toDateString(),
                'meeting_weekday' => $year->meetingWeekday(),
                'meeting_days' => $calendar->meetingDays($year),
                'closures' => $year->closures->map(fn (SchoolClosure $closure): array => [
                    'id' => $closure->id,
                    'closed_on' => $closure->closed_on->toDateString(),
                    'reason' => $closure->reason,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** The admin shape plus the next meeting days, for a teacher or a family. */
    public static function reader(SchoolCalendar $calendar): array
    {
        return self::admin($calendar) + ['upcoming' => $calendar->upcoming()];
    }
}
