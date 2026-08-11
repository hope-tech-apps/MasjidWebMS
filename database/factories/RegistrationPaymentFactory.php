<?php

namespace Database\Factories;

use App\Models\Masjid;
use App\Models\Registration;
use App\Models\RegistrationPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RegistrationPayment>
 *
 * Default is a settled one-time charge as the webhook handlers record it —
 * in production a row usually exists only once money moved, so `succeeded`
 * with the Stripe identifiers populated is the realistic base state.
 */
class RegistrationPaymentFactory extends Factory
{
    protected $model = RegistrationPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Minor units: $150.00 charged, Stripe kept its 2.9% + 30¢.
        $amount = 15000;
        $fee = 465;

        return [
            'masjid_id' => Masjid::first()?->id,
            'registration_id' => Registration::factory(),
            'amount_minor' => $amount,
            'stripe_payment_intent_id' => 'pi_' . Str::random(16),
            'stripe_charge_id' => 'ch_' . Str::random(16),
            'stripe_balance_transaction_id' => 'txn_' . Str::random(16),
            'stripe_fee_minor' => $fee,
            'net_minor' => $amount - $fee,
            'status' => RegistrationPayment::STATUS_SUCCEEDED,
            'paid_at' => now(),
        ];
    }

    /** An installment charge as invoice.payment_succeeded records it. */
    public function fromInvoice(): self
    {
        return $this->state(fn () => [
            'stripe_invoice_id' => 'in_' . Str::random(16),
        ]);
    }

    /** charge.refunded recorded on the row — the roster stays untouched. */
    public function refunded(): self
    {
        return $this->state(fn () => [
            'status' => RegistrationPayment::STATUS_REFUNDED,
        ]);
    }
}
