<?php

namespace Tests\Feature\Cart;

use App\Models\CartItem;
use App\Models\Donation;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\Order;
use App\Services\Cart\CartCheckoutService;
use App\Services\Stripe\CartPaymentService;
use App\Services\Stripe\DonationService;
use App\Services\Stripe\FormResponsePaymentService;
use App\Services\Stripe\MealOrderPaymentService;
use App\Services\Stripe\MealOrderTopUpPaymentService;
use App\Services\Stripe\RegistrationPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whose event is it? The cart's arm in StripeWebhookController::dispatch() is asked LAST,
 * only when no older question matched (design §12, slice 4b). Without it a cart event
 * would fall to the donation default and be silently booked as one.
 *
 * Proved two ways: the routing questions are mutually exclusive (no metadata satisfies
 * the cart's and an older one at once, so no event can be routed twice), and through the
 * real route, with traps set for each old arm: a pending donation that a mis-routed
 * cart event WOULD have marked succeeded, by the exact keys the donation default reads.
 * The reverse is pinned too: a donation event never touches a basket.
 *
 * Every older arm's own suite (DonationFlowTest, FormPaymentWebhookTest,
 * RegistrationWebhookTest, MealOrderTopUpTest, ...) passes untouched beside this one.
 */
class CartWebhookRoutingTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /** A form-only basket (no gift line), so any Donation in the database is a mis-route. */
    private function ticketOrder(): Order
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 2, $this->twoTickets());

        return $this->placeOrder($cart);
    }

    /** A pending donation to any fund of the order's organisation. */
    private function pendingDonation(Order $order): Donation
    {
        $org = Masjid::query()->findOrFail($order->masjid_id);

        return app(DonationService::class)->createPendingDonation($org, $this->fund($org), 1000, false);
    }

    #[Test]
    public function no_metadata_answers_the_carts_question_and_an_older_one_at_once(): void
    {
        $cartKeys = [
            [CartCheckoutService::METADATA_KEY => 'x'],
            [CartCheckoutService::CHARGE_REF_KEY => 'x'],
        ];
        $olderKeys = [
            ['donation_uuid' => 'x'],
            ['order_uuid' => 'x'],
            ['registration_uuid' => 'x'],
            ['form_response_uuid' => 'x'],
            ['form_charge_ref' => 'x'],
            ['kind' => 'lunch_top_up', 'order_uuid' => 'x'],
        ];

        foreach ($cartKeys as $metadata) {
            $object = ['metadata' => $metadata];

            $this->assertTrue(CartPaymentService::isCartEvent($object));
            $this->assertFalse(MealOrderPaymentService::isOrderEvent($object), 'a cart event is not a lunch order');
            $this->assertFalse(RegistrationPaymentService::isRegistrationEvent($object), 'nor a registration');
            $this->assertFalse(FormResponsePaymentService::isFormResponseEvent($object), 'nor a form response');
            $this->assertFalse(MealOrderTopUpPaymentService::isTopUpEvent($object), 'nor a top-up');
        }

        foreach ($olderKeys as $metadata) {
            $this->assertFalse(CartPaymentService::isCartEvent(['metadata' => $metadata]), 'an older event is never a cart event: ' . json_encode($metadata));
        }

        $this->assertFalse(CartPaymentService::isCartEvent([]), 'a plain donation event carries neither key');
        $this->assertFalse(CartPaymentService::isCartEvent(['metadata' => [CartCheckoutService::METADATA_KEY => '']]), 'an empty key is no key');
    }

    #[Test]
    public function a_cart_session_never_books_a_donation_through_the_old_default(): void
    {
        $order = $this->ticketOrder();

        // The trap: a pending donation whose uuid is the cart's client_reference_id and whose
        // payment intent is the one the cart is paid with. The donation default reads exactly
        // these, so a cart event that reached it would mark this donation succeeded.
        $trap = $this->pendingDonation($order);
        $trap->forceFill(['uuid' => $order->uuid, 'stripe_payment_intent_id' => 'pi_cart_1'])->save();

        $this->postWebhook($this->sessionEvent($order))->assertOk();
        $this->postWebhook($this->intentEvent($order))->assertOk();

        $trap->refresh();
        $this->assertSame('pending', $trap->status, 'the cart event never reached the donation default');
        $this->assertNull($trap->stripe_checkout_session_id);
        $this->assertSame(1, Donation::withoutMasjidScope()->count(), 'and booked no donation of its own');

        // The basket itself settled, through its own arm.
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(1, FormResponse::query()->count());
    }

    #[Test]
    public function a_cart_event_that_cannot_be_settled_is_still_not_a_donation(): void
    {
        $order = $this->ticketOrder();
        $trap = $this->pendingDonation($order);
        $trap->forceFill(['uuid' => $order->uuid, 'stripe_payment_intent_id' => 'pi_cart_1'])->save();

        // Unknown account, no account, another uuid: each is refused inside the cart arm and
        // must NOT fall through to the donation default, which would settle the trap.
        $this->postWebhook($this->sessionEvent($order, ['account' => 'acct_nobody_holds_this']))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['account' => null]))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['metadata' => [CartCheckoutService::METADATA_KEY => (string) Str::uuid()]]))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['type' => 'checkout.session.async_payment_failed', 'payment_status' => 'unpaid']))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['type' => 'checkout.session.expired', 'payment_status' => 'unpaid', 'account' => 'acct_nobody_holds_this']))->assertOk();

        $this->assertSame('pending', $trap->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(0, FormResponse::query()->count());
    }

    #[Test]
    public function a_linked_baskets_event_is_not_a_donation_either(): void
    {
        // A holder's basket carries only cart_charge_ref and no client_reference_id at all.
        $order = $this->ticketOrder();
        $order->forceFill(['charge_ref' => 'cref_' . Str::random(32)])->save();
        $trap = $this->pendingDonation($order);
        $trap->forceFill(['stripe_payment_intent_id' => 'pi_cart_1'])->save();

        // Wrong account for the pin: refused inside the cart arm.
        $this->postWebhook($this->intentEvent($order->fresh(), ['account' => 'acct_stranger']))->assertOk();

        $this->assertSame('pending', $trap->fresh()->status, 'the payment-intent match the donation default makes is never reached');
    }

    #[Test]
    public function a_donation_event_never_touches_a_basket(): void
    {
        $order = $this->ticketOrder();
        $donation = $this->pendingDonation($order);
        $org = Masjid::query()->findOrFail($order->masjid_id);

        // A plain donation checkout, exactly as the donation door opens one: no cart key.
        $event = [
            'id' => 'evt_' . Str::random(24),
            'type' => 'checkout.session.completed',
            'account' => $org->stripe_account_id,
            'created' => time(),
            'data' => ['object' => [
                'id' => 'cs_donation_1',
                'object' => 'checkout.session',
                'mode' => 'payment',
                'status' => 'complete',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_donation_1',
                'amount_total' => 1000,
                'currency' => 'usd',
                'client_reference_id' => $donation->uuid,
                'metadata' => ['donation_uuid' => $donation->uuid],
            ]],
        ];

        $this->postWebhook($event)->assertOk();

        $this->assertSame('succeeded', $donation->fresh()->status, 'the donation default still books a donation');
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'and the basket beside it is untouched');
        $this->assertNull($order->fresh()->stripe_payment_intent_id);
        $this->assertSame(0, FormResponse::query()->count());
    }
}
