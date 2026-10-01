<?php

namespace App\Services\Cart\Sources;

use App\Models\Masjid;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartLineOutcome;

/**
 * Re-checks a basket line that buys a size of a product from the online shop (shop slice B1).
 *
 * The line points at a ProductVariant (the size), and everything that can change between filling
 * the basket and paying is asked again here, in the source's own words:
 *
 *   - the `shop` capability switched off (the grant the whole shop ships dark behind): `gone`;
 *   - the product removed, or taken off the shelf (`active` false), or its size removed or
 *     disabled: `gone`. CartPricer loads the variant with `masjid_id`, so another organisation's
 *     size is "not found" and the line is `gone`, never a leak and never a charge; this loads the
 *     product the same way, and a trashed row is not found either;
 *   - a price that changed: the line stays, at the CURRENT price, and the shopper is told
 *     ("The price changed while this was in your basket.") exactly as a dish's price is;
 *   - a currency that is not the basket's: `gone`. A product carries its own `currency`, but the
 *     basket is one currency and the Stripe page charges in it, so a product in another one must
 *     not be sold at its number in this one;
 *   - stock. `$unitsLeft` is what the caller says is still free for THIS line (CartPricer: the
 *     size's stock, less what pending orders and sales have taken, less what the basket's earlier
 *     lines of the same size have already claimed; null means unlimited). At none the line is
 *     `gone` ("Sold out."); asking for more than is left it stays, CLAMPED to what is left and
 *     repriced, as a dish clamps to its per-order cap, and the shopper is told how many.
 *
 * The unit is the size's own `price_minor`, else the product's `base_price_minor`: integer minor
 * units, always. A quantity is 1..20 (MAX_QUANTITY); above it is clamped, below it is `gone`.
 *
 * Nothing here reserves anything. A basket holds no stock; the units are held from the moment a
 * checkout opens a Stripe page, by that pending order (ProductStock), and the decision to refuse is
 * CartCheckoutService's, under the variant's row lock.
 */
final readonly class ProductLineSource
{
    /** The reason when the `shop` grant is off for the organisation. */
    public const SHOP_OFF = 'The shop is not available.';

    public const SOLD_OUT = 'Sold out.';

    /** The most of one size a single line may ask for. */
    public const MAX_QUANTITY = 20;

    /**
     * @param  int|null  $unitsLeft  what is still free for this line, or null for unlimited stock
     * @param  Masjid|null  $org  the organisation the size belongs to. CartPricer passes the one it
     *                            already loaded; a direct caller may omit it and the gate then reads
     *                            it from the size, so it is never skipped.
     * @param  string  $currency  the basket's currency, lower case (config services.stripe.currency)
     */
    public function reprice(
        ProductVariant $variant,
        int $quantity,
        int $unitAmountShownMinor,
        ?int $unitsLeft = null,
        ?Masjid $org = null,
        string $currency = 'usd',
    ): CartLineOutcome {
        // Hand-filtered, as everything the public basket loads is: the page runs unbound, so the
        // tenant scope adds nothing and `masjid_id` is the only thing keeping another
        // organisation's product out. A trashed product is not found.
        $product = Product::withoutMasjidScope()
            ->where('masjid_id', $variant->masjid_id)
            ->find($variant->product_id);

        $label = $product === null ? (string) $variant->label : self::labelFor($product, $variant);

        // Fail closed: an organisation that cannot be read has no shop. Masjid::query() leaves out
        // a soft-deleted one.
        $org ??= Masjid::query()->find($variant->masjid_id);

        if ($org === null || ! $org->hasCapability('shop')) {
            return CartLineOutcome::gone($label, self::SHOP_OFF);
        }

        if ($product === null || ! $product->active || ! $variant->enabled) {
            return CartLineOutcome::gone($label, 'This is no longer available.');
        }

        if (strtolower((string) $product->currency) !== strtolower($currency)) {
            return CartLineOutcome::gone($label, 'This is priced in another currency, so it cannot be paid for here.');
        }

        if ($quantity < 1) {
            return CartLineOutcome::gone($label, 'This no longer has a quantity to pay for.');
        }

        $unitMinor = (int) ($variant->price_minor ?? $product->base_price_minor);

        if ($unitMinor < 1) {
            return CartLineOutcome::gone($label, 'This has no price, so it cannot be paid for here.');
        }

        $reasons = [];

        if ($quantity > self::MAX_QUANTITY) {
            $quantity = self::MAX_QUANTITY;
            $reasons[] = 'Only ' . self::MAX_QUANTITY . " per order, so this was reduced to {$quantity}.";
        }

        if ($unitsLeft !== null) {
            if ($unitsLeft < 1) {
                return CartLineOutcome::gone($label, self::SOLD_OUT);
            }

            if ($quantity > $unitsLeft) {
                $quantity = $unitsLeft;
                $reasons[] = "Only {$unitsLeft} left, so this was reduced to {$unitsLeft}.";
            }
        }

        if ($unitMinor !== $unitAmountShownMinor) {
            $reasons[] = 'The price changed while this was in your basket.';
        }

        // Any of these means the shopper is no longer paying what they last saw, so each goes
        // through `repriced`: still payable, never silent.
        if ($reasons !== []) {
            return CartLineOutcome::repriced($unitMinor, $quantity, $label, implode(' ', $reasons));
        }

        return CartLineOutcome::available($unitMinor, $quantity, $label);
    }

    /** What a line is called: the product and its size, as the basket, the order and Stripe show it. */
    public static function labelFor(Product $product, ProductVariant $variant): string
    {
        return $product->name . ' (' . $variant->label . ')';
    }
}
