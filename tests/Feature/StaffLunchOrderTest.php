<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\User;
use App\Services\Stripe\MealOrderCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Orders taken by staff on the lunch board — admins, SuperAdmins and lunch
 * volunteers — without going through the public order link.
 *
 * Prices come from the menu, never the request; the online ordering window
 * does not apply (walk-ups happen after it closes); nobody is opted into
 * texts; the order says who took it; and it is CHARGED like any public order —
 * through Stripe, never marked paid by the person entering it.
 */
class StaffLunchOrderTest extends TestCase
{
    use RefreshDatabase;

    /** Stripe, faked: pages made so far, what a lookup reports, and an outage. */
    public static int $pagesMade = 0;
    public static string $pageStatus = 'open';
    public static bool $stripeDown = false;

    private Masjid $masjid;
    private User $admin;
    private MealMenu $menu;
    private MealMenuItem $biryani;
    private MealMenuItem $water;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->org();
        $this->admin = $this->staff($this->masjid, 'MasjidAdmin', 'masjid-admin');
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $this->biryani = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Chicken Biryani Plate', 'price_minor' => 800,
        ]);
        $this->water = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Water', 'price_minor' => 100, 'max_quantity' => 2,
        ]);

        $this->fakeStripe();
    }

    private function fakeStripe(): void
    {
        self::$pagesMade = 0;
        self::$pageStatus = 'open';
        self::$stripeDown = false;

        $this->app->bind(MealOrderCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends MealOrderCheckoutService
            {
                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    if (StaffLunchOrderTest::$stripeDown) {
                        throw \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe.');
                    }

                    $n = ++StaffLunchOrderTest::$pagesMade;

                    return ['id' => "cs_test_{$n}", 'url' => "https://stripe.test/pay/{$n}", 'payment_intent' => null];
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    $status = StaffLunchOrderTest::$pageStatus;

                    return ['status' => $status, 'url' => $status === 'open' ? 'https://stripe.test/pay/' . substr($sessionId, 8) : null];
                }
            };
        });
    }

    private function org(): Masjid
    {
        return Masjid::create([
            'name' => 'Lunch Org ' . uniqid(),
            'email' => 'lunch' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => false, 'org_type' => 'masjid',
            // Stripe Connect onboarding complete: it can take card payments.
            'stripe_account_id' => 'acct_test_' . uniqid(),
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
        ]);
    }

    private function staff(Masjid $masjid, string $type, string $role): User
    {
        $user = User::factory()->create(['type' => $type, 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => $role, 'is_default' => true]);

        return $user->fresh();
    }

    /** Form-encoded, exactly as the board posts it. */
    private function order(string $prefix, array $fields)
    {
        return $this->post("{$prefix}/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders", $fields, ['Accept' => 'application/json']);
    }

    private function linkFor(MealOrder $order, string $prefix = '/api/admin')
    {
        return $this->post("{$prefix}/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/payment-link", [], ['Accept' => 'application/json']);
    }

    private function onePlate(): array
    {
        return ['customer_name' => 'After Jummah', 'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]]];
    }

    #[Test]
    public function an_admin_takes_an_order_priced_by_the_server_and_charged_through_stripe(): void
    {
        Sanctum::actingAs($this->admin);

        $this->order('/api/admin', [
            'customer_name' => 'Walk-up Brother',
            'items' => [
                ['item_id' => $this->biryani->id, 'quantity' => 2],
                ['item_id' => $this->water->id, 'quantity' => 1],
            ],
            // Ignored: prices never come from the request, and nobody entering
            // an order can declare it paid.
            'total_minor' => 1, 'unit_price_minor' => 1, 'paid' => '1', 'payment_status' => 'paid',
        ])->assertCreated()
            ->assertJsonPath('data.order_number', '001')
            ->assertJsonPath('checkout_url', 'https://stripe.test/pay/1');

        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $this->assertSame(1700, $order->total_minor);
        $this->assertSame(MealOrder::SOURCE_STAFF, $order->source);
        $this->assertSame((int) $this->admin->id, $order->entered_by_user_id);
        $this->assertSame(MealOrder::METHOD_ONLINE, $order->payment_method);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status);
        $this->assertNull($order->paid_at);
        $this->assertSame(MealOrder::STATUS_PENDING, $order->status);
        $this->assertSame('cs_test_1', $order->stripe_checkout_session_id);
        $this->assertSame(0, (int) $order->fee_covered_minor);
    }

    #[Test]
    public function a_lunch_volunteer_cannot_mark_their_own_order_paid(): void
    {
        $volunteer = $this->staff($this->masjid, User::TYPE_LUNCH_STAFF, 'lunch-staff');
        Sanctum::actingAs($volunteer);

        $this->order('/api/lunch', [
            'customer_name' => 'Volunteer, own lunch',
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 2]],
            'paid' => '1',
        ])->assertCreated()->assertJsonPath('checkout_url', 'https://stripe.test/pay/1');

        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status);
        $this->assertSame((int) $volunteer->id, $order->entered_by_user_id);

        // Nor by hand afterwards: an online order is marked paid by Stripe only.
        $this->post("/api/lunch/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/mark-paid", [], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->fresh()->payment_status);

        // The volunteer can hand the customer the payment page again.
        $this->linkFor($order, '/api/lunch')->assertOk()->assertJsonPath('data.checkout_url', 'https://stripe.test/pay/1');
    }

    #[Test]
    public function a_super_admin_can_add_an_order_too(): void
    {
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550005555'])->fresh());

        $this->order('/api/admin', [
            'customer_name' => 'Phone order', 'customer_phone' => '+1 555 000 1234',
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame(2400, MealOrder::withoutMasjidScope()->value('total_minor'));
    }

    #[Test]
    public function staff_can_still_take_orders_after_online_ordering_closes_but_not_on_a_draft(): void
    {
        Sanctum::actingAs($this->admin);

        $this->menu->update(['status' => MealMenu::STATUS_CLOSED]);
        $this->order('/api/admin', $this->onePlate())->assertCreated();

        $this->menu->update(['status' => MealMenu::STATUS_DRAFT]);
        $this->order('/api/admin', $this->onePlate())->assertStatus(422);
    }

    #[Test]
    public function nothing_is_written_when_the_order_cannot_be_charged(): void
    {
        Sanctum::actingAs($this->admin);

        // Online payment switched off for this lunch.
        $this->menu->forceFill(['allow_online_payment' => false])->save();
        $this->order('/api/admin', $this->onePlate())->assertStatus(422);

        // An organisation that has not finished Stripe onboarding.
        $this->menu->forceFill(['allow_online_payment' => true])->save();
        $this->masjid->forceFill(['stripe_charges_enabled' => false])->save();
        $this->order('/api/admin', $this->onePlate())->assertStatus(422);

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
        $this->assertSame(0, self::$pagesMade);
    }

    #[Test]
    public function when_stripe_does_not_answer_the_order_stands_and_the_board_can_retry(): void
    {
        Sanctum::actingAs($this->admin);
        self::$stripeDown = true;

        $response = $this->order('/api/admin', $this->onePlate())->assertCreated()->assertJsonPath('checkout_url', null);
        $this->assertStringContainsString('Payment link', (string) $response->json('message'));

        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status);

        self::$stripeDown = false;
        $this->linkFor($order)->assertOk()->assertJsonPath('data.checkout_url', 'https://stripe.test/pay/1');
    }

    #[Test]
    public function a_payment_link_reuses_an_open_page_and_replaces_an_expired_one(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();

        // Still open: the same page, so the customer never holds two ways to pay.
        $this->linkFor($order)->assertOk()->assertJsonPath('data.checkout_url', 'https://stripe.test/pay/1');
        $this->assertSame(1, self::$pagesMade);

        // Expired after 24 hours: a new page, under a new idempotency key.
        $firstKey = $order->fresh()->idempotency_key;
        self::$pageStatus = 'expired';
        $this->linkFor($order)->assertOk()->assertJsonPath('data.checkout_url', 'https://stripe.test/pay/2');
        $this->assertSame(2, self::$pagesMade);
        $this->assertNotSame($firstKey, $order->fresh()->idempotency_key);
        $this->assertSame('cs_test_2', $order->fresh()->stripe_checkout_session_id);

        // Paid on Stripe but not yet recorded: left to the webhook.
        self::$pageStatus = 'complete';
        $this->linkFor($order)->assertStatus(422);
        $this->assertSame(2, self::$pagesMade);
    }

    #[Test]
    public function a_payment_link_moves_a_pickup_order_online_and_refuses_paid_or_cancelled_orders(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();

        // As the public link would have left a pay-at-pickup order.
        $order->forceFill(['payment_method' => MealOrder::METHOD_PICKUP, 'idempotency_key' => null, 'stripe_checkout_session_id' => null])->save();

        $this->linkFor($order)->assertOk();
        // Online now, so it cannot also be marked paid by hand.
        $this->assertSame(MealOrder::METHOD_ONLINE, $order->fresh()->payment_method);

        $order->forceFill(['status' => MealOrder::STATUS_CANCELLED])->save();
        $this->linkFor($order)->assertStatus(422);

        $order->forceFill(['status' => MealOrder::STATUS_CONFIRMED, 'payment_status' => MealOrder::PAYMENT_PAID, 'paid_at' => now()])->save();
        $this->linkFor($order)->assertStatus(422);
    }

    #[Test]
    public function items_must_be_on_this_menu_available_and_within_the_cap(): void
    {
        Sanctum::actingAs($this->admin);
        $otherMenu = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $elsewhere = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $otherMenu->id, 'name' => 'Other plate', 'price_minor' => 900,
        ]);
        $soldOut = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id, 'name' => 'Sold out', 'price_minor' => 700, 'is_available' => false,
        ]);

        $this->order('/api/admin', ['customer_name' => 'X', 'items' => [['item_id' => $elsewhere->id, 'quantity' => 1]]])->assertStatus(422);
        $this->order('/api/admin', ['customer_name' => 'X', 'items' => [['item_id' => $soldOut->id, 'quantity' => 1]]])->assertStatus(422);
        $this->order('/api/admin', ['customer_name' => 'X', 'items' => [['item_id' => $this->water->id, 'quantity' => 3]]])->assertStatus(422);
        $this->order('/api/admin', ['customer_name' => 'X', 'items' => []])->assertStatus(422);

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function staff_orders_never_opt_anyone_into_texts(): void
    {
        Sanctum::actingAs($this->admin);

        $this->order('/api/admin', [
            'customer_name' => 'No consent given', 'customer_phone' => '+15550009999',
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]],
            'notify_sms' => '1',
        ])->assertCreated();

        // The staff door has no SMS path at all: the controller never reads
        // notify_sms, so nothing downstream can have recorded a consent.
        $this->assertStringNotContainsString(
            'LunchSmsOptIn',
            file_get_contents(app_path('Http/Controllers/AdminDashboard/MealOrdersController.php'))
        );
    }

    #[Test]
    public function the_board_shows_who_took_each_order(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.orders.0.source', 'staff')
            ->assertJsonPath('data.orders.0.entered_by.name', $this->admin->name);
    }

    #[Test]
    public function another_organisation_cannot_add_to_this_menu(): void
    {
        $other = $this->org();
        Sanctum::actingAs($this->staff($other, 'MasjidAdmin', 'masjid-admin'));

        // Through its own organisation's URL: this menu is not in its tenant.
        $this->post("/api/admin/masjids/{$other->id}/jummah-lunch/menus/{$this->menu->id}/orders",
            $this->onePlate(), ['Accept' => 'application/json'])->assertNotFound();

        // Through this organisation's URL: the tenant gate refuses.
        $this->order('/api/admin', $this->onePlate())->assertForbidden();

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }
}
