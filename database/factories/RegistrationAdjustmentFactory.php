<?php

namespace Database\Factories;

use App\Models\Masjid;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RegistrationAdjustment>
 */
class RegistrationAdjustmentFactory extends Factory
{
    protected $model = RegistrationAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'masjid_id' => Masjid::first()?->id,
            'registration_id' => Registration::factory(),
            'kind' => RegistrationAdjustment::KIND_AID,
            // Minor units — a $25.00 reduction.
            'amount_minor' => 2500,
            'reason' => 'Financial aid — approved by the office',
            'granted_by_user_id' => null,
        ];
    }

    public function discount(): self
    {
        return $this->state(fn () => [
            'kind' => RegistrationAdjustment::KIND_DISCOUNT,
            'reason' => 'Sibling discount',
        ]);
    }

    public function code(string $code = 'EARLYBIRD'): self
    {
        return $this->state(fn () => [
            'kind' => RegistrationAdjustment::KIND_CODE,
            'reason' => "Code {$code}",
        ]);
    }

    public function grantedBy(User $user): self
    {
        return $this->state(fn () => ['granted_by_user_id' => $user->id]);
    }
}
