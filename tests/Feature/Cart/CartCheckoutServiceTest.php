<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Cart\CartCheckoutRefused;
use App\Services\Cart\CartCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Sending a priced basket to ONE Stripe Checkout page (design §11).
 *
 * The three Stripe seams are overridden — the siblings' test pattern — so nothing
 * reaches the network, and every call is recorded so the PARAMETERS can be pinned.
 * The rules below are the ones that move or protect money.
 */
class CartCheckoutServiceTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;

    private const RETURN_BASE = 'https://mec.manara.hopetechapps.com/basket';

    /** A service whose Stripe seams record every call instead of making it. */
    private function service(array $retrieveAnswer = ['status' => 'open', 'url' => 'https://checkout.stripe.test/existing']): CartCheckoutService
    {
        return new class(new StripeClient('sk_test_offline'), $retrieveAnswer) extends CartCheckoutService {
            public array $created = [];
            public array $retrieved = [];
            public array $expired = [];
            public ?\Closure $onCreate = null;
            public int $refuseEmailTimes = 0;

            public function __construct(StripeClient $stripe, public array $retrieveAnswer)
            {
                parent::__construct($stripe);
            }

            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                if ($this->onCreate) {
                    ($this->onCreate)($params, $connectedAccountId, $idempotencyKey);
                }
                if (isset($params['customer_email']) && $this->refuseEmailTimes > 0) {
                    $this->refuseEmailTimes--;
                    throw new InvalidRequestException('Invalid email address: someone@example.test');
                }
                $this->created[] = ['params' => $params, 'account' => $connectedAccountId, 'key' => $idempotencyKey];
                $n = count($this->created);

                return ['id' => "cs_test_{$n}", 'url' => "https://checkout.stripe.test/{$n}", 'payment_intent' => null];
            }

            protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
            {
                $this->retrieved[] = $sessionId;

                return $this->retrieveAnswer;
            }

            protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
            {
                $this->expired[] = $sessionId;
            }
        };
    }

    /** A basket of two $15 tickets and a $50 gift: $80. */
    private function fullBasket(array $orgOverrides = []): array
    {
        $org = $this->org($orgOverrides);
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 1, $this->twoTickets());
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        return [$org, $cart];
    }

    #[Test]
    public function one_page_is_opened_as_a_direct_charge_on_the_organisations_own_account(): void
    {
        [$org, $cart] = $this->fullBasket();
        $svc = $this->service();

        $result = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org');

        $this->assertCount(1, $svc->created, 'exactly one page');
        $call = $svc->created[0];
        $p = $call['params'];

        $this->assertSame($org->stripe_account_id, $call['account'], 'the stripe_account request option is the org, never the platform');
        $this->assertSame('payment', $p['mode']);
        $this->assertSame(['card'], $p['payment_method_types']);
        $this->assertSame(8000, array_sum(array_map(fn ($l) => $l['price_data']['unit_amount'] * $l['quantity'], $p['line_items'])));
        $this->assertSame('https://checkout.stripe.test/1', $result['url']);
    }

    #[Test]
    public function the_routing_key_rides_on_both_the_session_and_the_payment_intent(): void
    {
        [, $cart] = $this->fullBasket();
        $svc = $this->service();

        $order = $svc->checkout($cart, self::RETURN_BASE)['order'];
        $p = $svc->created[0]['params'];

        $this->assertSame($order->uuid, $p['metadata'][CartCheckoutService::METADATA_KEY]);
        $this->assertSame($order->uuid, $p['payment_intent_data']['metadata'][CartCheckoutService::METADATA_KEY]);
        $this->assertSame((string) $order->masjid_id, $p['payment_intent_data']['metadata']['masjid_id']);
        $this->assertSame($order->uuid, $p['client_reference_id']);
        $this->assertStringContainsString($order->uuid, $p['success_url']);
        $this->assertStringStartsWith(self::RETURN_BASE . '?', $p['success_url'], 'returns only to the checked base');
    }

    #[Test]
    public function the_idempotency_key_is_saved_on_the_order_before_stripe_is_asked(): void
    {
        [, $cart] = $this->fullBasket();
        $svc = $this->service();
        $seenBeforeCall = null;
        $svc->onCreate = function (array $params, string $acct, string $key) use (&$seenBeforeCall): void {
            $seenBeforeCall = Order::withoutMasjidScope()->where('idempotency_key', $key)->exists();
        };

        $svc->checkout($cart, self::RETURN_BASE);

        $this->assertTrue($seenBeforeCall, 'a retry must re-send a key that is already on the row');
    }

    #[Test]
    public function the_order_is_a_pending_snapshot_and_nothing_is_marked_paid(): void
    {
        [, $cart] = $this->fullBasket();

        $order = $this->service()->checkout($cart, self::RETURN_BASE)['order']->fresh();

        $this->assertSame(Order::STATUS_PENDING, $order->status, 'only the webhook marks an order paid');
        $this->assertNull($order->paid_at);
        $this->assertSame(8000, $order->total_minor);
        $this->assertSame('cs_test_1', $order->stripe_checkout_session_id);
        $this->assertSame(2, OrderItem::withoutMasjidScope()->where('order_id', $order->id)->count());
        $this->assertEqualsWithDelta(now()->addSeconds(CartCheckoutService::PAGE_LIFETIME_SECONDS)->getTimestamp(), $order->checkout_expires_at->getTimestamp(), 5);
    }

    #[Test]
    public function no_application_fee_key_is_sent_when_the_fee_is_zero(): void
    {
        config(['services.stripe.platform_fee_percentage' => 0]);
        [, $cart] = $this->fullBasket();
        $svc = $this->service();

        $svc->checkout($cart, self::RETURN_BASE);

        $this->assertArrayNotHasKey('application_fee_amount', $svc->created[0]['params']['payment_intent_data'], 'Stripe rejects a zero fee');
    }

    #[Test]
    public function a_platform_fee_above_zero_is_sent_on_the_charged_total(): void
    {
        config(['services.stripe.platform_fee_percentage' => 0.02]);
        [, $cart] = $this->fullBasket();
        $svc = $this->service();

        $svc->checkout($cart, self::RETURN_BASE);

        $this->assertSame(160, $svc->created[0]['params']['payment_intent_data']['application_fee_amount']);
    }

    #[Test]
    public function a_basket_that_changed_is_refused_with_the_changes_named_and_nothing_charged(): void
    {
        [$org, $cart] = $this->fullBasket();
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org, active: false)->id, 2500);
        $svc = $this->service();

        try {
            $svc->checkout($cart, self::RETURN_BASE);
            $this->fail('a basket with a line that went must not reach the card screen');
        } catch (CartCheckoutRefused $refusal) {
            $this->assertCount(1, $refusal->notices());
            $this->assertSame('gone', $refusal->notices()[0]['status']);
        }

        $this->assertSame([], $svc->created, 'Stripe was never asked');
        $this->assertSame(0, Order::withoutMasjidScope()->count(), 'no order was written');
    }

    #[Test]
    public function after_the_shopper_acknowledges_the_changes_checkout_goes_through(): void
    {
        [$org, $cart] = $this->fullBasket();
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org, active: false)->id, 2500);
        $svc = $this->service();

        $priced = $svc->acknowledge($cart);
        $this->assertSame([], $priced->notices(), 'acknowledging brings the basket into line');

        $order = $svc->checkout($cart, self::RETURN_BASE)['order'];
        $this->assertSame(8000, $order->total_minor, 'the closed fund is gone; the rest is charged');
    }

    #[Test]
    public function an_empty_basket_is_refused_before_stripe(): void
    {
        $svc = $this->service();

        $this->expectException(CartCheckoutRefused::class);
        try {
            $svc->checkout($this->cart($this->org()), self::RETURN_BASE);
        } finally {
            $this->assertSame([], $svc->created);
        }
    }

    #[Test]
    public function an_account_that_is_not_a_connected_account_never_reaches_stripe(): void
    {
        // An empty or bogus `stripe_account` would charge the PLATFORM. The invariant is
        // that nothing reaches Stripe and no order is written — whichever layer stops it
        // (the pricer drops every line with no real payee; checkout's guard is the
        // backstop). A donation-only basket, since the donation check alone would have
        // passed any non-null string before the pricer demanded a real acct_ id.
        $org = $this->org(['stripe_account_id' => 'not_a_connected_account']);
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $svc = $this->service();

        $stopped = null;
        try {
            $svc->checkout($cart, self::RETURN_BASE);
        } catch (CartCheckoutRefused|LogicException $e) {
            $stopped = $e;
        }

        $this->assertNotNull($stopped, 'checkout must refuse');
        $this->assertSame([], $svc->created, 'nothing was sent to Stripe');
        $this->assertSame(0, Order::withoutMasjidScope()->count(), 'no order was written');
    }

    #[Test]
    public function pressing_pay_twice_hands_back_the_open_page_instead_of_opening_a_second(): void
    {
        [, $cart] = $this->fullBasket();
        $svc = $this->service(['status' => 'open', 'url' => 'https://checkout.stripe.test/1']);

        $first = $svc->checkout($cart, self::RETURN_BASE);
        $second = $svc->checkout($cart, self::RETURN_BASE);

        $this->assertCount(1, $svc->created, 'one page, never two');
        $this->assertSame($first['order']->id, $second['order']->id);
        $this->assertSame(1, Order::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_page_already_paid_is_never_offered_again(): void
    {
        [, $cart] = $this->fullBasket();
        $svc = $this->service(['status' => 'complete', 'url' => null]);
        $svc->checkout($cart, self::RETURN_BASE);

        $this->expectException(CartCheckoutRefused::class);
        try {
            $svc->checkout($cart, self::RETURN_BASE);
        } finally {
            $this->assertCount(1, $svc->created, 'no second page for a basket already paid on Stripe');
        }
    }

    #[Test]
    public function a_basket_whose_total_changed_closes_the_old_page_and_opens_a_new_one(): void
    {
        [$org, $cart] = $this->fullBasket();
        $svc = $this->service(['status' => 'open', 'url' => 'https://checkout.stripe.test/1']);
        $svc->checkout($cart, self::RETURN_BASE);

        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 1000);   // basket now $90
        $second = $svc->checkout($cart, self::RETURN_BASE);

        $this->assertSame(['cs_test_1'], $svc->expired, 'the stale $80 page is closed');
        $this->assertCount(2, $svc->created);
        $this->assertSame(9000, $second['order']->total_minor);
    }

    #[Test]
    public function an_unusable_email_is_not_sent_and_a_usable_one_is(): void
    {
        // ONE fake for both: two separate fakes would both name their first page
        // cs_test_1, and orders.stripe_checkout_session_id is (rightly) unique.
        $svc = $this->service();

        [, $cart] = $this->fullBasket();
        $svc->checkout($cart, self::RETURN_BASE, 'not-an-email');
        $this->assertArrayNotHasKey('customer_email', $svc->created[0]['params']);

        [, $cart2] = $this->fullBasket();
        $svc->checkout($cart2, self::RETURN_BASE, 'buyer@example.org');
        $this->assertSame('buyer@example.org', $svc->created[1]['params']['customer_email']);
    }

    #[Test]
    public function an_email_stripe_refuses_is_dropped_and_retried_once_on_a_new_key(): void
    {
        [, $cart] = $this->fullBasket();
        $svc = $this->service();
        $svc->refuseEmailTimes = 1;
        $keys = [];
        $svc->onCreate = function (array $p, string $a, string $key) use (&$keys): void { $keys[] = $key; };

        $order = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org')['order'];

        $this->assertCount(2, $keys, 'refused once, retried once');
        $this->assertNotSame($keys[0], $keys[1], 'a refused key belongs to its parameters, so the retry uses a new one');
        $this->assertArrayNotHasKey('customer_email', $svc->created[0]['params']);
        $this->assertSame($keys[1], $order->fresh()->idempotency_key);
    }
}
