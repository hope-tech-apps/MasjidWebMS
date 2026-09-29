<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;
use App\Models\ClassAssignment;
use App\Models\ClassGradeWeight;
use App\Support\PerformanceLevel;
use App\Support\SchoolSettings;
use App\Support\SimpleMark;
use App\Support\SubjectKey;
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
 * SUBJECT, TYPE, WEIGHT and STANDARD (T-001.1, T-001.2, T-001.3)
 * ---------------------------------------------------------------------------
 *
 * All four are OPTIONAL in the API and an absent key means "leave as it is" on an
 * edit (an older screen still open in a tab must not wipe them), while a key
 * sent as null CLEARS. `subject` is REQUIRED in the teacher's form, not here
 * (owner decision G10), except for a subject-LIMITED teacher, whose fence
 * (App\Support\SubjectFence) the controller enforces.
 *
 * What this class can check without the class it is for is checked here: the
 * type is one of ClassAssignment::TYPES, the weight is 0-100, and every text
 * field has the length of its column (SQLite would store more; MySQL would
 * refuse). What needs the class is checked in GradebookController: that the
 * subject is on the school's list, that a weight override has a weighted class
 * to override, and that a standard is really one the school's own guide names.
 *
 * WHERE THE SCHOOL HAS TURNED OFF STANDARDS (`short_lesson_plan`, BISS) the three
 * standard keys are dropped before validation: not shown AND not written, the
 * rule the lesson plan's hidden fields follow.
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

        // Text keys are cleaned only when present, so an absent key stays absent.
        if ($this->has('subject')) {
            $merge['subject'] = is_string($this->input('subject')) ? SubjectKey::clean($this->input('subject')) : $this->input('subject');
        }

        if (! SchoolSettings::showsStandards($this->organisation())) {
            // getInputSource(): a JSON body lives in json(), a form body in request.
            foreach (['standard_code', 'curriculum_focus', 'curriculum_week_no'] as $key) {
                $this->getInputSource()->remove($key);
            }
        }

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
            // The lengths are the columns' own (class_assignments migration).
            'subject' => ['sometimes', 'nullable', 'string', 'max:64'],
            'type' => ['sometimes', 'nullable', 'string', Rule::in(ClassAssignment::TYPES)],
            'weight' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:' . ClassGradeWeight::MAX],
            'standard_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'curriculum_focus' => ['sometimes', 'nullable', 'string', 'max:500'],
            'curriculum_week_no' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
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
            'type.in' => 'That is not a type of work this gradebook understands.',
            'weight.max' => 'A weight is between 0 and ' . ClassGradeWeight::MAX . '.',
        ];
    }
}
