<?php

namespace Database\Factories;

use App\Models\BehaviorSkill;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BehaviorSkill>
 */
class BehaviorSkillFactory extends Factory
{
    protected $model = BehaviorSkill::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default to an existing row (mirrors GroupFactory). Tests normally
            // pass masjid_id explicitly, or let a bound TenantContext stamp it.
            'masjid_id' => Masjid::first()?->id,
            // Unique per tenant at the DB level, so the label must not collide
            // across a test that seeds several.
            'label' => ucfirst(fake()->unique()->words(2, true)),
            'polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'default_points' => 1,
            'is_active' => true,
        ];
    }

    /** A correction rather than a recognition. */
    public function negative(): static
    {
        return $this->state(fn () => ['polarity' => BehaviorSkill::POLARITY_NEGATIVE]);
    }

    /** Retired from the picker; past awards stay legible. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
