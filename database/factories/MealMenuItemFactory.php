<?php

namespace Database\Factories;

use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealMenuItem>
 */
class MealMenuItemFactory extends Factory
{
    protected $model = MealMenuItem::class;

    public function definition(): array
    {
        return [
            'masjid_id' => Masjid::first()?->id,
            // The parent menu inherits this item's tenant, so a multi-masjid test
            // never lands an item on another tenant's menu.
            'meal_menu_id' => fn (array $attributes) => MealMenu::factory()
                ->create(['masjid_id' => $attributes['masjid_id']])->id,
            'name' => fake()->randomElement(['Chicken Biryani Plate', 'Beef Biryani Plate', 'Samosa', 'Water']),
            'description' => null,
            'price_minor' => 800,
            'is_available' => true,
            'max_quantity' => null,
            'sort_order' => 0,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(['is_available' => false]);
    }
}
