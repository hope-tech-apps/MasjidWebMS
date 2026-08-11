<?php

namespace App\Http\Requests\Admin\Groups;

use App\Models\BehaviorSkill;
use Illuminate\Validation\Rule;

/**
 * Edit a behaviour skill (T-013).
 *
 * Every field is `sometimes`, so a caller may retire a skill
 * (`is_active: false`) without restating its label and weight.
 *
 * EDITING NEVER REWRITES HISTORY. Renaming "Disruption" to "Off-task" or
 * re-weighting it from 1 to 3 changes what the NEXT award records; awards
 * already given carry their own snapshot of label, polarity and points, exactly
 * like the fee-plan snapshots on registrations. That is a property of
 * BehaviorAward, not something this request needs to guard — it is noted here
 * because it is the reason this request is allowed to be liberal.
 */
class UpdateBehaviorSkillRequest extends BehaviorSkillFormRequest
{
    public function rules(): array
    {
        return [
            'label' => [
                'sometimes', 'required', 'string', 'max:255',
                // Ignore this row, or renaming a skill to its own label fails.
                $this->uniqueLabelRule((int) $this->route('skill_id')),
            ],
            'polarity' => ['sometimes', 'required', Rule::in(BehaviorSkill::POLARITIES)],
            'default_points' => $this->pointsRules(required: false),
            'is_active' => 'sometimes|boolean',
        ];
    }
}
