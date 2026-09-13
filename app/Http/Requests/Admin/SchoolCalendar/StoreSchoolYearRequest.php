<?php

namespace App\Http\Requests\Admin\SchoolCalendar;

use App\Http\Requests\BaseFormRequest;
use App\Support\SchoolCalendar;
use App\Support\TenantContext;
use Illuminate\Validation\Validator;

/**
 * A school year's label and dates.
 *
 * The meeting weekday is the first day's, so the last day must fall on it too
 * — anything else is a typo, and a year that ended on a Saturday would silently
 * lose its final Sunday. Years in one organisation may not overlap; no index can
 * express that, so it is checked here (scoped: only this organisation's years).
 *
 * What depends on the year's closures is checked in the controller, under the
 * year's row lock (SchoolCalendarController::updateYear).
 */
class StoreSchoolYearRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:32'],
            'first_day' => ['required', 'date_format:Y-m-d'],
            'last_day' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->hasAny(['first_day', 'last_day'])) {
                return;
            }

            $first = SchoolCalendar::day((string) $this->input('first_day'));
            $last = SchoolCalendar::day((string) $this->input('last_day'));

            if ($first === null || $last === null) {
                return;
            }

            if ($last->lt($first)) {
                $v->errors()->add('last_day', 'The last day cannot be before the first day.');

                return;
            }

            if ($last->dayOfWeek !== $first->dayOfWeek) {
                $v->errors()->add('last_day', sprintf(
                    'The last day must be a %s, the day the year starts on (%s).',
                    $first->format('l'),
                    SchoolCalendar::label($first->toDateString()),
                ));

                return;
            }

            if ((int) $first->diffInDays($last) > SchoolCalendar::MAX_YEAR_DAYS) {
                $v->errors()->add('last_day', 'A school year cannot run longer than a year.');

                return;
            }

            // Asked again under the organisation's row lock in the controller;
            // this early answer is for the message on an ordinary save.
            $overlap = SchoolCalendar::overlappingYear(
                (int) (app(TenantContext::class)->get() ?? $this->route('masjid_id')),
                $first->toDateString(),
                $last->toDateString(),
                $this->yearBeingEdited(),
            );

            if ($overlap !== null) {
                $v->errors()->add('first_day', SchoolCalendar::overlapMessage($overlap));
            }
        });
    }

    /** The year being edited, which its own dates may overlap. */
    protected function yearBeingEdited(): ?int
    {
        return null;
    }
}
