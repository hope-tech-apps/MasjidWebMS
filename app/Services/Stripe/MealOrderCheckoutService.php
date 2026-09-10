<?php

namespace App\Services\Stripe;

use App\Models\Masjid;
use App\Models\MealOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\StripeClient;

/**
 * The OUTBOUND Stripe leg of an online meal order — a deliberate, trimmed
 * sibling of RegistrationCheckoutService (which is itself a sibling of the
 * locked DonationService). Same doctrine, one-time payments only:
 *
 *   - Stripe Connect STANDARD account + DIRECT charge: the hosted Checkout
 *     Session is created ON the org's connected account (`stripe_account`
 *     option). Funds land in the ORG's balance; the org is merchant of record.
 *   - The platform takes only `application_fee_amount`, sent ONLY when > 0.
 *   - PCI SAQ A: the card is entered on Stripe's hosted page; this app never
 *     renders a card form.
 *   - Money is integer minor units (the order's `total_minor` snapshot).
 *   - **The webhook is the source of truth.** Nothing here marks the order paid;
 *     the row stays `unpaid` until a signature-verified event lands
 *     (MealOrderPaymentService). The returned URL is a redirect, not a promise.
 *
 * `order_uuid` (distinct from `donation_uuid` / `registration_uuid`) is the
 * routing key, carried on BOTH the session and the payment intent so either
 * event alone resolves the order.
 *
 * Only `createCheckoutSession()` touches the live API; it is a thin protected
 * seam so tests stub it and never reach Stripe.
 */
class MealOrderCheckoutService
{
    public function __construct(private StripeClient $stripe)
    {
    }

    /** The platform's application fee on a charge, integer minor units. */
    public static function applicationFee(int $chargedMinor, ?float $platformPct = null): int
    {
        $platformPct ??= (float) config('services.stripe.platform_fee_percentage', 0);

        return (int) round($chargedMinor * $platformPct);
    }

    /**
     * Open the hosted Checkout Session for a pending online order; return its URL.
     *
     * @param  array{success_url?:string,cancel_url?:string}  $options
     * @return array{order: MealOrder, checkout_url: string, session_id: ?string}
     */
    public function checkout(MealOrder $order, array $options = []): array
    {
        $masjid = $this->preflight($order);

        // Persist the idempotency key BEFORE talking to Stripe, so a retried
        // request re-sends the same key and Stripe returns the same Session
        // rather than opening a second one.
        $idempotencyKey = $order->idempotency_key ?: ('meal_order_' . Str::uuid());
        $order->idempotency_key = $idempotencyKey;
        $order->save();

        $currency = strtolower((string) ($order->currency ?: config('services.stripe.currency', 'usd')));

        $metadata = [
            'order_uuid' => $order->uuid,
            'masjid_id' => (string) $order->masjid_id,
        ];

        // Itemised so the hosted page shows the breakdown. The line items sum to
        // total_minor by construction (each food line = unit_price × quantity,
        // plus the optional donation line added below).
        $lineItems = [];
        foreach ($order->items as $item) {
            $lineItems[] = [
                'quantity' => (int) $item->quantity,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $item->unit_price_minor,
                    'product_data' => ['name' => (string) $item->item_name],
                ],
            ];
        }

        // The optional extra rides on the SAME charge, as its own line so the
        // hosted page names it rather than inflating the price of a plate — and
        // so the line items keep summing to total_minor, which is what the
        // application fee is computed from.
        if ((int) $order->donation_minor > 0) {
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $order->donation_minor,
                    'product_data' => ['name' => 'Additional donation'],
                ],
            ];
        }

        // Named, for the same reason as the extra: the customer agreed to a
        // processing fee, so the hosted page says so rather than quietly
        // inflating the food.
        if ((int) $order->fee_covered_minor > 0) {
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $order->fee_covered_minor,
                    'product_data' => ['name' => 'Card processing fee'],
                ],
            ];
        }

        if ($lineItems === []) {
            // Defensive: an order always has items, but never mint a $0 session.
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $order->total_minor,
                    'product_data' => ['name' => 'Jummah Lunch order'],
                ],
            ];
        }

        $paymentIntentData = ['metadata' => $metadata];
        $fee = self::applicationFee((int) $order->total_minor);
        if ($fee > 0) {
            $paymentIntentData['application_fee_amount'] = $fee;
        }

        $base = rtrim((string) config('app.url'), '/');
        $orderUrl = $base . '/jummah-lunch/' . $order->masjid_id . '/order/' . $order->uuid;

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $order->uuid,
            'metadata' => $metadata,
            'line_items' => $lineItems,
            'payment_intent_data' => $paymentIntentData,
            'success_url' => $options['success_url']
                ?? ($orderUrl . '?paid=1&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => $options['cancel_url']
                ?? ($orderUrl . '?cancelled=1'),
        ];

        $session = $this->createCheckoutSession(
            $params,
            (string) $masjid->stripe_account_id,
            $idempotencyKey
        );

        // Record the handle only — a session is a redirect, not a payment.
        if (! empty($session['id'])) {
            $order->stripe_checkout_session_id = $session['id'];
            $order->save();
        }

        return [
            'order' => $order,
            'checkout_url' => (string) ($session['url'] ?? ''),
            'session_id' => $session['id'] ?? null,
        ];
    }

    /**
     * Every reason this order may NOT be charged, stated once. Writes nothing.
     *
     * @throws RuntimeException
     */
    /**
     * The payment page for an unpaid order, for staff to open or send on. An
     * open page is handed back as it is, so a customer never holds two live
     * ways to pay the same order; an expired page is replaced (with a new
     * idempotency key, or Stripe would replay the old one); a completed page
     * is left to the webhook to record.
     *
     * @return array{order: MealOrder, checkout_url: string, session_id: ?string}
     */
    public function paymentLink(MealOrder $order): array
    {
        // Serialised on the order row: when two people press "Payment link" at
        // once, the second waits here, then finds the first one's open session
        // and gets that same page — never a second payable one.
        return DB::transaction(function () use ($order) {
            $order = MealOrder::withoutMasjidScope()->with('items')->lockForUpdate()->findOrFail($order->id);
            $masjid = $this->preflight($order);

            if ($order->stripe_checkout_session_id) {
                $session = $this->retrieveCheckoutSession(
                    (string) $order->stripe_checkout_session_id,
                    (string) $masjid->stripe_account_id
                );

                if ($session['status'] === 'open' && $session['url']) {
                    return [
                        'order' => $order,
                        'checkout_url' => (string) $session['url'],
                        'session_id' => $order->stripe_checkout_session_id,
                    ];
                }

                if ($session['status'] === 'complete') {
                    throw new RuntimeException('This order has been paid on Stripe. The board will show it as paid in a moment.');
                }

                $order->stripe_checkout_session_id = null;
            }

            // A fresh key whenever no live page exists. A key saved by an attempt
            // that never recorded a session would otherwise be replayed: Stripe
            // repeats a saved failure for 24h, and rejects the key outright when
            // the parameters differ (the public page's own return URLs). That
            // attempt handed nobody a page, so a new key cannot create a second
            // payable one — and the row lock above rules out a concurrent one.
            $order->idempotency_key = null;
            $order->save();

            return $this->checkout($order);
        });
    }

    private function preflight(MealOrder $order): Masjid
    {
        if ($order->payment_status === MealOrder::PAYMENT_PAID) {
            throw new RuntimeException('This order has already been paid.');
        }

        if ((int) $order->total_minor <= 0) {
            throw new RuntimeException('This order has nothing to pay.');
        }

        $masjid = Masjid::find($order->masjid_id);

        // Connect onboarding must be complete — a direct charge has nowhere to
        // land otherwise (the same gate the donation path uses).
        if (! $masjid || ! $masjid->canAcceptDonations()) {
            throw new RuntimeException('This organization is not able to accept online payments yet.');
        }

        return $masjid;
    }

    /**
     * Create the Checkout Session as a DIRECT charge on the connected account.
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
                : ($session->payment_intent?->id ?? null),
        ];
    }

    /** @return array{status: string, url: ?string} Stripe's 'open' | 'complete' | 'expired'. */
    protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
    {
        $session = $this->stripe->checkout->sessions->retrieve($sessionId, [], [
            'stripe_account' => $connectedAccountId,
        ]);

        return ['status' => (string) $session->status, 'url' => $session->url];
    }
}
