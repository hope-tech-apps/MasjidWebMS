<?php

namespace App\Services\Stripe;

use App\Models\Donation;
use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Masjid;
use App\Support\ZakatDesignation;
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
        return \App\Support\StripeFees::grossUp($intendedAmount, $feePercentage, $feeFixed);
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
        return \App\Support\StripeFees::on($chargedAmount, $feePercentage, $feeFixed);
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
     * `zakat` is the giver's own designation (null = did not say, in which case
     * the fund's type is the default — App\Support\ZakatDesignation). It is
     * stamped on the row persisted HERE, before the redirect, so it is already
     * present on the row the webhook later advances: the designation never
     * depends on the browser coming back.
     *
     * @param  array{success_url?:string,cancel_url?:string,contact_id?:int|null,zakat?:bool|null}  $options
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
        $zakat = ZakatDesignation::resolve($options['zakat'] ?? null, $fund);

        // Persist BEFORE talking to Stripe. masjid_id is set explicitly because
        // the public donation flow runs UNBOUND (no tenant middleware), so the
        // BelongsToMasjid creating hook does not stamp it here.
        $donation = Donation::create([
            'masjid_id' => $masjid->id,
            'contact_id' => $options['contact_id'] ?? null,
            'fund_id' => $fund->id,
            'type' => 'one_time',
            'is_zakat' => $zakat['is_zakat'],
            'zakat_source' => $zakat['zakat_source'],
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
        // The designation rides on the PAYMENT INTENT's metadata, which is what
        // the org sees against the payment in its own Stripe dashboard — the
        // surface a treasurer reconciles the restricted pot against.
        //
        // ABSENT rather than 'false' when the gift is not zakat, the same
        // positive-only discipline application_fee_amount follows above: a
        // non-zakat gift's Session parameters are byte-identical to what they
        // were before T-031. Our own row, not this metadata, is the record of
        // record — Stripe metadata is a convenience for the org's reconciliation.
        if ($zakat['is_zakat']) {
            $paymentIntentData['metadata']['zakat'] = 'true';
            $paymentIntentData['metadata']['zakat_source'] = (string) $zakat['zakat_source'];
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
     * The zakat designation is resolved ONCE here and stored on the commitment;
     * every invoice it books copies it (see bookRecurringInvoice) rather than
     * re-deriving it from the fund, whose type an admin may have edited in the
     * meantime. Recurring zakat is a real pattern — payers commonly settle one
     * annual obligation in monthly installments — and the platform records the
     * designation the giver made without ruling on whether instalments discharge
     * the obligation, which is a fiqh question it must not answer for them.
     *
     * @param  array{success_url?:string,cancel_url?:string,contact_id?:int|null,interval?:string,zakat?:bool|null}  $options
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
        $zakat = ZakatDesignation::resolve($options['zakat'] ?? null, $fund);

        $subscription = DonationSubscription::create([
            'masjid_id' => $masjid->id,
            'contact_id' => $options['contact_id'] ?? null,
            'fund_id' => $fund->id,
            'intended_amount' => $intendedAmount,
            'charged_amount' => $chargedAmount,
            'currency' => $currency,
            'donor_covers_fees' => $donorCoversFees,
            'is_zakat' => $zakat['is_zakat'],
            'zakat_source' => $zakat['zakat_source'],
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
        // Present only when the gift IS zakat, exactly as on the one-time path,
        // so a non-zakat recurring Session is unchanged from before T-031. It
        // goes on subscription_data.metadata for the same reason the routing
        // uuid does: invoices do not inherit invoice-level metadata, so this is
        // the only place it reaches every recurring charge in the org's dashboard.
        if ($zakat['is_zakat']) {
            $subscriptionData['metadata']['zakat'] = 'true';
            $subscriptionData['metadata']['zakat_source'] = (string) $zakat['zakat_source'];
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

        // A LIVE SUBSCRIPTION LANDING ON A ROW THAT SAYS 'canceled'.
        //
        // The ids are still linked — a row that cannot be found by
        // `stripe_subscription_id` is a row nobody can reconcile, and
        // `cancelSubscriptionByStripeId` needs the link to work at all — but this
        // combination is never benign and must never be silent. It means somebody
        // cancelled a commitment whose checkout had not completed, so nothing was
        // stopped at Stripe and Stripe is now billing a gift our screens report as
        // cancelled. `activateSubscription` will not move the row back to active,
        // so nothing downstream will ever say this out loud.
        //
        // The donor surface can no longer produce it (MemberRecurringGivingController
        // refuses to cancel an unlinked commitment for exactly this reason). The
        // ADMIN surface still can, and its tolerance is pinned by
        // DonationSubscriptionTenantIsolationTest, so closing that door is its own
        // task — until then this log is how the organisation finds out, with the
        // ids needed to cancel it in the Stripe dashboard.
        if ($subscription->status === 'canceled' && is_string($session['subscription'] ?? null)) {
            \Illuminate\Support\Facades\Log::critical(
                'A Stripe subscription completed checkout against a commitment already marked cancelled. Stripe is billing a gift this system reports as cancelled — cancel it in the Stripe dashboard.',
                [
                    'subscription_id' => $subscription->id,
                    'masjid_id' => $subscription->masjid_id,
                    'stripe_subscription_id' => $session['subscription'],
                    'stripe_checkout_session_id' => $session['id'] ?? null,
                ]
            );
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
            // Copied from the commitment, never re-derived: the designation was
            // made once at checkout, and the fund's type may have been edited
            // since. The metadata on the invoice is not consulted for this —
            // metadata never decides anything authoritative on an inbound event
            // (.claude/rules/stripe-payments.md); our own row is the record.
            'is_zakat' => $subscription->is_zakat,
            'zakat_source' => $subscription->zakat_source,
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

    /**
     * The DONOR's cancel: our row is allowed to say 'canceled' only once Stripe
     * agrees that this subscription is not billing any more.
     *
     * Why this exists beside `cancelSubscription` rather than replacing it. That
     * one is the ADMIN action, and its tolerance is deliberate and pinned
     * (DonationSubscriptionTenantIsolationTest): a staff member cancelling on the
     * phone gets the seat marked closed even during a Stripe outage, and they can
     * see the warning log, the Stripe dashboard and the donor's next statement.
     * A donor has none of those. For them the row IS the answer, and the sentence
     * the screen prints from it — "Cancelled" — is a promise about money that
     * leaves their account. So the donor door is held to the opposite standard:
     * no Stripe agreement, no local write, and the controller says so out loud.
     *
     * A refusal is not an answer, so it is asked about again — the same idiom as
     * `MealOrderCheckoutService::closeAndSee` and
     * `FormResponseCheckoutService::closeSession`. Stripe refuses to cancel a
     * subscription it has already cancelled, which is exactly what a retry after
     * a timed-out first attempt looks like; if Stripe then SAYS 'canceled', it
     * agrees, and the local write is honest. Anything else — a subscription it
     * cannot find (we may be asking the wrong connected account), a status still
     * live, an outage on the second call too — is rethrown and reaches the donor
     * as a refusal.
     *
     * A failure of the local write AFTER Stripe cancelled needs no special
     * handling, unlike the amount change: it fails safe (Stripe has stopped
     * billing), the donor's retry self-heals through the branch above, and
     * `customer.subscription.deleted` → `cancelSubscriptionByStripeId` repairs the
     * row on its own. There is no equivalent event for an amount.
     *
     * @throws \Throwable  whatever Stripe raised, when Stripe has not agreed
     */
    public function cancelSubscriptionOrFail(DonationSubscription $subscription): void
    {
        $stripeSubscriptionId = $this->stripeSubscriptionIdOf($subscription);
        $account = $this->connectedAccountOf($subscription);

        try {
            $this->cancelStripeSubscription($stripeSubscriptionId, $account);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $now = $this->retrieveStripeSubscription($stripeSubscriptionId, $account);

            if (($now['status'] ?? null) !== 'canceled') {
                throw $e;
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

    // ---------------------------------------------------------------------
    // Donor self-service on a standing commitment: pause / resume / change
    // amount. Cancel already lived above; these three are the verbs that did
    // not exist for ANYONE — donor or staff — before this slice.
    //
    // ---------------------------------------------------------------------
    // WHICH VERB WRITES LOCALLY, AND WHY THE SPLIT IS WHERE IT IS
    // ---------------------------------------------------------------------
    // .claude/rules/stripe-payments.md: "Webhooks are the source of truth" and
    // "STRIPE OWNS THE BILLING CLOCK, THE RETRIES AND THE DUNNING." Those two
    // sentences decide this for us, and they cut in different directions for
    // different verbs:
    //
    //   - PAUSE / RESUME are statements about the BILLING CLOCK — will Stripe
    //     raise the next invoice or not. Stripe owns that, so these two write
    //     NOTHING to our row. `pause_collection` at Stripe is the whole
    //     mechanism; there is no local flag, so there is nothing for the next
    //     invoice to ignore. What our screens show about a pause is READ BACK
    //     from Stripe (`pauseStateOf`), never remembered.
    //
    //     There is a second, blunter reason not to mirror it, and it is a
    //     schema fact rather than a preference: `donation_subscriptions.status`
    //     is a MySQL ENUM of exactly ['pending','active','past_due','canceled']
    //     (2026_07_22_000000_add_recurring_giving.php:49). Writing 'paused'
    //     into it is an error on MySQL in strict mode and a silent '' without
    //     it — and the CI suite runs on SQLite, where an ENUM is just TEXT, so
    //     it would pass 2000 green tests and corrupt a money row on
    //     production. Widening that column (to a string + PHP constants, per
    //     .claude/rules — "enumerations are PHP constants, never DB enums") is
    //     a migration, and until it lands the honest local representation of a
    //     pause is NO local representation at all.
    //
    //   - CHANGE AMOUNT writes locally, AFTER Stripe has accepted it and using
    //     the figure Stripe echoed back. That is not a contradiction: the
    //     amount columns are the COMMITMENT's terms, not a payment's state. No
    //     money moves here (`proration_behavior: none` — the new figure applies
    //     from the next invoice), and the money that does eventually move is
    //     still booked from `invoice.amount_paid` on a verified webhook
    //     (`bookRecurringInvoice`). The columns must be rewritten because
    //     `bookRecurringInvoice` COPIES `intended_amount` onto every donation
    //     it books: leaving a stale figure there would put a number the donor
    //     never agreed to onto a tax receipt.
    //
    //   - CANCEL is TWO methods above, on purpose, because the same tolerance is
    //     right for one caller and indefensible for the other.
    //     `cancelSubscription` is the ADMIN's: a Stripe failure is logged and the
    //     row is cancelled anyway, so an outage cannot stop staff closing a gift
    //     — they have the log, the dashboard and the donor on the phone.
    //     `cancelSubscriptionOrFail` is the DONOR's: no Stripe agreement, no
    //     local write. For a donor the row is the only answer there is, and a row
    //     that says "cancelled" while Stripe's billing clock runs is the worst
    //     thing this feature can produce — the donor is told their giving stopped
    //     and is charged next month. Change amount is held to the donor standard
    //     too, for every caller — see its docblock.
    // ---------------------------------------------------------------------

    /**
     * How Stripe should treat invoices while a commitment is paused.
     *
     * `void` and not `keep_as_draft`: a paused donor must not come back to a
     * stack of back-charges. Drafts would be collectable later, which turns
     * "pause my monthly gift" into "defer my monthly gifts" — a different
     * promise from the one the confirm copy makes.
     */
    public const PAUSE_BEHAVIOR = 'void';

    /**
     * Bounds on a recurring gift, in integer minor units.
     *
     * The floor keeps us above Stripe's minimum charge and matches the public
     * checkout's `min:100` (CreateDonationCheckoutRequest); the ceiling is the
     * same one that request uses. A PHP constant rather than two literals
     * repeated in a FormRequest and a service, so the API's refusal and the
     * service's own guard can never drift apart and let a $0.30 or a
     * $10,000,000 monthly commitment through one door but not the other.
     */
    public const MIN_RECURRING_AMOUNT = 100;
    public const MAX_RECURRING_AMOUNT = 99999999;

    /**
     * Pause collection on a standing commitment.
     *
     * A REAL pause at Stripe (`pause_collection`), which is the only kind that
     * works: Stripe raises the invoices, so only Stripe can decline to raise
     * the next one. Notably `pause_collection` does NOT emit
     * `customer.subscription.deleted`, so the webhook's cancel arm — and
     * `cancelSubscriptionByStripeId` behind it — cannot mistake a pause for a
     * cancel and retire a commitment the donor intends to resume.
     *
     * Writes nothing locally (see the block comment above). Throws on a Stripe
     * failure rather than swallowing it: a pause that silently did not happen
     * is a charge the donor was promised would not arrive.
     *
     * @return array{status:?string,paused:bool,unit_amount:?int,item_id:?string,product_id:?string}
     */
    public function pauseSubscription(DonationSubscription $subscription): array
    {
        return $this->pauseStripeSubscription(
            $this->stripeSubscriptionIdOf($subscription),
            // Built HERE and not inside the seam on purpose: the seam is the one
            // method a test replaces, so anything built inside it is a payload no
            // test can ever see. `void` reaching Stripe is the whole promise of a
            // pause (see PAUSE_BEHAVIOR), and a promise nothing can observe is a
            // promise nothing can pin.
            ['pause_collection' => ['behavior' => self::PAUSE_BEHAVIOR]],
            $this->connectedAccountOf($subscription)
        );
    }

    /** Stripe seam: update a subscription on a connected account to pause collection. */
    protected function pauseStripeSubscription(
        string $stripeSubscriptionId,
        array $params,
        string $connectedAccountId
    ): array {
        return $this->normalizeStripeSubscription($this->stripe->subscriptions->update(
            $stripeSubscriptionId,
            $params,
            $this->accountOptions($connectedAccountId)
        ));
    }

    /**
     * Lift the pause: Stripe resumes its own billing clock on the EXISTING
     * subscription.
     *
     * The thing this must never become is "create a new subscription". A donor
     * resuming would then hold two Stripe subscriptions against one commitment
     * row, the second one invisible to `bookRecurringInvoice`'s lookup by
     * `stripe_subscription_id`, and the first one still paused forever. Clearing
     * `pause_collection` is the whole operation.
     *
     * @return array{status:?string,paused:bool,unit_amount:?int,item_id:?string,product_id:?string}
     */
    public function resumeSubscription(DonationSubscription $subscription): array
    {
        return $this->resumeStripeSubscription(
            $this->stripeSubscriptionIdOf($subscription),
            // `null` is how the API unsets `pause_collection` — stripe-php encodes
            // a null parameter as the empty string, which is Stripe's documented
            // "unset this field". Do NOT "helpfully" omit the key instead: an
            // absent key leaves the pause in place and the call would report
            // success having changed nothing, so every resumed donor would stay
            // paused forever while their screen said otherwise.
            //
            // Built here rather than in the seam for the same reason as pause: a
            // payload assembled inside the one method tests replace is a payload
            // no test can inspect, and this is the exact key a well-meaning
            // "clean-up" removes.
            ['pause_collection' => null],
            $this->connectedAccountOf($subscription)
        );
    }

    /** Stripe seam: update a subscription on a connected account to clear its pause. */
    protected function resumeStripeSubscription(
        string $stripeSubscriptionId,
        array $params,
        string $connectedAccountId
    ): array {
        return $this->normalizeStripeSubscription($this->stripe->subscriptions->update(
            $stripeSubscriptionId,
            $params,
            $this->accountOptions($connectedAccountId)
        ));
    }

    /**
     * Change what a standing commitment charges, from the next invoice on.
     *
     * The shape of the Stripe call, and why it is two calls and not one:
     * a subscription item's `price_data` accepts a `product` ID but NOT the
     * inline `product_data` that Checkout accepts, so the existing item and its
     * product have to be read before the new price can be attached to them.
     * Reusing the product also keeps the org's Stripe dashboard reading as one
     * continuing gift rather than a new product per amount change.
     *
     * `proration_behavior: none` — this is a donation, not a service the donor
     * has part-consumed. Prorating would invoice or credit them mid-cycle, which
     * is money moving on a client action, and the confirm copy promises the
     * opposite ("The new amount applies from your next charge").
     *
     * Kept, deliberately, from the original commitment:
     *   - `application_fee_percent` lives on the subscription and is untouched
     *     here, so the platform fee cannot be silently reset by an amount change
     *     (and never sent as 0 — see createSubscriptionCheckout).
     *   - the donor-covers-fees gross-up is re-applied to the NEW intended
     *     amount, so the org still nets what the donor means to give.
     *   - `is_zakat` / `zakat_source` are NOT re-derived. .claude/rules/zakat.md:
     *     a recurring commitment is designated once, at checkout. Re-resolving
     *     it here would let an admin's later edit to the fund's type silently
     *     re-designate money the giver already labelled. Changing the
     *     designation is a cancel and a new commitment, and so is changing the
     *     fund — re-pointing restricted money in flight is not an edit.
     *
     * Unlike `cancelSubscription`, a Stripe failure here is NOT tolerated into a
     * local write. The tolerance makes sense for a cancel (the donor asked for
     * the gift to stop, and our row saying "stopped" is the conservative end
     * state); it would be indefensible here, where a local row that says $25
     * while Stripe still bills $50 shows the donor a number the card statement
     * will contradict.
     *
     * The order is Stripe first, our row second, and it cannot be the other way
     * round (the row mirrors the figure Stripe echoes). That leaves a third
     * outcome between success and failure — accepted there, unrecorded here —
     * which is raised as its own type rather than folded into either, because a
     * caller that reports it as "nothing changed" is telling the donor the
     * opposite of what their statement will say.
     *
     * @throws \InvalidArgumentException  outside the sane range
     * @throws RecurringAmountChangedOnlyAtStripe  accepted at Stripe, not recorded here
     */
    public function changeSubscriptionAmount(
        DonationSubscription $subscription,
        int $newIntendedAmount
    ): DonationSubscription {
        if ($newIntendedAmount < self::MIN_RECURRING_AMOUNT || $newIntendedAmount > self::MAX_RECURRING_AMOUNT) {
            throw new \InvalidArgumentException('Recurring amount out of range.');
        }

        $stripeSubscriptionId = $this->stripeSubscriptionIdOf($subscription);
        $account = $this->connectedAccountOf($subscription);

        $charged = $subscription->donor_covers_fees
            ? self::grossUp($newIntendedAmount)
            : $newIntendedAmount;

        $current = $this->retrieveStripeSubscription($stripeSubscriptionId, $account);

        if (empty($current['item_id']) || empty($current['product_id'])) {
            throw new \RuntimeException('This commitment has no priced item at Stripe.');
        }

        $result = $this->updateStripeSubscriptionAmount(
            $stripeSubscriptionId,
            (string) $current['item_id'],
            (string) $current['product_id'],
            $charged,
            (string) ($subscription->currency ?: 'usd'),
            $subscription->interval === 'year' ? 'year' : 'month',
            $account
        );

        // Trust Stripe's echo over our own arithmetic. The row is a mirror of
        // what the card will actually be charged, and if those two ever disagree
        // the operator needs to see the disagreement in the log rather than have
        // our number quietly win.
        $confirmed = $result['unit_amount'];

        if ($confirmed !== null && $confirmed !== $charged) {
            \Illuminate\Support\Facades\Log::warning('Stripe accepted a different recurring amount than we sent.', [
                'subscription_id' => $subscription->id,
                'sent' => $charged,
                'stripe' => $confirmed,
            ]);
        }

        // Everything above this line has ALREADY happened at Stripe. From here a
        // failure is not "nothing changed", it is "Stripe changed and we did
        // not", and it must never be answered with the former — see
        // RecurringAmountChangedOnlyAtStripe. The row is what
        // `bookRecurringInvoice` copies onto every donation and receipt it
        // writes, so a stale figure here is a number the donor never agreed to,
        // printed on a tax document.
        try {
            $subscription->forceFill([
                'intended_amount' => $newIntendedAmount,
                'charged_amount' => $confirmed ?? $charged,
            ])->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::critical(
                'Stripe repriced a recurring gift that we then failed to record. The card will be charged the new amount; this row still holds the old one.',
                [
                    'subscription_id' => $subscription->id,
                    'masjid_id' => $subscription->masjid_id,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'accepted_by_stripe' => $confirmed ?? $charged,
                    'still_in_the_row' => (int) $subscription->getOriginal('charged_amount'),
                    'error' => $e->getMessage(),
                ]
            );

            throw new RecurringAmountChangedOnlyAtStripe(
                $confirmed ?? $charged,
                (int) $subscription->id,
                $e
            );
        }

        return $subscription->refresh();
    }

    /** Stripe seam: move the subscription's single item onto a new unit amount. */
    protected function updateStripeSubscriptionAmount(
        string $stripeSubscriptionId,
        string $itemId,
        string $productId,
        int $unitAmount,
        string $currency,
        string $interval,
        string $connectedAccountId
    ): array {
        return $this->normalizeStripeSubscription($this->stripe->subscriptions->update(
            $stripeSubscriptionId,
            [
                'items' => [[
                    'id' => $itemId,
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product' => $productId,
                        'unit_amount' => $unitAmount,
                        'recurring' => ['interval' => $interval],
                    ],
                ]],
                'proration_behavior' => 'none',
            ],
            $this->accountOptions($connectedAccountId)
        ));
    }

    /**
     * Is this commitment's collection paused at Stripe right now?
     *
     * `null` means "we could not find out", and callers must render it as
     * unknown rather than as `false`. There is nowhere local to cache this (see
     * the block comment above), so a screen that wants it has to ask — and a
     * donor must still be able to open their giving screen when Stripe is slow,
     * unreachable, or when the org has no connected account at all. That last
     * case is the .claude/rules invariant that an integration with no
     * credentials no-ops instead of throwing.
     */
    public function pauseStateOf(DonationSubscription $subscription): ?bool
    {
        if (! $subscription->stripe_subscription_id) {
            return null;
        }

        $account = $this->connectedAccountOf($subscription, false);

        if ($account === '') {
            return null;
        }

        try {
            return $this->retrieveStripeSubscription($subscription->stripe_subscription_id, $account)['paused'];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Could not read pause state from Stripe.', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Stripe seam: read a subscription on a connected account. */
    protected function retrieveStripeSubscription(string $stripeSubscriptionId, string $connectedAccountId): array
    {
        return $this->normalizeStripeSubscription($this->stripe->subscriptions->retrieve(
            $stripeSubscriptionId,
            [],
            $this->accountOptions($connectedAccountId)
        ));
    }

    /**
     * Flatten a Stripe Subscription into the plain array every seam above
     * returns, so the money logic never handles an SDK object and the tests can
     * stub a seam with a literal.
     *
     * @return array{status:?string,paused:bool,unit_amount:?int,item_id:?string,product_id:?string}
     */
    private function normalizeStripeSubscription(mixed $subscription): array
    {
        $item = $subscription->items->data[0] ?? null;
        $price = $item->price ?? null;
        $product = $price->product ?? null;

        return [
            'status' => is_string($subscription->status ?? null) ? $subscription->status : null,
            // Stripe reports a pause as a `pause_collection` OBJECT and an
            // unpaused subscription as null, so presence is the whole signal.
            'paused' => ($subscription->pause_collection ?? null) !== null,
            'unit_amount' => isset($price->unit_amount) ? (int) $price->unit_amount : null,
            'item_id' => is_string($item->id ?? null) ? $item->id : null,
            'product_id' => is_string($product) ? $product : ($product->id ?? null),
        ];
    }

    /** The connected account these verbs must act on. */
    private function connectedAccountOf(DonationSubscription $subscription, bool $required = true): string
    {
        $account = (string) (Masjid::find($subscription->masjid_id)?->stripe_account_id ?? '');

        if ($required && $account === '') {
            throw new \RuntimeException('This organisation has no connected Stripe account.');
        }

        return $account;
    }

    /** The Stripe subscription these verbs must act on. */
    private function stripeSubscriptionIdOf(DonationSubscription $subscription): string
    {
        if (! $subscription->stripe_subscription_id) {
            throw new \RuntimeException('This commitment has no Stripe subscription yet.');
        }

        return $subscription->stripe_subscription_id;
    }

    /** Direct-charge request options: the Stripe-Account header, or none. */
    private function accountOptions(string $connectedAccountId): array
    {
        return $connectedAccountId !== '' ? ['stripe_account' => $connectedAccountId] : [];
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
