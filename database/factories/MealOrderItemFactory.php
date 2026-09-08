<?php

namespace Database\Factories;

use App\Models\MealOrder;
use App\Models\MealOrderItem;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealOrderItem>
 */
class MealOrderItemFactory extends Factory
{
    protected $model = MealOrderItem::class;

    public function definition(): array
    {
        return [
            'masjid_id' => Masjid::first()?->id,
            'meal_order_id' => fn (array $attributes) => MealOrder::factory()
                ->create(['masjid_id' => $attributes['masjid_id']])->id,
            'meal_menu_item_id' => null,
            'item_name' => 'Chicken Biryani Plate',
            'unit_price_minor' => 800,
            'quantity' => 1,
            'line_total_minor' => 800,
        ];
    }
}
