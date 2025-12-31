<?php

namespace Database\Factories;

use App\Models\Masjid;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Service>
 */
class ServiceFactory extends Factory
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
            'title' => $this->faker->name(),
            'description' => $this->faker->text(),
            'text' => $this->faker->paragraph()
        ];
    }

    public function configure()
    {
        return $this->afterCreating(function (Service $service) {
            $service->addMedia(storage_path('app/public/images/charity_icon.png'))
            ->preservingOriginal()
            ->toMediaCollection('servicesIcons');
            $service->addMedia(storage_path('app/public/images/masjid_on_zokhrufa.png'))
            ->preservingOriginal()
            ->toMediaCollection('services');
        });
    }
}
