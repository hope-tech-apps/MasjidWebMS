<?php

namespace Tests\Feature;

use App\Mail\KitchenOrderForCustomer;
use App\Mail\KitchenOrderForOffice;
use App\Mail\LunchOrderConfirmation;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\OrganisationPaymentMethod;
use App\Models\User;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\PaymentMethods;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * The kitchen: a standing catalogue on the meal-ordering module, pickup at the
 * organisation, booked at least 48 hours ahead, confirmed by the office, paid by
 * the organisation's accepted methods (owner, 2026-09-21: "Build ordering in
 * Manara" — "Pickup at MEC, 48h, office confirms").
 *
 * What it pins, beyond the lunch door's rules it reuses:
 *  - the lead time and the booking window are the SERVER's, read in the
 *    organisation's timezone;
 *  - the ways to pay are the organisation's accepted methods, card only while its
 *    Stripe account can take charges, and never one it does not list;
 *  - a paid kitchen order stays pending until the office confirms it, and the
 *    confirmation is recorded once, with who;
 *  - the office hears about an order when it becomes real, once;
 *  - the Friday-lunch door neither serves, takes nor edits a catalogue.
 *
 * All people and addresses here are invented.
 */
class KitchenOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://kitchen.example.test';

    private Masjid $masjid;

    private MealMenu $menu;

    private MealMenuItem $tray;     // 9000

    private MealMenuItem $soup;     // 4000

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['cors.allowed_origins' => [self::SITE]]);

        app(TenantContext::class)->forgetTenant(); // the public path runs UNBOUND

        // Noon UTC on a Thursday = 08:00 in New York (EDT).
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00', 'UTC'));

        $this->masjid = $this->makeMasjid(true);

        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->catalogue()->create([
            'status' => MealMenu::STATUS_OPEN,
            'notify_emails' => 'office@kitchen.example.test, cook@kitchen.example.test',
        ]);

        $this->tray = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Chicken Kabsa (Serves 8)', 'price_minor' => 9000,
        ]);
        $this->soup = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Lentil Soup (Small)', 'price_minor' => 4000,
        ]);

        $this->accept($this->masjid, [
            PaymentMethods::CARD => null,
            PaymentMethods::ZELLE => 'Send to pay@kitchen.example.test with your order number.',
            PaymentMethods::CASH => 'Pay at the office when you pick up.',
        ]);

        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- the catalogue read

    #[Test]
    public function the_catalogue_lists_its_dishes_ways_to_pay_and_the_pickup_window_in_local_time(): void
    {
        $this->getJson("/api/v1/kitchen-menus/{$this->menu->uuid}", $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu.uuid', $this->menu->uuid)
            ->assertJsonPath('data.menu.accepting_orders', true)
            ->assertJsonPath('data.menu.pickup_lead_hours', 48)
            // 12:00 UTC + 48h = Saturday 08:00 in New York.
            ->assertJsonPath('data.menu.earliest_pickup_local', '2026-10-03T08:00')
            ->assertJsonPath('data.menu.timezone', 'America/New_York')
            ->assertJsonCount(2, 'data.menu.items')
            ->assertJsonPath('data.menu.payment_methods.0.method', PaymentMethods::CARD)
            ->assertJsonPath('data.menu.payment_methods.1.method', PaymentMethods::ZELLE)
            ->assertJsonPath('data.menu.payment_methods.1.instructions', 'Send to pay@kitchen.example.test with your order number.');
    }

    #[Test]
    public function card_is_not_offered_while_the_organisations_stripe_account_cannot_take_charges(): void
    {
        $this->masjid->forceFill(['stripe_charges_enabled' => false])->save();

        $methods = $this->getJson("/api/v1/kitchen-menus/{$this->menu->uuid}", $this->header())
            ->assertOk()
            ->json('data.menu.payment_methods');

        $this->assertSame([PaymentMethods::ZELLE, PaymentMethods::CASH], array_column($methods, 'method'));

        $this->placeOrder(['payment_method' => PaymentMethods::CARD])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That way of paying is not available for this kitchen. Please choose another.');
        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_draft_catalogue_a_dated_menu_and_another_organisations_catalogue_are_not_served(): void
    {
        $draft = MealMenu::factory()->forMasjid($this->masjid)->catalogue()->create(['title' => 'Draft kitchen']);
        $friday = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $other = $this->makeMasjid(true);
        $foreign = MealMenu::factory()->forMasjid($other)->catalogue()->create(['status' => MealMenu::STATUS_OPEN]);

        foreach ([$draft, $friday, $foreign] as $menu) {
            $this->getJson("/api/v1/kitchen-menus/{$menu->uuid}", $this->header())->assertNotFound();
        }
    }

    #[Test]
    public function the_kitchen_is_closed_when_the_organisations_lunch_module_is_switched_off(): void
    {
        $this->masjid->forceFill(['capability_overrides' => ['jummah_lunch' => false]])->save();

        $this->getJson("/api/v1/kitchen-menus/{$this->menu->uuid}", $this->header())->assertNotFound();
        $this->placeOrder()->assertNotFound();
    }

    #[Test]
    public function an_organisation_that_lists_no_payment_method_takes_no_kitchen_order(): void
    {
        OrganisationPaymentMethod::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->delete();

        $this->getJson("/api/v1/kitchen-menus/{$this->menu->uuid}", $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu.accepting_orders', false)
            ->assertJsonPath('data.menu.payment_methods', []);

        $this->placeOrder(['payment_method' => PaymentMethods::CASH])->assertStatus(422);
        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    // --------------------------------------------------------------- placing an order

    #[Test]
    public function an_offline_order_is_priced_on_the_server_pending_and_remembers_how_they_will_pay(): void
    {
        $response = $this->placeOrder([
            'items' => [
                ['item_id' => $this->tray->id, 'quantity' => 2, 'unit_price_minor' => 1],
                ['item_id' => $this->soup->id, 'quantity' => 1],
            ],
        ])->assertOk();

        $response->assertJsonPath('data.order.total_minor', 22000)
            ->assertJsonPath('data.order.status', MealOrder::STATUS_PENDING)
            ->assertJsonPath('data.order.confirmed', false)
            ->assertJsonPath('data.order.preferred_payment', PaymentMethods::ZELLE)
            ->assertJsonPath('data.order.how_to_pay.instructions', 'Send to pay@kitchen.example.test with your order number.')
            ->assertJsonPath('data.order.pickup_label', 'Saturday, October 3 at 2:00 PM');

        $order = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame((int) $this->masjid->id, (int) $order->masjid_id);
        $this->assertSame(MealOrder::METHOD_PICKUP, $order->payment_method);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status);
        $this->assertSame(PaymentMethods::ZELLE, $order->preferred_payment);
        // 14:00 New York (EDT) is 18:00 UTC.
        $this->assertSame('2026-10-03 18:00:00', $order->pickup_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($order->stripe_checkout_session_id);
    }

    #[Test]
    public function a_pickup_inside_the_lead_time_is_refused_with_the_earliest_time_it_could_be(): void
    {
        // 47 hours ahead: Saturday 07:00 New York.
        $this->placeOrder(['pickup_at' => '2026-10-03T07:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', "Orders need at least 48 hours' notice. The earliest pickup is Saturday, October 3 at 8:00 AM.");

        // Exactly the lead time is enough.
        $this->placeOrder(['pickup_at' => '2026-10-03T08:00'])->assertOk();
    }

    #[Test]
    public function the_lead_time_is_the_menus_own_not_a_fixed_48(): void
    {
        $this->menu->forceFill(['pickup_lead_hours' => 72])->save();

        $this->placeOrder(['pickup_at' => '2026-10-03T14:00'])->assertStatus(422);
        $this->placeOrder(['pickup_at' => '2026-10-04T08:00'])->assertOk();
    }

    #[Test]
    public function a_pickup_beyond_the_booking_window_or_not_a_time_at_all_is_refused(): void
    {
        $this->placeOrder(['pickup_at' => '2027-03-01T12:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please choose a pickup within the next 90 days.');

        $this->placeOrder(['pickup_at' => 'next saturday-ish'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please choose a pickup date and time.');

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function more_trays_than_a_dish_allows_are_refused_never_quietly_trimmed(): void
    {
        $this->soup->forceFill(['max_quantity' => 2])->save();

        $this->placeOrder(['items' => [['item_id' => $this->soup->id, 'quantity' => 3]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only 2 × Lentil Soup (Small) per order.');

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_method_the_organisation_does_not_accept_is_refused(): void
    {
        $this->placeOrder(['payment_method' => PaymentMethods::CHECK])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That way of paying is not available for this kitchen. Please choose another.');

        // The menu's own switch narrows the organisation's list too.
        $this->menu->forceFill(['allow_pay_at_pickup' => false])->save();
        $this->placeOrder(['payment_method' => PaymentMethods::ZELLE])->assertStatus(422);

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_card_order_opens_a_stripe_page_that_returns_to_the_kitchen_order_page(): void
    {
        $captured = $this->stubCheckout();

        $this->placeOrder(['payment_method' => PaymentMethods::CARD], ['Origin' => self::SITE])
            ->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://stripe.test/checkout/kitchen')
            ->assertJsonPath('data.order.payment_method', MealOrder::METHOD_ONLINE)
            ->assertJsonPath('data.order.can_pay_online', true);

        $order = MealOrder::withoutMasjidScope()->sole();
        $this->assertNull($order->preferred_payment);
        $this->assertSame(
            self::SITE . '/kitchen/order/' . $order->uuid . '?paid=1&session_id={CHECKOUT_SESSION_ID}',
            $captured->params['success_url']
        );
        $this->assertSame(self::SITE . '/kitchen/order/' . $order->uuid . '?cancelled=1', $captured->params['cancel_url']);

        // Nobody is told anything until the card is actually paid.
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_card_order_saved_when_stripe_fails_comes_back_with_its_uuid_so_the_customer_is_not_asked_to_order_again(): void
    {
        config(['app.debug' => false]);
        $this->app->bind(MealOrderCheckoutService::class, fn ($app) => new class($app->make(StripeClient::class)) extends MealOrderCheckoutService
        {
            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                throw \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe.');
            }
        });

        $response = $this->placeOrder(['payment_method' => PaymentMethods::CARD], ['Origin' => self::SITE])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your order is saved, but its card payment page could not be opened. Please try paying again from your order.');

        // The renderer sends the customer to this order's page on a 422 that
        // carries one (classifyKitchenPlace → savedUnpaid), never back to the form.
        $order = MealOrder::withoutMasjidScope()->sole();
        $response->assertJsonPath('data.order.uuid', $order->uuid);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status);
        $this->assertNull($order->stripe_checkout_session_id);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_replacement_payment_page_from_the_board_also_returns_to_the_kitchen_order_page(): void
    {
        $order = $this->cardOrder();
        $captured = $this->stubCheckout();
        Sanctum::actingAs($this->adminFor($this->masjid));

        // The customer's page expired; the office sends a new one from the board,
        // which passes no return URLs of its own.
        $this->postJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/payment-link")
            ->assertOk();

        $this->assertSame(
            self::SITE . '/kitchen/order/' . $order->uuid . '?paid=1&session_id={CHECKOUT_SESSION_ID}',
            $captured->params['success_url']
        );
        $this->assertSame(self::SITE . '/kitchen/order/' . $order->uuid . '?cancelled=1', $captured->params['cancel_url']);
    }

    #[Test]
    public function a_card_order_from_a_site_the_platform_does_not_trust_is_refused_before_anything_is_written(): void
    {
        $this->stubCheckout();

        foreach ([[], ['Origin' => 'https://stranger.example.test']] as $headers) {
            $this->placeOrder(['payment_method' => PaymentMethods::CARD], $headers)
                ->assertStatus(422)
                ->assertJsonPath('message', 'Card payment is available only on the organisation\'s own website.');
        }

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_order_form_works_as_the_browser_encodes_it(): void
    {
        $this->post('/api/v1/kitchen-orders', $this->orderBody(), $this->header() + ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.order.total_minor', 9000);
    }

    #[Test]
    public function the_honeypot_silently_drops_a_bot(): void
    {
        $this->placeOrder(['website' => 'http://spam.example.test'])->assertOk()->assertJsonPath('data.order', null);

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_closed_catalogue_takes_no_order(): void
    {
        $this->menu->forceFill(['status' => MealMenu::STATUS_CLOSED])->save();

        $this->placeOrder()->assertStatus(422)->assertJsonPath('message', 'This kitchen is not taking orders right now.');
        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    // ----------------------------------------------------------- the office is told

    #[Test]
    public function the_office_is_emailed_about_an_offline_order_once_and_the_customer_is_told_it_awaits_confirmation(): void
    {
        $this->placeOrder()->assertOk();

        Mail::assertQueued(KitchenOrderForOffice::class, function (KitchenOrderForOffice $mail) {
            return $mail->hasTo('office@kitchen.example.test')
                && $mail->hasTo('cook@kitchen.example.test')
                && $mail->pickupLabel === 'Saturday, October 3 at 2:00 PM'
                && $mail->paymentLine === '$90.00 to pay by Zelle.';
        });
        Mail::assertQueued(KitchenOrderForOffice::class, 1);
        Mail::assertQueued(KitchenOrderForCustomer::class, fn (KitchenOrderForCustomer $mail) => $mail->hasTo('buyer@kitchen.example.test')
            && $mail->kind === KitchenOrderForCustomer::RECEIVED
            && $mail->howToPay === 'Send to pay@kitchen.example.test with your order number.'
            && $mail->orderUrl === null);
        // Never the Friday email: no "pay at pickup after Jummah" for a catering order.
        Mail::assertNotQueued(LunchOrderConfirmation::class);
    }

    #[Test]
    public function with_no_office_addresses_the_organisations_own_address_is_told(): void
    {
        $this->menu->forceFill(['notify_emails' => null])->save();

        $this->placeOrder()->assertOk();

        Mail::assertQueued(KitchenOrderForOffice::class, fn (KitchenOrderForOffice $mail) => $mail->hasTo(strtolower($this->masjid->email)));
    }

    #[Test]
    public function a_paid_card_order_tells_the_office_once_and_stays_pending_until_the_office_confirms(): void
    {
        $order = $this->cardOrder();

        // Stripe sends two success events for one payment.
        $this->signedWebhook($this->sessionEvent($order))->assertOk();
        $this->signedWebhook($this->intentEvent($order))->assertOk();

        $order->refresh();
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);
        $this->assertSame(MealOrder::STATUS_PENDING, $order->status, 'paying does not confirm a kitchen order');
        $this->assertNull($order->confirmed_at);

        Mail::assertQueued(KitchenOrderForOffice::class, 1);
        Mail::assertQueued(KitchenOrderForOffice::class, fn (KitchenOrderForOffice $mail) => $mail->paymentLine === 'Paid $90.00 by card.');
        Mail::assertQueued(KitchenOrderForCustomer::class, 1);
        Mail::assertQueued(KitchenOrderForCustomer::class, fn (KitchenOrderForCustomer $mail) => $mail->orderUrl === self::SITE . '/kitchen/order/' . $order->uuid);
        Mail::assertNotQueued(LunchOrderConfirmation::class);
    }

    #[Test]
    public function the_office_confirms_an_order_once_with_who_and_the_customer_is_told_once(): void
    {
        $admin = $this->adminFor($this->masjid);
        $this->placeOrder()->assertOk();
        $order = MealOrder::withoutMasjidScope()->sole();

        Sanctum::actingAs($admin);
        $url = "/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/status";

        $this->putJson($url, ['status' => MealOrder::STATUS_CONFIRMED])->assertOk()
            ->assertJsonPath('data.status', MealOrder::STATUS_CONFIRMED);

        $order->refresh();
        $this->assertNotNull($order->confirmed_at);
        $this->assertSame((int) $admin->id, $order->confirmed_by_user_id);
        $first = $order->confirmed_at;

        // Ready, and confirmed again later, rewrite nothing and send nothing more.
        Carbon::setTestNow(Carbon::now()->addHour());
        $this->putJson($url, ['status' => MealOrder::STATUS_READY])->assertOk();
        $this->putJson($url, ['status' => MealOrder::STATUS_CONFIRMED])->assertOk();

        $this->assertTrue($first->equalTo($order->fresh()->confirmed_at));
        Mail::assertQueued(KitchenOrderForCustomer::class, fn (KitchenOrderForCustomer $mail) => $mail->kind === KitchenOrderForCustomer::CONFIRMED);
        Mail::assertQueued(KitchenOrderForCustomer::class, 2); // received + confirmed, never a second confirmed

        $this->getJson('/api/v1/kitchen-orders/' . $order->uuid, $this->header())
            ->assertOk()
            ->assertJsonPath('data.order.confirmed', true)
            ->assertJsonPath('data.order.status', MealOrder::STATUS_CONFIRMED);
    }

    #[Test]
    public function confirming_a_friday_lunch_order_records_no_office_confirmation_and_sends_no_kitchen_email(): void
    {
        $admin = $this->adminFor($this->masjid);
        $friday = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $order = MealOrder::factory()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $friday->id]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$friday->id}/orders/{$order->id}/status", [
            'status' => MealOrder::STATUS_CONFIRMED,
        ])->assertOk();

        $this->assertNull($order->fresh()->confirmed_at);
        Mail::assertNotQueued(KitchenOrderForCustomer::class);
    }

    #[Test]
    public function a_paid_friday_lunch_order_is_still_confirmed_by_its_payment(): void
    {
        $friday = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $order = MealOrder::factory()->online()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $friday->id]);

        $this->signedWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(MealOrder::STATUS_CONFIRMED, $order->fresh()->status);
        Mail::assertNotQueued(KitchenOrderForOffice::class);
    }

    // ------------------------------------------------- the Friday door stays Friday's

    #[Test]
    public function the_lunch_page_never_serves_or_takes_an_order_on_a_catalogue(): void
    {
        $this->getJson('/api/v1/lunch-menu', $this->header())->assertOk()->assertJsonPath('data.menu', null);

        $this->postJson('/api/v1/lunch-orders', [
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->tray->id, 'quantity' => 1]],
            'customer_name' => 'Test Buyer',
            'customer_phone' => '5550100000',
            'payment_method' => 'pickup',
        ], $this->header())->assertStatus(422);

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_kitchen_order_cannot_be_changed_on_the_lunch_link(): void
    {
        $this->placeOrder()->assertOk();
        $order = MealOrder::withoutMasjidScope()->sole();

        $this->patchJson('/api/v1/lunch-orders/' . $order->uuid, [
            'items' => [['meal_menu_item_id' => $this->tray->id, 'quantity' => 5]],
        ], $this->header())
            ->assertStatus(409)
            ->assertJsonPath('message', 'Please contact the office to change a kitchen order.');

        $this->assertSame(9000, (int) $order->fresh()->total_minor);
    }

    #[Test]
    public function the_kitchen_order_page_reads_only_kitchen_orders_of_its_own_organisation(): void
    {
        $friday = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $lunch = MealOrder::factory()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $friday->id]);
        $this->getJson('/api/v1/kitchen-orders/' . $lunch->uuid, $this->header())->assertNotFound();

        $this->placeOrder()->assertOk();
        $order = MealOrder::withoutMasjidScope()->where('meal_menu_id', $this->menu->id)->sole();
        $other = $this->makeMasjid(true);

        $this->getJson('/api/v1/kitchen-orders/' . $order->uuid, ['masjid-id' => (string) $other->id])->assertNotFound();
        $this->getJson('/api/v1/kitchen-orders/' . $order->uuid, $this->header())
            ->assertOk()
            ->assertJsonMissingPath('data.order.customer_phone')
            ->assertJsonMissingPath('data.order.confirmed_by_user_id');
    }

    #[Test]
    public function staff_taking_a_kitchen_order_by_phone_must_say_when_it_will_be_picked_up(): void
    {
        $this->stubCheckout();
        $admin = $this->adminFor($this->masjid);
        Sanctum::actingAs($admin);
        $url = "/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders";
        $body = [
            'items' => [['item_id' => $this->tray->id, 'quantity' => 1]],
            'customer_name' => 'Phone Buyer',
            'payment_method' => PaymentMethods::CARD,
        ];

        $this->postJson($url, $body)->assertStatus(422);
        $this->postJson($url, $body + ['pickup_at' => '2026-09-30T12:00'])->assertStatus(422);
        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());

        // The office is not held to the public lead time: tomorrow is fine.
        $this->postJson($url, $body + ['pickup_at' => '2026-10-02T12:00'])->assertStatus(201);
        $this->assertSame('2026-10-02 16:00:00', MealOrder::withoutMasjidScope()->sole()->pickup_at->utc()->format('Y-m-d H:i:s'));
    }

    // ------------------------------------------- paying late is placing late (review 1)

    #[Test]
    public function a_card_page_stops_taking_payment_when_the_notice_before_pickup_begins(): void
    {
        $captured = $this->stubCheckout();

        // Pickup Saturday 18:00 UTC, 48 hours' notice: payable until Thursday 18:00 UTC.
        $this->placeOrder(['payment_method' => PaymentMethods::CARD], ['Origin' => self::SITE])->assertOk();
        $this->assertSame(Carbon::parse('2026-10-01 18:00:00', 'UTC')->getTimestamp(), $captured->params['expires_at']);

        // A pickup weeks away is held to Stripe's own ceiling, never past it.
        $this->placeOrder(['payment_method' => PaymentMethods::CARD, 'pickup_at' => '2026-10-20T14:00'], ['Origin' => self::SITE])->assertOk();
        $this->assertSame(Carbon::now()->addHours(24)->subMinute()->getTimestamp(), $captured->params['expires_at']);
    }

    #[Test]
    public function a_card_order_cannot_be_paid_again_once_the_notice_before_pickup_has_begun(): void
    {
        $order = $this->cardOrder();
        $captured = $this->stubCheckout();
        $url = "/api/v1/kitchen-orders/{$order->uuid}/checkout";

        // Five minutes before the deadline: under the half hour a Stripe page needs.
        Carbon::setTestNow(Carbon::parse('2026-10-01 17:55:00', 'UTC'));
        $this->getJson("/api/v1/kitchen-orders/{$order->uuid}", $this->header())->assertOk()->assertJsonPath('data.order.can_pay_online', false);

        foreach (['2026-10-01 17:55:00', '2026-10-04 12:00:00'] as $late) { // inside the notice; after the pickup itself
            Carbon::setTestNow(Carbon::parse($late, 'UTC'));
            $this->postJson($url, [], $this->header() + ['Origin' => self::SITE])
                ->assertStatus(422)
                ->assertJsonPath('message', 'This order can no longer be paid online: the kitchen needs payment at least 48 hours before pickup. Please contact the office.');
        }

        $this->assertSame([], $captured->params, 'no page was made');
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->fresh()->payment_status);
        Mail::assertNotQueued(KitchenOrderForOffice::class);
    }

    #[Test]
    public function a_card_order_can_be_paid_again_on_the_kitchen_page_while_there_is_time(): void
    {
        $order = $this->cardOrder();
        $captured = $this->stubCheckout();

        Carbon::setTestNow(Carbon::parse('2026-10-01 13:00:00', 'UTC'));
        $this->getJson("/api/v1/kitchen-orders/{$order->uuid}", $this->header())->assertOk()->assertJsonPath('data.order.can_pay_online', true);

        $this->postJson("/api/v1/kitchen-orders/{$order->uuid}/checkout", [], $this->header() + ['Origin' => self::SITE])
            ->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://stripe.test/checkout/kitchen');

        // The replacement for the expired page keeps the kitchen's deadline.
        $this->assertSame(Carbon::parse('2026-10-01 18:00:00', 'UTC')->getTimestamp(), $captured->params['expires_at']);
        $this->assertSame(self::SITE . '/kitchen/order/' . $order->uuid . '?cancelled=1', $captured->params['cancel_url']);
    }

    #[Test]
    public function a_card_order_cannot_be_paid_once_the_catalogue_stops_taking_orders(): void
    {
        $order = $this->cardOrder();
        $captured = $this->stubCheckout();
        $this->menu->forceFill(['status' => MealMenu::STATUS_CLOSED])->save();

        $this->postJson("/api/v1/kitchen-orders/{$order->uuid}/checkout", [], $this->header() + ['Origin' => self::SITE])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This kitchen is not taking orders right now.')
            ->assertJsonPath('data.order.can_pay_online', false);

        $this->assertSame([], $captured->params);
    }

    #[Test]
    public function a_card_order_whose_pickup_leaves_no_time_to_pay_is_refused_before_it_is_written(): void
    {
        $this->stubCheckout();

        // Exactly the notice is enough for an order paid to the office, not for card:
        // its page would have to stop taking payment the moment it opened.
        $this->placeOrder(['payment_method' => PaymentMethods::CARD, 'pickup_at' => '2026-10-03T08:00'], ['Origin' => self::SITE])
            ->assertStatus(422)
            ->assertJsonPath('message', 'To pay by card online, please choose a pickup on or after Saturday, October 3 at 8:31 AM, or choose another way to pay.');
        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());

        $this->placeOrder(['payment_method' => PaymentMethods::CARD, 'pickup_at' => '2026-10-03T08:31'], ['Origin' => self::SITE])->assertOk();
    }

    #[Test]
    public function the_office_can_still_send_a_card_link_inside_the_notice_but_it_stops_at_the_pickup(): void
    {
        $order = $this->cardOrder();
        $captured = $this->stubCheckout();
        Sanctum::actingAs($this->adminFor($this->masjid));

        // Six hours before the pickup: too late for the customer's own page.
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
        $this->postJson("/api/v1/kitchen-orders/{$order->uuid}/checkout", [], $this->header() + ['Origin' => self::SITE])->assertStatus(422);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/payment-link")
            ->assertOk();
        $this->assertSame(Carbon::parse('2026-10-03 18:00:00', 'UTC')->getTimestamp(), $captured->params['expires_at']);
    }

    // ------------------------------------------------ the kitchen's checkout door (review 9)

    #[Test]
    public function an_order_paid_to_the_office_never_gets_a_card_page(): void
    {
        $captured = $this->stubCheckout();
        $this->placeOrder()->assertOk();
        $order = MealOrder::withoutMasjidScope()->sole();

        $this->postJson("/api/v1/kitchen-orders/{$order->uuid}/checkout", [], $this->header() + ['Origin' => self::SITE])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order is paid to the office, not online.');

        $this->assertSame([], $captured->params);
        $this->assertNull($order->fresh()->stripe_checkout_session_id);
        $this->assertSame(MealOrder::METHOD_PICKUP, $order->fresh()->payment_method);
    }

    // ------------------------------------------------------------- the menu's switches

    #[Test]
    public function card_is_neither_offered_nor_taken_while_the_menu_has_online_payment_off(): void
    {
        $this->stubCheckout();
        $this->menu->forceFill(['allow_online_payment' => false])->save();

        $methods = $this->getJson("/api/v1/kitchen-menus/{$this->menu->uuid}", $this->header())
            ->assertOk()
            ->json('data.menu.payment_methods');
        $this->assertNotContains(PaymentMethods::CARD, array_column($methods, 'method'));

        $this->placeOrder(['payment_method' => PaymentMethods::CARD], ['Origin' => self::SITE])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That way of paying is not available for this kitchen. Please choose another.');
        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function with_email_collection_off_an_address_sent_anyway_is_neither_kept_nor_written_to(): void
    {
        $this->menu->forceFill(['collect_customer_email' => false])->save();

        $this->placeOrder(['customer_email' => 'buyer@kitchen.example.test'])->assertOk();

        $this->assertNull(MealOrder::withoutMasjidScope()->sole()->customer_email);
        Mail::assertNotQueued(KitchenOrderForCustomer::class);
        Mail::assertQueued(KitchenOrderForOffice::class, 1);
    }

    #[Test]
    public function a_dish_marked_unavailable_is_neither_listed_nor_orderable(): void
    {
        $this->soup->forceFill(['is_available' => false])->save();

        $this->getJson("/api/v1/kitchen-menus/{$this->menu->uuid}", $this->header())
            ->assertOk()
            ->assertJsonCount(1, 'data.menu.items')
            ->assertJsonPath('data.menu.items.0.id', $this->tray->id);

        $this->placeOrder(['items' => [['item_id' => $this->soup->id, 'quantity' => 1]]])->assertStatus(422);
        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------- the office's confirmation

    #[Test]
    public function marking_a_pending_order_ready_is_the_offices_confirmation_and_tells_the_customer_once(): void
    {
        $admin = $this->adminFor($this->masjid);
        $this->placeOrder()->assertOk();
        $order = MealOrder::withoutMasjidScope()->sole();
        Sanctum::actingAs($admin);

        $this->putJson($this->statusUrl($order), ['status' => MealOrder::STATUS_READY])->assertOk();

        $order->refresh();
        $this->assertNotNull($order->confirmed_at);
        $this->assertSame((int) $admin->id, $order->confirmed_by_user_id);
        Mail::assertQueued(KitchenOrderForCustomer::class, fn (KitchenOrderForCustomer $mail) => $mail->kind === KitchenOrderForCustomer::CONFIRMED);
        $this->assertCount(1, Mail::queued(KitchenOrderForCustomer::class, fn (KitchenOrderForCustomer $mail) => $mail->kind === KitchenOrderForCustomer::CONFIRMED));
    }

    #[Test]
    public function collecting_a_pending_order_records_the_confirmation_but_emails_nobody_at_the_counter(): void
    {
        $admin = $this->adminFor($this->masjid);
        $this->placeOrder()->assertOk();
        $order = MealOrder::withoutMasjidScope()->sole();
        Sanctum::actingAs($admin);

        $this->putJson($this->statusUrl($order), ['status' => MealOrder::STATUS_PICKED_UP])->assertOk();

        $order->refresh();
        $this->assertNotNull($order->confirmed_at);
        $this->assertSame((int) $admin->id, $order->confirmed_by_user_id);
        Mail::assertNotQueued(KitchenOrderForCustomer::class, fn (KitchenOrderForCustomer $mail) => $mail->kind === KitchenOrderForCustomer::CONFIRMED);
    }

    #[Test]
    public function an_unpaid_card_order_cannot_be_confirmed_and_its_customer_is_never_told_confirmed(): void
    {
        $order = $this->cardOrder();
        Sanctum::actingAs($this->adminFor($this->masjid));

        foreach ([MealOrder::STATUS_CONFIRMED, MealOrder::STATUS_READY, MealOrder::STATUS_PICKED_UP] as $status) {
            $this->putJson($this->statusUrl($order), ['status' => $status])
                ->assertStatus(422)
                ->assertJsonPath('data', 'This order\'s card payment has not come in, so it cannot be confirmed yet. If the customer paid another way, use Mark paid first.');
        }

        $order->refresh();
        $this->assertSame(MealOrder::STATUS_PENDING, $order->status);
        $this->assertNull($order->confirmed_at);

        // Any other caller of the notifier is held to the same rule.
        app(\App\Services\Kitchen\KitchenOrderNotifier::class)->confirmed($order);
        Mail::assertNothingQueued();

        // Once the card is paid, the office confirms it as any other.
        $this->signedWebhook($this->sessionEvent($order->fresh()))->assertOk();
        $this->putJson($this->statusUrl($order), ['status' => MealOrder::STATUS_CONFIRMED])->assertOk();
        $this->assertNotNull($order->fresh()->confirmed_at);
        Mail::assertQueued(KitchenOrderForCustomer::class, fn (KitchenOrderForCustomer $mail) => $mail->kind === KitchenOrderForCustomer::CONFIRMED);
    }

    #[Test]
    public function a_cancelled_order_is_never_announced_to_the_office_or_the_customer(): void
    {
        $order = $this->cardOrder();
        $order->forceFill(['status' => MealOrder::STATUS_CANCELLED])->save();

        // Paid on a page left open after the office cancelled it: money the office
        // refunds, never an order to announce.
        $this->signedWebhook($this->sessionEvent($order->fresh()))->assertOk();

        Mail::assertNotQueued(KitchenOrderForOffice::class);
        Mail::assertNotQueued(KitchenOrderForCustomer::class);

        $this->placeOrder()->assertOk(); // an order paid to the office, which confirmed() would otherwise email
        $offline = MealOrder::withoutMasjidScope()->where('payment_method', MealOrder::METHOD_PICKUP)->sole();
        $offline->forceFill(['status' => MealOrder::STATUS_CANCELLED])->save();

        app(\App\Services\Kitchen\KitchenOrderNotifier::class)->confirmed($offline->fresh());
        Mail::assertNotQueued(KitchenOrderForCustomer::class, fn (KitchenOrderForCustomer $mail) => $mail->kind === KitchenOrderForCustomer::CONFIRMED);
    }

    // ------------------------------------------------------ who the office emails go to

    #[Test]
    public function office_addresses_are_real_addresses_at_most_five_and_the_organisations_own_is_always_one(): void
    {
        $menu = new MealMenu(['notify_emails' => 'a@x.example.test, not-an-email, b@x.example.test']);
        $this->assertSame(['a@x.example.test', 'b@x.example.test'], \App\Services\Kitchen\KitchenOrderNotifier::officeRecipients($menu, null));

        $seven = implode(', ', array_map(fn ($i) => "cook{$i}@x.example.test", range(1, 7)));
        $this->assertCount(5, \App\Services\Kitchen\KitchenOrderNotifier::officeRecipients(new MealMenu(['notify_emails' => $seven]), null));

        // Typed addresses never cut the organisation out.
        $recipients = \App\Services\Kitchen\KitchenOrderNotifier::officeRecipients($this->menu, $this->masjid);
        $this->assertSame([strtolower($this->masjid->email), 'office@kitchen.example.test', 'cook@kitchen.example.test'], $recipients);
    }

    #[Test]
    public function the_organisations_name_for_its_other_method_is_what_the_office_and_the_board_read(): void
    {
        $row = new OrganisationPaymentMethod(['method' => PaymentMethods::OTHER, 'label' => 'Venmo', 'instructions' => 'Send to @kitchen-test', 'sort_order' => 9]);
        $row->masjid_id = $this->masjid->id;
        $row->save();

        $this->placeOrder(['payment_method' => PaymentMethods::OTHER])->assertOk();

        Mail::assertQueued(KitchenOrderForOffice::class, fn (KitchenOrderForOffice $mail) => $mail->paymentLine === '$90.00 to pay by Venmo.');

        Sanctum::actingAs($this->adminFor($this->masjid));
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.orders.0.preferred_payment_label', 'Venmo');
    }

    // ------------------------------------------------------------ the public order read

    #[Test]
    public function an_offboarded_organisations_kitchen_order_is_not_found(): void
    {
        $this->placeOrder()->assertOk();
        $order = MealOrder::withoutMasjidScope()->sole();

        $this->masjid->delete();

        $this->getJson("/api/v1/kitchen-orders/{$order->uuid}", $this->header())->assertNotFound();
    }

    // ------------------------------------------------------ orders taken by phone (review 2)

    #[Test]
    public function the_office_takes_a_phone_order_paid_to_it_when_the_organisation_has_no_stripe(): void
    {
        $captured = $this->stubCheckout();
        $this->masjid->forceFill(['stripe_charges_enabled' => false])->save();
        // The menu's public switch narrows the website, not the office.
        $this->menu->forceFill(['allow_pay_at_pickup' => false])->save();
        Sanctum::actingAs($this->adminFor($this->masjid));
        $url = "/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders";
        $body = [
            'items' => [['item_id' => $this->tray->id, 'quantity' => 1]],
            'customer_name' => 'Phone Buyer',
            'customer_email' => 'phone@kitchen.example.test',
            'pickup_at' => '2026-10-02T12:00',
        ];

        // The board lists the organisation's methods, card marked not ready.
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.payment_methods.0.method', PaymentMethods::CARD)
            ->assertJsonPath('data.payment_methods.0.ready', false)
            ->assertJsonPath('data.payment_methods.1.method', PaymentMethods::ZELLE)
            ->assertJsonPath('data.payment_methods.1.ready', true);

        $this->postJson($url, $body)->assertStatus(422)->assertJsonPath('data', 'Choose how the customer will pay.');
        $this->postJson($url, $body + ['payment_method' => PaymentMethods::CARD])->assertStatus(422);
        $this->postJson($url, $body + ['payment_method' => PaymentMethods::CHECK])->assertStatus(422); // not accepted
        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());

        $this->postJson($url, $body + ['payment_method' => PaymentMethods::ZELLE])
            ->assertStatus(201)
            ->assertJsonPath('checkout_url', null)
            ->assertJsonPath('message', 'Order #' . MealOrder::withoutMasjidScope()->value('order_number') . ' added, to be paid by Zelle. Use Mark paid when the money comes.');

        $order = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame(MealOrder::METHOD_PICKUP, $order->payment_method);
        $this->assertSame(PaymentMethods::ZELLE, $order->preferred_payment);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status);
        $this->assertSame(MealOrder::SOURCE_STAFF, $order->source);
        $this->assertNull($order->stripe_checkout_session_id);
        $this->assertSame([], $captured->params, 'no Stripe page for an order paid to the office');
        Mail::assertQueued(KitchenOrderForOffice::class, fn (KitchenOrderForOffice $mail) => $mail->paymentLine === '$90.00 to pay by Zelle.');
    }

    // ---------------------------------------------------------------------- helpers

    private function statusUrl(MealOrder $order): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/status";
    }

    private function header(): array
    {
        return ['masjid-id' => (string) $this->masjid->id];
    }

    private function orderBody(array $overrides = []): array
    {
        return array_merge([
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->tray->id, 'quantity' => 1]],
            'customer_name' => 'Test Buyer',
            'customer_phone' => '5550100000',
            'customer_email' => 'buyer@kitchen.example.test',
            // Saturday 14:00 in New York, 50 hours ahead.
            'pickup_at' => '2026-10-03T14:00',
            'payment_method' => PaymentMethods::ZELLE,
        ], $overrides);
    }

    private function placeOrder(array $overrides = [], array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/kitchen-orders', $this->orderBody($overrides), $this->header() + $headers);
    }

    private function cardOrder(): MealOrder
    {
        $this->stubCheckout();
        $this->placeOrder(['payment_method' => PaymentMethods::CARD], ['Origin' => self::SITE])->assertOk();

        return MealOrder::withoutMasjidScope()->sole();
    }

    /** @param  array<string, ?string>  $methods */
    private function accept(Masjid $masjid, array $methods): void
    {
        $position = 0;

        foreach ($methods as $method => $instructions) {
            $row = new OrganisationPaymentMethod(['method' => $method, 'instructions' => $instructions, 'sort_order' => $position++]);
            $row->masjid_id = $masjid->id;
            $row->save();
        }
    }

    /** A checkout service that never calls Stripe and remembers what it would have sent. */
    private function stubCheckout(): object
    {
        $captured = new class
        {
            public array $params = [];
        };

        $this->app->bind(MealOrderCheckoutService::class, function ($app) use ($captured) {
            return new class($app->make(StripeClient::class), $captured) extends MealOrderCheckoutService
            {
                public function __construct(StripeClient $stripe, private object $captured)
                {
                    parent::__construct($stripe);
                }

                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    $this->captured->params = $params;

                    return ['id' => 'cs_test_kitchen', 'url' => 'https://stripe.test/checkout/kitchen', 'payment_intent' => 'pi_test_kitchen'];
                }

                /** Every page on record has expired, so asking for one makes a new one. */
                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    return ['status' => 'expired', 'payment_status' => 'unpaid', 'url' => null];
                }
            };
        });

        return $captured;
    }

    private function sessionEvent(MealOrder $order): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'type' => 'checkout.session.completed',
            'account' => $this->masjid->stripe_account_id,
            'data' => ['object' => [
                'id' => $order->stripe_checkout_session_id ?? 'cs_test_kitchen',
                'object' => 'checkout.session',
                'status' => 'complete',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_kitchen',
                'metadata' => ['order_uuid' => $order->uuid, 'masjid_id' => (string) $this->masjid->id],
            ]],
        ];
    }

    private function intentEvent(MealOrder $order): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'account' => $this->masjid->stripe_account_id,
            'data' => ['object' => ['id' => 'pi_test_kitchen', 'object' => 'payment_intent', 'metadata' => ['order_uuid' => $order->uuid]]],
        ];
    }

    private function signedWebhook(array $event): TestResponse
    {
        config(['services.stripe.webhook_secret' => 'whsec_kitchen']);
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_kitchen');

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function makeMasjid(bool $payable): Masjid
    {
        return Masjid::create([
            'name' => 'Kitchen Test Org ' . uniqid(),
            'email' => 'office' . uniqid() . '@org.example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => false, 'org_type' => 'masjid',
            'timezone' => 'America/New_York',
            'stripe_account_id' => $payable ? 'acct_test_' . uniqid() : null,
            'stripe_charges_enabled' => $payable,
            'stripe_payouts_enabled' => $payable,
        ]);
    }

    private function adminFor(Masjid $masjid): User
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }
}
