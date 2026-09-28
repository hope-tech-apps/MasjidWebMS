<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Stripe\FormChargeAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * Sends a priced basket to ONE Stripe Checkout page (universal cart, design §11).
 *
 * A sibling of the four single-item services, not a refactor of them — the
 * locked payment design defers any shared extraction. It follows their rules to
 * the letter, and the ones that matter most are:
 *
 *   - DIRECT charge on the ONE connected account CartPricer chose, passed as the
 *     `stripe_account` request option. assertConnectedAccount() runs before every
 *     Stripe call, copied from the form service: an empty account would send the
 *     call to the PLATFORM, making it merchant of record.
 *   - The idempotency key is minted and SAVED on the order before Stripe is asked,
 *     so a retry re-sends the same key and cannot open a second page.
 *   - Nothing here marks anything paid. Only the signature-verified webhook does
 *     (slice 4b), and only on payment_status 'paid'.
 *   - `application_fee_amount` is present only when above zero; Stripe rejects 0.
 *   - The routing key `cart_order_uuid` rides on BOTH the session and the payment
 *     intent, so the webhook finds the order from either event.
 *
 * And one rule of its own: a basket whose contents changed since the shopper last
 * looked is REFUSED here, with the changes named (CartCheckoutRefused::basketChanged).
 * The shopper confirms them through acknowledge() and tries again. Money is never
 * taken for a basket the shopper has not seen as it now is.
 *
 * Reachable from no endpoint until the webhook half (4b) exists.
 */
class CartCheckoutService
{
    /** Stripe's minimum is 30 minutes; the 60 s keeps us clear of its clock. */
    public const PAGE_LIFETIME_SECONDS = 30 * 60 + 60;

    public const METADATA_KEY = 'cart_order_uuid';

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly CartPricer $pricer = new CartPricer,
    ) {}

    /**
     * @param  string  $returnBase  an origin+path FormPaymentReturn::base() has already checked
     * @return array{order: Order, url: string}
     */
    public function checkout(Cart $cart, string $returnBase, ?string $buyerEmail = null): array
    {
        return DB::transaction(function () use ($cart, $returnBase, $buyerEmail): array {
            // Re-read under a lock: two tabs pressing "pay" must not open two pages.
            $locked = Cart::withoutMasjidScope()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            $priced = $this->pricer->price($locked);

            if ($priced->refusal !== null) {
                throw new CartCheckoutRefused($priced->refusal);
            }

            if ($priced->notices() !== []) {
                throw CartCheckoutRefused::basketChanged($priced->notices());
            }

            if (! $priced->isPayable()) {
                throw new CartCheckoutRefused('Your basket has nothing to pay for.');
            }

            $account = (string) $priced->destinationAccountId;
            self::assertConnectedAccount($account);

            // An open page for this very basket and total is handed back, never doubled.
            $reused = $this->reuseOpenPage($locked, $priced, $account);
            if ($reused !== null) {
                return $reused;
            }

            $order = $this->createPendingOrder($locked, $priced, $account, $buyerEmail);

            $url = $this->openPage($order, $priced, $account, $returnBase, $buyerEmail);

            return ['order' => $order, 'url' => $url];
        });
    }

    /**
     * Brings the basket into line with what checkout found — drops what went, and
     * takes the current price and quantity of what changed — so the shopper's next
     * press of "pay" goes through. Called once the shopper has SEEN the notices.
     */
    public function acknowledge(Cart $cart): PricedBasket
    {
        return DB::transaction(function () use ($cart): PricedBasket {
            $locked = Cart::withoutMasjidScope()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $priced = $this->pricer->price($locked);

            foreach ($priced->lines as ['item' => $item, 'outcome' => $outcome]) {
                if ($outcome->status === 'gone') {
                    $item->delete();
                } elseif ($outcome->status === 'repriced') {
                    $item->forceFill([
                        'unit_amount_shown_minor' => $outcome->unitAmountMinor,
                        'quantity' => $outcome->quantity,
                    ])->save();
                }
            }

            return $this->pricer->price($locked);
        });
    }

    /** The platform's cut, on the charged total. Present only when above zero. */
    public static function applicationFee(int $totalMinor, ?float $platformPct = null): int
    {
        $pct = $platformPct ?? (float) config('services.stripe.platform_fee_percentage', 0);

        return max(0, (int) round($totalMinor * $pct));
    }

    /** @return array{order: Order, url: string}|null */
    private function reuseOpenPage(Cart $cart, PricedBasket $priced, string $account): ?array
    {
        $open = Order::withoutMasjidScope()
            ->where('masjid_id', $cart->masjid_id)
            ->where('cart_id', $cart->id)
            ->where('status', Order::STATUS_PENDING)
            ->whereNotNull('stripe_checkout_session_id')
            ->latest('id')
            ->first();

        if ($open === null) {
            return null;
        }

        // A page opened for a different total, or on an account that has since
        // changed, is not this basket's page any more: close it and open a new one.
        if ((int) $open->total_minor !== $priced->totalMinor || ! hash_equals((string) $open->charge_account_id, $account)) {
            $this->closePage($open);

            return null;
        }

        $session = $this->retrieveCheckoutSession((string) $open->stripe_checkout_session_id, (string) $open->charge_account_id);

        if (($session['status'] ?? null) === 'open' && is_string($session['url'] ?? null)) {
            return ['order' => $open, 'url' => $session['url']];
        }

        if (($session['status'] ?? null) === 'complete') {
            // The shopper has paid and the webhook has not recorded it yet. Never
            // offer to take the money a second time.
            throw new CartCheckoutRefused('Your payment is being confirmed. Please wait a moment.');
        }

        // Expired: leave this order for the webhook's expiry and open a fresh page.
        return null;
    }

    private function createPendingOrder(Cart $cart, PricedBasket $priced, string $account, ?string $buyerEmail): Order
    {
        $uuid = (string) Str::uuid();

        $order = Order::withoutMasjidScope()->create([
            'masjid_id' => $cart->masjid_id,
            'uuid' => $uuid,
            'order_number' => strtoupper(substr(str_replace('-', '', $uuid), 0, 8)),
            'cart_id' => $cart->id,
            'contact_id' => $cart->contact_id,
            'buyer_email' => self::usableEmail($buyerEmail),
            'status' => Order::STATUS_PENDING,
            'total_minor' => $priced->totalMinor,
            'fee_minor' => self::applicationFee($priced->totalMinor),
            'currency' => $priced->currency,
            'charge_account_id' => $account,
            // SAVED before Stripe is asked: a retry re-sends this key.
            'idempotency_key' => 'cart_order_' . Str::uuid(),
            'checkout_expires_at' => now()->addSeconds(self::PAGE_LIFETIME_SECONDS),
        ]);

        foreach ($priced->lines as ['item' => $item, 'outcome' => $outcome]) {
            OrderItem::withoutMasjidScope()->create([
                'order_id' => $order->id,
                'masjid_id' => $order->masjid_id,
                'buyable_type' => $item->buyable_type,
                'buyable_id' => $item->buyable_id,
                'recorded_as' => $item->recorded_as,
                'label' => $outcome->label,
                'quantity' => $outcome->quantity,
                'unit_amount_minor' => $outcome->unitAmountMinor,
                'total_minor' => $outcome->totalMinor(),
                'currency' => $priced->currency,
            ]);
        }

        return $order;
    }

    private function openPage(Order $order, PricedBasket $priced, string $account, string $returnBase, ?string $buyerEmail): string
    {
        $lineItems = [];
        $sum = 0;

        foreach ($priced->lines as ['outcome' => $outcome]) {
            $lineItems[] = [
                'quantity' => $outcome->quantity,
                'price_data' => [
                    'currency' => $priced->currency,
                    'unit_amount' => $outcome->unitAmountMinor,
                    'product_data' => ['name' => $outcome->label],
                ],
            ];
            $sum += $outcome->totalMinor();
        }

        // The page must charge exactly what the order recorded, or the webhook's
        // amount check would later refuse a payment the shopper already made.
        if ($sum !== (int) $order->total_minor || $sum < 1) {
            throw new LogicException("Order {$order->id}: lines sum to {$sum}, the order says {$order->total_minor}.");
        }

        $metadata = [self::METADATA_KEY => (string) $order->uuid, 'masjid_id' => (string) $order->masjid_id];
        $paymentIntentData = ['metadata' => $metadata];

        $fee = (int) $order->fee_minor;
        if ($fee > 0) {
            $paymentIntentData['application_fee_amount'] = $fee;
        }

        $query = http_build_query([self::METADATA_KEY => (string) $order->uuid]);

        $params = [
            'mode' => 'payment',
            // Card only: a delayed method would hold tickets and dishes while it
            // cleared, and nothing here can release them yet.
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'payment_intent_data' => $paymentIntentData,
            'metadata' => $metadata,
            'client_reference_id' => (string) $order->uuid,
            'expires_at' => $order->checkout_expires_at->getTimestamp(),
            'success_url' => "{$returnBase}?{$query}&paid=1",
            'cancel_url' => "{$returnBase}?{$query}&cancelled=1",
        ];

        $email = self::usableEmail($buyerEmail);
        if ($email !== null) {
            $params['customer_email'] = $email;
        }

        try {
            $session = $this->createCheckoutSession($params, $account, (string) $order->idempotency_key);
        } catch (InvalidRequestException $e) {
            if (! isset($params['customer_email'])) {
                throw $e;
            }

            // Stripe refused the address. Retry once without it, on a NEW key — a
            // refused key belongs to its parameters. Never log Stripe's message:
            // it quotes the address.
            unset($params['customer_email']);
            $order->forceFill(['idempotency_key' => 'cart_order_' . Str::uuid()])->save();
            $session = $this->createCheckoutSession($params, $account, (string) $order->idempotency_key);
        }

        $order->forceFill(['stripe_checkout_session_id' => $session['id']])->save();

        if (! is_string($session['url'] ?? null)) {
            throw new CartCheckoutRefused('The payment page could not be opened. Please try again.');
        }

        return $session['url'];
    }

    private function closePage(Order $order): void
    {
        $id = (string) $order->stripe_checkout_session_id;
        $account = (string) $order->charge_account_id;

        $session = $this->retrieveCheckoutSession($id, $account);

        if (($session['status'] ?? null) === 'open') {
            $this->expireCheckoutSession($id, $account);
        } elseif (($session['status'] ?? null) === 'complete') {
            throw new CartCheckoutRefused('Your payment is being confirmed. Please wait a moment.');
        }
    }

    /** A plausible address, or null — an obviously bad one would make Stripe refuse the page. */
    private static function usableEmail(?string $email): ?string
    {
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return str_contains(substr($email, strrpos($email, '@') + 1), '.') ? $email : null;
    }

    private static function assertConnectedAccount(string $connectedAccountId): void
    {
        if (! FormChargeAccount::isAccount($connectedAccountId)) {
            throw new LogicException('A cart payment page is only ever opened, read or closed on a connected account.');
        }
    }

    // ---- The three Stripe seams, overridden in tests (as the siblings are). ----

    /** @return array{id: ?string, url: ?string, payment_intent: ?string} */
    protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
    {
        self::assertConnectedAccount($connectedAccountId);

        $session = $this->stripe->checkout->sessions->create($params, [
            'stripe_account' => $connectedAccountId,
            'idempotency_key' => $idempotencyKey,
        ]);

        $pi = $session->payment_intent ?? null;

        return [
            'id' => $session->id ?? null,
            'url' => $session->url ?? null,
            'payment_intent' => is_string($pi) ? $pi : ($pi->id ?? null),
        ];
    }

    /** @return array{status: ?string, url: ?string} */
    protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
    {
        self::assertConnectedAccount($connectedAccountId);

        $session = $this->stripe->checkout->sessions->retrieve($sessionId, [], ['stripe_account' => $connectedAccountId]);

        return ['status' => $session->status ?? null, 'url' => $session->url ?? null];
    }

    protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
    {
        self::assertConnectedAccount($connectedAccountId);

        $this->stripe->checkout->sessions->expire($sessionId, [], ['stripe_account' => $connectedAccountId]);
    }
}
