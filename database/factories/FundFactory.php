<?php

namespace Database\Factories;

use App\Models\Fund;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Fund>
 */
class FundFactory extends Factory
{
    protected $model = Fund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Tests normally pass masjid_id explicitly, or let a bound
            // TenantContext stamp it on create (mirrors ContactFactory).
            'masjid_id' => Masjid::first()?->id,
            'name' => fake()->randomElement(['Zakat', 'Sadaqah', 'General Fund']),
            'type' => fake()->randomElement(Fund::TYPES),
            'receiptable' => true,
            'is_active' => true,
        ];
    }
}
