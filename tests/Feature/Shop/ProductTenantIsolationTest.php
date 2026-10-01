<?php

namespace Tests\Feature\Shop;

use App\Models\CartItem;
use App\Models\Masjid;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductVariant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * Shop slice B1: the three new tenant-scoped models, Product, ProductVariant and ProductSale,
 * cannot cross an organisation. MySQL has no row-level security, so this is the only backstop;
 * the mechanism itself is proved for every BelongsToMasjid model by TenantScopingCoverageTest,
 * which also demands the cross-tenant TEST written here against real rows in two organisations.
 *
 * One method per model, each naming its model and asserting a refusal (a null find, zero rows
 * updated or deleted), which is what that meta-test reads.
 */
class ProductTenantIsolationTest extends TestCase
{
    use BuildsBaskets;
    use BuildsShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Shop Org ' . uniqid(), 'email' => 'shop-' . uniqid() . '@test.local', 'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    /** A paid order with one product line, built unbound with an explicit organisation. */
    private function paidLine(Masjid $org, ProductVariant $variant): OrderItem
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

        return OrderItem::withoutMasjidScope()->create([
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
    }

    private function saleFor(Masjid $org, ProductVariant $variant): ProductSale
    {
        $line = $this->paidLine($org, $variant);

        // Unbound, so the explicit masjid_id is honoured (the creating hook only overrides when bound).
        return ProductSale::create([
            'masjid_id' => $org->id,
            'order_id' => $line->order_id,
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

    #[Test]
    public function organisation_a_cannot_read_update_or_delete_organisation_bs_products(): void
    {
        $a = $this->makeMasjid();
        $b = $this->makeMasjid();
        $productA = $this->product($a);
        $productB = $this->product($b);

        app(TenantContext::class)->set($a->id);

        // Reads: B's row is simply not there.
        $this->assertNull(Product::query()->find($productB->id));
        $this->assertSame([$productA->id], Product::query()->pluck('id')->all());

        // Writes: an update or delete aimed at B's row touches nothing, soft deletes included.
        $this->assertSame(0, Product::query()->whereKey($productB->id)->update(['name' => 'Hacked']));
        $this->assertSame(0, Product::query()->whereKey($productB->id)->delete());

        // A create is stamped with the bound tenant whatever the caller says.
        $forged = Product::create([
            'masjid_id' => $b->id, 'name' => 'Forged', 'slug' => 'forged-' . uniqid(), 'base_price_minor' => 100,
        ]);
        $this->assertSame($a->id, (int) $forged->masjid_id);

        // And B's data is exactly as it was.
        app(TenantContext::class)->forgetTenant();
        $this->assertSame('School Polo', Product::query()->whereKey($productB->id)->value('name'));
        $this->assertNull(Product::withTrashed()->whereKey($productB->id)->value('deleted_at'), 'B\'s product was not trashed');
    }

    #[Test]
    public function organisation_a_cannot_read_update_or_delete_organisation_bs_variants(): void
    {
        $a = $this->makeMasjid();
        $b = $this->makeMasjid();
        $variantA = $this->sizeOf($a, ['stock' => 5]);
        $variantB = $this->sizeOf($b, ['stock' => 5]);

        app(TenantContext::class)->set($a->id);

        $this->assertNull(ProductVariant::query()->find($variantB->id));
        $this->assertNull(ProductVariant::query()->withTrashed()->find($variantB->id), 'trashed rows are no exception');
        $this->assertSame([$variantA->id], ProductVariant::query()->pluck('id')->all());

        $this->assertSame(0, ProductVariant::query()->whereKey($variantB->id)->update(['stock' => 0]));
        $this->assertSame(0, ProductVariant::query()->whereKey($variantB->id)->delete());

        // A variant cannot be filed under another organisation's product by naming it, either:
        // the stamp is the tenant's, whatever the caller says.
        $forged = ProductVariant::create([
            'masjid_id' => $b->id, 'product_id' => $variantA->product_id, 'label' => 'XL',
        ]);
        $this->assertSame($a->id, (int) $forged->masjid_id);

        app(TenantContext::class)->forgetTenant();
        $this->assertSame(5, (int) ProductVariant::query()->whereKey($variantB->id)->value('stock'));
        $this->assertNull(ProductVariant::withTrashed()->whereKey($variantB->id)->value('deleted_at'), 'B\'s variant was not trashed');
    }

    #[Test]
    public function organisation_a_cannot_read_update_or_delete_organisation_bs_product_sales(): void
    {
        $a = $this->makeMasjid();
        $b = $this->makeMasjid();
        $saleA = $this->saleFor($a, $this->sizeOf($a));
        $saleB = $this->saleFor($b, $this->sizeOf($b));

        app(TenantContext::class)->set($a->id);

        $this->assertNull(ProductSale::query()->find($saleB->id));
        $this->assertSame([$saleA->id], ProductSale::query()->pluck('id')->all());
        $this->assertSame(1, (int) ProductSale::query()->sum('quantity'), 'a pickup count can never include the other organisation');

        $this->assertSame(0, ProductSale::query()->whereKey($saleB->id)->update(['collected_at' => now()]));
        $this->assertSame(0, ProductSale::query()->whereKey($saleB->id)->delete());

        app(TenantContext::class)->forgetTenant();
        $this->assertNull(ProductSale::query()->whereKey($saleB->id)->value('collected_at'), 'B\'s sale was not marked collected');
        $this->assertSame(1, ProductSale::query()->where('masjid_id', $b->id)->count());
    }
}
