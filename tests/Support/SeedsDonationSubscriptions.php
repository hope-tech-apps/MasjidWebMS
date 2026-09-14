<?php

namespace Tests\Support;

use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Masjid;
use DateTimeInterface;

/**
 * Seeds a recurring gift that a PRODUCTION database could actually hold.
 *
 * `donation_subscriptions.status` is enum('pending','active','past_due','canceled')
 * (database/migrations/2026_07_22_000000_add_recurring_giving.php). The suite runs
 * on SQLite, which ignores the ENUM, so a row seeded with any other word passes CI
 * while describing a state MySQL refuses to store, and the test proves nothing
 * about production. 'paused' is the tempting one: a pause lives only at Stripe
 * (`pause_collection`) and the local row stays 'active' (DonationService), so a
 * test about "a paused gift" seeds 'active'.
 *
 * Every seed goes through assertSubscriptionStatusIsStorable(), which fails the
 * test that asks for anything else.
 */
trait SeedsDonationSubscriptions
{
    /** @return list<string> the ENUM, in migration order */
    protected static function storableSubscriptionStatuses(): array
    {
        return ['pending', 'active', 'past_due', 'canceled'];
    }

    protected static function subscriptionStatusIsStorable(string $status): bool
    {
        return in_array($status, static::storableSubscriptionStatuses(), true);
    }

    protected function assertSubscriptionStatusIsStorable(string $status): void
    {
        $this->assertTrue(
            static::subscriptionStatusIsStorable($status),
            "'{$status}' is not a donation_subscriptions.status production can store. SQLite would accept it "
                . 'and the test would prove nothing; seed one of: '
                . implode(', ', static::storableSubscriptionStatuses()) . '.'
        );
    }

    /**
     * @param  array<string, mixed>  $attributes  any fillable column; `status` is checked against the ENUM
     * @param  ?DateTimeInterface  $createdAt  backdates the row (created_at is not fillable)
     */
    protected function seedDonationSubscription(
        Masjid $masjid,
        Fund $fund,
        array $attributes = [],
        ?DateTimeInterface $createdAt = null,
    ): DonationSubscription {
        $this->assertSubscriptionStatusIsStorable((string) ($attributes['status'] ?? 'active'));

        $subscription = DonationSubscription::withoutMasjidScope()->create(array_merge([
            'masjid_id' => $masjid->id,
            'contact_id' => null,
            'fund_id' => $fund->id,
            'intended_amount' => 5000,
            'charged_amount' => 5000,
            'currency' => 'usd',
            'donor_covers_fees' => false,
            'is_zakat' => false,
            'zakat_source' => null,
            'interval' => 'month',
            'status' => 'active',
            'stripe_subscription_id' => 'sub_' . uniqid(),
            'stripe_customer_id' => 'cus_' . uniqid(),
            'idempotency_key' => 'sub_' . uniqid(),
        ], $attributes));

        if ($createdAt !== null) {
            $subscription->forceFill(['created_at' => $createdAt])->save();
        }

        return $subscription->fresh();
    }
}
