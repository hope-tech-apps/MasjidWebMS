<?php

namespace Database\Factories;

use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Donation>
 */
class DonationFactory extends Factory
{
    protected $model = Donation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Minor units (cents). Default to a $50 pending gift.
        $intended = 5000;

        return [
            'uuid' => (string) Str::uuid(),
            'masjid_id' => Masjid::first()?->id,
            'contact_id' => null,
            'fund_id' => Fund::first()?->id,
            'type' => 'one_time',
            'intended_amount' => $intended,
            'charged_amount' => $intended,
            'currency' => 'usd',
            'donor_covers_fees' => false,
            'status' => 'pending',
            'idempotency_key' => 'checkout_' . Str::uuid(),
        ];
    }

    /** A succeeded donation with the Stripe identifiers populated. */
    public function succeeded(): self
    {
        return $this->state(fn () => [
            'status' => 'succeeded',
            'stripe_payment_intent_id' => 'pi_' . Str::random(16),
            'stripe_checkout_session_id' => 'cs_test_' . Str::random(16),
            'stripe_charge_id' => 'ch_' . Str::random(16),
            'stripe_balance_transaction_id' => 'txn_' . Str::random(16),
        ]);
    }
}
