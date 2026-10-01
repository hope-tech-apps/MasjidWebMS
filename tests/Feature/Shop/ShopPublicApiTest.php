<?php

namespace Tests\Feature\Shop;

use App\Http\Middleware\EnsureShopEnabled;
use App\Models\Masjid;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Service;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * Shop slice B2: the renderer's read of the shop, GET /api/v1/shop/products and /{slug}.
 *
 * Three promises, each with its own tests: DARK (the grant off, the basket off, no such organisation:
 * the 404 of a route that was never built, byte for byte, before any limiter), NO STOCK NUMBERS (only
 * `sold_out`, never a count), and CHEAP (one grouped query for what is held, however many sizes).
 */
class ShopPublicApiTest extends TestCase
{
    use BuildsBaskets;
    use BuildsShop;
    use BuildsShopAdmin;
    use RefreshDatabase;

    /** The keys a listed product carries, and a size, and nothing else. */
    private const PRODUCT_KEYS = ['name', 'slug', 'category', 'description', 'price_minor', 'price_varies', 'currency', 'images', 'variants'];

    private const VARIANT_KEYS = ['id', 'label', 'price_minor', 'sold_out'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootShopAdminTest();
        Storage::fake('public');

        // The shop's reads need the basket on as well as the grant; the default is OFF.
        config(['cart.enabled' => true, 'cart.masjid_ids' => []]);
    }

    /** @return array<string,string> */
    private function headers(?Masjid $org): array
    {
        return $org === null ? [] : ['masjid-id' => (string) $org->id];
    }

    /** @return array<string,mixed> the listing's `data` */
    private function listing(Masjid $org): array
    {
        return $this->getJson('/api/v1/shop/products', $this->headers($org))->assertOk()->json('data');
    }

    /**
     * @param  array<string,mixed>  $variants  label => overrides
     */
    private function listed(Masjid $org, string $name, array $variants, array $product = []): Product
    {
        $row = $this->product($org, array_merge(['name' => $name, 'slug' => Str::slug($name)], $product));

        foreach ($variants as $label => $overrides) {
            $this->variant($row, array_merge(['label' => (string) $label], $overrides));
        }

        return $row;
    }

    // ------------------------------------------------------------ dark

    #[Test]
    public function with_the_grant_off_the_shop_routes_are_the_404_of_a_route_that_does_not_exist(): void
    {
        config(['app.debug' => false]);

        $org = $this->org();
        $this->assertFalse($org->hasCapability('shop'), 'premise: off is the default');
        $this->listed($org, 'School Polo', ['M' => []]);

        $unknown = $this->getJson('/api/v1/no-such-route', $this->headers($org))->assertNotFound()->getContent();

        foreach (['/api/v1/shop/products', '/api/v1/shop/products/school-polo', '/api/v1/shop/products/made-up'] as $uri) {
            $response = $this->getJson($uri, $this->headers($org))->assertNotFound();

            $this->assertSame($unknown, $response->getContent(), "{$uri} is not the same 404 as a route that does not exist");
            $response->assertHeaderMissing('X-RateLimit-Limit');
            $response->assertHeaderMissing('Retry-After');
        }

        // With debug on the router's message names the path; the gate's sentence is the same one.
        config(['app.debug' => true]);
        $unknown = $this->getJson('/api/v1/no-such-route', $this->headers($org))->assertNotFound();
        $dark = $this->getJson('/api/v1/shop/products', $this->headers($org))->assertNotFound();

        $this->assertSame(str_replace('no-such-route', 'shop/products', (string) $unknown->json('message')), $dark->json('message'));
    }

    #[Test]
    public function every_reason_the_shop_is_dark_is_that_same_404(): void
    {
        config(['app.debug' => false]);

        $unknown = $this->getJson('/api/v1/no-such-route')->assertNotFound()->getContent();

        $granted = $this->shopOrg();
        $this->listed($granted, 'School Polo', ['M' => []]);

        // The grant switched off by a SuperAdmin, on an organisation that has a catalogue.
        $revoked = $this->shopOrg();
        $revoked->forceFill(['capability_overrides' => ['shop' => false]])->save();
        $this->listed($revoked, 'School Polo', ['M' => []]);

        // An organisation offboarded since it built a shop.
        $gone = $this->shopOrg();
        $this->listed($gone, 'School Polo', ['M' => []]);
        $gone->delete();

        $other = $this->shopOrg();

        // Control: with everything on, the same organisation answers.
        $this->assertCount(1, $this->listing($granted));

        /** @var array<string, array{0: array<string,mixed>, 1: array<string,string>}> $scenarios config to set, headers to send */
        $scenarios = [
            'the basket is off' => [['cart.enabled' => false], $this->headers($granted)],
            'the basket is off for this organisation' => [['cart.masjid_ids' => [$other->id]], $this->headers($granted)],
            'the shop grant is off' => [[], $this->headers($revoked)],
            'there is no masjid-id header' => [[], []],
            'the organisation does not exist' => [[], ['masjid-id' => '987654']],
            'the organisation was offboarded' => [[], $this->headers($gone)],
            'the header is not a number' => [[], ['masjid-id' => 'abc']],
        ];

        foreach ($scenarios as $why => [$config, $headers]) {
            config(['cart.enabled' => true, 'cart.masjid_ids' => []]);
            config($config);

            foreach (['/api/v1/shop/products', '/api/v1/shop/products/school-polo'] as $uri) {
                $response = $this->getJson($uri, $headers)->assertNotFound();

                $this->assertSame($unknown, $response->getContent(), "{$uri} when {$why} is not the same 404 as a route that does not exist");
            }
        }

        // And lit again, it answers.
        config(['cart.enabled' => true, 'cart.masjid_ids' => []]);
        $this->assertCount(1, $this->listing($granted));
    }

    #[Test]
    public function the_gate_ranks_ahead_of_the_limiter_on_both_routes(): void
    {
        // The middleware aliases, groups and priority list reach the router when the HTTP kernel is
        // built, which the first request does.
        $this->app->make(HttpKernel::class);
        $router = app('router');

        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/shop/')) {
                $found[$route->methods()[0] . ' ' . $route->uri()] = $router->gatherRouteMiddleware($route);
            }
        }

        $this->assertEqualsCanonicalizing(['GET api/v1/shop/products', 'GET api/v1/shop/products/{slug}'], array_keys($found), 'the shop route table');

        foreach ($found as $route => $stack) {
            $gate = array_search(EnsureShopEnabled::class, $stack, true);
            $limiter = array_search(ThrottleRequests::class . ':shop-read', $stack, true);

            $this->assertNotFalse($gate, "{$route} is behind the shop gate");
            $this->assertNotFalse($limiter, "{$route} carries the shop-read limiter");
            $this->assertLessThan($limiter, $gate, "{$route}: a dark shop must run no limiter");
        }
    }

    #[Test]
    public function the_limiter_is_real_when_lit_and_never_runs_when_dark(): void
    {
        config(['app.debug' => false]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        $ran = 0;
        RateLimiter::for('shop-read', function () use (&$ran) {
            $ran++;

            return Limit::perMinute(2)->by('test');
        });

        // Dark: the closure never runs, however often it is asked.
        $dark = $this->org();
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/shop/products', $this->headers($dark))->assertNotFound()->assertHeaderMissing('X-RateLimit-Limit');
        }
        $this->assertSame(0, $ran, 'no limiter closure ran for a dark shop');

        // Lit: the third read in a minute is refused, which shows the limiter is on the route at all.
        $lit = $this->shopOrg();
        $this->getJson('/api/v1/shop/products', $this->headers($lit))->assertOk();
        $this->getJson('/api/v1/shop/products', $this->headers($lit))->assertOk();
        $this->getJson('/api/v1/shop/products', $this->headers($lit))->assertStatus(429);
        $this->assertGreaterThan(0, $ran);
    }

    // ------------------------------------------------------------ the shape

    #[Test]
    public function a_listing_carries_the_products_cheapest_price_its_pictures_in_order_and_its_enabled_sizes(): void
    {
        $org = $this->shopOrg();
        $polo = $this->listed($org, 'School Polo', [
            'M' => ['sort' => 0],
            'L' => ['sort' => 1, 'price_minor' => 2700],
            'XL' => ['sort' => 2, 'enabled' => false],
        ], ['category' => 'Uniforms', 'description' => 'The navy school polo.', 'sort' => 1]);
        $cap = $this->listed($org, 'Cap', ['One size' => ['price_minor' => 1500]], ['sort' => 2, 'category' => null, 'description' => null]);

        // Created out of order: the list follows `order_column`, not the id.
        $second = $this->imageOf($polo, 2);
        $first = $this->imageOf($polo, 1);

        $data = $this->listing($org);

        $this->assertSame(['School Polo', 'Cap'], array_column($data, 'name'), 'the order the office set');

        $row = $data[0];
        $this->assertSame(self::PRODUCT_KEYS, array_keys($row));
        $this->assertSame('school-polo', $row['slug']);
        $this->assertSame('Uniforms', $row['category']);
        $this->assertSame('The navy school polo.', $row['description']);
        $this->assertSame(2500, $row['price_minor'], 'the lowest enabled size: M has no price of its own, so the product\'s');
        $this->assertTrue($row['price_varies']);
        $this->assertSame('usd', $row['currency']);
        $this->assertCount(2, $row['images']);
        $this->assertStringContainsString($first->file_name, $row['images'][0]);
        $this->assertStringContainsString($second->file_name, $row['images'][1]);

        $sizes = ProductVariant::withoutMasjidScope()->where('product_id', $polo->id)->orderBy('sort')->get()->keyBy('label');
        $this->assertSame(
            [
                ['id' => $sizes['M']->id, 'label' => 'M', 'price_minor' => 2500, 'sold_out' => false],
                ['id' => $sizes['L']->id, 'label' => 'L', 'price_minor' => 2700, 'sold_out' => false],
            ],
            $row['variants'],
            'a size\'s effective price, and the disabled XL is not shown'
        );

        $cheap = $data[1];
        $this->assertSame(1500, $cheap['price_minor']);
        $this->assertFalse($cheap['price_varies']);
        $this->assertSame([], $cheap['images']);
        $this->assertNull($cheap['category']);
        $this->assertNull($cheap['description']);

        // The product page is the same product, found by its slug.
        $page = $this->getJson('/api/v1/shop/products/school-polo', $this->headers($org))->assertOk();
        $this->assertSame('success', $page->json('status'));
        $this->assertSame($row, $page->json('data'));

        $this->assertSame($cap->slug, $this->getJson('/api/v1/shop/products/cap', $this->headers($org))->assertOk()->json('data.slug'));
    }

    #[Test]
    public function an_empty_shop_is_an_empty_list_and_an_unknown_slug_is_a_404_with_a_sentence(): void
    {
        $org = $this->shopOrg();

        $response = $this->getJson('/api/v1/shop/products', $this->headers($org))->assertOk();
        $this->assertArrayHasKey('data', $response->json(), 'an empty shop is data: [], not no data');
        $this->assertSame([], $response->json('data'));

        $missing = $this->getJson('/api/v1/shop/products/nothing-here', $this->headers($org))->assertNotFound();
        $this->assertSame('error', $missing->json('status'));
        $this->assertSame('This product was not found.', $missing->json('message'));
    }

    // ------------------------------------------------------------ no stock numbers

    #[Test]
    public function no_stock_sold_or_held_number_appears_anywhere_in_either_response(): void
    {
        $org = $this->shopOrg();
        $polo = $this->listed($org, 'School Polo', [
            'M' => ['stock' => 20, 'sold_count' => 12],
            'L' => ['stock' => null, 'sold_count' => 31],
        ]);
        $this->pendingOrderHolding($org, ProductVariant::withoutMasjidScope()->where('product_id', $polo->id)->where('label', 'M')->firstOrFail(), 1);

        $forbidden = ['stock', 'sold_count', 'sold', 'held', 'available', 'left', 'remaining', 'quantity', 'count', 'inventory', 'units'];

        foreach (['/api/v1/shop/products', '/api/v1/shop/products/school-polo'] as $uri) {
            $response = $this->getJson($uri, $this->headers($org))->assertOk();
            $data = $response->json('data');
            $products = $uri === '/api/v1/shop/products' ? $data : [$data];

            $this->assertCount(1, $products);

            foreach ($products as $product) {
                $this->assertSame(self::PRODUCT_KEYS, array_keys($product), "{$uri}: a product carries exactly these keys");

                foreach ($product['variants'] as $variant) {
                    $this->assertSame(self::VARIANT_KEYS, array_keys($variant), "{$uri}: a size carries exactly these keys");
                }
            }

            // Every key at every depth, lists' indexes included.
            $keys = [];
            $collect = static function (array $node) use (&$collect, &$keys): void {
                foreach ($node as $key => $value) {
                    $keys[] = (string) $key;

                    if (is_array($value)) {
                        $collect($value);
                    }
                }
            };
            $collect($data);

            foreach ($forbidden as $name) {
                $this->assertNotContains($name, $keys, "{$uri}: `{$name}` is a number the public must not read");
            }

            $this->assertStringNotContainsString('"stock"', $response->getContent());
            $this->assertStringNotContainsString('sold_count', $response->getContent());
        }
    }

    #[Test]
    public function a_size_is_sold_out_when_none_is_available_and_an_unlimited_size_never_is(): void
    {
        $org = $this->shopOrg();
        $polo = $this->listed($org, 'School Polo', [
            'sold' => ['stock' => 5, 'sold_count' => 5],
            'held' => ['stock' => 5, 'sold_count' => 3],
            'some left' => ['stock' => 5, 'sold_count' => 3],
            'unlimited' => ['stock' => null, 'sold_count' => 9999],
            'zero' => ['stock' => 0],
            'oversold' => ['stock' => 5, 'sold_count' => 6],
        ]);

        $sizes = ProductVariant::withoutMasjidScope()->where('product_id', $polo->id)->get()->keyBy('label');
        // Two held by a page still open: 5 in all, 3 sold, 2 in a basket: none left to buy.
        $this->pendingOrderHolding($org, $sizes['held'], 2);
        // One held: one left.
        $this->pendingOrderHolding($org, $sizes['some left'], 1);

        $soldOut = collect($this->listing($org)[0]['variants'])->pluck('sold_out', 'label')->all();

        $this->assertSame([
            'sold' => true,
            'held' => true,
            'some left' => false,
            'unlimited' => false,
            'zero' => true,
            'oversold' => true,
        ], $soldOut);
    }

    // ------------------------------------------------------------ what is listed

    #[Test]
    public function inactive_deleted_and_disabled_things_are_hidden_and_so_are_other_organisations_products(): void
    {
        $org = $this->shopOrg();
        $other = $this->shopOrg();

        $this->listed($org, 'Visible', ['M' => [], 'Hidden size' => ['enabled' => false]]);
        $this->listed($org, 'Inactive', ['M' => []], ['active' => false]);
        $this->listed($org, 'Deleted', ['M' => []])->delete();
        $this->listed($org, 'All sizes off', ['M' => ['enabled' => false], 'L' => ['enabled' => false]]);
        $this->listed($org, 'No sizes', []);
        $trashedOnly = $this->listed($org, 'Trashed size only', ['M' => []]);
        ProductVariant::withoutMasjidScope()->where('product_id', $trashedOnly->id)->get()->each->delete();
        $mixed = $this->listed($org, 'Mixed sizes', ['Old' => [], 'New' => []]);
        ProductVariant::withoutMasjidScope()->where('product_id', $mixed->id)->where('label', 'Old')->get()->each->delete();
        $this->listed($other, 'Theirs', ['M' => []]);

        $data = $this->listing($org);

        $this->assertSame(['Mixed sizes', 'Visible'], array_column($data, 'name'));
        $byName = collect($data)->keyBy('name');
        $this->assertSame(['M'], array_column($byName['Visible']['variants'], 'label'), 'a disabled size is not shown');
        $this->assertSame(['New'], array_column($byName['Mixed sizes']['variants'], 'label'), 'a deleted size is not shown');

        foreach (['inactive', 'deleted', 'all-sizes-off', 'no-sizes', 'trashed-size-only', 'theirs'] as $slug) {
            $this->getJson("/api/v1/shop/products/{$slug}", $this->headers($org))->assertNotFound();
        }

        // Each organisation reads its own shelf.
        $this->assertSame(['Theirs'], array_column($this->listing($other), 'name'));
        $this->getJson('/api/v1/shop/products/theirs', $this->headers($other))->assertOk();
        $this->getJson('/api/v1/shop/products/visible', $this->headers($other))->assertNotFound();
    }

    #[Test]
    public function a_picture_of_another_model_that_shares_the_products_id_is_not_served(): void
    {
        $org = $this->shopOrg();
        $polo = $this->listed($org, 'School Polo', ['M' => []]);
        $mine = $this->imageOf($polo, 1);
        $this->pictureOf(Service::class, (int) $polo->id, Product::IMAGES, 1);
        $this->pictureOf(Product::class, (int) $polo->id, 'something_else', 1);

        $images = $this->listing($org)[0]['images'];

        $this->assertCount(1, $images);
        $this->assertStringContainsString($mine->file_name, $images[0]);
    }

    // ------------------------------------------------------------ cost and caching

    #[Test]
    public function the_held_sum_is_one_grouped_query_and_the_total_does_not_grow_with_the_catalogue(): void
    {
        $small = $this->shopOrg();
        $this->listed($small, 'One', ['M' => ['stock' => 5]]);

        $big = $this->shopOrg();
        for ($p = 1; $p <= 5; $p++) {
            $product = $this->listed($big, "Product {$p}", ['S' => ['stock' => 9], 'M' => ['stock' => 9], 'L' => ['stock' => null], 'XL' => ['stock' => 9]]);
            $this->imageOf($product, 1);
            // Something held on one size of each, so the sum has rows to group.
            $this->pendingOrderHolding($big, ProductVariant::withoutMasjidScope()->where('product_id', $product->id)->where('label', 'M')->firstOrFail(), 1);
        }

        $count = function (Masjid $org): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/shop/products', $this->headers($org))->assertOk();
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            return $queries;
        };

        $smallQueries = $count($small);
        $bigQueries = $count($big);

        $held = static fn (array $queries): int => count(array_filter($queries, static fn (string $q): bool => stripos($q, 'order_items') !== false));

        $this->assertSame(1, $held($smallQueries), 'one grouped sum for one size');
        $this->assertSame(1, $held($bigQueries), 'one grouped sum for twenty sizes, not one per size');
        $this->assertSame(count($smallQueries), count($bigQueries), 'twenty sizes cost the same number of queries as one');
    }

    #[Test]
    public function the_reads_send_the_same_cache_control_the_baskets_reads_send_and_nothing_cacheable(): void
    {
        $org = $this->shopOrg();
        $this->listed($org, 'School Polo', ['M' => []]);

        $token = $this->postJson('/api/v1/carts', [], $this->headers($org))->assertOk()->json('data.token');
        $basket = $this->getJson('/api/v1/cart', $this->headers($org) + ['Cart-Token' => $token])->assertOk();

        $list = $this->getJson('/api/v1/shop/products', $this->headers($org))->assertOk();
        $page = $this->getJson('/api/v1/shop/products/school-polo', $this->headers($org))->assertOk();

        $expected = (string) $basket->headers->get('Cache-Control');

        $this->assertNotSame('', $expected, 'premise: the basket read sends a Cache-Control');
        $this->assertSame($expected, (string) $list->headers->get('Cache-Control'));
        $this->assertSame($expected, (string) $page->headers->get('Cache-Control'));

        foreach (['public', 'max-age', 's-maxage', 'immutable'] as $cacheable) {
            $this->assertStringNotContainsString($cacheable, $expected, 'availability moves: nothing here is cacheable');
        }
    }
}
