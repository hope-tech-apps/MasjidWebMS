<?php

namespace Database\Factories;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Group;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BehaviorAward>
 */
class BehaviorAwardFactory extends Factory
{
    protected $model = BehaviorAward::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default to existing rows (mirrors GroupPostFactory). Tests pass
            // masjid_id + group_id + group_membership_id explicitly — the
            // membership especially, since it names the student and there is no
            // sensible default for "which child".
            'masjid_id' => Masjid::first()?->id,
            'group_id' => Group::first()?->id,
            'group_membership_id' => null,
            'behavior_skill_id' => null,
            'awarded_by_user_id' => null,
            // The SNAPSHOT: written onto the award, never read back from a
            // skill row — the same shape production takes.
            'skill_label' => ucfirst(fake()->words(2, true)),
            'skill_polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'points' => 1,
            'note' => null,
            // Left null so BehaviorAward's creating hook stamps `now()`.
            'awarded_at' => null,
            'revoked_by_user_id' => null,
            // Left null so the creating hook applies the configured retention
            // window — the same path production takes.
            'retained_until' => null,
        ];
    }
}
