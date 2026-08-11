<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\Registrant;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Registrant>
 */
class RegistrantFactory extends Factory
{
    protected $model = Registrant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'masjid_id' => Masjid::first()?->id,
            'registration_id' => Registration::factory(),
            // Resolved after the ids above so the Contact lands in the SAME
            // tenant rather than Masjid::first().
            'contact_id' => fn (array $attributes) => Contact::factory()->create([
                'masjid_id' => $attributes['masjid_id'],
            ])->id,
        ];
    }
}
