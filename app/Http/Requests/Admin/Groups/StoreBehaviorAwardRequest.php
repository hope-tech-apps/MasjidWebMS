<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

/**
 * Award a behaviour skill to ONE student (T-013).
 *
 * Both ids are only SHAPE-checked here. Whether `membership_id` names a
 * participant of THIS group, and whether `behavior_skill_id` names a skill of
 * this tenant, are settled in BehaviorAwardsController through the
 * tenant-scoped relations — so another organization's id is a miss.
 * Re-implementing the tenant filter in an `exists:` rule would duplicate the
 * guardrail (.claude/rules/tenant-scoping.md) and, worse, an `exists:` rule
 * would confirm the existence of a row the caller may not see.
 *
 * `points` is OPTIONAL: absent, the controller snapshots the skill's
 * `default_points`. Present, it overrides for this one award — a teacher who
 * wants to mark something as exceptional should not have to edit the whole
 * school's vocabulary to do it. Either way the value is frozen onto the award.
 *
 * `awarded_at` is optional and may be in the past (a teacher entering Friday's
 * awards on Sunday), but never in the future: recognition for something that
 * has not happened yet is a mistake, not an instruction — the same reasoning
 * that makes `retained_until` `after_or_equal:today`.
 *
 * masjid_id is NOT accepted and never will be — the BelongsToMasjid creating
 * hook stamps it from the bound tenant.
 */
class StoreBehaviorAwardRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $maxPoints = max(1, (int) config('groups.behavior.max_points', 100));

        return [
            'membership_id' => 'required|integer',
            'behavior_skill_id' => 'required|integer',
            'points' => ['sometimes', 'integer', 'min:' . (-$maxPoints), 'max:' . $maxPoints],
            'note' => 'nullable|string|max:' . (int) config('groups.behavior.max_note_length', 1000),
            'awarded_at' => 'nullable|date|before_or_equal:now',
            'retained_until' => 'nullable|date|after_or_equal:today',
        ];
    }

    public function messages(): array
    {
        return [
            'awarded_at.before_or_equal' => 'An award cannot be dated in the future.',
        ];
    }
}
