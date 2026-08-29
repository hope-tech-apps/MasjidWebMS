<?php

namespace Database\Factories;

use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealOrder>
 */
class MealOrderFactory extends Factory
{
    protected $model = MealOrder::class;

    public function definition(): array
    {
        return [
            'masjid_id' => Masjid::first()?->id,
            'meal_menu_id' => fn (array $attributes) => MealMenu::factory()
                ->create(['masjid_id' => $attributes['masjid_id']])->id,
            'contact_id' => null,
            'order_number' => (string) fake()->unique()->numberBetween(1, 999),
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->numerify('##########'),
            'customer_email' => fake()->safeEmail(),
            'status' => MealOrder::STATUS_PENDING,
            'payment_method' => MealOrder::METHOD_PICKUP,
            'payment_status' => MealOrder::PAYMENT_UNPAID,
            'subtotal_minor' => 800,
            'total_minor' => 800,
            'currency' => 'usd',
            'placed_at' => now(),
        ];
    }

    public function online(): static
    {
        return $this->state(['payment_method' => MealOrder::METHOD_ONLINE]);
    }

    public function paid(): static
    {
        return $this->state([
            'payment_status' => MealOrder::PAYMENT_PAID,
            'status' => MealOrder::STATUS_CONFIRMED,
            'paid_at' => now(),
        ]);
    }
}
