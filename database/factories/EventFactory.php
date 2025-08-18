<?php

namespace Database\Factories;

use App\Models\Masjid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
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
            'title' => $this->faker->name() . ' Event',
            'details' => $this->faker->text(),
            'place' => $this->faker->address(),
            'start' => $this->faker->dateTimeBetween(now(), now()->addWeek()),
            'end' => $this->faker->dateTimeBetween(now()->addWeek(), now()->addWeek()->addMonth()),
            'link' => 'masjidwebsite.test/events',
        ];
    }
}
