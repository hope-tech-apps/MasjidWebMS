<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;
use App\Models\ClassAssignment;
use App\Support\PerformanceLevel;
use Illuminate\Validation\Rule;

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
 *
 * ---------------------------------------------------------------------------
 * `scale` is OPTIONAL, and its default is the ORGANISATION'S, not 'points'
 * ---------------------------------------------------------------------------
 *
 * A school on the four performance levels should not have to choose the scale
 * on every single piece of work, so an absent `scale` takes
 * `config('groups.default_grading_scale')`. That is a different knob from the
 * COLUMN default, which is `points` because every row that already exists was
 * marked as points — see the migration.
 *
 * On a levels assignment `points_possible` is FORCED to PerformanceLevel::MAX
 * in prepareForValidation rather than being required from the client. A teacher
 * choosing "performance levels" has already said everything there is to say
 * about the maximum, and a form that then asked them for one would be offering a
 * way to create a five-level assignment the rest of the system cannot read.
 */
class StoreClassAssignmentRequest extends BaseFormRequest
{
    /**
     * Fill in what the scale already implies, before any rule runs.
     *
     * Deliberately overwrites rather than defaults: a payload that names
     * `levels` AND `points_possible: 7` is not a request to be honoured, and
     * silently ignoring the 7 is better than a 422 about a field the teacher was
     * never shown.
     */
    protected function prepareForValidation(): void
    {
        $scale = $this->input('scale') ?? config('groups.default_grading_scale', ClassAssignment::SCALE_POINTS);

        $merge = ['scale' => $scale];

        if ($scale === ClassAssignment::SCALE_LEVELS) {
            $merge['points_possible'] = PerformanceLevel::MAX;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'scale' => ['required', Rule::in(ClassAssignment::SCALES)],
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
            'scale.in' => 'That is not a grading scale this gradebook understands.',
        ];
    }
}
