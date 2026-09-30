<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\Order;
use App\Services\Cart\CartCheckoutService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\StripeClient;

/**
 * What the cart's webhook tests share: a checkout whose Stripe seams touch no network,
 * and events signed exactly as Stripe signs them (with the CONNECT endpoint's secret:
 * a basket is a direct charge on the organisation's account), posted through the real
 * route so the dispatch arm, the event ledger and both services are all in play.
 *
 * Orders are made by CartCheckoutService itself, not built by hand, so each test settles
 * the snapshot checkout really wrote.
 */
trait SignsCartWebhooks
{
    protected const PLATFORM_SECRET = 'whsec_cart_platform';

    protected const CONNECT_SECRET = 'whsec_cart_connect';

    protected const RETURN_BASE = 'https://mec.manara.hopetechapps.com/basket';

    protected function armWebhooks(): void
    {
        config([
            'services.stripe.webhook_secret' => self::PLATFORM_SECRET,
            'services.stripe.connect_webhook_secret' => self::CONNECT_SECRET,
        ]);

        Mail::fake();
        Log::spy();
    }

    /** A checkout service whose Stripe seams answer locally. */
    protected function checkoutService(): CartCheckoutService
    {
        return new class(new StripeClient('sk_test_offline')) extends CartCheckoutService {
            public array $created = [];

            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                $this->created[] = ['params' => $params, 'account' => $connectedAccountId];

                return [
                    'id' => 'cs_cart_' . Str::lower(Str::random(12)),
                    'url' => 'https://checkout.stripe.test/' . count($this->created),
                    'payment_intent' => null,
                ];
            }

            protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
            {
                return ['status' => 'open', 'url' => 'https://checkout.stripe.test/existing'];
            }

            protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
            {
            }
        };
    }

    /** Open the basket's page and hand back the pending order it left. */
    protected function placeOrder(Cart $cart, ?string $buyerEmail = 'buyer@example.org'): Order
    {
        return $this->checkoutService()->checkout($cart, self::RETURN_BASE, $buyerEmail)['order']->fresh();
    }

    /** checkout.session.completed by default; `type` says another session event. */
    protected function sessionEvent(Order $order, array $o = []): array
    {
        $object = [
            'id' => $o['session_id'] ?? $order->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'mode' => 'payment',
            // Every completed session is `complete`, paid or not; payment_status is what says.
            'status' => 'complete',
            'payment_status' => $o['payment_status'] ?? 'paid',
            'amount_total' => $o['amount'] ?? (int) $order->total_minor,
            'currency' => $o['currency'] ?? 'usd',
            'payment_intent' => $o['payment_intent'] ?? 'pi_cart_1',
            'customer_details' => ['email' => 'buyer@example.org', 'name' => 'Amal Buyer'],
            'metadata' => $this->routing($order, $o),
        ];

        // The uuid is never sent on a holder's account (no client_reference_id there).
        if ($order->charge_ref === null) {
            $object['client_reference_id'] = $order->uuid;
        }

        return $this->cartEvent($o['type'] ?? 'checkout.session.completed', $order, $o, $object);
    }

    protected function intentEvent(Order $order, array $o = []): array
    {
        return $this->cartEvent('payment_intent.succeeded', $order, $o, [
            'id' => $o['payment_intent'] ?? 'pi_cart_1',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => $o['amount'] ?? (int) $order->total_minor,
            'amount_received' => $o['amount'] ?? (int) $order->total_minor,
            'currency' => $o['currency'] ?? 'usd',
            'metadata' => $this->routing($order, $o),
        ]);
    }

    /** What CartCheckoutService puts on the session and on the payment intent. */
    protected function routing(Order $order, array $o = []): array
    {
        if (isset($o['metadata'])) {
            return $o['metadata'];
        }

        if ($order->charge_ref !== null) {
            return [CartCheckoutService::CHARGE_REF_KEY => $order->charge_ref];
        }

        return [CartCheckoutService::METADATA_KEY => $order->uuid, 'masjid_id' => (string) $order->masjid_id];
    }

    /** An event raised on the order's own connected account, unless the test says otherwise. */
    protected function cartEvent(string $type, Order $order, array $o, array $object): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . Str::random(24),
            'type' => $type,
            'account' => array_key_exists('account', $o) ? $o['account'] : $order->charge_account_id,
            'created' => time(),
            'data' => ['object' => $object],
        ];
    }

    /** Signed exactly as Stripe signs, with the Connect endpoint's secret. */
    protected function postWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::CONNECT_SECRET);

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [], [], [],
            [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        );
    }

    /** Assert a warning was logged whose message contains $needle. */
    protected function assertWarned(string $needle): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, $needle))
            ->atLeast()->once();
    }
}
