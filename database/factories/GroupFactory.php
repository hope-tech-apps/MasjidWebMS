<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            // Default to an existing masjid (mirrors ContactFactory). Tests
            // normally pass masjid_id explicitly, or let a bound TenantContext
            // stamp it on create.
            'masjid_id' => Masjid::first()?->id,
            'name' => $name,
            // Suffixed so two masjids seeded from the same factory run never
            // collide on the per-masjid unique (masjid_id, slug) index.
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'kind' => Group::KIND_GENERAL,
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
