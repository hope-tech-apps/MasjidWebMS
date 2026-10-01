<?php

namespace App\Services\Shop;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything the admin API writes to the catalogue: a product, its sizes as a list, and its
 * removal (shop slice B2). The controllers validate; this decides what is stored.
 *
 * ## What no request can set
 *
 * `masjid_id` (stamped by BelongsToMasjid from the bound tenant), `slug` after creation,
 * `sold_count` (it moves only at settlement, under the size's row lock: CartSettlementService::
 * settleProduct) and `product_id` of a size (the route's product). Every attribute list below is
 * built field by field, because `Product` and `ProductVariant` list those columns as fillable.
 *
 * ## The currency is the platform's
 *
 * `products.currency` is ALWAYS `config('services.stripe.currency')`, written on every create and
 * every update. The request refuses another currency by name (StoreProductRequest), and a product
 * left in another one by the B1 default is put right by its next save: this closes ASSUMPTIONS S-9.
 *
 * ## A product's sizes are a list
 *
 * `$variants === null` leaves the sizes alone. A list is the product's whole set of sizes: a row
 * with an `id` edits that size, a row without one adds a size, and a live size left out is
 * soft-deleted (its `sold_count` and its sales stay where they are; a sale carries its own
 * snapshot). An `id` that is not one of THIS product's live sizes (another organisation's, another
 * product's, a trashed one) is a 404 like any other id, and nothing is saved.
 *
 * ## A size's name is frozen once an order line names it
 *
 * Once ANY `order_items` row names a size (an open payment page, a paid sale, whatever became of the
 * order) its label cannot change: a rename would change what that line is called while its sale's
 * snapshot keeps the old name, and the catalogue would disagree with the order it sold. Such a rename
 * is a 422 on `variants.N.label` ("switch the size off and add a new one") and the whole save is
 * refused; the size's price, stock, `enabled` and `sort` stay editable, and the size may still be
 * removed. The check is made under the sizes' row locks, which a checkout also takes before it
 * writes a line, so a line written a moment ago is seen.
 *
 * The three steps run in a fixed order because the unique index on a size's label is among LIVE
 * rows: omitted sizes go first (freeing their labels), sizes whose label changes then step aside
 * under a throwaway label (so S -> M together with M -> S, or a rotation, never meets the index
 * half-way), and only then are the new labels and the new rows written.
 */
final class ProductWriter
{
    /** Tries at a slug that another save took between the read and the insert. */
    private const SLUG_ATTEMPTS = 5;

    /**
     * @param  array<string,mixed>  $attributes  name, category, description, base_price_minor, active, sort
     * @param  list<array<string,mixed>>|null  $variants
     */
    public function create(int $masjidId, array $attributes, ?array $variants): Product
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($masjidId, $attributes, $variants): Product {
                    $product = Product::create([
                        'name' => (string) $attributes['name'],
                        'slug' => ProductSlug::unique((string) $attributes['name'], $masjidId),
                        'category' => $attributes['category'] ?? null,
                        'description' => $attributes['description'] ?? null,
                        'base_price_minor' => (int) $attributes['base_price_minor'],
                        'currency' => self::currency(),
                        'active' => array_key_exists('active', $attributes) ? self::bool($attributes['active']) : true,
                        'sort' => array_key_exists('sort', $attributes) ? (int) $attributes['sort'] : 0,
                    ]);

                    if ($variants !== null) {
                        $this->syncVariants($product, $variants);
                    }

                    return $product;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Only the slug is retried: a size label cannot clash here (the request has already
                // refused a list that repeats one), so anything else is a real failure.
                if ($attempt >= self::SLUG_ATTEMPTS || ! str_contains($e->getMessage(), 'slug')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $attributes  any of name, category, description, base_price_minor, active, sort
     * @param  list<array<string,mixed>>|null  $variants
     */
    public function update(Product $product, array $attributes, ?array $variants): Product
    {
        return DB::transaction(function () use ($product, $attributes, $variants): Product {
            // Two saves of one product take turns: the sizes are rewritten as a list.
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $changes = Arr::only($attributes, ['name', 'category', 'description', 'base_price_minor', 'active', 'sort']);

            if (array_key_exists('base_price_minor', $changes)) {
                $changes['base_price_minor'] = (int) $changes['base_price_minor'];
            }

            if (array_key_exists('active', $changes)) {
                $changes['active'] = self::bool($changes['active']);
            }

            if (array_key_exists('sort', $changes)) {
                $changes['sort'] = (int) $changes['sort'];
            }

            // Always the configured currency, whatever the row held (ASSUMPTIONS S-9).
            $locked->fill($changes);
            $locked->currency = self::currency();
            $locked->save();

            if ($variants !== null) {
                $this->syncVariants($locked, $variants);
            }

            return $locked;
        });
    }

    /**
     * Soft-delete a product and its sizes. A paid sale keeps its own snapshot (name, size, price)
     * and its order, so the pickup list and the order still read; a basket line that names one of
     * these sizes prices as `gone` from now on, and a pending page that already holds it is still
     * settled (settlement reads sizes withTrashed).
     */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $locked->variants()->delete();
            $locked->delete();
        });
    }

    /** The one currency every product is sold in, lower case as the column stores it. */
    public static function currency(): string
    {
        return strtolower((string) config('services.stripe.currency', 'usd'));
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function syncVariants(Product $product, array $rows): void
    {
        // A JSON object would reach here with string keys; the position is the row's place in the list.
        $rows = array_values($rows);

        /** @var Collection<int,ProductVariant> $existing  this product's live sizes, by id, locked */
        $existing = $product->variants()->lockForUpdate()->get()->keyBy('id');

        $kept = [];

        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;

            if ($id === null) {
                continue;
            }

            if (! $existing->has($id)) {
                throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$id]);
            }

            $kept[$id] = true;
        }

        // A size an order line names keeps its name. Refused before anything is written.
        $this->refuseRenamedSizesInOrders($product, $existing, $rows);

        // 1. A size left out of the list is soft-deleted, before anything claims its label.
        foreach ($existing as $id => $variant) {
            if (! isset($kept[$id])) {
                $variant->delete();
            }
        }

        // 2. A size whose label changes steps aside under a throwaway label, so a swap or a
        //    rotation cannot meet the unique index half-way. The id makes it unique, the random
        //    tail keeps it from ever being a label somebody typed.
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;

            if ($id !== null && $existing[$id]->label !== (string) $row['label']) {
                ProductVariant::query()->whereKey($id)->update(['label' => '~' . $id . '~' . Str::random(8)]);
            }
        }

        // 3. Write the rows: existing sizes get what the row says, new ones are created.
        foreach ($rows as $position => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;

            if ($id !== null) {
                $variant = $existing[$id];
                $variant->fill($this->variantAttributes($row, false, $position));
                $variant->save();

                continue;
            }

            // The relation sets `product_id`; BelongsToMasjid stamps `masjid_id`; `sold_count`
            // starts at the column's 0 and is nobody's to set.
            $product->variants()->create($this->variantAttributes($row, true, $position));
        }
    }

    /**
     * @param  Collection<int,ProductVariant>  $existing  this product's live sizes, by id
     * @param  list<array<string,mixed>>  $rows
     *
     * @throws ValidationException when a size named by an order line is given another label
     */
    private function refuseRenamedSizesInOrders(Product $product, Collection $existing, array $rows): void
    {
        $renamed = [];

        foreach ($rows as $position => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;

            if ($id !== null && $existing[$id]->label !== (string) $row['label']) {
                $renamed[$position] = $id;
            }
        }

        if ($renamed === []) {
            return;
        }

        $named = DB::table('order_items')
            ->where('masjid_id', (int) $product->masjid_id)
            ->where('buyable_type', CartItem::TYPE_PRODUCT)
            ->whereIn('buyable_id', array_values($renamed))
            ->distinct()
            ->pluck('buyable_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();

        $errors = [];

        foreach ($renamed as $position => $id) {
            if ($named->has($id)) {
                $errors["variants.{$position}.label"] = [
                    'The size "' . $existing[$id]->label . '" is already in a basket or an order, so its name cannot change. '
                    . 'Switch it off and add a new size instead.',
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The columns a row of the list may set. A key the row does not carry leaves an existing size
     * as it was (a new size takes the defaults: enabled, the product's price, unlimited stock, its
     * place in the list), and a key the row carries as null clears it: no price of its own, or
     * unlimited stock.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function variantAttributes(array $row, bool $creating, int $position): array
    {
        $attributes = ['label' => (string) $row['label']];

        if (array_key_exists('enabled', $row)) {
            $attributes['enabled'] = self::bool($row['enabled']);
        } elseif ($creating) {
            $attributes['enabled'] = true;
        }

        if (array_key_exists('price_minor', $row)) {
            $attributes['price_minor'] = $row['price_minor'] === null ? null : (int) $row['price_minor'];
        }

        if (array_key_exists('stock', $row)) {
            $attributes['stock'] = $row['stock'] === null ? null : (int) $row['stock'];
        }

        if (array_key_exists('sort', $row)) {
            $attributes['sort'] = (int) $row['sort'];
        } elseif ($creating) {
            $attributes['sort'] = $position;
        }

        return $attributes;
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
