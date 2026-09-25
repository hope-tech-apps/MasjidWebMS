<?php

namespace Tests\Feature;

use App\Mail\LunchOrderConfirmation;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\MealOrderEdit;
use App\Models\MealOrderTopUp;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Lunch\MealOrderEditor;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Services\Stripe\MealOrderTopUpPaymentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * A customer changing a lunch order they have ALREADY PAID for (owner,
 * 2026-09-24), and the order-link email.
 *
 * The properties money depends on:
 *   - the new total is set against what was PAID. Lower is refused (no automatic
 *     refunds, ever); the same is applied at once; higher changes NOTHING until the
 *     difference is paid on its own Stripe page;
 *   - that page is a direct charge on the organisation's account, card only, for
 *     exactly the difference, closing at the cutoff; one open per order;
 *   - only the signed webhook applies the change, once, and only when the session,
 *     the order, the organisation and the amount all match;
 *   - when the order moved meanwhile, the money is recorded and the plates are not;
 *   - a top-up is never read as the order's own payment;
 *   - the order email goes only to an address the order holds, once per event.
 */
class MealOrderTopUpTest extends TestCase
{
    use RefreshDatabase;

    /** Stripe, faked: every page made (params, account, key), every page closed, what a lookup reports. */
    public static array $created = [];
    public static array $expired = [];
    public static array $sessions = [];

    /**
     * When true, the checkout service's closeOpenTopUps() does nothing: another
     * request's page committed AFTER this request's close had already run, which is
     * the interleaving two taps or two tabs produce (and SQLite's no-op row lock
     * cannot).
     */
    public static bool $skipClose = false;

    private const WEBHOOK_SECRET = 'whsec_lunch_top_up_test';

    private const CUSTOMER_EMAIL = 'topup.customer@example.test';

    private Masjid $masjid;
    private MealMenu $menu;
    private MealMenuItem $biryani;   // 800
    private MealMenuItem $lamb;      // 800, for a swap
    private MealMenuItem $water;     // 100, max 2

    private int $orderNo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);

        app(TenantContext::class)->forgetTenant(); // the public path and the webhook run UNBOUND

        Mail::fake();

        $this->masjid = $this->makeOrg();

        // Three hours to the cutoff: inside Stripe's 24-hour ceiling, so the top-up
        // page closes exactly at the cutoff.
        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->open()->create([
            'service_date' => '2027-01-08',
            'ordering_closes_at' => now()->addHours(3),
        ]);
        $this->biryani = $this->item('Chicken Biryani Plate', 800);
        $this->lamb = $this->item('Lamb Plate', 800);
        $this->water = $this->item('Water', 100, 2);

        $this->fakeStripe();
    }

    // ------------------------------------------------------ the customer's edit

    #[Test]
    public function fewer_plates_on_a_paid_order_are_refused_and_nothing_changes(): void
    {
        $order = $this->paidOrder([[$this->biryani, 2]]);

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'To remove plates from a paid order, please contact the masjid.')
            ->assertJsonPath('data.code', 'paid_reduce');

        $this->assertSame(1600, (int) $order->fresh()->total_minor);
        $this->assertSame(2, (int) $order->fresh()->load('items')->items->first()->quantity);
        $this->assertSame(0, MealOrderTopUp::withoutMasjidScope()->count());
        $this->assertSame(0, MealOrderEdit::withoutMasjidScope()->count());
        $this->assertSame([], self::$created, 'no payment page for a refusal');
    }

    #[Test]
    public function a_swap_for_the_same_total_is_applied_at_once_as_the_customers_edit(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);

        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 0],
            ['meal_menu_item_id' => $this->lamb->id, 'quantity' => 1],
        ])->assertOk()
            ->assertJsonPath('data.order.total_minor', 800)
            ->assertJsonPath('data.order.paid_minor', 800);

        $order = $order->fresh()->load('items');
        $this->assertCount(1, $order->items);
        $this->assertSame((int) $this->lamb->id, (int) $order->items->first()->meal_menu_item_id);
        $this->assertSame(0, (int) $order->balance_minor, 'still paid in full');
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);

        $edit = MealOrderEdit::withoutMasjidScope()->sole();
        $this->assertSame(MealOrderEdit::ACTOR_CUSTOMER, $edit->actor);
        $this->assertNull($edit->user_id);
        $this->assertSame([], self::$created, 'a swap costs nothing, so no payment page');
    }

    #[Test]
    public function more_plates_on_a_paid_order_change_nothing_until_the_difference_is_paid(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);

        $response = $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.status', 'payment_required')
            ->assertJsonPath('data.checkout_url', 'https://stripe.test/topup/1')
            ->assertJsonPath('data.amount_minor', 800)
            ->assertJsonPath('data.proposed_total_minor', 1600)
            // The order is exactly as it was.
            ->assertJsonPath('data.order.total_minor', 800)
            ->assertJsonPath('data.order.items.0.quantity', 1);

        $this->assertSame(800, (int) $order->fresh()->total_minor);
        $this->assertSame(0, MealOrderEdit::withoutMasjidScope()->count());

        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();
        $this->assertSame(MealOrderTopUp::STATUS_PENDING, $topUp->status);
        $this->assertSame((int) $this->masjid->id, (int) $topUp->masjid_id);
        $this->assertSame(800, $topUp->base_total_minor);
        $this->assertSame(800, $topUp->base_settled_minor);
        $this->assertSame(800, $topUp->amount_minor);
        $this->assertSame(1600, $topUp->proposed_total_minor);
        $this->assertSame([$this->biryani->id => 2], $topUp->wanted());
        $this->assertSame('cs_topup_1', $topUp->stripe_session_id);
        $this->assertNotNull($topUp->idempotency_key);

        // The page: a direct charge on the org's account, card only, for exactly
        // the difference, closing at the cutoff.
        $page = self::$created[0];
        $this->assertSame($this->masjid->stripe_account_id, $page['account']);
        $this->assertSame($topUp->idempotency_key, $page['key']);
        $this->assertSame(['card'], $page['params']['payment_method_types']);
        $this->assertCount(1, $page['params']['line_items']);
        $this->assertSame(800, $page['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame($this->menu->fresh()->ordering_closes_at->getTimestamp(), $page['params']['expires_at']);
        $this->assertSame([
            'kind' => MealOrderTopUp::STRIPE_KIND,
            'top_up_id' => (string) $topUp->id,
            'order_uuid' => $order->uuid,
            'masjid_id' => (string) $this->masjid->id,
        ], $page['params']['metadata']);
        // The payment intent never names the order, so nothing routing by
        // order_uuid can read a top-up as the order's own payment.
        $this->assertArrayNotHasKey('order_uuid', $page['params']['payment_intent_data']['metadata']);
        $this->assertStringEndsWith('?topup=success', $page['params']['success_url']);
        $this->assertStringEndsWith('?topup=cancelled', $page['params']['cancel_url']);

        // The order page learns the difference is waiting, and nothing more.
        $this->showOrder($order)
            ->assertOk()
            ->assertJsonPath('data.order.paid_minor', 800)
            ->assertJsonPath('data.order.top_up.amount_minor', 800)
            ->assertJsonPath('data.order.last_top_up_status', 'pending')
            ->assertJsonPath(
                'data.order.topup_open_until',
                $this->menu->fresh()->ordering_closes_at->copy()->subMinutes(30)->toIso8601String()
            )
            ->assertJsonPath('data.order.can_edit', true);
        $this->assertSame(['amount_minor', 'expires_at'], array_keys((array) $response->json('data.order.top_up')));
    }

    #[Test]
    public function a_paid_orders_edit_posted_as_form_fields_reads_the_same_as_json(): void
    {
        // The public page posts form-encoded (the global axios Content-Type), the one
        // encoding the JSON tests above never send (.claude/rules/shipping.md).
        $order = $this->paidOrder([[$this->biryani, 1]]);

        $this->patch(
            '/api/v1/lunch-orders/' . $order->uuid,
            ['items' => [['meal_menu_item_id' => (string) $this->biryani->id, 'quantity' => '2']]],
            ['masjid-id' => (string) $this->masjid->id, 'Accept' => 'application/json']
        )->assertOk()
            ->assertJsonPath('data.status', 'payment_required')
            ->assertJsonPath('data.amount_minor', 800);
    }

    #[Test]
    public function inside_thirty_minutes_of_the_cutoff_a_paid_order_cannot_be_added_to_online(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->menu->forceFill(['ordering_closes_at' => now()->addMinutes(20)])->save();

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertStatus(422)
            ->assertJsonPath('message', "It's too close to the ordering cutoff to add plates to a paid order online. Please contact the masjid.")
            ->assertJsonPath('data.code', 'too_close_to_cutoff');

        $this->assertSame(0, MealOrderTopUp::withoutMasjidScope()->count());
        $this->assertSame([], self::$created);
        $this->assertSame(800, (int) $order->fresh()->total_minor);

        // Thirty-five minutes out is still in time.
        $this->menu->forceFill(['ordering_closes_at' => now()->addMinutes(35)])->save();
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.status', 'payment_required');
    }

    #[Test]
    public function after_the_cutoff_a_paid_order_is_refused_as_paid(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->menu->forceFill(['ordering_closes_at' => now()->subMinute()])->save();

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ordering has closed and this order is paid. Please contact the masjid to change it.')
            ->assertJsonPath('data.code', 'paid');

        $this->showOrder($order)
            ->assertOk()
            ->assertJsonPath('data.order.can_edit', false)
            ->assertJsonPath('data.order.edit_notice_code', 'paid');

        $this->assertSame(0, MealOrderTopUp::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_new_top_up_closes_the_old_page_first(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertOk()
            ->assertJsonPath('data.amount_minor', 1600);

        $this->assertSame(['cs_topup_1'], self::$expired, 'the first page is closed at Stripe');

        $topUps = MealOrderTopUp::withoutMasjidScope()->orderBy('id')->get();
        $this->assertCount(2, $topUps);
        $this->assertSame(MealOrderTopUp::STATUS_EXPIRED, $topUps[0]->status);
        $this->assertSame(MealOrderTopUp::STATUS_PENDING, $topUps[1]->status);
        $this->assertSame(1, MealOrderTopUp::withoutMasjidScope()->where('status', MealOrderTopUp::STATUS_PENDING)->count());
    }

    #[Test]
    public function a_change_waits_while_the_last_difference_is_being_confirmed(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();

        // Paid on Stripe; the webhook has not landed yet.
        self::$sessions['cs_topup_1'] = ['status' => 'complete', 'payment_status' => 'paid'];

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertStatus(409)
            ->assertJsonPath('data.code', 'topup_confirming');

        // A swap would put that payment in conflict too, so it waits as well.
        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 0],
            ['meal_menu_item_id' => $this->lamb->id, 'quantity' => 1],
        ])->assertStatus(409)->assertJsonPath('data.code', 'topup_confirming');

        $this->assertSame(1, MealOrderTopUp::withoutMasjidScope()->count());
        $this->assertSame(MealOrderTopUp::STATUS_PENDING, MealOrderTopUp::withoutMasjidScope()->sole()->status);
        $this->assertSame(800, (int) $order->fresh()->total_minor);
    }

    // ------------------------------------------------------------- the webhook

    #[Test]
    public function the_webhook_applies_a_paid_top_up_once_and_the_order_is_paid_in_full(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        $event = $this->completedEvent($topUp, $order);
        $this->signedWebhook($event)->assertOk();

        $order = $order->fresh()->load('items');
        $this->assertSame(1600, (int) $order->total_minor);
        $this->assertSame(2, (int) $order->items->first()->quantity);
        $this->assertSame(1600, (int) $order->settled_total_minor);
        $this->assertSame(0, (int) $order->balance_minor, 'paid in full at the new total');
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);

        $topUp->refresh();
        $this->assertSame(MealOrderTopUp::STATUS_APPLIED, $topUp->status);
        $this->assertNotNull($topUp->applied_at);
        $this->assertSame('pi_topup_1', $topUp->stripe_payment_intent_id);

        $edit = MealOrderEdit::withoutMasjidScope()->sole();
        $this->assertSame(MealOrderEdit::ACTOR_CUSTOMER, $edit->actor);
        $this->assertSame(800, $edit->before['total_minor']);
        $this->assertSame(1600, $edit->after['total_minor']);

        // The same event again, and the same session's other success event: nothing more.
        $this->signedWebhook($event)->assertOk();
        $this->signedWebhook(array_merge($this->completedEvent($topUp, $order), ['type' => 'checkout.session.async_payment_succeeded']))->assertOk();

        $order = $order->fresh();
        $this->assertSame(1600, (int) $order->total_minor);
        $this->assertSame(1600, (int) $order->settled_total_minor);
        $this->assertSame(1, MealOrderEdit::withoutMasjidScope()->count());

        // The order page no longer shows a difference waiting.
        $this->showOrder($order)
            ->assertOk()
            ->assertJsonPath('data.order.top_up', null)
            ->assertJsonPath('data.order.last_top_up_status', 'applied')
            ->assertJsonPath('data.order.paid_minor', 1600);
    }

    #[Test]
    public function a_payment_for_a_different_amount_records_nothing(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        Log::spy();

        $this->signedWebhook($this->completedEvent($topUp, $order, ['amount_total' => 700]))->assertOk();

        $this->assertSame(800, (int) $order->fresh()->total_minor);
        $this->assertNull($order->fresh()->settled_total_minor);
        $this->assertSame(0, MealOrderEdit::withoutMasjidScope()->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'different amount'))
            ->once();

        // Closed, not left pending: Stripe says that page is complete, and a
        // pending row for it would refuse every later change as "still being
        // confirmed" for ever.
        $this->assertSame(MealOrderTopUp::STATUS_REJECTED, $topUp->fresh()->status);
        self::$sessions['cs_topup_1'] = ['status' => 'complete', 'payment_status' => 'paid'];

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertOk()
            ->assertJsonPath('data.status', 'payment_required');
        $this->showOrder($order)->assertOk()->assertJsonPath('data.order.last_top_up_status', 'pending');
    }

    #[Test]
    public function a_page_completed_without_payment_is_closed_and_does_not_hold_the_order(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        $this->signedWebhook($this->completedEvent($topUp, $order, ['payment_status' => 'unpaid']))->assertOk();

        $this->assertSame(MealOrderTopUp::STATUS_REJECTED, $topUp->fresh()->status);
        $this->assertSame(800, (int) $order->fresh()->total_minor);
        $this->assertNull($order->fresh()->settled_total_minor);

        // Should that page's money land after all, it is recorded, never applied.
        $this->signedWebhook($this->completedEvent($topUp, $order))->assertOk();
        $this->assertSame(MealOrderTopUp::STATUS_CONFLICT, $topUp->fresh()->status);
        $this->assertSame(800, (int) $order->fresh()->total_minor);
        $this->assertSame(1600, (int) $order->fresh()->settled_total_minor);
    }

    #[Test]
    public function a_session_that_does_not_match_the_top_up_records_nothing(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        // Another session id, another order's uuid, another masjid id: each alone
        // is refused, and none of them touches the top-up (an unpaid completion of
        // the RIGHT page is a_page_completed_without_payment_...).
        $this->signedWebhook($this->completedEvent($topUp, $order, ['id' => 'cs_someone_else']))->assertOk();
        $this->signedWebhook($this->completedEvent($topUp, $order, [], ['order_uuid' => 'not-this-order']))->assertOk();
        $this->signedWebhook($this->completedEvent($topUp, $order, [], ['masjid_id' => '999999']))->assertOk();

        $this->assertSame(800, (int) $order->fresh()->total_minor);
        $this->assertNull($order->fresh()->settled_total_minor);
        $this->assertSame(MealOrderTopUp::STATUS_PENDING, $topUp->fresh()->status);
    }

    #[Test]
    public function another_organisations_account_cannot_settle_this_organisations_top_up(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        $other = $this->makeOrg();

        // Everything names our top-up, but the event was raised on the OTHER
        // organisation's connected account, and says it is theirs.
        $event = $this->completedEvent($topUp, $order, [], ['masjid_id' => (string) $other->id]);
        $event['account'] = $other->stripe_account_id;
        $this->signedWebhook($event)->assertOk();

        $this->assertNull($topUp->fresh()->applied_at);
        $this->assertSame(MealOrderTopUp::STATUS_PENDING, $topUp->fresh()->status);
        $this->assertSame(800, (int) $order->fresh()->total_minor);
        $this->assertNull($order->fresh()->settled_total_minor);

        // Nor is the top-up visible to the other organisation's bound tenant.
        app(TenantContext::class)->set($other->id);
        $this->assertNull(MealOrderTopUp::find($topUp->id));
        app(TenantContext::class)->forgetTenant();
    }

    #[Test]
    public function when_staff_changed_the_order_the_payment_is_recorded_and_the_plates_are_not(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        // Meanwhile staff add a water on the board: $9.00, $8.00 paid.
        app(MealOrderEditor::class)->apply(
            $order,
            $this->menu,
            [$this->biryani->id => 1, $this->water->id => 1],
            MealOrderEditor::ACTOR_STAFF,
            null
        );

        Log::spy();

        $this->signedWebhook($this->completedEvent($topUp, $order))->assertOk();

        $order = $order->fresh()->load('items');
        // The staff edit stands; the customer's change was not applied over it.
        $this->assertSame(900, (int) $order->total_minor);
        $this->assertSame(1, (int) $order->items->firstWhere('meal_menu_item_id', $this->biryani->id)->quantity);
        // The money is never lost: $8.00 + $8.00 recorded, so $7.00 shows owed back.
        $this->assertSame(1600, (int) $order->settled_total_minor);
        $this->assertSame(-700, (int) $order->balance_minor);
        $this->assertSame(MealOrderTopUp::STATUS_CONFLICT, $topUp->fresh()->status);
        $this->assertSame(1, MealOrderEdit::withoutMasjidScope()->count(), 'only the staff edit');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'was NOT applied'))
            ->once();

        // A replay records the money once, not twice.
        $this->signedWebhook($this->completedEvent($topUp, $order))->assertOk();
        $this->assertSame(1600, (int) $order->fresh()->settled_total_minor);
        Mail::assertNotQueued(LunchOrderConfirmation::class, fn (LunchOrderConfirmation $m) => $m->updated);

        // The page is told what the server recorded, not left to guess from totals.
        $this->showOrder($order)->assertOk()->assertJsonPath('data.order.last_top_up_status', 'conflict');
    }

    #[Test]
    public function an_expired_top_up_page_leaves_the_order_as_it_was(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        $event = $this->completedEvent($topUp, $order, ['status' => 'expired', 'payment_status' => 'unpaid']);
        $event['type'] = 'checkout.session.expired';
        $this->signedWebhook($event)->assertOk();

        $this->assertSame(MealOrderTopUp::STATUS_EXPIRED, $topUp->fresh()->status);
        $this->assertSame(800, (int) $order->fresh()->total_minor);
        $this->assertNull($order->fresh()->settled_total_minor);
        $this->showOrder($order)->assertOk()->assertJsonPath('data.order.top_up', null);
    }

    #[Test]
    public function a_top_up_is_never_read_as_the_orders_own_payment(): void
    {
        // A pickup order staff marked paid in cash. Were the top-up routed as the
        // order's payment, MealOrderPaymentService would record its payment intent
        // on the order and log a double payment.
        $order = $this->paidOrder([[$this->biryani, 1]], [
            'payment_method' => MealOrder::METHOD_PICKUP,
            'paid_via' => MealOrder::PAID_VIA_CASH,
        ]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        $this->signedWebhook($this->completedEvent($topUp, $order))->assertOk();
        $this->signedWebhook([
            'id' => 'evt_' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'account' => $this->masjid->stripe_account_id,
            'data' => ['object' => [
                'id' => 'pi_topup_1',
                'object' => 'payment_intent',
                'metadata' => ['kind' => MealOrderTopUp::STRIPE_KIND, 'top_up_id' => (string) $topUp->id, 'masjid_id' => (string) $this->masjid->id],
            ]],
        ])->assertOk();

        $order = $order->fresh();
        $this->assertNull($order->stripe_payment_intent_id, 'the order\'s own payment fields are untouched');
        $this->assertSame(MealOrder::PAID_VIA_CASH, $order->paid_via);
        $this->assertSame(1600, (int) $order->total_minor);
        $this->assertSame(1600, (int) $order->settled_total_minor);
        $this->assertSame(MealOrderTopUp::STATUS_APPLIED, $topUp->fresh()->status);

        $this->assertTrue(MealOrderTopUpPaymentService::isTopUpEvent(['metadata' => ['kind' => MealOrderTopUp::STRIPE_KIND]]));
        $this->assertFalse(MealOrderTopUpPaymentService::isTopUpEvent(['metadata' => ['order_uuid' => 'x']]));
        $this->assertFalse(MealOrderTopUpPaymentService::isTopUpEvent([]));
    }

    // ------------------------------------------------------ the order email

    #[Test]
    public function a_pay_at_pickup_order_with_an_email_is_sent_its_link_once_on_the_site_it_came_from(): void
    {
        config(['cors.allowed_origins' => ['https://burlington.example.test']]);

        $this->postJson('/api/v1/lunch-orders', [
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 2]],
            'customer_name' => 'Test Customer',
            'customer_phone' => '5550100100',
            'customer_email' => self::CUSTOMER_EMAIL,
            'payment_method' => 'pickup',
        ], ['masjid-id' => (string) $this->masjid->id, 'Origin' => 'https://burlington.example.test'])->assertOk();

        $order = MealOrder::withoutMasjidScope()->latest('id')->first();
        $this->assertSame('https://burlington.example.test', $order->site_origin);
        $this->assertNotNull($order->confirmation_sent_at);

        $sent = Mail::queued(LunchOrderConfirmation::class, fn (LunchOrderConfirmation $m) => $m->hasTo(self::CUSTOMER_EMAIL));
        $this->assertCount(1, $sent);

        $mail = $sent->first();
        $this->assertFalse($mail->updated);
        $this->assertSame(
            'https://burlington.example.test/jummah-lunch/' . $this->masjid->id . '/order/' . $order->uuid,
            $mail->orderUrl
        );
        $this->assertSame('$16.00', $mail->totalLine);
        $this->assertSame('$16.00', $mail->dueLine);
        $this->assertSame('Pay at pickup', $mail->dueLabel);
        $this->assertNotNull($mail->changeUntil);
        $this->assertStringContainsString('View or change your order', $mail->render());

        // Asked again for the same order: never a second email.
        app(\App\Services\Lunch\LunchOrderMailer::class)->confirmation($order);
        $this->assertCount(1, Mail::queued(LunchOrderConfirmation::class));
    }

    #[Test]
    public function an_order_without_an_email_is_never_written_to(): void
    {
        $this->postJson('/api/v1/lunch-orders', [
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->biryani->id, 'quantity' => 1]],
            'customer_name' => 'Test Customer',
            'customer_phone' => '5550100101',
            'payment_method' => 'pickup',
        ], ['masjid-id' => (string) $this->masjid->id])->assertOk();

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertNull(MealOrder::withoutMasjidScope()->latest('id')->first()->confirmation_sent_at);
    }

    #[Test]
    public function a_card_payers_stripe_email_is_kept_and_the_confirmation_goes_once(): void
    {
        $order = $this->unpaidOnlineOrder();
        $this->assertNull($order->customer_email);

        $session = [
            'id' => 'cs_order_1',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_order_1',
            'customer_details' => ['email' => 'card.payer@example.test'],
            'metadata' => ['order_uuid' => $order->uuid, 'masjid_id' => (string) $this->masjid->id],
        ];

        $this->signedWebhook(['id' => 'evt_' . uniqid(), 'type' => 'checkout.session.completed', 'account' => $this->masjid->stripe_account_id, 'data' => ['object' => $session]])->assertOk();
        // Stripe's other success event for the same payment, and a replay.
        $this->signedWebhook([
            'id' => 'evt_' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'account' => $this->masjid->stripe_account_id,
            'data' => ['object' => ['id' => 'pi_order_1', 'object' => 'payment_intent', 'metadata' => ['order_uuid' => $order->uuid]]],
        ])->assertOk();
        $this->signedWebhook(['id' => 'evt_' . uniqid(), 'type' => 'checkout.session.completed', 'account' => $this->masjid->stripe_account_id, 'data' => ['object' => $session]])->assertOk();

        $order = $order->fresh();
        $this->assertSame(MealOrder::PAYMENT_PAID, $order->payment_status);
        $this->assertSame('card.payer@example.test', $order->customer_email);

        $sent = Mail::queued(LunchOrderConfirmation::class);
        $this->assertCount(1, $sent);
        $this->assertTrue($sent->first()->hasTo('card.payer@example.test'));
        $this->assertSame('$8.00', $sent->first()->paidLine);
        $this->assertNull($sent->first()->dueLine);
    }

    #[Test]
    public function the_stripe_email_is_not_kept_where_the_menu_does_not_ask_for_one(): void
    {
        $this->menu->forceFill(['collect_customer_email' => false])->save();
        $order = $this->unpaidOnlineOrder();

        $this->signedWebhook(['id' => 'evt_' . uniqid(), 'type' => 'checkout.session.completed', 'account' => $this->masjid->stripe_account_id, 'data' => ['object' => [
            'id' => 'cs_order_2',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_order_2',
            'customer_details' => ['email' => 'card.payer@example.test'],
            'metadata' => ['order_uuid' => $order->uuid, 'masjid_id' => (string) $this->masjid->id],
        ]]])->assertOk();

        $this->assertSame(MealOrder::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->customer_email);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function an_applied_top_up_sends_your_order_was_updated_once(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]], ['customer_email' => self::CUSTOMER_EMAIL]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        $event = $this->completedEvent($topUp, $order);
        $this->signedWebhook($event)->assertOk();
        $this->signedWebhook(array_merge($event, ['id' => 'evt_' . uniqid(), 'type' => 'checkout.session.async_payment_succeeded']))->assertOk();

        $updated = Mail::queued(LunchOrderConfirmation::class, fn (LunchOrderConfirmation $m) => $m->updated);
        $this->assertCount(1, $updated);
        $this->assertTrue($updated->first()->hasTo(self::CUSTOMER_EMAIL));
        $this->assertSame('$16.00', $updated->first()->totalLine);
        $this->assertSame('$16.00', $updated->first()->paidLine);
        $this->assertNull($updated->first()->dueLine);
        $this->assertNotNull($topUp->fresh()->notified_at);
    }

    // ------------------------------------------------------ review fixes (2026-09-25)

    #[Test]
    public function a_top_up_paid_after_staff_closed_the_menu_is_recorded_and_not_applied(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        // Staff close ordering early; the kitchen counts without this plate.
        $this->menu->forceFill(['status' => MealMenu::STATUS_CLOSED])->save();

        $this->signedWebhook($this->completedEvent($topUp, $order))->assertOk();

        $order = $order->fresh()->load('items');
        $this->assertSame(800, (int) $order->total_minor, 'no plate the kitchen did not count');
        $this->assertSame(1, (int) $order->items->first()->quantity);
        $this->assertSame(1600, (int) $order->settled_total_minor, 'the money is recorded, owed back');
        $this->assertSame(MealOrderTopUp::STATUS_CONFLICT, $topUp->fresh()->status);
        $this->assertSame(0, MealOrderEdit::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_top_up_paid_after_the_cutoff_was_moved_earlier_is_recorded_and_not_applied(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        // The page still runs to the old cutoff; staff brought the cutoff forward,
        // and the customer paid after the new one.
        $this->menu->forceFill(['ordering_closes_at' => now()->subMinutes(5)])->save();

        $this->signedWebhook($this->completedEvent($topUp, $order))->assertOk();

        $this->assertSame(800, (int) $order->fresh()->total_minor);
        $this->assertSame(1600, (int) $order->fresh()->settled_total_minor);
        $this->assertSame(MealOrderTopUp::STATUS_CONFLICT, $topUp->fresh()->status);
    }

    #[Test]
    public function a_payment_made_before_the_cutoff_is_applied_even_when_its_webhook_arrives_after(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        // Paid ten minutes before the cutoff; Stripe's retry delivers it after.
        $paidAt = $this->menu->fresh()->ordering_closes_at->copy()->subMinutes(10)->getTimestamp();
        $this->travelTo($this->menu->fresh()->ordering_closes_at->copy()->addMinutes(20));

        $event = $this->completedEvent($topUp, $order);
        $event['created'] = $paidAt;
        $this->signedWebhook($event)->assertOk();

        $this->assertSame(MealOrderTopUp::STATUS_APPLIED, $topUp->fresh()->status);
        $this->assertSame(1600, (int) $order->fresh()->total_minor);
        $this->assertSame(0, (int) $order->fresh()->balance_minor);
    }

    #[Test]
    public function closing_a_menu_or_moving_its_cutoff_earlier_closes_the_top_up_pages_it_holds(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $checkout = app(MealOrderCheckoutService::class);

        // A later cutoff leaves the page alone: it closes before the new one.
        $this->menu->forceFill(['ordering_closes_at' => now()->addHours(5)])->save();
        $this->assertSame(0, $checkout->closeTopUpsOutliving($this->menu->fresh()));
        $this->assertSame([], self::$expired);

        // An earlier one closes it: it would outlive ordering.
        $this->menu->forceFill(['ordering_closes_at' => now()->addHour()])->save();
        $this->assertSame(1, $checkout->closeTopUpsOutliving($this->menu->fresh()));
        $this->assertSame(['cs_topup_1'], self::$expired);
        $this->assertSame(MealOrderTopUp::STATUS_EXPIRED, MealOrderTopUp::withoutMasjidScope()->sole()->status);

        // And staff closing the menu on the board closes every page it holds.
        $this->menu->forceFill(['ordering_closes_at' => now()->addHours(3)])->save();
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $admin = $this->admin();

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}", ['status' => MealMenu::STATUS_CLOSED])
            ->assertOk();
        app(TenantContext::class)->forgetTenant();

        $this->assertSame(['cs_topup_1', 'cs_topup_2'], self::$expired);
        $this->assertSame(0, MealOrderTopUp::withoutMasjidScope()->where('status', MealOrderTopUp::STATUS_PENDING)->count());
    }

    #[Test]
    public function a_second_request_cannot_open_a_page_beside_one_already_open(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();

        // The second tap's close ran before the first tap's page was committed.
        self::$skipClose = true;

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertStatus(409)
            ->assertJsonPath('data.code', 'order_moved');

        $this->assertCount(1, self::$created, 'one payable page, never two');
        $this->assertSame(1, MealOrderTopUp::withoutMasjidScope()->count());
        $this->assertSame(MealOrderTopUp::STATUS_PENDING, MealOrderTopUp::withoutMasjidScope()->sole()->status);
    }

    #[Test]
    public function a_swap_is_refused_while_another_requests_page_is_open(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();

        self::$skipClose = true;

        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 0],
            ['meal_menu_item_id' => $this->lamb->id, 'quantity' => 1],
        ])->assertStatus(409)->assertJsonPath('data.code', 'order_moved');

        $order = $order->fresh()->load('items');
        $this->assertSame((int) $this->biryani->id, (int) $order->items->sole()->meal_menu_item_id);
        $this->assertSame(0, MealOrderEdit::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_paid_order_whose_prices_moved_is_not_changed_online(): void
    {
        // Two plates bought at $8.00. The masjid then charges $10.00 a plate: adding
        // one must not re-charge the two already bought ($30.00 - $16.00 = $14.00).
        $order = $this->paidOrder([[$this->biryani, 2]]);
        $this->biryani->forceFill(['price_minor' => 1000])->save();

        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]])
            ->assertStatus(409)
            ->assertJsonPath('data.code', 'paid_prices_moved');

        $this->assertSame([], self::$created);
        $this->assertSame(0, MealOrderTopUp::withoutMasjidScope()->count());
        $this->assertSame(1600, (int) $order->fresh()->total_minor);

        $this->showOrder($order)
            ->assertOk()
            ->assertJsonPath('data.order.can_edit', false)
            ->assertJsonPath('data.order.edit_notice_code', 'paid_prices_moved');

        // Cheaper works the same way: a $3.00 side on a menu now at $6.00 a plate
        // must not pass as "the same total" or be refused as a removal.
        $this->biryani->forceFill(['price_minor' => 600])->save();
        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2],
            ['meal_menu_item_id' => $this->water->id, 'quantity' => 1],
        ])->assertStatus(409)->assertJsonPath('data.code', 'paid_prices_moved');
        $this->assertSame(0, MealOrderEdit::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_same_total_staff_change_is_not_undone_by_the_customers_payment(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        // Biryani ran out; staff swap it for lamb at the same price. Total and
        // settled amount are both unchanged.
        app(MealOrderEditor::class)->apply($order, $this->menu, [$this->lamb->id => 1], MealOrderEditor::ACTOR_STAFF, null);
        $this->assertSame(800, (int) $order->fresh()->total_minor);

        $this->signedWebhook($this->completedEvent($topUp, $order))->assertOk();

        $order = $order->fresh()->load('items');
        $this->assertSame((int) $this->lamb->id, (int) $order->items->sole()->meal_menu_item_id, 'the staff swap stands');
        $this->assertSame(1, (int) $order->items->sole()->quantity);
        $this->assertSame(1600, (int) $order->settled_total_minor, 'the payment is recorded, owed back');
        $this->assertSame(MealOrderTopUp::STATUS_CONFLICT, $topUp->fresh()->status);
    }

    #[Test]
    public function cancelling_a_paid_order_on_the_board_closes_its_top_up_page(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();

        Sanctum::actingAs($this->admin());
        $this->putJson(
            "/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/status",
            ['status' => MealOrder::STATUS_CANCELLED]
        )->assertOk()->assertJsonPath('warning', false);
        app(TenantContext::class)->forgetTenant();

        $this->assertSame(['cs_topup_1'], self::$expired);
        $this->assertSame(MealOrderTopUp::STATUS_EXPIRED, MealOrderTopUp::withoutMasjidScope()->sole()->status);
        $this->showOrder($order)->assertOk()->assertJsonPath('data.order.top_up', null);
    }

    #[Test]
    public function a_staff_edit_closes_the_customers_top_up_page_first(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();

        Sanctum::actingAs($this->admin());
        $this->patch(
            "/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$order->id}/items",
            ['items' => [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 1], ['meal_menu_item_id' => $this->water->id, 'quantity' => 1]]],
            ['Accept' => 'application/json']
        )->assertOk();
        app(TenantContext::class)->forgetTenant();

        $this->assertSame(['cs_topup_1'], self::$expired);
        $this->assertSame(MealOrderTopUp::STATUS_EXPIRED, MealOrderTopUp::withoutMasjidScope()->sole()->status);

        // A page paid a moment ago refuses the staff change instead.
        $paid = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($paid, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        self::$sessions['cs_topup_2'] = ['status' => 'complete', 'payment_status' => 'paid'];

        Sanctum::actingAs($this->admin());
        $this->patch(
            "/api/admin/masjids/{$this->masjid->id}/jummah-lunch/menus/{$this->menu->id}/orders/{$paid->id}/items",
            ['items' => [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 3]]],
            ['Accept' => 'application/json']
        )->assertStatus(422)
            ->assertJsonPath('data', 'The customer has just paid to add to this order, and Stripe is confirming it, so nothing was changed. Try again in a minute.');
        app(TenantContext::class)->forgetTenant();

        $this->assertSame(800, (int) $paid->fresh()->total_minor);
    }

    #[Test]
    public function a_difference_under_stripes_minimum_is_refused_by_name(): void
    {
        $sauce = $this->item('Sauce', 25);
        $order = $this->paidOrder([[$this->biryani, 1]]);

        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 1],
            ['meal_menu_item_id' => $sauce->id, 'quantity' => 1],
        ])->assertStatus(422)->assertJsonPath('data.code', 'topup_too_small');

        $this->assertSame([], self::$created);
        $this->assertSame(0, MealOrderTopUp::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_paid_order_with_a_balance_open_cannot_be_changed_by_its_customer(): void
    {
        // Two plates paid ($16.00). Staff take one off ($8.00 owed back) and refund
        // it in Stripe, which the app cannot see.
        $order = $this->paidOrder([[$this->biryani, 2]]);
        app(MealOrderEditor::class)->apply($order, $this->menu, [$this->biryani->id => 1], MealOrderEditor::ACTOR_STAFF, null);
        $this->assertSame(-800, (int) $order->fresh()->balance_minor);

        // Asking for the second plate back would come to exactly the $16.00 on
        // record, and be taken as a free swap.
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertStatus(409)
            ->assertJsonPath('data.code', 'paid_balance_open');

        // Nor is a smaller change told it "removes plates".
        $this->editAsCustomer($order, [
            ['meal_menu_item_id' => $this->biryani->id, 'quantity' => 1],
            ['meal_menu_item_id' => $this->water->id, 'quantity' => 1],
        ])->assertStatus(409)->assertJsonPath('data.code', 'paid_balance_open');

        $order = $order->fresh()->load('items');
        $this->assertSame(800, (int) $order->total_minor);
        $this->assertSame(1, (int) $order->items->sole()->quantity);
        $this->assertSame(1, MealOrderEdit::withoutMasjidScope()->count(), 'only the staff edit');
        $this->assertSame([], self::$created);

        $this->showOrder($order)
            ->assertOk()
            ->assertJsonPath('data.order.can_edit', false)
            ->assertJsonPath('data.order.edit_notice_code', 'paid_balance_open');
    }

    #[Test]
    public function after_a_conflict_the_customer_cannot_spend_the_money_owed_back(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        app(MealOrderEditor::class)->apply($order, $this->menu, [$this->biryani->id => 1, $this->water->id => 1], MealOrderEditor::ACTOR_STAFF, null);
        $this->signedWebhook($this->completedEvent($topUp, $order))->assertOk();
        $this->assertSame(-700, (int) $order->fresh()->balance_minor);

        // $16.00 on record against a $9.00 order: two plates and a water would come
        // to $17.00 and ask only $1.00; two plates alone would be "free".
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])
            ->assertStatus(409)
            ->assertJsonPath('data.code', 'paid_balance_open');
        $this->assertCount(1, self::$created);
        $this->assertSame(900, (int) $order->fresh()->total_minor);
    }

    #[Test]
    public function a_top_up_paid_but_not_applied_is_emailed_once(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]], ['customer_email' => self::CUSTOMER_EMAIL]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        $this->menu->forceFill(['status' => MealMenu::STATUS_CLOSED])->save();

        $event = $this->completedEvent($topUp, $order);
        $this->signedWebhook($event)->assertOk();
        $this->signedWebhook(array_merge($event, ['id' => 'evt_' . uniqid(), 'type' => 'checkout.session.async_payment_succeeded']))->assertOk();

        $sent = Mail::queued(LunchOrderConfirmation::class, fn (LunchOrderConfirmation $m) => $m->unappliedPaymentLine !== null);
        $this->assertCount(1, $sent);
        $mail = $sent->first();
        $this->assertTrue($mail->hasTo(self::CUSTOMER_EMAIL));
        $this->assertSame('$8.00', $mail->unappliedPaymentLine);
        $this->assertFalse($mail->updated);
        $this->assertNull($mail->changeUntil, 'an order with money owed back is not changed online');
        $this->assertStringContainsString('your order was not changed', $mail->render());
        $this->assertNotNull($topUp->fresh()->notified_at);
        Mail::assertNotQueued(LunchOrderConfirmation::class, fn (LunchOrderConfirmation $m) => $m->updated);
    }

    #[Test]
    public function the_email_promises_a_change_only_while_one_can_be_made_and_a_reply_only_where_it_goes_to_the_masjid(): void
    {
        $args = [
            'orderNumber' => '007', 'masjidName' => 'Test Masjid', 'customerName' => null, 'menuTitle' => null,
            'serviceDate' => null, 'items' => [], 'totalLine' => '$8.00', 'paidLine' => '$8.00', 'dueLine' => null,
            'dueLabel' => null, 'pickupNote' => null, 'orderUrl' => 'https://masjid.example.test/jummah-lunch/1/order/abc',
        ];

        $closed = (new LunchOrderConfirmation(...$args, changeUntil: null, masjidEmail: null))->render();
        $this->assertStringContainsString('View your order', $closed);
        $this->assertStringNotContainsString('View or change your order', $closed);
        $this->assertStringNotContainsString('Reply to it', $closed);

        $open = (new LunchOrderConfirmation(...$args, changeUntil: 'Friday, January 8 at 11:00 AM', masjidEmail: 'office@masjid.example.test'))->render();
        $this->assertStringContainsString('View or change your order', $open);
        $this->assertStringContainsString('Reply to it', $open);
        $this->assertStringContainsString('Adding plates to a paid order online closes 30 minutes before that.', $open);
    }

    #[Test]
    public function one_order_link_cannot_open_payment_pages_without_end(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $same = [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 1]];

        for ($i = 0; $i < 10; $i++) {
            $this->editAsCustomer($order, $same)->assertOk();
        }

        $this->editAsCustomer($order, $same)
            ->assertStatus(429)
            ->assertJsonPath('message', 'This order has been changed too many times in the last hour. Please try again later or contact the masjid.');
    }

    #[Test]
    public function an_event_from_another_account_is_refused_even_when_its_metadata_is_ours(): void
    {
        $order = $this->paidOrder([[$this->biryani, 1]]);
        $this->editAsCustomer($order, [['meal_menu_item_id' => $this->biryani->id, 'quantity' => 2]])->assertOk();
        $topUp = MealOrderTopUp::withoutMasjidScope()->sole();

        // Only the account differs: our masjid id, our session id, our order.
        $event = $this->completedEvent($topUp, $order);
        $event['account'] = $this->makeOrg()->stripe_account_id;
        $this->signedWebhook($event)->assertOk();

        $this->assertSame(MealOrderTopUp::STATUS_PENDING, $topUp->fresh()->status);
        $this->assertSame(800, (int) $order->fresh()->total_minor);
        $this->assertNull($order->fresh()->settled_total_minor);
    }

    #[Test]
    public function a_stripe_email_never_replaces_the_address_the_customer_gave(): void
    {
        $order = $this->unpaidOnlineOrder();
        $order->forceFill(['customer_email' => self::CUSTOMER_EMAIL])->save();

        $this->signedWebhook(['id' => 'evt_' . uniqid(), 'type' => 'checkout.session.completed', 'account' => $this->masjid->stripe_account_id, 'data' => ['object' => [
            'id' => 'cs_order_3',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_order_3',
            'customer_details' => ['email' => 'someone.else@example.test'],
            'metadata' => ['order_uuid' => $order->uuid, 'masjid_id' => (string) $this->masjid->id],
        ]]])->assertOk();

        $this->assertSame(MealOrder::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertSame(self::CUSTOMER_EMAIL, $order->fresh()->customer_email);
        $this->assertTrue(Mail::queued(LunchOrderConfirmation::class)->first()->hasTo(self::CUSTOMER_EMAIL));
    }

    // ------------------------------------------------------------------ helpers

    private function item(string $name, int $price, ?int $max = null): MealMenuItem
    {
        return MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => $name, 'price_minor' => $price, 'max_quantity' => $max,
        ]);
    }

    private function showOrder(MealOrder $order): TestResponse
    {
        return $this->getJson('/api/v1/lunch-orders/' . $order->uuid, ['masjid-id' => (string) $this->masjid->id]);
    }

    private function editAsCustomer(MealOrder $order, array $items): TestResponse
    {
        return $this->patchJson(
            '/api/v1/lunch-orders/' . $order->uuid,
            ['items' => $items],
            ['masjid-id' => (string) $this->masjid->id]
        );
    }

    /**
     * A PAID order as the webhook leaves one: lines snapshotted from the menu, paid,
     * confirmed, no email unless one is given.
     *
     * @param  array<int,array{0:MealMenuItem,1:int}>  $lines
     */
    private function paidOrder(array $lines, array $attrs = []): MealOrder
    {
        $order = new MealOrder([
            'meal_menu_id' => $this->menu->id,
            'customer_name' => 'Test Customer',
            'customer_phone' => '5550100199',
            'customer_email' => $attrs['customer_email'] ?? null,
            'payment_method' => $attrs['payment_method'] ?? MealOrder::METHOD_ONLINE,
        ]);
        $order->masjid_id = $this->masjid->id;
        $order->currency = 'usd';

        $subtotal = 0;
        foreach ($lines as [$item, $qty]) {
            $subtotal += (int) $item->price_minor * $qty;
        }

        $order->subtotal_minor = $subtotal;
        $order->total_minor = $subtotal;
        $order->order_number = str_pad((string) (++$this->orderNo), 3, '0', STR_PAD_LEFT);
        $order->placed_at = now();
        $order->payment_status = $attrs['payment_status'] ?? MealOrder::PAYMENT_PAID;
        $order->status = MealOrder::STATUS_CONFIRMED;
        $order->paid_at = now();
        $order->paid_via = $attrs['paid_via'] ?? null;
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

    /** An online order waiting for its card payment, placed without an email. */
    private function unpaidOnlineOrder(): MealOrder
    {
        $order = MealOrder::factory()->online()->create([
            'masjid_id' => $this->masjid->id,
            'meal_menu_id' => $this->menu->id,
            'customer_email' => null,
        ]);
        $order->items()->create([
            'masjid_id' => $order->masjid_id,
            'meal_menu_item_id' => $this->biryani->id,
            'item_name' => $this->biryani->name,
            'unit_price_minor' => 800,
            'quantity' => 1,
            'line_total_minor' => 800,
        ]);

        return $order->fresh();
    }

    /**
     * checkout.session.completed for a top-up, as Stripe sends it on the Connect
     * endpoint: everything matching unless overridden.
     */
    private function completedEvent(MealOrderTopUp $topUp, MealOrder $order, array $session = [], array $metadata = []): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'type' => 'checkout.session.completed',
            'account' => $this->masjid->stripe_account_id,
            'data' => ['object' => array_merge([
                'id' => $topUp->stripe_session_id,
                'object' => 'checkout.session',
                'status' => 'complete',
                'payment_status' => 'paid',
                'amount_total' => (int) $topUp->amount_minor,
                'currency' => 'usd',
                'payment_intent' => 'pi_topup_1',
                'metadata' => array_merge([
                    'kind' => MealOrderTopUp::STRIPE_KIND,
                    'top_up_id' => (string) $topUp->id,
                    'order_uuid' => $order->uuid,
                    'masjid_id' => (string) $this->masjid->id,
                ], $metadata),
            ], $session)],
        ];
    }

    /** Through the real endpoint and its real signature check, so the dispatch is exercised too. */
    private function signedWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function makeOrg(): Masjid
    {
        return Masjid::create([
            'name' => 'Top-up Test Org ' . uniqid(),
            'email' => 'office' . uniqid() . '@masjid.test',
            'phone' => '+1555' . random_int(1000000, 9999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => false, 'org_type' => 'masjid',
            // Stripe Connect onboarding complete: it can take card payments.
            'stripe_account_id' => 'acct_test_' . uniqid(),
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
        ]);
    }

    /** A MasjidAdmin who runs this organisation's board. */
    private function admin(): User
    {
        if (! \Spatie\Permission\Models\Role::query()->exists()) {
            $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        }

        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->masjid->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        if (! $this->masjid->user_id) {
            $this->masjid->user_id = $user->id;
            $this->masjid->save();
        }

        return $user->fresh();
    }

    /** The three seams the checkout service reaches Stripe through, and nothing else. */
    private function fakeStripe(): void
    {
        self::$created = [];
        self::$expired = [];
        self::$sessions = [];
        self::$skipClose = false;

        $this->app->bind(MealOrderCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends MealOrderCheckoutService
            {
                public function closeOpenTopUps(MealOrder $order): void
                {
                    if (MealOrderTopUpTest::$skipClose) {
                        return;
                    }

                    parent::closeOpenTopUps($order);
                }

                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    MealOrderTopUpTest::$created[] = ['params' => $params, 'account' => $connectedAccountId, 'key' => $idempotencyKey];
                    $n = count(MealOrderTopUpTest::$created);
                    MealOrderTopUpTest::$sessions["cs_topup_{$n}"] = ['status' => 'open', 'payment_status' => 'unpaid'];

                    return ['id' => "cs_topup_{$n}", 'url' => "https://stripe.test/topup/{$n}", 'payment_intent' => null];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                    MealOrderTopUpTest::$expired[] = $sessionId;
                    MealOrderTopUpTest::$sessions[$sessionId] = ['status' => 'expired', 'payment_status' => 'unpaid'];
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    $session = MealOrderTopUpTest::$sessions[$sessionId] ?? ['status' => 'expired', 'payment_status' => null];

                    return [
                        'status' => $session['status'],
                        'payment_status' => $session['payment_status'],
                        'url' => $session['status'] === 'open' ? 'https://stripe.test/topup/' . $sessionId : null,
                    ];
                }
            };
        });
    }
}
