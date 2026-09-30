<?php

namespace App\Http\Requests\Admin\Masjids;

use App\Http\Requests\BaseFormRequest;
use App\Support\PointsReportSchedule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A SuperAdmin setting when a school's weekly points report goes out (T-003.3).
 *
 * Either half, or both, or null for "the default". `report_weekday` arrives as a
 * digit STRING from the SPA's form-encoded transport, so it is coerced to an int in
 * prepareForValidation (.claude/rules/shipping.md: coerce in the request, never at
 * the call site) and anything that is not a whole number stays as it was and fails.
 * The rules live in PointsReportSchedule, the same place the command reads them,
 * so the two cannot disagree about what a usable value is.
 */
class SetPointsReportScheduleRequest extends BaseFormRequest
{
    /**
     * The SuperAdmin check is authorize(), not a controller line: a FormRequest
     * validates at injection, so a check in the body would show a non-super admin
     * (who passes the route's middleware) validation errors before refusing them.
     */
    public function authorize(): bool
    {
        return $this->user()?->type === 'SuperAdmin';
    }

    protected function failedAuthorization(): void
    {
        throw new HttpException(Response::HTTP_FORBIDDEN, 'Only a super admin can change when a school\'s weekly points report is sent.');
    }

    protected function prepareForValidation(): void
    {
        $day = $this->input('report_weekday');

        if (is_string($day) && preg_match('/^\d$/', $day) === 1) {
            $this->merge(['report_weekday' => (int) $day]);
        }
    }

    public function rules(): array
    {
        return [
            'report_weekday' => ['sometimes', 'nullable'],
            'report_time' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();

            if (! $this->exists('report_weekday') && ! $this->exists('report_time')) {
                $v->errors()->add('report_weekday', 'Send a weekday, a time, or both.');

                return;
            }

            $day = $data['report_weekday'] ?? null;
            if ($this->exists('report_weekday') && $day !== null && ! PointsReportSchedule::validWeekday($day)) {
                $v->errors()->add('report_weekday', 'The weekday is a number from 0 (Sunday) to 6 (Saturday).');
            }

            $time = $data['report_time'] ?? null;
            if ($this->exists('report_time') && $time !== null && ! PointsReportSchedule::validTime($time)) {
                $v->errors()->add('report_time', 'The time is 24-hour HH:MM on the school\'s clock, such as 15:00.');
            }
        });
    }
}
