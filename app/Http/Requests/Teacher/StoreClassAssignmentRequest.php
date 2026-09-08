<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;

/**
 * Setting a piece of work for a class, or correcting one already set.
 *
 * `points_possible` is bounded from config as a FAT-FINGER GUARD, not a policy:
 * nothing here says work ought to be out of 10 or out of 100, only that a stray
 * keystroke cannot make it out of 100000.
 *
 * Lowering it on an EDIT can invalidate marks already entered (12 out of a
 * newly-lowered 10). That cannot be checked here — this class cannot see the
 * existing scores without a query — so the controller refuses it and names the
 * students. Validation that needs the database lives with the database.
 */
class StoreClassAssignmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'points_possible' => [
                'required', 'integer', 'min:1',
                'max:' . (int) config('groups.gradebook.max_points_possible', 1000),
            ],
            'assigned_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'points_possible.min' => 'Work has to be out of at least one point.',
        ];
    }
}
