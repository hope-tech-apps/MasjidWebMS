<?php

namespace Database\Factories;

use App\Models\MealMenu;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealMenu>
 */
class MealMenuFactory extends Factory
{
    protected $model = MealMenu::class;

    public function definition(): array
    {
        return [
            'masjid_id' => Masjid::first()?->id,
            'title' => 'Jummah Lunch',
            'service_date' => fake()->unique()->dateTimeBetween('+1 day', '+120 days')->format('Y-m-d'),
            'status' => MealMenu::STATUS_DRAFT,
            'ordering_closes_at' => null,
            'pickup_instructions' => 'Pick up after Jummah in the main hall.',
            'allow_online_payment' => true,
            'allow_pay_at_pickup' => true,
            'currency' => 'usd',
        ];
    }

    public function forMasjid(Masjid $masjid): static
    {
        return $this->state(['masjid_id' => $masjid->id]);
    }

    public function open(): static
    {
        return $this->state([
            'status' => MealMenu::STATUS_OPEN,
            'ordering_closes_at' => now()->addDay(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(['status' => MealMenu::STATUS_CLOSED]);
    }
}
