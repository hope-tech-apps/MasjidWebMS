<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\MealOrderEdit;
use App\Models\User;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\StripeFees;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Changing a lunch order after it was placed — by the customer on the link they
 * hold, and by staff on the board.
 *
 * The properties under test are the ones money depends on:
 *   - the server RE-PRICES every line from the menu. A price in the body is
 *     ignored, exactly as it is when the order is placed;
 *   - the customer's window is the cutoff, and their own payment: after either,
 *     the masjid decides;
 *   - staff have NO cutoff (that is the point of the endpoint) and may edit a
 *     PAID order — and when they do, the difference appears as money still owed
 *     or owed back, never as a payment;
 *   - a plate is not orderable across a menu, and never across organisations;
 *   - an order cannot be emptied, the optional extra is never touched, and the
 *     card fee moves only for an order that was already covering it;
 *   - an unpaid order's open Stripe page is for the OLD amount, so it is closed
 *     before the new one is written;
 *   - every edit is recorded against whoever made it.
 */
class MealOrderEditTest extends TestCase
{
    use RefreshDatabase;

    /** Stripe, faked: the pages made, the pages closed, and what a lookup reports. */
    public static int $pagesMade = 0;
    public static array $expired = [];
    public static string $pageStatus = 'open';
    public static ?string $pagePaymentStatus = null;

    private Masjid $masjid;
    private User $admin;
    private MealMenu $menu;
    private MealMenuItem $biryani;   // 800, no cap
    private MealMenuItem $water;     // 100, max 2

    private Masjid $otherOrg;
    private MealMenuItem $otherOrgPlate;

    private MealMenu $otherMenu;     // a second menu at the SAME organisation
    private MealMenuItem $otherMenuPlate;

    private int $orderNo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        app(TenantContext::class)->forgetTenant(); // the customer's own edit runs UNBOUND

        $this->masjid = $this->org();
        $this->admin = $this->staff($this->masjid, 'MasjidAdmin', 'masjid-admin');
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        // Service dates are named rather than generated: the table is unique on
        // (masjid_id, service_date), and two menus at one organisation must not
        // depend on a faker sequence to land on different Fridays.
        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->open()->create(['service_date' => '2027-01-08']);
        $this->biryani = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Chicken Biryani Plate', 'price_minor' => 800,
        ]);
        $this->water = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Water', 'price_minor' => 100, 'max_quantity' => 2,
        ]);

        // A second menu at the same organisation: its items are not orderable here.
        $this->otherMenu = MealMenu::factory()->forMasjid($this->masjid)->open()->create(['service_date' => '2027-01-15']);
        $this->otherMenuPlate = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->otherMenu->id,
            'name' => 'Next Week Plate', 'price_minor' => 900,
        ]);

        // Another organisation entirely.
        $this->otherOrg = $this->org();
        $otherOrgMenu = MealMenu::factory()->forMasjid($this->otherOrg)->open()->create(['service_date' => '2027-01-08']);
        $this->otherOrgPlate = MealMenuItem::factory()->create([
            'masjid_id' => $this->otherOrg->id, 'meal_menu_id' => $otherOrgMenu->id,
            'name' => 'Someone Else\'s Plate', 'price_minor' => 1,
        ]);

        $this->fakeStripe();
    }

    // ------------------------------------------------------------- the customer

    #[Test]
    public function a_customer_changes_their_own_order_and_every_line_is_re_priced_from_the_menu(): void
    {
        $order = $this->placeOrder([[$this->biryani, 2]]);

        $response = $this->editAsCustomer($order, [
            // A lying client price is sent and must be ignored.
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3, 'unit_price_minor' => 1, 'price_minor' => 1],
            ['meal_menu_item_id' => $this->water->id, 'quantity' => 1],
        ])->assertOk();

        // 3 × $8.00 + 1 × $1.00 = $25.00, from the menu, not the payload.
        $response->assertJsonPath('data.order.subtotal_minor', 2500);
        $response->assertJsonPath('data.order.total_minor', 2500);

        $order = $order->fresh()->load('items');
        $this->assertSame(2500, (int) $order->total_minor);
        $this->assertCount(2, $order->items);
        $this->assertSame(800, (int) $order->items->firstWhere('meal_menu_item_id', $this->biryani->id)->unit_price_minor);
        $this->assertSame(3, (int) $order->items->firstWhere('meal_menu_item_id', $this->biryani->id)->quantity);
    }

    #[Test]
    public function a_quantity_of_zero_removes_that_line(): void
    {
        $order = $this->placeOrder([[$this->biryani, 2], [$this->water, 1]]);

        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2],
            ['meal_menu_item_id' => $this->water->id, 'quantity' => 0],
        ])->assertOk()->assertJsonPath('data.order.total_minor', 1600);

        $order = $order->fresh()->load('items');
        $this->assertCount(1, $order->items);
        $this->assertNull($order->items->firstWhere('meal_menu_item_id', $this->water->id));
    }

    #[Test]
    public function an_edit_a_minute_before_the_cutoff_is_taken_and_a_minute_after_is_refused(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]]);

        // A minute before ordering closes.
        $this->menu->forceFill(['ordering_closes_at' => now()->addMinute()])->save();

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.order.total_minor', 1600);

        // A minute after it closed: the kitchen has counted the plates.
        $this->menu->forceFill(['ordering_closes_at' => now()->subMinute()])->save();

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Orders for this menu are closed.');

        $this->assertSame(1600, (int) $order->fresh()->total_minor, 'the refused edit changed nothing');
    }

    #[Test]
    public function a_paid_order_is_not_changed_for_the_customer_until_the_difference_is_paid_and_an_admin_may_change_it(): void
    {
        // Until 2026-09-24 a paid order was refused to the customer outright. The
        // owner's rule since: more plates are paid for FIRST (MealOrderTopUpTest has
        // the rest). What this pins is that asking changes nothing on the order.
        $order = $this->placeOrder([[$this->biryani, 1]], [
            'payment_status' => MealOrder::PAYMENT_PAID,
            'status' => MealOrder::STATUS_CONFIRMED,
            'paid_at' => now(),
        ]);

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.status', 'payment_required')
            ->assertJsonPath('data.amount_minor', 800);

        $this->assertSame(800, (int) $order->fresh()->total_minor);
        $this->assertSame(0, MealOrderEdit::withoutMasjidScope()->count());

        // The board may: that is the whole reason staff have their own endpoint.
        $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.total_minor', 1600);
    }

    #[Test]
    public function more_than_the_kitchens_cap_is_refused(): void
    {
        $order = $this->placeOrder([[$this->water, 1]]);

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->water->id, 'quantity' => 3]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only 2 × Water per order.');

        $this->assertSame(1, (int) $order->fresh()->load('items')->items->first()->quantity);
    }

    #[Test]
    public function repeating_one_item_across_many_rows_cannot_go_past_the_ceiling_a_single_row_has(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]]);

        // Biryani has no `max_quantity`: the kitchen never set one, which before
        // this ceiling meant the only bound on it was per ROW. The request rules
        // allow 100 rows of 99, the rows are summed, and 9,900 plates came out
        // the other side — $79,200 of food on an endpoint nobody signs in to use.
        $rows = array_fill(0, 100, ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 99]);

        $this->editAsCustomer($order, $rows)
            ->assertOk()
            ->assertJsonPath('data.order.subtotal_minor', 99 * 800)
            ->assertJsonPath('data.order.total_minor', 99 * 800);

        $order = $order->fresh()->load('items');
        $this->assertCount(1, $order->items);
        $this->assertSame(99, (int) $order->items->first()->quantity);
    }

    #[Test]
    public function the_ceiling_is_the_shared_one_every_door_prices_through(): void
    {
        // Asserted on the shared class itself, because the public order page and
        // the staff board sum repeated rows through this same method — the edit
        // endpoints were never the only door standing open.
        $wanted = \App\Support\LunchOrderLines::wanted(
            array_fill(0, 100, ['item_id' => $this->biryani->id, 'quantity' => 99])
        );

        $this->assertSame([$this->biryani->id => 99], $wanted);
    }

    #[Test]
    public function an_item_from_another_menu_is_refused(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]]);

        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 1],
            ['meal_menu_item_id' => $this->otherMenuPlate->id, 'quantity' => 1],
        ])->assertStatus(422);

        $this->assertSame(800, (int) $order->fresh()->total_minor);
    }

    #[Test]
    public function an_item_from_another_organisation_is_not_reachable(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]]);

        // A crafted body naming a real item id at a DIFFERENT masjid. It must not
        // price, and must not land a foreign row on this order.
        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->otherOrgPlate->id, 'quantity' => 5],
        ])->assertStatus(422);

        $order = $order->fresh()->load('items');
        $this->assertSame(800, (int) $order->total_minor);
        $this->assertCount(1, $order->items);
        $this->assertNull(
            $order->items->firstWhere('meal_menu_item_id', $this->otherOrgPlate->id),
            'another organisation\'s item must never reach an order here'
        );
    }

    #[Test]
    public function another_organisations_order_is_not_reachable_by_uuid(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]]);

        // The right uuid, the WRONG organisation in the header.
        $this->patchJson(
            '/api/v1/lunch-orders/' . $order->uuid,
            ['items' => [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 5]]],
            ['masjid-id' => (string) $this->otherOrg->id]
        )->assertStatus(404);

        $this->assertSame(800, (int) $order->fresh()->total_minor);
    }

    #[Test]
    public function an_order_may_not_be_emptied_by_the_customer(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1], [$this->water, 1]]);

        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 0],
            ['meal_menu_item_id' => $this->water->id, 'quantity' => 0],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'An order must keep at least one plate. Please contact the masjid to cancel it.');

        $this->assertCount(2, $order->fresh()->load('items')->items);
    }

    #[Test]
    public function a_cancelled_order_is_refused(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]], ['status' => MealOrder::STATUS_CANCELLED]);

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertStatus(409);

        $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertStatus(422)
            ->assertJsonPath('data', 'This order was cancelled. Set it back to confirmed first.');

        $this->assertSame(800, (int) $order->fresh()->total_minor);
    }

    #[Test]
    public function the_optional_extra_is_left_exactly_as_it_was(): void
    {
        // The one amount the CUSTOMER chose. Changing the food is not a decision
        // about the donation, in either direction.
        $order = $this->placeOrder([[$this->biryani, 1]], ['donation_minor' => 500]);
        $this->assertSame(1300, (int) $order->total_minor);

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertOk()
            ->assertJsonPath('data.order.donation_minor', 500)
            ->assertJsonPath('data.order.subtotal_minor', 2400)
            ->assertJsonPath('data.order.total_minor', 2900);

        $this->assertSame(500, (int) $order->fresh()->donation_minor);
    }

    #[Test]
    public function the_card_fee_is_recomputed_only_when_the_order_was_already_covering_it(): void
    {
        // Not covering it: it stays at nothing, whatever the food now costs.
        $plain = $this->placeOrder([[$this->biryani, 1]]);

        $this->editAsCustomer($plain, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.order.fee_covered_minor', 0)
            ->assertJsonPath('data.order.total_minor', 1600);

        // Covering it: the SAME formula the order was placed with, on the new
        // subtotal plus the untouched extra.
        $covering = $this->placeOrder([[$this->biryani, 2]], [
            'donation_minor' => 500,
            'fee_covered_minor' => StripeFees::coverage(1600 + 500),
        ]);

        $expectedFee = StripeFees::coverage(2400 + 500);
        $this->assertGreaterThan(0, $expectedFee);

        $this->editAsCustomer($covering, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertOk()
            ->assertJsonPath('data.order.fee_covered_minor', $expectedFee)
            ->assertJsonPath('data.order.donation_minor', 500)
            ->assertJsonPath('data.order.total_minor', 2400 + 500 + $expectedFee);
    }

    #[Test]
    public function every_edit_is_recorded_against_whoever_made_it(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]]);

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();

        $edit = MealOrderEdit::withoutMasjidScope()->latest('id')->first();
        $this->assertNotNull($edit, 'an edit that is not recorded is not an edit');
        $this->assertSame(MealOrderEdit::ACTOR_CUSTOMER, $edit->actor);
        $this->assertNull($edit->user_id, 'a customer has no login; the uuid on their link is what they hold');
        $this->assertSame($this->masjid->id, (int) $edit->masjid_id);
        $this->assertSame(800, (int) $edit->before['total_minor']);
        $this->assertSame(1600, (int) $edit->after['total_minor']);
        $this->assertSame(1, (int) $edit->before['items'][0]['quantity']);
        $this->assertSame(2, (int) $edit->after['items'][0]['quantity']);

        // And the staff door records the staff member.
        $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])->assertOk();

        $staffEdit = MealOrderEdit::withoutMasjidScope()->latest('id')->first();
        $this->assertSame(MealOrderEdit::ACTOR_STAFF, $staffEdit->actor);
        $this->assertSame($this->admin->id, (int) $staffEdit->user_id);
        $this->assertSame(2, MealOrderEdit::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_basket_that_changes_nothing_records_nothing(): void
    {
        $order = $this->placeOrder([[$this->biryani, 2], [$this->water, 1]]);

        // The same basket, in the other order.
        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->water->id, 'quantity' => 1],
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2],
        ])->assertOk()->assertJsonPath('message', 'Your order is unchanged.');

        $this->assertSame(0, MealOrderEdit::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_edit_posted_as_form_fields_is_read_the_same_as_json(): void
    {
        // The SPA pins a global axios Content-Type of x-www-form-urlencoded, which
        // every instance in the app inherits — including the public order page's
        // own — so an edit must not depend on arriving as JSON.
        //
        // What this pins is that the endpoint reads `items` from the request body
        // whatever shape it came in. It does NOT pin the parse of a RAW
        // form-urlencoded PATCH body: that fix-up lives in Symfony's
        // createFromGlobals, which the test harness's Request::create does not run,
        // so a raw body here would be empty for reasons that have nothing to do
        // with this code.
        $order = $this->placeOrder([[$this->biryani, 1]]);

        $this->patch(
            '/api/v1/lunch-orders/' . $order->uuid,
            ['items' => [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]]],
            ['Accept' => 'application/json', 'masjid-id' => (string) $this->masjid->id]
        )->assertOk();

        $this->assertSame(1600, (int) $order->fresh()->total_minor);
    }

    // ---------------------------------------------------------------- the board

    #[Test]
    public function staff_may_change_an_order_after_ordering_has_closed(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]]);

        // Ordering closed an hour ago — which is exactly when these requests come.
        $this->menu->forceFill(['status' => MealMenu::STATUS_CLOSED, 'ordering_closes_at' => now()->subHour()])->save();

        $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertOk()
            ->assertJsonPath('data.total_minor', 2400);
    }

    #[Test]
    public function changing_a_paid_order_shows_what_is_still_owed_and_marks_nothing_paid(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]], [
            'payment_status' => MealOrder::PAYMENT_PAID,
            'status' => MealOrder::STATUS_CONFIRMED,
            'payment_method' => MealOrder::METHOD_ONLINE,
            'paid_at' => now()->subHour(),
        ]);
        $paidAt = $order->paid_at;

        $response = $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.total_minor', 1600)
            ->assertJsonPath('data.balance_minor', 800);

        $this->assertStringContainsString('$8.00', (string) $response->json('message'));

        $order = $order->fresh();
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status, 'an edit never moves payment state');
        $this->assertSame(800, (int) $order->settled_total_minor, 'what actually settled is remembered');
        $this->assertSame($paidAt->toIso8601String(), $order->paid_at->toIso8601String());
        $this->assertNull($order->paid_via);
        $this->assertNull($order->marked_paid_by_user_id);

        // A second edit does not rewrite what settled.
        $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 4]])->assertOk();
        $this->assertSame(800, (int) $order->fresh()->settled_total_minor);
        $this->assertSame(2400, (int) $order->fresh()->balance_minor);
    }

    #[Test]
    public function reducing_a_paid_order_shows_money_owed_back(): void
    {
        $order = $this->placeOrder([[$this->biryani, 3]], [
            'payment_status' => MealOrder::PAYMENT_PAID,
            'status' => MealOrder::STATUS_CONFIRMED,
            'paid_at' => now(),
        ]);

        $response = $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 1]])
            ->assertOk()
            ->assertJsonPath('data.total_minor', 800)
            ->assertJsonPath('data.balance_minor', -1600);

        $this->assertStringContainsString('owed back', (string) $response->json('message'));
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    #[Test]
    public function the_board_counts_the_money_that_settled_not_the_new_price(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]], [
            'payment_status' => MealOrder::PAYMENT_PAID,
            'status' => MealOrder::STATUS_CONFIRMED,
            'paid_at' => now(),
        ]);

        $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()
            // $8.00 came in; the food is now $16.00 and $8.00 of it is owed.
            ->assertJsonPath('data.summary.revenue_paid_minor', 800)
            ->assertJsonPath('data.summary.expected_total_minor', 1600);
    }

    #[Test]
    public function a_paid_orders_card_fee_stays_the_one_that_was_actually_charged(): void
    {
        // The live shape this was found on: one plate, the customer added $5 and
        // covered the card fee, all of it paid by card.
        $fee = StripeFees::coverage(1600 + 500);
        $this->assertSame(94, $fee, 'the rate this was measured at (2.9% + 30c)');

        $order = $this->placeOrder([[$this->biryani, 2]], [
            'payment_method' => MealOrder::METHOD_ONLINE,
            'payment_status' => MealOrder::PAYMENT_PAID,
            'status' => MealOrder::STATUS_CONFIRMED,
            'paid_at' => now(),
            'donation_minor' => 500,
            'fee_covered_minor' => $fee,
        ]);
        $this->assertSame(2194, (int) $order->total_minor);

        // Staff add a plate after the cutoff. The plate is $8.00, so that is
        // exactly what the customer owes — not $8.00 plus a card fee on money
        // no card will ever process.
        $response = $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertOk()
            ->assertJsonPath('data.fee_covered_minor', 94)
            ->assertJsonPath('data.total_minor', 2400 + 500 + 94)
            ->assertJsonPath('data.balance_minor', 800);

        $this->assertStringContainsString('$8.00', (string) $response->json('message'));

        // And the board's two paid-money tiles still decompose: what settled,
        // less the extra, less the fee actually collected, is the food paid for.
        Sanctum::actingAs($this->admin);
        $summary = $this->getJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders")
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(2194, (int) $summary['revenue_paid_minor']);
        $this->assertSame(500, (int) $summary['donations_paid_minor']);
        $this->assertSame(94, (int) $summary['fees_covered_paid_minor'], 'the fee the customer was charged, not a new quote');
        $this->assertSame(
            1600,
            (int) $summary['revenue_paid_minor'] - (int) $summary['donations_paid_minor'] - (int) $summary['fees_covered_paid_minor'],
            'the food that was actually paid for'
        );
    }

    #[Test]
    public function staff_cannot_empty_an_order(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]]);

        $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 0]])
            ->assertStatus(422)
            ->assertJsonPath('data', 'An order must keep at least one plate. Cancel the order instead.');

        $this->assertCount(1, $order->fresh()->load('items')->items);
    }

    #[Test]
    public function staff_may_add_any_available_item_on_the_menu(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]]);

        $this->editAsStaff($order, [
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 1],
            ['meal_menu_item_id' => $this->water->id, 'quantity' => 2],
        ])->assertOk()->assertJsonPath('data.total_minor', 1000);
    }

    #[Test]
    public function another_organisations_order_is_a_404_on_the_board(): void
    {
        $otherMenu = MealMenu::factory()->forMasjid($this->otherOrg)->open()->create(['service_date' => '2027-01-22']);
        $foreign = $this->placeOrder([[$this->otherOrgPlate, 1]], [
            'masjid_id' => $this->otherOrg->id,
            'meal_menu_id' => $otherMenu->id,
        ]);

        Sanctum::actingAs($this->admin);

        // This organisation's own menu in the URL, someone else's order id.
        $this->patch(
            "/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$foreign->id}/items",
            ['items' => [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 9]]],
            ['Accept' => 'application/json']
        )->assertStatus(404);

        $this->assertSame(1, (int) $foreign->fresh()->total_minor);
    }

    // ------------------------------------------------------- the Stripe page

    #[Test]
    public function an_edit_that_fails_after_closing_the_page_does_not_leave_a_dead_link_on_the_order(): void
    {
        // Expiring a Checkout Session cannot be undone, and the close happens
        // INSIDE the edit's transaction — deliberately, because closing after the
        // commit would leave the customer holding a live page for the OLD amount.
        // So when a later step throws, the rollback restores
        // `stripe_checkout_session_id` on the row and it points at a session that
        // is dead at Stripe: the customer's saved link 404s while the order still
        // claims to have a payment page, and nothing says otherwise.
        //
        // The failure is forced by removing the audit table, which is the last
        // write in the transaction and therefore genuinely after the close.
        $order = $this->placeOrder([[$this->biryani, 1]], [
            'payment_method' => MealOrder::METHOD_ONLINE,
            'stripe_checkout_session_id' => 'cs_test_doomed',
        ]);

        \Illuminate\Support\Facades\Schema::drop('meal_order_edits');

        $threw = null;

        try {
            app(\App\Services\Lunch\MealOrderEditor::class)->apply(
                $order,
                $this->menu,
                [$this->biryani->id => 2],
                \App\Services\Lunch\MealOrderEditor::ACTOR_CUSTOMER,
                null
            );
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw, 'the edit must still fail — the audit row is not optional');

        $fresh = $order->fresh();

        // The edit did NOT happen: the money and the lines are untouched.
        $this->assertSame(800, (int) $fresh->total_minor, 'a failed edit must not move the money');
        $this->assertSame(1, (int) $fresh->items()->sum('quantity'), 'a failed edit must not change the plates');

        // But the page really was expired at Stripe, so the row must not keep
        // offering it. Null is the honest state, and it is what lets the board
        // and the customer's page mint a fresh link.
        $this->assertSame(['cs_test_doomed'], self::$expired, 'the page was closed at Stripe');
        $this->assertNull(
            $fresh->stripe_checkout_session_id,
            'the order still points at a session Stripe has expired; the customer\'s link 404s with nothing saying why'
        );
    }

    #[Test]
    public function the_old_payment_page_is_closed_and_a_new_one_is_made_for_the_new_total(): void
    {
        // An unpaid ONLINE order with a live Stripe page for $8.00. If that page
        // survived the edit, the customer could add a plate and then pay the old
        // amount, and the webhook would mark the order paid in full.
        $order = $this->placeOrder([[$this->biryani, 1]], [
            'payment_method' => MealOrder::METHOD_ONLINE,
            'stripe_checkout_session_id' => 'cs_test_old',
        ]);

        $response = $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.order.total_minor', 1600);

        $this->assertSame(['cs_test_old'], self::$expired, 'the page for the old amount must be closed');
        $this->assertSame(1, self::$pagesMade, 'and a new page made for the new amount');
        $this->assertNotNull($response->json('data.checkout_url'));
        $this->assertNotSame('cs_test_old', $order->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function a_staff_edit_hands_back_a_working_payment_link_for_the_new_total(): void
    {
        // The case this endpoint exists for: an unpaid card order, edited after
        // ordering has closed. Closing the old page is right — it is for the old
        // amount — but the customer is holding that link, and until now nothing
        // replaced it unless a member of staff read a sentence and pressed a
        // button. Menu 5 had unpaid card orders on the board when this was found.
        $order = $this->placeOrder([[$this->biryani, 1]], [
            'payment_method' => MealOrder::METHOD_ONLINE,
            'stripe_checkout_session_id' => 'cs_test_old_staff',
        ]);

        $response = $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.total_minor', 1600);

        $this->assertSame(['cs_test_old_staff'], self::$expired);
        $this->assertSame(1, self::$pagesMade, 'the customer is left with a way to pay');
        $this->assertNotNull($response->json('checkout_url'));
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $order->fresh()->payment_status);
        $this->assertNotSame('cs_test_old_staff', $order->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function an_order_that_says_pay_at_pickup_but_holds_a_payment_page_still_gets_a_new_one(): void
    {
        // A live shape on the board: staff send a payment link to someone who
        // chose to pay at pickup. The order still says `pickup` and still carries
        // the session. Deciding on the method rather than on the page meant the
        // link was expired at Stripe and nothing was made to replace it.
        $order = $this->placeOrder([[$this->biryani, 1]], [
            'payment_method' => MealOrder::METHOD_PICKUP,
            'stripe_checkout_session_id' => 'cs_test_link_sent',
        ]);

        $response = $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk();

        $this->assertSame(['cs_test_link_sent'], self::$expired);
        $this->assertSame(1, self::$pagesMade);
        $this->assertNotNull($response->json('data.checkout_url'));
    }

    #[Test]
    public function an_order_stripe_reports_as_paid_is_not_changed(): void
    {
        // The webhook is seconds behind: Stripe already holds the customer's card
        // payment for the OLD total. Re-pricing it would change what they paid for.
        $order = $this->placeOrder([[$this->biryani, 1]], [
            'payment_method' => MealOrder::METHOD_ONLINE,
            'stripe_checkout_session_id' => 'cs_test_paid',
        ]);

        self::$pageStatus = 'complete';
        self::$pagePaymentStatus = 'paid';

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertStatus(409);

        $order = $order->fresh()->load('items');
        $this->assertSame(800, (int) $order->total_minor, 'nothing was changed');
        $this->assertSame(1, (int) $order->items->first()->quantity);
        $this->assertSame('cs_test_paid', $order->stripe_checkout_session_id);
        $this->assertSame(0, MealOrderEdit::withoutMasjidScope()->count());
    }

    // --------------------------------------- what the ORDER PAGE is told it may do

    #[Test]
    public function the_order_page_is_told_it_may_be_changed_and_each_line_carries_the_id_the_edit_body_names(): void
    {
        $order = $this->placeOrder([[$this->biryani, 2], [$this->water, 1]]);

        $response = $this->showOrder($order)->assertStatus(200);

        $this->assertTrue($response->json('data.order.can_edit'));
        $this->assertNull($response->json('data.order.edit_notice'));

        // Without these ids the page cannot build an edit body at all: it would
        // have only the snapshotted NAMES, and names are not what the endpoint
        // takes. This is the field the whole editor hangs on.
        $this->assertSame(
            [$this->biryani->id, $this->water->id],
            array_column($response->json('data.order.items'), 'meal_menu_item_id')
        );
    }

    #[Test]
    public function after_the_cutoff_the_order_page_is_given_the_reason_instead_of_the_controls(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]]);
        $this->menu->forceFill(['ordering_closes_at' => now()->subMinute()])->save();

        $response = $this->showOrder($order)->assertStatus(200);

        $this->assertFalse($response->json('data.order.can_edit'));
        // The SAME sentence a PATCH would answer with, so the page never writes
        // its own words for a refusal it did not make.
        $this->assertSame('Orders for this menu are closed.', $response->json('data.order.edit_notice'));

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Orders for this menu are closed.');
    }

    #[Test]
    public function a_paid_order_tells_the_page_it_is_paid_rather_than_that_ordering_closed(): void
    {
        $order = $this->placeOrder([[$this->biryani, 1]], [
            'payment_status' => MealOrder::PAYMENT_PAID,
            'paid_at' => now(),
        ]);

        // Before the cutoff a paid order may be changed (the difference paid first,
        // MealOrderTopUpTest); after it, the customer is told it is paid.
        $this->menu->forceFill(['ordering_closes_at' => now()->subMinute()])->save();

        $response = $this->showOrder($order)->assertStatus(200);

        $this->assertFalse($response->json('data.order.can_edit'));
        $this->assertSame(
            'This order is already paid. Please contact the masjid to change it.',
            $response->json('data.order.edit_notice')
        );
    }

    #[Test]
    public function a_line_whose_menu_item_was_deleted_stops_the_page_offering_an_edit(): void
    {
        $order = $this->placeOrder([[$this->biryani, 2], [$this->water, 1]]);

        // The snapshotted name and price stand, but meal_menu_item_id goes null —
        // so this line cannot travel back in an edit body. An editor offered here
        // would drop it on save and quietly reduce the order.
        $this->water->delete();

        $response = $this->showOrder($order)->assertStatus(200);

        $this->assertNull($response->json('data.order.items.1.meal_menu_item_id'));
        $this->assertSame(100, (int) $response->json('data.order.items.1.line_total_minor'));
        $this->assertFalse($response->json('data.order.can_edit'));
        $this->assertSame(
            'Part of this order is no longer on the menu. Please contact the masjid to change it.',
            $response->json('data.order.edit_notice')
        );
    }

    #[Test]
    public function a_body_that_leaves_out_a_line_nobody_could_name_is_refused_on_both_doors(): void
    {
        $order = $this->placeOrder([[$this->biryani, 2], [$this->water, 1]]);

        // The dish is deleted: the order's line keeps its name and price but has
        // no id left, so no body can ask to keep it. The page already refuses to
        // offer an editor (can_edit=false) — but a body that never loaded the
        // page just leaves the line out, and leaving a line out removes it.
        $this->water->delete();

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Part of this order is no longer on the menu. Please contact the masjid to change it.');

        $response = $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertStatus(422);
        $this->assertStringContainsString('Water', (string) $response->json('data'));

        $order = $order->fresh()->load('items');
        $this->assertCount(2, $order->items, 'nothing was dropped');
        $this->assertSame(1700, (int) $order->total_minor);
        $this->assertSame(0, MealOrderEdit::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_line_whose_dish_went_unavailable_cannot_be_dropped_by_leaving_it_out(): void
    {
        $order = $this->placeOrder([[$this->biryani, 2], [$this->water, 1]]);

        // Still on the menu, but the kitchen has run out: neither screen offers
        // it, so a body omitting it did not choose to remove it.
        $this->water->forceFill(['is_available' => false])->save();

        $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertStatus(422);

        $this->assertSame(1700, (int) $order->fresh()->total_minor);
    }

    #[Test]
    public function staff_may_still_take_a_line_off_an_order_while_its_dish_is_on_the_menu(): void
    {
        // The refusal above must not cost staff the ordinary case: a dish that is
        // still offered, left out of the body, is a line somebody removed.
        $order = $this->placeOrder([[$this->biryani, 2], [$this->water, 1]]);

        $this->editAsStaff($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.total_minor', 1600);

        $this->assertCount(1, $order->fresh()->load('items')->items);
    }

    #[Test]
    public function the_reason_an_order_cannot_be_changed_is_named_as_well_as_written(): void
    {
        // The page is read in Arabic as often as in English, and this sentence
        // sits under the total on EVERY order once a lunch has closed. The
        // sentence stays exactly as it was; the name beside it is what the page
        // can say in the reader's own language.
        $order = $this->placeOrder([[$this->biryani, 1]]);
        $this->menu->forceFill(['ordering_closes_at' => now()->subMinute()])->save();

        $this->showOrder($order)
            ->assertOk()
            ->assertJsonPath('data.order.can_edit', false)
            ->assertJsonPath('data.order.edit_notice', 'Orders for this menu are closed.')
            ->assertJsonPath('data.order.edit_notice_code', 'closed');

        $paid = $this->placeOrder([[$this->biryani, 1]], [
            'payment_status' => MealOrder::PAYMENT_PAID,
            'paid_at' => now(),
        ]);

        $this->showOrder($paid)->assertOk()->assertJsonPath('data.order.edit_notice_code', 'paid');

        // And an order that CAN be changed carries neither.
        $this->menu->forceFill(['ordering_closes_at' => now()->addDay()])->save();
        $this->showOrder($order)
            ->assertOk()
            ->assertJsonPath('data.order.can_edit', true)
            ->assertJsonPath('data.order.edit_notice', null)
            ->assertJsonPath('data.order.edit_notice_code', null);
    }

    // ------------------------------------------------------------------ helpers

    /** The public order page's own read. */
    private function showOrder(MealOrder $order)
    {
        return $this->getJson(
            '/api/v1/lunch-orders/' . $order->uuid,
            ['masjid-id' => (string) $this->masjid->id]
        );
    }

    /** The customer's own edit, on the link they hold. */
    private function editAsCustomer(MealOrder $order, array $items)
    {
        return $this->patchJson(
            '/api/v1/lunch-orders/' . $order->uuid,
            ['items' => $items],
            ['masjid-id' => (string) $this->masjid->id]
        );
    }

    /** The board's edit, form-encoded exactly as the SPA posts it. */
    private function editAsStaff(MealOrder $order, array $items)
    {
        Sanctum::actingAs($this->admin);

        return $this->patch(
            "/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$order->meal_menu_id}/orders/{$order->id}/items",
            ['items' => $items],
            ['Accept' => 'application/json']
        );
    }

    /**
     * An order as it stands after being placed: priced from the menu, with the
     * lines snapshotted, exactly as the ordering paths write it.
     *
     * @param  array<int,array{0:MealMenuItem,1:int}>  $lines
     * @param  array<string,mixed>  $attrs
     */
    private function placeOrder(array $lines, array $attrs = []): MealOrder
    {
        $order = new MealOrder([
            'meal_menu_id' => $attrs['meal_menu_id'] ?? $this->menu->id,
            'customer_name' => 'Yusuf Ali',
            'customer_phone' => '3365551234',
            'payment_method' => $attrs['payment_method'] ?? MealOrder::METHOD_PICKUP,
        ]);

        $order->masjid_id = $attrs['masjid_id'] ?? $this->masjid->id;
        $order->currency = 'usd';

        $subtotal = 0;
        foreach ($lines as [$item, $qty]) {
            $subtotal += (int) $item->price_minor * $qty;
        }

        $order->subtotal_minor = $subtotal;
        $order->donation_minor = (int) ($attrs['donation_minor'] ?? 0);
        $order->fee_covered_minor = (int) ($attrs['fee_covered_minor'] ?? 0);
        $order->total_minor = $subtotal + $order->donation_minor + $order->fee_covered_minor;
        $order->order_number = str_pad((string) (++$this->orderNo), 3, '0', STR_PAD_LEFT);
        $order->placed_at = now();

        foreach (['status', 'payment_status', 'paid_at', 'stripe_checkout_session_id'] as $column) {
            if (array_key_exists($column, $attrs)) {
                $order->{$column} = $attrs[$column];
            }
        }

        $order->save();

        foreach ($lines as [$item, $qty]) {
            $order->items()->create([
                'masjid_id' => $order->masjid_id,
                'meal_menu_item_id' => $item->id,
                'item_name' => $item->name,
                'unit_price_minor' => (int) $item->price_minor,
                'quantity' => $qty,
                'line_total_minor' => (int) $item->price_minor * $qty,
            ]);
        }

        return $order->fresh()->load('items');
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

    /** The three seams the checkout service reaches Stripe through, and nothing else. */
    private function fakeStripe(): void
    {
        self::$pagesMade = 0;
        self::$expired = [];
        self::$pageStatus = 'open';
        self::$pagePaymentStatus = null;

        $this->app->bind(MealOrderCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends MealOrderCheckoutService
            {
                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    $n = ++MealOrderEditTest::$pagesMade;

                    return ['id' => "cs_test_new_{$n}", 'url' => "https://stripe.test/pay/{$n}", 'payment_intent' => null];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                    MealOrderEditTest::$expired[] = $sessionId;
                    MealOrderEditTest::$pageStatus = 'expired';
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    $status = MealOrderEditTest::$pageStatus;

                    return [
                        'status' => $status,
                        'payment_status' => MealOrderEditTest::$pagePaymentStatus,
                        'url' => $status === 'open' ? 'https://stripe.test/pay/' . $sessionId : null,
                    ];
                }
            };
        });
    }
}
