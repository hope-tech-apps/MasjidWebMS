<?php

namespace App\Services\Shop;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\ProductStock;
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
 *
 * ## A stale editor is refused
 *
 * `products.lock_version` goes up by one, under the product's row lock, with every successful update
 * (a sizes-only save included) and with every picture change (ShopProductImagesController). An update
 * names the version it read; under the lock a different one is ProductChangedElsewhere (a 409) and
 * nothing is written, so a screen opened before another editor's save cannot put back what they
 * changed (a removed size, a corrected stock).
 *
 * ## Locks, in this order, and why
 *
 * The product row is locked FIRST (FOR UPDATE, by primary key plus the organisation the scope
 * adds): it serialises every writer of the product and its sizes, so two saves of one product take
 * turns. Then, for an update that carries a list, the sizes the REQUEST ROWS name are locked by
 * PRIMARY KEY alone, ascending, through ProductStock::lock(), the lock a checkout takes before it
 * writes an order line. Only THEN are the product's live sizes and the order lines naming them read,
 * with PLAIN reads: after those locks they see every line a competing checkout committed, so a size
 * whose name is about to change is always one of the request rows and its lines are visible.
 * Nothing in the transaction reads before the first lock: the route binds the product in the
 * controller, before the transaction, in autocommit, so under REPEATABLE READ the first consistent
 * read of the transaction is after the locks, and it fixes no stale snapshot. A locking read of
 * `product_variants` by `product_id` or `masjid_id` is NEVER used (under REPEATABLE READ it takes gap
 * locks, B1's ship blocker, DECISIONS.md 2026-10-01). A product created in this transaction has no
 * sizes, so create() takes no locking read of sizes at all. Every transaction here retries a
 * deadlock victim up to three times: there is no Stripe call, no mail and no file in any of them.
 */
final class ProductWriter
{
    /** Tries at a slug that another save took between the read and the insert. */
    private const SLUG_ATTEMPTS = 5;

    /** Tries at a transaction that InnoDB chose as a deadlock victim. */
    private const DEADLOCK_ATTEMPTS = 3;

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
                        // A product made in this transaction has no sizes: nothing to read, nothing to lock.
                        $this->syncVariants($product, array_values($variants), new Collection);
                    }

                    return $product;
                }, self::DEADLOCK_ATTEMPTS);
            } catch (UniqueConstraintViolationException $e) {
                // The backstop for a clash the request's own check missed (a race, or a collation
                // equivalence LiveText does not know): two sizes of one product are a 422; a slug
                // another save took first is retried with the next suffix.
                $index = self::clashingIndex($e);

                if ($index === 'label') {
                    throw self::twoSizesClash();
                }

                if ($index !== 'slug') {
                    throw $e;
                }

                // Five tries at the next free suffix: more than that is a name other saves keep taking
                // as fast as it is read, and the answer is a sentence, not a 500.
                if ($attempt >= self::SLUG_ATTEMPTS) {
                    throw ValidationException::withMessages(['name' => ['Another product took that name just now. Try saving again.']]);
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $attributes  any of name, category, description, base_price_minor, active, sort
     * @param  list<array<string,mixed>>|null  $variants
     * @param  int  $expectedVersion  the `lock_version` the editor read
     *
     * @throws ProductChangedElsewhere when the product is no longer at that version
     */
    public function update(Product $product, array $attributes, ?array $variants, int $expectedVersion): Product
    {
        try {
            return DB::transaction(function () use ($product, $attributes, $variants, $expectedVersion): Product {
                // Two saves of one product take turns: the sizes are rewritten as a list.
                $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

                if ((int) $locked->lock_version !== $expectedVersion) {
                    throw new ProductChangedElsewhere;
                }

                $rows = $variants === null ? null : array_values($variants);
                $existing = new Collection;

                if ($rows !== null) {
                    // The sizes the rows name, by primary key and nothing else, BEFORE the first read of
                    // the sizes: a checkout takes these locks before it writes a line, so what is read
                    // next has every committed line in it. Ownership is checked on the rows afterwards.
                    ProductStock::lock((int) $locked->masjid_id, self::namedIds($rows));

                    $existing = $locked->variants()->get()->keyBy('id');
                }

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
                // Every successful save moves the version, a sizes-only one included.
                $locked->lock_version = (int) $locked->lock_version + 1;
                $locked->save();

                if ($rows !== null) {
                    $this->syncVariants($locked, $rows, $existing);
                }

                return $locked;
            }, self::DEADLOCK_ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            if (self::clashingIndex($e) === 'label') {
                throw self::twoSizesClash();
            }

            throw $e;
        }
    }

    /**
     * Soft-delete a product and its sizes. A paid sale keeps its own snapshot (name, size, price)
     * and its order, so the pickup list and the order still read; a basket line that names one of
     * these sizes prices as `gone` from now on, and a pending page that already holds it is still
     * settled (settlement reads sizes withTrashed).
     *
     * The sizes are found with a plain read under the product's lock and soft-deleted by PRIMARY KEY
     * after taking the same locks a checkout takes, never by a `product_id` range.
     */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $ids = $locked->variants()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            if ($ids !== []) {
                ProductStock::lock((int) $locked->masjid_id, $ids);

                ProductVariant::withoutMasjidScope()->whereIn('id', $ids)->delete();
            }

            $locked->delete();
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Move a product's `lock_version` on by one, atomically (one UPDATE, `lock_version = lock_version + 1`,
     * which takes the row lock for its own length). The picture endpoints call it: a picture added,
     * removed or moved changes what the editor shows, so a save that read the product before it
     * is stale.
     */
    public static function bumpVersion(int $productId): void
    {
        Product::query()->whereKey($productId)->increment('lock_version');
    }

    /** The one currency every product is sold in, lower case as the column stores it. */
    public static function currency(): string
    {
        return strtolower((string) config('services.stripe.currency', 'usd'));
    }

    /**
     * Which unique index a violation names: `label` (a size's live label), `slug` (a product's live
     * slug) or null. MySQL names the generated-column index (`live_label`, `live_slug`); SQLite names
     * the columns of its partial index (`product_variants.label`, `products.slug`). The driver's own
     * message is read as well as the one Laravel builds, which also carries the SQL.
     */
    private static function clashingIndex(UniqueConstraintViolationException $e): ?string
    {
        $message = $e->getMessage() . ' ' . ($e->getPrevious()?->getMessage() ?? '');

        if (preg_match('/live_label|product_variants\.label/', $message) === 1) {
            return 'label';
        }

        if (preg_match('/live_slug|products\.slug/', $message) === 1) {
            return 'slug';
        }

        return null;
    }

    private static function twoSizesClash(): ValidationException
    {
        return ValidationException::withMessages(['variants' => ['Two sizes of one product cannot share a name.']]);
    }

    /**
     * The ids the rows of a list name, ascending and without repeats.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<int>
     */
    private static function namedIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $ids[(int) $row['id']] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  Collection<int,ProductVariant>  $existing  this product's live sizes, by id (empty for a new product)
     */
    private function syncVariants(Product $product, array $rows, Collection $existing): void
    {
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

        // 3. Write the rows: existing sizes get what the row says, new ones are created. Only the
        //    columns a row may set are ever written: `sold_count` is never among them.
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
