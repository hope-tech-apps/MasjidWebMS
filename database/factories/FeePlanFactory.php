<?php

namespace Database\Factories;

use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Offering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FeePlan>
 */
class FeePlanFactory extends Factory
{
    protected $model = FeePlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'masjid_id' => Masjid::first()?->id,
            'offering_id' => Offering::factory(),
            'kind' => FeePlan::KIND_ONE_TIME,
            // Minor units — a $150.00 one-time fee.
            'amount_minor' => 15000,
            'currency' => 'usd',
            'billing_interval' => null,
            'installment_count' => null,
            'label' => 'Standard',
            'is_active' => true,
        ];
    }

    public function free(): self
    {
        return $this->state(fn () => [
            'kind' => FeePlan::KIND_FREE,
            'amount_minor' => 0,
            'label' => 'Free',
        ]);
    }

    /** N monthly charges via a Subscription Schedule (end_behavior=cancel). */
    public function installment(int $count = 9, int $perChargeMinor = 10000): self
    {
        return $this->state(fn () => [
            'kind' => FeePlan::KIND_INSTALLMENT,
            'amount_minor' => $perChargeMinor,
            'billing_interval' => FeePlan::INTERVAL_MONTH,
            'installment_count' => $count,
            'label' => "Monthly — {$count} payments",
        ]);
    }

    /** Open-ended recurring (membership dues). */
    public function recurring(string $interval = FeePlan::INTERVAL_MONTH, int $perChargeMinor = 2500): self
    {
        return $this->state(fn () => [
            'kind' => FeePlan::KIND_RECURRING,
            'amount_minor' => $perChargeMinor,
            'billing_interval' => $interval,
            'installment_count' => null,
            'label' => ucfirst($interval) . 'ly dues',
        ]);
    }

    /** Superseded by a replacement plan (immutable rows deactivate, never edit). */
    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
