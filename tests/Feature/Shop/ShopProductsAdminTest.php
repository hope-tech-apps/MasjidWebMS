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
use Illuminate\Support\Facades\DB;
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
        $this->assertSame(10, $response->json('meta.max_image_mb'), 'the SPA reads the picture limit from the meta');
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

        $this->putProduct($this->org, $id, ['name' => 'Navy Polo'])->assertOk()->assertJsonPath('data.slug', 'school-polo');

        $this->assertSame('school-polo', Product::query()->findOrFail($id)->slug, 'a rename leaves the address the renderer links to');
    }

    // ------------------------------------------------------------ the currency

    #[Test]
    public function a_product_is_always_in_the_configured_currency_even_one_left_in_another_by_the_b1_default(): void
    {
        $legacy = $this->product($this->org, ['currency' => 'gbp']);

        $this->putProduct($this->org, $legacy->id, ['name' => 'Renamed'])
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

        $this->putProduct($this->org, $polo->id, ['base_price_minor' => 2800, 'active' => false])->assertOk();

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

        $response = $this->putProduct($this->org, $polo->id, [
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

        $this->putProduct($this->org, $polo->id, ['name' => 'Navy Polo'])->assertOk()->assertJsonCount(2, 'data.variants');
        $this->assertSame(2, ProductVariant::query()->where('product_id', $polo->id)->count());

        $this->putProduct($this->org, $polo->id, ['variants' => []])->assertOk()->assertJsonCount(0, 'data.variants');
        $this->assertSame(0, ProductVariant::query()->where('product_id', $polo->id)->count());
        $this->assertSame(2, ProductVariant::withoutMasjidScope()->withTrashed()->where('product_id', $polo->id)->count(), 'soft-deleted, not removed');
    }

    #[Test]
    public function a_label_freed_by_a_removed_size_can_be_taken_by_a_new_one_in_the_same_save(): void
    {
        $polo = $this->product($this->org);
        $old = $this->variant($polo, ['label' => 'M', 'stock' => 4, 'sold_count' => 4]);

        $new = $this->putProduct($this->org, $polo->id, ['variants' => [['label' => 'M', 'stock' => 12]]])
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

        $this->putProduct($this->org, $polo->id, ['variants' => [
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

        $variant = $this->putProduct($this->org, $polo->id, ['variants' => [['id' => $m->id, 'label' => 'M', 'stock' => 5]]])
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

        $response = $this->putProduct($this->org, $polo->id, ['variants' => [
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
            $this->putProduct($this->org, $polo->id, [
                'name' => 'Should not be saved',
                'variants' => [['id' => $id, 'label' => 'Z']],
            ])->assertNotFound();
        }

        $this->assertSame('School Polo', Product::query()->findOrFail($polo->id)->name, 'the whole save rolled back');
        $this->assertSame(['M'], ProductVariant::query()->where('product_id', $polo->id)->pluck('label')->all(), 'and no size was removed');
        $this->assertSame('M', ProductVariant::query()->findOrFail($theirs->id)->label);
    }

    // ------------------------------------------------------------ a stale editor is refused

    #[Test]
    public function every_product_answer_carries_the_lock_version_and_a_new_product_starts_at_zero(): void
    {
        $id = $this->postJson($this->products(), $this->polo())->assertCreated()->assertJsonPath('data.lock_version', 0)->json('data.id');

        $this->getJson($this->products('/' . $id))->assertOk()->assertJsonPath('data.lock_version', 0);
        $this->assertSame([0], array_column($this->getJson($this->products())->assertOk()->json('data.data'), 'lock_version'));

        $this->putProduct($this->org, $id, ['name' => 'Navy Polo'])->assertOk()->assertJsonPath('data.lock_version', 1);
        $this->getJson($this->products('/' . $id))->assertJsonPath('data.lock_version', 1);
        $this->assertSame([1], array_column($this->getJson($this->products())->json('data.data'), 'lock_version'));
    }

    #[Test]
    public function a_save_must_name_a_lock_version_and_a_missing_or_malformed_one_is_a_422(): void
    {
        $polo = $this->product($this->org);
        $this->variant($polo, ['label' => 'M']);

        foreach ([[], ['lock_version' => null], ['lock_version' => '0'], ['lock_version' => 0.5], ['lock_version' => -1], ['lock_version' => true], ['lock_version' => [0]]] as $extra) {
            $response = $this->putJson($this->products('/' . $polo->id), ['name' => 'Navy Polo'] + $extra)->assertStatus(422);

            $this->assertArrayHasKey('lock_version', $response->json('data'), json_encode($extra));
        }

        $this->assertSame('School Polo', Product::query()->findOrFail($polo->id)->name, 'nothing was saved');
        $this->assertSame(0, (int) Product::query()->findOrFail($polo->id)->lock_version);
    }

    #[Test]
    public function a_stale_editor_is_refused_with_a_409_and_the_other_editors_work_is_not_put_back(): void
    {
        $polo = $this->product($this->org);
        $s = $this->variant($polo, ['label' => 'S', 'stock' => 5]);
        $m = $this->variant($polo, ['label' => 'M', 'stock' => 5, 'sort' => 1]);

        // Editors A and B both open the product at version 0, with sizes S and M (M has 5).
        $opened = $this->getJson($this->products('/' . $polo->id))->assertOk()->json('data');
        $this->assertSame(0, $opened['lock_version']);

        // A adds XL and raises M's stock to 9: version 1.
        $this->putProduct($this->org, $polo->id, ['variants' => [
            ['id' => $s->id, 'label' => 'S', 'stock' => 5],
            ['id' => $m->id, 'label' => 'M', 'stock' => 9, 'sort' => 1],
            ['label' => 'XL', 'stock' => 3],
        ]], 0)->assertOk()->assertJsonPath('data.lock_version', 1);

        // B, still holding version 0 and a list without XL where M has 5, saves a rename of the product.
        $before = ProductVariant::withoutMasjidScope()->where('product_id', $polo->id)->orderBy('id')->get(['id', 'label', 'stock', 'deleted_at'])->toArray();

        $response = $this->putProduct($this->org, $polo->id, [
            'name' => 'B renamed it',
            'variants' => [
                ['id' => $s->id, 'label' => 'S', 'stock' => 5],
                ['id' => $m->id, 'label' => 'M', 'stock' => 5, 'sort' => 1],
            ],
        ], 0)->assertStatus(409);

        $this->assertSame(
            ['status' => 'failed', 'message' => 'This product was changed by someone else. Reload it and make your change again.'],
            $response->json(),
            'exactly this body'
        );

        // Nothing of B's was written: XL is still there, M still has 9, the name is as it was, the version too.
        $this->assertSame($before, ProductVariant::withoutMasjidScope()->where('product_id', $polo->id)->orderBy('id')->get(['id', 'label', 'stock', 'deleted_at'])->toArray());
        $this->assertSame(['S', 'M', 'XL'], ProductVariant::query()->where('product_id', $polo->id)->orderBy('id')->pluck('label')->all());
        $this->assertSame(9, (int) ProductVariant::query()->findOrFail($m->id)->stock, 'M\'s stock was not reverted');
        $this->assertSame('School Polo', Product::query()->findOrFail($polo->id)->name);
        $this->assertSame(1, (int) Product::query()->findOrFail($polo->id)->lock_version);

        // B reloads, sees A's work, and saves again at version 1.
        $reloaded = $this->getJson($this->products('/' . $polo->id))->assertOk()->json('data');
        $this->assertSame(1, $reloaded['lock_version']);
        $this->assertSame(['S', 'M', 'XL'], array_column($reloaded['variants'], 'label'));

        $this->putProduct($this->org, $polo->id, ['name' => 'B renamed it'], $reloaded['lock_version'])->assertOk()->assertJsonPath('data.lock_version', 2);
    }

    #[Test]
    public function every_successful_save_moves_the_version_on_by_one_and_a_refused_one_does_not(): void
    {
        $polo = $this->product($this->org);
        $m = $this->variant($polo, ['label' => 'M', 'stock' => 5]);

        $version = fn (): int => (int) Product::query()->findOrFail($polo->id)->lock_version;

        $this->assertSame(0, $version());

        // A product-fields-only save.
        $this->putProduct($this->org, $polo->id, ['category' => 'Uniforms'])->assertOk();
        $this->assertSame(1, $version());

        // A SIZES-ONLY save changes nothing on the product row itself, and still moves it on.
        $this->putProduct($this->org, $polo->id, ['variants' => [['id' => $m->id, 'label' => 'M', 'stock' => 6]]])->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->assertSame(2, $version());

        // Refused saves leave it where it was: a 422 (invalid), a 404 (a size that is not this product's), a 409 (stale).
        $this->putProduct($this->org, $polo->id, ['base_price_minor' => 0])->assertStatus(422);
        $this->putProduct($this->org, $polo->id, ['variants' => [['id' => 999999, 'label' => 'Z']]])->assertNotFound();
        $this->putProduct($this->org, $polo->id, ['name' => 'Stale'], 1)->assertStatus(409);
        $this->assertSame(2, $version());
        $this->assertSame('School Polo', Product::query()->findOrFail($polo->id)->name);
    }

    // ------------------------------------------------------------ locks, and sold_count never written

    #[Test]
    public function an_update_locks_the_product_then_the_named_sizes_by_primary_key_and_only_then_reads_the_sizes(): void
    {
        $polo = $this->product($this->org);
        $s = $this->variant($polo, ['label' => 'S']);
        $m = $this->variant($polo, ['label' => 'M']);

        DB::enableQueryLog();
        $this->putProduct($this->org, $polo->id, ['variants' => [
            ['id' => $m->id, 'label' => 'M', 'stock' => 4],
            ['id' => $s->id, 'label' => 'S'],
        ]])->assertOk();
        $statements = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $variantReads = array_values(array_filter($statements, static fn (string $q): bool => str_starts_with($q, 'select') && str_contains($q, 'from "product_variants"')));

        // The first read of the sizes is the PRIMARY-KEY lock of the rows' ids: `where id in (...) order by id`, and
        // no product_id or masjid_id range in it (a locking range read gap-locks under REPEATABLE READ).
        $this->assertGreaterThanOrEqual(2, count($variantReads));
        $this->assertStringContainsString('"id" in (', $variantReads[0]);
        $this->assertStringContainsString('order by "id" asc', $variantReads[0], 'ascending, the one order every locker uses');
        $this->assertStringNotContainsString('product_id', $variantReads[0]);
        $this->assertStringNotContainsString('masjid_id', $variantReads[0]);

        // Only THEN the product's live sizes, a plain read by product.
        $this->assertStringContainsString('"product_id" = ?', $variantReads[1]);

        // And the product's own row was locked before either (its select precedes the first read of the sizes).
        $first = null;
        foreach ($statements as $index => $statement) {
            if (str_contains($statement, 'from "product_variants"') && str_starts_with($statement, 'select')) {
                $first = $index;
                break;
            }
        }
        $productReads = array_keys(array_filter($statements, static fn (string $q): bool => str_starts_with($q, 'select') && str_contains($q, 'from "products"')));
        $this->assertGreaterThanOrEqual(2, count(array_filter($productReads, static fn (int $index): bool => $index < $first)), 'the route\'s own read of the product, then the writer\'s locking read of it');
    }

    #[Test]
    public function creating_a_product_with_sizes_takes_no_read_of_sizes_at_all(): void
    {
        DB::enableQueryLog();
        $this->postJson($this->products(), $this->polo(['variants' => [['label' => 'S'], ['label' => 'M']]]))->assertCreated();
        $statements = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $reads = array_filter($statements, static fn (string $q): bool => str_starts_with($q, 'select') && str_contains($q, 'from "product_variants"'));

        // The response re-reads the finished product's sizes AFTER the transaction; before it, a product made
        // in the transaction has no sizes to read or lock. So the only reads are those of the answer.
        $inserts = array_keys(array_filter($statements, static fn (string $q): bool => str_starts_with($q, 'insert into "product_variants"')));
        $this->assertCount(2, $inserts);

        foreach (array_keys($reads) as $index) {
            $this->assertGreaterThan(max($inserts), $index, 'no read of sizes before the sizes were written');
        }
    }

    #[Test]
    public function the_transactions_retry_a_deadlock_victim_three_times(): void
    {
        $reflection = new \ReflectionClass(\App\Services\Shop\ProductWriter::class);
        $this->assertSame(3, $reflection->getConstant('DEADLOCK_ATTEMPTS'));

        $source = (string) file_get_contents(app_path('Services/Shop/ProductWriter.php'));
        $this->assertSame(3, substr_count($source, 'self::DEADLOCK_ATTEMPTS);'), 'create, update and delete each retry');
    }

    #[Test]
    public function a_save_after_a_settlement_keeps_the_settled_sold_count_and_never_writes_one(): void
    {
        $polo = $this->product($this->org);
        $m = $this->variant($polo, ['label' => 'M', 'stock' => 20, 'sold_count' => 0]);

        // The editor loaded the size (sold_count 0)...
        $loaded = ProductVariant::query()->findOrFail($m->id);
        $this->assertSame(0, (int) $loaded->sold_count);

        // ...a settlement then moved it (what CartSettlementService::settleProduct does, under the size's lock)...
        ProductVariant::withoutMasjidScope()->whereKey($m->id)->update(['sold_count' => 7]);

        // ...and the save that follows must not put the 0 back, or write any sold_count at all.
        DB::enableQueryLog();
        $this->putProduct($this->org, $polo->id, ['variants' => [['id' => $m->id, 'label' => 'M', 'stock' => 25, 'enabled' => true, 'sort' => 3]]])
            ->assertOk()
            ->assertJsonPath('data.variants.0.sold_count', 7)
            ->assertJsonPath('data.variants.0.available', 18);
        $statements = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame(7, (int) ProductVariant::query()->findOrFail($m->id)->sold_count);

        $writes = array_filter($statements, static fn (string $q): bool => (str_starts_with($q, 'update') || str_starts_with($q, 'insert')) && str_contains($q, 'product_variants'));
        $this->assertNotEmpty($writes, 'premise: the size was written');

        foreach ($writes as $statement) {
            $this->assertStringNotContainsString('sold_count', $statement, 'the writer never writes sold_count');
        }
    }

    // ------------------------------------------------------------ a size's name is frozen once an order line names it

    /**
     * @return array<string, Closure(\App\Models\ProductVariant): void> what makes an order line name a size
     */
    private function namingOrders(): array
    {
        return [
            'an open payment page' => fn (ProductVariant $size) => $this->pendingOrderHolding($this->org, $size, 1),
            'a paid sale' => fn (ProductVariant $size) => $this->paidSale($this->org, $size),
            'an order that expired' => function (ProductVariant $size): void {
                $order = $this->pendingOrderHolding($this->org, $size, 1);
                Order::withoutMasjidScope()->whereKey($order->id)->update(['status' => Order::STATUS_EXPIRED]);
            },
        ];
    }

    #[Test]
    public function a_size_that_an_order_line_names_cannot_be_renamed_whatever_became_of_the_order(): void
    {
        foreach ($this->namingOrders() as $why => $make) {
            $polo = $this->product($this->org, ['name' => 'School Polo', 'slug' => 'polo-' . uniqid()]);
            $named = $this->variant($polo, ['label' => 'M', 'stock' => 5]);
            $make($named);

            $response = $this->putProduct($this->org, $polo->id, [
                'name' => 'Should not be saved',
                'variants' => [['id' => $named->id, 'label' => 'Medium', 'stock' => 99]],
            ])->assertStatus(422);

            $this->assertSame('failed', $response->json('status'), $why);
            $message = $response->json('data')['variants.0.label'][0] ?? '';
            $this->assertStringContainsString('"M"', $message, "{$why}: the sentence names the size");
            $this->assertStringContainsString('Switch it off and add a new size instead.', $message, $why);

            // The whole save was refused: the name, the stock and the label are all as they were.
            $this->assertSame('School Polo', Product::query()->findOrFail($polo->id)->name, "{$why}: the product was not saved");
            $fresh = ProductVariant::query()->findOrFail($named->id);
            $this->assertSame('M', $fresh->label, $why);
            $this->assertSame(5, (int) $fresh->stock, $why);
        }
    }

    #[Test]
    public function a_frozen_size_keeps_every_other_field_editable_and_can_still_be_removed_and_replaced(): void
    {
        $polo = $this->product($this->org);
        $named = $this->variant($polo, ['label' => 'M', 'stock' => 5, 'price_minor' => null, 'enabled' => true, 'sort' => 0]);
        $free = $this->variant($polo, ['label' => 'L']);
        $this->paidSale($this->org, $named);

        // Same label: price, stock, enabled and sort change. Another size no order names is renamed in the same save.
        $response = $this->putProduct($this->org, $polo->id, ['variants' => [
            ['id' => $named->id, 'label' => 'M', 'price_minor' => 2900, 'stock' => 40, 'enabled' => false, 'sort' => 7],
            ['id' => $free->id, 'label' => 'Large'],
        ]])->assertOk();

        $fresh = ProductVariant::query()->findOrFail($named->id);
        $this->assertSame(2900, (int) $fresh->price_minor);
        $this->assertSame(40, (int) $fresh->stock);
        $this->assertFalse((bool) $fresh->enabled);
        $this->assertSame(7, (int) $fresh->sort);
        $this->assertSame('M', $fresh->label);
        $this->assertSame('Large', ProductVariant::query()->findOrFail($free->id)->label, 'a size no order names renames freely');
        $this->assertContains('Large', array_column($response->json('data.variants'), 'label'));

        // The advice the 422 gives works: the size is switched off, and a new one is added under another name.
        $this->putProduct($this->org, $polo->id, ['variants' => [
            ['id' => $named->id, 'label' => 'M', 'enabled' => false],
            ['id' => $free->id, 'label' => 'Large'],
            ['label' => 'Medium'],
        ]])->assertOk()->assertJsonCount(3, 'data.variants');

        // And a named size may be removed outright (it is soft-deleted; its sale keeps its snapshot).
        $this->putProduct($this->org, $polo->id, ['variants' => [['id' => $free->id, 'label' => 'Large']]])->assertOk();
        $this->assertTrue(ProductVariant::withoutMasjidScope()->withTrashed()->findOrFail($named->id)->trashed());
    }

    #[Test]
    public function swapping_two_names_is_refused_when_either_size_is_named_by_an_order(): void
    {
        $polo = $this->product($this->org);
        $s = $this->variant($polo, ['label' => 'S']);
        $m = $this->variant($polo, ['label' => 'M']);
        $this->paidSale($this->org, $m);

        $response = $this->putProduct($this->org, $polo->id, ['variants' => [
            ['id' => $s->id, 'label' => 'M'],
            ['id' => $m->id, 'label' => 'S'],
        ]])->assertStatus(422);

        $this->assertArrayHasKey('variants.1.label', $response->json('data'), 'the size an order names is the one refused');
        $this->assertArrayNotHasKey('variants.0.label', $response->json('data'));
        $this->assertSame('S', ProductVariant::query()->findOrFail($s->id)->label);
        $this->assertSame('M', ProductVariant::query()->findOrFail($m->id)->label);
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
