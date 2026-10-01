<?php

namespace Tests\Feature\Shop;

use App\Http\Requests\Admin\Shop\ProductFormRequest;
use App\Models\Masjid;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Shop\ProductSlug;
use App\Support\FormPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * Shop slice B2: what the product boundary REFUSES, one rule per test, each refusal leaving the
 * catalogue exactly as it was. The money rules are the ones that matter most: a price is an integer
 * number of minor units, at least 1, and at most the most one card payment can take; the currency
 * is the platform's; a stock is a whole number that is never negative.
 *
 * The answer is the app's 422 envelope, `{status: 'failed', data: {field: [messages]}}`, with the
 * dotted field name as one flat key (`variants.0.stock`).
 */
class ShopProductValidationTest extends TestCase
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

    /**
     * POST a product that must be refused on `$field`, and show that nothing was written.
     *
     * @param  array<string,mixed>  $body
     */
    private function assertStoreRefused(array $body, string $field, string $why): void
    {
        $before = Product::withoutMasjidScope()->withTrashed()->count();

        $response = $this->postJson($this->products(), $body)->assertStatus(422);

        $this->assertSame('failed', $response->json('status'));
        $this->assertArrayHasKey($field, $response->json('data'), "{$why}: expected the refusal on {$field}, got " . json_encode($response->json('data')));
        $this->assertSame($before, Product::withoutMasjidScope()->withTrashed()->count(), "{$why}: a product was written anyway");
    }

    /**
     * PUT a list of sizes that must be refused on `$field`, and show that the product's sizes are as they were.
     *
     * @param  list<array<string,mixed>>  $variants
     */
    private function assertListRefused(Product $product, array $variants, string $field, string $why): void
    {
        $before = ProductVariant::withoutMasjidScope()->withTrashed()->where('product_id', $product->id)->orderBy('id')->get(['id', 'label', 'stock', 'price_minor', 'deleted_at'])->toArray();

        $response = $this->putJson($this->products('/' . $product->id), ['variants' => $variants])->assertStatus(422);

        $this->assertArrayHasKey($field, $response->json('data'), "{$why}: expected the refusal on {$field}, got " . json_encode($response->json('data')));
        $this->assertSame(
            $before,
            ProductVariant::withoutMasjidScope()->withTrashed()->where('product_id', $product->id)->orderBy('id')->get(['id', 'label', 'stock', 'price_minor', 'deleted_at'])->toArray(),
            "{$why}: the sizes changed anyway"
        );
    }

    // ------------------------------------------------------------ the price

    #[Test]
    public function a_product_price_is_a_whole_number_of_minor_units_from_one_to_the_most_a_card_payment_takes(): void
    {
        $ceiling = FormPayment::MAX_CHARGE_MINOR;

        $refused = [
            'zero' => 0,
            'negative' => -1,
            'one over the ceiling' => $ceiling + 1,
            'a float' => 25.5,
            'a numeric string' => '2500',
            'text' => 'abc',
            'a boolean' => true,
            'nothing' => null,
        ];

        foreach ($refused as $why => $price) {
            $this->assertStoreRefused($this->polo(['base_price_minor' => $price]), 'base_price_minor', "a price of {$why}");
        }

        // The edges are in.
        $this->postJson($this->products(), $this->polo(['base_price_minor' => 1]))->assertCreated();
        $this->postJson($this->products(), $this->polo(['base_price_minor' => $ceiling]))->assertCreated()->assertJsonPath('data.base_price_minor', $ceiling);
    }

    #[Test]
    public function an_update_holds_a_price_to_the_same_bounds(): void
    {
        $polo = $this->product($this->org, ['base_price_minor' => 2500]);

        foreach ([0, -5, FormPayment::MAX_CHARGE_MINOR + 1, 25.5, '2500'] as $price) {
            $this->putJson($this->products('/' . $polo->id), ['base_price_minor' => $price])->assertStatus(422);
        }

        $this->assertSame(2500, (int) Product::query()->findOrFail($polo->id)->base_price_minor);
    }

    #[Test]
    public function a_size_price_is_held_to_the_same_bounds_and_may_be_left_out_or_null_for_the_products_price(): void
    {
        $polo = $this->product($this->org);
        $this->variant($polo, ['label' => 'M']);

        foreach ([0, -1, FormPayment::MAX_CHARGE_MINOR + 1, 25.5, '2000', false] as $price) {
            $this->assertListRefused($polo, [['label' => 'M', 'price_minor' => $price]], 'variants.0.price_minor', 'a size price of ' . json_encode($price));
        }

        $this->putJson($this->products('/' . $polo->id), ['variants' => [['label' => 'M', 'price_minor' => 1]]])->assertOk();
        $this->putJson($this->products('/' . $polo->id), ['variants' => [['label' => 'M', 'price_minor' => FormPayment::MAX_CHARGE_MINOR]]])->assertOk();
        $this->putJson($this->products('/' . $polo->id), ['variants' => [['label' => 'M', 'price_minor' => null]]])->assertOk()->assertJsonPath('data.variants.0.price_minor', null);
    }

    // ------------------------------------------------------------ the currency

    #[Test]
    public function a_request_naming_another_currency_is_refused(): void
    {
        foreach (['gbp', 'EUR', 'cad'] as $currency) {
            $this->assertStoreRefused($this->polo(['currency' => $currency]), 'currency', "currency {$currency}");
        }

        $polo = $this->product($this->org);

        $response = $this->putJson($this->products('/' . $polo->id), ['currency' => 'gbp', 'name' => 'Renamed'])->assertStatus(422);

        $this->assertSame('Products are sold in USD only.', $response->json('data.currency.0'));
        $this->assertSame('School Polo', Product::query()->findOrFail($polo->id)->name, 'nothing of the refused request was saved');
    }

    // ------------------------------------------------------------ stock

    #[Test]
    public function a_stock_is_a_whole_number_that_is_never_negative_and_may_be_null_for_unlimited(): void
    {
        $polo = $this->product($this->org);
        $this->variant($polo, ['label' => 'M', 'stock' => 5]);

        foreach (['negative' => -1, 'a float' => 1.5, 'a numeric string' => '3', 'text' => 'many', 'too big' => 4294967296] as $why => $stock) {
            $this->assertListRefused($polo, [['label' => 'M', 'stock' => $stock]], 'variants.0.stock', "a stock that is {$why}");
        }

        // Zero is a real stock (sold out on purpose), null is unlimited, and the unsigned-int edge fits.
        $this->putJson($this->products('/' . $polo->id), ['variants' => [['label' => 'M', 'stock' => 0]]])->assertOk()->assertJsonPath('data.variants.0.stock', 0)->assertJsonPath('data.variants.0.available', 0);
        $this->putJson($this->products('/' . $polo->id), ['variants' => [['label' => 'M', 'stock' => null]]])->assertOk()->assertJsonPath('data.variants.0.stock', null)->assertJsonPath('data.variants.0.available', null);
        $this->putJson($this->products('/' . $polo->id), ['variants' => [['label' => 'M', 'stock' => ProductFormRequest::STOCK_MAX]]])->assertOk();
    }

    // ------------------------------------------------------------ sizes

    #[Test]
    public function a_size_needs_a_label_of_at_most_forty_characters_and_two_sizes_cannot_share_one(): void
    {
        $polo = $this->product($this->org);
        $this->variant($polo, ['label' => 'M']);

        $this->assertListRefused($polo, [['stock' => 3]], 'variants.0.label', 'a size with no label');
        $this->assertListRefused($polo, [['label' => '']], 'variants.0.label', 'a size with an empty label');
        $this->assertListRefused($polo, [['label' => '   ']], 'variants.0.label', 'a size with a blank label');
        $this->assertListRefused($polo, [['label' => str_repeat('x', 41)]], 'variants.0.label', 'a 41 character label');
        $this->assertListRefused($polo, [['label' => ['M']]], 'variants.0.label', 'a label that is a list');
        $this->assertListRefused($polo, [['label' => 'M'], ['label' => 'M']], 'variants.1.label', 'the same label twice');
        $this->assertListRefused($polo, [['label' => 'Adult L'], ['label' => 'adult l']], 'variants.1.label', 'the same label in another case');

        $this->putJson($this->products('/' . $polo->id), ['variants' => [['label' => str_repeat('x', 40)]]])->assertOk();
    }

    #[Test]
    public function a_size_listed_twice_by_id_and_an_id_on_a_new_product_are_refused(): void
    {
        $polo = $this->product($this->org);
        $m = $this->variant($polo, ['label' => 'M']);

        $this->assertListRefused($polo, [['id' => $m->id, 'label' => 'M'], ['id' => $m->id, 'label' => 'L']], 'variants.1.id', 'one size twice');

        $this->assertStoreRefused($this->polo(['variants' => [['id' => $m->id, 'label' => 'M']]]), 'variants.0.id', 'an id on a product that has no sizes yet');
    }

    #[Test]
    public function the_sizes_of_one_request_are_capped(): void
    {
        $rows = [];
        for ($i = 1; $i <= ProductFormRequest::MAX_VARIANTS + 1; $i++) {
            $rows[] = ['label' => "S{$i}"];
        }

        $this->assertStoreRefused($this->polo(['variants' => $rows]), 'variants', 'more sizes than a request may carry');
    }

    // ------------------------------------------------------------ names and widths

    #[Test]
    public function a_product_needs_a_name_and_the_text_fields_stop_at_their_columns_widths(): void
    {
        $this->assertStoreRefused(['base_price_minor' => 2500], 'name', 'no name');
        $this->assertStoreRefused($this->polo(['name' => '']), 'name', 'an empty name');
        $this->assertStoreRefused($this->polo(['name' => str_repeat('a', ProductFormRequest::NAME_MAX + 1)]), 'name', 'a 121 character name');
        $this->assertStoreRefused($this->polo(['category' => str_repeat('a', ProductFormRequest::CATEGORY_MAX + 1)]), 'category', 'a 61 character category');
        $this->assertStoreRefused($this->polo(['description' => str_repeat('a', ProductFormRequest::DESCRIPTION_MAX + 1)]), 'description', 'a description over the cap');
        $this->assertStoreRefused($this->polo(['sort' => 'first']), 'sort', 'a sort that is not a number');
        $this->assertStoreRefused($this->polo(['active' => 'sometimes']), 'active', 'an active that is not a boolean');

        $this->postJson($this->products(), $this->polo([
            'name' => str_repeat('a', ProductFormRequest::NAME_MAX),
            'category' => str_repeat('b', ProductFormRequest::CATEGORY_MAX),
        ]))->assertCreated();
    }

    #[Test]
    public function the_request_widths_are_the_columns_widths(): void
    {
        // SQLite ignores a varchar's length and MySQL (strict) refuses a longer value with a 500: so
        // the two numbers are compared here, from the migration's own source.
        $source = (string) file_get_contents(database_path('migrations/2026_10_06_100000_create_shop_tables.php'));

        $width = function (string $table, string $column) use ($source): int {
            $pattern = '/Schema::create\(\'' . $table . '\'.*?\$table->string\(\'' . $column . '\',\s*(\d+)\)/s';
            $this->assertSame(1, preg_match($pattern, $source, $found), "{$table}.{$column} was not found in the migration");

            return (int) $found[1];
        };

        $this->assertSame($width('products', 'name'), ProductFormRequest::NAME_MAX);
        $this->assertSame($width('products', 'category'), ProductFormRequest::CATEGORY_MAX);
        $this->assertSame($width('product_variants', 'label'), ProductFormRequest::LABEL_MAX);
        $this->assertSame($width('products', 'slug'), ProductSlug::MAX_LENGTH);
    }

    #[Test]
    public function a_longest_name_still_gets_a_slug_that_fits_even_with_a_suffix(): void
    {
        // 120 accented characters slug to 120 ASCII ones; a clash then adds "-2" and "-3".
        $name = str_repeat('é', ProductFormRequest::NAME_MAX);

        $slugs = [];
        foreach ([1, 2, 3] as $_) {
            $slugs[] = $this->postJson($this->products(), $this->polo(['name' => $name]))->assertCreated()->json('data.slug');
        }

        $this->assertSame([str_repeat('e', 120), str_repeat('e', 120) . '-2', str_repeat('e', 120) . '-3'], $slugs);

        foreach ($slugs as $slug) {
            $this->assertLessThanOrEqual(ProductSlug::MAX_LENGTH, mb_strlen($slug));
        }
    }

    #[Test]
    public function a_name_that_slugs_to_nothing_still_gets_a_handle(): void
    {
        $this->postJson($this->products(), $this->polo(['name' => '!!!']))->assertCreated()->assertJsonPath('data.slug', 'product');
        $this->postJson($this->products(), $this->polo(['name' => '???']))->assertCreated()->assertJsonPath('data.slug', 'product-2');
    }
}
