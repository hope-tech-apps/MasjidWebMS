<?php

namespace Tests\Feature\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Masjid;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Builders for the shop's catalogue and for product lines in a basket. Use it WITH
 * Tests\Feature\Cart\BuildsBaskets, which supplies the organisation (`org()`), the basket
 * (`cart()`) and the other line types. Everything is created UNBOUND with an explicit
 * masjid_id, exactly as the public basket runs.
 */
trait BuildsShop
{
    /** An organisation that has been granted the shop (default OFF everywhere). */
    protected function shopOrg(array $overrides = []): Masjid
    {
        $org = $this->org($overrides);
        $org->forceFill(['capability_overrides' => ['shop' => true]])->save();

        return $org->fresh();
    }

    /** A live, active $25.00 product. */
    protected function product(Masjid $org, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'masjid_id' => $org->id,
            'name' => 'School Polo',
            'slug' => 'school-polo-' . uniqid(),
            'category' => 'Uniforms',
            'description' => 'The navy school polo.',
            'base_price_minor' => 2500,
            'currency' => 'usd',
            'active' => true,
            'sort' => 0,
        ], $overrides));
    }

    /** An enabled size "M" at the product's price, with UNLIMITED stock (null) unless given. */
    protected function variant(Product $product, array $overrides = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'masjid_id' => $product->masjid_id,
            'product_id' => $product->id,
            'label' => 'M',
            'enabled' => true,
            'price_minor' => null,
            'stock' => null,
            'sold_count' => 0,
            'sort' => 0,
        ], $overrides));
    }

    /** One variant of a fresh product, in one call: the shape most tests want. */
    protected function sizeOf(Masjid $org, array $variant = [], array $product = []): ProductVariant
    {
        return $this->variant($this->product($org, $product), $variant);
    }

    /**
     * A product line in the basket, the way CartLineAdder::productLine writes it. `$shown` is the
     * unit price the basket showed (the variant's own, else the product's base price).
     */
    protected function addVariant(Cart $cart, ProductVariant $variant, int $quantity = 1, ?int $shown = null): CartItem
    {
        $product = Product::withoutMasjidScope()->withTrashed()->findOrFail($variant->product_id);

        return CartItem::withoutMasjidScope()->create([
            'cart_id' => $cart->id,
            'masjid_id' => $cart->masjid_id,
            'buyable_type' => CartItem::TYPE_PRODUCT,
            'buyable_id' => $variant->id,
            'recorded_as' => CartItem::RECORDED_AS_SALE,
            'label' => $product->name . ' (' . $variant->label . ')',
            'quantity' => $quantity,
            'unit_amount_shown_minor' => $shown ?? (int) ($variant->price_minor ?? $product->base_price_minor),
            'currency' => 'usd',
            'payload' => ['product_id' => (int) $product->id],
        ]);
    }
}
