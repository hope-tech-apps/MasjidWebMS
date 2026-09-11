<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\MealMenus\MarkMealOrderPaidRequest;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\User;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Services\Stripe\MealOrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Mark paid on the lunch board says how the money came (DECISIONS.md 2026-09-11):
 * Cash, Zelle, Masjid Terminal or Stripe, recorded in `paid_via` beside who
 * pressed it. It works on any unpaid order that is not cancelled, pickup or
 * online. An order's own Stripe page is closed first, on the locked row, so the
 * customer cannot pay by card as well, and a refusal records nothing.
 *
 * Stripe is faked through MealOrderCheckoutService's protected seams, as
 * StaffLunchOrderTest does. Every request is form-encoded, the way the board
 * posts.
 */
class MealOrderMarkPaidTest extends TestCase
{
    use RefreshDatabase;

    private const PAID_BY_CARD = 'This order was already paid by card online. It will show as paid in a moment.';

    private const STRIPE_DOWN = "Stripe did not answer, so this order's card payment link could not be closed and nothing was recorded. Try again in a moment.";

    private const PAID_ON_ITS_PAGE = 'This order was already paid by card online, so nothing was recorded. If you also took money for it by hand, give that back.';

    /** Stripe, faked: what a lookup reports, how a close is answered, and what happened. */
    public static string $pageStatus = 'open';
    public static ?string $pagePaymentStatus = 'unpaid';
    /**
     * How Stripe answers a close: null closes it; 'paid' and 'clearing' refuse
     * because the customer finished the page a moment before (by card, or by a
     * bank debit whose money has not moved); 'still-open' refuses with the page
     * still live; 'network' never answers.
     */
    public static ?string $expireRefusal = null;
    /** Every lookup fails to connect. */
    public static bool $lookupDown = false;
    public static int $lookups = 0;
    public static int $pagesMade = 0;
    /** Payment pages closed (expired), in order. */
    public static array $expired = [];
    /** DB::transactionLevel() at each page made: above the test's own, it was made under the row lock. */
    public static array $levelsAtCreate = [];

    private Masjid $masjid;
    private User $admin;
    private MealMenu $menu;
    private MealMenuItem $plate;

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
        $this->plate = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Chicken Biryani Plate', 'price_minor' => 800,
        ]);

        $this->fakeStripe();
    }

    private function fakeStripe(): void
    {
        self::$pageStatus = 'open';
        self::$pagePaymentStatus = 'unpaid';
        self::$expireRefusal = null;
        self::$lookupDown = false;
        self::$lookups = 0;
        self::$pagesMade = 0;
        self::$expired = [];
        self::$levelsAtCreate = [];

        $this->app->bind(MealOrderCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends MealOrderCheckoutService
            {
                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    MealOrderMarkPaidTest::$levelsAtCreate[] = DB::transactionLevel();
                    $n = ++MealOrderMarkPaidTest::$pagesMade;

                    return ['id' => "cs_test_{$n}", 'url' => "https://stripe.test/pay/{$n}", 'payment_intent' => null];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                    switch (MealOrderMarkPaidTest::$expireRefusal) {
                        case 'paid':
                            MealOrderMarkPaidTest::$pageStatus = 'complete';
                            MealOrderMarkPaidTest::$pagePaymentStatus = 'paid';
                            throw \Stripe\Exception\InvalidRequestException::factory('This Checkout Session is not in an expirable state.');
                        case 'clearing':
                            MealOrderMarkPaidTest::$pageStatus = 'complete';
                            MealOrderMarkPaidTest::$pagePaymentStatus = 'unpaid';
                            throw \Stripe\Exception\InvalidRequestException::factory('This Checkout Session is not in an expirable state.');
                        case 'still-open':
                            throw \Stripe\Exception\InvalidRequestException::factory('Refused.');
                        case 'network':
                            throw \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe.');
                    }

                    MealOrderMarkPaidTest::$expired[] = $sessionId;
                    MealOrderMarkPaidTest::$pageStatus = 'expired';
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    MealOrderMarkPaidTest::$lookups++;

                    if (MealOrderMarkPaidTest::$lookupDown) {
                        throw \Stripe\Exception\ApiConnectionException::factory('Could not connect to Stripe.');
                    }

                    $status = MealOrderMarkPaidTest::$pageStatus;

                    return [
                        'status' => $status,
                        'payment_status' => MealOrderMarkPaidTest::$pagePaymentStatus,
                        'url' => $status === 'open' ? 'https://stripe.test/pay/' . substr($sessionId, 8) : null,
                    ];
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

    private function url(MealOrder $order, string $prefix = '/api/admin'): string
    {
        return "{$prefix}/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/mark-paid";
    }

    /** Form-encoded, exactly as the board's Mark paid dialog posts it. */
    private function markPaid(MealOrder $order, string $via, string $prefix = '/api/admin')
    {
        return $this->post($this->url($order, $prefix), ['paid_via' => $via], ['Accept' => 'application/json']);
    }

    private function linkFor(MealOrder $order)
    {
        return $this->post("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/payment-link", [], ['Accept' => 'application/json']);
    }

    private function pickupOrder(array $attributes = []): MealOrder
    {
        return MealOrder::factory()->create(array_merge([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
        ], $attributes));
    }

    /**
     * An order taken on the board, the shape of order #018: online, unpaid, its
     * Stripe page (cs_test_1) open. Taken by the admin, who stays signed in.
     */
    private function cardOrder(): MealOrder
    {
        Sanctum::actingAs($this->admin);
        $this->post("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders", [
            'customer_name' => 'Card Customer',
            'customer_phone' => '+1 555 010 2030',
            'items' => [['item_id' => $this->plate->id, 'quantity' => 1]],
        ], ['Accept' => 'application/json'])->assertCreated();

        $order = MealOrder::withoutMasjidScope()->latest('id')->firstOrFail();
        $this->assertSame(MealOrder::METHOD_ONLINE, $order->payment_method);
        $this->assertSame('cs_test_1', $order->stripe_checkout_session_id);

        return $order;
    }

    /** A refusal leaves the order as it was: unpaid, no method, nobody, its page still on record. */
    private function assertNothingRecorded(MealOrder $order, ?string $page = 'cs_test_1'): void
    {
        $order->refresh();
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->payment_status);
        $this->assertNull($order->paid_via);
        $this->assertNull($order->marked_paid_by_user_id);
        $this->assertNull($order->paid_at);
        $this->assertSame($page, $order->stripe_checkout_session_id);
    }

    #[Test]
    public function each_way_of_paying_is_recorded_on_a_pickup_order_with_who_took_it(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (MealOrder::PAID_VIA as $via) {
            $order = $this->pickupOrder();

            $this->markPaid($order, $via)
                ->assertOk()
                ->assertJsonPath('data.paid_via', $via)
                ->assertJsonPath('data.payment_status', MealOrder::PAYMENT_PAID)
                ->assertJsonPath('data.marked_paid_by.name', $this->admin->name);

            $order->refresh();
            $this->assertSame($via, $order->paid_via);
            $this->assertSame((int) $this->admin->id, $order->marked_paid_by_user_id);
            $this->assertNotNull($order->paid_at);
            // The channel it was ordered through stays; paid_via says how the money came.
            $this->assertSame(MealOrder::METHOD_PICKUP, $order->payment_method);
        }

        // None of them had a card page, so Stripe was never asked.
        $this->assertSame(0, self::$lookups);
        $this->assertSame(['cash', 'zelle', 'terminal', 'stripe'], MealOrder::PAID_VIA);
        $this->assertSame(['Cash', 'Zelle', 'Masjid Terminal', 'Stripe'], array_values(MealOrder::PAID_VIA_LABELS));
    }

    #[Test]
    public function a_missing_or_unknown_way_of_paying_is_refused_and_nothing_is_written(): void
    {
        Sanctum::actingAs($this->admin);
        $order = $this->pickupOrder();

        $bodies = [[], ['paid_via' => ''], ['paid_via' => 'card'], ['paid_via' => 'Cash'], ['paid_via' => 'online'], ['paid_via' => ['cash']]];

        foreach ($bodies as $body) {
            $this->post($this->url($order), $body, ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonPath('status', 'failed')
                // One sentence in `data`: a board on the bundle from before this
                // question existed shows `data` only when it is a string.
                ->assertJsonPath('data', MarkMealOrderPaidRequest::refusal());
        }

        $this->assertNothingRecorded($order, null);
        $this->assertSame('Choose how they paid: Cash, Zelle, Masjid Terminal or Stripe. If the board does not ask, reload the page.', MarkMealOrderPaidRequest::refusal());
    }

    #[Test]
    public function a_card_order_with_an_open_page_has_it_closed_and_is_then_marked_paid(): void
    {
        $order = $this->cardOrder();

        $this->markPaid($order, MealOrder::PAID_VIA_ZELLE)
            ->assertOk()
            ->assertJsonPath('data.paid_via', MealOrder::PAID_VIA_ZELLE)
            ->assertJsonPath('data.stripe_checkout_session_id', null);

        $this->assertSame(['cs_test_1'], self::$expired);
        $order->refresh();
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);
        $this->assertSame(MealOrder::PAID_VIA_ZELLE, $order->paid_via);
        $this->assertSame((int) $this->admin->id, $order->marked_paid_by_user_id);
        $this->assertSame(MealOrder::METHOD_ONLINE, $order->payment_method);
        // Forgotten, so nothing can reopen it, and no new page is made for a paid order.
        $this->assertNull($order->stripe_checkout_session_id);
        $this->linkFor($order)->assertStatus(422);
        $this->assertSame(1, self::$pagesMade);
    }

    #[Test]
    public function an_expired_page_is_forgotten_and_nothing_needs_closing(): void
    {
        $order = $this->cardOrder();
        self::$pageStatus = 'expired';

        $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertOk();

        $this->assertSame([], self::$expired);
        $order->refresh();
        $this->assertNull($order->stripe_checkout_session_id);
        $this->assertSame(MealOrder::PAID_VIA_CASH, $order->paid_via);
    }

    #[Test]
    public function a_card_order_with_no_page_on_record_is_marked_paid_without_asking_stripe(): void
    {
        Sanctum::actingAs($this->admin);
        $order = MealOrder::factory()->online()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id]);

        $this->markPaid($order, MealOrder::PAID_VIA_TERMINAL)->assertOk();

        $this->assertSame(0, self::$lookups);
        $this->assertSame(MealOrder::PAID_VIA_TERMINAL, $order->fresh()->paid_via);
    }

    #[Test]
    public function a_page_already_paid_by_card_is_refused_and_nothing_is_recorded(): void
    {
        $order = $this->cardOrder();
        self::$pageStatus = 'complete';
        self::$pagePaymentStatus = 'paid';

        $this->markPaid($order, MealOrder::PAID_VIA_CASH)
            ->assertStatus(422)
            ->assertJsonPath('data', self::PAID_BY_CARD);

        $this->assertNothingRecorded($order);
        $this->assertSame([], self::$expired);
    }

    #[Test]
    public function a_bank_payment_still_clearing_is_refused_and_nothing_is_recorded(): void
    {
        $order = $this->cardOrder();
        self::$pageStatus = 'complete';
        self::$pagePaymentStatus = 'unpaid';

        $response = $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertStatus(422);
        $this->assertStringContainsString('still clearing', $response->json('data'));

        $this->assertNothingRecorded($order);
        $this->assertSame([], self::$expired);
    }

    #[Test]
    public function a_close_stripe_refuses_is_asked_about_again_and_nothing_is_recorded(): void
    {
        $order = $this->cardOrder();

        // The customer paid by card a moment before the close landed.
        self::$expireRefusal = 'paid';
        $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertStatus(422)->assertJsonPath('data', self::PAID_BY_CARD);
        $this->assertNothingRecorded($order);

        // A bank debit finished the page a moment before: its money has not moved.
        self::$pageStatus = 'open';
        self::$pagePaymentStatus = 'unpaid';
        self::$expireRefusal = 'clearing';
        $response = $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertStatus(422);
        $this->assertStringContainsString('still clearing', $response->json('data'));
        $this->assertNothingRecorded($order);

        // Refused, and the page is still open: it could still be paid.
        self::$pageStatus = 'open';
        self::$expireRefusal = 'still-open';
        $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertStatus(422)
            ->assertJsonPath('data', "Stripe would not close this order's card payment link, so it was not marked paid. Refresh the board and try again.");
        $this->assertNothingRecorded($order);

        $this->assertSame([], self::$expired);
    }

    #[Test]
    public function when_stripe_cannot_be_asked_nothing_is_recorded(): void
    {
        $order = $this->cardOrder();

        self::$lookupDown = true;
        $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertStatus(422)->assertJsonPath('data', self::STRIPE_DOWN);
        $this->assertNothingRecorded($order);

        // The lookup answers; the close never does.
        self::$lookupDown = false;
        self::$expireRefusal = 'network';
        $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertStatus(422)->assertJsonPath('data', self::STRIPE_DOWN);
        $this->assertNothingRecorded($order);

        // No Stripe account on record: nobody to ask about a page that may still be payable.
        $account = $this->masjid->stripe_account_id;
        $this->masjid->forceFill(['stripe_account_id' => null])->save();
        self::$expireRefusal = null;
        $response = $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertStatus(422);
        $this->assertStringContainsString('not on record', $response->json('data'));
        $this->assertNothingRecorded($order);

        // Once Stripe answers, the same press goes through.
        $this->masjid->forceFill(['stripe_account_id' => $account])->save();
        $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertOk();
        $this->assertSame(['cs_test_1'], self::$expired);
        $this->assertSame(MealOrder::PAID_VIA_CASH, $order->fresh()->paid_via);
    }

    #[Test]
    public function a_cancelled_order_is_not_marked_paid(): void
    {
        Sanctum::actingAs($this->admin);
        $order = $this->pickupOrder(['status' => MealOrder::STATUS_CANCELLED]);

        $this->markPaid($order, MealOrder::PAID_VIA_CASH)
            ->assertStatus(422)
            ->assertJsonPath('data', 'This order was cancelled. Restore it before marking it paid.');

        $this->assertNothingRecorded($order, null);
    }

    #[Test]
    public function a_second_press_never_rewrites_how_it_was_paid_or_who_took_it(): void
    {
        $order = $this->cardOrder();
        $volunteer = $this->staff($this->masjid, User::TYPE_LUNCH_STAFF, 'lunch-staff');

        Sanctum::actingAs($volunteer);
        $this->markPaid($order, MealOrder::PAID_VIA_CASH, '/api/lunch')->assertOk();
        $paidAt = $order->fresh()->paid_at;
        $lookups = self::$lookups;

        // A colleague, later, choosing something else.
        $this->travel(5)->minutes();
        Sanctum::actingAs($this->admin);
        $this->markPaid($order, MealOrder::PAID_VIA_ZELLE)
            ->assertOk()
            ->assertJsonPath('data.paid_via', MealOrder::PAID_VIA_CASH);

        $order->refresh();
        $this->assertSame(MealOrder::PAID_VIA_CASH, $order->paid_via);
        $this->assertSame((int) $volunteer->id, $order->marked_paid_by_user_id);
        $this->assertEquals($paidAt, $order->paid_at);
        // Already paid, so Stripe was not asked again.
        $this->assertSame($lookups, self::$lookups);
    }

    /**
     * The stale board (review 2026-09-11). The customer paid on the order's own page
     * and the webhook recorded it, while a board up to 15 seconds behind still showed
     * the order unpaid and a volunteer took cash as well. The press is refused, as it
     * is in the seconds before the webhook lands (closePageBeforePaidByHand), so
     * whether staff are told never depends on how fast Stripe delivers. The forms'
     * take-cash refuses the same case (FormResponsesController::settleByHand).
     */
    #[Test]
    public function a_press_on_an_order_already_paid_on_its_own_card_page_is_refused_and_nothing_is_written(): void
    {
        Sanctum::actingAs($this->admin);
        $order = MealOrder::factory()->online()->paid()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'stripe_payment_intent_id' => 'pi_test_card',
        ]);
        $paidAt = $order->fresh()->paid_at;

        $this->markPaid($order, MealOrder::PAID_VIA_CASH)
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('data', self::PAID_ON_ITS_PAGE);

        $order->refresh();
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);
        $this->assertNull($order->paid_via);
        $this->assertNull($order->marked_paid_by_user_id);
        $this->assertEquals($paidAt, $order->paid_at);
        // Settled already, so Stripe is not asked.
        $this->assertSame(0, self::$lookups);
    }

    /**
     * What the board reads before it says anything (review 2026-09-11): a press
     * that wrote the payment answers `recorded: true`; one that found the order
     * already marked paid by hand answers `recorded: false`, with how and by whom it
     * was first recorded. The board then never toasts a method the server did not
     * write. It is still a 200 (DECISIONS.md 2026-09-11, 3).
     */
    #[Test]
    public function every_press_says_whether_it_recorded_the_payment(): void
    {
        Sanctum::actingAs($this->admin);
        $order = $this->pickupOrder();

        $this->markPaid($order, MealOrder::PAID_VIA_ZELLE)
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('data.paid_via', MealOrder::PAID_VIA_ZELLE);

        // A colleague whose board still shows it unpaid records Cash.
        $volunteer = $this->staff($this->masjid, User::TYPE_LUNCH_STAFF, 'lunch-staff');
        Sanctum::actingAs($volunteer);
        $this->markPaid($order, MealOrder::PAID_VIA_CASH, '/api/lunch')
            ->assertOk()
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('data.paid_via', MealOrder::PAID_VIA_ZELLE)
            ->assertJsonPath('data.marked_paid_by.name', $this->admin->name);
        $this->assertSame(MealOrder::PAID_VIA_ZELLE, $order->fresh()->paid_via);

        // A pickup order marked paid before the board asked how, or before it said
        // who: paid by hand all the same, so a 200 that records nothing.
        $legacy = $this->pickupOrder();
        $legacy->forceFill(['payment_status' => MealOrder::PAYMENT_PAID, 'paid_at' => now()])->save();
        $this->markPaid($legacy, MealOrder::PAID_VIA_CASH, '/api/lunch')
            ->assertOk()
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('data.paid_via', null);
        $this->assertNull($legacy->fresh()->paid_via);
        $this->assertNull($legacy->fresh()->marked_paid_by_user_id);
    }

    /**
     * An order's FIRST page is made on the locked row (review 2026-09-11), as every
     * later one is (paymentLink). It used to be made on the copy the order's own
     * request had just written, after that write committed: a volunteer who marked
     * the order paid in between found no page to close, and the page made a moment
     * later was recorded on the paid order and handed to the customer, payable for
     * 24 hours. Now the row is read again under the lock, and a paid or cancelled
     * order gets no page.
     */
    #[Test]
    public function a_first_page_is_never_made_for_an_order_paid_by_hand_or_cancelled_while_it_waited(): void
    {
        Sanctum::actingAs($this->admin);
        $payments = app(MealOrderCheckoutService::class);

        $order = MealOrder::factory()->online()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id]);
        // The copy the order's own request holds, read before anyone else acted.
        $readByTheOrder = MealOrder::withoutMasjidScope()->with('items')->findOrFail($order->id);

        // A volunteer takes cash first. No page is on record yet, so nothing to close.
        $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertOk();

        // Caught into a variable, never around an assertion: PHPUnit's own failures
        // are RuntimeExceptions too.
        $refused = null;
        try {
            $payments->checkout($readByTheOrder);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $this->assertSame(0, self::$pagesMade, 'An order paid by hand must not be given a payment page.');
        $this->assertStringContainsString('already been paid', (string) $refused);
        $order->refresh();
        $this->assertNull($order->stripe_checkout_session_id);
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);
        $this->assertSame(MealOrder::PAID_VIA_CASH, $order->paid_via);
        // The caller's copy says what the row says, so its answer is never "unpaid".
        $this->assertSame(MealOrder::PAYMENT_PAID, $readByTheOrder->payment_status);

        // Cancelled in between: no page either.
        $cancelled = MealOrder::factory()->online()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id]);
        $read = MealOrder::withoutMasjidScope()->with('items')->findOrFail($cancelled->id);
        $cancelled->forceFill(['status' => MealOrder::STATUS_CANCELLED])->save();

        $refused = null;
        try {
            $payments->checkout($read);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $this->assertSame(0, self::$pagesMade, 'A cancelled order must not be given a payment page.');
        $this->assertStringContainsString('cancelled', (string) $refused);
        $this->assertNull($cancelled->fresh()->stripe_checkout_session_id);
    }

    /**
     * A page recorded while the first one waited for the lock (Payment link, pressed
     * on the brand-new order) is the answer: that same page, never a second payable
     * one. Before the lock, both requests made a page, and the later write left the
     * other live and unrecorded, so nothing could close it.
     */
    #[Test]
    public function a_page_recorded_while_the_first_one_waited_is_handed_back_not_doubled(): void
    {
        $order = MealOrder::factory()->online()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id]);
        $readByTheOrder = MealOrder::withoutMasjidScope()->with('items')->findOrFail($order->id);
        $order->forceFill(['stripe_checkout_session_id' => 'cs_test_9'])->save();

        $result = app(MealOrderCheckoutService::class)->checkout($readByTheOrder);

        $this->assertSame('https://stripe.test/pay/9', $result['checkout_url']);
        $this->assertSame(0, self::$pagesMade);
        $this->assertSame('cs_test_9', $order->fresh()->stripe_checkout_session_id);
        $this->assertSame('cs_test_9', $readByTheOrder->stripe_checkout_session_id);
    }

    /**
     * The other half of the race above: while a page is being made, the row stays
     * locked, so Mark paid waits and then closes the page it finds. SQLite cannot
     * hold one request while another runs, so this pins the lock's precondition
     * instead: every page, the first one on Add order included, is asked of Stripe
     * inside a transaction opened after the test's own.
     */
    #[Test]
    public function every_payment_page_is_made_inside_the_transaction_that_locked_the_row(): void
    {
        $outside = DB::transactionLevel();

        $order = $this->cardOrder();               // Add order on the board: the first page
        self::$pageStatus = 'expired';
        $this->linkFor($order)->assertOk();        // Payment link: a replacement page

        $this->assertSame(2, self::$pagesMade);
        foreach (self::$levelsAtCreate as $level) {
            $this->assertGreaterThan($outside, $level);
        }
    }

    /**
     * Stripe reports the order's page paid while the order is unpaid here: the
     * refusal decision 4 asks for, and also the one moment the server holds proof
     * that a webhook may not be arriving, as happened on this platform once before
     * (a Connect endpoint on a redirecting apex). Logged at warning, by ids only.
     */
    #[Test]
    public function stripe_reporting_a_page_paid_that_is_unpaid_here_is_logged_by_ids(): void
    {
        $order = $this->cardOrder();
        self::$pageStatus = 'complete';
        self::$pagePaymentStatus = 'paid';
        Log::spy();

        $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertStatus(422)->assertJsonPath('data', self::PAID_BY_CARD);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) use ($order) {
                return str_contains($message, 'webhook')
                    && ($context['order_uuid'] ?? null) === $order->uuid
                    && ($context['masjid_id'] ?? null) === (int) $this->masjid->id
                    && ($context['checkout_session_id'] ?? null) === 'cs_test_1'
                    && ! str_contains(json_encode($context), 'Card Customer');
            })
            ->once();
        $this->assertNothingRecorded($order);
    }

    #[Test]
    public function the_board_shows_how_each_order_was_paid_and_who_marked_it(): void
    {
        Sanctum::actingAs($this->admin);
        // Paid on its own Stripe page, by the webhook: no method is recorded.
        MealOrder::factory()->online()->paid()->create(['masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id]);
        $byHand = $this->pickupOrder();
        $this->markPaid($byHand, MealOrder::PAID_VIA_TERMINAL)->assertOk();

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.orders.0.id', $byHand->id)
            ->assertJsonPath('data.orders.0.paid_via', MealOrder::PAID_VIA_TERMINAL)
            ->assertJsonPath('data.orders.0.marked_paid_by.name', $this->admin->name)
            ->assertJsonPath('data.orders.1.paid_via', null)
            ->assertJsonPath('data.orders.1.marked_paid_by', null)
            ->assertJsonPath('data.summary.paid_orders', 2);
    }

    #[Test]
    public function a_card_payment_on_an_order_paid_by_hand_is_logged_as_paid_twice_and_rewrites_nothing(): void
    {
        $order = $this->cardOrder();
        $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertOk();
        $paidAt = $order->fresh()->paid_at;
        $this->travel(5)->minutes();

        Log::spy();
        $payments = app(MealOrderPaymentService::class);
        $account = $this->masjid->stripe_account_id;

        // One charge raises both events; checkout.session.async_payment_succeeded
        // runs the same handler as the completed session.
        $payments->handleCheckoutCompleted([
            'id' => 'cs_test_1', 'status' => 'complete', 'payment_status' => 'paid',
            'payment_intent' => 'pi_test_1', 'metadata' => ['order_uuid' => $order->uuid],
        ], $account);
        $payments->handlePaymentIntentSucceeded(['id' => 'pi_test_1', 'metadata' => ['order_uuid' => $order->uuid]], $account);

        $order->refresh();
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);
        $this->assertSame(MealOrder::PAID_VIA_CASH, $order->paid_via);
        $this->assertSame((int) $this->admin->id, $order->marked_paid_by_user_id);
        $this->assertEquals($paidAt, $order->paid_at);
        // The charge is findable in Stripe; its page is not put back on the order.
        $this->assertSame('pi_test_1', $order->stripe_payment_intent_id);
        $this->assertNull($order->stripe_checkout_session_id);

        // Said once for the one charge, by ids: never the customer's name or phone.
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) use ($order) {
                $said = json_encode($context);

                return str_contains($message, 'paid twice')
                    && ($context['order_uuid'] ?? null) === $order->uuid
                    && ($context['payment_intent_id'] ?? null) === 'pi_test_1'
                    && ($context['paid_via'] ?? null) === MealOrder::PAID_VIA_CASH
                    && ! str_contains($said, 'Card Customer')
                    && ! str_contains($said, '010 2030');
            })
            ->once();

        // A second, different charge is said too, and never recorded over the first.
        $payments->handlePaymentIntentSucceeded(['id' => 'pi_test_2', 'metadata' => ['order_uuid' => $order->uuid]], $account);
        $this->assertSame('pi_test_1', $order->fresh()->stripe_payment_intent_id);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'paid twice') && ($context['payment_intent_id'] ?? null) === 'pi_test_2')
            ->once();

        // Where the organisation looks, not only the server log: the board row carries
        // both how staff recorded it and the card charge, the pair only a double
        // payment leaves, so the board can say "refund one" beside it.
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.orders.0.id', $order->id)
            ->assertJsonPath('data.orders.0.paid_via', MealOrder::PAID_VIA_CASH)
            ->assertJsonPath('data.orders.0.stripe_payment_intent_id', 'pi_test_1');
    }

    #[Test]
    public function another_organisation_cannot_mark_this_order_paid(): void
    {
        $order = $this->pickupOrder();
        $other = $this->org();
        Sanctum::actingAs($this->staff($other, 'MasjidAdmin', 'masjid-admin'));

        // Through this organisation's URL: the tenant gate refuses.
        $this->markPaid($order, MealOrder::PAID_VIA_CASH)->assertForbidden();

        // Through its own organisation's URL: this order is not in its tenant.
        $this->post("/api/admin/masjids/{$other->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/mark-paid",
            ['paid_via' => MealOrder::PAID_VIA_CASH], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertNothingRecorded($order, null);
    }

    #[Test]
    public function the_column_exists_starts_empty_and_is_never_filled_from_a_request_body(): void
    {
        $this->assertTrue(Schema::hasColumn('meal_orders', 'paid_via'));
        $this->assertNull($this->pickupOrder()->fresh()->paid_via);

        // Not mass-assignable: it moves only through markPaidByHand().
        $this->assertNull((new MealOrder(['paid_via' => MealOrder::PAID_VIA_CASH]))->paid_via);
    }

    #[Test]
    public function the_model_refuses_a_way_of_paying_it_does_not_know(): void
    {
        $order = $this->pickupOrder();

        $this->expectException(\InvalidArgumentException::class);
        $order->markPaidByHand('cheque', (int) $this->admin->id);
    }
}
