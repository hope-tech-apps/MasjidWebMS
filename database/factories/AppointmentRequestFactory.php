<?php

namespace Database\Factories;

use App\Models\AppointmentRequest;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AppointmentRequest>
 */
class AppointmentRequestFactory extends Factory
{
    protected $model = AppointmentRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default to an existing masjid (mirrors GroupFactory). Tests
            // normally pass masjid_id explicitly, or let a bound TenantContext
            // stamp it on create.
            'masjid_id' => Masjid::first()?->id,
            'applicant_name' => fake()->name(),
            'phone' => '+1' . fake()->numerify('##########'),
            'email' => fake()->optional()->safeEmail(),
            // Y-m-d string, matching what the public boundary enforces.
            'date_of_birth' => fake()->date('Y-m-d', '-18 years'),
            'reason' => fake()->sentence(8),
            'preferred_window' => fake()->optional()->randomElement([
                'Weekday mornings',
                'After 5pm',
                'Saturdays only',
            ]),
            'status' => AppointmentRequest::STATUS_NEW,
            'source' => AppointmentRequest::SOURCE_WEB,
        ];
    }
}
