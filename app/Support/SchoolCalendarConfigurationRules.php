<?php

namespace App\Support;

use App\Models\{SchoolYear, SchoolClosure};
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/** Request checks for ON only. Controllers repeat dependent checks under the year mutex. */
final class SchoolCalendarConfigurationRules
{
    public static function enabled(Request $request): bool
    {
        return SchoolCalendarRequestMode::enabled((int) (app(TenantContext::class)->get() ?? $request->route('masjid_id')));
    }

    public static function yearRules(): array
    {
        return [
            'label' => ['required', 'string', 'max:32'],
            'first_day' => ['required', 'date_format:Y-m-d'],
            'last_day' => ['required', 'date_format:Y-m-d'],
            'meeting_weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'meeting_weekdays.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'term_system' => ['sometimes', 'nullable', 'in:quarters,semesters,trimesters'],
        ];
    }

    public static function yearValidator(Request $request, Validator $validator, ?int $ignore): void
    {
        $validator->after(function (Validator $v) use ($request, $ignore) {
            if ($v->errors()->hasAny(['first_day','last_day','meeting_weekdays']) || $v->errors()->isNotEmpty()) return;
            $first = SchoolCalendar::day($request->input('first_day')); $last = SchoolCalendar::day($request->input('last_day'));
            if ($last->lt($first)) { $v->errors()->add('last_day', 'The last day cannot be before the first day.'); return; }
            $weekdays = array_map('intval', $request->input('meeting_weekdays'));
            if (! in_array($first->dayOfWeek, $weekdays, true)) $v->errors()->add('first_day', 'The first day must be on a configured meeting weekday.');
            if (! in_array($last->dayOfWeek, $weekdays, true)) $v->errors()->add('last_day', 'The last day must be on a configured meeting weekday.');
            if ((int) $first->diffInDays($last) > SchoolCalendar::MAX_YEAR_DAYS) { $v->errors()->add('last_day', 'A school year cannot run longer than a year.'); return; }
            $overlap = SchoolCalendar::overlappingYear((int) (app(TenantContext::class)->get() ?? $request->route('masjid_id')), $first->toDateString(), $last->toDateString(), $ignore);
            if ($overlap) $v->errors()->add('first_day', SchoolCalendar::overlapMessage($overlap));
        });
    }

    public static function closureValidator(Request $request, Validator $validator): void
    {
        $validator->after(function (Validator $v) use ($request) {
            if ($v->errors()->hasAny(['school_year_id', 'closed_on'])) return;
            $org = (int) (app(TenantContext::class)->get() ?? $request->route('masjid_id'));
            $year = SchoolYear::query()->where('masjid_id', $org)->whereKey($request->input('school_year_id'))->first();
            if (! $year) { $v->errors()->add('school_year_id', 'That school year does not exist.'); return; }
            $day = $request->input('closed_on');
            if ($day < $year->first_day->toDateString() || $day > $year->last_day->toDateString()) {
                $v->errors()->add('closed_on', 'The no-school day must be inside its school year.'); return;
            }
            if (! in_array(SchoolCalendar::day($day)->dayOfWeek, SchoolDateAuthority::weekdays($year), true)) {
                $v->errors()->add('closed_on', 'The no-school day must be on a configured meeting weekday.'); return;
            }
            if (SchoolClosure::query()->where('masjid_id', $org)->where('school_year_id', $year->id)->whereDate('closed_on', $day)->exists()) {
                $v->errors()->add('closed_on', SchoolCalendar::label($day).' is already a no-school day.');
            }
        });
    }
}
