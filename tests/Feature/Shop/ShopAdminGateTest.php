<?php

namespace Tests\Feature\Shop;

use App\Models\Masjid;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductVariant;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * Shop slice B2: WHO may reach the shop's admin routes, and of WHICH organisation. Three questions
 * asked of every route:
 *
 *   - the `shop` capability: off, every route answers 403 exactly as class_store's do;
 *   - the permission: `view donations` reads, `manage donations` writes, and an admin without them is refused;
 *   - the tenant: another organisation's product, size, picture or sale is a 404, and what it holds is untouched.
 *
 * The route table is walked from the router, and the map of calls below must name every shop route,
 * so a route added without its gate and its tenancy test fails here until it has them.
 */
class ShopAdminGateTest extends TestCase
{
    use BuildsBaskets;
    use BuildsShop;
    use BuildsShopAdmin;
    use RefreshDatabase;

    private const BASE = 'api/admin/masjids/{masjid_id}/shop/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootShopAdminTest();
        Storage::fake('public');
    }

    /**
     * Every shop admin route, as a call that sends a request WORTH answering (a valid body), against
     * `$base`'s own URL and the ids given.
     *
     * @param  array{product: int, media: int, sale: int}  $ids
     * @return array<string, Closure(): TestResponse>
     */
    private function calls(Masjid $base, array $ids): array
    {
        $url = fn (string $path): string => $this->shopUrl($base, $path);
        $upload = fn (): array => ['images' => [UploadedFile::fake()->create('probe.jpg', 10, 'image/jpeg')]];

        return [
            'GET products' => fn () => $this->getJson($url('/products')),
            'POST products' => fn () => $this->postJson($url('/products'), ['name' => 'Gate Probe', 'base_price_minor' => 100]),
            'GET products/{product_id}' => fn () => $this->getJson($url("/products/{$ids['product']}")),
            'PUT products/{product_id}' => fn () => $this->putJson($url("/products/{$ids['product']}"), ['name' => 'Gate Probe']),
            'DELETE products/{product_id}' => fn () => $this->deleteJson($url("/products/{$ids['product']}")),
            'POST products/{product_id}/images' => fn () => $this->withHeaders(['Accept' => 'application/json'])->post($url("/products/{$ids['product']}/images"), $upload()),
            'PUT products/{product_id}/images/order' => fn () => $this->putJson($url("/products/{$ids['product']}/images/order"), ['order' => [$ids['media']]]),
            'DELETE products/{product_id}/images/{media_id}' => fn () => $this->deleteJson($url("/products/{$ids['product']}/images/{$ids['media']}")),
            'GET sales.csv' => fn () => $this->get($url('/sales.csv')),
            'GET sales' => fn () => $this->getJson($url('/sales')),
            'POST sales/{sale_id}/collect' => fn () => $this->postJson($url("/sales/{$ids['sale']}/collect")),
            'DELETE sales/{sale_id}/collect' => fn () => $this->deleteJson($url("/sales/{$ids['sale']}/collect")),
        ];
    }

    /**
     * An organisation with a product, a size, a picture and a paid sale: what every route has to name.
     *
     * @return array{org: Masjid, admin: User, product: Product, variant: ProductVariant, media: Media, sale: ProductSale}
     */
    private function world(bool $granted = true): array
    {
        $org = $granted ? $this->shopOrg() : $this->org();
        $product = $this->product($org);
        $variant = $this->variant($product, ['label' => 'M', 'stock' => 5]);

        return [
            'org' => $org,
            'admin' => $this->adminOf($org),
            'product' => $product,
            'variant' => $variant,
            'media' => $this->imageOf($product),
            'sale' => $this->paidSale($org, $variant),
        ];
    }

    /** @return array{product: int, media: int, sale: int} */
    private function idsOf(array $world): array
    {
        return ['product' => (int) $world['product']->id, 'media' => (int) $world['media']->id, 'sale' => (int) $world['sale']->id];
    }

    /** What the shop holds, as numbers a refused request must not move. */
    private function snapshot(): array
    {
        return [
            'products' => Product::withoutMasjidScope()->withTrashed()->count(),
            'trashed' => Product::withoutMasjidScope()->onlyTrashed()->count(),
            'variants' => ProductVariant::withoutMasjidScope()->withTrashed()->count(),
            'media' => Media::query()->count(),
            'collected' => ProductSale::withoutMasjidScope()->whereNotNull('collected_at')->count(),
            'names' => Product::withoutMasjidScope()->orderBy('id')->pluck('name')->all(),
        ];
    }

    // ------------------------------------------------------------ the route table

    #[Test]
    public function every_shop_route_carries_the_capability_and_a_donations_permission_and_sits_outside_the_crm(): void
    {
        $this->assertSame(8, Permission::query()->count(), 'no permission was minted for the shop');

        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), self::BASE)) {
                continue;
            }

            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $key = $method . ' ' . substr($route->uri(), strlen(self::BASE));
            $middleware = $route->gatherMiddleware();

            $this->assertContains('capability:shop', $middleware, "{$key} is not behind the shop capability");
            $this->assertNotContains('crm', $middleware, "{$key} needs the member directory, which a shop must not");

            $permissions = array_values(array_filter($middleware, static fn ($m): bool => is_string($m) && str_starts_with($m, 'permission:')));
            $this->assertSame(
                [$method === 'GET' ? 'permission:view donations' : 'permission:manage donations'],
                $permissions,
                "{$key} carries the wrong permission: a read needs view donations, a write needs manage donations"
            );

            $found[] = $key;
        }

        $world = $this->world();

        $this->assertEqualsCanonicalizing(
            array_keys($this->calls($world['org'], $this->idsOf($world))),
            $found,
            'the shop route table and the calls this test makes are not the same set: a route was added or removed'
        );
    }

    // ------------------------------------------------------------ the capability

    #[Test]
    public function with_the_capability_off_every_shop_route_answers_403_as_class_stores_do_and_writes_nothing(): void
    {
        $world = $this->world(granted: false);
        $this->assertFalse($world['org']->hasCapability('shop'), 'premise: the shop is off by default');

        Sanctum::actingAs($world['admin']);
        $before = $this->snapshot();

        foreach ($this->calls($world['org'], $this->idsOf($world)) as $key => $call) {
            $response = $call()->assertForbidden();

            $this->assertSame('Online shop is not switched on for this organisation.', $response->json('message'), "{$key}: the sentence the capability gate gives");
        }

        $this->assertSame($before, $this->snapshot(), 'nothing was written');
    }

    #[Test]
    public function the_capability_is_per_organisation(): void
    {
        $on = $this->world();
        $off = $this->world(granted: false);

        Sanctum::actingAs($on['admin']);
        $this->getJson($this->shopUrl($on['org'], '/products'))->assertOk();

        Sanctum::actingAs($off['admin']);
        $this->getJson($this->shopUrl($off['org'], '/products'))->assertForbidden();
    }

    // ------------------------------------------------------------ the permission

    #[Test]
    public function an_admin_without_the_permissions_is_refused_on_every_shop_route(): void
    {
        $world = $this->world();
        $none = $this->adminHolding($world['org'], []);

        Sanctum::actingAs($none);
        $before = $this->snapshot();

        foreach ($this->calls($world['org'], $this->idsOf($world)) as $key => $call) {
            $call()->assertForbidden();
        }

        $this->assertSame($before, $this->snapshot(), 'a refused request wrote nothing');
    }

    #[Test]
    public function view_donations_reads_the_shop_and_manage_donations_is_needed_to_change_it(): void
    {
        $world = $this->world();
        $reader = $this->adminHolding($world['org'], ['view donations']);

        Sanctum::actingAs($reader);
        $before = $this->snapshot();

        foreach ($this->calls($world['org'], $this->idsOf($world)) as $key => $call) {
            $response = $call();

            if (str_starts_with($key, 'GET ')) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
            }
        }

        $this->assertSame($before, $this->snapshot(), 'a reader changed nothing');

        // The reverse: manage without view is refused on the reads, since the two are separate grants.
        $writer = $this->adminHolding($world['org'], ['manage donations']);
        Sanctum::actingAs($writer);

        $this->getJson($this->shopUrl($world['org'], '/products'))->assertForbidden();
        $this->getJson($this->shopUrl($world['org'], '/sales'))->assertForbidden();
        $this->get($this->shopUrl($world['org'], '/sales.csv'))->assertForbidden();
        $this->postJson($this->shopUrl($world['org'], '/products'), ['name' => 'Written', 'base_price_minor' => 100])->assertCreated();
    }

    #[Test]
    public function an_unauthenticated_caller_gets_a_401_and_a_non_admin_login_is_refused(): void
    {
        $world = $this->world();

        $this->getJson($this->shopUrl($world['org'], '/products'))->assertUnauthorized();

        // A parent-style plain User login is not an administrator of anything.
        $member = User::factory()->create(['type' => 'User', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        Sanctum::actingAs($member);

        $this->getJson($this->shopUrl($world['org'], '/products'))->assertStatus(401);
    }

    // ------------------------------------------------------------ the tenant

    #[Test]
    public function another_organisations_product_size_picture_or_sale_is_a_404_on_every_route_that_names_one(): void
    {
        $mine = $this->world();
        $theirs = $this->world();

        $foreign = $this->idsOf($theirs);
        $own = $this->idsOf($mine);

        $theirVariant = (int) $theirs['variant']->id;
        $before = $this->snapshot();
        $theirName = Product::withoutMasjidScope()->findOrFail($foreign['product'])->name;

        Sanctum::actingAs($mine['admin']);
        $calls = $this->calls($mine['org'], $foreign);

        // Routes that name an id of the other organisation: all 404.
        $named = [
            'GET products/{product_id}',
            'PUT products/{product_id}',
            'DELETE products/{product_id}',
            'POST products/{product_id}/images',
            'PUT products/{product_id}/images/order',
            'DELETE products/{product_id}/images/{media_id}',
            'POST sales/{sale_id}/collect',
            'DELETE sales/{sale_id}/collect',
        ];

        foreach ($named as $key) {
            $calls[$key]()->assertNotFound();
        }

        // My product, THEIR picture: the picture is not mine, so it is a 404 and it is not deleted or moved.
        $this->deleteJson($this->shopUrl($mine['org'], "/products/{$own['product']}/images/{$foreign['media']}"))->assertNotFound();
        $this->putJson($this->shopUrl($mine['org'], "/products/{$own['product']}/images/order"), ['order' => [$own['media'], $foreign['media']]])->assertNotFound();

        // My product, THEIR size inside the list: a 404, and the whole save rolls back.
        $this->putJson($this->shopUrl($mine['org'], "/products/{$own['product']}"), [
            'name' => 'Should not be saved',
            'variants' => [['id' => $theirVariant, 'label' => 'Z']],
        ])->assertNotFound();

        $this->assertSame($before, $this->snapshot(), 'a request aimed at the other organisation changed nothing');
        $this->assertSame($theirName, Product::withoutMasjidScope()->findOrFail($foreign['product'])->name);
        $this->assertSame('M', ProductVariant::withoutMasjidScope()->findOrFail($theirVariant)->label);
        $this->assertNotNull(Media::query()->find($foreign['media']), 'their picture is still there');
        $this->assertNull(ProductSale::withoutMasjidScope()->findOrFail($foreign['sale'])->collected_at);

        // The routes that name no id read only my own.
        $this->assertSame([$own['product']], array_column($calls['GET products']()->assertOk()->json('data.data'), 'id'));
        $this->assertSame([$own['sale']], array_column($calls['GET sales']()->assertOk()->json('data.data'), 'id'));
        $this->assertSame([$own['sale']], array_column($this->getJson($this->shopUrl($mine['org'], '/sales?state=all'))->json('data.data'), 'id'));

        $csv = $calls['GET sales.csv']()->assertOk()->streamedContent();
        $this->assertStringContainsString((string) Order::withoutMasjidScope()->findOrFail($mine['sale']->order_id)->order_number, $csv);
        $this->assertStringNotContainsString(
            (string) Order::withoutMasjidScope()->findOrFail($theirs['sale']->order_id)->order_number,
            $csv,
            'the other organisation\'s order number is in my export'
        );
    }

    #[Test]
    public function naming_another_organisation_in_the_route_is_refused_before_any_shop_code_runs(): void
    {
        $mine = $this->world();
        $theirs = $this->world();

        Sanctum::actingAs($mine['admin']);

        // The URL's organisation is theirs: the tenant middleware refuses an administrator of mine.
        $this->getJson($this->shopUrl($theirs['org'], '/products'))->assertForbidden();
        $this->postJson($this->shopUrl($theirs['org'], '/products'), ['name' => 'Planted', 'base_price_minor' => 100])->assertForbidden();

        $this->assertSame(0, Product::withoutMasjidScope()->where('name', 'Planted')->count());
    }
}
