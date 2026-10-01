<?php

namespace Tests\Feature\Shop;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductVariant;
use App\Models\Service;
use App\Support\CartTables;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * Shop slice B1: the SHAPE of the shop's data, which SQLite would otherwise hide.
 *
 *   - the column types and widths MySQL will enforce (SQLite ignores varchar(n)), asserted as
 *     TYPES and against the migration's own declarations (.claude/rules/shipping.md);
 *   - every index named by hand, under MySQL's 64 characters;
 *   - "unique among LIVE rows" for a slug and a size label, which a trashed row must not break and
 *     a restore into a clash must (.claude/rules/migrations.md: a partial index here, a generated
 *     column on MySQL, which tests/Mysql/ShopLiveUniquenessTest.php runs against the real engine);
 *   - product_sales: one sale per order line, RESTRICT keys that make a paid order unprunable, and
 *     plain ids (no key) to the product and variant, which may be trashed or removed after selling;
 *   - a product's pictures are read by the whole media key, `model_type` included.
 */
class ShopSchemaTest extends TestCase
{
    use BuildsBaskets;
    use BuildsShop;
    use RefreshDatabase;

    private const MIGRATIONS = [
        'database/migrations/2026_10_06_100000_create_shop_tables.php',
        'database/migrations/2026_10_06_100100_add_buyable_index_to_order_items_table.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    /** @return array{0: Order, 1: OrderItem} a PAID order with one product line */
    private function paidLineFor(\App\Models\Masjid $org, ProductVariant $variant): array
    {
        $order = Order::withoutMasjidScope()->create([
            'masjid_id' => $org->id,
            'uuid' => (string) Str::uuid(),
            'order_number' => strtoupper(Str::random(8)),
            'status' => Order::STATUS_PAID,
            'total_minor' => 2500,
            'currency' => 'usd',
            'charge_account_id' => 'acct_' . uniqid(),
        ]);

        $line = OrderItem::withoutMasjidScope()->create([
            'order_id' => $order->id,
            'masjid_id' => $org->id,
            'buyable_type' => CartItem::TYPE_PRODUCT,
            'buyable_id' => $variant->id,
            'recorded_as' => CartItem::RECORDED_AS_SALE,
            'label' => 'School Polo (M)',
            'quantity' => 1,
            'unit_amount_minor' => 2500,
            'total_minor' => 2500,
            'currency' => 'usd',
        ]);

        return [$order, $line];
    }

    private function saleFor(Order $order, OrderItem $line, ProductVariant $variant): ProductSale
    {
        return ProductSale::create([
            'masjid_id' => $order->masjid_id,
            'order_id' => $order->id,
            'order_item_id' => $line->id,
            'product_id' => $variant->product_id,
            'variant_id' => $variant->id,
            'product_name' => 'School Polo',
            'variant_label' => 'M',
            'quantity' => 1,
            'unit_minor' => 2500,
            'total_minor' => 2500,
        ]);
    }

    // ------------------------------------------------------------- the schema

    #[Test]
    public function the_tables_and_their_column_types_are_what_mysql_will_also_enforce(): void
    {
        foreach (['products', 'product_variants', 'product_sales'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        // SQLite ignores varchar(n) and integer widths, so a round-trip proves nothing: assert
        // the declared TYPE.
        $this->assertSame('varchar', Schema::getColumnType('products', 'name'));
        $this->assertSame('varchar', Schema::getColumnType('products', 'slug'));
        $this->assertSame('text', Schema::getColumnType('products', 'description'), 'a description is as long as the owner writes it');
        $this->assertSame('integer', Schema::getColumnType('products', 'base_price_minor'));
        $this->assertSame('varchar', Schema::getColumnType('product_variants', 'label'));
        $this->assertSame('integer', Schema::getColumnType('product_variants', 'price_minor'));
        $this->assertSame('integer', Schema::getColumnType('product_variants', 'stock'));
        $this->assertSame('integer', Schema::getColumnType('product_variants', 'sold_count'));
        $this->assertSame('varchar', Schema::getColumnType('product_sales', 'product_name'));
        $this->assertSame('varchar', Schema::getColumnType('product_sales', 'variant_label'));
        $this->assertSame('integer', Schema::getColumnType('product_sales', 'unit_minor'));
        $this->assertSame('integer', Schema::getColumnType('product_sales', 'total_minor'));
        $this->assertSame('datetime', Schema::getColumnType('product_sales', 'collected_at'));

        // The migration declares the widths MySQL will enforce; SQLite cannot show them.
        $source = (string) file_get_contents(base_path(self::MIGRATIONS[0]));
        foreach ([
            "string('name', 120)", "string('slug', 140)", "string('category', 60)", "char('currency', 3)",
            "text('description')", "string('label', 40)", "string('product_name', 120)", "string('variant_label', 40)",
            "unsignedInteger('base_price_minor')", "unsignedInteger('stock')", "unsignedInteger('sold_count')",
            "unsignedBigInteger('unit_minor')", "unsignedBigInteger('total_minor')",
        ] as $declaration) {
            $this->assertStringContainsString($declaration, $source);
        }

        // The snapshot columns are as wide as what they copy, so a name that fitted fits.
        $this->assertStringContainsString("string('name', 120)", $source);
        $this->assertStringContainsString("string('product_name', 120)", $source);
    }

    #[Test]
    public function every_index_name_is_hand_written_and_fits_mysqls_64_characters(): void
    {
        $expected = [
            'products' => ['products_tenant_active_sort_index', 'products_live_slug_unique'],
            'product_variants' => ['product_variants_live_label_unique'],
            'product_sales' => ['product_sales_order_item_unique', 'product_sales_tenant_product_variant_index'],
            'order_items' => ['order_items_buyable_index'],
        ];

        foreach ($expected as $table => $names) {
            $have = array_column(Schema::getIndexes($table), 'name');

            foreach ($names as $name) {
                $this->assertContains($name, $have, "{$table} lost its hand-named index {$name}");
            }

            foreach ($have as $name) {
                $this->assertLessThanOrEqual(64, strlen((string) $name), "{$table}: {$name} is over MySQL's 64 characters");
            }
        }

        foreach (self::MIGRATIONS as $path) {
            $source = (string) file_get_contents(base_path($path));

            foreach (preg_split('/\R/', $source) as $line) {
                if (preg_match('/\$table->(index|unique)\(/', $line) === 1) {
                    $this->assertMatchesRegularExpression("/,\s*'[a-z0-9_]+'\)\s*;/", $line, "{$path}: an index with no hand name: {$line}");
                }
            }

            $this->assertStringNotContainsString('->enum(', $source, "{$path} uses a database enum");
            $this->assertStringNotContainsString("unique(['masjid_id', 'slug', 'deleted_at']", $source, 'unique(col, deleted_at) enforces nothing');
        }
    }

    #[Test]
    public function the_generated_columns_are_hidden_so_a_serialised_row_is_the_same_on_both_drivers(): void
    {
        $this->assertContains('live_slug', (new Product)->getHidden());
        $this->assertContains('live_label', (new ProductVariant)->getHidden());
        $this->assertNotContains('live_slug', (new Product)->getFillable());
        $this->assertNotContains('live_label', (new ProductVariant)->getFillable());

        $variant = $this->sizeOf($this->org());

        $this->assertArrayNotHasKey('live_slug', Product::query()->findOrFail($variant->product_id)->toArray());
        $this->assertArrayNotHasKey('live_label', $variant->fresh()->toArray());
    }

    #[Test]
    public function product_sales_is_a_cart_table_and_sits_before_order_items_so_a_drop_in_order_works(): void
    {
        $names = CartTables::NAMES;

        $this->assertContains('product_sales', $names);
        $this->assertLessThan(array_search('order_items', $names, true), array_search('product_sales', $names, true));
        $this->assertLessThan(array_search('orders', $names, true), array_search('product_sales', $names, true));

        foreach ($names as $table) {
            Schema::dropIfExists($table);
        }

        foreach ($names as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} dropped in NAMES order without a foreign-key error");
        }
    }

    // ----------------------------------------- unique among LIVE rows (the partial index)

    #[Test]
    public function a_slug_is_unique_per_organisation_among_live_products_only(): void
    {
        $a = $this->org();
        $b = $this->org();
        $first = $this->product($a, ['slug' => 'school-polo']);

        // Another organisation may use the same slug.
        $this->product($b, ['slug' => 'school-polo']);

        // The same organisation may not.
        try {
            $this->product($a, ['slug' => 'school-polo', 'name' => 'Second polo']);
            $this->fail('a second LIVE product with the same slug in one organisation was admitted');
        } catch (QueryException $e) {
            $this->assertSame(1, Product::query()->where('masjid_id', $a->id)->where('slug', 'school-polo')->count());
        }

        // A trashed product frees its slug (this is what unique(slug, deleted_at) cannot do)...
        $first->delete();
        $second = $this->product($a, ['slug' => 'school-polo', 'name' => 'Second polo']);
        $this->assertSame('Second polo', Product::query()->where('slug', 'school-polo')->where('masjid_id', $a->id)->value('name'));

        // ...and several trashed ones may share it.
        $second->delete();
        $third = $this->product($a, ['slug' => 'school-polo', 'name' => 'Third polo']);
        $this->assertSame(2, Product::onlyTrashed()->where('masjid_id', $a->id)->where('slug', 'school-polo')->count());

        // Restoring one INTO a clash is refused: the rule holds in both directions.
        try {
            $first->restore();
            $this->fail('a trashed product was restored on top of a live one with its slug');
        } catch (QueryException $e) {
            $this->assertNotNull(Product::withTrashed()->find($first->id)->deleted_at);
            $this->assertNull($third->fresh()->deleted_at);
        }
    }

    #[Test]
    public function a_size_label_is_unique_per_product_among_live_variants_only(): void
    {
        $org = $this->org();
        $polo = $this->product($org);
        $hoodie = $this->product($org, ['name' => 'Hoodie']);
        $medium = $this->variant($polo, ['label' => 'M']);

        // Another product may have its own "M".
        $this->variant($hoodie, ['label' => 'M']);

        try {
            $this->variant($polo, ['label' => 'M']);
            $this->fail('a second LIVE "M" on one product was admitted');
        } catch (QueryException $e) {
            $this->assertSame(1, ProductVariant::query()->where('product_id', $polo->id)->where('label', 'M')->count());
        }

        $medium->delete();
        $again = $this->variant($polo, ['label' => 'M', 'stock' => 3]);
        $this->assertSame(3, (int) ProductVariant::query()->where('product_id', $polo->id)->where('label', 'M')->value('stock'));

        try {
            $medium->restore();
            $this->fail('a trashed size was restored on top of a live one with its label');
        } catch (QueryException $e) {
            $this->assertNull($again->fresh()->deleted_at);
        }
    }

    // ------------------------------------------------------------ product_sales

    #[Test]
    public function a_line_has_one_sale_and_the_database_itself_refuses_a_second(): void
    {
        $org = $this->org();
        $variant = $this->sizeOf($org);
        [$order, $line] = $this->paidLineFor($org, $variant);
        $this->saleFor($order, $line, $variant);

        $this->expectException(QueryException::class);
        $this->saleFor($order, $line, $variant);
    }

    #[Test]
    public function a_paid_order_with_a_sale_cannot_be_deleted_nor_can_its_line(): void
    {
        $org = $this->org();
        $variant = $this->sizeOf($org);
        [$order, $line] = $this->paidLineFor($org, $variant);
        $sale = $this->saleFor($order, $line, $variant);

        foreach ([
            fn () => Order::withoutMasjidScope()->whereKey($order->id)->delete(),
            fn () => OrderItem::withoutMasjidScope()->whereKey($line->id)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('RESTRICT let a sale\'s order or line be deleted');
            } catch (QueryException $e) {
                $this->assertNotNull(ProductSale::withoutMasjidScope()->find($sale->id), 'the sale is still there');
            }
        }

        // An UNPAID order, which cart:prune does delete, has no sale and goes with its lines.
        $unpaid = Order::withoutMasjidScope()->create([
            'masjid_id' => $org->id, 'uuid' => (string) Str::uuid(), 'order_number' => strtoupper(Str::random(8)),
            'status' => Order::STATUS_PENDING, 'total_minor' => 2500, 'currency' => 'usd', 'charge_account_id' => 'acct_' . uniqid(),
        ]);
        OrderItem::withoutMasjidScope()->create([
            'order_id' => $unpaid->id, 'masjid_id' => $org->id, 'buyable_type' => CartItem::TYPE_PRODUCT,
            'buyable_id' => $variant->id, 'recorded_as' => CartItem::RECORDED_AS_SALE, 'label' => 'School Polo (M)',
            'quantity' => 1, 'unit_amount_minor' => 2500, 'total_minor' => 2500, 'currency' => 'usd',
        ]);

        $this->assertSame(1, Order::withoutMasjidScope()->whereKey($unpaid->id)->delete());
        $this->assertSame(0, OrderItem::withoutMasjidScope()->where('order_id', $unpaid->id)->count(), 'its lines cascade');
    }

    #[Test]
    public function a_sale_outlives_its_variant_and_product_which_have_no_key_to_it(): void
    {
        $org = $this->org();
        $variant = $this->sizeOf($org);
        [$order, $line] = $this->paidLineFor($org, $variant);
        $sale = $this->saleFor($order, $line, $variant);

        $variant->delete();
        Product::query()->find($variant->product_id)->delete();
        $this->assertNotNull(ProductSale::withoutMasjidScope()->find($sale->id));

        // Even removed outright: product_sales names them by plain id.
        ProductVariant::withTrashed()->whereKey($variant->id)->forceDelete();
        Product::withTrashed()->whereKey($variant->product_id)->forceDelete();

        $fresh = ProductSale::withoutMasjidScope()->findOrFail($sale->id);
        $this->assertSame('School Polo', $fresh->product_name, 'the snapshot is the truth');
        $this->assertSame('M', $fresh->variant_label);
        $this->assertSame((int) $variant->id, (int) $fresh->variant_id);
    }

    // ------------------------------------------------------------------- images

    #[Test]
    public function a_products_pictures_are_read_by_the_whole_media_key(): void
    {
        Storage::fake('public');

        $org = $this->org();
        $other = $this->org();
        $product = $this->product($org);

        // The collision the half-key allowed: another model's row in the same collection whose id
        // is this product's id. Premise: the ids really are equal.
        $service = Service::create(['masjid_id' => $other->id, 'title' => 't', 'summary' => 's', 'description' => 'd', 'text' => 'x']);
        $this->assertSame((int) $product->id, (int) $service->id, 'premise: the fixture needs a service whose id is the product\'s id');

        $own = $this->media(Product::class, $product->id, Product::IMAGES, 2);
        $first = $this->media(Product::class, $product->id, Product::IMAGES, 1);
        $this->media(Service::class, $service->id, Product::IMAGES, 1);
        $this->media(Product::class, $product->id, 'something_else', 1);

        $this->assertSame('product_images', Product::IMAGES);
        $this->assertSame([$first->id, $own->id], $product->images()->pluck('id')->all(), 'only this product\'s, in their order');
        $this->assertSame(2, Product::query()->withCount('images')->findOrFail($product->id)->images_count);
        $this->assertInstanceOf(\Spatie\MediaLibrary\HasMedia::class, $product);
    }

    private function media(string $type, int $id, string $collection, int $order): Media
    {
        $media = new Media;
        $media->model_type = $type;
        $media->model_id = $id;
        $media->uuid = (string) Str::uuid();
        $media->collection_name = $collection;
        $media->name = 'image';
        $media->file_name = 'image-' . Str::random(6) . '.png';
        $media->mime_type = 'image/png';
        $media->disk = 'public';
        $media->size = 12;
        $media->manipulations = [];
        $media->custom_properties = [];
        $media->generated_conversions = [];
        $media->responsive_images = [];
        $media->order_column = $order;
        $media->save();

        return $media;
    }
}
