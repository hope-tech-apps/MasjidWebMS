<?php

namespace App\Services\Shop;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\ProductStock;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A product as the ADMIN screens read it (shop slice B2): the numbers behind "Total 20 · sold 12 ·
 * in baskets 1 · left 7".
 *
 * Per size: `stock` is the TOTAL put on sale, sold units included (null = unlimited);
 * `sold_count` is what paid lines have taken; `held` is what pending payment pages hold right now;
 * `available` is what can still be bought, never below zero (null = unlimited). So a size whose
 * stock was set below what is sold and held reads `available` 0 and still carries the stock the
 * office typed. The public read (PublicCatalogue) carries NONE of these numbers.
 *
 * `held` is one grouped query for however many sizes the caller shows (ProductStock::held), asked
 * once per request by heldFor() and handed to product().
 */
final class ProductPayload
{
    /**
     * Units held by pending orders, for every size of these products, in one query.
     *
     * @param  iterable<Product>  $products  each with its `variants` loaded
     * @return array<int,int>  variant id => units held (a size with none is absent)
     */
    public static function heldFor(int $masjidId, iterable $products): array
    {
        $ids = [];

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $ids[] = (int) $variant->id;
            }
        }

        return ProductStock::held($masjidId, array_values(array_unique($ids)));
    }

    /**
     * One product, re-read with its sizes and pictures as the screen shows them: what the picture
     * endpoints answer with, so the editor refreshes from one response.
     *
     * @return array<string,mixed>
     */
    public static function read(int $productId): array
    {
        $product = Product::query()->with(self::relations())->findOrFail($productId);

        return self::product($product, self::heldFor((int) $product->masjid_id, [$product]));
    }

    /**
     * @param  array<int,int>  $held  from heldFor()
     * @return array<string,mixed>
     */
    public static function product(Product $product, array $held): array
    {
        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'slug' => (string) $product->slug,
            'category' => $product->category,
            'description' => $product->description,
            'base_price_minor' => (int) $product->base_price_minor,
            'currency' => (string) $product->currency,
            'active' => (bool) $product->active,
            'sort' => (int) $product->sort,
            // The version an editor must name when it saves (UpdateProductRequest): every answer carries it.
            'lock_version' => (int) $product->lock_version,
            'variants' => $product->variants
                ->map(static fn (ProductVariant $variant): array => self::variant($product, $variant, $held[(int) $variant->id] ?? 0))
                ->values()
                ->all(),
            'images' => $product->images
                ->map(static fn (Media $media): array => self::image($media))
                ->values()
                ->all(),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public static function variant(Product $product, ProductVariant $variant, int $held): array
    {
        return [
            'id' => (int) $variant->id,
            'label' => (string) $variant->label,
            'enabled' => (bool) $variant->enabled,
            // The size's own price when it sets one, else the product's: what a buyer pays.
            'price_minor' => $variant->price_minor === null ? null : (int) $variant->price_minor,
            'effective_price_minor' => (int) ($variant->price_minor ?? $product->base_price_minor),
            'stock' => $variant->stock === null ? null : (int) $variant->stock,
            'sold_count' => (int) $variant->sold_count,
            'held' => $held,
            'available' => ProductStock::available($variant, $held),
            'sort' => (int) $variant->sort,
        ];
    }

    /** @return array<string,mixed> */
    public static function image(Media $media): array
    {
        return [
            'id' => (int) $media->id,
            'url' => $media->original_url,
            'name' => (string) $media->name,
            'file_name' => (string) $media->file_name,
            'mime_type' => (string) $media->mime_type,
            'size' => (int) $media->size,
            'order' => (int) $media->order_column,
        ];
    }

    /** The relations product() reads, in the order a screen shows them. */
    public static function relations(): array
    {
        return [
            'variants' => static fn ($query) => $query->orderBy('sort')->orderBy('id'),
            'images',
        ];
    }
}
