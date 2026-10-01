<?php

namespace Tests\Feature\Shop;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Cart\CartCheckoutRefused;
use App\Services\Cart\CartPricer;
use App\Services\Cart\CartSettlementService;
use App\Services\Cart\ProductStock;
use Carbon\Carbon;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * Stock without a hold table (shop slice B1; DECISIONS.md 2026-09-30): what a basket's checkout
 * does about the units of a size, and the arithmetic under it.
 *
 *   available = stock - sold_count - held, where `held` is the quantity on the lines of PENDING
 *   orders whose page (plus the grace) has not lapsed. Unlimited stock (NULL) is always available.
 *
 * What SQLite cannot show is the locking: it serialises writers and compiles `lockForUpdate()` to
 * nothing, so two interleaved checkouts cannot be staged here. What can be pinned is the cause of
 * the anomaly the locks exist to prevent: the order of the statements (the sizes are locked ahead
 * of the checkout's first plain read, ascending by id) and the DECISION itself, which is taken
 * inside the checkout and refuses whatever the pricer saw. tests/Mysql/ShopStockMysqlTest.php runs
 * the real statements against the engine.
 */
class ShopStockTest extends TestCase
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

    private function refusal(\App\Services\Cart\CartCheckoutService $svc, $cart): CartCheckoutRefused
    {
        try {
            $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org');
        } catch (CartCheckoutRefused $r) {
            return $r;
        }

        $this->fail('checkout should have refused');
    }

    private function pay(Order $order): void
    {
        app(CartSettlementService::class)->settle((int) $order->id, 'pi_shop_' . $order->id, (int) $order->total_minor, 'usd');
    }

    // ----------------------------------------------------- two baskets, the last unit

    #[Test]
    public function the_last_unit_goes_to_the_first_basket_and_the_second_is_told_before_the_card_screen(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 1]);
        $one = $this->cart($org);
        $two = $this->cart($org);
        $this->addVariant($one, $variant, 1);
        $this->addVariant($two, $variant, 1);

        $first = $this->placeOrder($one);
        $this->assertSame(Order::STATUS_PENDING, $first->status);

        $svc = $this->checkoutService();
        $refused = $this->refusal($svc, $two);

        $this->assertSame(
            [['label' => 'School Polo (M)', 'status' => 'gone', 'reason' => 'Sold out.']],
            $refused->notices(),
            'the shopper sees it as a notice, before any card screen'
        );
        $this->assertSame(1, Order::withoutMasjidScope()->count(), 'no order was made for the second basket');
        $this->assertSame([], $svc->created, 'and no payment page was opened');
        $this->assertSame(0, (int) $variant->fresh()->sold_count, 'nothing is sold until a payment lands');
    }

    #[Test]
    public function once_the_first_basket_has_paid_the_second_is_still_refused(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 1]);
        $one = $this->cart($org);
        $two = $this->cart($org);
        $this->addVariant($one, $variant, 1);
        $this->addVariant($two, $variant, 1);

        $this->pay($this->placeOrder($one));
        $this->assertSame(1, (int) $variant->fresh()->sold_count, 'premise');

        $svc = $this->checkoutService();
        $refused = $this->refusal($svc, $two);

        $this->assertSame('Sold out.', $refused->notices()[0]['reason']);
        $this->assertSame([], $svc->created);
    }

    #[Test]
    public function the_decision_is_taken_inside_the_checkout_and_refuses_whatever_the_pricer_saw(): void
    {
        // The pricer leaves out the basket's OWN pending orders (a page about to be reused or replaced
        // must not hold units against the basket that owns it); the decision does not. Here the
        // basket has a pending order that holds the unit and opened no page, which nothing will reuse:
        // the pricer finds the line payable, and the checkout still refuses and rolls everything back.
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 1]);
        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 1);
        $this->pendingOrderHolding($org, $variant, 1, 20, $cart->id);

        $priced = (new CartPricer)->price($cart);
        $this->assertSame('available', $priced->lines[0]['outcome']->status, 'premise: the pricer sees nothing wrong');

        $svc = $this->checkoutService();
        $refused = $this->refusal($svc, $cart);

        $this->assertSame('School Polo (M): sold out.', $refused->getMessage());
        $this->assertSame([], $refused->notices(), 'a sentence, not a changed basket');
        $this->assertSame(1, Order::withoutMasjidScope()->count(), 'the order the checkout made was rolled back');
        $this->assertSame(1, OrderItem::withoutMasjidScope()->count(), 'with its lines');
        $this->assertSame([], $svc->created, 'no payment page was opened');
    }

    #[Test]
    public function a_shortfall_names_how_many_are_left(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 3]);
        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 2);
        $this->pendingOrderHolding($org, $variant, 2, 20, $cart->id);

        $refused = $this->refusal($this->checkoutService(), $cart);

        $this->assertSame('School Polo (M): only 1 left.', $refused->getMessage());
        $this->assertSame(1, Order::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_open_page_of_the_same_basket_is_handed_back_and_its_units_are_not_counted_twice(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 1]);
        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 1);

        $svc = $this->checkoutService();
        $first = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org');
        $again = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org');

        $this->assertSame($first['order']->uuid, $again['order']->uuid, 'the same page, handed back');
        $this->assertCount(1, $svc->created, 'only one page was ever opened');
        $this->assertSame(1, Order::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_changed_basket_replaces_its_own_page_and_keeps_the_units_it_held(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 1]);
        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 1);

        $svc = $this->checkoutService();
        $old = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org')['order'];

        // A gift is added: the open page is no longer this basket's, so it is closed and replaced.
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $new = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org')['order'];

        $this->assertNotSame($old->uuid, $new->uuid);
        $this->assertSame(Order::STATUS_EXPIRED, $old->fresh()->status, 'the old page was closed');
        $this->assertSame(Order::STATUS_PENDING, $new->fresh()->status);
        $this->assertSame(
            1,
            (int) OrderItem::withoutMasjidScope()->where('order_id', $new->id)->where('buyable_type', CartItem::TYPE_PRODUCT)->sum('quantity'),
            'the basket took the last unit again from its own closed page'
        );
    }

    #[Test]
    public function unlimited_stock_is_never_refused(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => null, 'sold_count' => 9999]);
        $svc = $this->checkoutService();

        foreach (range(1, 3) as $n) {
            $cart = $this->cart($org);
            $this->addVariant($cart, $variant, 20);
            $order = $svc->checkout($cart, self::RETURN_BASE, "buyer{$n}@example.org")['order'];
            $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        }

        $this->assertCount(3, $svc->created, 'sixty units, three pages, no refusal');
    }

    #[Test]
    public function two_lines_of_one_size_are_clamped_together_and_the_checkout_takes_exactly_the_stock(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 3]);
        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 2);
        $this->addVariant($cart, $variant, 2);

        $svc = $this->checkoutService();
        $refused = $this->refusal($svc, $cart);

        $this->assertCount(1, $refused->notices(), 'the second line is told how many it got');
        $this->assertSame('repriced', $refused->notices()[0]['status']);

        // "OK" to what they were shown, then pay.
        $svc->acknowledge($cart, (string) $refused->seen());
        $order = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org')['order'];

        $this->assertSame(
            3,
            (int) OrderItem::withoutMasjidScope()->where('order_id', $order->id)->sum('quantity'),
            'together the two lines never exceed the stock'
        );
    }

    #[Test]
    public function a_changed_price_is_a_notice_before_the_card_screen_and_the_new_price_is_what_is_charged(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 10]);
        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 2, 2500);

        // The owner raises the price while the basket sits there.
        $variant->product->forceFill(['base_price_minor' => 2700])->save();

        $svc = $this->checkoutService();
        $refused = $this->refusal($svc, $cart);

        $this->assertSame(
            [['label' => 'School Polo (M)', 'status' => 'repriced', 'reason' => 'The price changed while this was in your basket.']],
            $refused->notices(),
            'told BEFORE the card screen'
        );
        $this->assertSame(5400, $refused->priced()->totalMinor, 'the basket the shopper is asked to accept, at its new price');
        $this->assertSame([], $svc->created, 'no payment page was opened');
        $this->assertSame(0, Order::withoutMasjidScope()->count());

        // "OK" to what they saw, then the page opens at the new price.
        $svc->acknowledge($cart, (string) $refused->seen());
        $order = $svc->checkout($cart, self::RETURN_BASE, 'buyer@example.org')['order'];

        $this->assertSame(5400, (int) $order->total_minor);
        $this->assertSame(2700, (int) OrderItem::withoutMasjidScope()->where('order_id', $order->id)->sole()->unit_amount_minor);
    }

    // ---------------------------------------------------------------- expiry, no release

    #[Test]
    public function an_expired_pending_orders_units_are_available_again_only_after_the_grace(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');

        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 1]);
        $one = $this->cart($org);
        $two = $this->cart($org);
        $this->addVariant($one, $variant, 1);
        $this->addVariant($two, $variant, 1);

        $held = $this->placeOrder($one);
        $this->assertSame('2026-10-01 12:31:00', $held->checkout_expires_at->format('Y-m-d H:i:s'), 'premise: the page lives 31 minutes');

        // 12:45 — the page lapsed at 12:31, but a payment landing as it expired may still be on its way: held.
        Carbon::setTestNow('2026-10-01 12:45:00');
        $svc = $this->checkoutService();
        $this->assertSame('Sold out.', $this->refusal($svc, $two)->notices()[0]['reason']);

        // 12:47 — the 15 minutes of grace are over, and nothing was released: it simply stopped counting.
        Carbon::setTestNow('2026-10-01 12:47:00');
        $order = $svc->checkout($two, self::RETURN_BASE, 'two@example.org')['order'];

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, $held->fresh()->status, 'the lapsed order was not touched: there is nothing to release');
    }

    #[Test]
    public function the_grace_is_the_configured_number_of_minutes(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        config(['cart.shop_hold_grace_minutes' => 0]);

        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 1]);
        $one = $this->cart($org);
        $two = $this->cart($org);
        $this->addVariant($one, $variant, 1);
        $this->addVariant($two, $variant, 1);

        $this->placeOrder($one);   // lapses at 12:31

        Carbon::setTestNow('2026-10-01 12:30:00');
        $this->assertSame('Sold out.', $this->refusal($this->checkoutService(), $two)->notices()[0]['reason'], 'still open: held');

        Carbon::setTestNow('2026-10-01 12:32:00');
        $order = $this->checkoutService()->checkout($two, self::RETURN_BASE, 'two@example.org')['order'];
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'with no grace the hold ends with the page');
    }

    #[Test]
    public function an_order_marked_expired_stops_holding_at_once(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 1]);
        $one = $this->cart($org);
        $two = $this->cart($org);
        $this->addVariant($one, $variant, 1);
        $this->addVariant($two, $variant, 1);

        $held = $this->placeOrder($one);
        Order::withoutMasjidScope()->whereKey($held->id)->update(['status' => Order::STATUS_EXPIRED]);

        $order = $this->checkoutService()->checkout($two, self::RETURN_BASE, 'two@example.org')['order'];

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    // ----------------------------------------------------------- the arithmetic itself

    #[Test]
    public function held_counts_only_pending_unlapsed_orders_for_that_variant_in_that_organisation(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');

        $org = $this->shopOrg();
        $other = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 50]);
        $second = $this->sizeOf($org, ['stock' => 50]);

        $this->pendingOrderHolding($org, $variant, 2, 20);
        $this->pendingOrderHolding($org, $variant, 3, 20);                    // two orders add up
        $this->pendingOrderHolding($org, $second, 7, 20);                     // another size is its own count
        $lapsed = $this->pendingOrderHolding($org, $variant, 11, -10);        // lapsed 10 minutes ago: inside the grace
        $gone = $this->pendingOrderHolding($org, $variant, 13, -20);          // lapsed 20 minutes ago: past it
        $this->pendingOrderHolding($other, $variant, 17, 20);                 // another organisation's order
        $paid = $this->pendingOrderHolding($org, $variant, 19, 20);
        $paid->forceFill(['status' => Order::STATUS_PAID])->save();

        $held = ProductStock::held((int) $org->id, [(int) $variant->id, (int) $second->id]);
        ksort($held);   // a GROUP BY promises no order

        $this->assertSame([(int) $variant->id => 2 + 3 + 11, (int) $second->id => 7], $held);
        $this->assertSame([], ProductStock::held((int) $org->id, []), 'no sizes, no question');
        $this->assertSame(
            [(int) $variant->id => 2 + 3],
            ProductStock::held((int) $org->id, [(int) $variant->id], null, $lapsed->id),
            'one order can be left out: the one a checkout has just made'
        );
        $this->assertNotNull($gone);
    }

    #[Test]
    public function available_is_stock_less_sold_less_held_and_never_negative(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 10, 'sold_count' => 4]);

        $this->assertSame(6, ProductStock::available($variant, 0));
        $this->assertSame(2, ProductStock::available($variant, 4));
        $this->assertSame(0, ProductStock::available($variant, 6));
        $this->assertSame(0, ProductStock::available($variant, 60), 'never a negative number');

        $oversold = $this->sizeOf($org, ['stock' => 3, 'sold_count' => 5]);
        $this->assertSame(0, ProductStock::available($oversold, 0));

        $unlimited = $this->sizeOf($org, ['stock' => null, 'sold_count' => 5]);
        $this->assertNull(ProductStock::available($unlimited, 1000), 'NULL is unlimited');
    }

    // ----------------------------------------------------------- the statement order

    /**
     * The statements without any savepoint bookkeeping a nested transaction may log.
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function withoutSavepoints(array $statements): array
    {
        return array_values(array_filter($statements, static fn (string $sql): bool => stripos($sql, 'savepoint') === false));
    }

    /** Whether a statement is a plain `select ... from "$table"`. */
    private function selectsFrom(string $sql, string $table): bool
    {
        return (bool) preg_match('/^select\b.*\bfrom "' . preg_quote($table, '/') . '"/i', $sql);
    }

    #[Test]
    public function the_sizes_are_locked_ahead_of_the_checkouts_first_plain_read_in_ascending_order(): void
    {
        $org = $this->shopOrg();
        $product = $this->product($org);
        $large = $this->variant($product, ['label' => 'L', 'stock' => 5]);
        $small = $this->variant($product, ['label' => 'S', 'stock' => 5]);
        $this->assertGreaterThan($large->id, $small->id, 'premise: the later-added line names the higher id');

        $cart = $this->cart($org);
        // The basket names the higher id FIRST: the lock order must not follow the line order.
        $this->addVariant($cart, $small, 1);
        $this->addVariant($cart, $large, 1);

        /** @var list<string> $log statements in order, with 'BEGIN' where the checkout's transaction opens */
        $log = [];
        Event::listen(TransactionBeginning::class, function () use (&$log): void {
            $log[] = 'BEGIN';
        });
        DB::listen(function ($query) use (&$log): void {
            $log[] = $query->sql;
        });

        $this->checkoutService()->checkout($cart, self::RETURN_BASE, 'buyer@example.org');

        $begin = array_search('BEGIN', $log, true);
        $this->assertNotFalse($begin, 'premise: the checkout opened its own transaction');
        $inside = $this->withoutSavepoints(array_slice($log, $begin + 1));

        $firstSizes = null;

        foreach ($inside as $i => $sql) {
            if ($this->selectsFrom($sql, 'product_variants')) {
                $firstSizes = $i;
                break;
            }
        }

        $this->assertNotNull($firstSizes, 'the sizes are locked inside the transaction');

        // Ahead of it: the cart's own lock and the basket's lines, and nothing else. Any other plain
        // read (the organisation, the order, the held lines) would fix the snapshot first.
        $this->assertTrue($this->selectsFrom($inside[0], 'carts'), 'the cart is locked first: ' . $inside[0]);

        foreach (array_slice($inside, 0, $firstSizes) as $sql) {
            $this->assertTrue(
                $this->selectsFrom($sql, 'carts') || $this->selectsFrom($sql, 'cart_items'),
                "a plain read ran before the sizes were locked and would fix the snapshot: {$sql}"
            );
        }

        // After it, the pricer reads the organisation and the held lines, and the stock check
        // reads them again after the order exists.
        $firstOrg = null;
        $firstHeld = null;

        foreach ($inside as $i => $sql) {
            $firstOrg ??= $this->selectsFrom($sql, 'masjids') ? $i : null;
            $firstHeld ??= $this->selectsFrom($sql, 'order_items') ? $i : null;
        }

        $this->assertGreaterThan($firstSizes, $firstOrg, 'the organisation is read after the locks');
        $this->assertGreaterThan($firstSizes, $firstHeld, 'the held lines are read after the locks');

        // Ascending, whatever order the lines name them in: the lock is one statement ordered by id.
        $this->assertMatchesRegularExpression('/order by "id" asc/i', $inside[$firstSizes]);
    }

    #[Test]
    public function a_basket_with_no_product_line_takes_no_size_lock_and_reads_no_size(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        $log = [];
        DB::listen(function ($query) use (&$log): void {
            $log[] = $query->sql;
        });

        $this->checkoutService()->checkout($cart, self::RETURN_BASE, 'buyer@example.org');

        $this->assertSame([], array_values(array_filter($log, fn (string $sql): bool => $this->selectsFrom($sql, 'product_variants') || $this->selectsFrom($sql, 'products'))), 'the ordinary basket never touches the shop\'s tables');
    }

    #[Test]
    public function settlement_locks_the_sizes_after_the_order_and_in_ascending_order(): void
    {
        $org = $this->shopOrg();
        $product = $this->product($org);
        $large = $this->variant($product, ['label' => 'L', 'stock' => 5]);
        $small = $this->variant($product, ['label' => 'S', 'stock' => 5]);

        $cart = $this->cart($org);
        $this->addVariant($cart, $small, 1);
        $this->addVariant($cart, $large, 1);
        $order = $this->placeOrder($cart);

        $log = [];
        Event::listen(TransactionBeginning::class, function () use (&$log): void {
            $log[] = 'BEGIN';
        });
        DB::listen(function ($query) use (&$log): void {
            $log[] = $query->sql;
        });

        $this->pay($order);

        $begin = array_search('BEGIN', $log, true);
        $this->assertNotFalse($begin);
        $inside = $this->withoutSavepoints(array_slice($log, $begin + 1));

        $firstOrder = null;
        $firstSizes = null;

        foreach ($inside as $i => $sql) {
            $firstOrder ??= $this->selectsFrom($sql, 'orders') ? $i : null;
            $firstSizes ??= $this->selectsFrom($sql, 'product_variants') ? $i : null;
        }

        $this->assertNotNull($firstSizes);
        $this->assertGreaterThan($firstOrder, $firstSizes, 'cart, then order, then size: the one lock order');
        $this->assertMatchesRegularExpression('/order by "id" asc/i', $inside[$firstSizes], 'all the order\'s sizes in one ascending statement, ahead of any line');
    }
}
