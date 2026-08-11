<?php

namespace App\Http\Requests\Admin\Groups;

use App\Models\BehaviorSkill;
use Illuminate\Validation\Rule;

/**
 * Define a behaviour skill — one entry in this tenant's recognition vocabulary
 * (T-013).
 *
 * `polarity` is required rather than defaulted: whether a school is recognising
 * something or correcting it is the one thing nobody else can infer, and a
 * silent default would let "Disruption" land in a parent's summary under
 * encouragement.
 */
class StoreBehaviorSkillRequest extends BehaviorSkillFormRequest
{
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255', $this->uniqueLabelRule()],
            'polarity' => ['required', Rule::in(BehaviorSkill::POLARITIES)],
            'default_points' => $this->pointsRules(required: true),
            'is_active' => 'sometimes|boolean',
        ];
    }
}
