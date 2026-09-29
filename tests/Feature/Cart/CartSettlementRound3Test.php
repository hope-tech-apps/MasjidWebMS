<?php

namespace Tests\Feature\Cart;

use App\Mail\DonationReceiptMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Donation;
use App\Models\Masjid;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Round 3 of the settlement review fixes (design/brief-4b-fixes-round3.md, from the check of
 * ba50f193). Every test here fails without its fix.
 *
 *  1. a receipt whose send failed is not left claimed, so it is not lost to every later step;
 *  2. a refund or dispute that names an order not yet paid is flagged and warned about, and one
 *     that names no order at all leaves an info line;
 *  3. a refund finds the LIVE organisation holding the account, as settlement does, when a
 *     trashed one holds the same account id.
 */
class CartSettlementRound3Test extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /** One $50 gift and nothing else, so the only mail a settlement sends is the gift's receipt. A $20 refund of it is partial. */
    private function giftBasket(?Masjid $org = null): Cart
    {
        $org ??= $this->org();
        $cart = $this->cart($org);

        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        return $cart;
    }

    private function giftLine(Order $order): OrderItem
    {
        return OrderItem::withoutMasjidScope()->where('order_id', $order->id)->where('buyable_type', CartItem::TYPE_DONATION)->sole();
    }

    private function refundEvent(Order $order, int $refunded, array $o = []): array
    {
        return $this->cartEvent('charge.refunded', $order, $o, [
            'id' => 'ch_cart_1',
            'object' => 'charge',
            'payment_intent' => 'pi_cart_1',
            'amount_refunded' => $refunded,
            'currency' => 'usd',
        ]);
    }

    private function disputeEvent(Order $order, array $o = []): array
    {
        return $this->cartEvent('charge.dispute.created', $order, $o, [
            'id' => 'dp_cart_1',
            'object' => 'dispute',
            'payment_intent' => 'pi_cart_1',
            'amount' => (int) $order->total_minor,
            'currency' => 'usd',
        ]);
    }

    /** A basket order that names the charge's payment intent but that settlement has not recorded as paid. */
    private function unpaidOrderNamingThePayment(): Order
    {
        $order = $this->placeOrder($this->giftBasket());
        Order::withoutMasjidScope()->whereKey($order->id)->update(['stripe_payment_intent_id' => 'pi_cart_1']);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'premise: settlement has not recorded it');

        return $order->fresh();
    }

    // ------------------------------------------------------------ 1. a failed send does not lose the receipt

    #[Test]
    public function a_receipt_whose_send_failed_gives_its_claim_back(): void
    {
        $order = $this->placeOrder($this->giftBasket(), null);

        // The intent knows no address: its step issues the receipt and claims nothing.
        $this->postWebhook($this->intentEvent($order))->assertOk();

        // The session event brings the payer, so its step wins the claim, links the donor and hands the
        // receipt to the controller, whose send then fails (the mailer is down).
        $mail = Mail::getFacadeRoot();
        Mail::swap(new class
        {
            public function to(mixed ...$recipients): never
            {
                throw new RuntimeException('smtp is down');
            }
        });
        $this->postWebhook($this->sessionEvent($order))->assertOk();
        Mail::swap($mail);

        $gift = Donation::withoutMasjidScope()->sole();
        $this->assertNotNull($gift->contact_id, 'premise: the step ran, won the claim and linked the donor');
        $this->assertNull($gift->receipt_delivered_at, 'premise: the send failed');
        $this->assertWarned('Receipt email failed to send');
        Mail::assertNothingSent();

        // Left claimed, every later step would get 0 rows and the receipt would never go out.
        $this->assertNull($this->giftLine($order)->receipt_claimed_at, 'the claim goes back with the failed send');
    }

    #[Test]
    public function a_receipt_that_was_sent_keeps_its_claim(): void
    {
        $order = $this->placeOrder($this->giftBasket(), null);

        $this->postWebhook($this->intentEvent($order))->assertOk();
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertNotNull(Donation::withoutMasjidScope()->sole()->receipt_delivered_at);
        Mail::assertSent(DonationReceiptMail::class, 1);

        // Delivered: the claim stays, so no later step may send it again.
        $this->assertNotNull($this->giftLine($order)->receipt_claimed_at);
    }

    // ------------------------------------------------------------ 2. a refund or dispute before settlement is not lost

    #[Test]
    public function a_refund_on_an_order_that_is_not_paid_yet_is_flagged_and_warned_about(): void
    {
        $order = $this->unpaidOrderNamingThePayment();

        $this->postWebhook($this->refundEvent($order, 2000))->assertOk();

        $flagged = $order->fresh();
        $this->assertSame(Order::CHARGE_FLAG_PARTIALLY_REFUNDED, $flagged->charge_flag);
        $this->assertSame(2000, (int) $flagged->charge_refunded_minor);
        $this->assertNotNull($flagged->charge_flagged_at);
        $this->assertSame(Order::STATUS_PENDING, $flagged->status, 'flagging an order does not settle it');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message)
                && str_contains($message, $order->order_number)
                && str_contains($message, 'before settlement recorded it'))
            ->atLeast()->once();
    }

    #[Test]
    public function a_dispute_on_an_order_that_is_not_paid_yet_is_flagged_and_warned_about(): void
    {
        $order = $this->unpaidOrderNamingThePayment();

        $this->postWebhook($this->disputeEvent($order))->assertOk();

        $flagged = $order->fresh();
        $this->assertSame(Order::CHARGE_FLAG_DISPUTED, $flagged->charge_flag);
        $this->assertNotNull($flagged->charge_flagged_at);
        $this->assertSame(Order::STATUS_PENDING, $flagged->status);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message)
                && str_contains($message, $order->order_number)
                && str_contains($message, 'before settlement recorded it'))
            ->atLeast()->once();
    }

    #[Test]
    public function a_refund_for_a_payment_no_order_carries_writes_nothing_and_leaves_an_info_line(): void
    {
        // A basket whose payment has not been recorded: no order carries any payment intent yet.
        $order = $this->placeOrder($this->giftBasket());

        $stranger = $this->refundEvent($order, 2000);
        $stranger['data']['object']['payment_intent'] = 'pi_not_a_basket';
        $this->postWebhook($stranger)->assertOk();

        $this->assertNull($order->fresh()->charge_flag);
        $this->assertSame(0, (int) $order->fresh()->charge_refunded_minor);
        $this->assertNull($order->fresh()->charge_flagged_at);

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'no cart order carries'))
            ->atLeast()->once();
    }

    // ------------------------------------------------------------ 3. the live holder before a trashed one

    #[Test]
    public function a_refund_finds_the_live_organisation_when_a_trashed_one_holds_the_same_account(): void
    {
        $account = 'acct_shared_' . uniqid();

        // An offboarded organisation keeps its account id, and comes FIRST in any unordered lookup;
        // the unique index covers live organisations only, so a live one may hold the same id.
        $gone = $this->org(['name' => 'Offboarded Masjid', 'stripe_account_id' => $account]);
        $gone->delete();
        $live = $this->org(['name' => 'Live Masjid', 'stripe_account_id' => $account]);
        $this->assertGreaterThan($gone->id, $live->id, 'premise: the trashed organisation has the lower id');

        $order = $this->placeOrder($this->giftBasket($live));
        $this->postWebhook($this->sessionEvent($order))->assertOk();
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status, 'premise: settlement resolved the live organisation');

        $this->postWebhook($this->refundEvent($order, 2000))->assertOk();

        $flagged = $order->fresh();
        $this->assertSame(Order::CHARGE_FLAG_PARTIALLY_REFUNDED, $flagged->charge_flag, 'the order belongs to the live organisation, which holds the account');
        $this->assertSame(2000, (int) $flagged->charge_refunded_minor);
    }

    #[Test]
    public function a_refund_still_finds_an_offboarded_organisation_when_no_live_one_holds_the_account(): void
    {
        $org = $this->org();
        $order = $this->placeOrder($this->giftBasket($org));
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        // Offboarded after the payment: the money is still its own, and a refund is still flagged.
        $org->delete();

        $this->postWebhook($this->refundEvent($order, 2000))->assertOk();

        $this->assertSame(Order::CHARGE_FLAG_PARTIALLY_REFUNDED, $order->fresh()->charge_flag);
    }
}
