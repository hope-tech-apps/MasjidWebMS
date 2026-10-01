<?php

namespace Tests\Feature\Shop;

use App\Models\CartItem;
use App\Services\Cart\Sources\ProductLineSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\Endpoints\CallsCartApi;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * POST /api/v1/cart/items with `type: product` (shop slice B1): a SIZE of a product and a
 * quantity 1..20, validated at the door, priced through the basket's own pricer, and refused with
 * its reason when it would come back `gone`. The grant is asked first, and another organisation's
 * size is a size that does not exist.
 */
class CartAddProductTest extends TestCase
{
    use BuildsBaskets;
    use BuildsShop;
    use CallsCartApi;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
        $this->turnCartOn();
    }

    private function lineCount(): int
    {
        return CartItem::withoutMasjidScope()->count();
    }

    /** @return array<string,mixed> */
    private function sizeBody(int $variantId, int $quantity = 1, ?string $key = null): array
    {
        return array_filter([
            'type' => 'product',
            'variant_id' => $variantId,
            'quantity' => $quantity,
            'client_line_key' => $key,
        ], static fn ($value): bool => $value !== null);
    }

    #[Test]
    public function a_size_is_priced_by_the_shop_and_stored_as_a_product_line(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['label' => 'YM', 'price_minor' => 3000]);
        $token = $this->startBasket($org);

        $response = $this->addLine($org, $token, $this->sizeBody($variant->id, 2, 'press-polo-01'))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_minor', 6000)
            ->assertJsonPath('data.lines.0.type', 'product')
            ->assertJsonPath('data.lines.0.label', 'School Polo (YM)')
            ->assertJsonPath('data.lines.0.status', 'available')
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->assertJsonPath('data.lines.0.unit_minor', 3000);

        $line = CartItem::withoutMasjidScope()->sole();

        $this->assertSame((int) $line->id, $response->json('data.line_id'));
        $this->assertSame(CartItem::TYPE_PRODUCT, $line->buyable_type);
        $this->assertSame('product_variant', $line->buyable_type);
        $this->assertSame((int) $variant->id, (int) $line->buyable_id, 'the SIZE, never the product');
        $this->assertSame(CartItem::RECORDED_AS_SALE, $line->recorded_as);
        $this->assertSame('School Polo (YM)', $line->label);
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame(3000, (int) $line->unit_amount_shown_minor, 'what the size costs now');
        $this->assertSame('usd', $line->currency);
        $this->assertSame((int) $org->id, (int) $line->masjid_id, 'stamped by hand: nothing binds a tenant here');
        $this->assertSame(['product_id' => (int) $variant->product_id], $line->payload);
        $this->assertSame('press-polo-01', $line->client_line_key);
    }

    #[Test]
    public function a_size_without_its_own_price_costs_the_products_base_price(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org);
        $token = $this->startBasket($org);

        $this->addLine($org, $token, $this->sizeBody($variant->id, 3))
            ->assertOk()
            ->assertJsonPath('data.total_minor', 7500)
            ->assertJsonPath('data.lines.0.unit_minor', 2500);
    }

    #[Test]
    public function with_the_shop_off_adding_a_product_is_refused_and_writes_nothing(): void
    {
        $org = $this->org();   // not granted
        $variant = $this->sizeOf($org);
        $token = $this->startBasket($org);

        $this->addLine($org, $token, $this->sizeBody($variant->id))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', ProductLineSource::SHOP_OFF);

        $this->assertSame(0, $this->lineCount());
    }

    #[Test]
    public function a_dark_shop_gives_the_same_answer_for_a_real_size_and_a_made_up_one(): void
    {
        // The grant is asked before the size is read, so a dark shop cannot be probed for what it holds.
        $org = $this->org();
        $real = $this->sizeOf($org);
        $token = $this->startBasket($org);

        $realAnswer = $this->addLine($org, $token, $this->sizeBody($real->id))->assertStatus(422);
        $madeUp = $this->addLine($org, $token, $this->sizeBody(999999))->assertStatus(422);

        $this->assertSame($realAnswer->getContent(), $madeUp->getContent());
        $this->assertSame(0, $this->lineCount());
    }

    #[Test]
    public function another_organisations_size_is_refused_as_a_size_that_does_not_exist(): void
    {
        $mine = $this->shopOrg();
        $theirs = $this->shopOrg();
        $foreign = $this->sizeOf($theirs, ['stock' => 5]);
        $token = $this->startBasket($mine);

        $answer = $this->addLine($mine, $token, $this->sizeBody($foreign->id))
            ->assertStatus(422)->assertJsonPath('status', 'failed');
        $missing = $this->addLine($mine, $token, $this->sizeBody(999999))
            ->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->assertSame(['variant_id' => ['This item is not available.']], $answer->json('data'));
        $this->assertSame($answer->getContent(), $missing->getContent(), 'their size is a size that does not exist');
        $this->assertSame(0, $this->lineCount());
        $this->assertSame(0, (int) $foreign->fresh()->sold_count);
    }

    #[Test]
    public function a_size_that_would_come_back_gone_is_refused_with_the_sources_own_reason(): void
    {
        $org = $this->shopOrg();
        $token = $this->startBasket($org);

        $inactive = $this->sizeOf($org);
        $inactive->product->forceFill(['active' => false])->save();

        $refusals = [
            ['This is no longer available.', $this->sizeOf($org, ['enabled' => false])],
            ['This is no longer available.', $inactive],
            ['Sold out.', $this->sizeOf($org, ['stock' => 2, 'sold_count' => 2])],
        ];

        foreach ($refusals as [$reason, $variant]) {
            $this->addLine($org, $token, $this->sizeBody($variant->id))
                ->assertStatus(422)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('message', $reason);
        }

        $this->assertSame(0, $this->lineCount(), 'a line that would come back gone is never kept');
    }

    #[Test]
    public function a_trashed_size_or_product_is_not_available(): void
    {
        $org = $this->shopOrg();
        $token = $this->startBasket($org);
        $trashedSize = $this->sizeOf($org);
        $trashedSize->delete();
        $trashedProduct = $this->sizeOf($org);
        $trashedProduct->product->delete();

        foreach ([$trashedSize, $trashedProduct] as $variant) {
            $this->addLine($org, $token, $this->sizeBody($variant->id))
                ->assertStatus(422)->assertJsonPath('status', 'failed');
        }

        $this->assertSame(0, $this->lineCount());
    }

    #[Test]
    public function more_than_is_left_is_kept_but_clamped_and_told_as_a_meal_is_clamped_to_its_cap(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 2]);
        $token = $this->startBasket($org);

        $this->addLine($org, $token, $this->sizeBody($variant->id, 5))
            ->assertOk()
            ->assertJsonPath('data.lines.0.status', 'repriced')
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->assertJsonPath('data.lines.0.reason', 'Only 2 left, so this was reduced to 2.')
            ->assertJsonPath('data.total_minor', 5000);

        $this->assertSame(5, (int) CartItem::withoutMasjidScope()->sole()->quantity, 'what the shopper asked for; the notice is what changes it');
    }

    #[Test]
    public function the_quantity_is_one_to_twenty_and_the_variant_is_required(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org);
        $token = $this->startBasket($org);

        foreach ([0, 21, -1] as $bad) {
            $answer = $this->addLine($org, $token, ['type' => 'product', 'variant_id' => $variant->id, 'quantity' => $bad])
                ->assertStatus(422)->assertJsonPath('status', 'failed');
            $this->assertArrayHasKey('quantity', $answer->json('data'), "quantity {$bad}");
        }

        $noVariant = $this->addLine($org, $token, ['type' => 'product', 'quantity' => 1])->assertStatus(422);
        $this->assertArrayHasKey('variant_id', $noVariant->json('data'));

        $noQuantity = $this->addLine($org, $token, ['type' => 'product', 'variant_id' => $variant->id])->assertStatus(422);
        $this->assertArrayHasKey('quantity', $noQuantity->json('data'));

        $this->addLine($org, $token, $this->sizeBody($variant->id, 20))->assertOk();
        $this->assertSame(1, $this->lineCount(), 'only the twenty was kept');
    }

    #[Test]
    public function a_form_encoded_add_works_because_the_browser_never_sends_json_numbers(): void
    {
        // The SPA posts form-encoded (.claude/rules/shipping.md): every value arrives as a string.
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org);
        $token = $this->startBasket($org);

        $this->post('/api/v1/cart/items', [
            'type' => 'product',
            'variant_id' => (string) $variant->id,
            'quantity' => '2',
        ], $this->cartHeaders($org, $token))->assertOk()->assertJsonPath('data.total_minor', 5000);

        $line = CartItem::withoutMasjidScope()->sole();
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame((int) $variant->id, (int) $line->buyable_id);
    }

    #[Test]
    public function a_repeated_key_returns_its_line_and_a_different_request_under_it_is_a_409(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org);
        $other = $this->sizeOf($org, ['label' => 'L']);
        $token = $this->startBasket($org);

        $first = $this->addLine($org, $token, $this->sizeBody($variant->id, 2, 'press-twice-01'))->assertOk();
        $again = $this->addLine($org, $token, $this->sizeBody($variant->id, 2, 'press-twice-01'))->assertOk();

        $this->assertSame($first->json('data.line_id'), $again->json('data.line_id'));
        $this->assertSame(1, $this->lineCount(), 'a retry is the same line');

        // Same key, a different size or a different quantity: not the same press.
        $this->addLine($org, $token, $this->sizeBody($other->id, 2, 'press-twice-01'))->assertStatus(409);
        $this->addLine($org, $token, $this->sizeBody($variant->id, 3, 'press-twice-01'))->assertStatus(409);
        $this->assertSame(1, $this->lineCount());
    }

    #[Test]
    public function the_basket_endpoint_never_shows_the_stock_or_the_payload(): void
    {
        $org = $this->shopOrg();
        $variant = $this->sizeOf($org, ['stock' => 7, 'sold_count' => 2]);
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->sizeBody($variant->id))->assertOk();

        $view = $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk();
        $body = (string) $view->getContent();

        $this->assertSame(['id', 'type', 'label', 'status', 'reason', 'quantity', 'unit_minor'], array_keys($view->json('data.lines.0')));
        $this->assertStringNotContainsString('product_id', $body, 'the payload stays on the server');
        $this->assertStringNotContainsString('sold_count', $body);
        $this->assertStringNotContainsString('stock', $body, 'no stock numbers: the public read says only what the line shows');
    }
}
