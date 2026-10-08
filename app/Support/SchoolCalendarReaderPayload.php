<?php

namespace App\Support;

/** Additive ON calendar shape; the legacy serializer remains frozen. */
final class SchoolCalendarReaderPayload
{
    public static function reader(SchoolDateAuthority $calendar): array
    {
        $calendar->years()->loadMissing('terms');
        return [
            'timezone' => $calendar->timezone(), 'today' => $calendar->today(),
            'years' => $calendar->years()->map(fn ($year) => [
                'id' => $year->id, 'label' => $year->label,
                'first_day' => $year->first_day->toDateString(), 'last_day' => $year->last_day->toDateString(),
                'meeting_weekday' => $year->meetingWeekday(),
                'meeting_weekdays' => SchoolDateAuthority::weekdays($year),
                'meeting_days' => $calendar->meetingDays($year),
                'term_system' => $year->term_system,
                'terms' => $year->terms->map(fn ($term) => [
                    'id' => $term->id, 'name' => $term->name,
                    'starts_on' => $term->starts_on->toDateString(), 'ends_on' => $term->ends_on->toDateString(), 'position' => $term->position,
                ])->values()->all(),
                'closures' => $year->closures->map(fn ($closure) => [
                    'id' => $closure->id, 'closed_on' => $closure->closed_on->toDateString(), 'reason' => $closure->reason,
                ])->values()->all(),
            ])->values()->all(),
            'upcoming' => $calendar->upcoming(),
        ];
    }
}
