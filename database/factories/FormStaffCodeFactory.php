<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\FormStaffCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FormStaffCode>
 *
 * A live staff code, stored the only way a code is ever stored: as its keyed
 * digest. The plaintext cannot be read back off the row, so a test that needs
 * to TYPE the code chooses it with withCode(). FormStaffCode::issue() is the
 * other way to get one; it returns the plaintext alongside the row.
 */
class FormStaffCodeFactory extends Factory
{
    protected $model = FormStaffCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = FormStaffCode::generate();

        return [
            'form_id' => Form::factory(),
            // After form_id on purpose: attribute closures see the keys already
            // resolved, so the code lands in its own form's masjid.
            'masjid_id' => fn (array $attributes) => Form::withTrashed()->find($attributes['form_id'])?->masjid_id,
            'holder_name' => fake()->name(),
            'code_hash' => FormStaffCode::hashFor($plain),
            'code_hint' => FormStaffCode::hintFor($plain),
            'expires_at' => now()->addDay(),
        ];
    }

    /** A code whose plaintext the test knows (any spelling normalise() accepts). */
    public function withCode(string $plain): static
    {
        return $this->state(fn () => [
            'code_hash' => FormStaffCode::hashFor($plain),
            'code_hint' => FormStaffCode::hintFor($plain),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
