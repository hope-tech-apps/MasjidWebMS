<?php

namespace Database\Factories;

use App\Models\GroupMessage;
use App\Models\GroupThread;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GroupMessage>
 */
class GroupMessageFactory extends Factory
{
    protected $model = GroupMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default to existing rows (mirrors GroupPostFactory); tests pass
            // ids explicitly or rely on the bound TenantContext for masjid_id.
            'masjid_id' => Masjid::first()?->id,
            'group_thread_id' => GroupThread::first()?->id,
            'author_user_id' => null,
            'body' => fake()->paragraph(),
        ];
    }
}
