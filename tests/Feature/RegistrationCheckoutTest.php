<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Services\Registrations\RegistrationService;
use App\Services\Stripe\RegistrationCheckoutService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-006c — the PAID one-time path's outbound half: the public quote / register
 * / checkout endpoints and RegistrationCheckoutService.
 *
 * Stripe is MOCKED at its single seam (the protected createCheckoutSession);
 * nothing here reaches the live API. What the seam RECEIVES is asserted, because
 * that is where the locked doctrine actually lives: a direct charge on the org's
 * connected account, a positive-only application fee, integer minor units, and
 * an expiry aligned to the seat hold.
 *
 * The invariant that outranks all of them: creating a session advances NOTHING.
 * A registration is still pending and unpaid after the URL is handed out —
 * only a signature-verified webhook can change that (RegistrationWebhookTest).
 */
class RegistrationCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private Masjid $otherMasjid;

    /** @var array<string,mixed> what the Stripe seam was called with */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        config([
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.currency' => 'usd',
            'services.stripe.registration_checkout_window_minutes' => 30,
        ]);

        // /api/v1 never runs the tenant middleware — everything on this path
        // must set/filter masjid_id explicitly.
        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $this->otherMasjid = $this->makeMasjid(['stripe_account_id' => 'acct_B', 'stripe_charges_enabled' => true]);
    }

    // ------------------------------------------------------------- quote

    #[Test]
    public function quote_prices_from_the_plan_server_side_and_writes_nothing(): void
    {
        [$offering, $plan] = $this->makeOffering();

        $this->postQuote($offering, ['fee_plan_id' => $plan->id])
            ->assertOk()
            ->assertJsonPath('data.list_total_minor', 15000)
            ->assertJsonPath('data.adjusted_total_minor', 15000)
            ->assertJsonPath('data.amount_due_minor', 15000)
            ->assertJsonPath('data.currency', 'usd')
            ->assertJsonPath('data.requires_payment', true);

        // A quote is a preview: no registration, no form response, no seat.
        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('form_responses', 0);
        $this->assertSame(0, $offering->fresh()->registration_count);
    }

    #[Test]
    public function quote_reports_an_existing_registrations_adjusted_snapshot(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        // Aid is granted server-side by an admin, strictly pre-checkout.
        app(RegistrationService::class)->grantAdjustment(
            $registration,
            RegistrationAdjustment::KIND_AID,
            5000,
            'Need-based aid'
        );

        $this->postQuote($offering, [
            'fee_plan_id' => $plan->id,
            'registration_uuid' => $registration->uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.list_total_minor', 15000)
            ->assertJsonPath('data.adjusted_total_minor', 10000)
            ->assertJsonPath('data.amount_due_minor', 10000);
    }

    #[Test]
    public function quote_never_applies_a_client_supplied_code(): void
    {
        [$offering, $plan] = $this->makeOffering();

        $this->postQuote($offering, ['fee_plan_id' => $plan->id, 'code' => 'FREE100'])
            ->assertOk()
            ->assertJsonPath('data.code_applied', false)
            ->assertJsonPath('data.amount_due_minor', 15000);
    }

    #[Test]
    public function quote_refuses_another_tenants_offering(): void
    {
        [$offering, $plan] = $this->makeOffering();

        // Masjid B asking for masjid A's slug is the same miss as a bogus slug.
        $this->postJson(
            "/api/v1/offerings/{$offering->slug}/quote",
            ['fee_plan_id' => $plan->id],
            ['masjid-id' => (string) $this->otherMasjid->id]
        )->assertStatus(404);
    }

    // ---------------------------------------------------------- register

    #[Test]
    public function register_holds_a_seat_and_returns_a_hosted_checkout_url(): void
    {
        $this->stubSession('cs_reg_1', 'https://checkout.stripe.test/cs_reg_1', 'pi_reg_1');

        [$offering, $plan] = $this->makeOffering(['capacity' => 5]);

        $response = $this->postRegister($offering, [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf', 'email' => 'amal@example.test'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertOk();

        $response->assertJsonPath('data.status', Registration::STATUS_PENDING)
            ->assertJsonPath('data.payment_status', Registration::PAYMENT_AWAITING)
            ->assertJsonPath('data.amount_due_minor', 15000)
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/cs_reg_1');

        // Reserve-at-pending: the seat is held while payment is outstanding.
        $this->assertSame(1, $offering->fresh()->registration_count);

        $registration = Registration::first();
        $this->assertSame('cs_reg_1', $registration->stripe_checkout_session_id);

        // The URL is a redirect, not a payment: nothing is confirmed or booked.
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertDatabaseCount('registration_payments', 0);

        // Contacts-first: the payer exists in the CRM.
        $this->assertDatabaseHas('contacts', [
            'masjid_id' => $this->masjid->id,
            'email' => 'amal@example.test',
        ]);
    }

    #[Test]
    public function register_on_a_free_plan_confirms_with_no_stripe_leg_at_all(): void
    {
        // Bound so any call to the seam would blow the test up.
        $service = Mockery::mock(RegistrationCheckoutService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldNotReceive('createCheckoutSession');
        $this->app->instance(RegistrationCheckoutService::class, $service);

        $offering = Offering::factory()->forMasjid($this->masjid)->withCapacity(5)->create();
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $this->postRegister($offering, [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Free Rider', 'email' => 'free@example.test'],
            'data' => ['full_name' => 'Free Rider'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Registration::STATUS_CONFIRMED)
            ->assertJsonPath('data.payment_status', Registration::PAYMENT_NONE)
            ->assertJsonPath('data.amount_due_minor', 0)
            ->assertJsonPath('data.checkout_url', null);

        $registration = Registration::first();
        $this->assertNull($registration->stripe_checkout_session_id);
        $this->assertNull($registration->idempotency_key);
    }

    #[Test]
    public function register_validates_intake_answers_against_the_stored_schema(): void
    {
        [$offering, $plan] = $this->makeOffering();

        // full_name is required by the form's own schema, not by the request.
        $response = $this->postRegister($offering, [
            'fee_plan_id' => $plan->id,
            'payer' => ['email' => 'nobody@example.test'],
            'data' => ['unrelated' => 'x'],
        ])->assertStatus(422);

        $response->assertJsonPath('status', 'failed');
        $this->assertArrayHasKey('full_name', $response->json('data'));

        $this->assertDatabaseCount('registrations', 0);
    }

    #[Test]
    public function register_refuses_another_tenants_offering(): void
    {
        [$offering, $plan] = $this->makeOffering();

        $this->postJson(
            "/api/v1/offerings/{$offering->slug}/register",
            [
                'fee_plan_id' => $plan->id,
                'payer' => ['email' => 'thief@example.test'],
                'data' => ['full_name' => 'Thief'],
            ],
            ['masjid-id' => (string) $this->otherMasjid->id]
        )->assertStatus(404);

        $this->assertDatabaseCount('registrations', 0);
    }

    // ---------------------------------------------------------- checkout

    #[Test]
    public function the_session_is_a_direct_charge_on_the_orgs_connected_account(): void
    {
        $this->stubSession('cs_direct', 'https://checkout.stripe.test/cs_direct', 'pi_direct');

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $this->postCheckout($registration)->assertOk();

        // The Stripe-Account header: funds land in the ORG's balance, the org
        // is merchant of record. Never a destination charge.
        $this->assertSame('acct_A', $this->captured['account']);

        $params = $this->captured['params'];
        $this->assertSame('payment', $params['mode']);
        $this->assertSame($registration->uuid, $params['client_reference_id']);
        $this->assertSame($registration->uuid, $params['metadata']['registration_uuid']);
        $this->assertSame(
            $registration->uuid,
            $params['payment_intent_data']['metadata']['registration_uuid']
        );

        // Integer minor units, straight off the snapshot.
        $this->assertSame(15000, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('usd', $params['line_items'][0]['price_data']['currency']);

        // No escrow-shaped parameter may ever appear.
        $this->assertArrayNotHasKey('transfer_data', $params);
        $this->assertArrayNotHasKey('on_behalf_of', $params);
    }

    #[Test]
    public function no_application_fee_is_sent_when_the_platform_takes_nothing(): void
    {
        config(['services.stripe.platform_fee_percentage' => 0]);
        $this->stubSession('cs_fee_0', 'https://checkout.stripe.test/cs_fee_0', null);

        [$offering, $plan] = $this->makeOffering();
        $this->postCheckout($this->registerThrough($offering, $plan))->assertOk();

        // Stripe rejects a zero fee — the key must be absent, not 0.
        $this->assertArrayNotHasKey(
            'application_fee_amount',
            $this->captured['params']['payment_intent_data']
        );
    }

    #[Test]
    public function a_positive_application_fee_is_sent_when_the_platform_takes_a_cut(): void
    {
        config(['services.stripe.platform_fee_percentage' => 0.02]);
        $this->stubSession('cs_fee_2', 'https://checkout.stripe.test/cs_fee_2', null);

        [$offering, $plan] = $this->makeOffering();
        $this->postCheckout($this->registerThrough($offering, $plan))->assertOk();

        // 2% of $150.00 = 300 minor units.
        $this->assertSame(
            300,
            $this->captured['params']['payment_intent_data']['application_fee_amount']
        );
    }

    #[Test]
    public function the_first_session_uses_the_intake_key_and_a_remint_rotates_it(): void
    {
        $this->stubSession('cs_key_1', 'https://checkout.stripe.test/cs_key_1', null);

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $intakeKey = $registration->idempotency_key;
        $this->assertNotNull($intakeKey);

        $this->postCheckout($registration)->assertOk();
        $this->assertSame($intakeKey, $this->captured['key']);

        // Re-mint: the old session is being abandoned, so the key rotates —
        // otherwise Stripe would replay the stale session.
        $this->postCheckout($registration)->assertOk();
        $this->assertNotSame($intakeKey, $this->captured['key']);
        $this->assertSame($this->captured['key'], $registration->fresh()->idempotency_key);
    }

    #[Test]
    public function the_session_expiry_is_aligned_to_the_seat_hold(): void
    {
        $this->stubSession('cs_exp', 'https://checkout.stripe.test/cs_exp', null);

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $this->postCheckout($registration)->assertOk();

        $expiresAt = $this->captured['params']['expires_at'];

        // Stripe's floor is 30 minutes; the seat hold we sweep against and the
        // deadline Stripe expires against are the SAME instant.
        $this->assertGreaterThanOrEqual(now()->addMinutes(29)->getTimestamp(), $expiresAt);
        $this->assertSame($registration->fresh()->checkout_expires_at->getTimestamp(), $expiresAt);
    }

    #[Test]
    public function checkout_refuses_a_registration_with_nothing_left_to_pay(): void
    {
        $service = Mockery::mock(RegistrationCheckoutService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldNotReceive('createCheckoutSession');
        $this->app->instance(RegistrationCheckoutService::class, $service);

        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        // The free-path carve-out already confirmed it — never a $0 session.
        $this->postCheckout($registration)->assertStatus(422);
    }

    // ------------------------------------------- T-006e: subscription shapes

    #[Test]
    public function an_installment_plan_opens_a_subscription_session_priced_per_installment(): void
    {
        $this->stubSession('cs_inst', 'https://checkout.stripe.test/cs_inst', null);

        [$offering, $registration] = $this->subscriptionRegistration(
            FeePlan::factory()->installment(9, 10000)
        );

        $this->postCheckout($registration)->assertOk();

        $params = $this->captured['params'];

        // Still a DIRECT charge on the org's own account — the shape of the
        // session changes, the money path does not.
        $this->assertSame('acct_A', $this->captured['account']);
        $this->assertSame('subscription', $params['mode']);
        $this->assertArrayNotHasKey('payment_intent_data', $params);
        $this->assertArrayNotHasKey('transfer_data', $params);
        $this->assertArrayNotHasKey('on_behalf_of', $params);

        // The snapshot is the FULL commitment ($900); one charge is a ninth of
        // it, in integer minor units.
        $this->assertSame(90000, (int) $registration->adjusted_total_minor);
        $this->assertSame(10000, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('month', $params['line_items'][0]['price_data']['recurring']['interval']);

        // Written onto the SUBSCRIPTION, so every invoice it raises carries the
        // routing signal the webhook dispatches on.
        $this->assertSame(
            $registration->uuid,
            $params['subscription_data']['metadata']['registration_uuid']
        );
    }

    #[Test]
    public function a_recurring_plan_opens_an_open_ended_subscription_at_the_interval_price(): void
    {
        $this->stubSession('cs_rec', 'https://checkout.stripe.test/cs_rec', null);

        [$offering, $registration] = $this->subscriptionRegistration(
            FeePlan::factory()->recurring(FeePlan::INTERVAL_YEAR, 2500)
        );

        $this->postCheckout($registration)->assertOk();

        $params = $this->captured['params'];

        $this->assertSame('subscription', $params['mode']);
        // Open-ended has no finite total: the snapshot IS the per-interval charge.
        $this->assertSame(2500, (int) $registration->adjusted_total_minor);
        $this->assertSame(2500, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('year', $params['line_items'][0]['price_data']['recurring']['interval']);
    }

    #[Test]
    public function a_subscription_sends_no_application_fee_percent_when_the_platform_takes_nothing(): void
    {
        config(['services.stripe.platform_fee_percentage' => 0]);
        $this->stubSession('cs_sub_fee_0', 'https://checkout.stripe.test/cs_sub_fee_0', null);

        [, $registration] = $this->subscriptionRegistration(FeePlan::factory()->installment(4, 5000));

        $this->postCheckout($registration)->assertOk();

        // Stripe rejects a zero application_fee_percent — absent, never 0.
        $this->assertArrayNotHasKey(
            'application_fee_percent',
            $this->captured['params']['subscription_data']
        );
    }

    #[Test]
    public function a_subscription_sends_a_positive_application_fee_percent(): void
    {
        config(['services.stripe.platform_fee_percentage' => 0.02]);
        $this->stubSession('cs_sub_fee_2', 'https://checkout.stripe.test/cs_sub_fee_2', null);

        [, $registration] = $this->subscriptionRegistration(FeePlan::factory()->installment(4, 5000));

        $this->postCheckout($registration)->assertOk();

        // A PERCENT, not an amount: subscriptions have no application_fee_amount.
        $this->assertSame(
            2.0,
            $this->captured['params']['subscription_data']['application_fee_percent']
        );
    }

    #[Test]
    public function aid_reduces_every_installment_and_rounding_favours_the_payer(): void
    {
        $this->stubSession('cs_aid', 'https://checkout.stripe.test/cs_aid', null);

        [, $registration] = $this->subscriptionRegistration(FeePlan::factory()->installment(3, 10000));

        // $300 commitment, $100.01 waived → $199.99 over three payments.
        app(RegistrationService::class)->grantAdjustment(
            $registration,
            RegistrationAdjustment::KIND_AID,
            10001,
            'Need-based aid'
        );

        $this->postCheckout($registration->fresh())->assertOk();

        // 19999 / 3 = 6666.33 → 6666 per charge. The 1¢ remainder is forgiven:
        // rounding never charges a family more than they were quoted.
        $this->assertSame(6666, $this->captured['params']['line_items'][0]['price_data']['unit_amount']);
    }

    #[Test]
    public function a_subscription_plan_with_no_billing_interval_refuses_loudly(): void
    {
        $service = Mockery::mock(RegistrationCheckoutService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldNotReceive('createCheckoutSession');
        $this->app->instance(RegistrationCheckoutService::class, $service);

        [, $registration] = $this->subscriptionRegistration(
            FeePlan::factory()->recurring()->state(['billing_interval' => null])
        );

        // Money kinds never degrade: guessing "month" would invent a billing
        // cadence nobody agreed to.
        $this->postCheckout($registration)->assertStatus(422);
    }

    #[Test]
    public function a_created_subscription_session_alone_confirms_and_pays_nothing(): void
    {
        $this->stubSession('cs_sub_noop', 'https://checkout.stripe.test/cs_sub_noop', null);

        [, $registration] = $this->subscriptionRegistration(FeePlan::factory()->installment(9, 10000));

        $this->postCheckout($registration)->assertOk();

        // The trust boundary is identical for subscriptions: only a
        // signature-verified webhook advances anything.
        $fresh = $registration->fresh();
        $this->assertSame(Registration::STATUS_PENDING, $fresh->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $fresh->payment_status);
        $this->assertNull($fresh->stripe_subscription_id);
        $this->assertNull($fresh->stripe_subscription_schedule_id);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    #[Test]
    public function checkout_refuses_another_tenants_registration(): void
    {
        $this->stubSession('cs_x', 'https://checkout.stripe.test/cs_x', null);

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        // Masjid B cannot check out masjid A's registration onto acct_B.
        $this->postJson(
            "/api/v1/registrations/{$registration->uuid}/checkout",
            [],
            ['masjid-id' => (string) $this->otherMasjid->id]
        )->assertStatus(404);

        $this->assertNull($registration->fresh()->stripe_checkout_session_id);
    }

    #[Test]
    public function checkout_refuses_an_org_that_has_not_finished_connect_onboarding(): void
    {
        $notReady = $this->makeMasjid();   // no connected account
        $offering = Offering::factory()->forMasjid($notReady)->create();
        $plan = FeePlan::factory()->create([
            'masjid_id' => $notReady->id,
            'offering_id' => $offering->id,
        ]);

        $registration = app(RegistrationService::class)->register(
            $offering,
            $plan,
            Contact::factory()->create(['masjid_id' => $notReady->id]),
            ['full_name' => 'No Stripe']
        );

        $this->postJson(
            "/api/v1/registrations/{$registration->uuid}/checkout",
            [],
            ['masjid-id' => (string) $notReady->id]
        )->assertStatus(422);
    }

    #[Test]
    public function a_created_session_alone_never_confirms_or_pays_anything(): void
    {
        $this->stubSession('cs_noop', 'https://checkout.stripe.test/cs_noop', 'pi_noop');

        [$offering, $plan] = $this->makeOffering(['capacity' => 5]);
        $registration = $this->registerThrough($offering, $plan);

        $this->postCheckout($registration)->assertOk();

        // THE trust boundary: a hosted URL, and a browser that returns from it,
        // prove nothing. Only a signature-verified webhook advances state.
        $fresh = $registration->fresh();
        $this->assertSame(Registration::STATUS_PENDING, $fresh->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $fresh->payment_status);
        $this->assertDatabaseCount('registration_payments', 0);
        $this->assertDatabaseCount('group_memberships', 0);
    }

    // ============================= helpers =============================

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    /** @return array{0: Offering, 1: FeePlan} */
    private function makeOffering(array $state = []): array
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create($state);

        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        return [$offering, $plan];
    }

    /**
     * An offering + a subscription-shaped plan + a pending registration on it.
     *
     * @param  \Illuminate\Database\Eloquent\Factories\Factory  $planFactory
     * @return array{0: Offering, 1: Registration}
     */
    private function subscriptionRegistration($planFactory): array
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();

        $plan = $planFactory->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        return [$offering, $this->registerThrough($offering, $plan)];
    }

    /** Register through the T-006b service (no HTTP, no Stripe). */
    private function registerThrough(Offering $offering, FeePlan $plan): Registration
    {
        return app(RegistrationService::class)->register(
            $offering,
            $plan,
            Contact::factory()->create(['masjid_id' => $this->masjid->id]),
            ['full_name' => 'Amal Yusuf']
        );
    }

    /**
     * Bind a partial RegistrationCheckoutService whose only mocked method is the
     * outbound Session create — everything else (guards, fees, snapshot,
     * persistence) runs for real, and the live API is never touched.
     */
    private function stubSession(string $id, string $url, ?string $paymentIntent): void
    {
        $service = Mockery::mock(RegistrationCheckoutService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('createCheckoutSession')
            ->andReturnUsing(function (array $params, string $account, string $key) use ($id, $url, $paymentIntent) {
                $this->captured = ['params' => $params, 'account' => $account, 'key' => $key];

                return ['id' => $id, 'url' => $url, 'payment_intent' => $paymentIntent];
            });

        $this->app->instance(RegistrationCheckoutService::class, $service);
    }

    private function postQuote(Offering $offering, array $body)
    {
        return $this->postJson(
            "/api/v1/offerings/{$offering->slug}/quote",
            $body,
            ['masjid-id' => (string) $this->masjid->id]
        );
    }

    private function postRegister(Offering $offering, array $body)
    {
        return $this->postJson(
            "/api/v1/offerings/{$offering->slug}/register",
            $body,
            ['masjid-id' => (string) $this->masjid->id]
        );
    }

    private function postCheckout(Registration $registration)
    {
        return $this->postJson(
            "/api/v1/registrations/{$registration->uuid}/checkout",
            [],
            ['masjid-id' => (string) $registration->masjid_id]
        );
    }
}
