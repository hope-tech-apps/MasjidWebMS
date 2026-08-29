<?php

namespace App\Services\Stripe;

use App\Models\Masjid;
use App\Models\MealOrder;
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
        // total_minor by construction (each line = unit_price × quantity).
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
}
