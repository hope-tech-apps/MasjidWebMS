<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;
use App\Models\LessonPlan;
use Illuminate\Validation\Rule;

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
 * ## EVERY TEMPLATE FIELD IS `nullable`, NEVER `sometimes`
 *
 * The endpoint is a whole-row upsert, so the client must send the whole object
 * every time and an omitted field means "cleared". `sometimes` would make a
 * partial payload silently keep stale prose while appearing to save — the worst
 * of both. The one consequence the frontend must honour is that there can be no
 * per-section autosave; one Save writes the plan.
 *
 * `body` (the template's ACTIVITIES) stays required: it is NOT NULL in the
 * database, and a plan with no activities is not a plan.
 *
 * Authorization is absent by design: it is structural, from the `teacher.leads`
 * middleware every route in this realm sits behind.
 */
class SaveLessonPlanRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $prose = (int) config('groups.lessons.max_section_length', 2000);

        return [
            'session_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:' . now()->addYear()->toDateString(),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:' . (int) config('groups.lessons.max_body_length', 5000)],

            'subject' => ['nullable', 'string', 'max:64'],
            'grade_label' => ['nullable', 'string', 'max:32'],
            'curriculum_week_no' => ['nullable', 'integer', 'min:1', 'max:52'],

            'standard_code' => ['nullable', 'string', 'max:32'],
            'standard_description' => ['nullable', 'string', 'max:' . $prose],

            'objective' => ['nullable', 'string', 'max:' . $prose],
            'learning_outcomes' => ['nullable', 'array', 'max:10'],
            'learning_outcomes.*' => ['nullable', 'string', 'max:500'],

            'differentiation_support' => ['nullable', 'string', 'max:' . $prose],
            'differentiation_extension' => ['nullable', 'string', 'max:' . $prose],
            'differentiation_learning_styles' => ['nullable', 'string', 'max:' . $prose],
            'differentiation_ell_aal' => ['nullable', 'string', 'max:' . $prose],
            'differentiation_sen' => ['nullable', 'string', 'max:' . $prose],

            'cross_integration_subject' => ['nullable', 'string', 'max:' . $prose],
            'cross_integration_islamic' => ['nullable', 'string', 'max:' . $prose],
            'cross_integration_stem' => ['nullable', 'string', 'max:' . $prose],

            'teaching_methods' => ['nullable', 'array', 'max:' . count(LessonPlan::TEACHING_METHODS)],
            'teaching_methods.*' => [Rule::in(LessonPlan::TEACHING_METHODS)],
            'teaching_methods_other' => ['nullable', 'string', 'max:255'],
            'teaching_aids' => ['nullable', 'string', 'max:' . $prose],

            'assessment_formative' => ['nullable', 'string', 'max:' . $prose],
            'assessment_exit_ticket' => ['nullable', 'string', 'max:' . $prose],

            'reflection_worked' => ['nullable', 'string', 'max:' . $prose],
            'reflection_improve' => ['nullable', 'string', 'max:' . $prose],
        ];
    }

    public function messages(): array
    {
        return [
            'session_date.before_or_equal' => 'A plan for a day more than a year out is a typo, not foresight.',
            'body.required' => 'A plan needs its activities.',
            'teaching_methods.*.in' => 'That is not a teaching method this form knows.',
        ];
    }
}
