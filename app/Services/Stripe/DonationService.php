<?php

namespace App\Services\Stripe;

use App\Models\Donation;
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
            'payment_intent' => is_string($session->payment_intent)
                ? $session->payment_intent
                : ($session->payment_intent->id ?? null),
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
