<?php

namespace App\Http\Requests\Admin\SchoolCalendar;

use App\Http\Requests\BaseFormRequest;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Support\SchoolCalendar;
use App\Support\TenantContext;
use Illuminate\Validation\Validator;

/**
 * A no-school day: inside its year, on the year's meeting weekday, once.
 *
 * A date outside the year or off its weekday is a typo — there was never going
 * to be school that day — so it is refused rather than stored as a closure of
 * nothing. The year is found through the tenant scope, so another
 * organisation's year reads as one that does not exist.
 *
 * Whether a register was already taken that day is NOT checked here: it is
 * counted inside the controller's transaction, under the year's row lock
 * (SchoolCalendarController::storeClosure).
 */
class StoreSchoolClosureRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'school_year_id' => ['required', 'integer'],
            'closed_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:160'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->hasAny(['school_year_id', 'closed_on'])) {
                return;
            }

            // Named as well as scoped (.claude/rules/school-calendar.md).
            $masjidId = (int) (app(TenantContext::class)->get() ?? $this->route('masjid_id'));

            $year = SchoolYear::query()
                ->where('masjid_id', $masjidId)
                ->whereKey((int) $this->input('school_year_id'))
                ->first();

            if ($year === null) {
                $v->errors()->add('school_year_id', 'That school year does not exist.');

                return;
            }

            $day = (string) $this->input('closed_on');
            $first = $year->first_day->toDateString();
            $last = $year->last_day->toDateString();

            if ($day < $first || $day > $last) {
                $v->errors()->add('closed_on', sprintf(
                    '%s is outside the %s school year (%s to %s).',
                    SchoolCalendar::label($day),
                    $year->label,
                    SchoolCalendar::label($first),
                    SchoolCalendar::label($last),
                ));

                return;
            }

            if (SchoolCalendar::day($day)?->dayOfWeek !== $year->meetingWeekday()) {
                $v->errors()->add('closed_on', sprintf(
                    '%s is not a %s, the day this school meets.',
                    SchoolCalendar::label($day),
                    SchoolCalendar::weekdayName($year->meetingWeekday()),
                ));

                return;
            }

            if (SchoolClosure::query()->where('masjid_id', $masjidId)->where('school_year_id', $year->id)->whereDate('closed_on', $day)->exists()) {
                $v->errors()->add('closed_on', SchoolCalendar::label($day).' is already a no-school day.');
            }
        });
    }
}
