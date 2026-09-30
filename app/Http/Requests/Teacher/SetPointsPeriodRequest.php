<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;
use App\Models\Group;
use Illuminate\Validation\Rule;

/**
 * A teacher choosing how their class's points read: one running total, or a week
 * at a time (T-003.2).
 *
 * Authorization is structural: the route sits inside `teacher.leads`, so the
 * caller already leads this class. The value is a string checked against
 * Group::POINTS_PERIODS, so it is unaffected by the form-encoded transport the SPA
 * posts with (.claude/rules/shipping.md: only booleans need coercing, and there is
 * none here).
 */
class SetPointsPeriodRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'points_period' => ['required', 'string', Rule::in(Group::POINTS_PERIODS)],
        ];
    }
}
