<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\MealOrder;
use App\Models\OrganisationPaymentMethod;
use App\Models\User;
use App\Support\PaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Accepted payment methods (owner, 2026-09-21): each organisation lists the ways
 * it accepts money, with its own "how to pay" text per method, and every module
 * that takes offline payments can record each of them on "Mark as paid".
 *
 * The admin screen replaces the whole set in one save; the public read tells a
 * page only what the organisation can honour today (card only while its Stripe
 * account can take charges). Tenant isolation is the guardrail's: another
 * organisation's route is a 403, its rows are invisible, a write is stamped with
 * the bound tenant whatever the body says. All addresses here are invented.
 */
class OrganisationPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid(true);
        $this->masjidB = $this->makeMasjid(true);
        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);
    }

    private function url(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id . '/payment-methods';
    }

    #[Test]
    public function an_admin_saves_the_methods_their_organisation_accepts_in_the_order_given(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->url(), ['methods' => [
            ['method' => 'zelle', 'instructions' => 'Send to pay@org-a.example.test'],
            ['method' => 'cash', 'instructions' => 'At the office.'],
            ['method' => 'other', 'label' => 'PayPal', 'instructions' => 'paypal.example.test/org-a'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.methods.0.method', 'zelle')
            ->assertJsonPath('data.methods.1.method', 'cash')
            ->assertJsonPath('data.methods.2.label', 'PayPal')
            ->assertJsonPath('data.card_ready', true);

        $this->assertSame(
            ['zelle', 'cash', 'other'],
            OrganisationPaymentMethod::withoutMasjidScope()->where('masjid_id', $this->masjidA->id)->orderBy('sort_order')->pluck('method')->all()
        );
    }

    #[Test]
    public function saving_again_replaces_the_set_and_a_method_left_out_is_no_longer_accepted(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->url(), ['methods' => [['method' => 'zelle'], ['method' => 'check', 'instructions' => 'Payable to Org A.']]])->assertOk();
        $this->putJson($this->url(), ['methods' => [['method' => 'check', 'instructions' => 'Payable to Org A, memo: order number.']]])->assertOk();

        $rows = OrganisationPaymentMethod::withoutMasjidScope()->where('masjid_id', $this->masjidA->id)->get();
        $this->assertSame(['check'], $rows->pluck('method')->all());
        $this->assertSame('Payable to Org A, memo: order number.', $rows->first()->instructions);

        // An empty list is a deliberate "none of these".
        $this->putJson($this->url(), ['methods' => []])->assertOk();
        $this->assertSame(0, OrganisationPaymentMethod::withoutMasjidScope()->where('masjid_id', $this->masjidA->id)->count());
    }

    #[Test]
    public function a_body_that_lost_the_list_or_names_a_method_twice_or_an_unknown_one_changes_nothing(): void
    {
        Sanctum::actingAs($this->adminA);
        $this->putJson($this->url(), ['methods' => [['method' => 'zelle', 'instructions' => 'keep me']]])->assertOk();

        foreach ([
            [],
            ['methods' => [['method' => 'cash'], ['method' => 'cash']]],
            ['methods' => [['method' => 'bitcoin']]],
            ['methods' => [['method' => 'cash', 'label' => str_repeat('x', 65)]]],
            ['methods' => [['method' => 'other']]],
        ] as $body) {
            $this->putJson($this->url(), $body)->assertStatus(422)->assertJsonPath('status', 'failed');
        }

        $this->assertSame(
            ['zelle' => 'keep me'],
            OrganisationPaymentMethod::withoutMasjidScope()->where('masjid_id', $this->masjidA->id)->pluck('instructions', 'method')->all()
        );
    }

    #[Test]
    public function another_organisations_methods_are_refused_invisible_and_untouched(): void
    {
        $theirs = new OrganisationPaymentMethod(['method' => 'zelle', 'instructions' => 'Org B only']);
        $theirs->masjid_id = $this->masjidB->id;
        $theirs->save();

        Sanctum::actingAs($this->adminA);

        // Naming B in the route: refused by the tenant middleware.
        $this->getJson($this->url($this->masjidB))->assertForbidden();
        $this->putJson($this->url($this->masjidB), ['methods' => []])->assertForbidden();

        // A's own screen neither shows nor deletes B's row, and a write is stamped A.
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.methods', []);
        $this->putJson($this->url(), ['methods' => [['method' => 'cash']]])->assertOk();

        $this->assertDatabaseHas('organisation_payment_methods', ['id' => $theirs->id, 'masjid_id' => $this->masjidB->id, 'instructions' => 'Org B only']);
        $this->assertSame(
            (int) $this->masjidA->id,
            (int) OrganisationPaymentMethod::withoutMasjidScope()->where('method', 'cash')->value('masjid_id')
        );
        $this->assertDatabaseMissing('organisation_payment_methods', ['masjid_id' => $this->masjidB->id, 'method' => 'cash']);
    }

    #[Test]
    public function the_public_list_offers_card_only_while_the_stripe_account_can_take_charges(): void
    {
        foreach (['card' => null, 'zelle' => 'Send to pay@org-a.example.test'] as $method => $instructions) {
            $row = new OrganisationPaymentMethod(['method' => $method, 'instructions' => $instructions]);
            $row->masjid_id = $this->masjidA->id;
            $row->save();
        }

        $this->getJson('/api/v1/payment-methods', ['masjid-id' => (string) $this->masjidA->id])
            ->assertOk()
            ->assertJsonPath('data.methods.0.method', 'card')
            ->assertJsonPath('data.methods.0.online', true)
            ->assertJsonPath('data.methods.1.label', 'Zelle')
            ->assertJsonPath('data.methods.1.instructions', 'Send to pay@org-a.example.test');

        $this->masjidA->forceFill(['stripe_charges_enabled' => false])->save();

        $this->getJson('/api/v1/payment-methods', ['masjid-id' => (string) $this->masjidA->id])
            ->assertOk()
            ->assertJsonCount(1, 'data.methods')
            ->assertJsonPath('data.methods.0.method', 'zelle');

        // Another organisation's list never includes A's rows.
        $this->getJson('/api/v1/payment-methods', ['masjid-id' => (string) $this->masjidB->id])
            ->assertOk()
            ->assertJsonPath('data.methods', []);
        $this->getJson('/api/v1/payment-methods', ['masjid-id' => '999999'])->assertNotFound();
    }

    #[Test]
    public function the_admin_screen_says_when_card_is_saved_but_cannot_be_offered_yet(): void
    {
        $this->masjidA->forceFill(['stripe_charges_enabled' => false])->save();
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->url(), ['methods' => [['method' => 'card']]])
            ->assertOk()
            ->assertJsonPath('data.methods.0.method', 'card')
            ->assertJsonPath('data.card_ready', false);
    }

    #[Test]
    public function every_offline_method_an_organisation_can_advertise_can_be_recorded_on_mark_paid_and_on_an_offline_gift(): void
    {
        foreach (PaymentMethods::OFFLINE as $method) {
            $this->assertContains($method, MealOrder::PAID_VIA, "the lunch and kitchen board cannot record {$method}");
            $this->assertArrayHasKey($method, MealOrder::PAID_VIA_LABELS);
            $this->assertContains($method, FormResponse::PAID_VIA, "forms cannot record {$method}");
            $this->assertArrayHasKey($method, FormResponse::PAID_VIA_LABELS);
            // Cash is "Take cash" on forms; every other one is "Mark paid".
            if ($method !== PaymentMethods::CASH) {
                $this->assertContains($method, FormResponse::PAID_VIA_EXTERNAL, "Mark paid on a form cannot record {$method}");
            }
            $this->assertContains($method, Donation::OFFLINE_PAYMENT_METHODS, "an offline gift cannot record {$method}");
            $this->assertLessThanOrEqual(16, strlen($method), 'paid_via and preferred_payment are 16 characters');
        }
    }

    #[Test]
    public function the_instructions_column_is_text_and_the_method_column_a_short_string(): void
    {
        $this->assertSame('text', Schema::getColumnType('organisation_payment_methods', 'instructions'));
        $this->assertSame('varchar', Schema::getColumnType('organisation_payment_methods', 'method'));
    }

    private function makeMasjid(bool $payable): Masjid
    {
        return Masjid::create([
            'name' => 'Payments Test Org ' . uniqid(),
            'email' => 'office' . uniqid() . '@org.example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => false, 'org_type' => 'masjid',
            'stripe_account_id' => $payable ? 'acct_test_' . uniqid() : null,
            'stripe_charges_enabled' => $payable,
            'stripe_payouts_enabled' => $payable,
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }
}
