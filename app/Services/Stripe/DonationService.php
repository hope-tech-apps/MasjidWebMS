<?php

namespace App\Services\Stripe;

use App\Models\Donation;
use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Masjid;
use Illuminate\Support\Str;
use Stripe\StripeClient;

/**
 * DonationService — creates the donation + its Stripe Checkout Session.
 *
 * Design (locked, see .claude/rules/stripe-payments.md):
 *   - Stripe Connect STANDARD account + DIRECT charge: the Checkout Session is
 *     created ON the org's connected account (the `stripe_account` request
 *     option = the Stripe-Account header). Funds land in the ORG's balance; the
 *     org is the merchant of record and bears its own refunds/disputes.
 *   - The platform takes only `application_fee_amount` (a Connect fee), sent
 *     only when > 0 (Stripe rejects a zero fee).
 *   - PCI SAQ A: card data is entered on Stripe's hosted Checkout page — this
 *     app never sees a PAN.
 *   - Idempotency: the Session create is keyed by the donation's
 *     `idempotency_key` so a retried request can't double-charge.
 *   - A `pending` donation row is persisted BEFORE the redirect; it is only
 *     advanced to `succeeded` by webhooks, never by the browser redirect.
 *
 * All amounts are integer minor units (cents).
 *
 * The outward Stripe calls live in small protected seams (createCheckoutSession,
 * fetchChargeFinancials) that return plain arrays, so the money/persistence
 * logic is unit-testable without touching the live API.
 */
class DonationService
{
    public function __construct(private StripeClient $stripe)
    {
    }

    /**
     * Donor-covers-fees gross-up.
     *
     * The donor wants the org to NET `$intendedAmount`. Stripe deducts
     * `rate * charged + fixed` from the charge. Solving
     *
     *     charged - (rate * charged + fixed) = intended
     *   ⇒ charged * (1 - rate) = intended + fixed
     *   ⇒ charged = (intended + fixed) / (1 - rate)
     *
     * e.g. intended $100.00 (10000¢) @ 2.9% + 30¢ ⇒ round(10030 / 0.971) =
     * 10330¢ = $103.30, whose net after Stripe's fee is back to ~$100.00.
     */
    public static function grossUp(
        int $intendedAmount,
        ?float $feePercentage = null,
        ?int $feeFixed = null
    ): int {
        $feePercentage ??= (float) config('services.stripe.fee_percentage', 0.029);
        $feeFixed ??= (int) config('services.stripe.fee_fixed', 30);

        return (int) round(($intendedAmount + $feeFixed) / (1 - $feePercentage));
    }

    /**
     * Stripe's processing fee on a charge: `rate * charged + fixed`, rounded to
     * whole minor units — the same shape the gross-up inverts. Used as the
     * deterministic fallback when the real balance-transaction fee isn't on the
     * webhook payload yet (source of truth is still the balance transaction).
     */
    public static function computeStripeFee(
        int $chargedAmount,
        ?float $feePercentage = null,
        ?int $feeFixed = null
    ): int {
        $feePercentage ??= (float) config('services.stripe.fee_percentage', 0.029);
        $feeFixed ??= (int) config('services.stripe.fee_fixed', 30);

        return (int) round($chargedAmount * $feePercentage) + $feeFixed;
    }

    /** The platform's application fee (Connect) for an intended amount. */
    public static function applicationFee(int $intendedAmount, ?float $platformPct = null): int
    {
        $platformPct ??= (float) config('services.stripe.platform_fee_percentage', 0);

        return (int) round($intendedAmount * $platformPct);
    }

    /**
     * Persist a pending donation and open a Stripe Checkout Session for it as a
     * DIRECT charge on the masjid's connected account.
     *
     * @param  array{success_url?:string,cancel_url?:string,contact_id?:int|null}  $options
     * @return array{donation: Donation, checkout_url: string}
     */
    public function createDonationCheckout(
        Masjid $masjid,
        Fund $fund,
        int $intendedAmount,
        bool $donorCoversFees,
        array $options = []
    ): array {
        $currency = strtolower((string) config('services.stripe.currency', 'usd'));

        $chargedAmount = $donorCoversFees ? self::grossUp($intendedAmount) : $intendedAmount;
        $applicationFee = self::applicationFee($intendedAmount);
        $idempotencyKey = 'checkout_' . Str::uuid();

        // Persist BEFORE talking to Stripe. masjid_id is set explicitly because
        // the public donation flow runs UNBOUND (no tenant middleware), so the
        // BelongsToMasjid creating hook does not stamp it here.
        $donation = Donation::create([
            'masjid_id' => $masjid->id,
            'contact_id' => $options['contact_id'] ?? null,
            'fund_id' => $fund->id,
            'type' => 'one_time',
            'intended_amount' => $intendedAmount,
            'charged_amount' => $chargedAmount,
            'currency' => $currency,
            'donor_covers_fees' => $donorCoversFees,
            'status' => 'pending',
            'application_fee_amount' => $applicationFee > 0 ? $applicationFee : null,
            'idempotency_key' => $idempotencyKey,
        ]);

        $paymentIntentData = [
            'metadata' => [
                'donation_uuid' => $donation->uuid,
                'masjid_id' => (string) $masjid->id,
                'fund_id' => (string) $fund->id,
            ],
        ];
        // Only attach a positive application fee — Stripe rejects a zero fee.
        if ($applicationFee > 0) {
            $paymentIntentData['application_fee_amount'] = $applicationFee;
        }

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $donation->uuid,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $chargedAmount,
                    'product_data' => [
                        'name' => $fund->name . ' donation',
                    ],
                ],
            ]],
            'payment_intent_data' => $paymentIntentData,
            'metadata' => [
                'donation_uuid' => $donation->uuid,
            ],
            'success_url' => $options['success_url']
                ?? rtrim((string) config('app.url'), '/') . '/donations/thank-you?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $options['cancel_url']
                ?? rtrim((string) config('app.url'), '/') . '/donations/cancelled',
        ];

        $session = $this->createCheckoutSession(
            $params,
            (string) $masjid->stripe_account_id,
            $idempotencyKey
        );

        $donation->fill(array_filter([
            'stripe_checkout_session_id' => $session['id'] ?? null,
            'stripe_payment_intent_id' => $session['payment_intent'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return [
            'donation' => $donation,
            'checkout_url' => (string) ($session['url'] ?? ''),
        ];
    }

    /**
     * The platform's application fee for a SUBSCRIPTION, as a percent (e.g. 2.00).
     *
     * Subscriptions can't take a fixed per-invoice fee the way one-time charges
     * take application_fee_amount — Stripe applies application_fee_percent to every
     * invoice. config stores the platform fee as a fraction (0.02); Stripe wants a
     * percent (2.0), rounded to 2 dp as its API requires.
     */
    public static function applicationFeePercent(?float $platformPct = null): float
    {
        $platformPct ??= (float) config('services.stripe.platform_fee_percentage', 0);

        return round($platformPct * 100, 2);
    }

    /**
     * Persist a pending DonationSubscription and open a Stripe Checkout Session in
     * subscription mode as a DIRECT charge on the masjid's connected account.
     *
     * The commitment row is 'pending' here; it (and the first Donation row) is
     * advanced only by the invoice.payment_succeeded webhook, never by the
     * browser redirect — same trust model as one-time.
     *
     * @param  array{success_url?:string,cancel_url?:string,contact_id?:int|null,interval?:string}  $options
     * @return array{subscription: DonationSubscription, checkout_url: string}
     */
    public function createSubscriptionCheckout(
        Masjid $masjid,
        Fund $fund,
        int $intendedAmount,
        bool $donorCoversFees,
        array $options = []
    ): array {
        $currency = strtolower((string) config('services.stripe.currency', 'usd'));
        $interval = ($options['interval'] ?? 'month') === 'year' ? 'year' : 'month';

        $chargedAmount = $donorCoversFees ? self::grossUp($intendedAmount) : $intendedAmount;
        $feePercent = self::applicationFeePercent();
        $idempotencyKey = 'subscription_' . Str::uuid();

        $subscription = DonationSubscription::create([
            'masjid_id' => $masjid->id,
            'contact_id' => $options['contact_id'] ?? null,
            'fund_id' => $fund->id,
            'intended_amount' => $intendedAmount,
            'charged_amount' => $chargedAmount,
            'currency' => $currency,
            'donor_covers_fees' => $donorCoversFees,
            'interval' => $interval,
            'status' => 'pending',
            'application_fee_percent' => $feePercent > 0 ? $feePercent : null,
            'idempotency_key' => $idempotencyKey,
        ]);

        // Every invoice carries this metadata, so the webhook can resolve the
        // masjid/fund/commitment for each recurring charge.
        $subscriptionData = [
            'metadata' => [
                'donation_subscription_uuid' => $subscription->uuid,
                'masjid_id' => (string) $masjid->id,
                'fund_id' => (string) $fund->id,
            ],
        ];
        // Only attach a positive fee — Stripe rejects a zero application_fee_percent.
        if ($feePercent > 0) {
            $subscriptionData['application_fee_percent'] = $feePercent;
        }

        $params = [
            'mode' => 'subscription',
            'client_reference_id' => $subscription->uuid,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $chargedAmount,
                    'recurring' => ['interval' => $interval],
                    'product_data' => [
                        'name' => $fund->name . ' — recurring donation',
                    ],
                ],
            ]],
            'subscription_data' => $subscriptionData,
            'metadata' => [
                'donation_subscription_uuid' => $subscription->uuid,
            ],
            'success_url' => $options['success_url']
                ?? rtrim((string) config('app.url'), '/') . '/donations/thank-you?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $options['cancel_url']
                ?? rtrim((string) config('app.url'), '/') . '/donations/cancelled',
        ];

        $session = $this->createCheckoutSession(
            $params,
            (string) $masjid->stripe_account_id,
            $idempotencyKey
        );

        $subscription->fill(array_filter([
            'stripe_checkout_session_id' => $session['id'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return [
            'subscription' => $subscription,
            'checkout_url' => (string) ($session['url'] ?? ''),
        ];
    }

    /**
     * A subscription-mode checkout completed: pin the Stripe subscription/customer
     * ids onto our commitment row. Does NOT book a donation — the first
     * invoice.payment_succeeded does that, so out-of-order events converge.
     *
     * @return ?DonationSubscription  null when the session isn't one of ours
     */
    public function linkSubscriptionCheckout(array $session): ?DonationSubscription
    {
        $subscription = $this->findSubscription([
            'uuid' => $session['metadata']['donation_subscription_uuid']
                ?? ($session['client_reference_id'] ?? null),
            'stripe_checkout_session_id' => $session['id'] ?? null,
        ]);

        if (! $subscription) {
            return null;
        }

        $subscription->fill(array_filter([
            'stripe_subscription_id' => is_string($session['subscription'] ?? null)
                ? $session['subscription'] : null,
            'stripe_customer_id' => is_string($session['customer'] ?? null)
                ? $session['customer'] : null,
        ], fn ($v) => $v !== null))->save();

        return $subscription;
    }

    /**
     * Book a paid recurring invoice as a succeeded Donation (type = 'recurring')
     * and activate its subscription. Returns the donation so the caller can issue
     * and deliver the receipt exactly as for a one-time gift.
     *
     * Resolution is metadata-first so event ordering never matters: the invoice
     * carries our subscription uuid even if checkout.session.completed hasn't
     * linked the Stripe subscription id yet (and we self-heal that link here).
     *
     * Idempotent: dedup by invoice id AND a deterministic idempotency key, so a
     * redelivered invoice can never book a second donation.
     *
     * @return ?Donation  null when the invoice isn't one of ours
     */
    public function bookRecurringInvoice(array $invoice, ?string $account = null): ?Donation
    {
        // The subscription id and our metadata have lived in different spots across
        // Stripe API versions, so check the known locations rather than one path.
        $stripeSubId = $this->firstString($invoice, [
            ['subscription'],
            ['parent', 'subscription_details', 'subscription'],
            ['subscription_details', 'subscription'],
        ]);
        $uuid = $this->firstString($invoice, [
            ['subscription_details', 'metadata', 'donation_subscription_uuid'],
            ['parent', 'subscription_details', 'metadata', 'donation_subscription_uuid'],
            ['lines', 'data', 0, 'metadata', 'donation_subscription_uuid'],
        ]);

        $subscription = $this->findSubscription([
            'stripe_subscription_id' => $stripeSubId,
            'uuid' => $uuid,
        ]);

        if (! $subscription) {
            return null; // not one of ours — the controller acks and ignores.
        }

        // Self-heal the Stripe link if the invoice arrived before checkout.completed.
        if ($stripeSubId && ! $subscription->stripe_subscription_id) {
            $subscription->stripe_subscription_id = $stripeSubId;
            $subscription->save();
        }

        $invoiceId = $invoice['id'] ?? null;

        // Dedup: this invoice may already have booked a donation.
        if ($invoiceId) {
            $existing = Donation::where('stripe_invoice_id', $invoiceId)->first();
            if ($existing) {
                $this->activateSubscription($subscription, $invoice);

                return $existing;
            }
        }

        $charged = (int) ($invoice['amount_paid'] ?? $subscription->charged_amount);
        // charge / payment_intent moved under `payments` in newer API versions;
        // a null here just means we fall back to the deterministic fee formula.
        $chargeId = $this->firstString($invoice, [
            ['charge'],
            ['payments', 'data', 0, 'payment', 'charge'],
        ]);
        $paymentIntent = $this->firstString($invoice, [
            ['payment_intent'],
            ['payments', 'data', 0, 'payment', 'payment_intent'],
        ]);

        [$fee, $net, $btId] = $this->resolveInvoiceFee($charged, $chargeId, $account);

        $donation = Donation::create([
            'masjid_id' => $subscription->masjid_id,
            'contact_id' => $subscription->contact_id,
            'fund_id' => $subscription->fund_id,
            'type' => 'recurring',
            'intended_amount' => $subscription->intended_amount,
            'charged_amount' => $charged,
            'currency' => $subscription->currency,
            'donor_covers_fees' => $subscription->donor_covers_fees,
            'status' => 'succeeded',
            'stripe_subscription_id' => $subscription->stripe_subscription_id,
            'stripe_invoice_id' => $invoiceId,
            'stripe_payment_intent_id' => $paymentIntent,
            'stripe_charge_id' => $chargeId,
            'stripe_balance_transaction_id' => $btId,
            'stripe_fee_amount' => $fee,
            'net_amount' => $net,
            // Deterministic per invoice: the unique constraint is the last-resort
            // guard against a double-book under a race.
            'idempotency_key' => 'invoice_' . ($invoiceId ?? Str::uuid()),
        ]);

        $this->activateSubscription($subscription, $invoice);

        return $donation;
    }

    /** Advance a subscription to active and record its customer id. */
    private function activateSubscription(DonationSubscription $subscription, array $invoice): void
    {
        $fields = [];

        if ($subscription->status !== 'active' && $subscription->status !== 'canceled') {
            $fields['status'] = 'active';
        }
        if (! $subscription->stripe_customer_id && is_string($invoice['customer'] ?? null)) {
            $fields['stripe_customer_id'] = $invoice['customer'];
        }

        if ($fields !== []) {
            $subscription->fill($fields)->save();
        }
    }

    /** Fee/net for a recurring charge: real balance transaction if reachable, else the formula. */
    private function resolveInvoiceFee(int $charged, ?string $chargeId, ?string $account): array
    {
        if ($chargeId) {
            try {
                $f = $this->fetchChargeFinancials($chargeId, $account);
                if ($f['fee'] !== null) {
                    return [$f['fee'], $f['net'], $f['balance_transaction_id']];
                }
            } catch (\Throwable $e) {
                // fall through to the deterministic formula
            }
        }

        $fee = self::computeStripeFee($charged);

        return [$fee, $charged - $fee, null];
    }

    /**
     * Admin-initiated cancel: tell Stripe to cancel the subscription on the
     * connected account, then mark our row canceled for immediate feedback. The
     * customer.subscription.deleted webhook will also arrive and is idempotent.
     * Already-gone-at-Stripe is treated as success (we still cancel locally).
     */
    public function cancelSubscription(DonationSubscription $subscription): void
    {
        if ($subscription->stripe_subscription_id) {
            $masjid = Masjid::find($subscription->masjid_id);
            try {
                $this->cancelStripeSubscription(
                    $subscription->stripe_subscription_id,
                    (string) ($masjid?->stripe_account_id ?? '')
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Stripe subscription cancel failed; canceling locally.', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $subscription->forceFill([
            'status' => 'canceled',
            'canceled_at' => now(),
        ])->save();
    }

    /** Stripe seam: cancel a subscription on a connected account. */
    protected function cancelStripeSubscription(string $stripeSubscriptionId, string $connectedAccountId): void
    {
        $opts = $connectedAccountId !== '' ? ['stripe_account' => $connectedAccountId] : [];
        $this->stripe->subscriptions->cancel($stripeSubscriptionId, [], $opts);
    }

    /** Mark a subscription canceled (from customer.subscription.deleted). */
    public function cancelSubscriptionByStripeId(string $stripeSubscriptionId): void
    {
        $subscription = DonationSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

        if ($subscription && $subscription->status !== 'canceled') {
            $subscription->forceFill([
                'status' => 'canceled',
                'canceled_at' => now(),
            ])->save();
        }
    }

    /**
     * Return the first path that resolves to a non-empty string in a nested array.
     * Each path is a list of keys/indexes to walk. Tolerates missing branches.
     */
    private function firstString(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $node = $data;
            foreach ($path as $key) {
                if (! is_array($node) || ! array_key_exists($key, $node)) {
                    $node = null;
                    break;
                }
                $node = $node[$key];
            }
            if (is_string($node) && $node !== '') {
                return $node;
            }
        }

        return null;
    }

    /** Find a subscription by any known identifier (runs unbound in the webhook). */
    private function findSubscription(array $keys): ?DonationSubscription
    {
        foreach (['uuid', 'stripe_subscription_id', 'stripe_checkout_session_id'] as $column) {
            if (! empty($keys[$column])) {
                $sub = DonationSubscription::where($column, $keys[$column])->first();
                if ($sub) {
                    return $sub;
                }
            }
        }

        return null;
    }

    /**
     * Advance a donation to `succeeded`, merging any Stripe identifiers/fees
     * that have arrived. Idempotent and safe on out-of-order events: it never
     * re-flips an already-succeeded row and only fills fields it was given.
     *
     * @param  array{payment_intent_id?:?string,checkout_session_id?:?string,charge_id?:?string,balance_transaction_id?:?string,fee?:?int,net?:?int}  $data
     */
    public function markSucceeded(Donation $donation, array $data = []): Donation
    {
        $fields = array_filter([
            'stripe_payment_intent_id' => $data['payment_intent_id'] ?? null,
            'stripe_checkout_session_id' => $data['checkout_session_id'] ?? null,
            'stripe_charge_id' => $data['charge_id'] ?? null,
            'stripe_balance_transaction_id' => $data['balance_transaction_id'] ?? null,
            'stripe_fee_amount' => $data['fee'] ?? null,
            'net_amount' => $data['net'] ?? null,
        ], fn ($v) => $v !== null);

        if ($donation->status !== 'succeeded') {
            $fields['status'] = 'succeeded';
        }

        if (! empty($fields)) {
            $donation->fill($fields)->save();
        }

        return $donation;
    }

    /**
     * Store Stripe identifiers on a not-yet-final donation without advancing
     * its status (e.g. a checkout.session.completed whose payment is still
     * pending/async).
     */
    public function recordStripeIds(Donation $donation, array $data = []): Donation
    {
        $fields = array_filter([
            'stripe_payment_intent_id' => $data['payment_intent_id'] ?? null,
            'stripe_checkout_session_id' => $data['checkout_session_id'] ?? null,
        ], fn ($v) => $v !== null);

        if (! empty($fields)) {
            $donation->fill($fields)->save();
        }

        return $donation;
    }

    // ---------------------------------------------------------------------
    // Stripe seams (thin wrappers; overridden/stubbed in tests). These are the
    // only methods that touch the live API.
    // ---------------------------------------------------------------------

    /**
     * Create the Checkout Session as a direct charge on the connected account.
     *
     * @return array{id:?string,url:?string,payment_intent:?string}
     */
    protected function createCheckoutSession(
        array $params,
        string $connectedAccountId,
        string $idempotencyKey
    ): array {
        $session = $this->stripe->checkout->sessions->create($params, [
            'stripe_account' => $connectedAccountId,
            'idempotency_key' => $idempotencyKey,
        ]);

        return [
            'id' => $session->id,
            'url' => $session->url,
            // null on a subscription-mode session; ?-> keeps it warning-free.
            'payment_intent' => is_string($session->payment_intent)
                ? $session->payment_intent
                : ($session->payment_intent?->id ?? null),
        ];
    }

    /**
     * Retrieve a charge (on the connected account) expanded with its balance
     * transaction — the source of truth for the actual fee/net.
     *
     * @return array{charge_id:?string,balance_transaction_id:?string,fee:?int,net:?int}
     */
    public function fetchChargeFinancials(string $chargeId, ?string $connectedAccountId): array
    {
        $opts = $connectedAccountId ? ['stripe_account' => $connectedAccountId] : [];

        $charge = $this->stripe->charges->retrieve(
            $chargeId,
            ['expand' => ['balance_transaction']],
            $opts
        );

        $bt = $charge->balance_transaction ?? null;

        return [
            'charge_id' => $charge->id,
            'balance_transaction_id' => is_string($bt) ? $bt : ($bt->id ?? null),
            'fee' => is_object($bt) ? ($bt->fee ?? null) : null,
            'net' => is_object($bt) ? ($bt->net ?? null) : null,
        ];
    }
}
