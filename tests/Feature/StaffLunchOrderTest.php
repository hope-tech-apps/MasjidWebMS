<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\User;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\StripeFees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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
 * through Stripe, never marked paid by the person entering it — with the same
 * optional extra and covered card fee the public page offers.
 */
class StaffLunchOrderTest extends TestCase
{
    use RefreshDatabase;

    /** Stripe, faked: pages made so far, what a lookup reports, and an outage. */
    public static int $pagesMade = 0;
    public static string $pageStatus = 'open';
    public static bool $stripeDown = false;
    /** The parameters of the last Checkout Session asked for — what Stripe would charge. */
    public static array $lastParams = [];
    /** Every idempotency key Stripe was sent, in order — including failed attempts. */
    public static array $keys = [];
    /** Payment pages closed (expired), in order. */
    public static array $expired = [];
    /**
     * How Stripe answers a close: null closes it; 'paid' refuses because the
     * customer paid a moment before (the page is complete); 'still-open'
     * refuses with the page still live; 'network' never answers.
     */
    public static ?string $expireRefusal = null;

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
        self::$lastParams = [];
        self::$keys = [];
        self::$expired = [];
        self::$expireRefusal = null;

        $this->app->bind(MealOrderCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends MealOrderCheckoutService
            {
                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    StaffLunchOrderTest::$keys[] = $idempotencyKey;

                    if (StaffLunchOrderTest::$stripeDown) {
                        throw \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe.');
                    }

                    StaffLunchOrderTest::$lastParams = $params;
                    $n = ++StaffLunchOrderTest::$pagesMade;

                    return ['id' => "cs_test_{$n}", 'url' => "https://stripe.test/pay/{$n}", 'payment_intent' => null];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                    switch (StaffLunchOrderTest::$expireRefusal) {
                        case 'paid':
                            StaffLunchOrderTest::$pageStatus = 'complete';
                            throw \Stripe\Exception\InvalidRequestException::factory('This Checkout Session is not in an expirable state.');
                        case 'still-open':
                            throw \Stripe\Exception\InvalidRequestException::factory('Refused.');
                        case 'network':
                            throw \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe.');
                    }

                    StaffLunchOrderTest::$expired[] = $sessionId;
                    StaffLunchOrderTest::$pageStatus = 'expired';
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

    private function linkFor(MealOrder $order, string $prefix = '/api/admin', array $fields = [])
    {
        return $this->post("{$prefix}/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/payment-link", $fields, ['Accept' => 'application/json']);
    }

    private function statusFor(MealOrder $order, string $status)
    {
        return $this->call('PUT', "/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/status", ['status' => $status], [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function markPaidFor(MealOrder $order, string $prefix = '/api/admin')
    {
        return $this->post("{$prefix}/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/mark-paid", [], ['Accept' => 'application/json']);
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

        // The retry went out under a NEW key: the failed attempt's key could have
        // a saved failure replayed for 24h, or clash with different parameters.
        $this->assertCount(2, self::$keys);
        $this->assertNotSame(self::$keys[0], self::$keys[1]);
        $this->assertSame(self::$keys[1], $order->fresh()->idempotency_key);
    }

    #[Test]
    public function a_removed_volunteer_is_still_named_on_the_orders_they_took(): void
    {
        $volunteer = $this->staff($this->masjid, User::TYPE_LUNCH_STAFF, 'lunch-staff');
        Sanctum::actingAs($volunteer);
        $this->order('/api/lunch', $this->onePlate())->assertCreated();

        // Removing a login soft-deletes it; the office still needs to know who took the order.
        $volunteer->delete();
        $this->assertSoftDeleted('users', ['id' => $volunteer->id]);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.orders.0.entered_by.name', $volunteer->name);
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
    public function staff_can_add_an_extra_cover_the_card_fee_or_both(): void
    {
        Sanctum::actingAs($this->admin);
        // Pinned, so the expected cents below are literals rather than the
        // output of the StripeFees call the code under test also makes.
        config(['services.stripe.fee_percentage' => 0.029, 'services.stripe.fee_fixed' => 30]);
        $food = 1600; // two plates
        $plate = 'Chicken Biryani Plate';

        foreach ([
            // Every shape a form-encoded client sends a yes/no in.
            'extra only' => ['donation_minor' => '500', 'cover_fees' => 'false', 'donation' => 500, 'fee' => 0,
                'lines' => [$plate, 'Additional donation']],
            'fee only' => ['donation_minor' => '0', 'cover_fees' => 'true', 'donation' => 0, 'fee' => 79,
                'lines' => [$plate, 'Card processing fee']],
            'both' => ['donation_minor' => '500', 'cover_fees' => '1', 'donation' => 500, 'fee' => 94,
                'lines' => [$plate, 'Additional donation', 'Card processing fee']],
        ] as $case => $c) {
            $this->order('/api/admin', [
                'customer_name' => $case,
                'items' => [['item_id' => $this->biryani->id, 'quantity' => 2]],
                'donation_minor' => $c['donation_minor'],
                'cover_fees' => $c['cover_fees'],
                // Ignored: the fee is a yes/no, never an amount from the body.
                'fee_covered_minor' => '1',
            ])->assertCreated();

            $order = MealOrder::withoutMasjidScope()->where('customer_name', $case)->firstOrFail();
            $this->assertSame($food, $order->subtotal_minor, $case);
            $this->assertSame($c['donation'], $order->donation_minor, $case);
            $this->assertSame($c['fee'], $order->fee_covered_minor, $case);
            $this->assertSame($food + $c['donation'] + $c['fee'], $order->total_minor, $case);

            // What Stripe is asked to charge: exactly these named lines, once
            // each, in this order, summing to the stored total. Raw lines, not
            // keyed by name — a duplicated line must not be able to hide.
            $raw = self::$lastParams['line_items'];
            $this->assertSame($c['lines'], array_map(fn ($l) => $l['price_data']['product_data']['name'], $raw), $case);
            $this->assertSame($order->total_minor, array_sum(array_map(fn ($l) => $l['price_data']['unit_amount'] * $l['quantity'], $raw)), $case);
        }

        // $8 of food + $5 extra with the fee covered: the organisation nets $13.
        $this->assertSame(2100, StripeFees::grossUp(2100) - StripeFees::on(StripeFees::grossUp(2100)));
    }

    #[Test]
    public function the_extra_and_the_fee_follow_the_menu_and_the_bounds(): void
    {
        Sanctum::actingAs($this->admin);
        $one = fn (array $extra) => $this->order('/api/admin', $this->onePlate() + $extra);

        // Beyond the ceiling, negative, a fraction, or a non-answer: refused, not rounded.
        $one(['donation_minor' => (string) (MealOrder::MAX_DONATION_MINOR + 1)])->assertStatus(422);
        $one(['donation_minor' => '-100'])->assertStatus(422);
        $one(['donation_minor' => '2.5'])->assertStatus(422);
        $one(['cover_fees' => 'maybe'])->assertStatus(422);
        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());

        // A menu that offers neither charges neither, whatever the body says.
        $this->menu->forceFill(['allow_donation' => false, 'allow_fee_coverage' => false])->save();
        $one(['donation_minor' => '500', 'cover_fees' => '1'])->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $this->assertSame(0, $order->donation_minor);
        $this->assertSame(0, $order->fee_covered_minor);
        $this->assertSame(800, $order->total_minor);
    }

    #[Test]
    public function the_fee_is_grossed_up_on_what_is_actually_charged_not_on_a_refused_extra(): void
    {
        Sanctum::actingAs($this->admin);

        config(['services.stripe.fee_percentage' => 0.029, 'services.stripe.fee_fixed' => 30]);

        // Extra switched off, fee coverage on: the $5 asked for is refused, so
        // the fee must cover the food alone. Grossing up on the refused extra
        // would charge the payer for money nobody is sending.
        $this->menu->forceFill(['allow_donation' => false, 'allow_fee_coverage' => true])->save();
        $this->order('/api/admin', $this->onePlate() + ['donation_minor' => '500', 'cover_fees' => 'true'])->assertCreated();

        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $this->assertSame(0, $order->donation_minor);
        $this->assertSame(55, $order->fee_covered_minor);   // on $8.00, not on $13.00 (which would be 70)
        $this->assertSame(855, $order->total_minor);
    }

    #[Test]
    public function the_board_prices_from_the_menu_payload_in_both_realms(): void
    {
        // Non-default, so a match proves the values come from config rather than
        // agreeing with the board's own fallbacks (2.9% + 30c, $1,000).
        config(['services.stripe.fee_percentage' => 0.022, 'services.stripe.fee_fixed' => 25]);
        $volunteer = $this->staff($this->masjid, User::TYPE_LUNCH_STAFF, 'lunch-staff');

        foreach (['/api/admin' => $this->admin, '/api/lunch' => $volunteer] as $prefix => $user) {
            Sanctum::actingAs($user);
            $this->getJson("{$prefix}/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}")
                ->assertOk()
                ->assertJsonPath('data.max_donation_minor', MealOrder::MAX_DONATION_MINOR)
                ->assertJsonPath('data.stripe_fee_percentage', 0.022)
                ->assertJsonPath('data.stripe_fee_fixed_minor', 25);
        }
    }

    #[Test]
    public function a_new_menu_keeps_the_extra_and_fee_switches_the_admin_chose(): void
    {
        Sanctum::actingAs($this->admin);

        // Form-encoded "0"s, exactly as the board's create form now sends them.
        $this->post("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus", [
            'title' => 'Switches off', 'service_date' => now()->addWeeks(9)->toDateString(),
            'status' => MealMenu::STATUS_DRAFT, 'allow_donation' => '0', 'allow_fee_coverage' => '0',
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $menu = MealMenu::withoutMasjidScope()->where('title', 'Switches off')->firstOrFail();
        $this->assertFalse((bool) $menu->allow_donation);
        $this->assertFalse((bool) $menu->allow_fee_coverage);
    }

    #[Test]
    public function the_board_counts_items_ordered_in_total_and_per_item_without_cancelled_orders(): void
    {
        Sanctum::actingAs($this->admin);

        $this->order('/api/admin', ['customer_name' => 'A', 'items' => [
            ['item_id' => $this->biryani->id, 'quantity' => 2],
            ['item_id' => $this->water->id, 'quantity' => 1],
        ]])->assertCreated();
        $this->order('/api/admin', ['customer_name' => 'B', 'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]]])->assertCreated();
        $this->order('/api/admin', ['customer_name' => 'C', 'items' => [['item_id' => $this->biryani->id, 'quantity' => 2]]])->assertCreated();

        // A cancelled order is not cooked.
        MealOrder::withoutMasjidScope()->where('customer_name', 'C')->firstOrFail()
            ->forceFill(['status' => MealOrder::STATUS_CANCELLED])->save();

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.summary.orders', 3)
            ->assertJsonPath('data.summary.items_ordered', 4)
            ->assertJsonPath('data.summary.cancelled_orders', 1)
            ->assertJsonPath('data.summary.items_by_item', [
                ['meal_menu_item_id' => $this->biryani->id, 'item_name' => 'Chicken Biryani Plate', 'quantity' => 3],
                ['meal_menu_item_id' => $this->water->id, 'item_name' => 'Water', 'quantity' => 1],
            ]);
    }

    #[Test]
    public function the_menus_list_counts_orders_to_make_without_cancelled_ones(): void
    {
        Sanctum::actingAs($this->admin);

        $this->order('/api/admin', ['customer_name' => 'A', 'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]]])->assertCreated();
        $this->order('/api/admin', ['customer_name' => 'B', 'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]]])->assertCreated();

        // Cancelled on the board: it leaves the kitchen's list, so it leaves the card's count too.
        MealOrder::withoutMasjidScope()->where('customer_name', 'B')->firstOrFail()
            ->forceFill(['status' => MealOrder::STATUS_CANCELLED])->save();

        $card = collect($this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus")->assertOk()->json('data'))
            ->firstWhere('id', $this->menu->id);

        $this->assertSame(1, (int) $card['live_orders_count']);
        // The existing field keeps its meaning for anything already reading it.
        $this->assertSame(2, (int) $card['orders_count']);
    }

    #[Test]
    public function a_renamed_dish_counts_under_its_current_name_and_deleted_dishes_stay_apart(): void
    {
        Sanctum::actingAs($this->admin);
        $samosa = MealMenuItem::factory()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id, 'name' => 'Samosa', 'price_minor' => 200]);
        $dates = MealMenuItem::factory()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id, 'name' => 'Dates', 'price_minor' => 100]);

        $this->order('/api/admin', ['customer_name' => 'A', 'items' => [
            ['item_id' => $this->biryani->id, 'quantity' => 4],
            ['item_id' => $samosa->id, 'quantity' => 3],
            ['item_id' => $dates->id, 'quantity' => 2],
        ]])->assertCreated();

        // Renamed after the order: counted under the name the kitchen sees now.
        $this->biryani->forceFill(['name' => 'Beef Biryani Plate'])->save();
        // Two dishes removed from the menu: their lines lose the menu item id.
        $samosa->fresh()->delete();
        $dates->fresh()->delete();

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.summary.items_ordered', 9)
            ->assertJsonPath('data.summary.items_by_item', [
                ['meal_menu_item_id' => $this->biryani->id, 'item_name' => 'Beef Biryani Plate', 'quantity' => 4],
                ['meal_menu_item_id' => null, 'item_name' => 'Samosa', 'quantity' => 3],
                ['meal_menu_item_id' => null, 'item_name' => 'Dates', 'quantity' => 2],
            ]);
    }

    #[Test]
    public function a_payment_link_with_a_new_extra_and_fee_closes_the_old_page_and_charges_the_new_amount(): void
    {
        config(['services.stripe.fee_percentage' => 0.029, 'services.stripe.fee_fixed' => 30]);
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();   // page 1, open
        $order = MealOrder::withoutMasjidScope()->firstOrFail();

        $this->linkFor($order, '/api/admin', ['donation_minor' => '500', 'cover_fees' => 'true'])
            ->assertOk()->assertJsonPath('data.checkout_url', 'https://stripe.test/pay/2');

        // The page the customer may already hold was closed BEFORE the new one was made.
        $this->assertSame(['cs_test_1'], self::$expired);
        $order->refresh();
        $this->assertSame(500, $order->donation_minor);
        $this->assertSame(70, $order->fee_covered_minor);   // 2.9% + 30c grossed up on $13.00
        $this->assertSame(1370, $order->total_minor);
        $raw = self::$lastParams['line_items'];
        $this->assertSame(['Chicken Biryani Plate', 'Additional donation', 'Card processing fee'], array_map(fn ($l) => $l['price_data']['product_data']['name'], $raw));
        $this->assertSame(1370, array_sum(array_map(fn ($l) => $l['price_data']['unit_amount'] * $l['quantity'], $raw)));
    }

    #[Test]
    public function the_same_extra_and_fee_reuse_the_open_payment_page(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate() + ['donation_minor' => '500', 'cover_fees' => '1'])->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();

        $this->linkFor($order, '/api/admin', ['donation_minor' => '500', 'cover_fees' => '1'])
            ->assertOk()->assertJsonPath('data.checkout_url', 'https://stripe.test/pay/1');
        $this->assertSame([], self::$expired);
        $this->assertSame(1, self::$pagesMade);
    }

    #[Test]
    public function a_page_already_paid_on_stripe_is_never_repriced(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();

        self::$pageStatus = 'complete';
        $this->linkFor($order, '/api/admin', ['donation_minor' => '500'])->assertStatus(422);
        $this->assertSame([], self::$expired);
        $this->assertSame(0, $order->fresh()->donation_minor);
    }

    #[Test]
    public function cancelling_an_unpaid_online_order_closes_its_payment_page(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();

        $this->statusFor($order, 'cancelled')
            ->assertOk()->assertJsonPath('message', 'Cancelled, and its payment page is closed.')->assertJsonPath('warning', false);
        $this->assertSame(['cs_test_1'], self::$expired);
        $this->assertSame(MealOrder::STATUS_CANCELLED, $order->fresh()->status);
        // No live page is left, so the board stops offering to close one.
        $this->assertNull($order->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function marking_a_pickup_order_paid_records_who_took_the_money(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $order->forceFill(['payment_method' => MealOrder::METHOD_PICKUP, 'stripe_checkout_session_id' => null, 'idempotency_key' => null])->save();

        $volunteer = $this->staff($this->masjid, User::TYPE_LUNCH_STAFF, 'lunch-staff');
        Sanctum::actingAs($volunteer);
        $this->post("/api/lunch/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/mark-paid", [], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame((int) $volunteer->id, $order->fresh()->marked_paid_by_user_id);

        // A second press, by someone else, never rewrites who took the money.
        Sanctum::actingAs($this->admin);
        $this->post("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/mark-paid", [], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame((int) $volunteer->id, $order->fresh()->marked_paid_by_user_id);

        // Still named after their login is removed (a soft delete): that is when
        // the office asks who took the cash.
        $volunteer->delete();
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()->assertJsonPath('data.orders.0.marked_paid_by.name', $volunteer->name);
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

    #[Test]
    public function a_cancel_that_lands_first_stops_a_new_page_even_past_the_controller_check(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        self::$pageStatus = 'expired';

        // The request read the order before a colleague's cancel committed.
        $readBeforeTheCancel = $order->fresh();
        $order->forceFill(['status' => MealOrder::STATUS_CANCELLED])->save();

        try {
            app(MealOrderCheckoutService::class)->paymentLink($readBeforeTheCancel);
            $this->fail('A cancelled order must not get a new payment page.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }

        $this->assertSame(1, self::$pagesMade);
        $this->assertSame('cs_test_1', $order->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function when_the_customer_pays_as_the_page_is_closed_nothing_is_repriced_and_staff_are_told_it_is_paid(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();   // page 1, open
        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $key = $order->idempotency_key;
        self::$expireRefusal = 'paid';

        $this->linkFor($order, '/api/admin', ['donation_minor' => '500'])
            ->assertStatus(422)
            ->assertJsonPath('data', 'This order has been paid on Stripe. The board will show it as paid in a moment.');
        $order->refresh();
        $this->assertSame(1, self::$pagesMade);
        $this->assertSame(0, $order->donation_minor);
        $this->assertSame(800, $order->total_minor);
        $this->assertSame('cs_test_1', $order->stripe_checkout_session_id);
        $this->assertSame($key, $order->idempotency_key);

        // Cancelling in that same moment says so, instead of "the link still works".
        self::$pageStatus = 'open';
        $this->statusFor($order, 'cancelled')->assertOk()
            ->assertJsonPath('warning', true)
            ->assertJsonPath('message', 'This order had already been paid on Stripe, so it will show as paid. Refund it in Stripe if it should not stand.');
        $this->assertSame(MealOrder::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame('cs_test_1', $order->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function a_page_stripe_would_not_close_stays_as_it_was_and_the_board_can_close_it_later(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();

        self::$expireRefusal = 'still-open';
        $this->linkFor($order, '/api/admin', ['donation_minor' => '500'])->assertStatus(422);
        $this->assertSame(1, self::$pagesMade);
        $this->assertSame(0, $order->fresh()->donation_minor);

        self::$expireRefusal = 'network';
        $this->statusFor($order, 'cancelled')->assertOk()
            ->assertJsonPath('warning', true)
            ->assertJsonPath('message', 'Cancelled, but its payment page could not be closed, so the link still works. Press "Close payment page" on the order to try again.');
        $this->assertSame(MealOrder::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame('cs_test_1', $order->fresh()->stripe_checkout_session_id);

        // "Close payment page" sends the cancel again; this time Stripe answers.
        self::$expireRefusal = null;
        $this->statusFor($order, 'cancelled')->assertOk()
            ->assertJsonPath('warning', false)
            ->assertJsonPath('message', 'Cancelled, and its payment page is closed.');
        $this->assertSame(['cs_test_1'], self::$expired);
        $this->assertNull($order->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function cancelling_a_pickup_order_with_no_page_says_nothing_about_one(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $order->forceFill(['payment_method' => MealOrder::METHOD_PICKUP, 'stripe_checkout_session_id' => null, 'idempotency_key' => null])->save();

        $this->statusFor($order, 'cancelled')->assertOk()
            ->assertJsonPath('message', null)
            ->assertJsonPath('warning', false);
        $this->assertSame([], self::$expired);
    }

    #[Test]
    public function an_extra_or_fee_the_lunch_stopped_offering_stays_on_the_order_and_its_page(): void
    {
        config(['services.stripe.fee_percentage' => 0.029, 'services.stripe.fee_fixed' => 30]);
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate() + ['donation_minor' => '500', 'cover_fees' => '1'])->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $this->assertSame(1370, $order->total_minor);   // $8 + $5 + 70c fee on $13

        $this->menu->forceFill(['allow_donation' => false, 'allow_fee_coverage' => false])->save();

        // What a board loaded before the switch would send, and a crafted body.
        $this->linkFor($order, '/api/admin', ['donation_minor' => '0', 'cover_fees' => '0'])
            ->assertOk()->assertJsonPath('data.checkout_url', 'https://stripe.test/pay/1');
        $this->linkFor($order, '/api/admin', ['donation_minor' => '9000'])
            ->assertOk()->assertJsonPath('data.checkout_url', 'https://stripe.test/pay/1');

        $this->assertSame([], self::$expired);
        $this->assertSame(1, self::$pagesMade);
        $order->refresh();
        $this->assertSame(500, $order->donation_minor);
        $this->assertSame(70, $order->fee_covered_minor);
        $this->assertSame(1370, $order->total_minor);
    }

    #[Test]
    public function a_choice_left_out_keeps_what_the_order_carries(): void
    {
        config(['services.stripe.fee_percentage' => 0.029, 'services.stripe.fee_fixed' => 30]);
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate() + ['donation_minor' => '500'])->assertCreated();   // $13, no fee
        $order = MealOrder::withoutMasjidScope()->firstOrFail();

        // Only the fee was changed: the $5 stays, and the fee covers food and extra.
        $this->linkFor($order, '/api/admin', ['cover_fees' => '1'])
            ->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://stripe.test/pay/2')
            ->assertJsonPath('data.order.total_minor', 1370);
        $order->refresh();
        $this->assertSame(500, $order->donation_minor);
        $this->assertSame(70, $order->fee_covered_minor);
        $this->assertSame(['cs_test_1'], self::$expired);
    }

    #[Test]
    public function a_pickup_order_given_a_page_is_charged_the_online_fee_and_can_no_longer_be_marked_paid(): void
    {
        config(['services.stripe.fee_percentage' => 0.029, 'services.stripe.fee_fixed' => 30]);
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        // As the public page leaves a pay-at-pickup order: no page, and no fee.
        $order->forceFill(['payment_method' => MealOrder::METHOD_PICKUP, 'stripe_checkout_session_id' => null, 'idempotency_key' => null])->save();

        $this->linkFor($order, '/api/admin', ['cover_fees' => '1'])
            ->assertOk()->assertJsonPath('data.order.total_minor', 855);
        $order->refresh();
        $this->assertSame(55, $order->fee_covered_minor);
        $this->assertSame(855, $order->total_minor);
        $this->assertSame(MealOrder::METHOD_ONLINE, $order->payment_method);
        $this->assertSame(['Chicken Biryani Plate', 'Card processing fee'], array_map(fn ($l) => $l['price_data']['product_data']['name'], self::$lastParams['line_items']));

        // Online now: cash and a card payment cannot both be taken.
        $this->markPaidFor($order)->assertStatus(422);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->marked_paid_by_user_id);
    }

    #[Test]
    public function money_for_a_cancelled_order_is_recorded_and_said_out_loud(): void
    {
        Sanctum::actingAs($this->admin);
        $this->order('/api/admin', $this->onePlate())->assertCreated();
        $order = MealOrder::withoutMasjidScope()->firstOrFail();
        $order->forceFill(['status' => MealOrder::STATUS_CANCELLED])->save();

        Log::spy();
        app(\App\Services\Stripe\MealOrderPaymentService::class)->handlePaymentIntentSucceeded(
            ['id' => 'pi_test_1', 'metadata' => ['order_uuid' => $order->uuid]],
            $this->masjid->stripe_account_id
        );

        $this->assertSame(MealOrder::PAYMENT_PAID, $order->fresh()->payment_status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'cancelled meal order was paid'))
            ->once();
    }
}
