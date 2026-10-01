<?php

namespace Tests\Feature\Shop;

use App\Models\Masjid;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * Shop slice B2: the pickup list over HTTP at .../shop/sales, its collect and undo, its summary and
 * its CSV. The buyer is the ORDER's; the product, the size, the quantity and the price are the
 * sale's own snapshot; a refunded or disputed sale is never "to hand out".
 */
class ShopPickupListTest extends TestCase
{
    use BuildsBaskets;
    use BuildsShop;
    use BuildsShopAdmin;
    use RefreshDatabase;

    private Masjid $org;

    private User $admin;

    private Product $polo;

    private ProductVariant $m;

    private ProductVariant $l;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootShopAdminTest();
        Storage::fake('public');

        $this->org = $this->shopOrg();
        $this->admin = $this->adminOf($this->org);

        $this->polo = $this->product($this->org, ['name' => 'School Polo', 'slug' => 'school-polo']);
        $this->m = $this->variant($this->polo, ['label' => 'M', 'stock' => 50]);
        $this->l = $this->variant($this->polo, ['label' => 'L', 'stock' => 50, 'sort' => 1]);

        Sanctum::actingAs($this->admin);
    }

    /** @param  array<string,mixed>  $query */
    private function sales(array $query = [], string $path = ''): string
    {
        return $this->shopUrl($this->org, '/sales' . $path) . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * The rows of the list, newest paid first.
     *
     * @param  array<string,mixed>  $query
     * @return list<array<string,mixed>>
     */
    private function rows(array $query = []): array
    {
        return $this->getJson($this->sales($query))->assertOk()->json('data.data');
    }

    /** @return list<int> the sale ids of a list, in order */
    private function ids(array $query = []): array
    {
        return array_column($this->rows($query), 'id');
    }

    /**
     * @param  array<string,mixed>  $order
     * @param  array<string,mixed>  $sale
     */
    private function sale(ProductVariant $variant, array $order = [], array $sale = []): ProductSale
    {
        return $this->paidSale($this->org, $variant, $order, $sale);
    }

    // ------------------------------------------------------------ the list

    #[Test]
    public function the_list_shows_what_is_to_hand_out_newest_paid_first_with_the_buyer_read_from_the_order(): void
    {
        $older = $this->sale($this->m, ['paid_at' => now()->subHours(3), 'buyer_name' => 'Aisha Khan', 'buyer_email' => 'aisha@example.org', 'buyer_phone' => '+1 555 111 2222'], ['quantity' => 2]);
        $newer = $this->sale($this->l, ['paid_at' => now()->subHour(), 'buyer_name' => 'Bilal Ahmed']);
        $this->sale($this->m, ['paid_at' => now()->subHours(2)], ['collected_at' => now(), 'collected_by_user_id' => $this->admin->id]);
        $this->sale($this->m, ['paid_at' => now()->subHours(4), 'charge_flag' => Order::CHARGE_FLAG_REFUNDED]);

        $rows = $this->rows();

        $this->assertSame([$newer->id, $older->id], array_column($rows, 'id'), 'to hand out only, newest paid first');

        $row = $rows[1];
        $order = Order::withoutMasjidScope()->findOrFail($older->order_id);
        $this->assertSame($order->order_number, $row['order_number']);
        $this->assertSame('Aisha Khan', $row['buyer_name']);
        $this->assertSame('aisha@example.org', $row['buyer_email']);
        $this->assertSame('+1 555 111 2222', $row['buyer_phone']);
        $this->assertSame('School Polo', $row['product_name']);
        $this->assertSame('M', $row['variant_label']);
        $this->assertSame(2, $row['quantity']);
        $this->assertSame(2500, $row['unit_minor']);
        $this->assertSame(5000, $row['total_minor']);
        $this->assertSame('usd', $row['currency']);
        $this->assertSame($older->product_id, $row['product_id']);
        $this->assertSame($older->variant_id, $row['variant_id']);
        $this->assertNotNull($row['paid_at']);
        $this->assertNull($row['collected_at']);
        $this->assertNull($row['collected_by']);
        $this->assertFalse($row['oversold']);
        $this->assertFalse($row['refunded']);
        $this->assertTrue($row['to_hand_out']);

        // The sale holds NO buyer: that is read from the order, and never copied.
        foreach (['buyer_name', 'buyer_email', 'buyer_phone', 'contact_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('product_sales', $column), "product_sales.{$column} would copy the buyer");
        }
    }

    #[Test]
    public function the_states_are_to_hand_out_collected_and_all_and_a_mistyped_one_is_refused(): void
    {
        $waiting = $this->sale($this->m, ['paid_at' => now()->subHours(1)]);
        $done = $this->sale($this->m, ['paid_at' => now()->subHours(2)], ['collected_at' => now(), 'collected_by_user_id' => $this->admin->id]);
        $refunded = $this->sale($this->m, ['paid_at' => now()->subHours(3), 'charge_flag' => Order::CHARGE_FLAG_REFUNDED]);

        $this->assertSame([$waiting->id], $this->ids());
        $this->assertSame([$waiting->id], $this->ids(['state' => 'to_hand_out']));
        $this->assertSame([$done->id], $this->ids(['state' => 'collected']));
        $this->assertSame([$waiting->id, $done->id, $refunded->id], $this->ids(['state' => 'all']));

        // An empty value is "no filter", i.e. the default; a wrong one is a refusal, not "everything".
        $this->assertSame([$waiting->id], $this->ids(['state' => '']));
        $this->getJson($this->sales(['state' => 'maybe']))->assertStatus(422)->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function a_refunded_or_disputed_sale_is_never_to_hand_out_and_shows_under_all_with_its_flag(): void
    {
        $refunded = $this->sale($this->m, ['paid_at' => now()->subHours(1), 'charge_flag' => Order::CHARGE_FLAG_REFUNDED]);
        $disputed = $this->sale($this->m, ['paid_at' => now()->subHours(2), 'charge_flag' => Order::CHARGE_FLAG_DISPUTED]);
        $partial = $this->sale($this->m, ['paid_at' => now()->subHours(3), 'charge_flag' => Order::CHARGE_FLAG_PARTIALLY_REFUNDED]);

        $this->assertSame([$partial->id], $this->ids(), 'only the partly refunded one stays: a refund names an amount, never a line');

        $all = collect($this->rows(['state' => 'all']))->keyBy('id');

        $this->assertTrue($all[$refunded->id]['refunded']);
        $this->assertSame('refunded', $all[$refunded->id]['charge_flag']);
        $this->assertFalse($all[$refunded->id]['to_hand_out']);

        $this->assertTrue($all[$disputed->id]['refunded'], 'a dispute is shown as refunded: the money is not the office\'s');
        $this->assertSame('disputed', $all[$disputed->id]['charge_flag']);
        $this->assertFalse($all[$disputed->id]['to_hand_out']);

        $this->assertFalse($all[$partial->id]['refunded']);
        $this->assertSame('partially_refunded', $all[$partial->id]['charge_flag'], 'the office sees the flag and judges');
        $this->assertTrue($all[$partial->id]['to_hand_out']);
    }

    #[Test]
    public function the_list_filters_by_product_and_size_and_a_deleted_product_is_still_filterable(): void
    {
        $hoodie = $this->product($this->org, ['name' => 'Hoodie', 'slug' => 'hoodie']);
        $s = $this->variant($hoodie, ['label' => 'S']);

        $polo = $this->sale($this->m, ['paid_at' => now()->subHours(1)]);
        $poloL = $this->sale($this->l, ['paid_at' => now()->subHours(2)]);
        $hood = $this->sale($s, ['paid_at' => now()->subHours(3)]);

        $this->assertSame([$polo->id, $poloL->id], $this->ids(['product_id' => $this->polo->id]));
        $this->assertSame([$poloL->id], $this->ids(['variant_id' => $this->l->id]));
        $this->assertSame([$hood->id], $this->ids(['product_id' => $hoodie->id, 'variant_id' => $s->id]));
        $this->assertSame([], $this->ids(['product_id' => $this->polo->id, 'variant_id' => $s->id]));

        // A product removed after it sold keeps its sales and its id: still there to filter by.
        $hoodie->delete();
        $this->assertSame([$hood->id], $this->ids(['product_id' => $hoodie->id]));

        $this->getJson($this->sales(['product_id' => 'abc']))->assertStatus(422);
    }

    #[Test]
    public function search_matches_an_order_number_or_a_buyer_name_without_regard_to_case_and_treats_wildcards_literally(): void
    {
        $aisha = $this->sale($this->m, ['paid_at' => now()->subHours(1), 'order_number' => 'ZX81QW20', 'buyer_name' => 'Aisha Khan']);
        $bilal = $this->sale($this->m, ['paid_at' => now()->subHours(2), 'order_number' => 'PL55AA10', 'buyer_name' => 'Bilal Ahmed']);
        $odd = $this->sale($this->m, ['paid_at' => now()->subHours(3), 'order_number' => 'QQ00QQ00', 'buyer_name' => '100%_Fan']);
        $all = ['state' => 'all'];

        $this->assertSame([$aisha->id], $this->ids($all + ['search' => 'zx81']), 'order number, any case, a substring');
        $this->assertSame([$aisha->id], $this->ids($all + ['search' => 'AISHA']), 'buyer name, any case');
        $this->assertSame([$bilal->id], $this->ids($all + ['search' => 'al ah']), 'a substring across a space');
        $this->assertSame([], $this->ids($all + ['search' => 'nobody']));

        // % and _ are characters, not wildcards: each finds only the name that holds it.
        $this->assertSame([$odd->id], $this->ids($all + ['search' => '%']));
        $this->assertSame([$odd->id], $this->ids($all + ['search' => '_']));
        $this->assertSame([$odd->id], $this->ids($all + ['search' => '0%_f']));

        // The default state still applies: a collected sale is not found without asking for it.
        $this->sale($this->m, ['paid_at' => now(), 'buyer_name' => 'Zaynab Done'], ['collected_at' => now(), 'collected_by_user_id' => $this->admin->id]);
        $this->assertSame([], $this->ids(['search' => 'zaynab']));
        $this->assertCount(1, $this->ids(['search' => 'zaynab', 'state' => 'collected']));
    }

    #[Test]
    public function the_list_is_paginated_with_a_ceiling_on_the_page_size(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->sale($this->m, ['paid_at' => now()->subMinutes($i)]);
        }

        $first = $this->getJson($this->sales(['per_page' => 2]))->assertOk();
        $this->assertCount(2, $first->json('data.data'));
        $this->assertSame(5, $first->json('data.total'));
        $this->assertSame(3, $first->json('data.last_page'));

        $second = $this->getJson($this->sales(['per_page' => 2, 'page' => 2]))->assertOk();
        $this->assertCount(2, $second->json('data.data'));
        $this->assertNotSame(array_column($first->json('data.data'), 'id'), array_column($second->json('data.data'), 'id'));

        $this->getJson($this->sales(['per_page' => 101]))->assertStatus(422);
        $this->getJson($this->sales(['per_page' => 0]))->assertStatus(422);
    }

    #[Test]
    public function an_oversold_sale_says_so_on_its_row(): void
    {
        $sale = $this->sale($this->m, [], ['oversold' => true]);

        $row = $this->rows()[0];

        $this->assertSame($sale->id, $row['id']);
        $this->assertTrue($row['oversold']);
    }

    // ------------------------------------------------------------ the header counts

    #[Test]
    public function the_summary_counts_units_per_product_and_size_from_one_grouped_query_whatever_the_filters(): void
    {
        $hoodie = $this->product($this->org, ['name' => 'Hoodie', 'slug' => 'hoodie']);
        $s = $this->variant($hoodie, ['label' => 'S']);
        $collected = ['collected_at' => now(), 'collected_by_user_id' => $this->admin->id];

        $this->sale($this->m, [], ['quantity' => 2]);                                                                   // to hand out
        $this->sale($this->m, [], ['quantity' => 1] + $collected);                                                       // collected
        $this->sale($this->m, [], ['quantity' => 3, 'oversold' => true]);                                                // to hand out AND oversold
        $this->sale($this->m, ['charge_flag' => Order::CHARGE_FLAG_REFUNDED], ['quantity' => 1, 'oversold' => true]);    // refunded: needs no banner now
        $this->sale($this->m, ['charge_flag' => Order::CHARGE_FLAG_DISPUTED], ['quantity' => 4]);                        // disputed: not to hand out
        $this->sale($this->m, ['charge_flag' => Order::CHARGE_FLAG_PARTIALLY_REFUNDED], ['quantity' => 2]);              // partly refunded: still to hand out
        $this->sale($this->l, [], ['quantity' => 1]);
        $this->sale($s, [], ['quantity' => 1] + $collected);

        DB::enableQueryLog();
        $response = $this->getJson($this->sales())->assertOk();
        $grouped = array_filter(DB::getQueryLog(), static fn (array $q): bool => stripos($q['query'], 'group by') !== false && stripos($q['query'], 'product_sales') !== false);
        DB::disableQueryLog();

        $this->assertCount(1, $grouped, 'the header is ONE grouped query, not one per size');

        $expected = [
            ['product_id' => $this->polo->id, 'variant_id' => $this->m->id, 'product_name' => 'School Polo', 'variant_label' => 'M', 'to_hand_out' => 7, 'collected' => 1, 'oversold' => 3],
            ['product_id' => $this->polo->id, 'variant_id' => $this->l->id, 'product_name' => 'School Polo', 'variant_label' => 'L', 'to_hand_out' => 1, 'collected' => 0, 'oversold' => 0],
            ['product_id' => $hoodie->id, 'variant_id' => $s->id, 'product_name' => 'Hoodie', 'variant_label' => 'S', 'to_hand_out' => 0, 'collected' => 1, 'oversold' => 0],
        ];

        $this->assertSame($expected, $response->json('meta.summary'));

        // Filters move the list, never the header.
        $filtered = $this->getJson($this->sales(['state' => 'collected', 'search' => 'nobody', 'product_id' => $hoodie->id]))->assertOk();
        $this->assertSame($expected, $filtered->json('meta.summary'));
    }

    // ------------------------------------------------------------ collect and undo

    #[Test]
    public function collect_stamps_the_collector_is_idempotent_and_keeps_the_first_one(): void
    {
        Log::spy();
        $sale = $this->sale($this->m, ['buyer_name' => 'Aisha Khan']);
        $second = $this->adminOf($this->org);

        $response = $this->postJson($this->sales([], '/' . $sale->id . '/collect'))->assertOk();

        $this->assertSame($this->admin->id, $response->json('data.collected_by.id'));
        $this->assertSame($this->admin->name, $response->json('data.collected_by.name'));
        $this->assertNotNull($response->json('data.collected_at'));
        $this->assertFalse($response->json('data.to_hand_out'));
        $stamped = ProductSale::withoutMasjidScope()->findOrFail($sale->id);
        $this->assertSame($this->admin->id, (int) $stamped->collected_by_user_id);
        $firstAt = $stamped->collected_at->toIso8601String();

        // A colleague presses it too, at the next table: the row as it stands, the first collector kept.
        Sanctum::actingAs($second);
        $again = $this->postJson($this->sales([], '/' . $sale->id . '/collect'))->assertOk();

        $this->assertSame('Already handed out.', $again->json('message'));
        $this->assertSame($this->admin->id, $again->json('data.collected_by.id'), 'the first collector is kept');
        $kept = ProductSale::withoutMasjidScope()->findOrFail($sale->id);
        $this->assertSame($this->admin->id, (int) $kept->collected_by_user_id);
        $this->assertSame($firstAt, $kept->collected_at->toIso8601String(), 'and so is the first moment');

        // It leaves the work list and joins the collected one.
        $this->assertSame([], $this->ids());
        $this->assertSame([$sale->id], $this->ids(['state' => 'collected']));

        // The actor is logged, once (the repeat changed nothing), with ids and no buyer.
        Log::shouldHaveReceived('info')->once()->withArgs(function (...$args) use ($sale): bool {
            [$message, $context] = $args + [null, null];

            return $message === 'A shop sale was marked collected.'
                && $context === [
                    'masjid_id' => $this->org->id,
                    'sale_id' => $sale->id,
                    'order_id' => $sale->order_id,
                    'actor_user_id' => $this->admin->id,
                ];
        });
    }

    #[Test]
    public function a_refunded_or_disputed_sale_refuses_collect_with_a_sentence_and_stays_uncollected(): void
    {
        $refunded = $this->sale($this->m, ['charge_flag' => Order::CHARGE_FLAG_REFUNDED]);
        $disputed = $this->sale($this->m, ['charge_flag' => Order::CHARGE_FLAG_DISPUTED]);

        $one = $this->postJson($this->sales([], '/' . $refunded->id . '/collect'))->assertStatus(422);
        $this->assertSame('failed', $one->json('status'));
        $this->assertStringContainsString('refunded', $one->json('message'));

        $two = $this->postJson($this->sales([], '/' . $disputed->id . '/collect'))->assertStatus(422);
        $this->assertStringContainsString('disputed', $two->json('message'));

        foreach ([$refunded, $disputed] as $sale) {
            $fresh = ProductSale::withoutMasjidScope()->findOrFail($sale->id);
            $this->assertNull($fresh->collected_at);
            $this->assertNull($fresh->collected_by_user_id);
        }

        // A sale collected and refunded afterwards is not "collected" a second time, either.
        $late = $this->sale($this->m, [], ['collected_at' => now()->subHour(), 'collected_by_user_id' => $this->admin->id]);
        Order::withoutMasjidScope()->whereKey($late->order_id)->update(['charge_flag' => Order::CHARGE_FLAG_REFUNDED]);
        $this->postJson($this->sales([], '/' . $late->id . '/collect'))->assertStatus(422);
    }

    #[Test]
    public function a_partly_refunded_sale_can_still_be_collected(): void
    {
        $partial = $this->sale($this->m, ['charge_flag' => Order::CHARGE_FLAG_PARTIALLY_REFUNDED]);

        $this->postJson($this->sales([], '/' . $partial->id . '/collect'))->assertOk()->assertJsonPath('data.charge_flag', 'partially_refunded');
    }

    #[Test]
    public function undo_clears_the_mark_and_the_log_keeps_who_had_collected_it(): void
    {
        $collector = $this->adminOf($this->org);
        $sale = $this->sale($this->m, [], ['collected_at' => now()->subHour(), 'collected_by_user_id' => $collector->id]);
        Log::spy();

        $response = $this->deleteJson($this->sales([], '/' . $sale->id . '/collect'))->assertOk();

        $this->assertSame('Collected mark undone.', $response->json('message'));
        $this->assertNull($response->json('data.collected_at'));
        $this->assertNull($response->json('data.collected_by'));
        $this->assertTrue($response->json('data.to_hand_out'));

        $fresh = ProductSale::withoutMasjidScope()->findOrFail($sale->id);
        $this->assertNull($fresh->collected_at);
        $this->assertNull($fresh->collected_by_user_id);
        $this->assertSame([$sale->id], $this->ids(), 'back on the work list');

        // Nothing to undo is not an error, and writes nothing to the log.
        $this->deleteJson($this->sales([], '/' . $sale->id . '/collect'))->assertOk()->assertJsonPath('message', 'This sale was not marked collected.');

        Log::shouldHaveReceived('info')->once()->withArgs(function (...$args) use ($sale, $collector): bool {
            [$message, $context] = $args + [null, null];

            return $message === 'A shop sale\'s collected mark was undone.'
                && is_array($context)
                && $context['sale_id'] === $sale->id
                && $context['actor_user_id'] === $this->admin->id
                && $context['was_collected_by_user_id'] === $collector->id
                && is_string($context['was_collected_at']);
        });
    }

    #[Test]
    public function undo_is_allowed_on_a_refunded_sale_and_collect_and_undo_round_trip(): void
    {
        $refunded = $this->sale($this->m, ['charge_flag' => Order::CHARGE_FLAG_REFUNDED], ['collected_at' => now(), 'collected_by_user_id' => $this->admin->id]);

        $this->deleteJson($this->sales([], '/' . $refunded->id . '/collect'))->assertOk()->assertJsonPath('data.collected_at', null);

        $normal = $this->sale($this->m);
        $this->postJson($this->sales([], '/' . $normal->id . '/collect'))->assertOk();
        $this->deleteJson($this->sales([], '/' . $normal->id . '/collect'))->assertOk();
        $this->postJson($this->sales([], '/' . $normal->id . '/collect'))->assertOk()->assertJsonPath('message', 'Handed out.');
    }

    // ------------------------------------------------------------ a deleted product

    #[Test]
    public function a_deleted_product_s_sales_still_list_under_the_names_they_were_sold_as(): void
    {
        $sale = $this->sale($this->m);

        // Renamed, then deleted, through the API: the pickup list does not follow either.
        $this->putProduct($this->org, $this->polo->id, ['name' => 'Navy Polo'])->assertOk();
        $this->deleteJson($this->shopUrl($this->org, '/products/' . $this->polo->id))->assertOk();

        $rows = $this->rows();

        $this->assertSame([$sale->id], array_column($rows, 'id'));
        $this->assertSame('School Polo', $rows[0]['product_name'], 'the snapshot, not the catalogue');
        $this->assertSame('M', $rows[0]['variant_label']);
        $this->assertSame(2500, $rows[0]['total_minor']);

        // And it can still be handed out.
        $this->postJson($this->sales([], '/' . $sale->id . '/collect'))->assertOk();
    }

    // ------------------------------------------------------------ the CSV

    #[Test]
    public function the_csv_has_the_lists_columns_and_filters_and_neutralises_a_formula_in_a_buyer_name(): void
    {
        $evil = $this->sale($this->m, ['paid_at' => now()->subHours(1), 'buyer_name' => "=cmd|' /C calc'!A0", 'buyer_email' => '+evil@example.org'], ['quantity' => 2, 'oversold' => true]);
        $plain = $this->sale($this->l, ['paid_at' => now()->subHours(2), 'buyer_name' => 'Aisha Khan']);
        $refunded = $this->sale($this->m, ['paid_at' => now()->subHours(3), 'charge_flag' => Order::CHARGE_FLAG_REFUNDED]);
        $collected = $this->sale($this->m, ['paid_at' => now()->subHours(4), 'buyer_name' => '@SUM(1+1)'], ['collected_at' => now(), 'collected_by_user_id' => $this->admin->id]);
        $partial = $this->sale($this->m, ['paid_at' => now()->subHours(5), 'charge_flag' => Order::CHARGE_FLAG_PARTIALLY_REFUNDED]);

        $response = $this->get($this->sales(['state' => 'all'], '.csv'))->assertOk();

        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename="shop-sales-.+-\d{4}-\d{2}-\d{2}\.csv"$/', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), 'names, e-mails and phones are never cached');

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'a BOM, so Excel reads Arabic names as UTF-8');

        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', substr($csv, 3)))));
        $table = array_map('str_getcsv', $lines);

        $this->assertSame([
            'Sale', 'Order number', 'Paid at', 'Buyer name', 'Buyer email', 'Buyer phone',
            'Product', 'Size', 'Quantity', 'Total', 'Currency',
            'Collected at', 'Collected by', 'Oversold', 'Refunded', 'Charge flag',
        ], $table[0]);
        $this->assertCount(6, $table, 'a header and the five sales: state=all');

        $byId = [];
        foreach (array_slice($table, 1) as $cells) {
            $byId[(int) $cells[0]] = $cells;
        }

        // The formula is text: a leading apostrophe, the standard neutralisation, on every cell a person typed.
        $this->assertSame("'=cmd|' /C calc'!A0", $byId[$evil->id][3]);
        $this->assertSame("'+evil@example.org", $byId[$evil->id][4]);
        $this->assertSame("'+1 555 010 0100", $byId[$evil->id][5], 'a phone number starts with a plus, which is a formula trigger too');
        $this->assertSame("'@SUM(1+1)", $byId[$collected->id][3]);
        $this->assertSame('Aisha Khan', $byId[$plain->id][3], 'and a plain name is left alone');

        // The columns of the list.
        $this->assertSame('School Polo', $byId[$evil->id][6]);
        $this->assertSame('M', $byId[$evil->id][7]);
        $this->assertSame('2', $byId[$evil->id][8]);
        $this->assertSame('50.00', $byId[$evil->id][9], 'integer cents as a plain decimal');
        $this->assertSame('USD', $byId[$evil->id][10]);
        $this->assertSame('yes', $byId[$evil->id][13]);
        $this->assertSame('no', $byId[$plain->id][13]);
        $this->assertSame('yes', $byId[$refunded->id][14], 'as the list says it: refunded');
        $this->assertSame('refunded', $byId[$refunded->id][15], 'and the order\'s own word beside it');
        $this->assertSame('no', $byId[$plain->id][14]);
        $this->assertSame('', $byId[$plain->id][15]);
        $this->assertSame('no', $byId[$partial->id][14], 'a partly refunded sale is still to hand out, so the list and the file agree it is not refunded');
        $this->assertSame('partially_refunded', $byId[$partial->id][15]);
        $this->assertSame($this->admin->name, $byId[$collected->id][12]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2} [A-Z]{2,5}$/', $byId[$evil->id][2], 'the organisation\'s own clock, with the zone written out');
        $this->assertSame('', $byId[$plain->id][11], 'not collected: no time');

        // The same filters as the list.
        $onlyCollected = array_map('str_getcsv', array_values(array_filter(explode("\n", str_replace("\r", '', substr($this->get($this->sales(['state' => 'collected'], '.csv'))->streamedContent(), 3))))));
        $this->assertCount(2, $onlyCollected);
        $this->assertSame((string) $collected->id, $onlyCollected[1][0]);

        $defaults = array_map('str_getcsv', array_values(array_filter(explode("\n", str_replace("\r", '', substr($this->get($this->sales([], '.csv'))->streamedContent(), 3))))));
        $this->assertSame([(string) $evil->id, (string) $plain->id, (string) $partial->id], [$defaults[1][0], $defaults[2][0], $defaults[3][0]], 'to hand out by default, in the order they were recorded');
        $this->assertCount(4, $defaults);

        $this->get($this->sales(['state' => 'maybe'], '.csv'), ['Accept' => 'application/json'])->assertStatus(422);
    }

    #[Test]
    public function the_csv_walks_past_one_chunk_without_dropping_or_repeating_a_sale(): void
    {
        // The export reads 500 sales at a time by `product_sales.id`, over a join that has an `id` of
        // its own in two other tables. One more than a chunk proves the cursor.
        $expected = [];
        for ($i = 0; $i < 501; $i++) {
            $expected[] = $this->sale($this->m)->id;
        }

        $csv = $this->get($this->sales(['state' => 'all'], '.csv'))->assertOk()->streamedContent();
        $table = array_map('str_getcsv', array_values(array_filter(explode("\n", str_replace("\r", '', substr($csv, 3))))));

        $ids = array_map(static fn (array $cells): int => (int) $cells[0], array_slice($table, 1));

        $this->assertCount(501, $ids);
        $this->assertSame($expected, $ids, 'every sale once, in the order recorded');
    }

    // ------------------------------------------------------------ another organisation

    #[Test]
    public function another_organisations_sales_are_never_listed_summarised_exported_or_collected(): void
    {
        $other = $this->shopOrg();
        $theirs = $this->paidSale($other, $this->sizeOf($other, ['label' => 'M']), ['buyer_name' => 'Their Buyer']);
        $mine = $this->sale($this->m, ['buyer_name' => 'My Buyer']);

        $list = $this->getJson($this->sales(['state' => 'all']))->assertOk();
        $this->assertSame([$mine->id], array_column($list->json('data.data'), 'id'));
        $this->assertSame([$this->m->id], array_column($list->json('meta.summary'), 'variant_id'), 'the header counts only this organisation');

        $csv = $this->get($this->sales(['state' => 'all'], '.csv'))->streamedContent();
        $this->assertStringContainsString('My Buyer', $csv);
        $this->assertStringNotContainsString('Their Buyer', $csv);

        $this->postJson($this->sales([], '/' . $theirs->id . '/collect'))->assertNotFound();
        $this->deleteJson($this->sales([], '/' . $theirs->id . '/collect'))->assertNotFound();
        $this->assertNull(ProductSale::withoutMasjidScope()->findOrFail($theirs->id)->collected_at);

        // Their product's id, as a filter, matches nothing of theirs.
        $this->assertSame([], $this->ids(['state' => 'all', 'product_id' => $theirs->product_id]));
    }
}
