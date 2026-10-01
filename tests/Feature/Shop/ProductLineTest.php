<?php

namespace Tests\Feature\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Cart\CartLineOutcome;
use App\Services\Cart\CartPricer;
use App\Services\Cart\PricedBasket;
use App\Services\Cart\Sources\ProductLineSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * Re-checking the shop's product line when a basket is priced (shop slice B1): every way a size
 * can stop being sold between filling the basket and paying, and the stock arithmetic that a
 * line cannot decide alone (it is shared with the other lines of the basket and with the pending
 * orders of other shoppers).
 *
 * CartPricer is driven, not the source alone, because the stock a line is given is the pricer's
 * to work out and the loading of the size is hand-filtered by organisation there.
 */
class ProductLineTest extends TestCase
{
    use BuildsBaskets;
    use BuildsShop;
    use RefreshDatabase;

    private function price(Cart $cart): PricedBasket
    {
        return (new CartPricer)->price($cart);
    }

    /** The outcome of the basket's n-th line (0-based). */
    private function outcome(PricedBasket $priced, int $n = 0): CartLineOutcome
    {
        return $priced->lines[$n]['outcome'];
    }

    // -------------------------------------------------------------------- the price

    #[Test]
    public function a_size_at_the_products_base_price_is_payable_and_its_label_names_both(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $this->addVariant($cart, $this->sizeOf($org, ['label' => 'YM']), 2);

        $priced = $this->price($cart);
        $line = $this->outcome($priced);

        $this->assertSame('available', $line->status);
        $this->assertSame('School Polo (YM)', $line->label);
        $this->assertSame(2500, $line->unitAmountMinor);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(5000, $priced->totalMinor);
        $this->assertSame($org->stripe_account_id, $priced->destinationAccountId, 'paid into the organisation\'s own account, as food and gifts are');
    }

    #[Test]
    public function a_sizes_own_price_overrides_the_products_base_price(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org, ['label' => 'XL', 'price_minor' => 3000]);
        $this->addVariant($cart, $variant, 1);

        $line = $this->outcome($this->price($cart));

        $this->assertSame('available', $line->status);
        $this->assertSame(3000, $line->unitAmountMinor, 'the size\'s price, not the product\'s 2500');
    }

    #[Test]
    public function a_price_changed_since_it_went_into_the_basket_is_repriced_and_the_shopper_is_told(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org);
        $this->addVariant($cart, $variant, 2, 2500);

        // The owner raises the base price to $27.00 while the basket sits there.
        $variant->product->forceFill(['base_price_minor' => 2700])->save();

        $priced = $this->price($cart);
        $line = $this->outcome($priced);

        $this->assertSame('repriced', $line->status);
        $this->assertSame(2700, $line->unitAmountMinor, 'the CURRENT price, never the old one');
        $this->assertSame(5400, $priced->totalMinor);
        $this->assertSame('The price changed while this was in your basket.', $line->reason);
        $this->assertCount(1, $priced->notices(), 'a notice the shopper sees before the card screen');
        $this->assertSame('repriced', $priced->notices()[0]['status']);
    }

    #[Test]
    public function a_sizes_own_price_changing_is_a_repricing_too(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org, ['price_minor' => 3000]);
        $this->addVariant($cart, $variant, 1);

        $variant->forceFill(['price_minor' => 2800])->save();

        $line = $this->outcome($this->price($cart));

        $this->assertSame('repriced', $line->status);
        $this->assertSame(2800, $line->unitAmountMinor);
    }

    // ------------------------------------------------------------------ the gates

    #[Test]
    public function with_the_shop_off_a_product_line_is_gone_and_charges_nothing(): void
    {
        $org = $this->org();   // NOT granted the shop
        $this->assertFalse($org->hasCapability('shop'), 'premise');
        $cart = $this->cart($org);
        $this->addVariant($cart, $this->sizeOf($org), 1);

        $priced = $this->price($cart);
        $line = $this->outcome($priced);

        $this->assertSame('gone', $line->status);
        $this->assertSame(ProductLineSource::SHOP_OFF, $line->reason);
        $this->assertSame(0, $priced->totalMinor);
        $this->assertFalse($priced->isPayable());
    }

    #[Test]
    public function turning_the_shop_off_after_a_line_was_added_drops_the_line_at_the_next_pricing(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $this->addVariant($cart, $this->sizeOf($org), 1);
        $this->assertSame('available', $this->outcome($this->price($cart))->status, 'premise');

        $org->forceFill(['capability_overrides' => ['shop' => false]])->save();

        $this->assertSame('gone', $this->outcome($this->price($cart))->status);
    }

    #[Test]
    public function another_organisations_size_is_gone_never_a_charge(): void
    {
        $mine = $this->shopOrg();
        $theirs = $this->shopOrg();
        $foreign = $this->sizeOf($theirs, ['stock' => 5]);

        // A basket of mine that somehow names their size (the id comes from the browser).
        $cart = $this->cart($mine);
        CartItem::withoutMasjidScope()->create([
            'cart_id' => $cart->id, 'masjid_id' => $mine->id, 'buyable_type' => CartItem::TYPE_PRODUCT,
            'buyable_id' => $foreign->id, 'recorded_as' => CartItem::RECORDED_AS_SALE, 'label' => 'Their polo',
            'quantity' => 1, 'unit_amount_shown_minor' => 2500, 'currency' => 'usd', 'payload' => ['product_id' => $foreign->product_id],
        ]);

        $priced = $this->price($cart);

        $this->assertSame('gone', $this->outcome($priced)->status);
        $this->assertSame(0, $priced->totalMinor);
        $this->assertSame(0, (int) $foreign->fresh()->sold_count, 'and nothing of theirs was touched');
    }

    #[Test]
    public function a_disabled_size_is_gone(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org);
        $this->addVariant($cart, $variant, 1);

        $variant->forceFill(['enabled' => false])->save();

        $line = $this->outcome($this->price($cart));
        $this->assertSame('gone', $line->status);
        $this->assertSame('This is no longer available.', $line->reason);
    }

    #[Test]
    public function a_product_taken_off_the_shelf_is_gone(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org);
        $this->addVariant($cart, $variant, 1);

        $variant->product->forceFill(['active' => false])->save();

        $this->assertSame('gone', $this->outcome($this->price($cart))->status);
    }

    #[Test]
    public function a_trashed_product_is_gone_even_though_its_size_is_live(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org);
        $this->addVariant($cart, $variant, 1);

        $variant->product->delete();

        $this->assertNotNull(ProductVariant::query()->find($variant->id), 'premise: the size itself is not trashed');
        $this->assertSame('gone', $this->outcome($this->price($cart))->status);
    }

    #[Test]
    public function a_trashed_size_is_gone(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org);
        $this->addVariant($cart, $variant, 1);

        $variant->delete();

        $this->assertSame('gone', $this->outcome($this->price($cart))->status);
    }

    #[Test]
    public function a_product_in_another_currency_is_gone_rather_than_sold_at_its_number_in_ours(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org, [], ['currency' => 'cad']);
        $this->addVariant($cart, $variant, 1);

        $line = $this->outcome($this->price($cart));

        $this->assertSame('gone', $line->status);
        $this->assertStringContainsString('another currency', (string) $line->reason);
    }

    #[Test]
    public function a_product_with_no_price_is_gone(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org, [], ['base_price_minor' => 0]);
        $this->addVariant($cart, $variant, 1, 0);

        $this->assertSame('gone', $this->outcome($this->price($cart))->status);
    }

    #[Test]
    public function a_quantity_over_twenty_is_clamped_to_twenty_and_told(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $this->addVariant($cart, $this->sizeOf($org), 25);

        $line = $this->outcome($this->price($cart));

        $this->assertSame('repriced', $line->status);
        $this->assertSame(20, $line->quantity);
        $this->assertStringContainsString('Only 20 per order', (string) $line->reason);
    }

    // --------------------------------------------------------------------- stock

    #[Test]
    public function unlimited_stock_is_never_clamped_or_sold_out(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        // NULL stock is unlimited, however many are sold or held.
        $variant = $this->sizeOf($org, ['stock' => null, 'sold_count' => 9000]);
        $this->pendingOrderHolding($org, $variant, 500);
        $this->addVariant($cart, $variant, 20);

        $line = $this->outcome($this->price($cart));

        $this->assertSame('available', $line->status);
        $this->assertSame(20, $line->quantity);
    }

    #[Test]
    public function stock_less_what_is_sold_is_what_a_line_can_ask_for(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org, ['stock' => 5, 'sold_count' => 3]);
        $this->addVariant($cart, $variant, 4);

        $line = $this->outcome($this->price($cart));

        $this->assertSame('repriced', $line->status);
        $this->assertSame(2, $line->quantity, 'clamped to what is left');
        $this->assertSame('Only 2 left, so this was reduced to 2.', $line->reason);
        $this->assertSame(5000, $this->price($cart)->totalMinor);
    }

    #[Test]
    public function a_size_with_none_left_is_gone_as_sold_out(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org, ['stock' => 5, 'sold_count' => 5]);
        $this->addVariant($cart, $variant, 1);

        $line = $this->outcome($this->price($cart));

        $this->assertSame('gone', $line->status);
        $this->assertSame('Sold out.', $line->reason);
    }

    #[Test]
    public function an_oversold_size_has_none_left_not_a_negative_number(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org, ['stock' => 5, 'sold_count' => 7]);
        $this->addVariant($cart, $variant, 1);

        $this->assertSame('gone', $this->outcome($this->price($cart))->status);
    }

    #[Test]
    public function another_shoppers_pending_order_holds_its_units_against_this_basket(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 3]);

        $other = $this->cart($org);
        $this->pendingOrderHolding($org, $variant, 2, 20, $other->id);

        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 3);

        $line = $this->outcome($this->price($cart));

        $this->assertSame('repriced', $line->status);
        $this->assertSame(1, $line->quantity, '3 in stock, 2 held by the other basket');
    }

    #[Test]
    public function the_baskets_own_pending_order_does_not_count_against_itself(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 2]);
        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 2);

        // The page this basket already opened holds both units; pricing it again (the page is about to
        // be handed back) must still see them as the basket's own.
        $this->pendingOrderHolding($org, $variant, 2, 20, $cart->id);

        $line = $this->outcome($this->price($cart));

        $this->assertSame('available', $line->status);
        $this->assertSame(2, $line->quantity);
    }

    #[Test]
    public function an_order_whose_basket_was_pruned_still_holds_its_units(): void
    {
        // cart_id nulls out when a basket is deleted; `cart_id != x` is not true for NULL, so a naive
        // exclusion would have released the units of an order somebody may still pay.
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 2]);
        $this->pendingOrderHolding($org, $variant, 2, 20, null);

        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 1);

        $this->assertSame('gone', $this->outcome($this->price($cart))->status);
    }

    #[Test]
    public function two_lines_of_one_size_are_clamped_together(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org, ['stock' => 3]);
        $this->addVariant($cart, $variant, 2);
        $this->addVariant($cart, $variant, 2);

        $priced = $this->price($cart);

        $this->assertSame('available', $this->outcome($priced, 0)->status);
        $this->assertSame(2, $this->outcome($priced, 0)->quantity);
        $this->assertSame('repriced', $this->outcome($priced, 1)->status);
        $this->assertSame(1, $this->outcome($priced, 1)->quantity, 'the second line gets only what the first left');
        $this->assertSame(3, $this->outcome($priced, 0)->quantity + $this->outcome($priced, 1)->quantity, 'together they never exceed the stock');
        $this->assertSame(7500, $priced->totalMinor);
    }

    #[Test]
    public function a_third_line_of_a_size_the_first_two_used_up_is_gone(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org, ['stock' => 3]);
        $this->addVariant($cart, $variant, 2);
        $this->addVariant($cart, $variant, 1);
        $this->addVariant($cart, $variant, 1);

        $priced = $this->price($cart);

        $this->assertSame(['available', 'available', 'gone'], array_map(fn (array $l): string => $l['outcome']->status, $priced->lines));
        $this->assertSame('Sold out.', $this->outcome($priced, 2)->reason);
    }

    #[Test]
    public function a_gone_line_claims_no_units_from_the_lines_after_it(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $variant = $this->sizeOf($org, ['stock' => 2]);
        $this->addVariant($cart, $variant, 0);   // asks for nothing: gone, and claims nothing
        $this->addVariant($cart, $variant, 2);

        $priced = $this->price($cart);

        $this->assertSame('gone', $this->outcome($priced, 0)->status);
        $this->assertSame('available', $this->outcome($priced, 1)->status);
        $this->assertSame(2, $this->outcome($priced, 1)->quantity, 'the whole stock is still free for the line that is payable');
    }

    #[Test]
    public function different_sizes_have_separate_stock(): void
    {
        $org = $this->shopOrg();
        $cart = $this->cart($org);
        $product = $this->product($org);
        $small = $this->variant($product, ['label' => 'S', 'stock' => 1]);
        $large = $this->variant($product, ['label' => 'L', 'stock' => 10]);
        $this->addVariant($cart, $small, 1);
        $this->addVariant($cart, $large, 5);

        $priced = $this->price($cart);

        $this->assertSame('available', $this->outcome($priced, 0)->status);
        $this->assertSame('available', $this->outcome($priced, 1)->status);
        $this->assertSame(5, $this->outcome($priced, 1)->quantity);
    }

    #[Test]
    public function an_order_that_has_been_paid_or_has_expired_no_longer_holds_units(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 2]);
        $paid = $this->pendingOrderHolding($org, $variant, 1);
        $paid->forceFill(['status' => Order::STATUS_PAID])->save();
        $expired = $this->pendingOrderHolding($org, $variant, 1);
        $expired->forceFill(['status' => Order::STATUS_EXPIRED])->save();

        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 2);

        // Neither is a hold: a paid order is in `sold_count` once settled (this fixture did not
        // settle it), and an expired one has stopped counting.
        $this->assertSame(2, $this->outcome($this->price($cart))->quantity);
    }

    #[Test]
    public function a_hold_in_another_organisation_with_the_same_ids_never_counts(): void
    {
        $org = $this->shopOrg();
        $other = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 2]);

        // The other organisation's order carries a line naming THIS size's id (forged or a collision).
        $order = $this->pendingOrderHolding($other, $variant, 2);
        $this->assertSame((int) $other->id, (int) $order->masjid_id, 'premise');

        $cart = $this->cart($org);
        $this->addVariant($cart, $variant, 2);

        $this->assertSame('available', $this->outcome($this->price($cart))->status);
    }
}
