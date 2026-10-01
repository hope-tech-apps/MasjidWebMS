<?php

namespace Tests\Feature\Shop;

use App\Models\Masjid;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * Shop slice B2: the catalogue over HTTP at /api/admin/masjids/{id}/shop/products. A product, its
 * slug, its currency, its sizes as a list, the stock arithmetic the office reads, and its removal.
 *
 * What the boundary REFUSES (prices, widths, labels, currency) is ShopProductValidationTest; who may
 * reach any of it is ShopAdminGateTest. Everything here runs with the CRM OFF (`crm_enabled` is
 * false on a fresh organisation), which is the point: the shop does not need the member directory.
 */
class ShopProductsAdminTest extends TestCase
{
    use BuildsBaskets;
    use BuildsShop;
    use BuildsShopAdmin;
    use RefreshDatabase;

    private Masjid $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootShopAdminTest();
        Storage::fake('public');

        $this->org = $this->shopOrg();
        $this->admin = $this->adminOf($this->org);

        $this->assertFalse((bool) $this->org->crm_enabled, 'premise: the CRM is off, and the shop does not need it');

        Sanctum::actingAs($this->admin);
    }

    private function products(string $path = ''): string
    {
        return $this->shopUrl($this->org, '/products' . $path);
    }

    /** @return array<string,mixed> */
    private function polo(array $overrides = []): array
    {
        return array_merge(['name' => 'School Polo', 'base_price_minor' => 2500], $overrides);
    }

    // ------------------------------------------------------------ reading

    #[Test]
    public function the_index_lists_active_and_inactive_products_but_not_deleted_ones_with_sold_held_and_available(): void
    {
        $polo = $this->product($this->org, ['name' => 'School Polo', 'slug' => 'school-polo', 'sort' => 1]);
        $this->product($this->org, ['name' => 'Hoodie', 'slug' => 'hoodie', 'active' => false, 'sort' => 2]);
        $this->product($this->org, ['name' => 'Gone', 'slug' => 'gone', 'sort' => 3])->delete();

        $m = $this->variant($polo, ['label' => 'M', 'stock' => 20, 'sold_count' => 12]);
        $this->variant($polo, ['label' => 'L', 'stock' => null, 'sort' => 1]);
        $this->pendingOrderHolding($this->org, $m, 1);

        $response = $this->getJson($this->products())->assertOk();
        $rows = $response->json('data.data');

        $this->assertSame(['School Polo', 'Hoodie'], array_column($rows, 'name'), 'active and inactive, not the deleted one');
        $this->assertSame(['M', 'L'], array_column($rows[0]['variants'], 'label'));

        // "Total 20 · sold 12 · in baskets 1 · left 7".
        $this->assertSame(20, $rows[0]['variants'][0]['stock']);
        $this->assertSame(12, $rows[0]['variants'][0]['sold_count']);
        $this->assertSame(1, $rows[0]['variants'][0]['held']);
        $this->assertSame(7, $rows[0]['variants'][0]['available']);

        // Unlimited stock: no total, and no limit to be left under.
        $this->assertNull($rows[0]['variants'][1]['stock']);
        $this->assertNull($rows[0]['variants'][1]['available']);

        $this->assertSame('usd', $response->json('meta.currency'));
        $this->assertSame(8, $response->json('meta.max_images'));
    }

    #[Test]
    public function a_product_is_shown_with_its_sizes_and_pictures(): void
    {
        $polo = $this->product($this->org);
        $this->variant($polo, ['label' => 'M', 'price_minor' => 2700]);
        $picture = $this->imageOf($polo);

        $data = $this->getJson($this->products('/' . $polo->id))->assertOk()->json('data');

        $this->assertSame($polo->id, $data['id']);
        $this->assertSame(2700, $data['variants'][0]['effective_price_minor'], 'the size\'s own price wins');
        $this->assertSame(2700, $data['variants'][0]['price_minor']);
        $this->assertSame([$picture->id], array_column($data['images'], 'id'));
        $this->assertStringContainsString($picture->file_name, $data['images'][0]['url']);
    }

    #[Test]
    public function a_size_with_no_price_of_its_own_reads_the_products_price_as_its_effective_price(): void
    {
        $polo = $this->product($this->org, ['base_price_minor' => 2500]);
        $this->variant($polo, ['label' => 'M', 'price_minor' => null]);

        $variant = $this->getJson($this->products('/' . $polo->id))->assertOk()->json('data.variants.0');

        $this->assertNull($variant['price_minor']);
        $this->assertSame(2500, $variant['effective_price_minor']);
    }

    // ------------------------------------------------------------ creating

    #[Test]
    public function a_product_is_created_with_its_sizes_in_one_request_and_stamped_with_the_bound_organisation(): void
    {
        $other = $this->shopOrg();

        $response = $this->postJson($this->products(), $this->polo([
            'category' => 'Uniforms',
            'description' => 'The navy school polo.',
            // Whatever the body says, the organisation is the one the server bound.
            'masjid_id' => $other->id,
            'variants' => [
                ['label' => 'YS', 'stock' => 10],
                ['label' => 'YM', 'price_minor' => 2700, 'enabled' => false],
            ],
        ]))->assertCreated();

        $data = $response->json('data');

        $this->assertSame('school-polo', $data['slug']);
        $this->assertSame('usd', $data['currency']);
        $this->assertTrue($data['active']);
        $this->assertSame(['YS', 'YM'], array_column($data['variants'], 'label'));
        $this->assertSame(10, $data['variants'][0]['available']);
        $this->assertSame(0, $data['variants'][0]['sold_count']);
        $this->assertTrue($data['variants'][0]['enabled'], 'a size is on sale unless it says otherwise');
        $this->assertFalse($data['variants'][1]['enabled']);
        $this->assertSame(2700, $data['variants'][1]['effective_price_minor']);

        $product = Product::withoutMasjidScope()->findOrFail($data['id']);
        $this->assertSame($this->org->id, (int) $product->masjid_id);

        foreach (ProductVariant::withoutMasjidScope()->where('product_id', $product->id)->get() as $variant) {
            $this->assertSame($this->org->id, (int) $variant->masjid_id);
        }

        $this->assertSame(0, Product::withoutMasjidScope()->where('masjid_id', $other->id)->count(), 'nothing was planted in the other organisation');
    }

    #[Test]
    public function the_slug_is_generated_from_the_name_and_a_clash_gets_a_suffix(): void
    {
        $slugs = [];

        foreach (['School Polo', 'School Polo', 'School Polo'] as $name) {
            $slugs[] = $this->postJson($this->products(), $this->polo(['name' => $name]))->assertCreated()->json('data.slug');
        }

        $this->assertSame(['school-polo', 'school-polo-2', 'school-polo-3'], $slugs);
    }

    #[Test]
    public function a_slug_is_never_taken_from_the_body_and_a_rename_keeps_it(): void
    {
        $id = $this->postJson($this->products(), $this->polo(['slug' => 'something-else']))->assertCreated()->json('data.id');

        $this->assertSame('school-polo', Product::query()->findOrFail($id)->slug, 'the slug is generated from the name');

        $this->putJson($this->products('/' . $id), ['name' => 'Navy Polo'])->assertOk()->assertJsonPath('data.slug', 'school-polo');

        $this->assertSame('school-polo', Product::query()->findOrFail($id)->slug, 'a rename leaves the address the renderer links to');
    }

    // ------------------------------------------------------------ the currency

    #[Test]
    public function a_product_is_always_in_the_configured_currency_even_one_left_in_another_by_the_b1_default(): void
    {
        $legacy = $this->product($this->org, ['currency' => 'gbp']);

        $this->putJson($this->products('/' . $legacy->id), ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.currency', 'usd');

        $this->assertSame('usd', Product::query()->findOrFail($legacy->id)->currency);

        // Naming the platform's currency is fine, in either case; it is stored as the column holds it.
        $this->postJson($this->products(), $this->polo(['currency' => 'USD']))->assertCreated()->assertJsonPath('data.currency', 'usd');
    }

    // ------------------------------------------------------------ editing

    #[Test]
    public function an_update_changes_only_the_fields_it_sends(): void
    {
        $polo = $this->product($this->org, ['name' => 'School Polo', 'category' => 'Uniforms', 'base_price_minor' => 2500, 'active' => true, 'sort' => 4]);

        $this->putJson($this->products('/' . $polo->id), ['base_price_minor' => 2800, 'active' => false])->assertOk();

        $fresh = Product::query()->findOrFail($polo->id);
        $this->assertSame(2800, (int) $fresh->base_price_minor);
        $this->assertFalse((bool) $fresh->active);
        $this->assertSame('School Polo', $fresh->name);
        $this->assertSame('Uniforms', $fresh->category);
        $this->assertSame(4, (int) $fresh->sort);
    }

    #[Test]
    public function a_list_update_adds_edits_and_soft_deletes_sizes_and_never_touches_sold_count(): void
    {
        $polo = $this->product($this->org, ['base_price_minor' => 2500]);
        $s = $this->variant($polo, ['label' => 'S', 'stock' => 5, 'sold_count' => 2]);
        $m = $this->variant($polo, ['label' => 'M', 'stock' => 10, 'sold_count' => 3]);
        $l = $this->variant($polo, ['label' => 'L', 'stock' => null, 'sold_count' => 7]);

        $response = $this->putJson($this->products('/' . $polo->id), [
            'variants' => [
                ['id' => $s->id, 'label' => 'S', 'stock' => 8, 'price_minor' => 2000],
                // Only the keys it carries change: the stock and the price of M are left alone.
                ['id' => $m->id, 'label' => 'Medium', 'enabled' => false],
                ['label' => 'XL', 'stock' => 3],
            ],
        ])->assertOk();

        $variants = $response->json('data.variants');
        $this->assertSame(['S', 'Medium', 'XL'], array_column($variants, 'label'), 'L was left out of the list');

        $s = ProductVariant::query()->findOrFail($s->id);
        $this->assertSame(8, (int) $s->stock);
        $this->assertSame(2000, (int) $s->price_minor);
        $this->assertSame(2, (int) $s->sold_count, 'the sold count is the settlement\'s alone');
        $this->assertSame(6, $variants[0]['available'], '8 in all, 2 sold');

        $m = ProductVariant::query()->findOrFail($m->id);
        $this->assertSame('Medium', $m->label);
        $this->assertFalse((bool) $m->enabled);
        $this->assertSame(10, (int) $m->stock);
        $this->assertNull($m->price_minor);
        $this->assertSame(3, (int) $m->sold_count);

        // Left out: soft-deleted, its sold count and its row still there for the sales that name it.
        $gone = ProductVariant::withoutMasjidScope()->withTrashed()->findOrFail($l->id);
        $this->assertTrue($gone->trashed());
        $this->assertSame(7, (int) $gone->sold_count);

        // Added: a new row of this product and organisation, with no sales.
        $xl = ProductVariant::query()->where('label', 'XL')->firstOrFail();
        $this->assertSame($polo->id, (int) $xl->product_id);
        $this->assertSame($this->org->id, (int) $xl->masjid_id);
        $this->assertSame(0, (int) $xl->sold_count);
        $this->assertTrue((bool) $xl->enabled);
        $this->assertSame(3, (int) $xl->stock);
    }

    #[Test]
    public function a_request_that_sends_no_sizes_leaves_them_alone_and_an_empty_list_takes_them_all_away(): void
    {
        $polo = $this->product($this->org);
        $this->variant($polo, ['label' => 'S']);
        $this->variant($polo, ['label' => 'M']);

        $this->putJson($this->products('/' . $polo->id), ['name' => 'Navy Polo'])->assertOk()->assertJsonCount(2, 'data.variants');
        $this->assertSame(2, ProductVariant::query()->where('product_id', $polo->id)->count());

        $this->putJson($this->products('/' . $polo->id), ['variants' => []])->assertOk()->assertJsonCount(0, 'data.variants');
        $this->assertSame(0, ProductVariant::query()->where('product_id', $polo->id)->count());
        $this->assertSame(2, ProductVariant::withoutMasjidScope()->withTrashed()->where('product_id', $polo->id)->count(), 'soft-deleted, not removed');
    }

    #[Test]
    public function a_label_freed_by_a_removed_size_can_be_taken_by_a_new_one_in_the_same_save(): void
    {
        $polo = $this->product($this->org);
        $old = $this->variant($polo, ['label' => 'M', 'stock' => 4, 'sold_count' => 4]);

        $new = $this->putJson($this->products('/' . $polo->id), ['variants' => [['label' => 'M', 'stock' => 12]]])
            ->assertOk()
            ->json('data.variants.0');

        $this->assertNotSame($old->id, $new['id'], 'a new row, not the old one: the old size and its sales stay as they were');
        $this->assertSame(12, $new['available']);
        $this->assertTrue(ProductVariant::withoutMasjidScope()->withTrashed()->findOrFail($old->id)->trashed());
    }

    #[Test]
    public function two_sizes_may_swap_their_labels_in_one_save(): void
    {
        $polo = $this->product($this->org);
        $s = $this->variant($polo, ['label' => 'S']);
        $m = $this->variant($polo, ['label' => 'M']);
        $l = $this->variant($polo, ['label' => 'L']);

        $this->putJson($this->products('/' . $polo->id), ['variants' => [
            ['id' => $s->id, 'label' => 'M'],
            ['id' => $m->id, 'label' => 'S'],
            ['id' => $l->id, 'label' => 'L'],
        ]])->assertOk();

        $this->assertSame('M', ProductVariant::query()->findOrFail($s->id)->label);
        $this->assertSame('S', ProductVariant::query()->findOrFail($m->id)->label);
        $this->assertSame('L', ProductVariant::query()->findOrFail($l->id)->label);
    }

    #[Test]
    public function a_stock_set_below_what_is_sold_and_held_is_saved_reads_none_left_and_never_touches_the_sold_count(): void
    {
        $polo = $this->product($this->org);
        $m = $this->variant($polo, ['label' => 'M', 'stock' => 20, 'sold_count' => 12]);
        $this->pendingOrderHolding($this->org, $m, 1);

        $variant = $this->putJson($this->products('/' . $polo->id), ['variants' => [['id' => $m->id, 'label' => 'M', 'stock' => 5]]])
            ->assertOk()
            ->json('data.variants.0');

        // "Stop selling": allowed, never refused.
        $this->assertSame(5, $variant['stock']);
        $this->assertSame(12, $variant['sold_count']);
        $this->assertSame(1, $variant['held']);
        $this->assertSame(0, $variant['available'], 'never a negative number of units left');

        $fresh = ProductVariant::query()->findOrFail($m->id);
        $this->assertSame(5, (int) $fresh->stock);
        $this->assertSame(12, (int) $fresh->sold_count);
    }

    #[Test]
    public function no_request_can_set_a_sold_count_a_product_or_an_organisation_on_a_size(): void
    {
        $polo = $this->product($this->org);
        $other = $this->product($this->org, ['name' => 'Hoodie', 'slug' => 'hoodie']);
        $m = $this->variant($polo, ['label' => 'M', 'stock' => 10, 'sold_count' => 4]);
        $foreign = $this->shopOrg();

        $response = $this->putJson($this->products('/' . $polo->id), ['variants' => [
            ['id' => $m->id, 'label' => 'M', 'sold_count' => 0, 'product_id' => $other->id, 'masjid_id' => $foreign->id],
            ['label' => 'L', 'sold_count' => 99, 'product_id' => $other->id, 'masjid_id' => $foreign->id],
        ]])->assertOk();

        $m = ProductVariant::withoutMasjidScope()->findOrFail($m->id);
        $this->assertSame(4, (int) $m->sold_count);
        $this->assertSame($polo->id, (int) $m->product_id);
        $this->assertSame($this->org->id, (int) $m->masjid_id);

        $l = ProductVariant::withoutMasjidScope()->findOrFail($response->json('data.variants.1.id'));
        $this->assertSame(0, (int) $l->sold_count);
        $this->assertSame($polo->id, (int) $l->product_id);
        $this->assertSame($this->org->id, (int) $l->masjid_id);
    }

    #[Test]
    public function a_size_id_that_is_not_this_products_live_size_is_a_404_and_saves_nothing(): void
    {
        $polo = $this->product($this->org, ['name' => 'School Polo', 'slug' => 'school-polo']);
        $hoodie = $this->product($this->org, ['name' => 'Hoodie', 'slug' => 'hoodie']);
        $this->variant($polo, ['label' => 'M']);
        $theirs = $this->variant($hoodie, ['label' => 'M']);
        $trashed = $this->variant($polo, ['label' => 'Old']);
        $trashed->delete();

        foreach ([$theirs->id, $trashed->id, 999999] as $id) {
            $this->putJson($this->products('/' . $polo->id), [
                'name' => 'Should not be saved',
                'variants' => [['id' => $id, 'label' => 'Z']],
            ])->assertNotFound();
        }

        $this->assertSame('School Polo', Product::query()->findOrFail($polo->id)->name, 'the whole save rolled back');
        $this->assertSame(['M'], ProductVariant::query()->where('product_id', $polo->id)->pluck('label')->all(), 'and no size was removed');
        $this->assertSame('M', ProductVariant::query()->findOrFail($theirs->id)->label);
    }

    // ------------------------------------------------------------ deleting

    #[Test]
    public function deleting_a_product_soft_deletes_it_and_its_sizes_and_frees_its_slug(): void
    {
        $id = $this->postJson($this->products(), $this->polo(['variants' => [['label' => 'M']]]))->assertCreated()->json('data.id');
        $sizeId = ProductVariant::query()->where('product_id', $id)->value('id');

        $this->deleteJson($this->products('/' . $id))->assertOk()->assertJsonPath('status', 'success');

        $this->assertTrue(Product::withoutMasjidScope()->withTrashed()->findOrFail($id)->trashed(), 'soft-deleted, not removed');
        $this->assertTrue(ProductVariant::withoutMasjidScope()->withTrashed()->findOrFail($sizeId)->trashed());

        $this->getJson($this->products('/' . $id))->assertNotFound();
        $this->assertSame([], array_column($this->getJson($this->products())->assertOk()->json('data.data'), 'id'));

        // A product made later under the same name gets the plain slug: uniqueness is among LIVE rows.
        $this->postJson($this->products(), $this->polo())->assertCreated()->assertJsonPath('data.slug', 'school-polo');
        $this->assertSame(2, Product::withoutMasjidScope()->withTrashed()->where('slug', 'school-polo')->count());
    }

    #[Test]
    public function a_product_with_paid_sales_is_deleted_and_its_sales_keep_their_snapshot_and_their_order(): void
    {
        $polo = $this->product($this->org, ['name' => 'School Polo']);
        $m = $this->variant($polo, ['label' => 'M', 'stock' => 5, 'sold_count' => 1]);
        $sale = $this->paidSale($this->org, $m);

        $this->deleteJson($this->products('/' . $polo->id))->assertOk();

        $kept = ProductSale::withoutMasjidScope()->findOrFail($sale->id);
        $this->assertSame('School Polo', $kept->product_name);
        $this->assertSame('M', $kept->variant_label);
        $this->assertSame($polo->id, (int) $kept->product_id);
        $this->assertNotNull(Order::withoutMasjidScope()->find($sale->order_id), 'the order a sale belongs to is untouched');
        $this->assertSame(1, (int) ProductVariant::withoutMasjidScope()->withTrashed()->findOrFail($m->id)->sold_count);
    }
}
