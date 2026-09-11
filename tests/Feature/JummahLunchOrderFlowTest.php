<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Services\Stripe\MealOrderPaymentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * The public Jummah-lunch ordering path end to end, plus the webhook that is the
 * sole source of an order's paid state.
 *
 * The load-bearing property is the same one the registration path pins: PRICES
 * ARE NEVER TRUSTED FROM THE CLIENT. The request carries item ids and
 * quantities; the total is re-derived from the menu on the server.
 */
class JummahLunchOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private MealMenu $menu;

    private MealMenuItem $biryani;   // 800

    private MealMenuItem $water;     // 100

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant(); // the public path runs UNBOUND

        $this->masjid = $this->makePayableMasjid();

        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->open()->create();

        $this->biryani = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Chicken Biryani Plate', 'price_minor' => 800,
        ]);
        $this->water = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Water', 'price_minor' => 100,
        ]);
    }

    private function header(): array
    {
        return ['masjid-id' => (string) $this->masjid->id];
    }

    // -------------------------------------------------------------- menu read

    #[Test]
    public function the_public_menu_lists_the_open_menu_and_its_available_items(): void
    {
        $this->getJson('/api/v1/lunch-menu', $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu.uuid', $this->menu->uuid)
            ->assertJsonCount(2, 'data.menu.items');
    }

    #[Test]
    public function the_menu_is_null_when_nothing_is_open(): void
    {
        $this->menu->update(['status' => MealMenu::STATUS_CLOSED]);

        $this->getJson('/api/v1/lunch-menu', $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu', null);
    }

    // ------------------------------------------------------------ placing orders

    #[Test]
    public function a_pickup_order_is_priced_on_the_server_not_the_client(): void
    {
        $response = $this->postJson('/api/v1/lunch-orders', [
            'menu_uuid' => $this->menu->uuid,
            'items' => [
                // A lying client price is sent and must be ignored.
                ['item_id' => $this->biryani->id, 'quantity' => 2, 'unit_price_minor' => 1],
                ['item_id' => $this->water->id, 'quantity' => 1],
            ],
            'customer_name' => 'Yusuf Ali',
            'customer_phone' => '3365551234',
            'payment_method' => 'pickup',
        ], $this->header())->assertOk();

        // 2 × $8.00 + 1 × $1.00 = $17.00, from the menu, not the payload.
        $response->assertJsonPath('data.order.total_minor', 1700);

        $order = MealOrder::withoutMasjidScope()->latest('id')->first();
        $this->assertSame($this->masjid->id, (int) $order->masjid_id);
        $this->assertSame(MealOrder::METHOD_PICKUP, $order->payment_method);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status);
        $this->assertSame(1700, (int) $order->total_minor);
        $this->assertNotNull($order->order_number);
        $this->assertCount(2, $order->items);
    }

    #[Test]
    public function the_honeypot_silently_drops_a_bot(): void
    {
        $this->postJson('/api/v1/lunch-orders', [
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]],
            'customer_name' => 'Bot',
            'customer_phone' => '0000000000',
            'payment_method' => 'pickup',
            'website' => 'http://spam.example',
        ], $this->header())->assertOk();

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_closed_menu_refuses_orders(): void
    {
        $this->menu->update(['status' => MealMenu::STATUS_CLOSED]);

        $this->postJson('/api/v1/lunch-orders', [
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]],
            'customer_name' => 'Late Diner',
            'customer_phone' => '3365550000',
            'payment_method' => 'pickup',
        ], $this->header())->assertStatus(422);

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_unavailable_item_refuses_the_order(): void
    {
        $this->biryani->update(['is_available' => false]);

        $this->postJson('/api/v1/lunch-orders', [
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]],
            'customer_name' => 'Hopeful',
            'customer_phone' => '3365550000',
            'payment_method' => 'pickup',
        ], $this->header())->assertStatus(422);
    }

    #[Test]
    public function an_online_order_returns_a_checkout_url_and_stays_unpaid(): void
    {
        $this->stubCheckout();

        $response = $this->postJson('/api/v1/lunch-orders', [
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]],
            'customer_name' => 'Card Payer',
            'customer_phone' => '3365550000',
            'payment_method' => 'online',
        ], $this->header())->assertOk();

        $response->assertJsonPath('data.checkout_url', 'https://stripe.test/checkout/stub');

        $order = MealOrder::withoutMasjidScope()->latest('id')->first();
        $this->assertSame(MealOrder::METHOD_ONLINE, $order->payment_method);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status, 'the redirect is not a payment');
        $this->assertSame('cs_test_stub', $order->stripe_checkout_session_id);
    }

    // ------------------------------------------------------------- the webhook

    #[Test]
    public function the_webhook_marks_an_online_order_paid_idempotently(): void
    {
        $order = MealOrder::factory()->online()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
        ]);

        $service = app(MealOrderPaymentService::class);
        $session = [
            'metadata' => ['order_uuid' => $order->uuid],
            'payment_status' => 'paid',
            'id' => 'cs_live_x',
            'payment_intent' => 'pi_live_x',
        ];

        $service->handleCheckoutCompleted($session, $this->masjid->stripe_account_id);

        $order->refresh();
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);
        $this->assertSame(MealOrder::STATUS_CONFIRMED, $order->status);
        $this->assertSame('pi_live_x', $order->stripe_payment_intent_id);
        $paidAt = $order->paid_at;
        $this->assertNotNull($paidAt);

        // A second delivery (payment_intent.succeeded after the session) must not
        // move paid_at or double anything.
        $service->handlePaymentIntentSucceeded([
            'metadata' => ['order_uuid' => $order->uuid],
            'id' => 'pi_live_x',
        ], $this->masjid->stripe_account_id);

        $order->refresh();
        $this->assertTrue($paidAt->equalTo($order->paid_at));
    }

    #[Test]
    public function a_wrong_connected_account_never_settles_the_order(): void
    {
        $order = MealOrder::factory()->online()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
        ]);

        app(MealOrderPaymentService::class)->handleCheckoutCompleted([
            'metadata' => ['order_uuid' => $order->uuid],
            'payment_status' => 'paid',
            'id' => 'cs_x',
            'payment_intent' => 'pi_x',
        ], 'acct_someone_else');

        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->refresh()->payment_status);
    }

    #[Test]
    public function is_order_event_distinguishes_orders_from_registrations_and_donations(): void
    {
        $this->assertTrue(MealOrderPaymentService::isOrderEvent(['metadata' => ['order_uuid' => 'x']]));
        $this->assertFalse(MealOrderPaymentService::isOrderEvent(['metadata' => ['registration_uuid' => 'y']]));
        $this->assertFalse(MealOrderPaymentService::isOrderEvent(['metadata' => ['donation_uuid' => 'z']]));
        $this->assertFalse(MealOrderPaymentService::isOrderEvent([]));
    }

    // ------------------------------------------ a bank debit: complete is not paid

    #[Test]
    public function a_bank_debit_still_clearing_is_not_paid_until_its_money_lands(): void
    {
        $order = MealOrder::factory()->online()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id]);

        // The page completes, but a bank debit's money has not moved.
        $this->signedWebhook($this->bankDebitEvent($order, 'checkout.session.completed', 'unpaid'))->assertOk();
        $order->refresh();
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status);
        $this->assertNull($order->paid_at);
        $this->assertSame('cs_bank_1', $order->stripe_checkout_session_id);

        // Days later it lands.
        $this->signedWebhook($this->bankDebitEvent($order, 'checkout.session.async_payment_succeeded', 'paid'))->assertOk();
        $order->refresh();
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);
        $paidAt = $order->paid_at;
        $this->assertNotNull($paidAt);

        // The payment intent's own notice after it changes nothing.
        $this->signedWebhook($this->bankDebitIntentEvent($order))->assertOk();
        $this->assertTrue($paidAt->equalTo($order->fresh()->paid_at));
    }

    #[Test]
    public function a_payment_intent_alone_settles_a_clearing_bank_debit_once(): void
    {
        $order = MealOrder::factory()->online()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id]);

        $this->signedWebhook($this->bankDebitEvent($order, 'checkout.session.completed', 'unpaid'))->assertOk();
        $this->signedWebhook($this->bankDebitIntentEvent($order))->assertOk();
        $order->refresh();
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);
        $paidAt = $order->paid_at;

        $this->signedWebhook($this->bankDebitEvent($order, 'checkout.session.async_payment_succeeded', 'paid'))->assertOk();
        $this->assertTrue($paidAt->equalTo($order->fresh()->paid_at));
    }

    #[Test]
    public function a_failed_bank_debit_leaves_the_order_unpaid_and_says_so(): void
    {
        $order = MealOrder::factory()->online()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id]);
        Log::spy();

        $this->signedWebhook($this->bankDebitEvent($order, 'checkout.session.completed', 'unpaid'))->assertOk();
        $this->signedWebhook($this->bankDebitEvent($order, 'checkout.session.async_payment_failed', 'unpaid'))->assertOk();

        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->fresh()->payment_status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'meal order failed'))
            ->once();
    }

    private function bankDebitEvent(MealOrder $order, string $type, string $paymentStatus): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'type' => $type,
            'account' => $this->masjid->stripe_account_id,
            'data' => ['object' => [
                'id' => 'cs_bank_1',
                'object' => 'checkout.session',
                'status' => 'complete',
                'payment_status' => $paymentStatus,
                'payment_intent' => 'pi_bank_1',
                'metadata' => ['order_uuid' => $order->uuid, 'masjid_id' => (string) $this->masjid->id],
            ]],
        ];
    }

    private function bankDebitIntentEvent(MealOrder $order): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'account' => $this->masjid->stripe_account_id,
            'data' => ['object' => ['id' => 'pi_bank_1', 'object' => 'payment_intent', 'metadata' => ['order_uuid' => $order->uuid]]],
        ];
    }

    /** Through the real endpoint and its real signature check, so the dispatch is exercised too. */
    private function signedWebhook(array $event): TestResponse
    {
        config(['services.stripe.webhook_secret' => 'whsec_lunch_bank_debit']);
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_lunch_bank_debit');

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    // ------------------------------------------------------------------ helpers

    private function stubCheckout(): void
    {
        $this->app->bind(MealOrderCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends MealOrderCheckoutService
            {
                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    return [
                        'id' => 'cs_test_stub',
                        'url' => 'https://stripe.test/checkout/stub',
                        'payment_intent' => 'pi_test_stub',
                    ];
                }
            };
        });
    }

    private function makePayableMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Burlington Test ' . uniqid(),
            'email' => 'office' . uniqid() . '@masjid.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
            'stripe_account_id' => 'acct_test_' . uniqid(),
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
        ]);
    }
    // ---------------------------------------------- asking for an email, or not

    #[Test]
    public function the_menu_payload_says_whether_to_ask_for_an_email(): void
    {
        // Default is ON, so nothing that exists today changes behaviour.
        $this->getJson('/api/v1/lunch-menu', $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu.collect_customer_email', true);

        $this->menu->forceFill(['collect_customer_email' => false])->save();

        $this->getJson('/api/v1/lunch-menu', $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu.collect_customer_email', false);
    }

    #[Test]
    public function an_email_is_stored_when_the_masjid_asks_for_one(): void
    {
        $this->postJson('/api/v1/lunch-orders', [
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]],
            'customer_name' => 'Yusuf Ali',
            'customer_phone' => '3365551234',
            'customer_email' => 'yusuf@example.test',
            'payment_method' => 'pickup',
        ], $this->header())->assertOk();

        $this->assertSame(
            'yusuf@example.test',
            MealOrder::withoutMasjidScope()->latest('id')->first()->customer_email
        );
    }

    #[Test]
    public function an_email_is_dropped_when_the_masjid_turned_the_field_off(): void
    {
        // Hiding an input does not stop a crafted request from carrying one, and
        // the point of switching it off is not to hold the address at all.
        $this->menu->forceFill(['collect_customer_email' => false])->save();

        $this->postJson('/api/v1/lunch-orders', [
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]],
            'customer_name' => 'Yusuf Ali',
            'customer_phone' => '3365551234',
            'customer_email' => 'yusuf@example.test',
            'payment_method' => 'pickup',
        ], $this->header())->assertOk();

        $order = MealOrder::withoutMasjidScope()->latest('id')->first();
        $this->assertNull($order->customer_email, 'the address must not be stored');
        // The order itself still goes through — the field is optional, not a gate.
        $this->assertSame('Yusuf Ali', $order->customer_name);
    }
}
