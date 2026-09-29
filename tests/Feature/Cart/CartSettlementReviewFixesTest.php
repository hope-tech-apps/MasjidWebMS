<?php

namespace Tests\Feature\Cart;

use App\Mail\DonationReceiptMail;
use App\Mail\KitchenOrderForCustomer;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\FormResponse;
use App\Models\MealOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Cart\CartCheckoutRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The five findings the settlement review confirmed (design/settlement-build-review-
 * 2026-09-29.json, brief-4b-fixes.md). Every test here fails without its fix.
 *
 *  1. a paid basket is closed, and checkout / acknowledge refuse a closed one (and a paid
 *     fingerprint), so the same lines are never charged and recorded twice;
 *  2. a session event that follows the payment intent's settlement backfills the payer and
 *     runs the donor link, receipt delivery and meal confirmation the intent had to skip, once;
 *  3. a linked organisation's registration is pinned to the holder's account (the refund
 *     instruction); the holder's refund or dispute flags the ORDER since round 2;
 *  4. the legacy `amount_due` and `entry_count` are what checkout froze, not what the form
 *     says when the webhook lands;
 *  5. Adaptive Pricing is off on cart pages, and a payment intent in another currency is
 *     skipped quietly and left to the session event.
 */
class CartSettlementReviewFixesTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /** Two $15 tickets, two $12 dishes and a $50 gift: $104.00. */
    private function basket(): array
    {
        $org = $this->org();
        $form = $this->ticketForm($org);
        $dish = $this->dish($org);
        $fund = $this->fund($org);
        $cart = $this->cart($org);

        $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 2, $this->twoTickets());
        $this->add($cart, CartItem::TYPE_MEAL, $dish->id, 1200, 2);
        $this->add($cart, CartItem::TYPE_DONATION, $fund->id, 5000);

        return [$org, $cart, $form, $dish, $fund];
    }

    /** A guest's basket of a gift and a dish: no contact, and (with a null buyer e-mail) no address at all. */
    private function guestBasket(): Cart
    {
        $org = $this->org();
        $cart = $this->cart($org);

        $this->add($cart, CartItem::TYPE_MEAL, $this->dish($org)->id, 1200, 1);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        return $cart;
    }

    /** A child organisation whose form card payments go through its parent's account. */
    private function linkedBasket(): array
    {
        $holder = $this->org(['stripe_account_id' => 'acct_holder_' . uniqid()]);
        $child = $this->org(['name' => 'Burlington Islamic Sunday School', 'stripe_account_id' => null, 'stripe_charges_enabled' => false]);
        DB::table('masjids')->where('id', $child->id)->update(['parent_id' => $holder->id, 'forms_card_via_masjid_id' => $holder->id]);
        $child->refresh();

        $cart = $this->cart($child);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($child)->id, 1500, 2, $this->twoTickets());

        return [$holder, $child, $cart];
    }

    private function refused(callable $call): CartCheckoutRefused
    {
        try {
            $call();
        } catch (CartCheckoutRefused $refusal) {
            return $refusal;
        }

        $this->fail('The call should have been refused.');
    }

    // ------------------------------------------------------------ 1. a paid basket is closed

    #[Test]
    public function settlement_closes_the_basket_and_a_second_checkout_is_refused(): void
    {
        [, $cart] = $this->basket();
        $svc = $this->checkoutService();
        $order = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org')['order']->fresh();

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $closed = Cart::withoutMasjidScope()->findOrFail($cart->id);
        $this->assertSame(Cart::STATUS_CHECKED_OUT, $closed->status, 'the settlement transaction closes the basket');
        $this->assertSame(0, CartItem::withoutMasjidScope()->where('cart_id', $cart->id)->count(), 'the order lines are the snapshot');
        $this->assertSame(3, OrderItem::withoutMasjidScope()->where('order_id', $order->id)->count());

        $this->refused(fn () => $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org'));
        $this->assertCount(1, $svc->created, 'no second page was opened for a basket that was paid');
        $this->assertSame(1, Order::withoutMasjidScope()->count(), 'and no second order was made');

        $this->refused(fn () => $svc->acknowledge($cart, 'any-fingerprint'));
    }

    #[Test]
    public function a_settlement_that_does_not_happen_leaves_the_basket_open(): void
    {
        [, $cart] = $this->basket();
        $order = $this->placeOrder($cart);

        // A payment that does not match the order settles nothing, so it closes nothing.
        $this->postWebhook($this->sessionEvent($order, ['amount' => 1]))->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(Cart::STATUS_OPEN, Cart::withoutMasjidScope()->findOrFail($cart->id)->status);
        $this->assertSame(3, CartItem::withoutMasjidScope()->where('cart_id', $cart->id)->count());
    }

    #[Test]
    public function checkout_refuses_a_basket_whose_fingerprint_already_has_a_paid_order(): void
    {
        [$org, $cart, $form, , $fund] = $this->basket();
        $svc = $this->checkoutService();
        $order = $svc->checkout($cart, self::RETURN_BASE)['order']->fresh();

        // A cart left OPEN with its paid order behind it (paid before baskets were closed).
        Order::withoutMasjidScope()->whereKey($order->id)->update(['status' => Order::STATUS_PAID]);

        $this->refused(fn () => $svc->checkout($cart, self::RETURN_BASE));
        $this->assertCount(1, $svc->created, 'the same lines are never charged twice');

        // A NEW basket with the same contents is a new purchase (a monthly gift, say).
        $again = $this->cart($org);
        $this->add($again, CartItem::TYPE_FORM, $form->id, 1500, 2, $this->twoTickets());
        $this->add($again, CartItem::TYPE_DONATION, $fund->id, 5000);
        $svc->checkout($again, self::RETURN_BASE);
        $this->assertCount(2, $svc->created);
    }

    // ------------------------------------------------------------ 2. the intent first, the session after

    #[Test]
    public function the_session_event_after_the_intent_backfills_the_payer_and_delivers_the_receipt_once(): void
    {
        $cart = $this->guestBasket();
        $order = $this->placeOrder($cart, null);

        $this->postWebhook($this->intentEvent($order))->assertOk();

        // The intent knew no payer: an anonymous gift, a placeholder customer, nothing sent.
        $gift = Donation::withoutMasjidScope()->sole();
        $meal = MealOrder::withoutMasjidScope()->sole();
        $this->assertNull($gift->contact_id);
        $this->assertNull($gift->stripe_checkout_session_id);
        $this->assertSame('Online order ' . $order->order_number, $meal->customer_name);
        $this->assertNull($meal->customer_email);
        $this->assertNull($meal->confirmation_sent_at);
        $this->assertSame(1, DonationReceipt::withoutMasjidScope()->count());
        Mail::assertNothingSent();
        Mail::assertNotQueued(KitchenOrderForCustomer::class);

        // The session event that follows carries the payer.
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $gift->refresh();
        $meal->refresh();
        $this->assertNotNull($gift->contact_id, 'the donor is linked');
        $this->assertSame('buyer@example.org', Contact::withoutMasjidScope()->findOrFail($gift->contact_id)->email);
        $this->assertSame($order->stripe_checkout_session_id, $gift->stripe_checkout_session_id);
        $this->assertSame('Amal Buyer', $meal->customer_name);
        $this->assertSame('buyer@example.org', $meal->customer_email);
        $this->assertNotNull($meal->confirmation_sent_at, 'the confirmation the intent could not send');
        $this->assertNotNull($gift->receipt_delivered_at);
        Mail::assertSent(DonationReceiptMail::class, 1);
        Mail::assertQueued(KitchenOrderForCustomer::class, 1);

        // Nothing was settled twice.
        $this->assertSame(1, DonationReceipt::withoutMasjidScope()->count());
        $this->assertSame(1, Donation::withoutMasjidScope()->count());
        $this->assertSame(1, MealOrder::withoutMasjidScope()->count());
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        // Redeliveries change and send nothing more.
        $claimedAt = $meal->confirmation_sent_at;

        $this->postWebhook($this->sessionEvent($order))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['type' => 'checkout.session.async_payment_succeeded']))->assertOk();

        Mail::assertSent(DonationReceiptMail::class, 1);
        Mail::assertQueued(KitchenOrderForCustomer::class, 1);
        $this->assertTrue($claimedAt->equalTo($meal->fresh()->confirmation_sent_at));
        $this->assertSame(1, DonationReceipt::withoutMasjidScope()->count());
        $this->assertSame(1, Donation::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_backfill_never_overwrites_what_the_order_already_knows(): void
    {
        $cart = $this->guestBasket();
        $order = $this->placeOrder($cart, 'first@example.org');

        $this->postWebhook($this->intentEvent($order))->assertOk();

        $meal = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame('first@example.org', $meal->customer_email, 'premise: the basket carried an address');
        $gift = Donation::withoutMasjidScope()->sole();
        $contactId = $gift->contact_id;
        $this->assertNotNull($contactId, 'premise: the gift was linked from the basket\'s address');

        // The session's payer typed another address; the order's own stays.
        $event = $this->sessionEvent($order);
        $event['data']['object']['customer_details'] = ['email' => 'other@example.org', 'name' => 'Someone Else', 'phone' => '+15551234567'];
        $this->postWebhook($event)->assertOk();

        $meal->refresh();
        $this->assertSame('first@example.org', $meal->customer_email);
        $this->assertSame('Someone Else', $meal->customer_name, 'a placeholder name is filled in');
        $this->assertSame('+15551234567', $meal->customer_phone, 'and a blank phone');
        $this->assertSame($contactId, $gift->fresh()->contact_id, 'a linked gift keeps its contact');
        $this->assertSame(0, Contact::withoutMasjidScope()->where('email', 'other@example.org')->count());
    }

    // ------------------------------------------------------------ 3. a linked basket's row is pinned

    #[Test]
    public function a_linked_baskets_registration_is_pinned_to_the_holder_so_its_refund_flags_it(): void
    {
        [$holder, $child, $cart] = $this->linkedBasket();
        $order = $this->placeOrder($cart);
        $this->assertNotNull($order->charge_ref, 'premise: a holder\'s page');

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $row = FormResponse::query()->sole();
        $this->assertSame($child->id, (int) $row->masjid_id);
        $this->assertTrue($row->hasChargePin());
        $this->assertSame($holder->stripe_account_id, $row->charge_account_id);
        $this->assertSame($holder->id, (int) $row->charge_masjid_id, 'the organisation holding the account');
        $this->assertTrue($row->isChargedThroughAnotherOrg());

        // The holder refunds the basket's charge on ITS account. Round 2 (brief-4b-fixes-round2
        // B): a basket has ONE charge and the event names an amount, never a line, so the ORDER
        // is flagged and the registration is left to the cart, not guessed. The pin stays: it
        // still tells staff which account to refund on (FormChargeAccount::refundInstruction).
        $refund = $this->cartEvent('charge.refunded', $order, [], [
            'id' => 'ch_cart_1',
            'object' => 'charge',
            'payment_intent' => 'pi_cart_1',
            'amount_refunded' => 3000,
            'currency' => 'usd',
        ]);
        $this->postWebhook($refund)->assertOk();

        $this->assertNull($row->fresh()->charge_flag, 'a cart row is never flagged per line');
        $this->assertSame(Order::CHARGE_FLAG_REFUNDED, $order->fresh()->charge_flag);
    }

    #[Test]
    public function a_registration_paid_on_the_organisations_own_account_stays_unpinned(): void
    {
        [, $cart] = $this->basket();
        $order = $this->placeOrder($cart);

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $row = FormResponse::query()->sole();
        $this->assertFalse($row->hasChargePin());
        $this->assertNull($row->charge_masjid_id);
    }

    // ------------------------------------------------------------ 4. amount_due is what was paid

    #[Test]
    public function the_legacy_amount_due_and_entry_count_are_frozen_at_checkout(): void
    {
        [, $cart, $form] = $this->basket();
        $order = $this->placeOrder($cart);

        $snapshot = OrderItem::withoutMasjidScope()->where('order_id', $order->id)->where('buyable_type', CartItem::TYPE_FORM)->firstOrFail()->price_snapshot;
        $this->assertEquals(30.0, $snapshot['legacy_amount_due']);
        $this->assertSame(2, $snapshot['entry_count']);

        // The price is edited before the payment lands: the writer would now say $40.00.
        $settings = $form->settings;
        $settings['fee']['amount'] = 20;
        $form->forceFill(['settings' => $settings])->save();

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $row = FormResponse::query()->sole();
        $this->assertSame(3000, (int) $row->total_minor);
        $this->assertEquals(30.0, (float) $row->amount_due, 'what was paid, not the price at webhook time');
        $this->assertSame(2, (int) $row->entry_count);
    }

    // ------------------------------------------------------------ 5. adaptive pricing

    #[Test]
    public function adaptive_pricing_is_switched_off_on_the_organisations_own_page_and_a_holders(): void
    {
        [, $cart] = $this->basket();
        $own = $this->checkoutService();
        $own->checkout($cart, self::RETURN_BASE);

        [, , $linkedCart] = $this->linkedBasket();
        $linked = $this->checkoutService();
        $linked->checkout($linkedCart, self::RETURN_BASE);

        $this->assertSame(['enabled' => false], $own->created[0]['params']['adaptive_pricing']);
        $this->assertSame(['enabled' => false], $linked->created[0]['params']['adaptive_pricing']);
    }

    #[Test]
    public function a_payment_intent_in_another_currency_is_skipped_quietly_and_the_session_settles(): void
    {
        [, $cart] = $this->basket();
        $order = $this->placeOrder($cart);

        // A localised payment: the intent carries the presentment currency and amount.
        $this->postWebhook($this->intentEvent($order, ['currency' => 'cad', 'amount' => 14200]))->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(0, FormResponse::query()->count());
        // No false "did not match ... refund it" warning (nothing else here warns either).
        Log::shouldNotHaveReceived('warning');
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'different currency'))
            ->atLeast()->once();

        // The session event settles it.
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(1, Donation::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_session_event_in_another_currency_still_reports_the_refundable_mismatch(): void
    {
        [, $cart] = $this->basket();
        $order = $this->placeOrder($cart);

        $this->postWebhook($this->sessionEvent($order, ['currency' => 'cad', 'amount' => 14200]))->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertWarned('did not match the order');
    }
}
