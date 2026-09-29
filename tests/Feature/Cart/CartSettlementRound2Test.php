<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\FormResponse;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Cart\CartCheckoutService;
use App\Services\Cart\CartSettlementService;
use App\Services\Crm\DonorContactService;
use App\Services\Forms\FormResponseWriter;
use App\Services\Lunch\LunchOrderMailer;
use App\Services\Lunch\MealOrderCreator;
use App\Services\Receipts\ReceiptService;
use App\Services\Stripe\DonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\StripeClient;
use Tests\TestCase;
use Throwable;

/**
 * Round 2 of the settlement review fixes (design/brief-4b-fixes-round2.md, from the check of
 * bc4771df). Every test here fails without its fix.
 *
 *  A. the donor link and receipt delivery of a gift are claimed atomically, so the two success
 *     events that arrive together mail one receipt;
 *  B. a refund or dispute on a BASKET's charge flags the ORDER (never a line), the cart's
 *     registrations are left out of the form arm, and the flag records the latest amount;
 *  C. settling an order removes only the lines it paid for (matched by type, id, payload and
 *     quantity), closes the basket only when nothing is left, and expires the basket's other
 *     pending Stripe pages.
 */
class CartSettlementRound2Test extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /** One ticket ($15), a dish ($12) and a $50 gift: the lines a refund cannot be split across. */
    private function basket(): Cart
    {
        $org = $this->org();
        $cart = $this->cart($org);

        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 1, $this->oneTicket('A'));
        $this->add($cart, CartItem::TYPE_MEAL, $this->dish($org)->id, 1200, 1);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        return $cart;
    }

    /** A guest's gift and dish: nobody is known until Stripe's session details arrive. */
    private function guestBasket(): Cart
    {
        $org = $this->org();
        $cart = $this->cart($org);

        $this->add($cart, CartItem::TYPE_MEAL, $this->dish($org)->id, 1200, 1);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        return $cart;
    }

    private function oneTicket(string $name): array
    {
        return ['tickets' => [['attendeeName' => $name]]];
    }

    /** A settlement service that HOLDS the steps it would run after the commit, so a test can run them itself. */
    private function holdingSettlement(): CartSettlementService
    {
        $svc = new class(
            app(FormResponseWriter::class),
            app(MealOrderCreator::class),
            app(DonationService::class),
            app(ReceiptService::class),
            app(DonorContactService::class),
            app(LunchOrderMailer::class),
        ) extends CartSettlementService {
            /** @var list<\Closure> */
            public array $held = [];

            protected function afterCommit(int $orderId, array $steps): array
            {
                array_push($this->held, ...$steps);

                return [];
            }
        };
        $this->app->instance(CartSettlementService::class, $svc);

        return $svc;
    }

    /** A checkout whose Stripe seams answer locally, remember every page they were asked to expire, and can be made to refuse. */
    private function recordingCheckout(?Throwable $expireFails = null): CartCheckoutService
    {
        $svc = new class(new StripeClient('sk_test_offline'), $expireFails) extends CartCheckoutService {
            /** @var list<string> */
            public array $expired = [];

            public int $opened = 0;

            public function __construct(StripeClient $stripe, private readonly ?Throwable $expireFails)
            {
                parent::__construct($stripe);
            }

            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                $this->opened++;

                return [
                    'id' => 'cs_round2_' . $this->opened,
                    'url' => 'https://checkout.stripe.test/' . $this->opened,
                    'payment_intent' => null,
                ];
            }

            protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
            {
                return ['status' => 'open', 'url' => 'https://checkout.stripe.test/existing'];
            }

            protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
            {
                $this->expired[] = $sessionId;

                if ($this->expireFails !== null) {
                    throw $this->expireFails;
                }
            }
        };
        $this->app->instance(CartCheckoutService::class, $svc);

        return $svc;
    }

    private function charge(Order $order, int $refunded, array $o = []): array
    {
        return $this->cartEvent('charge.refunded', $order, $o, [
            'id' => 'ch_cart_1',
            'object' => 'charge',
            'payment_intent' => 'pi_cart_1',
            'amount_refunded' => $refunded,
            'currency' => 'usd',
        ]);
    }

    private function dispute(Order $order, array $o = []): array
    {
        return $this->cartEvent('charge.dispute.created', $order, $o, [
            'id' => 'dp_cart_1',
            'object' => 'dispute',
            'payment_intent' => 'pi_cart_1',
            'amount' => (int) $order->total_minor,
            'currency' => 'usd',
        ]);
    }

    // ------------------------------------------------------------ A. one receipt, however the events fall

    #[Test]
    public function two_success_events_queued_together_link_the_donor_and_deliver_one_receipt(): void
    {
        $svc = $this->holdingSettlement();
        $order = $this->placeOrder($this->guestBasket(), 'buyer@example.org');

        // The intent settles; its step has NOT run yet, so the session event that follows
        // reads the gift with no contact and queues a second donor-and-receipt step.
        $this->postWebhook($this->intentEvent($order))->assertOk();
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertNull(Donation::withoutMasjidScope()->sole()->contact_id, 'premise: neither step has run');

        $delivered = [];
        foreach ($svc->held as $step) {
            $out = $step();

            if (is_array($out)) {
                $delivered[] = $out;
            }
        }

        $this->assertCount(1, $delivered, 'only the step that claimed the line hands a receipt to be mailed');
        $this->assertSame(1, Contact::withoutMasjidScope()->where('email', 'buyer@example.org')->count());
        $this->assertNotNull(Donation::withoutMasjidScope()->sole()->contact_id);
        $this->assertNotNull(
            OrderItem::withoutMasjidScope()->where('order_id', $order->id)->where('buyable_type', CartItem::TYPE_DONATION)->sole()->receipt_claimed_at
        );
    }

    #[Test]
    public function a_step_with_nobody_to_deliver_to_leaves_the_claim_for_the_session_events_step(): void
    {
        $svc = $this->holdingSettlement();
        $order = $this->placeOrder($this->guestBasket(), null);

        $this->postWebhook($this->intentEvent($order))->assertOk();
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        // [intent: dish confirmation, gift step] then [session: dish confirmation, gift step].
        $this->assertCount(4, $svc->held);
        $line = fn (): OrderItem => OrderItem::withoutMasjidScope()->where('order_id', $order->id)->where('buyable_type', CartItem::TYPE_DONATION)->sole();

        // The intent knew no address: it issues the receipt, delivers nothing and claims nothing.
        $this->assertNull(($svc->held[1])(), 'no contact and no address: nothing to deliver');
        $this->assertNull($line()->receipt_claimed_at);

        // The session's step has the payer, and gets the claim.
        $out = ($svc->held[3])();
        $this->assertIsArray($out);
        $this->assertNotNull($line()->receipt_claimed_at);
        $this->assertNotNull(Donation::withoutMasjidScope()->sole()->contact_id);
    }

    // ------------------------------------------------------------ B. a basket's charge flags the ORDER

    #[Test]
    public function a_partial_refund_flags_the_order_names_it_for_staff_and_touches_no_line(): void
    {
        $order = $this->placeOrder($this->basket());
        $this->postWebhook($this->sessionEvent($order))->assertOk();
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        // Only the gift's $50 of the $77 basket is refunded in Stripe: the event says how much, never which line.
        $this->postWebhook($this->charge($order, 5000))->assertOk();

        $flagged = $order->fresh();
        $this->assertSame(Order::CHARGE_FLAG_PARTIALLY_REFUNDED, $flagged->charge_flag);
        $this->assertSame(5000, (int) $flagged->charge_refunded_minor);
        $this->assertNotNull($flagged->charge_flagged_at);

        $this->assertNull(FormResponse::query()->sole()->charge_flag, 'no registration is flagged from a basket-wide amount');
        $this->assertSame('succeeded', Donation::withoutMasjidScope()->sole()->status, 'no line is changed');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message)
                && str_contains($message, $order->order_number)
                && str_contains($message, 'cannot be attributed automatically'))
            ->atLeast()->once();
    }

    #[Test]
    public function a_repeated_or_later_refund_records_the_latest_amount_and_never_adds(): void
    {
        $order = $this->placeOrder($this->basket());
        $this->postWebhook($this->sessionEvent($order))->assertOk();
        $total = (int) $order->total_minor;

        $this->postWebhook($this->charge($order, 2000))->assertOk();
        $this->postWebhook($this->charge($order, 2000))->assertOk(); // a redelivery under another event id
        $this->assertSame(2000, (int) $order->fresh()->charge_refunded_minor, 'a replay adds nothing');
        $this->assertSame(Order::CHARGE_FLAG_PARTIALLY_REFUNDED, $order->fresh()->charge_flag);

        $this->postWebhook($this->charge($order, $total))->assertOk();
        $this->assertSame($total, (int) $order->fresh()->charge_refunded_minor, 'the latest figure, not 2000 + total');
        $this->assertSame(Order::CHARGE_FLAG_REFUNDED, $order->fresh()->charge_flag);

        // A late event carrying an older, smaller figure moves nothing back.
        $this->postWebhook($this->charge($order, 2000))->assertOk();
        $this->assertSame($total, (int) $order->fresh()->charge_refunded_minor);
        $this->assertSame(Order::CHARGE_FLAG_REFUNDED, $order->fresh()->charge_flag);
    }

    #[Test]
    public function a_dispute_flags_the_order_and_a_later_refund_does_not_downgrade_it(): void
    {
        $order = $this->placeOrder($this->basket());
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->postWebhook($this->dispute($order))->assertOk();
        $this->assertSame(Order::CHARGE_FLAG_DISPUTED, $order->fresh()->charge_flag);
        $this->assertNotNull($order->fresh()->charge_flagged_at);

        $this->postWebhook($this->charge($order, 1500))->assertOk();
        $this->assertSame(Order::CHARGE_FLAG_DISPUTED, $order->fresh()->charge_flag, 'the dispute keeps the flag');
        $this->assertSame(1500, (int) $order->fresh()->charge_refunded_minor, 'the refund is still recorded');
    }

    #[Test]
    public function a_holders_refund_of_a_linked_basket_with_two_registrations_flags_the_order_and_neither_row(): void
    {
        $holder = $this->org(['stripe_account_id' => 'acct_holder_' . uniqid()]);
        $child = $this->org(['name' => 'Burlington Islamic Sunday School', 'stripe_account_id' => null, 'stripe_charges_enabled' => false]);
        DB::table('masjids')->where('id', $child->id)->update(['parent_id' => $holder->id, 'forms_card_via_masjid_id' => $holder->id]);
        $child->refresh();

        // Two children registered in one basket: two rows, one charge, one payment intent.
        $form = $this->ticketForm($child);
        $cart = $this->cart($child);
        $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 1, $this->oneTicket('First Child'));
        $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 1, $this->oneTicket('Second Child'));

        $order = $this->placeOrder($cart);
        $this->assertNotNull($order->charge_ref, 'premise: a holder\'s page');
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $rows = FormResponse::query()->get();
        $this->assertCount(2, $rows);
        $this->assertSame([true, true], $rows->map->hasChargePin()->all(), 'premise: both rows are pinned, and share the payment intent');
        $this->assertCount(1, $rows->pluck('stripe_payment_intent_id')->unique());

        $this->postWebhook($this->charge($order, 1500))->assertOk();

        $flagged = $order->fresh();
        $this->assertSame(Order::CHARGE_FLAG_PARTIALLY_REFUNDED, $flagged->charge_flag);
        $this->assertSame(1500, (int) $flagged->charge_refunded_minor);

        foreach (FormResponse::query()->get() as $row) {
            $this->assertNull($row->charge_flag, 'the cart owns these rows: none is flagged, and none is guessed');
            $this->assertSame(0, (int) $row->charge_refunded_minor);
        }

        // And the holder's dispute is the same.
        $this->postWebhook($this->dispute($order))->assertOk();
        $this->assertSame(Order::CHARGE_FLAG_DISPUTED, $order->fresh()->charge_flag);
        $this->assertNull(FormResponse::query()->whereNotNull('charge_flag')->first());
    }

    #[Test]
    public function a_refund_on_another_account_or_for_no_basket_flags_nothing(): void
    {
        $order = $this->placeOrder($this->basket());
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        // The payment intent is the basket's, but the event is raised on some other account.
        $this->postWebhook($this->charge($order, 5000, ['account' => 'acct_someone_else']))->assertOk();
        $this->assertNull($order->fresh()->charge_flag);
        $this->assertSame(0, (int) $order->fresh()->charge_refunded_minor);

        // A charge that is no basket's (a donation's refund, say) is acked as it always was.
        $stranger = $this->charge($order, 5000);
        $stranger['data']['object']['payment_intent'] = 'pi_not_a_basket';
        $this->postWebhook($stranger)->assertOk();
        $this->assertNull($order->fresh()->charge_flag);
    }

    // ------------------------------------------------------------ C. only what was paid leaves the basket

    #[Test]
    public function an_order_paid_late_removes_only_its_own_lines_and_expires_the_other_page(): void
    {
        $org = $this->org();
        $form = $this->ticketForm($org);
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 1, $this->oneTicket('A'));

        $svc = $this->recordingCheckout();

        // Page A (the ticket) is about to lapse; the shopper adds a gift and opens page B (both).
        $a = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org')['order']->fresh();
        Order::withoutMasjidScope()->whereKey($a->id)->update(['checkout_expires_at' => now()->subMinute()]);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $b = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org')['order']->fresh();

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(Order::STATUS_EXPIRED, $a->fresh()->status, 'premise: A was expired locally without asking Stripe');
        $this->assertSame(Order::STATUS_PENDING, $b->status);
        $this->assertSame([], $svc->expired);

        // ...and A's payment lands after all.
        $this->postWebhook($this->sessionEvent($a))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $a->fresh()->status);
        $this->assertSame(1, FormResponse::query()->count());
        $this->assertSame(0, Donation::withoutMasjidScope()->count(), 'the gift was never paid for');

        // The gift was never paid for: it stays, and the basket stays open with it.
        $left = CartItem::withoutMasjidScope()->where('cart_id', $cart->id)->get();
        $this->assertCount(1, $left);
        $this->assertSame(CartItem::TYPE_DONATION, $left->first()->buyable_type);
        $this->assertSame(Cart::STATUS_OPEN, Cart::withoutMasjidScope()->findOrFail($cart->id)->status);

        // Page B holds the ticket that is already paid for: it must not be payable.
        $this->assertSame([$b->stripe_checkout_session_id], $svc->expired);
        $this->assertSame(Order::STATUS_EXPIRED, $b->fresh()->status);
    }

    #[Test]
    public function a_paid_basket_with_nothing_left_is_closed_and_its_other_page_is_expired(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 1, $this->oneTicket('A'));

        $svc = $this->recordingCheckout();

        // Page A lapses and the shopper opens page B for the very same lines; A's payment then lands.
        $a = $svc->checkout($cart, self::RETURN_BASE)['order']->fresh();
        Order::withoutMasjidScope()->whereKey($a->id)->update(['checkout_expires_at' => now()->subMinute()]);
        $b = $svc->checkout($cart, self::RETURN_BASE)['order']->fresh();
        $this->assertNotSame($a->id, $b->id);

        $this->postWebhook($this->sessionEvent($a))->assertOk();

        $this->assertSame(Cart::STATUS_CHECKED_OUT, Cart::withoutMasjidScope()->findOrFail($cart->id)->status);
        $this->assertSame(0, CartItem::withoutMasjidScope()->where('cart_id', $cart->id)->count());
        $this->assertSame([$b->stripe_checkout_session_id], $svc->expired, 'paying B would charge the ticket a second time');
        $this->assertSame(Order::STATUS_EXPIRED, $b->fresh()->status);
    }

    #[Test]
    public function a_line_is_matched_by_its_payload_not_just_its_form(): void
    {
        $org = $this->org();
        $form = $this->ticketForm($org);
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 1, $this->oneTicket('First Child'));

        $svc = $this->recordingCheckout();
        $a = $svc->checkout($cart, self::RETURN_BASE)['order']->fresh();
        Order::withoutMasjidScope()->whereKey($a->id)->update(['checkout_expires_at' => now()->subMinute()]);

        // Another registration on the SAME form, for someone else, added after page A opened.
        $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 1, $this->oneTicket('Second Child'));
        $svc->checkout($cart, self::RETURN_BASE);

        $this->postWebhook($this->sessionEvent($a))->assertOk();

        $left = CartItem::withoutMasjidScope()->where('cart_id', $cart->id)->get();
        $this->assertCount(1, $left, 'the first child was paid for; the second was not');
        $this->assertSame($this->oneTicket('Second Child'), $left->first()->payload);
        $this->assertSame(Cart::STATUS_OPEN, Cart::withoutMasjidScope()->findOrFail($cart->id)->status);
    }

    #[Test]
    public function a_line_the_shopper_re_quantified_is_not_what_the_earlier_page_paid_for(): void
    {
        // ASSUMPTIONS #36: page A is paid for TWO after the shopper acknowledged THREE of the same
        // line and opened page B. The payload hash cannot tell the two apart (a dish's quantity is
        // not in its payload), so the quantity has to be part of the match.
        $org = $this->org();
        $cart = $this->cart($org);
        $line = $this->add($cart, CartItem::TYPE_MEAL, $this->dish($org)->id, 1200, 2);

        $svc = $this->recordingCheckout();

        // Page A is opened for two and is about to lapse.
        $a = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org')['order']->fresh();
        Order::withoutMasjidScope()->whereKey($a->id)->update(['checkout_expires_at' => now()->subMinute()]);

        // The shopper acknowledges a change to three (CartCheckoutService::acknowledge() writes the
        // line's quantity in place, exactly so) and opens page B for the three.
        CartItem::withoutMasjidScope()->whereKey($line->id)->update(['quantity' => 3]);
        $b = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org')['order']->fresh();

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2400, (int) $a->total_minor, 'premise: A was priced for two dishes');
        $this->assertSame(3600, (int) $b->total_minor, 'premise: B was priced for three');
        $this->assertSame(Order::STATUS_EXPIRED, $a->fresh()->status, 'premise: A was expired locally without asking Stripe');
        $this->assertSame([], $svc->expired);

        // ...and A's payment lands after all.
        $this->postWebhook($this->sessionEvent($a))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $a->fresh()->status);

        // Two dishes were paid for; the line now asks for three, so it is NOT what A paid for and stays.
        $left = CartItem::withoutMasjidScope()->where('cart_id', $cart->id)->get();
        $this->assertCount(1, $left, 'the basket still holds the line: what it asks for is not what A paid for');
        $this->assertSame((int) $line->id, (int) $left->first()->id);
        $this->assertSame(3, (int) $left->first()->quantity);
        $this->assertSame(Cart::STATUS_OPEN, Cart::withoutMasjidScope()->findOrFail($cart->id)->status);

        // Page B, the one that can still be paid, is expired as it always was.
        $this->assertSame([$b->stripe_checkout_session_id], $svc->expired);
        $this->assertSame(Order::STATUS_EXPIRED, $b->fresh()->status);
    }

    #[Test]
    public function stripe_refusing_to_close_the_other_page_is_logged_and_never_fails_the_webhook(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 1, $this->oneTicket('A'));

        $svc = $this->recordingCheckout(new RuntimeException('Stripe is down'));
        $a = $svc->checkout($cart, self::RETURN_BASE)['order']->fresh();
        Order::withoutMasjidScope()->whereKey($a->id)->update(['checkout_expires_at' => now()->subMinute()]);
        $b = $svc->checkout($cart, self::RETURN_BASE)['order']->fresh();

        // Settlement has committed by then, so the answer is 200 and the money is recorded.
        $this->postWebhook($this->sessionEvent($a))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $a->fresh()->status);
        $this->assertSame(1, FormResponse::query()->count());
        $this->assertSame([$b->stripe_checkout_session_id], $svc->expired, 'it was asked');
        $this->assertSame(Order::STATUS_PENDING, $b->fresh()->status, 'and could not be closed');
        $this->assertWarned('could not be closed');
    }
}
