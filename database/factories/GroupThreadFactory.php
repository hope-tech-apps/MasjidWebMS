<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupThread;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GroupThread>
 */
class GroupThreadFactory extends Factory
{
    protected $model = GroupThread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default to existing rows (mirrors GroupPostFactory). Tests
            // normally pass masjid_id + group_id explicitly, or let a bound
            // TenantContext stamp the masjid on create.
            'masjid_id' => Masjid::first()?->id,
            'group_id' => Group::first()?->id,
            'created_by_user_id' => null,
            'subject' => ucfirst(fake()->words(4, true)),
            // Group-wide by default; participant-scoped threads need a real
            // membership row, which only the calling test can supply.
            'scope' => GroupThread::SCOPE_GROUP,
            'about_membership_id' => null,
            'closed_at' => null,
            // Left null so GroupThread's creating hook applies the configured
            // retention window — the same path production takes.
            'retained_until' => null,
        ];
    }
}
