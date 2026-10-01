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
use Illuminate\Support\Facades\DB;
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

        $response = $this->putProduct($this->org, $product->id, ['variants' => $variants])->assertStatus(422);

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
            $this->putProduct($this->org, $polo->id, ['base_price_minor' => $price])->assertStatus(422);
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

        $this->putProduct($this->org, $polo->id, ['variants' => [['label' => 'M', 'price_minor' => 1]]])->assertOk();
        $this->putProduct($this->org, $polo->id, ['variants' => [['label' => 'M', 'price_minor' => FormPayment::MAX_CHARGE_MINOR]]])->assertOk();
        $this->putProduct($this->org, $polo->id, ['variants' => [['label' => 'M', 'price_minor' => null]]])->assertOk()->assertJsonPath('data.variants.0.price_minor', null);
    }

    // ------------------------------------------------------------ the currency

    #[Test]
    public function a_request_naming_another_currency_is_refused(): void
    {
        foreach (['gbp', 'EUR', 'cad'] as $currency) {
            $this->assertStoreRefused($this->polo(['currency' => $currency]), 'currency', "currency {$currency}");
        }

        $polo = $this->product($this->org);

        $response = $this->putProduct($this->org, $polo->id, ['currency' => 'gbp', 'name' => 'Renamed'])->assertStatus(422);

        $this->assertSame('Products are sold in USD only.', $response->json('data.currency.0'));
        $this->assertSame('School Polo', Product::query()->findOrFail($polo->id)->name, 'nothing of the refused request was saved');
    }

    #[Test]
    public function a_currency_that_is_not_text_is_refused_not_a_server_error(): void
    {
        $this->assertStoreRefused($this->polo(['currency' => ['usd']]), 'currency', 'a currency that is a list');
        $this->assertStoreRefused($this->polo(['currency' => 840]), 'currency', 'a currency that is a number');
    }

    // ------------------------------------------------------------ the slug

    #[Test]
    public function a_slug_clash_is_judged_the_way_the_unique_index_judges_it(): void
    {
        // Production's MySQL compares live slugs case- and accent-blind: Polo and polo are one slug there.
        $slugs = [];
        foreach (['Polo', 'polo', 'POLO', 'Pólo'] as $name) {
            $slugs[] = $this->postJson($this->products(), $this->polo(['name' => $name]))->assertCreated()->json('data.slug');
        }
        $this->assertSame(['polo', 'polo-2', 'polo-3', 'polo-4'], $slugs, 'each name slugs to polo, and each clash gets the next suffix');

        // A row an import left with a capital letter still counts as taken: SQLite would let a second
        // `polo` through, MySQL would not, and the suffix has to decide it the same on both.
        $legacy = $this->product($this->org, ['name' => 'Legacy', 'slug' => 'Hoodie']);
        $this->assertSame('Hoodie', $legacy->slug, 'premise: the byte-exact SQLite index allows a capitalised slug');

        $this->postJson($this->products(), $this->polo(['name' => 'hoodie']))->assertCreated()->assertJsonPath('data.slug', 'hoodie-2');
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
        $this->putProduct($this->org, $polo->id, ['variants' => [['label' => 'M', 'stock' => 0]]])->assertOk()->assertJsonPath('data.variants.0.stock', 0)->assertJsonPath('data.variants.0.available', 0);
        $this->putProduct($this->org, $polo->id, ['variants' => [['label' => 'M', 'stock' => null]]])->assertOk()->assertJsonPath('data.variants.0.stock', null)->assertJsonPath('data.variants.0.available', null);
        $this->putProduct($this->org, $polo->id, ['variants' => [['label' => 'M', 'stock' => ProductFormRequest::STOCK_MAX]]])->assertOk();
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

        $this->putProduct($this->org, $polo->id, ['variants' => [['label' => str_repeat('x', 40)]]])->assertOk();
    }

    #[Test]
    public function two_labels_are_the_same_label_when_the_unique_index_would_say_so(): void
    {
        // Production's MySQL compares live labels under utf8mb4_unicode_ci, which ignores case AND accents;
        // SQLite is byte-exact. The check must refuse what the index would, so the office gets a sentence
        // and not a unique-violation 500.
        $pairs = [
            'M and m' => ['M', 'm'],
            'Medium and Medium with an accent' => ['Medium', 'Médium'],
            'Strasse and Strasse with an eszett' => ['Strasse', 'Straße'],
            'a case difference inside a longer label' => ['Adult L', 'ADULT l'],
            'N and N with a tilde' => ['N', 'Ñ'],
        ];

        foreach ($pairs as $why => [$first, $second]) {
            $this->assertStoreRefused($this->polo(['variants' => [['label' => $first], ['label' => $second]]]), 'variants.1.label', $why);
        }

        // On an update too, and the refusal leaves the product's sizes as they were.
        $polo = $this->product($this->org);
        $this->variant($polo, ['label' => 'Large']);
        $this->assertListRefused($polo, [['label' => 'Large'], ['label' => 'large']], 'variants.1.label', 'an update listing Large and large');

        // Different letters stay different, and a script that is not Latin is never merged by transliteration.
        $this->postJson($this->products(), $this->polo(['variants' => [['label' => 'M'], ['label' => 'N'], ['label' => 'كبير'], ['label' => 'صغير']]]))
            ->assertCreated()
            ->assertJsonCount(4, 'data.variants');
    }

    #[Test]
    public function labels_are_the_same_label_in_every_script_and_around_invisible_characters_and_spaces(): void
    {
        if (! class_exists(\Normalizer::class)) {
            $this->markTestSkipped('NFKD needs the intl extension (CI loads it); LiveTextTest covers the fallback.');
        }

        $pairs = [
            'Cyrillic E and E with a diaeresis' => ["\u{0415}", "\u{0401}"],
            'Arabic alef with hamza and plain alef' => ["\u{0623}\u{0637}\u{0641}\u{0627}\u{0644}", "\u{0627}\u{0637}\u{0641}\u{0627}\u{0644}"],
            'Arabic alef with madda and plain alef' => ["\u{0622}", "\u{0627}"],
            'XL and XL with a soft hyphen' => ['XL', "X\u{00AD}L"],
            'XL and XL with a zero width space' => ['XL', "X\u{200B}L"],
            'M and M with a trailing space' => ['M', 'M '],
            'M and M with a leading space' => [' M', 'M'],
        ];

        foreach ($pairs as $why => [$first, $second]) {
            $this->assertStoreRefused($this->polo(['variants' => [['label' => $first], ['label' => $second]]]), 'variants.1.label', $why);
        }

        // Kept: Medium and Medium with an accent are one label, and two different words are two.
        $this->assertStoreRefused($this->polo(['variants' => [['label' => 'Medium'], ['label' => "M\u{00E9}dium"]]]), 'variants.1.label', 'Medium and Medium with an accent');
        $this->postJson($this->products(), $this->polo(['variants' => [['label' => "\u{0643}\u{0628}\u{064A}\u{0631}"], ['label' => "\u{0635}\u{063A}\u{064A}\u{0631}"], ['label' => "\u{0415}"], ['label' => 'E']]]))
            ->assertCreated()
            ->assertJsonCount(4, 'data.variants');
    }

    // ------------------------------------------------------------ the backstop: a violation nobody checked for

    #[Test]
    public function a_label_clash_nothing_checked_for_is_a_clean_422_on_a_save_and_on_a_create(): void
    {
        // A competing save commits the same live label between our reads and our write: a trigger plays it by
        // inserting the winner just before the real insert, which the unique index then refuses.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER raced_label BEFORE INSERT ON product_variants
            WHEN NEW.label = 'Raced' AND NOT EXISTS (SELECT 1 FROM product_variants WHERE product_id = NEW.product_id AND label = 'Raced' AND deleted_at IS NULL)
            BEGIN
                INSERT INTO product_variants (masjid_id, product_id, label, enabled, sold_count, sort) VALUES (NEW.masjid_id, NEW.product_id, 'Raced', 1, 0, 0);
            END
        SQL);

        $polo = $this->product($this->org);

        $update = $this->putProduct($this->org, $polo->id, ['name' => 'Not saved', 'variants' => [['label' => 'Raced']]])->assertStatus(422);

        $this->assertSame('failed', $update->json('status'));
        $this->assertSame(['Two sizes of one product cannot share a name.'], $update->json('data')['variants']);
        $this->assertSame(0, ProductVariant::withoutMasjidScope()->withTrashed()->where('product_id', $polo->id)->count(), 'nothing of the refused save stayed');
        $this->assertSame('School Polo', Product::query()->findOrFail($polo->id)->name);
        $this->assertSame(0, (int) Product::query()->findOrFail($polo->id)->lock_version, 'and the version did not move');

        $products = Product::withoutMasjidScope()->count();
        $create = $this->postJson($this->products(), $this->polo(['name' => 'Raced Polo', 'variants' => [['label' => 'Raced']]]))->assertStatus(422);

        $this->assertSame(['Two sizes of one product cannot share a name.'], $create->json('data')['variants']);
        $this->assertSame($products, Product::withoutMasjidScope()->count(), 'the product made in the same transaction was rolled back');
    }

    #[Test]
    public function a_slug_taken_five_times_over_is_a_sentence_on_the_name_after_five_tries_not_a_500(): void
    {
        // Every attempt meets a winner that took the slug a moment before: the writer retries, and gives up politely.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER raced_slug BEFORE INSERT ON products
            WHEN NEW.slug = 'race-slug'
            BEGIN
                INSERT INTO products (masjid_id, name, slug, base_price_minor, currency, active, sort) VALUES (NEW.masjid_id, 'Race winner', 'race-slug', 100, 'usd', 1, 0);
            END
        SQL);

        DB::enableQueryLog();
        $response = $this->postJson($this->products(), $this->polo(['name' => 'Race Slug']))->assertStatus(422);
        $attempts = count(array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $q): bool => str_starts_with($q, 'insert into "products"')));
        DB::disableQueryLog();

        $this->assertSame(5, $attempts, 'five tries, no more');
        $this->assertSame(['Another product took that name just now. Try saving again.'], $response->json('data')['name']);
        $this->assertSame(0, Product::withoutMasjidScope()->where('slug', 'race-slug')->count());
    }

    #[Test]
    public function a_new_size_takes_the_place_in_the_list_the_caller_gave_it(): void
    {
        $polo = $this->product($this->org);
        $s = $this->variant($polo, ['label' => 'S', 'sort' => 0]);
        $l = $this->variant($polo, ['label' => 'L', 'sort' => 2]);

        // The new size sits between the two that exist, with no `sort` of its own: its place is its position.
        $response = $this->putProduct($this->org, $polo->id, ['variants' => [
            ['id' => $s->id, 'label' => 'S'],
            ['label' => 'M'],
            ['id' => $l->id, 'label' => 'L'],
        ]])->assertOk();

        $this->assertSame(['S', 'M', 'L'], array_column($response->json('data.variants'), 'label'));
        $this->assertSame([0, 1, 2], array_column($response->json('data.variants'), 'sort'));
    }

    #[Test]
    public function a_size_id_that_is_not_a_number_is_refused_on_the_id(): void
    {
        $polo = $this->product($this->org);
        $this->variant($polo, ['label' => 'M']);

        foreach (['abc', 1.5, true, [1], 0, -3] as $id) {
            $this->assertListRefused($polo, [['id' => $id, 'label' => 'M']], 'variants.0.id', 'a size id of ' . json_encode($id));
        }
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
