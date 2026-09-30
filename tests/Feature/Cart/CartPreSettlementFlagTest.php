<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Donation;
use App\Models\Order;
use App\Models\StripeWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\Endpoints\CallsCartApi;
use Tests\TestCase;

/**
 * A refund or dispute that arrives BEFORE settlement is not lost (pre-merge fix B2).
 *
 * The payment intent used to be written only in the save that marks the order paid, inside the
 * settlement transaction. When settlement refused (an amount that did not match) or threw (a
 * line that could not be recorded), the order carried no intent, so a `charge.refunded` or
 * `charge.dispute.created` for that very payment found no order, was logged at info and lost:
 * Stripe does not redeliver either. The intent is now recorded as soon as an event identifies
 * the order and its page, outside and before the settlement transaction.
 */
class CartPreSettlementFlagTest extends TestCase
{
    use BuildsBaskets;
    use CallsCartApi;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /** One $50 gift, and the fund it is for. @return array{0: Order, 1: int} */
    private function giftOrder(): array
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $fund->id, 5000);

        return [$this->placeOrder($cart), (int) $fund->id];
    }

    private function refundEvent(Order $order, int $refunded): array
    {
        return $this->cartEvent('charge.refunded', $order, [], [
            'id' => 'ch_cart_1',
            'object' => 'charge',
            'payment_intent' => 'pi_cart_1',
            'amount_refunded' => $refunded,
            'currency' => 'usd',
        ]);
    }

    private function disputeEvent(Order $order): array
    {
        return $this->cartEvent('charge.dispute.created', $order, [], [
            'id' => 'dp_cart_1',
            'object' => 'dispute',
            'payment_intent' => 'pi_cart_1',
            'amount' => (int) $order->total_minor,
            'currency' => 'usd',
        ]);
    }

    private function assertWarnedBeforeSettlement(Order $order): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message)
                && str_contains($message, $order->order_number)
                && str_contains($message, 'before settlement recorded it'))
            ->atLeast()->once();
    }

    // ------------------------------------------------------------ settlement refuses

    #[Test]
    public function a_refund_after_a_refused_settlement_flags_the_pending_order(): void
    {
        [$order] = $this->giftOrder();

        // One cent short: settlement refuses, leaves the order pending and records nothing.
        $this->postWebhook($this->sessionEvent($order, ['amount' => (int) $order->total_minor - 1]))->assertOk();

        $refused = $order->fresh();
        $this->assertSame(Order::STATUS_PENDING, $refused->status, 'premise: settlement refused');
        $this->assertSame(0, Donation::withoutMasjidScope()->count(), 'premise: nothing was recorded');
        $this->assertSame('pi_cart_1', $refused->stripe_payment_intent_id, 'the payment the event named is on the order');

        $this->postWebhook($this->refundEvent($order, 5000))->assertOk();

        $flagged = $order->fresh();
        $this->assertSame(Order::CHARGE_FLAG_REFUNDED, $flagged->charge_flag);
        $this->assertSame(5000, (int) $flagged->charge_refunded_minor);
        $this->assertNotNull($flagged->charge_flagged_at);
        $this->assertSame(Order::STATUS_PENDING, $flagged->status, 'flagging is not settling');
        $this->assertWarnedBeforeSettlement($order);
    }

    #[Test]
    public function a_payment_intent_event_that_settlement_refuses_records_its_intent_too(): void
    {
        [$order] = $this->giftOrder();

        $this->postWebhook($this->intentEvent($order, ['amount' => (int) $order->total_minor + 1]))->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame('pi_cart_1', $order->fresh()->stripe_payment_intent_id);

        $this->postWebhook($this->disputeEvent($order))->assertOk();

        $this->assertSame(Order::CHARGE_FLAG_DISPUTED, $order->fresh()->charge_flag);
        $this->assertWarnedBeforeSettlement($order);
    }

    // ------------------------------------------------------------ settlement throws

    #[Test]
    public function a_refund_after_a_settlement_that_threw_flags_the_pending_order(): void
    {
        [$order, $fundId] = $this->giftOrder();

        // The fund is gone, so the gift cannot be recorded: settlement throws and rolls back.
        DB::table('funds')->where('id', $fundId)->delete();
        $event = $this->sessionEvent($order);
        $this->postWebhook($event)->assertStatus(500);

        $threw = $order->fresh();
        $this->assertSame(Order::STATUS_PENDING, $threw->status, 'premise: everything settlement did was rolled back');
        $this->assertNull($threw->paid_at);
        $this->assertNull(StripeWebhookEvent::where('stripe_event_id', $event['id'])->value('processed_at'), 'premise: left for Stripe to retry');
        $this->assertSame('pi_cart_1', $threw->stripe_payment_intent_id, 'the intent was written outside the transaction that rolled back');

        $this->postWebhook($this->refundEvent($order, 2000))->assertOk();

        $flagged = $order->fresh();
        $this->assertSame(Order::CHARGE_FLAG_PARTIALLY_REFUNDED, $flagged->charge_flag);
        $this->assertSame(2000, (int) $flagged->charge_refunded_minor);
        $this->assertSame(Order::STATUS_PENDING, $flagged->status);
        $this->assertWarnedBeforeSettlement($order);
    }

    // ------------------------------------------------------------ what recording it must not do

    #[Test]
    public function an_intent_on_the_order_is_never_replaced_by_another_event(): void
    {
        [$order] = $this->giftOrder();

        $this->postWebhook($this->sessionEvent($order, ['amount' => 1]))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['amount' => 1, 'payment_intent' => 'pi_cart_OTHER']))->assertOk();
        $this->postWebhook($this->intentEvent($order, ['amount' => 1, 'payment_intent' => 'pi_cart_OTHER_TOO']))->assertOk();

        $this->assertSame('pi_cart_1', $order->fresh()->stripe_payment_intent_id, 'first one wins');
    }

    #[Test]
    public function an_event_that_does_not_identify_the_order_records_nothing_on_it(): void
    {
        [$order] = $this->giftOrder();
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertNotNull($order->stripe_checkout_session_id, 'premise: the order recorded its page');

        // Another account, no account, and a page the order never recorded: each is refused by
        // resolve(), and none may write an intent on the order it merely named.
        $this->postWebhook($this->sessionEvent($order, ['account' => 'acct_nobody_holds_this']))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['account' => null]))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['session_id' => 'cs_forged']))->assertOk();

        $this->assertNull($order->fresh()->stripe_payment_intent_id);
    }

    #[Test]
    public function having_an_intent_does_not_make_an_order_paid(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $this->turnCartOn();
        $order = $this->placeOrder($cart);

        $this->postWebhook($this->sessionEvent($order, ['amount' => 1]))->assertOk();
        $this->postWebhook($this->refundEvent($order, 1000))->assertOk();

        $order = $order->fresh();
        $this->assertSame('pi_cart_1', $order->stripe_payment_intent_id, 'premise: an intent, a paid-looking refund flag and no payment');
        $this->assertNotNull($order->charge_flag);

        // The model, the payment-state read the return page polls, the basket and the records:
        // every one of them reads the status, and the status is pending.
        $this->assertFalse($order->isPaid());
        $this->cartApi('GET', "/api/v1/cart-orders/{$order->uuid}", $org)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(Cart::STATUS_OPEN, Cart::withoutMasjidScope()->findOrFail($order->cart_id)->status, 'the basket was not closed as paid');
        $this->assertSame(1, CartItem::withoutMasjidScope()->where('cart_id', $order->cart_id)->count(), 'and its line was not taken out');
        $this->assertSame(0, Donation::withoutMasjidScope()->count());
    }
}
