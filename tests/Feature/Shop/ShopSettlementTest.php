<?php

namespace Tests\Feature\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Donation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSale;
use App\Models\ProductVariant;
use App\Services\Cart\CartSettlementResult;
use App\Services\Cart\CartSettlementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * Settling a paid shop line (shop slice B1; CartSettlementService::settleProduct).
 *
 * The sale is written from what checkout FROZE, the size's `sold_count` moves under its lock, and
 * the money is never refused: a line that is paid after the last unit has gone (only a webhook
 * later than the hold's grace can do that) is recorded, flagged `oversold`, and ERRORs on the
 * `monitors` channel so ops hear of it. Every order here is made by CartCheckoutService itself, so
 * what is settled is the snapshot checkout really wrote.
 */
class ShopSettlementTest extends TestCase
{
    use BuildsBaskets;
    use BuildsShop;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Settle an order directly, as the signed webhook does. */
    private function pay(Order $order, string $paymentIntent = 'pi_cart_1'): CartSettlementResult
    {
        return app(CartSettlementService::class)->settle((int) $order->id, $paymentIntent, (int) $order->total_minor, 'usd');
    }

    private function line(Order $order): OrderItem
    {
        return OrderItem::withoutMasjidScope()->where('order_id', $order->id)->where('buyable_type', CartItem::TYPE_PRODUCT)->firstOrFail();
    }

    /** A basket of `$quantity` of a fresh size, its order placed (still pending) and the size. */
    private function placed(int $quantity = 2, array $variant = [], array $product = []): array
    {
        $org = $this->shopOrg();
        $size = $this->sizeOf($org, $variant, $product);
        $cart = $this->cart($org);
        $this->addVariant($cart, $size, $quantity);

        return [$org, $size, $cart, $this->placeOrder($cart)];
    }

    // ------------------------------------------------------------- what checkout freezes

    #[Test]
    public function checkout_freezes_what_the_sale_is_written_from_and_writes_no_sale_yet(): void
    {
        [, $size, , $order] = $this->placed(2, ['label' => 'YM', 'stock' => 10]);

        $line = $this->line($order);

        $this->assertSame(CartItem::TYPE_PRODUCT, $line->buyable_type);
        $this->assertSame(CartItem::RECORDED_AS_SALE, $line->recorded_as);
        $this->assertSame('School Polo (YM)', $line->label);
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame(2500, (int) $line->unit_amount_minor);
        $this->assertSame(5000, (int) $line->total_minor);
        $this->assertNull($line->payload, 'nothing personal is kept on a shop line');
        $this->assertSame([
            'product_id' => (int) $size->product_id,
            'variant_id' => (int) $size->id,
            'product_name' => 'School Polo',
            'variant_label' => 'YM',
            'unit_minor' => 2500,
            'quantity' => 2,
            'total_minor' => 5000,
        ], $line->price_snapshot);
        $this->assertNull($line->record_id, 'records exist only once paid');
        $this->assertSame(0, ProductSale::withoutMasjidScope()->count());
        $this->assertSame(0, (int) $size->fresh()->sold_count, 'a pending order holds units; it does not sell them');
    }

    // -------------------------------------------------------------------- the sale

    #[Test]
    public function a_paid_line_raises_sold_count_and_writes_the_sale_from_its_snapshot(): void
    {
        [$org, $size, $cart, $order] = $this->placed(2, ['label' => 'YM', 'stock' => 10, 'sold_count' => 3]);

        $result = $this->pay($order);

        $this->assertTrue($result->settled);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(5, (int) $size->fresh()->sold_count, '3 + 2');

        $sale = ProductSale::withoutMasjidScope()->sole();
        $this->assertSame((int) $org->id, (int) $sale->masjid_id);
        $this->assertSame((int) $order->id, (int) $sale->order_id);
        $this->assertSame((int) $this->line($order)->id, (int) $sale->order_item_id);
        $this->assertSame((int) $size->product_id, (int) $sale->product_id);
        $this->assertSame((int) $size->id, (int) $sale->variant_id);
        $this->assertSame('School Polo', $sale->product_name);
        $this->assertSame('YM', $sale->variant_label);
        $this->assertSame(2, (int) $sale->quantity);
        $this->assertSame(2500, (int) $sale->unit_minor);
        $this->assertSame(5000, (int) $sale->total_minor);
        $this->assertFalse((bool) $sale->oversold);
        $this->assertNull($sale->collected_at);
        $this->assertNull($sale->collected_by_user_id);

        // The line points at it, as every other line points at its record.
        $line = $this->line($order);
        $this->assertSame(OrderItem::RECORD_PRODUCT_SALE, $line->record_type);
        $this->assertSame('product_sale', $line->record_type);
        $this->assertSame((int) $sale->id, (int) $line->record_id);

        // And what the order paid for has left the basket, which is closed.
        $this->assertSame(0, CartItem::withoutMasjidScope()->where('cart_id', $cart->id)->count());
        $this->assertSame(Cart::STATUS_CHECKED_OUT, $cart->fresh()->status);
    }

    #[Test]
    public function the_sale_is_what_was_paid_for_not_what_the_catalogue_says_now(): void
    {
        [, $size, , $order] = $this->placed(2, ['label' => 'M']);

        // After the page opened and before the payment landed, the owner renamed, repriced and relabelled.
        $size->product->forceFill(['name' => 'Renamed Polo', 'base_price_minor' => 9900])->save();
        $size->forceFill(['label' => 'Medium', 'price_minor' => 9900])->save();

        $this->pay($order);

        $sale = ProductSale::withoutMasjidScope()->sole();
        $this->assertSame('School Polo', $sale->product_name);
        $this->assertSame('M', $sale->variant_label);
        $this->assertSame(2500, (int) $sale->unit_minor);
        $this->assertSame(5000, (int) $sale->total_minor);
    }

    #[Test]
    public function a_replay_writes_nothing_twice(): void
    {
        [, $size, , $order] = $this->placed(2, ['stock' => 10]);
        $event = $this->sessionEvent($order);

        $this->postWebhook($event)->assertOk();
        $this->assertSame(2, (int) $size->fresh()->sold_count, 'premise');
        $paidAt = $order->fresh()->paid_at;

        // The very same delivery, a new delivery of the same payment, the other success event, an
        // async one and a direct second settlement: none writes or counts anything again.
        $this->postWebhook($event)->assertOk()->assertJsonPath('message', 'Duplicate event ignored.');
        $this->postWebhook($this->sessionEvent($order))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['type' => 'checkout.session.async_payment_succeeded']))->assertOk();
        $this->postWebhook($this->intentEvent($order))->assertOk();
        $this->pay($order);

        $this->assertSame(1, ProductSale::withoutMasjidScope()->count());
        $this->assertSame(2, (int) $size->fresh()->sold_count, 'sold_count moved once');
        $this->assertTrue($paidAt->equalTo($order->fresh()->paid_at));
    }

    #[Test]
    public function a_sale_that_already_names_the_line_is_linked_and_counts_nothing_a_second_time(): void
    {
        // The line has a sale (unique per line) but, somehow, no link: settlement relinks it and does not
        // count the units again.
        [, $size, , $order] = $this->placed(2, ['stock' => 10]);
        $line = $this->line($order);

        $sale = ProductSale::withoutMasjidScope()->create([
            'masjid_id' => $order->masjid_id, 'order_id' => $order->id, 'order_item_id' => $line->id,
            'product_id' => $size->product_id, 'variant_id' => $size->id, 'product_name' => 'School Polo',
            'variant_label' => 'M', 'quantity' => 2, 'unit_minor' => 2500, 'total_minor' => 5000,
        ]);

        $this->pay($order);

        $this->assertSame(1, ProductSale::withoutMasjidScope()->count());
        $this->assertSame((int) $sale->id, (int) $this->line($order)->record_id);
        $this->assertSame(0, (int) $size->fresh()->sold_count, 'it was counted when that sale was written, not now');
    }

    #[Test]
    public function a_mixed_basket_settles_the_product_beside_a_gift(): void
    {
        $org = $this->shopOrg();
        $size = $this->sizeOf($org, ['stock' => 5]);
        $cart = $this->cart($org);
        $this->addVariant($cart, $size, 1);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $order = $this->placeOrder($cart);

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(1, ProductSale::withoutMasjidScope()->count());
        $this->assertSame(1, Donation::withoutMasjidScope()->count());
        $this->assertSame(1, (int) $size->fresh()->sold_count);
        $this->assertSame(
            [OrderItem::RECORD_DONATION, OrderItem::RECORD_PRODUCT_SALE],
            OrderItem::withoutMasjidScope()->where('order_id', $order->id)->orderBy('record_type')->pluck('record_type')->all()
        );
    }

    #[Test]
    public function unlimited_stock_counts_what_is_sold_and_is_never_oversold(): void
    {
        [, $size, , $order] = $this->placed(20, ['stock' => null, 'sold_count' => 500]);

        $this->pay($order);

        $this->assertSame(520, (int) $size->fresh()->sold_count);
        $this->assertFalse((bool) ProductSale::withoutMasjidScope()->sole()->oversold);
    }

    // ------------------------------------------------------------------- oversold

    #[Test]
    public function an_oversold_paid_line_is_recorded_flagged_and_logs_an_error_on_monitors(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');

        $org = $this->shopOrg();
        $size = $this->sizeOf($org, ['stock' => 1]);
        $one = $this->cart($org);
        $two = $this->cart($org);
        $this->addVariant($one, $size, 1);
        $this->addVariant($two, $size, 1);

        // The first shopper opens a page (the last unit is held until 12:46) and goes quiet.
        $late = $this->placeOrder($one);

        // At 12:50 the hold has lapsed, so the second shopper is allowed the same unit, and pays.
        Carbon::setTestNow('2026-10-01 12:50:00');
        $timely = $this->placeOrder($two);
        $this->pay($timely, 'pi_cart_timely');

        $this->assertSame(1, (int) $size->fresh()->sold_count, 'premise: the last unit is sold');
        $this->assertFalse((bool) ProductSale::withoutMasjidScope()->sole()->oversold, 'premise: the timely sale is whole');

        // Now the first shopper's payment, delayed past the grace, arrives.
        $channel = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('monitors')->andReturn($channel);

        $result = $this->pay($late, 'pi_cart_late');

        $this->assertTrue($result->settled, 'the money is taken, so it is settled');
        $this->assertSame(Order::STATUS_PAID, $late->fresh()->status);
        $this->assertSame(2, (int) $size->fresh()->sold_count, 'sold_count goes over the stock: 2 of 1');

        $sale = ProductSale::withoutMasjidScope()->where('order_id', $late->id)->sole();
        $this->assertTrue((bool) $sale->oversold, 'flagged');
        $this->assertSame(1, (int) $sale->quantity);
        $this->assertSame(2500, (int) $sale->total_minor);
        $this->assertSame((int) $sale->id, (int) $this->line($late)->record_id, 'and linked: a record, not a refusal');

        // NEVER auto-refunded: nothing on the order says a refund happened.
        $this->assertNull($late->fresh()->charge_flag);
        $this->assertSame(0, (int) $late->fresh()->charge_refunded_minor);

        // An ERROR on the monitors channel, naming the order number and the size, with no buyer detail.
        $channel->shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($late, $size): bool {
            $text = $message . ' ' . json_encode($context);

            return str_contains($message, 'past its stock')
                && $context['order_number'] === $late->order_number
                && (int) $context['variant_id'] === (int) $size->id
                && $context['item'] === 'School Polo (M)'
                && ! str_contains($text, '@')
                && ! str_contains($text, 'Amal')
                && ! array_key_exists('buyer_email', $context)
                && ! array_key_exists('buyer_name', $context)
                && ! array_key_exists('buyer_phone', $context);
        });
    }

    #[Test]
    public function the_alert_is_raised_after_the_commit_and_never_for_a_sale_that_was_rolled_back(): void
    {
        // A basket whose second line cannot be recorded rolls everything back, the oversold sale
        // included: ops must not be told of a sale that does not exist.
        Carbon::setTestNow('2026-10-01 12:00:00');

        $org = $this->shopOrg();
        $size = $this->sizeOf($org, ['stock' => 1, 'sold_count' => 1]);   // already sold out: this payment oversells
        $cart = $this->cart($org);
        $this->addVariant($cart, $size, 1);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        // sold_count 1 of 1 would make the line gone at pricing, so make room to place the order, then take it.
        $size->forceFill(['sold_count' => 0])->save();
        $order = $this->placeOrder($cart);
        $size->forceFill(['sold_count' => 1])->save();

        // The gift's fund is removed after checkout: a gift whose fund is gone fails settlement loudly.
        \App\Models\Fund::withoutMasjidScope()->where('masjid_id', $org->id)->delete();

        $channel = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('monitors')->andReturn($channel);

        try {
            $this->pay($order);
            $this->fail('settlement should have thrown for the gift whose fund is gone');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('fund no longer exists', $e->getMessage());
        }

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'everything was rolled back');
        $this->assertSame(0, ProductSale::withoutMasjidScope()->count());
        $this->assertSame(1, (int) $size->fresh()->sold_count, 'and the count did not move');
        $channel->shouldNotHaveReceived('error');
    }

    // --------------------------------------------------------------- withdrawn since

    #[Test]
    public function a_size_or_product_withdrawn_since_checkout_is_still_settled(): void
    {
        foreach (['trashed size', 'trashed product', 'disabled size', 'product off the shelf'] as $case) {
            [, $size, , $order] = $this->placed(2, ['stock' => 10]);

            match ($case) {
                'trashed size' => $size->delete(),
                'trashed product' => $size->product->delete(),
                'disabled size' => $size->forceFill(['enabled' => false])->save(),
                'product off the shelf' => $size->product->forceFill(['active' => false])->save(),
            };

            $this->pay($order);

            $sale = ProductSale::withoutMasjidScope()->where('order_id', $order->id)->sole();
            $this->assertSame(2, (int) $sale->quantity, $case);
            $this->assertSame(5000, (int) $sale->total_minor, $case);
            $this->assertSame(
                2,
                (int) ProductVariant::withoutMasjidScope()->withTrashed()->find($size->id)->sold_count,
                "{$case}: the size is read withTrashed and its count still moves"
            );
        }
    }

    #[Test]
    public function a_size_removed_outright_is_recorded_from_its_snapshot_with_a_warning(): void
    {
        [, $size, , $order] = $this->placed(2, ['stock' => 10]);

        ProductVariant::withTrashed()->whereKey($size->id)->forceDelete();
        $this->assertNull(ProductVariant::withTrashed()->find($size->id), 'premise: nothing left to move stock on');

        $result = $this->pay($order);

        $this->assertTrue($result->settled, 'money taken is a record, never a retry loop');
        $sale = ProductSale::withoutMasjidScope()->sole();
        $this->assertSame('School Polo', $sale->product_name);
        $this->assertSame(5000, (int) $sale->total_minor);
        $this->assertFalse((bool) $sale->oversold);
        $this->assertWarned('a product size that no longer exists');
    }

    #[Test]
    public function a_withdrawn_size_is_logged_so_staff_can_see_the_sale_was_recorded_regardless(): void
    {
        [, $size, , $order] = $this->placed(1, ['stock' => 10]);
        $size->delete();

        $this->pay($order);

        $this->assertWarned('withdrawn since checkout');
    }

    // -------------------------------------------------------- the snapshot is checked

    #[Test]
    public function a_line_with_no_snapshot_fails_loudly_and_records_nothing(): void
    {
        [, $size, , $order] = $this->placed(2, ['stock' => 10]);
        OrderItem::withoutMasjidScope()->whereKey($this->line($order)->id)->update(['price_snapshot' => null]);

        try {
            $this->pay($order);
            $this->fail('a paid line that cannot be recorded must fail so the webhook is retried');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('no price snapshot', $e->getMessage());
        }

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'everything rolled back, so the retry starts clean');
        $this->assertSame(0, ProductSale::withoutMasjidScope()->count());
        $this->assertSame(0, (int) $size->fresh()->sold_count);
    }

    #[Test]
    public function a_snapshot_that_is_not_what_the_line_charged_fails_loudly(): void
    {
        [, $size, , $order] = $this->placed(2, ['stock' => 10]);
        $line = $this->line($order);
        $snapshot = $line->price_snapshot;
        $snapshot['total_minor'] = 4000;
        OrderItem::withoutMasjidScope()->whereKey($line->id)->update(['price_snapshot' => json_encode($snapshot)]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not what the line charged');

        try {
            $this->pay($order);
        } finally {
            $this->assertSame(0, ProductSale::withoutMasjidScope()->count());
            $this->assertSame(0, (int) $size->fresh()->sold_count);
        }
    }

    // -------------------------------------------------------- refunds stay order level

    #[Test]
    public function a_refund_or_dispute_flags_the_order_and_never_a_sale_or_the_stock(): void
    {
        [, $size, , $order] = $this->placed(2, ['stock' => 10]);
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $sale = ProductSale::withoutMasjidScope()->sole();
        $count = (int) $size->fresh()->sold_count;

        $this->postWebhook($this->cartEvent('charge.refunded', $order, [], [
            'id' => 'ch_cart_1', 'object' => 'charge', 'payment_intent' => 'pi_cart_1', 'amount_refunded' => 2500, 'currency' => 'usd',
        ]))->assertOk();

        $this->assertSame(Order::CHARGE_FLAG_PARTIALLY_REFUNDED, $order->fresh()->charge_flag);
        $this->assertSame(2500, (int) $order->fresh()->charge_refunded_minor);

        $this->postWebhook($this->cartEvent('charge.dispute.created', $order, [], [
            'id' => 'dp_cart_1', 'object' => 'dispute', 'payment_intent' => 'pi_cart_1', 'amount' => (int) $order->total_minor, 'currency' => 'usd',
        ]))->assertOk();

        $this->assertSame(Order::CHARGE_FLAG_DISPUTED, $order->fresh()->charge_flag);

        // The sale and the stock are exactly as settlement left them: there is no product arm.
        $this->assertSame(1, ProductSale::withoutMasjidScope()->count());
        $fresh = ProductSale::withoutMasjidScope()->sole();
        $this->assertSame((int) $sale->quantity, (int) $fresh->quantity);
        $this->assertSame((int) $sale->total_minor, (int) $fresh->total_minor);
        $this->assertFalse((bool) $fresh->oversold);
        $this->assertSame($count, (int) $size->fresh()->sold_count, 'a refund does not restock: a person decides that');
    }
}
