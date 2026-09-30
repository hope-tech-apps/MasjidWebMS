<?php

namespace Tests\Feature\Cart;

use App\Models\CartItem;
use App\Models\Order;
use App\Services\Cart\CartSettlementService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Settlement takes its locks before it reads anything else (pre-merge fix B7).
 *
 * Under MySQL's default REPEATABLE READ the first plain SELECT of a transaction fixes the
 * snapshot every later plain read sees. settleLocked() used to open with a plain SELECT of the
 * order, ahead of the cart and order `FOR UPDATE` locks, so a settlement that waited behind
 * another (the payment intent's and the session's events arrive together) went on to read the
 * order's lines and the basket's lines as they were BEFORE the wait: a backfill found no linked
 * lines and did nothing, and a line added while it waited was invisible to closeCart().
 *
 * SQLite serialises writers and compiles `lockForUpdate()` to nothing, so it cannot show the
 * anomaly, and nothing here pretends to. What it CAN pin is the statement order that removes the
 * cause: the order's basket id is read before the transaction begins, and the first statement
 * inside it is the cart's locking read, ahead of any read of the order.
 */
class CartSettlementLockOrderTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /** Whether a statement is a plain `select ... from "$table"`. */
    private function selectsFrom(string $sql, string $table): bool
    {
        return (bool) preg_match('/^select\b.*\bfrom "' . preg_quote($table, '/') . '"/i', $sql);
    }

    #[Test]
    public function the_order_is_not_read_inside_the_transaction_before_the_cart_is_locked(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $order = $this->placeOrder($cart);

        /** @var list<string> $log statements in order, with 'BEGIN' where the settlement's transaction opens */
        $log = [];
        Event::listen(TransactionBeginning::class, function () use (&$log): void {
            $log[] = 'BEGIN';
        });
        DB::listen(function ($query) use (&$log): void {
            $log[] = $query->sql;
        });

        $result = app(CartSettlementService::class)->settle(
            (int) $order->id,
            'pi_cart_1',
            (int) $order->total_minor,
            'usd',
        );

        $this->assertTrue($result->settled, 'premise: the settlement ran to the end');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        $begin = array_search('BEGIN', $log, true);
        $this->assertNotFalse($begin, 'premise: settlement opened its own transaction');

        // The basket id is read first, outside the transaction.
        $before = array_slice($log, 0, $begin);
        $this->assertNotSame([], array_filter($before, fn (string $sql): bool => $this->selectsFrom($sql, 'orders')), 'the order\'s basket id is read before the transaction opens');

        // Inside it, the first statement is the cart's locking read, and no plain read of the
        // order (or of its lines, or of the basket's lines) comes ahead of it.
        $inside = array_values(array_slice($log, $begin + 1));
        $firstCart = null;

        foreach ($inside as $i => $sql) {
            if ($this->selectsFrom($sql, 'carts')) {
                $firstCart = $i;
                break;
            }
        }

        $this->assertNotNull($firstCart, 'the cart is locked inside the transaction');

        foreach (array_slice($inside, 0, $firstCart) as $sql) {
            $this->assertFalse(
                $this->selectsFrom($sql, 'orders') || $this->selectsFrom($sql, 'order_items') || $this->selectsFrom($sql, 'cart_items'),
                "a plain read ran before the locks were taken and would fix the snapshot: {$sql}"
            );
        }

        // ...and the order is locked (read) only after the cart, the order checkout takes them in.
        $firstOrder = null;

        foreach ($inside as $i => $sql) {
            if ($this->selectsFrom($sql, 'orders')) {
                $firstOrder = $i;
                break;
            }
        }

        $this->assertNotNull($firstOrder);
        $this->assertGreaterThan($firstCart, $firstOrder, 'cart first, then order');
    }

    #[Test]
    public function an_order_whose_basket_is_gone_still_settles_without_a_cart_lock(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $order = $this->placeOrder($cart);

        // The basket was pruned: orders.cart_id is nullOnDelete.
        $cart->delete();
        $this->assertNull($order->fresh()->cart_id, 'premise');

        $result = app(CartSettlementService::class)->settle((int) $order->id, 'pi_cart_1', (int) $order->total_minor, 'usd');

        $this->assertTrue($result->settled);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }
}
