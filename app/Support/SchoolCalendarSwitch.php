<?php

namespace App\Support;

use App\Models\{Masjid, SchoolYear};
use Illuminate\Validation\ValidationException;

/** Called after the writer takes the organisation PK lock, before any ordinary read. */
final class SchoolCalendarSwitch
{
    public static function configure(Masjid $org, bool $enable): void
    {
        $wasEnabled = SchoolSettings::calendarTerms($org);
        if (! $wasEnabled && ! $enable) return;
        // The org mutex is the first statement; these plain reads see preceding writers' commits.
        $ids = SchoolYear::query()->where('masjid_id', $org->id)->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            // Existing primary key equality: record lock, no nonunique range/gap lock.
            $year = SchoolYear::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($enable) self::validateRetainedTerms($year);
            if (! $wasEnabled && $enable) {
                SchoolYear::withoutTimestamps(fn () => $year->update(['meeting_weekdays' => [$year->meetingWeekday()]]));
            }
            if (! $enable) {
                if (SchoolDateAuthority::weekdays($year) !== [$year->meetingWeekday()]) {
                    throw ValidationException::withMessages(['capability' => 'This calendar cannot be switched off while a school year meets on days the weekly calendar cannot represent. Set each year to the weekday of its first day first.']);
                }
                if ($year->last_day->dayOfWeek !== $year->meetingWeekday()) {
                    throw ValidationException::withMessages(['capability' => 'This calendar cannot be switched off while a last day is off the weekday of its first day. Correct the last day first.']);
                }
                foreach ($year->closures()->get() as $closure) {
                    if ($closure->closed_on->dayOfWeek !== $year->meetingWeekday()) {
                        throw ValidationException::withMessages(['capability' => 'This calendar cannot be switched off while a no-school day is off the weekday of its year. Remove that no-school day first.']);
                    }
                }
            }
        }
    }
    private static function validateRetainedTerms(SchoolYear $year): void
    {
        $previous = null;
        foreach ($year->terms()->orderBy('position')->get() as $term) {
            $reason = null;
            if ($term->starts_on->gt($term->ends_on) || $term->starts_on->lt($year->first_day) || $term->ends_on->gt($year->last_day)) {
                $reason = 'the term must be inside its school year';
            } elseif ($previous && $term->starts_on->lte($previous->ends_on)) {
                $reason = 'term dates must not overlap and positions must follow date order';
            }
            if ($reason) {
                throw ValidationException::withMessages(['capability' => 'School year "'.$year->label.'", term "'.$term->name.'": '.$reason.'. Change the retained dates before switching on.']);
            }
            $previous = $term;
        }
    }

}
