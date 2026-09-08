<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;

/**
 * Writing one day's lesson plan.
 *
 * THE DATE RULE IS THE MIRROR IMAGE OF THE REGISTER'S, and copying the
 * register's would break the only thing this feature is for.
 * SaveAttendanceRequest bounds `session_date` at `before_or_equal:today`,
 * because a register for a day that has not happened is a typo. A PLAN for a day
 * that has not happened is the entire point. The bound here is a year out, which
 * catches a mistyped year without forbidding planning a term ahead.
 *
 * Authorization is absent by design: it is structural, from the `teacher.leads`
 * middleware every route in this realm sits behind.
 */
class SaveLessonPlanRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'session_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:' . now()->addYear()->toDateString(),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:' . (int) config('groups.lessons.max_body_length', 5000)],
        ];
    }

    public function messages(): array
    {
        return [
            'session_date.before_or_equal' => 'A plan for a day more than a year out is a typo, not foresight.',
            'body.required' => 'A plan needs something in it.',
        ];
    }
}
