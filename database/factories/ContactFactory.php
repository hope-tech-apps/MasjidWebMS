<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default to an existing masjid (mirrors AnnouncementFactory). Tests
            // normally pass masjid_id explicitly, or let a bound TenantContext
            // stamp it on create.
            'masjid_id' => Masjid::first()?->id,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->numerify('+1##########'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
