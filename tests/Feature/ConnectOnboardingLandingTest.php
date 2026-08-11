<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Public Stripe Connect onboarding landings.
 *
 * Regression cover for the 2026-08-10 incident: Stripe's Account Link pointed
 * `return_url` at an `auth:sanctum` route, so the org admin's browser — which
 * carries no token — was shown `{"status":"error","message":"Request failed."}`
 * and abandoned onboarding with requirements still past due.
 *
 * The Stripe seam (`retrieveAccount`) is mocked throughout; per
 * `.claude/rules/stripe-payments.md` tests must never hit the live API.
 */
class ConnectOnboardingLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml (mirrors DonationFlowTest).
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    // ===================== the public return landing =====================

    #[Test]
    public function return_landing_is_public_and_reports_pending_when_charges_are_disabled(): void
    {
        $masjid = $this->makeMasjid([
            'stripe_account_id' => 'acct_pending',
            'stripe_charges_enabled' => false,
        ]);

        $this->stubStripeAccount(['charges_enabled' => false, 'payouts_enabled' => false]);

        // No Sanctum token — exactly how Stripe redirects the admin's browser.
        $response = $this->get("/connect/{$masjid->id}/return");

        $response->assertOk();
        $response->assertSee('Stripe is still reviewing', false);
        $response->assertSee($masjid->name);
        // The old failure mode was a raw JSON error envelope.
        $response->assertDontSee('Request failed.');
    }

    #[Test]
    public function return_landing_reports_connected_once_stripe_enables_charges(): void
    {
        $masjid = $this->makeMasjid([
            'stripe_account_id' => 'acct_live',
            'stripe_charges_enabled' => false,
        ]);

        $this->stubStripeAccount(['charges_enabled' => true, 'payouts_enabled' => true]);

        $response = $this->get("/connect/{$masjid->id}/return");

        $response->assertOk();
        $response->assertSee('You are connected to Stripe', false);

        // The convenience refresh persisted Stripe's answer.
        $this->assertTrue((bool) $masjid->fresh()->stripe_charges_enabled);
        $this->assertTrue((bool) $masjid->fresh()->stripe_payouts_enabled);
    }

    #[Test]
    public function return_landing_never_exposes_the_connected_account_id(): void
    {
        $masjid = $this->makeMasjid([
            'stripe_account_id' => 'acct_secret_identifier',
            'stripe_charges_enabled' => true,
        ]);

        $this->stubStripeAccount(['charges_enabled' => true, 'payouts_enabled' => true]);

        $response = $this->get("/connect/{$masjid->id}/return");

        $response->assertOk();
        $response->assertDontSee('acct_secret_identifier');
    }

    #[Test]
    public function return_landing_still_renders_when_stripe_is_unreachable(): void
    {
        $masjid = $this->makeMasjid([
            'stripe_account_id' => 'acct_flaky',
            'stripe_charges_enabled' => true,
        ]);

        $service = Mockery::mock(StripeConnectService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('retrieveAccount')->andThrow(new \RuntimeException('Stripe down'));
        $this->app->instance(StripeConnectService::class, $service);

        $response = $this->get("/connect/{$masjid->id}/return");

        // Falls back to stored flags rather than showing an error after the org
        // just finished onboarding; the webhook reconciles either way.
        $response->assertOk();
        $response->assertSee('You are connected to Stripe', false);
    }

    // ===================== the public refresh landing =====================

    #[Test]
    public function refresh_landing_explains_expiry_and_never_mints_a_new_link(): void
    {
        $masjid = $this->makeMasjid(['stripe_account_id' => 'acct_expired']);

        // Minting from an unauthenticated route would let anyone onboard any
        // masjid with their own bank account as the payout destination.
        $service = Mockery::mock(StripeConnectService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldNotReceive('createOnboardingLink');
        $service->shouldNotReceive('createAccountLink');
        $service->shouldNotReceive('ensureConnectedAccount');
        $this->app->instance(StripeConnectService::class, $service);

        $response = $this->get("/connect/{$masjid->id}/refresh");

        $response->assertOk();
        $response->assertSee('This onboarding link expired', false);
        $response->assertDontSee('acct_expired');
    }

    // ===================== edge cases =====================

    #[Test]
    public function unknown_masjid_renders_a_friendly_page_not_a_500(): void
    {
        $response = $this->get('/connect/999999/return');

        $response->assertOk();
        $response->assertSee('find that organization', false);
    }

    #[Test]
    public function non_numeric_masjid_id_does_not_reach_the_landing_controller(): void
    {
        // whereNumber guards the landing routes; anything else is SPA territory.
        // Asserted at the router rather than over HTTP: rendering the SPA blade
        // needs a Vite manifest, which a source-only checkout has no reason to
        // have — that would couple this test to the frontend build.
        $matched = app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create('/connect/not-a-number/return', 'GET')
        );

        $this->assertStringNotContainsString(
            'ConnectOnboardingLandingController',
            $matched->getActionName()
        );
    }

    // ===================== the authed JSON endpoint =====================

    #[Test]
    public function admin_status_endpoint_still_requires_authentication(): void
    {
        $masjid = $this->makeMasjid(['stripe_account_id' => 'acct_admin']);

        $response = $this->getJson("/api/admin/masjids/{$masjid->id}/connect/status");

        $response->assertUnauthorized();
    }

    // ===================== helpers =====================

    /**
     * Bind a partial StripeConnectService whose only mocked method is the
     * outbound account retrieve.
     */
    private function stubStripeAccount(array $account): void
    {
        $service = Mockery::mock(StripeConnectService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('retrieveAccount')->andReturnUsing(
            fn (string $id) => array_merge(['id' => $id], $account)
        );
        $this->app->instance(StripeConnectService::class, $service);
    }

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
            'crm_enabled' => true,
        ], $overrides));
    }
}
