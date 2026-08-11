<?php

namespace Database\Factories;

use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Registration>
 *
 * Default is the moment AFTER T-006b's intake transaction for a paid one-time
 * plan: seat reserved (`pending`), money `awaiting`, totals snapshotted from
 * the plan, checkout window open. States cover the free path, a completed
 * payment, and the waitlist.
 */
class RegistrationFactory extends Factory
{
    protected $model = Registration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'masjid_id' => Masjid::first()?->id,
            'offering_id' => Offering::factory(),
            // Attribute order matters: by the time these closures run,
            // offering_id/masjid_id above have been resolved to real ids, so
            // the plan belongs to the SAME offering and tenant.
            'fee_plan_id' => fn (array $attributes) => FeePlan::factory()->create([
                'masjid_id' => $attributes['masjid_id'],
                'offering_id' => $attributes['offering_id'],
            ])->id,
            'contact_id' => null,
            'form_response_id' => null,
            'status' => Registration::STATUS_PENDING,
            'payment_status' => Registration::PAYMENT_AWAITING,
            // Snapshots, minor units — match FeePlanFactory's one-time default.
            'list_total_minor' => 15000,
            'adjusted_total_minor' => 15000,
            'checkout_expires_at' => now()->addMinutes(30),
            'idempotency_key' => 'reg_checkout_' . Str::uuid(),
        ];
    }

    /**
     * The free-path carve-out: no Stripe leg, confirmed synchronously
     * in-request — the one path that advances state outside a webhook.
     */
    public function free(): self
    {
        return $this->state(fn () => [
            // A closure (not an inline ->create()) so it resolves during
            // attribute expansion, AFTER offering_id/masjid_id have become
            // real ids — a state callable itself only sees raw attributes.
            'fee_plan_id' => fn (array $attributes) => FeePlan::factory()->free()->create([
                'masjid_id' => $attributes['masjid_id'],
                'offering_id' => $attributes['offering_id'],
            ])->id,
            'status' => Registration::STATUS_CONFIRMED,
            'payment_status' => Registration::PAYMENT_NONE,
            'list_total_minor' => 0,
            'adjusted_total_minor' => 0,
            'checkout_expires_at' => null,
            'idempotency_key' => null,
        ]);
    }

    /** One-time payment completed: seat confirmed, money paid, session known. */
    public function paid(): self
    {
        return $this->state(fn () => [
            'status' => Registration::STATUS_CONFIRMED,
            'payment_status' => Registration::PAYMENT_PAID,
            'stripe_checkout_session_id' => 'cs_test_' . Str::random(16),
            'checkout_expires_at' => null,
        ]);
    }

    /** Raced out of the last seat: no reservation, no money in flight. */
    public function waitlisted(): self
    {
        return $this->state(fn () => [
            'status' => Registration::STATUS_WAITLISTED,
            'payment_status' => Registration::PAYMENT_NONE,
            'checkout_expires_at' => null,
            'idempotency_key' => null,
        ]);
    }
}
