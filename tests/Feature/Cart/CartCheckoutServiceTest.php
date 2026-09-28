<?php

namespace Tests\Feature\Cart;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Cart\CartCheckoutRefused;
use App\Services\Cart\CartCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Sending a priced basket to ONE Stripe Checkout page (design §11), including every
 * finding the checkout review confirmed (design/checkout-review-2026-09-28.json).
 *
 * The three Stripe seams are overridden — the siblings' test pattern — so nothing
 * reaches the network, and every call is recorded so the PARAMETERS can be pinned.
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
            public ?\Throwable $failCreateWith = null;
            public array $retrieveQueue = [];
            public ?\Throwable $retrieveThrows = null;
            public ?\Throwable $expireThrows = null;

            public function __construct(StripeClient $stripe, public array $retrieveAnswer)
            {
                parent::__construct($stripe);
            }

            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                if ($this->onCreate) {
                    ($this->onCreate)($params, $connectedAccountId, $idempotencyKey);
                }
                if ($this->failCreateWith) {
                    throw $this->failCreateWith;
                }
                if (isset($params['customer_email']) && $this->refuseEmailTimes > 0) {
                    $this->refuseEmailTimes--;
                    throw InvalidRequestException::factory('Invalid email address', 400, null, null, null, null, 'customer_email');
                }
                $this->created[] = ['params' => $params, 'account' => $connectedAccountId, 'key' => $idempotencyKey];
                $n = count($this->created);

                return ['id' => "cs_test_{$n}", 'url' => "https://checkout.stripe.test/{$n}", 'payment_intent' => null];
            }

            protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
            {
                $this->retrieved[] = $sessionId;
                if ($this->retrieveThrows) {
                    throw $this->retrieveThrows;
                }

                return $this->retrieveQueue !== [] ? array_shift($this->retrieveQueue) : $this->retrieveAnswer;
            }

            protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
            {
                if ($this->expireThrows) {
                    throw $this->expireThrows;
                }
                $this->expired[] = $sessionId;
            }
        };
    }

    /** Two $15 tickets (stored as the 2 places they are) and a $50 gift: $80. */
    private function fullBasket(array $orgOverrides = []): array
    {
        $org = $this->org($orgOverrides);
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 2, $this->twoTickets());
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        return [$org, $cart];
    }

    private function refusal(CartCheckoutService $svc, $cart): CartCheckoutRefused
    {
        try {
            $svc->checkout($cart, self::RETURN_BASE);
        } catch (CartCheckoutRefused $r) {
            return $r;
        }
        $this->fail('checkout should have refused');
    }

    // ---- the page itself ----

    #[Test]
    public function one_page_is_opened_as_a_direct_charge_on_the_organisations_own_account(): void
    {
        [$org, $cart] = $this->fullBasket();
        $svc = $this->service();

        $result = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org');

        $this->assertCount(1, $svc->created, 'exactly one page');
        $p = $svc->created[0]['params'];
        $this->assertSame($org->stripe_account_id, $svc->created[0]['account'], 'the org, never the platform');
        $this->assertSame('payment', $p['mode']);
        $this->assertSame(['card'], $p['payment_method_types']);
        $this->assertSame(8000, array_sum(array_map(fn ($l) => $l['price_data']['unit_amount'] * $l['quantity'], $p['line_items'])));
        $this->assertSame('https://checkout.stripe.test/1', $result['url']);
    }

    #[Test]
    public function on_its_own_account_the_routing_key_rides_on_both_the_session_and_the_payment_intent(): void
    {
        [, $cart] = $this->fullBasket();
        $svc = $this->service();

        $order = $svc->checkout($cart, self::RETURN_BASE)['order'];
        $p = $svc->created[0]['params'];

        $this->assertSame($order->uuid, $p['metadata'][CartCheckoutService::METADATA_KEY]);
        $this->assertSame($order->uuid, $p['payment_intent_data']['metadata'][CartCheckoutService::METADATA_KEY]);
        $this->assertSame($order->uuid, $p['client_reference_id']);
        $this->assertStringStartsWith(self::RETURN_BASE . '?', $p['success_url'], 'returns only to the checked base');
    }

    #[Test]
    public function on_a_holders_account_only_an_opaque_reference_is_in_the_metadata(): void
    {
        // Review: a linked org's page carried the public uuid and the child's masjid_id
        // on the PARENT's Stripe account, whose users read that metadata.
        $holder = $this->org(['stripe_account_id' => 'acct_holder_' . uniqid()]);
        $child = $this->org(['name' => 'Burlington Islamic Sunday School', 'stripe_account_id' => null, 'stripe_charges_enabled' => false]);
        DB::table('masjids')->where('id', $child->id)->update(['parent_id' => $holder->id, 'forms_card_via_masjid_id' => $holder->id]);
        $cart = $this->cart($child->refresh());
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($child)->id, 1500, 2, $this->twoTickets());
        $svc = $this->service();

        $order = $svc->checkout($cart, self::RETURN_BASE)['order'];
        $p = $svc->created[0]['params'];

        $this->assertSame($holder->stripe_account_id, $svc->created[0]['account'], 'charged on the holder');
        $this->assertSame([CartCheckoutService::CHARGE_REF_KEY => $order->charge_ref], $p['metadata'], 'nothing but the opaque ref');
        $this->assertSame([CartCheckoutService::CHARGE_REF_KEY => $order->charge_ref], $p['payment_intent_data']['metadata']);
        $this->assertArrayNotHasKey('client_reference_id', $p, 'the uuid is a bearer handle; never on the holder\'s account');
        $this->assertStringNotContainsString((string) $order->uuid, json_encode($p['metadata']));
        $this->assertStringContainsString('Burlington Islamic Sunday School', $p['payment_intent_data']['description']);
        $this->assertStringStartsWith('cref_', (string) $order->charge_ref);
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
        $this->assertSame(64, strlen((string) $order->basket_fingerprint));
        $this->assertSame(2, OrderItem::withoutMasjidScope()->where('order_id', $order->id)->count());
    }

    #[Test]
    public function no_application_fee_key_is_sent_when_the_fee_is_zero_and_it_is_when_above(): void
    {
        config(['services.stripe.platform_fee_percentage' => 0]);
        [, $cart] = $this->fullBasket();
        $svc = $this->service();
        $svc->checkout($cart, self::RETURN_BASE);
        $this->assertArrayNotHasKey('application_fee_amount', $svc->created[0]['params']['payment_intent_data'], 'Stripe rejects a zero fee');

        config(['services.stripe.platform_fee_percentage' => 0.02]);
        [, $cart2] = $this->fullBasket();
        $svc->checkout($cart2, self::RETURN_BASE);
        $this->assertSame(160, $svc->created[1]['params']['payment_intent_data']['application_fee_amount']);
    }

    // ---- the blocker: reuse only the SAME basket's page ----

    #[Test]
    public function pressing_pay_twice_on_the_same_basket_hands_back_the_same_page(): void
    {
        [, $cart] = $this->fullBasket();
        $svc = $this->service(['status' => 'open', 'url' => 'https://checkout.stripe.test/1']);

        $first = $svc->checkout($cart, self::RETURN_BASE);
        $second = $svc->checkout($cart, self::RETURN_BASE);

        $this->assertCount(1, $svc->created, 'one page, never two');
        $this->assertSame($first['order']->id, $second['order']->id);
    }

    #[Test]
    public function a_different_basket_with_the_same_total_never_gets_the_old_page(): void
    {
        // The review's blocker: reuse matched only total and account, so swapping a $50
        // gift to one fund for a $50 gift to another sent the shopper to the OLD page,
        // and the webhook would book the gift to the old fund.
        $org = $this->org();
        $cart = $this->cart($org);
        $zakat = $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $svc = $this->service(['status' => 'open', 'url' => 'https://checkout.stripe.test/1']);
        $old = $svc->checkout($cart, self::RETURN_BASE)['order'];

        $zakat->delete();
        $building = \App\Models\Fund::create(['masjid_id' => $org->id, 'name' => 'Building Fund', 'type' => 'general', 'is_active' => true]);
        $this->add($cart, CartItem::TYPE_DONATION, $building->id, 5000);   // same $50
        $new = $svc->checkout($cart, self::RETURN_BASE)['order'];

        $this->assertNotSame($old->id, $new->id, 'a new order for the new basket');
        $this->assertCount(2, $svc->created, 'a new page was opened');
        $this->assertSame(['cs_test_1'], $svc->expired, 'the old page was closed');
        $this->assertSame(Order::STATUS_EXPIRED, $old->fresh()->status, 'and stops being selected');
        $this->assertSame($building->id, OrderItem::withoutMasjidScope()->where('order_id', $new->id)->value('buyable_id'));
    }

    #[Test]
    public function changed_attendee_names_are_a_different_basket_too(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $line = $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 2, $this->twoTickets());
        $svc = $this->service(['status' => 'open', 'url' => 'https://checkout.stripe.test/1']);
        $svc->checkout($cart, self::RETURN_BASE);

        $line->forceFill(['payload' => ['tickets' => [['attendeeName' => 'X'], ['attendeeName' => 'Y']]]])->save();
        $svc->checkout($cart, self::RETURN_BASE);

        $this->assertCount(2, $svc->created, 'the tickets would be issued in the wrong names on the old page');
    }

    #[Test]
    public function a_page_already_paid_is_never_offered_again(): void
    {
        [, $cart] = $this->fullBasket();
        $svc = $this->service(['status' => 'complete', 'url' => null]);
        $svc->checkout($cart, self::RETURN_BASE);

        $this->refusal($svc, $cart);
        $this->assertCount(1, $svc->created, 'no second page for a basket already paid on Stripe');
    }

    // ---- what the shopper is shown ----

    #[Test]
    public function a_basket_that_changed_is_refused_with_the_changes_named_and_nothing_charged(): void
    {
        [$org, $cart] = $this->fullBasket();
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org, active: false)->id, 2500);
        $svc = $this->service();

        $refusal = $this->refusal($svc, $cart);

        $this->assertCount(1, $refusal->notices());
        $this->assertSame('gone', $refusal->notices()[0]['status']);
        $this->assertNotNull($refusal->seen(), 'the refusal carries what the shopper is shown');
        $this->assertSame([], $svc->created);
        $this->assertSame(0, Order::withoutMasjidScope()->count());
    }

    #[Test]
    public function acknowledging_what_was_shown_lets_checkout_through(): void
    {
        [$org, $cart] = $this->fullBasket();
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org, active: false)->id, 2500);
        $svc = $this->service();
        $seen = $this->refusal($svc, $cart)->seen();

        $priced = $svc->acknowledge($cart, $seen);
        $this->assertSame([], $priced->notices());

        $this->assertSame(8000, $svc->checkout($cart, self::RETURN_BASE)['order']->total_minor);
    }

    #[Test]
    public function a_change_after_the_shopper_was_shown_the_notice_is_not_adopted_silently(): void
    {
        // Review: acknowledge() used to adopt whatever the price was AT acknowledge time.
        $org = $this->org();
        $cart = $this->cart($org);
        $dish = $this->dish($org, ['price_minor' => 1500]);   // shown at $12, now $15
        $this->add($cart, CartItem::TYPE_MEAL, $dish->id, 1200, 1, ['pickup_at' => now()->addDays(3)->toIso8601String()]);
        $svc = $this->service();
        $seen = $this->refusal($svc, $cart)->seen();

        $dish->forceFill(['price_minor' => 2500])->save();   // edited again before the "OK"

        try {
            $svc->acknowledge($cart, $seen);
            $this->fail('the $25 was never shown to the shopper');
        } catch (CartCheckoutRefused $again) {
            $this->assertStringContainsString('price changed', $again->notices()[0]['reason']);
        }
        $this->assertSame(1200, CartItem::withoutMasjidScope()->where('cart_id', $cart->id)->value('unit_amount_shown_minor'), 'nothing was written');
    }

    #[Test]
    public function a_form_whose_places_were_recounted_is_told_not_charged_silently(): void
    {
        // Review: quantity was never compared — a flat $15 switched to $15 per attendee
        // charged 4 x $15 for a line the shopper saw as one place.
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 1, [
            'tickets' => [['attendeeName' => 'A'], ['attendeeName' => 'B'], ['attendeeName' => 'C'], ['attendeeName' => 'D']],
        ]);

        $refusal = $this->refusal($this->service(), $cart);

        $this->assertSame('repriced', $refusal->notices()[0]['status']);
        $this->assertStringContainsString('now for 4', $refusal->notices()[0]['reason']);
    }

    #[Test]
    public function a_form_with_card_payment_switched_off_cannot_be_paid_through_the_basket(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $form = $this->ticketForm($org, ['settings' => [
            'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'tickets'],
            'payment' => ['online' => false, 'officePayment' => true],
        ]]);
        $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 2, $this->twoTickets());

        $refusal = $this->refusal($this->service(), $cart);

        $this->assertSame('gone', $refusal->notices()[0]['status']);
    }

    #[Test]
    public function a_form_that_requires_fee_coverage_is_paid_on_its_own_page_not_in_the_basket(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $form = $this->ticketForm($org, ['settings' => [
            'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'tickets'],
            'payment' => ['online' => true, 'requireFeeCoverage' => true],
        ]]);
        $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 2, $this->twoTickets());

        $refusal = $this->refusal($this->service(), $cart);

        $this->assertSame('gone', $refusal->notices()[0]['status'], 'charging it without the coverage takes less than the org set');
    }

    // ---- bounds, accounts and robustness ----

    #[Test]
    public function a_total_outside_stripes_bounds_is_refused_before_anything_is_written(): void
    {
        $org = $this->org();
        $tiny = $this->cart($org);
        $this->add($tiny, CartItem::TYPE_DONATION, $this->fund($org)->id, 30);   // 30 cents
        $huge = $this->cart($org);
        $this->add($huge, CartItem::TYPE_DONATION, $this->fund($org)->id, 100_000_000);
        $svc = $this->service();

        $this->refusal($svc, $tiny);
        $this->refusal($svc, $huge);

        $this->assertSame([], $svc->created);
        $this->assertSame(0, Order::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_account_that_is_not_a_connected_account_never_reaches_stripe(): void
    {
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
    public function a_page_past_its_expiry_is_not_reused_and_stripe_is_not_asked(): void
    {
        [, $cart] = $this->fullBasket();
        $svc = $this->service();
        $old = $svc->checkout($cart, self::RETURN_BASE)['order'];
        $old->forceFill(['checkout_expires_at' => now()->subMinute()])->save();
        $svc->retrieved = [];

        $svc->checkout($cart, self::RETURN_BASE);

        $this->assertSame([], $svc->retrieved, 'an expired page is known without asking Stripe');
        $this->assertSame(Order::STATUS_EXPIRED, $old->fresh()->status);
        $this->assertCount(2, $svc->created);
    }

    #[Test]
    public function an_unreachable_old_account_never_leaves_the_basket_unpayable(): void
    {
        // Review: a page pinned to an account the platform can no longer reach blocked
        // the basket forever, because every reuse tried to read it.
        [, $cart] = $this->fullBasket();
        $svc = $this->service();
        $old = $svc->checkout($cart, self::RETURN_BASE)['order'];
        $svc->retrieveThrows = PermissionException::factory('No such account', 403);

        $result = $svc->checkout($cart, self::RETURN_BASE);

        $this->assertSame(Order::STATUS_EXPIRED, $old->fresh()->status);
        $this->assertNotSame($old->id, $result['order']->id, 'a new page on the current account');
    }

    #[Test]
    public function if_stripe_refuses_the_close_because_the_payer_just_paid_it_says_confirming(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $gift = $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $svc = $this->service(['status' => 'open', 'url' => 'https://checkout.stripe.test/1']);
        $svc->checkout($cart, self::RETURN_BASE);

        $gift->forceFill(['unit_amount_shown_minor' => 6000])->save();   // a different basket now
        $svc->expireThrows = InvalidRequestException::factory('Session is not open', 400);
        $svc->retrieveQueue = [['status' => 'open', 'url' => 'x'], ['status' => 'complete', 'url' => null]];

        // The donor typed $60 and the pricer shows $60 — a real change — but the old $50
        // page was paid in the race: never open a second page.
        $refusal = $this->refusal($svc, $cart);
        $this->assertStringContainsString('confirmed', $refusal->getMessage());
        $this->assertCount(1, $svc->created);
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
        $this->assertNotSame($keys[0], $keys[1], 'a refused key belongs to its parameters');
        $this->assertArrayNotHasKey('customer_email', $svc->created[0]['params']);
        $this->assertSame($keys[1], $order->fresh()->idempotency_key);
    }

    #[Test]
    public function a_refusal_about_anything_but_the_email_is_not_retried(): void
    {
        [, $cart] = $this->fullBasket();
        $svc = $this->service();
        $svc->failCreateWith = InvalidRequestException::factory('Amount too large', 400, null, null, null, null, 'line_items');
        $calls = 0;
        $svc->onCreate = function () use (&$calls): void { $calls++; };

        try {
            $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org');
            $this->fail('the refusal should surface');
        } catch (InvalidRequestException) {
        }

        $this->assertSame(1, $calls, 'a pointless retry would fail the same way');
    }

    #[Test]
    public function an_unusable_email_is_not_sent_and_a_usable_one_is(): void
    {
        $svc = $this->service();   // ONE fake: two would both name their first page cs_test_1

        [, $cart] = $this->fullBasket();
        $svc->checkout($cart, self::RETURN_BASE, 'not-an-email');
        $this->assertArrayNotHasKey('customer_email', $svc->created[0]['params']);

        [, $cart2] = $this->fullBasket();
        $svc->checkout($cart2, self::RETURN_BASE, 'buyer@example.org');
        $this->assertSame('buyer@example.org', $svc->created[1]['params']['customer_email']);
    }
}
