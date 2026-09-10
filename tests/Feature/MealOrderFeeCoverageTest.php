<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Services\Stripe\DonationService;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\StripeFees;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * The customer's option to absorb Stripe's processing fee.
 *
 * These are Connect DIRECT charges, so Stripe's cut comes out of the MASJID's
 * balance: an $8 plate settles at $7.47, because the flat 30c alone is 3.75% of
 * one plate. This is the same offer the donations module has always made.
 *
 * The property that matters, and the reason the arithmetic is a gross-up rather
 * than "add 2.9% + 30c": STRIPE CHARGES ITS FEE ON THE GROSSED-UP TOTAL, so
 * naively adding the fee leaves the org a few cents short every time. The tests
 * below assert the org lands on EXACTLY the intended amount after Stripe's own
 * fee formula is applied back to the charge.
 *
 * The customer sends a yes/no; the surcharge is computed on the server, so no
 * request body ever states an amount.
 */
class MealOrderFeeCoverageTest extends TestCase
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
        config(['services.stripe.fee_percentage' => 0.029, 'services.stripe.fee_fixed' => 30]);

        app(TenantContext::class)->forgetTenant();

        $this->masjid = Masjid::create([
            'name' => 'Fee Test ' . uniqid(),
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

    private function orderBody(array $overrides = []): array
    {
        return array_merge([
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->plate->id, 'quantity' => 1]],
            'customer_name' => 'Aisha',
            'customer_phone' => '+15551230000',
            'payment_method' => MealOrder::METHOD_ONLINE,
        ], $overrides);
    }

    private function stubCheckout(\ArrayObject $sink): void
    {
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
    }

    // ------------------------------------------------------------ the money

    #[Test]
    public function covering_the_fee_leaves_the_masjid_with_exactly_the_food_price(): void
    {
        $this->stubCheckout(new \ArrayObject());

        $this->postJson('/api/v1/lunch-orders', $this->orderBody(['cover_fees' => true]), $this->header())
            ->assertOk()
            ->assertJsonPath('data.order.subtotal_minor', 800)
            ->assertJsonPath('data.order.fee_covered_minor', 55)
            ->assertJsonPath('data.order.total_minor', 855);

        // The whole point: apply Stripe's OWN fee formula back to the charge and
        // the masjid must land on 800, not 799 or 801.
        $this->assertSame(800, 855 - StripeFees::on(855));
    }

    #[Test]
    public function the_gross_up_holds_across_a_range_of_baskets(): void
    {
        // A naive "add 2.9% + 30c" is short on every one of these.
        foreach ([100, 800, 1600, 2400, 5000, 12345] as $intended) {
            $charged = StripeFees::grossUp($intended);
            $this->assertSame(
                $intended,
                $charged - StripeFees::on($charged),
                "gross-up failed to net {$intended}"
            );
        }
    }

    #[Test]
    public function the_fee_covers_the_optional_extra_too(): void
    {
        $this->stubCheckout(new \ArrayObject());

        // $8 food + $5 extra = $13 intended; the surcharge is computed on both.
        $expected = StripeFees::coverage(1300);

        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'donation_minor' => 500,
            'cover_fees' => true,
        ]), $this->header())
            ->assertOk()
            ->assertJsonPath('data.order.donation_minor', 500)
            ->assertJsonPath('data.order.fee_covered_minor', $expected)
            ->assertJsonPath('data.order.total_minor', 1300 + $expected);

        $this->assertSame(1300, (1300 + $expected) - StripeFees::on(1300 + $expected));
    }

    #[Test]
    public function declining_leaves_the_order_exactly_as_before(): void
    {
        $this->stubCheckout(new \ArrayObject());

        $this->postJson('/api/v1/lunch-orders', $this->orderBody(['cover_fees' => false]), $this->header())
            ->assertOk()
            ->assertJsonPath('data.order.fee_covered_minor', 0)
            ->assertJsonPath('data.order.total_minor', 800);
    }

    #[Test]
    public function saying_nothing_at_all_never_surcharges(): void
    {
        // An older client that has never heard of the field must not start
        // charging customers more than they agreed to.
        $this->stubCheckout(new \ArrayObject());

        $this->postJson('/api/v1/lunch-orders', $this->orderBody(), $this->header())
            ->assertOk()
            ->assertJsonPath('data.order.fee_covered_minor', 0)
            ->assertJsonPath('data.order.total_minor', 800);
    }

    // ----------------------------------------------------------- the refusals

    #[Test]
    public function a_pay_at_pickup_order_is_never_surcharged(): void
    {
        // It never touches Stripe, so there is no fee to cover — taking one
        // would be charging for nothing.
        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'payment_method' => MealOrder::METHOD_PICKUP,
            'cover_fees' => true,
        ]), $this->header())
            ->assertOk()
            ->assertJsonPath('data.order.fee_covered_minor', 0)
            ->assertJsonPath('data.order.total_minor', 800);
    }

    #[Test]
    public function a_menu_with_the_offer_off_refuses_a_crafted_request(): void
    {
        $this->menu->update(['allow_fee_coverage' => false]);
        $this->stubCheckout(new \ArrayObject());

        $this->postJson('/api/v1/lunch-orders', $this->orderBody(['cover_fees' => true]), $this->header())
            ->assertOk()
            ->assertJsonPath('data.order.fee_covered_minor', 0)
            ->assertJsonPath('data.order.total_minor', 800);
    }

    #[Test]
    public function the_menu_payload_carries_the_offer_and_the_published_rate(): void
    {
        $this->getJson('/api/v1/lunch-menu', $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu.allow_fee_coverage', true)
            ->assertJsonPath('data.menu.stripe_fee_percentage', 0.029)
            ->assertJsonPath('data.menu.stripe_fee_fixed_minor', 30);
    }

    // -------------------------------------------------------------- to Stripe

    #[Test]
    public function stripe_gets_the_surcharge_as_its_own_named_line(): void
    {
        $sink = new \ArrayObject();
        $this->stubCheckout($sink);

        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'donation_minor' => 500,
            'cover_fees' => true,
        ]), $this->header())->assertOk();

        $lineItems = $sink['params']['line_items'];
        $names = array_map(fn ($li) => $li['price_data']['product_data']['name'], $lineItems);

        $this->assertSame(['Kabsah Plate', 'Additional donation', 'Card processing fee'], $names);

        // The invariant the application fee depends on.
        $sum = 0;
        foreach ($lineItems as $li) {
            $sum += $li['price_data']['unit_amount'] * $li['quantity'];
        }
        $this->assertSame((int) MealOrder::withoutMasjidScope()->first()->total_minor, $sum);
    }

    // ------------------------------------------------- the shared arithmetic

    #[Test]
    public function the_donations_module_still_computes_identical_figures(): void
    {
        // DonationService now delegates to StripeFees. Its published behaviour
        // must not have moved by a single cent.
        foreach ([500, 1000, 2500, 10000] as $amount) {
            $this->assertSame(StripeFees::grossUp($amount), DonationService::grossUp($amount));
            $this->assertSame(StripeFees::on($amount), DonationService::computeStripeFee($amount));
        }

        // The documented example from the original implementation.
        $this->assertSame(10330, DonationService::grossUp(10000));
    }

    #[Test]
    public function an_explicit_rate_override_is_still_honoured(): void
    {
        // The override arguments exist so a non-US rate can be passed in.
        $this->assertSame(0, StripeFees::grossUp(0));
        $this->assertSame(1100, StripeFees::grossUp(1000, 0.0, 100));
        $this->assertSame(100, StripeFees::coverage(1000, 0.0, 100));
    }
}
