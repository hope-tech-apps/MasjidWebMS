<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;
use App\Models\ClassAssignment;
use App\Support\PerformanceLevel;
use App\Support\SchoolSettings;
use App\Support\SimpleMark;
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
 * The Excellent / Good / Needs work scale is forced to SimpleMark::MAX the same
 * way.
 *
 * ---------------------------------------------------------------------------
 * WHICH scales a teacher may choose is the ORGANISATION'S (SchoolSettings)
 * ---------------------------------------------------------------------------
 *
 * Levels or points everywhere, as before; points or Excellent / Good / Needs
 * work where a SuperAdmin switched on `simple_marking` (BISS). A scale the
 * organisation does not offer is refused, with one exception: correcting work
 * that already exists keeps the scale it was set on, so a switch flipped later
 * never makes old work uneditable.
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
        $scale = $this->input('scale') ?? SchoolSettings::defaultScale($this->organisation());

        $merge = ['scale' => $scale];

        if ($scale === ClassAssignment::SCALE_LEVELS) {
            $merge['points_possible'] = PerformanceLevel::MAX;
        }

        if ($scale === ClassAssignment::SCALE_SIMPLE) {
            $merge['points_possible'] = SimpleMark::MAX;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'scale' => ['required', Rule::in($this->offeredScales())],
            'points_possible' => [
                'required', 'integer', 'min:1',
                'max:' . (int) config('groups.gradebook.max_points_possible', 1000),
            ],
            'assigned_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * What this organisation offers, plus the scale the work being corrected
     * already has.
     *
     * @return list<string>
     */
    private function offeredScales(): array
    {
        $offered = SchoolSettings::gradingScales($this->organisation());

        $existing = $this->route('assignment_id')
            ? ClassAssignment::query()->whereKey((int) $this->route('assignment_id'))->value('scale')
            : null;

        return $existing !== null ? array_values(array_unique([...$offered, $existing])) : $offered;
    }

    /** The organisation in the URL, which the `tenant` middleware has already matched to the teacher. */
    private function organisation(): ?\App\Models\Masjid
    {
        return SchoolSettings::org($this->route('masjid_id'));
    }

    public function messages(): array
    {
        return [
            'points_possible.min' => 'Work has to be out of at least one point.',
            'scale.in' => 'That is not a grading scale this gradebook understands.',
        ];
    }
}
