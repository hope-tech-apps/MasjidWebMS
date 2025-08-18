<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Masjid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'masjid_id' => Masjid::first()->id,
            'title' => $this->faker->name() . ' Announcement',
            'details' => $this->faker->text(),
            'start_date' => Carbon::now()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(3)->format('Y-m-d'),
            'link' => 'masjidwebsite.test/announcements'
        ];
    }

    public function configure()
    {
        return $this->afterCreating(function (Announcement $announcement) {
            $announcement->addMedia(storage_path('app/public/images/quruan-book.jpeg'))
            ->preservingOriginal()
            ->toMediaCollection('announcements');
        });
    }
}
