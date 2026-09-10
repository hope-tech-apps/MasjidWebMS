<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\User;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * The optional extra a customer can add on top of the food.
 *
 * This is the ONE amount on an order that the client chooses, which makes it the
 * one place the module's "a request body never prices an order" rule needs a
 * deliberate, bounded exception. So the tests below are mostly about the edges
 * of that exception: a ceiling, a floor, integers only, and a menu that does not
 * offer it at all refusing a crafted body — the same rule the email field
 * follows, because hiding an input does not stop anyone from sending one.
 *
 * The extra is kept in its own column rather than folded into `subtotal_minor`
 * so food revenue and the extra can never be confused in a report, and it rides
 * on the SAME Stripe charge as its own line item — otherwise the line items stop
 * summing to `total_minor`, which is what the application fee is computed from.
 */
class MealOrderExtraDonationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private MealMenu $menu;

    private MealMenuItem $plate; // 800

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant(); // the public path runs UNBOUND

        $this->masjid = Masjid::create([
            'name' => 'Extra Test ' . uniqid(),
            'email' => 'office' . uniqid() . '@masjid.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
            'stripe_account_id' => 'acct_test_' . uniqid(),
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
        ]);

        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->open()->create();

        $this->plate = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Kabsah Plate', 'price_minor' => 800,
        ]);
    }

    private function header(): array
    {
        return ['masjid-id' => (string) $this->masjid->id];
    }

    /** One plate, plus whatever extra the caller wants to try. */
    private function orderBody(array $overrides = []): array
    {
        return array_merge([
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->plate->id, 'quantity' => 1]],
            'customer_name' => 'Aisha',
            'customer_phone' => '+15551230000',
            'payment_method' => MealOrder::METHOD_PICKUP,
        ], $overrides);
    }

    // ------------------------------------------------------------- the offer

    #[Test]
    public function the_menu_payload_advertises_the_offer_and_its_ceiling(): void
    {
        $this->getJson('/api/v1/lunch-menu', $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu.allow_donation', true)
            ->assertJsonPath('data.menu.max_donation_minor', MealOrder::MAX_DONATION_MINOR);
    }

    #[Test]
    public function a_menu_can_turn_the_offer_off(): void
    {
        $this->menu->update(['allow_donation' => false]);

        $this->getJson('/api/v1/lunch-menu', $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu.allow_donation', false);
    }

    // ------------------------------------------------------------- the money

    #[Test]
    public function an_extra_is_added_to_the_total_and_kept_apart_from_the_food(): void
    {
        $res = $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'donation_minor' => 250,
        ]), $this->header())->assertOk();

        $res->assertJsonPath('data.order.subtotal_minor', 800)
            ->assertJsonPath('data.order.donation_minor', 250)
            ->assertJsonPath('data.order.total_minor', 1050);

        $order = MealOrder::withoutMasjidScope()->first();
        $this->assertSame(800, (int) $order->subtotal_minor);
        $this->assertSame(250, (int) $order->donation_minor);
        $this->assertSame(1050, (int) $order->total_minor);
    }

    #[Test]
    public function an_ordinary_order_is_completely_unchanged(): void
    {
        // The whole point of defaulting the column to 0: every order placed
        // without touching the new field behaves exactly as it did before.
        $this->postJson('/api/v1/lunch-orders', $this->orderBody(), $this->header())
            ->assertOk()
            ->assertJsonPath('data.order.donation_minor', 0)
            ->assertJsonPath('data.order.total_minor', 800);
    }

    #[Test]
    public function a_menu_that_does_not_offer_the_extra_refuses_a_crafted_one(): void
    {
        // Hiding the input does not stop a crafted request from carrying a
        // figure; the server, not the form, decides whether it is charged.
        $this->menu->update(['allow_donation' => false]);

        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'donation_minor' => 5000,
        ]), $this->header())
            ->assertOk()
            ->assertJsonPath('data.order.donation_minor', 0)
            ->assertJsonPath('data.order.total_minor', 800);
    }

    // -------------------------------------------------------------- the edges

    #[Test]
    public function an_amount_over_the_ceiling_is_rejected(): void
    {
        // A public, unauthenticated endpoint must not be able to open a
        // five-figure Checkout Session on a masjid's live Stripe account.
        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'donation_minor' => MealOrder::MAX_DONATION_MINOR + 1,
        ]), $this->header())
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['donation_minor']]);

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_negative_amount_is_rejected(): void
    {
        // Otherwise the extra becomes a discount on the plate price.
        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'donation_minor' => -500,
        ]), $this->header())
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['donation_minor']]);

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_fractional_amount_is_rejected_because_minor_units_are_the_contract(): void
    {
        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'donation_minor' => 250.5,
        ]), $this->header())
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['donation_minor']]);
    }

    #[Test]
    public function the_ceiling_itself_is_allowed(): void
    {
        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'donation_minor' => MealOrder::MAX_DONATION_MINOR,
        ]), $this->header())
            ->assertOk()
            ->assertJsonPath('data.order.donation_minor', MealOrder::MAX_DONATION_MINOR);
    }

    // -------------------------------------------------------------- to Stripe

    #[Test]
    public function stripe_gets_the_extra_as_its_own_line_that_still_sums_to_the_total(): void
    {
        $sink = new \ArrayObject();
        $this->app->bind(MealOrderCheckoutService::class, fn ($app) => new class($app->make(StripeClient::class), $sink) extends MealOrderCheckoutService
        {
            public function __construct(StripeClient $stripe, private \ArrayObject $sink)
            {
                parent::__construct($stripe);
            }

            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                $this->sink['params'] = $params;

                return ['id' => 'cs_test_stub', 'url' => 'https://stripe.test/x', 'payment_intent' => 'pi_test_stub'];
            }
        });

        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'items' => [['item_id' => $this->plate->id, 'quantity' => 2]],
            'payment_method' => MealOrder::METHOD_ONLINE,
            'donation_minor' => 300,
        ]), $this->header())->assertOk();

        $lineItems = $sink['params']['line_items'];

        // The extra is NAMED on the hosted page rather than inflating a plate.
        $this->assertCount(2, $lineItems);
        $this->assertSame('Kabsah Plate', $lineItems[0]['price_data']['product_data']['name']);
        $this->assertSame(800, $lineItems[0]['price_data']['unit_amount']);
        $this->assertSame(2, $lineItems[0]['quantity']);
        $this->assertSame('Additional donation', $lineItems[1]['price_data']['product_data']['name']);
        $this->assertSame(300, $lineItems[1]['price_data']['unit_amount']);
        $this->assertSame(1, $lineItems[1]['quantity']);

        // The invariant the application fee depends on.
        $sum = 0;
        foreach ($lineItems as $li) {
            $sum += $li['price_data']['unit_amount'] * $li['quantity'];
        }
        $this->assertSame(1900, $sum);
        $this->assertSame(1900, (int) MealOrder::withoutMasjidScope()->first()->total_minor);
    }

    #[Test]
    public function an_order_without_an_extra_sends_stripe_no_donation_line(): void
    {
        $sink = new \ArrayObject();
        $this->app->bind(MealOrderCheckoutService::class, fn ($app) => new class($app->make(StripeClient::class), $sink) extends MealOrderCheckoutService
        {
            public function __construct(StripeClient $stripe, private \ArrayObject $sink)
            {
                parent::__construct($stripe);
            }

            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                $this->sink['params'] = $params;

                return ['id' => 'cs_test_stub', 'url' => 'https://stripe.test/x', 'payment_intent' => 'pi_test_stub'];
            }
        });

        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'payment_method' => MealOrder::METHOD_ONLINE,
        ]), $this->header())->assertOk();

        $this->assertCount(1, $sink['params']['line_items']);
    }

    // --------------------------------------------------------------- the board

    #[Test]
    public function the_order_board_reports_the_extra_separately_from_food_revenue(): void
    {
        $this->postJson('/api/v1/lunch-orders', $this->orderBody(['donation_minor' => 250]), $this->header())->assertOk();
        $this->postJson('/api/v1/lunch-orders', $this->orderBody(['donation_minor' => 0]), $this->header())->assertOk();

        // One of them settles in person.
        MealOrder::withoutMasjidScope()->orderBy('id')->first()->markPaid();

        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $admin->id;
        $this->masjid->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/masjids/' . $this->masjid->id . '/jummah-lunch/menus/' . $this->menu->id . '/orders')
            ->assertOk()
            // 1050 collected in total, of which 250 was the extra.
            ->assertJsonPath('data.summary.revenue_paid_minor', 1050)
            ->assertJsonPath('data.summary.donations_paid_minor', 250)
            ->assertJsonPath('data.summary.expected_total_minor', 1850)
            ->assertJsonPath('data.summary.donations_expected_minor', 250);
    }
}
