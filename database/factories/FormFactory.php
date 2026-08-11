<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Form>
 *
 * Added by T-006a: Form predates factories (its tests build rows by hand), but
 * offerings.intake_form_id is NOT NULL, so OfferingFactory needs a Form to
 * point at. Minimal by design — one section, one required field — enough to be
 * a real FormSchema without prescribing anything.
 */
class FormFactory extends Factory
{
    protected $model = Form::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            // Default to an existing masjid (mirrors ContactFactory). Tests
            // normally pass masjid_id explicitly.
            'masjid_id' => Masjid::first()?->id,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'schema' => [
                'sections' => [
                    [
                        'id' => 'main',
                        'title' => 'Registration',
                        'fields' => [
                            ['name' => 'full_name', 'type' => 'text', 'label' => 'Full name', 'required' => true],
                        ],
                    ],
                ],
            ],
            'is_active' => true,
        ];
    }
}
