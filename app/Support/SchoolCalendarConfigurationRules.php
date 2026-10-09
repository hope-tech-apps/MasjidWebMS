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
            'terms' => ['sometimes', 'array', 'list', 'max:255'],
            'terms.*.id' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'terms.*.name' => ['required', 'string', 'max:80'],
            'terms.*.starts_on' => ['required', 'date_format:Y-m-d'],
            'terms.*.ends_on' => ['required', 'date_format:Y-m-d'],
            'terms.*.position' => ['required', 'integer', 'between:1,255'],
        ];
    }

    public static function yearValidator(Request $request, Validator $validator, ?int $ignore): void
    {
        $validator->setCustomMessages(['meeting_weekdays.required' => 'Choose at least one meeting day.', 'meeting_weekdays.min' => 'Choose at least one meeting day.']);
        $attributes = [];
        foreach ((array) $request->input('terms', []) as $i => $term) {
            $name = is_array($term) && is_string($term['name'] ?? null) ? $term['name'] : 'Term '.($i + 1);
            foreach (['id'=>'reference', 'name'=>'name', 'starts_on'=>'starts on', 'ends_on'=>'ends on', 'position'=>'term number'] as $field=>$label) $attributes["terms.$i.$field"] = "$name: $label";
        }
        $validator->setAttributeNames($attributes);
        $validator->after(function (Validator $v) use ($request, $ignore) {
            if ($v->errors()->hasAny(['first_day','last_day','meeting_weekdays']) || $v->errors()->isNotEmpty()) return;
            $first = SchoolCalendar::day($request->input('first_day')); $last = SchoolCalendar::day($request->input('last_day'));
            if ($last->lt($first)) { $v->errors()->add('last_day', 'The last day cannot be before the first day.'); return; }
            $weekdays = array_map('intval', $request->input('meeting_weekdays'));
            if (! in_array($first->dayOfWeek, $weekdays, true)) $v->errors()->add('first_day', 'The first day must be one of the days the school meets.');
            if (! in_array($last->dayOfWeek, $weekdays, true)) $v->errors()->add('last_day', 'The last day must be one of the days the school meets.');
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
                $v->errors()->add('closed_on', 'The no-school day must be one of the days the school meets.'); return;
            }
            if (SchoolClosure::query()->where('masjid_id', $org)->where('school_year_id', $year->id)->whereDate('closed_on', $day)->exists()) {
                $v->errors()->add('closed_on', SchoolCalendar::label($day).' is already a no-school day.');
            }
        });
    }
}
