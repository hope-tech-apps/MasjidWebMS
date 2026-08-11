<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupPost;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GroupPost>
 */
class GroupPostFactory extends Factory
{
    protected $model = GroupPost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default to existing rows (mirrors GroupFactory). Tests normally
            // pass masjid_id + group_id explicitly, or let a bound
            // TenantContext stamp the masjid on create.
            'masjid_id' => Masjid::first()?->id,
            'group_id' => Group::first()?->id,
            'author_user_id' => null,
            'title' => ucfirst(fake()->words(3, true)),
            'body' => fake()->paragraph(),
            // Left null so GroupPost's creating hook applies the configured
            // retention window — the same path production takes.
            'retained_until' => null,
        ];
    }
}
