<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every string the cart writes fits the column it is written to (pre-merge fix B1).
 *
 * The suite runs SQLite, which ignores the length in `varchar(16)`; production is MySQL in
 * strict mode, which refuses an over-long value with error 1406. `orders.charge_flag` was
 * declared 16 and 'partially_refunded' is 18 characters: every partial refund of a basket
 * failed to record on production and no SQLite test could say so. So the widths are read out
 * of the two cart migrations themselves, and compared with the longest value the code can write.
 *
 * Adding a string column to either migration fails `every_declared_string_column_has_a_recorded_maximum`
 * until its maximum is written down here, which is when the question should be asked.
 */
class CartColumnWidthsTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    private const MIGRATIONS = [
        '2026_09_27_090000_create_carts_table.php',
        '2026_09_28_090000_create_orders_table.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /**
     * `table.column => declared width`, from `string()`, `char()` and `uuid()` calls in the
     * migrations' `up()`. `string('x')` with no length is Laravel's 255.
     *
     * @return array<string,int>
     */
    private function declared(): array
    {
        $widths = [];

        foreach (self::MIGRATIONS as $file) {
            $source = (string) file_get_contents(database_path('migrations/' . $file));
            // [preamble, table, body, table, body, ...]
            $parts = preg_split("/Schema::create\\('(\\w+)'/", $source, -1, PREG_SPLIT_DELIM_CAPTURE);

            for ($i = 1; $i < count($parts); $i += 2) {
                $table = $parts[$i];
                $body = $parts[$i + 1];

                preg_match_all('/\$table->(?:string|char)\(\s*\'(\w+)\'\s*(?:,\s*(\d+))?\s*\)/', $body, $columns, PREG_SET_ORDER);

                foreach ($columns as $column) {
                    $widths["{$table}.{$column[1]}"] = isset($column[2]) && $column[2] !== '' ? (int) $column[2] : 255;
                }

                preg_match_all('/\$table->uuid\(\s*\'(\w+)\'\s*\)/', $body, $uuids, PREG_SET_ORDER);

                foreach ($uuids as $uuid) {
                    $widths["{$table}.{$uuid[1]}"] = 36;
                }
            }
        }

        return $widths;
    }

    /** The longest of a set of fixed vocabulary words. */
    private function longest(array $words): int
    {
        return max(array_map(static fn (string $word): int => strlen($word), $words));
    }

    /**
     * `table.column => the most characters the code can write there`, and where that comes from.
     *
     * @return array<string,int>
     */
    private function written(): array
    {
        $currency = strlen((string) config('services.stripe.currency', 'usd'));
        $recordedAs = [CartItem::RECORDED_AS_DONATION, CartItem::RECORDED_AS_REGISTRATION, CartItem::RECORDED_AS_ORDER_ONLY];
        $recordTypes = [OrderItem::RECORD_FORM_RESPONSE, OrderItem::RECORD_MEAL_ORDER, OrderItem::RECORD_DONATION];
        $charged = [Order::CHARGE_FLAG_REFUNDED, Order::CHARGE_FLAG_PARTIALLY_REFUNDED, Order::CHARGE_FLAG_DISPUTED];

        return [
            // The status constants and the other fixed vocabularies.
            'carts.status' => $this->longest([Cart::STATUS_OPEN, Cart::STATUS_CHECKED_OUT]),
            'cart_items.buyable_type' => $this->longest(CartItem::TYPES),
            'cart_items.recorded_as' => $this->longest($recordedAs),
            'orders.status' => $this->longest([Order::STATUS_PENDING, Order::STATUS_PAID, Order::STATUS_EXPIRED]),
            'orders.charge_flag' => $this->longest($charged),
            'order_items.buyable_type' => $this->longest(CartItem::TYPES),
            'order_items.recorded_as' => $this->longest($recordedAs),
            'order_items.record_type' => $this->longest($recordTypes),

            // ISO currency, from config('services.stripe.currency').
            'cart_items.currency' => $currency,
            'orders.currency' => $currency,
            'order_items.currency' => $currency,

            // Digests and generated keys, by construction.
            'carts.token_hash' => 64,                      // HMAC-SHA256, hex (Cart::hashToken)
            'cart_items.client_line_hash' => 64,           // keyed SHA-256, hex
            'orders.basket_fingerprint' => 64,             // SHA-256, hex (PricedBasket)
            'order_items.cart_payload_hash' => 64,         // SHA-256, hex (PricedBasket::payloadHash)
            'orders.uuid' => 36,                           // Str::uuid()
            'orders.order_number' => 8,                    // the first 8 characters of the uuid (CartCheckoutService)
            'orders.idempotency_key' => strlen('cart_order_') + 36,
            'orders.charge_ref' => strlen('cref_') + 32,   // 'cref_' . Str::random(32)

            // Bounded by the door's own validation (CartsController) or by a truncation on write.
            'cart_items.client_line_key' => 64,            // regex ^[A-Za-z0-9_-]{8,64}$
            'orders.buyer_email' => 190,                   // 'max:190' on buyer.email
            'orders.buyer_name' => 120,                    // usableText(..., BUYER_NAME_MAX)
            'orders.buyer_phone' => 32,                    // usableText(..., BUYER_PHONE_MAX)
            'cart_items.label' => 255,                     // mb_substr(..., 0, 255) in CartLineAdder
            'order_items.label' => 255,                    // a form, fund or dish name: each a string(255)

            // Stripe ids. 255 is the width of the columns they are copied from
            // (masjids.stripe_account_id) and of the ids Stripe documents.
            'orders.charge_account_id' => 255,
            'orders.stripe_checkout_session_id' => 255,
            'orders.stripe_payment_intent_id' => 255,
        ];
    }

    #[Test]
    public function every_value_the_code_writes_fits_its_column(): void
    {
        $declared = $this->declared();
        $written = $this->written();
        $tooLong = [];

        foreach ($written as $column => $max) {
            $this->assertArrayHasKey($column, $declared, "{$column} is not a string column of the cart migrations any more; update the map.");

            if ($max > $declared[$column]) {
                $tooLong[] = "{$column}: the code writes up to {$max} characters, the column holds {$declared[$column]}";
            }
        }

        $this->assertSame([], $tooLong, 'MySQL in strict mode refuses these (error 1406); SQLite does not, so only this test can see it.');
    }

    #[Test]
    public function every_declared_string_column_has_a_recorded_maximum(): void
    {
        $unrecorded = array_values(array_diff(array_keys($this->declared()), array_keys($this->written())));

        $this->assertSame([], $unrecorded, 'A new string column needs the most it can hold written down in written(), so its width is compared with it.');
    }

    #[Test]
    public function partially_refunded_fits_the_charge_flag_column(): void
    {
        // The defect this file exists for.
        $this->assertSame(18, strlen(Order::CHARGE_FLAG_PARTIALLY_REFUNDED), 'premise: it is longer than the 16 it was declared with');
        $this->assertGreaterThanOrEqual(18, $this->declared()['orders.charge_flag']);
    }

    #[Test]
    public function the_rows_a_real_basket_leaves_all_fit_their_columns(): void
    {
        // A basket of all three kinds, paid, then partly refunded: every table and every
        // vocabulary the flow writes, through the code that writes them.
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 2, $this->twoTickets());
        $this->add($cart, CartItem::TYPE_MEAL, $this->dish($org)->id, 1200, 2);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        $order = $this->placeOrder($cart);
        $this->postWebhook($this->sessionEvent($order))->assertOk();
        $this->postWebhook($this->cartEvent('charge.refunded', $order, [], [
            'id' => 'ch_cart_1',
            'object' => 'charge',
            'payment_intent' => 'pi_cart_1',
            'amount_refunded' => 2000,
            'currency' => 'usd',
        ]))->assertOk();

        $this->assertSame(Order::CHARGE_FLAG_PARTIALLY_REFUNDED, $order->fresh()->charge_flag, 'premise: the partial refund was recorded');

        $declared = $this->declared();
        $checked = 0;

        foreach (['carts', 'cart_items', 'orders', 'order_items'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                foreach ((array) $row as $column => $value) {
                    if (! is_string($value) || ! isset($declared["{$table}.{$column}"])) {
                        continue;
                    }

                    $checked++;
                    $this->assertLessThanOrEqual(
                        $declared["{$table}.{$column}"],
                        mb_strlen($value),
                        "{$table}.{$column} holds a value longer than its column ({$declared["{$table}.{$column}"]})."
                    );
                }
            }
        }

        $this->assertGreaterThan(40, $checked, 'premise: the rows were read, so the loop above checked something');
    }
}
