<?php

namespace Tests\Feature\Cart;

use App\Models\CartItem;
use App\Models\Donation;
use App\Models\FormResponse;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * payment_intent.payment_failed (subscribed on the Connect endpoint before the cart is switched on,
 * ASSUMPTIONS PM-A6). Cart pages are card only, so this is a declined attempt on a page that stays
 * open: nothing is recorded or changed, and a basket's is logged at info for the office. Every
 * payment intent that is not a basket's is acked and ignored exactly as before.
 */
class CartPaymentFailedTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
        Log::spy();
    }

    private function ticketOrder(): Order
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 2, $this->twoTickets());

        return $this->placeOrder($cart);
    }

    private function failedIntent(Order $order, array $metadata): array
    {
        return $this->cartEvent('payment_intent.payment_failed', $order, [], [
            'id' => 'pi_cart_declined',
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
            'amount' => (int) $order->total_minor,
            'currency' => 'usd',
            'metadata' => $metadata,
            'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.'],
        ]);
    }

    #[Test]
    public function a_declined_card_on_a_baskets_page_changes_nothing_and_is_logged_for_the_office(): void
    {
        $order = $this->ticketOrder();

        $this->postWebhook($this->failedIntent($order, $this->routing($order)))->assertOk();

        $order->refresh();
        $this->assertSame(Order::STATUS_PENDING, $order->status, 'the page stays open: the order is still waiting for a card that works');
        $this->assertNull($order->stripe_payment_intent_id, 'a declined attempt records no payment');
        $this->assertSame(0, FormResponse::query()->count(), 'and no record is written');
        $this->assertSame(0, OrderItem::withoutMasjidScope()->whereNotNull('record_id')->count());

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'card attempt on a basket')
                && ($context['order_id'] ?? null) === $order->id
                && ($context['decline_code'] ?? null) === 'insufficient_funds'
                && ! str_contains(json_encode($context), 'insufficient funds.'))
            ->once();
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function a_declined_card_that_is_not_a_baskets_is_acked_and_ignored_as_before(): void
    {
        $order = $this->ticketOrder();
        $donations = Donation::withoutMasjidScope()->count();

        // No cart key in the metadata: this payment intent is somebody else's.
        $this->postWebhook($this->failedIntent($order, ['masjid_id' => (string) $order->masjid_id]))->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame($donations, Donation::withoutMasjidScope()->count());
        Log::shouldNotHaveReceived('info', fn (string $message) => str_contains($message, 'card attempt on a basket'));
        Log::shouldNotHaveReceived('error');
    }
}
