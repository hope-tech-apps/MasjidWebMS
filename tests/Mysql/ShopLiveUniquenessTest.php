<?php

/*
|--------------------------------------------------------------------------
| The shop's "unique among LIVE rows", on the engine production runs
|--------------------------------------------------------------------------
|
| A product's slug is unique per organisation, and a size's label per product, only while the
| row is not soft-deleted. SQLite has partial indexes, so the ordinary suite
| (tests/Feature/Shop/ShopSchemaTest.php) proves the rule there. MySQL has none: the migration
| gives it a VIRTUAL generated column that collapses a trashed row to NULL (`live_slug`,
| `live_label`) and a plain UNIQUE index on it, and nothing but a run against MySQL shows that
| the statements are accepted, that the column really is generated, and that the rule holds in
| every direction (a duplicate refused, a trashed row freeing its slug, a restore into a clash
| refused). Runs only in CI's migrations-mysql job, as `pest --group=mysql`.
*/

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Shop\BuildsShop;

uses(BuildsBaskets::class, BuildsShop::class);

it('backs the live-slug and live-label rules with generated columns and unique indexes', function () {
    $cases = [
        ['products', 'live_slug', 'products_live_slug_unique', ['masjid_id', 'live_slug']],
        ['product_variants', 'live_label', 'product_variants_live_label_unique', ['product_id', 'live_label']],
    ];

    foreach ($cases as [$table, $column, $index, $columns]) {
        $extra = DB::selectOne(
            'SELECT EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->extra;

        expect($extra)->toContain('GENERATED');

        $rows = DB::select(
            'SELECT COLUMN_NAME AS col, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$table, $index]
        );

        expect(array_map(fn ($row) => $row->col, $rows))->toBe($columns)
            ->and((int) $rows[0]->non_unique)->toBe(0);
    }
});

it('refuses a second live slug in one organisation, admits it in another, and frees it when the first is trashed', function () {
    $a = $this->org();
    $b = $this->org();
    $first = $this->product($a, ['slug' => 'school-polo']);

    $this->product($b, ['slug' => 'school-polo']);

    expect(fn () => $this->product($a, ['slug' => 'school-polo', 'name' => 'Second']))->toThrow(QueryException::class);

    $first->delete();
    $second = $this->product($a, ['slug' => 'school-polo', 'name' => 'Second']);
    $second->delete();
    $third = $this->product($a, ['slug' => 'school-polo', 'name' => 'Third']);

    expect(Product::onlyTrashed()->where('masjid_id', $a->id)->where('slug', 'school-polo')->count())->toBe(2);

    // Restoring into a clash is refused: the rule holds in both directions.
    expect(fn () => $first->restore())->toThrow(QueryException::class);
    expect($third->fresh()->deleted_at)->toBeNull();
});

it('refuses a second live size label on one product, and frees it when the first is trashed', function () {
    $org = $this->org();
    $polo = $this->product($org);
    $hoodie = $this->product($org, ['name' => 'Hoodie']);
    $medium = $this->variant($polo, ['label' => 'M']);

    $this->variant($hoodie, ['label' => 'M']);

    expect(fn () => $this->variant($polo, ['label' => 'M']))->toThrow(QueryException::class);

    $medium->delete();
    $again = $this->variant($polo, ['label' => 'M']);

    expect(fn () => $medium->restore())->toThrow(QueryException::class);
    expect(ProductVariant::query()->where('product_id', $polo->id)->where('label', 'M')->count())->toBe(1)
        ->and($again->fresh()->deleted_at)->toBeNull();
});

it('keeps the generated columns out of a serialised row, as on SQLite', function () {
    $variant = $this->sizeOf($this->org());

    expect(array_key_exists('live_slug', Product::query()->findOrFail($variant->product_id)->toArray()))->toBeFalse()
        ->and(array_key_exists('live_label', $variant->fresh()->toArray()))->toBeFalse();
});
