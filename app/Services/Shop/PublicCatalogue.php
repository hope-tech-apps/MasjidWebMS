<?php

namespace App\Services\Shop;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\ProductStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The shop as the PUBLIC site reads it (shop slice B2): the renderer's product grid and product page.
 *
 * ## What is on the shelf
 *
 * A product is listed when it is active, not deleted, and has at least one ENABLED, live size. A size
 * that is disabled or deleted is not shown at all (it is the office's, not the buyer's), and a product
 * with none left is not listed. The page runs UNBOUND (no tenant middleware), so every query here
 * names the organisation by hand, and the sizes and the existence check read the base tables or
 * `withoutMasjidScope()`: a tenant context left bound by something else could neither widen nor
 * narrow what is returned.
 *
 * ## What is NOT in it
 *
 * No stock number, no sold count, no held count, no available count, no oversold flag and no "only N
 * left" wording, anywhere, at any depth (the point's rule for the public JSON). A size is `sold_out`
 * when nothing is available (ProductStock::available() === 0), and that ONE plain boolean is all the
 * public learns about stock; an unlimited size is never sold out (its availability is null). A
 * picture is a bare URL string, so it cannot carry a field. The admin read (ProductPayload) keeps
 * every number; this class must never call it. tests/Feature/Shop/ShopPublicApiTest pins the exact
 * keys of the product and of each size, the type of each value, and the absence of any key that
 * looks like a figure.
 *
 * ## Cost
 *
 * Four queries however many products and sizes there are: the products, their sizes, their pictures,
 * and ONE grouped sum of what pending orders hold (ProductStock::held), not one per size.
 */
final class PublicCatalogue
{
    /**
     * The most products one listing returns. The shelf is not paginated (the renderer wants it in one
     * read), so a hard ceiling keeps a runaway catalogue from becoming a runaway response: the
     * first 200 in the office's order are returned and the rest are not.
     */
    public const LISTING_LIMIT = 200;

    /**
     * Every listed product of the organisation, in the order the office set, up to LISTING_LIMIT.
     *
     * @return list<array<string,mixed>>
     */
    public static function listing(int $masjidId): array
    {
        return self::present($masjidId, self::shelf($masjidId)->limit(self::LISTING_LIMIT)->get());
    }

    /**
     * One listed product by its slug, or null (no such product, or not on the shelf).
     *
     * @return array<string,mixed>|null
     */
    public static function product(int $masjidId, string $slug): ?array
    {
        $found = self::present($masjidId, self::shelf($masjidId)->where('products.slug', $slug)->limit(1)->get());

        return $found[0] ?? null;
    }

    /** The products that are for sale: this organisation's, active, live, with an enabled live size. */
    private static function shelf(int $masjidId): Builder
    {
        return Product::withoutMasjidScope()
            ->where('products.masjid_id', $masjidId)
            ->where('products.active', true)
            ->whereExists(static function ($query) use ($masjidId): void {
                // The base table, on purpose: no model scope can be bent by a bound tenant.
                $query->select(DB::raw(1))
                    ->from('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('product_variants.masjid_id', $masjidId)
                    ->where('product_variants.enabled', true)
                    ->whereNull('product_variants.deleted_at');
            })
            ->orderBy('products.sort')
            ->orderBy('products.name')
            ->orderBy('products.id');
    }

    /**
     * @param  Collection<int,Product>  $products
     * @return list<array<string,mixed>>
     */
    private static function present(int $masjidId, Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $productIds = $products->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // Live (not trashed) and enabled sizes: the SoftDeletes scope stays, only the tenant one goes.
        $variants = ProductVariant::withoutMasjidScope()
            ->where('product_variants.masjid_id', $masjidId)
            ->whereIn('product_variants.product_id', $productIds)
            ->where('product_variants.enabled', true)
            ->orderBy('product_variants.sort')
            ->orderBy('product_variants.id')
            ->get()
            ->groupBy('product_id');

        // `model_type` is part of the key: another model's picture that shares a product's id is not its picture.
        $pictures = Media::query()
            ->where('model_type', Product::class)
            ->where('collection_name', Product::IMAGES)
            ->whereIn('model_id', $productIds)
            ->orderBy('order_column')
            ->orderBy('id')
            ->get()
            ->groupBy('model_id');

        $held = ProductStock::held($masjidId, $variants->flatten()->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        return $products->map(static function (Product $product) use ($variants, $pictures, $held): ?array {
            $sizes = $variants->get($product->id, collect());

            // The size that put it on the shelf was switched off between the two reads: not listed.
            if ($sizes->isEmpty()) {
                return null;
            }

            $prices = $sizes->map(static fn (ProductVariant $v): int => (int) ($v->price_minor ?? $product->base_price_minor));

            return [
                'name' => (string) $product->name,
                'slug' => (string) $product->slug,
                'category' => $product->category,
                'description' => $product->description,
                // From the cheapest enabled size; `price_varies` says the sizes are not all the same.
                'price_minor' => (int) $prices->min(),
                'price_varies' => $prices->unique()->count() > 1,
                'currency' => (string) $product->currency,
                'images' => $pictures->get($product->id, collect())
                    ->map(static fn (Media $media): string => $media->original_url)
                    ->values()
                    ->all(),
                'variants' => $sizes
                    ->map(static fn (ProductVariant $v): array => [
                        'id' => (int) $v->id,
                        'label' => (string) $v->label,
                        'price_minor' => (int) ($v->price_minor ?? $product->base_price_minor),
                        // Sold out is the only thing said about stock: available is 0, never "how many".
                        'sold_out' => ProductStock::available($v, $held[(int) $v->id] ?? 0) === 0,
                    ])
                    ->values()
                    ->all(),
            ];
        })->filter()->values()->all();
    }
}
