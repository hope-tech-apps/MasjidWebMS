<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Models\RegistrationPayment;
use App\Models\User;
use App\Services\Stripe\RegistrationCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-006d — the roster and the three explicit admin actions over HTTP:
 * /api/admin/masjids/{masjid_id}/offerings/{offering_id}/registrations.
 *
 * What this suite is really pinning is that the CONTROLLER OWNS NOTHING. Every
 * rule it appears to enforce is RegistrationService's, reached through the
 * endpoint and surfaced as a clean 422:
 *
 *   - aid is floored at zero and REFUSED once a Stripe leg exists;
 *   - promotion is manual, waitlist-only, and can never oversell capacity;
 *   - cancelling releases the seat through the one seat-release seam, and
 *     cancels the Stripe subscription when one exists — proven with a test
 *     double on the Stripe seam, because subscription creation itself is
 *     T-006e and there is nothing live to talk to.
 *
 * Cross-tenant coverage matches every other CRM suite: B's masjid in the route
 * is a 403, B's ids under A's route are a 404.
 */
class RegistrationAdminTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

    private User $adminA;

    private Offering $offeringA;

    private Offering $offeringB;

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

        config(['services.stripe.registration_checkout_window_minutes' => 30]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $this->masjidB = $this->makeMasjid(['stripe_account_id' => 'acct_B', 'stripe_charges_enabled' => true]);

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->offeringA = Offering::factory()->forMasjid($this->masjidA)
            ->withCapacity(5)->create(['name' => 'Weekend School 2026']);
        $this->offeringB = Offering::factory()->forMasjid($this->masjidB)
            ->withCapacity(5)->create(['name' => 'Other Org Program']);
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

    private function rosterUrl(?Offering $offering = null, ?Masjid $masjid = null): string
    {
        $offering ??= $this->offeringA;

        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id
            . '/offerings/' . $offering->id . '/registrations';
    }

    /** A registration on A's offering, defaulting to the paid-pending state. */
    private function makeRegistration(array $overrides = [], ?Offering $offering = null, ?Masjid $masjid = null): Registration
    {
        $offering ??= $this->offeringA;
        $masjid ??= $this->masjidA;

        return Registration::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
            'offering_id' => $offering->id,
        ], $overrides));
    }

    private function makeContact(Masjid $masjid, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge(['masjid_id' => $masjid->id], $overrides));
    }

    // ---------- auth + isolation ----------

    #[Test]
    public function the_roster_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->rosterUrl())->assertStatus(401);
    }

    #[Test]
    public function another_organizations_offering_has_no_reachable_roster(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->rosterUrl($this->offeringB))->assertStatus(404);
    }

    #[Test]
    public function an_admin_cannot_target_another_masjid_in_the_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->rosterUrl($this->offeringB, $this->masjidB))->assertStatus(403);
    }

    #[Test]
    public function another_organizations_registration_is_a_404_under_our_own_offering(): void
    {
        $foreign = $this->makeRegistration([], $this->offeringB, $this->masjidB);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->rosterUrl() . '/' . $foreign->id)->assertStatus(404);
        $this->postJson($this->rosterUrl() . '/' . $foreign->id . '/cancel')->assertStatus(404);
        $this->postJson($this->rosterUrl() . '/' . $foreign->id . '/promote')->assertStatus(404);
        $this->postJson($this->rosterUrl() . '/' . $foreign->id . '/adjustments', [
            'kind' => RegistrationAdjustment::KIND_AID,
            'amount_minor' => 1000,
        ])->assertStatus(404);

        $this->assertDatabaseHas('registrations', [
            'id' => $foreign->id,
            'status' => Registration::STATUS_PENDING,
        ]);
    }

    // ---------- roster ----------

    #[Test]
    public function the_roster_lists_this_offerings_registrations_and_filters_by_status(): void
    {
        $this->makeRegistration();
        $this->makeRegistration(['status' => Registration::STATUS_CONFIRMED, 'payment_status' => Registration::PAYMENT_PAID]);
        $this->makeRegistration(['status' => Registration::STATUS_WAITLISTED, 'payment_status' => Registration::PAYMENT_NONE]);
        // Belongs to another offering of the SAME organization — still excluded.
        $other = Offering::factory()->forMasjid($this->masjidA)->create();
        $this->makeRegistration([], $other);

        Sanctum::actingAs($this->adminA);

        $this->assertSame(3, $this->getJson($this->rosterUrl())->assertOk()->json('data.total'));

        $this->assertSame(1, $this->getJson($this->rosterUrl() . '?status=' . Registration::STATUS_WAITLISTED)
            ->assertOk()->json('data.total'));

        $this->assertSame(1, $this->getJson($this->rosterUrl() . '?payment_status=' . Registration::PAYMENT_PAID)
            ->assertOk()->json('data.total'));
    }

    #[Test]
    public function the_roster_can_be_searched_by_the_payer(): void
    {
        $payer = $this->makeContact($this->masjidA, ['first_name' => 'Amal', 'last_name' => 'Yusuf']);
        $this->makeRegistration(['contact_id' => $payer->id]);
        $this->makeRegistration();

        Sanctum::actingAs($this->adminA);

        $this->assertSame(1, $this->getJson($this->rosterUrl() . '?search=Amal')->assertOk()->json('data.total'));
    }

    #[Test]
    public function the_registrant_view_lists_people_rather_than_signups(): void
    {
        $guardian = $this->makeContact($this->masjidA, ['first_name' => 'Parent']);
        $registration = $this->makeRegistration(['contact_id' => $guardian->id]);

        // One household, three children: one registration, three registrants.
        foreach (['Zayd', 'Maryam', 'Ali'] as $name) {
            Registrant::create([
                'masjid_id' => $this->masjidA->id,
                'registration_id' => $registration->id,
                'contact_id' => $this->makeContact($this->masjidA, ['first_name' => $name])->id,
            ]);
        }

        Sanctum::actingAs($this->adminA);

        $this->assertSame(1, $this->getJson($this->rosterUrl())->assertOk()->json('data.total'));
        $this->assertSame(3, $this->getJson($this->rosterUrl() . '?view=registrants')->assertOk()->json('data.total'));
    }

    #[Test]
    public function showing_a_registration_returns_its_adjustments_and_payments(): void
    {
        $registration = $this->makeRegistration();

        RegistrationAdjustment::create([
            'masjid_id' => $this->masjidA->id,
            'registration_id' => $registration->id,
            'kind' => RegistrationAdjustment::KIND_AID,
            'amount_minor' => 2500,
            'reason' => 'Need-based',
        ]);

        RegistrationPayment::create([
            'masjid_id' => $this->masjidA->id,
            'registration_id' => $registration->id,
            'amount_minor' => 12500,
            'status' => RegistrationPayment::STATUS_SUCCEEDED,
        ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->rosterUrl() . '/' . $registration->id)->assertOk();

        $this->assertCount(1, $response->json('data.adjustments'));
        $this->assertCount(1, $response->json('data.payments'));
    }

    // ---------- adjustments ----------

    #[Test]
    public function granting_aid_reduces_the_snapshot_and_records_who_granted_it(): void
    {
        $registration = $this->makeRegistration();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/adjustments', [
            'kind' => RegistrationAdjustment::KIND_AID,
            'amount_minor' => 5000,
            'reason' => 'Need-based aid',
        ])->assertStatus(201)
            ->assertJsonPath('data.registration.adjusted_total_minor', 10000);

        $this->assertDatabaseHas('registration_adjustments', [
            'registration_id' => $registration->id,
            'amount_minor' => 5000,
            // The audit trail's "who" is the authenticated admin, never input.
            'granted_by_user_id' => $this->adminA->id,
        ]);

        // list_total_minor is the untouched snapshot; only `adjusted` moves.
        $this->assertSame(15000, (int) $registration->fresh()->list_total_minor);
    }

    #[Test]
    public function a_full_waiver_confirms_the_seat_with_no_stripe_leg(): void
    {
        $registration = $this->makeRegistration();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/adjustments', [
            'kind' => RegistrationAdjustment::KIND_AID,
            'amount_minor' => 15000,
        ])->assertStatus(201);

        $fresh = $registration->fresh();

        // The free-path carve-out: never a $0 session — the payment leg is
        // dismantled and the held seat confirms in-request.
        $this->assertSame(0, (int) $fresh->adjusted_total_minor);
        $this->assertSame(Registration::STATUS_CONFIRMED, $fresh->status);
        $this->assertSame(Registration::PAYMENT_NONE, $fresh->payment_status);
        $this->assertNull($fresh->idempotency_key);
    }

    #[Test]
    public function aid_is_refused_once_a_stripe_leg_exists(): void
    {
        $registration = $this->makeRegistration([
            'stripe_checkout_session_id' => 'cs_test_already_open',
        ]);

        Sanctum::actingAs($this->adminA);

        // Strictly pre-checkout: there is no post-hoc money movement in this
        // design, and the controller has no path around the service's guard.
        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/adjustments', [
            'kind' => RegistrationAdjustment::KIND_AID,
            'amount_minor' => 5000,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertDatabaseCount('registration_adjustments', 0);
        $this->assertSame(15000, (int) $registration->fresh()->adjusted_total_minor);
    }

    #[Test]
    public function aid_is_refused_on_a_settled_registration(): void
    {
        $registration = $this->makeRegistration([
            'status' => Registration::STATUS_CONFIRMED,
            'payment_status' => Registration::PAYMENT_PAID,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/adjustments', [
            'kind' => RegistrationAdjustment::KIND_AID,
            'amount_minor' => 5000,
        ])->assertStatus(422);
    }

    #[Test]
    public function an_adjustment_can_never_be_a_surcharge(): void
    {
        $registration = $this->makeRegistration();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/adjustments', [
            'kind' => RegistrationAdjustment::KIND_AID,
            'amount_minor' => -5000,
        ])->assertStatus(422);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/adjustments', [
            'kind' => 'bursary',
            'amount_minor' => 500,
        ])->assertStatus(422);

        $this->assertDatabaseCount('registration_adjustments', 0);
    }

    #[Test]
    public function aid_beyond_the_total_floors_at_zero(): void
    {
        $registration = $this->makeRegistration();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/adjustments', [
            'kind' => RegistrationAdjustment::KIND_DISCOUNT,
            'amount_minor' => 99999,
        ])->assertStatus(201);

        // The engine cannot owe anybody money.
        $this->assertSame(0, (int) $registration->fresh()->adjusted_total_minor);
    }

    // ---------- waitlist promotion ----------

    #[Test]
    public function promotion_moves_a_waitlisted_registration_into_a_held_seat(): void
    {
        $registration = $this->makeRegistration([
            'status' => Registration::STATUS_WAITLISTED,
            'payment_status' => Registration::PAYMENT_NONE,
            'checkout_expires_at' => null,
            'idempotency_key' => null,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/promote')
            ->assertOk()
            ->assertJsonPath('data.status', Registration::STATUS_PENDING)
            ->assertJsonPath('data.payment_status', Registration::PAYMENT_AWAITING);

        $fresh = $registration->fresh();

        // A paid promotion lands exactly where intake would have put it: a held
        // seat with a fresh checkout window and key to pay against.
        $this->assertNotNull($fresh->checkout_expires_at);
        $this->assertNotNull($fresh->idempotency_key);
        $this->assertSame(1, $this->offeringA->fresh()->registration_count);
    }

    #[Test]
    public function promoting_a_free_registration_confirms_it_outright(): void
    {
        $registration = $this->makeRegistration([
            'status' => Registration::STATUS_WAITLISTED,
            'payment_status' => Registration::PAYMENT_NONE,
            'list_total_minor' => 0,
            'adjusted_total_minor' => 0,
            'checkout_expires_at' => null,
            'idempotency_key' => null,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/promote')->assertOk();

        $fresh = $registration->fresh();
        $this->assertSame(Registration::STATUS_CONFIRMED, $fresh->status);
        $this->assertSame(Registration::PAYMENT_NONE, $fresh->payment_status);
        $this->assertNull($fresh->idempotency_key);
        $this->assertSame(1, $this->offeringA->fresh()->registration_count);
    }

    #[Test]
    public function promotion_can_never_oversell_a_full_offering(): void
    {
        $full = Offering::factory()->forMasjid($this->masjidA)->atCapacity(1)->create();
        $registration = $this->makeRegistration([
            'status' => Registration::STATUS_WAITLISTED,
            'payment_status' => Registration::PAYMENT_NONE,
        ], $full);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl($full) . '/' . $registration->id . '/promote')
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertSame(Registration::STATUS_WAITLISTED, $registration->fresh()->status);
        $this->assertSame(1, $full->fresh()->registration_count);
    }

    #[Test]
    public function only_a_waitlisted_registration_can_be_promoted(): void
    {
        $registration = $this->makeRegistration();   // pending, holds a seat already

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/promote')->assertStatus(422);

        $this->assertSame(0, $this->offeringA->fresh()->registration_count);
    }

    // ---------- cancel ----------

    #[Test]
    public function cancelling_a_confirmed_registration_releases_its_seat(): void
    {
        $this->offeringA->increment('registration_count');
        $registration = $this->makeRegistration([
            'status' => Registration::STATUS_CONFIRMED,
            'payment_status' => Registration::PAYMENT_PAID,
            'checkout_expires_at' => null,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', Registration::STATUS_CANCELLED);

        $this->assertSame(0, $this->offeringA->fresh()->registration_count);

        // The two state machines stay independent: a SETTLED charge is still
        // `paid`. v1 refunds are the org's own action in its Stripe dashboard,
        // and restating a settled ledger row would be a lie.
        $this->assertSame(Registration::PAYMENT_PAID, $registration->fresh()->payment_status);
    }

    #[Test]
    public function cancelling_a_pending_registration_releases_its_seat_and_the_money_leg(): void
    {
        $this->offeringA->increment('registration_count');
        $registration = $this->makeRegistration();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/cancel')->assertOk();

        $fresh = $registration->fresh();
        $this->assertSame(Registration::STATUS_CANCELLED, $fresh->status);
        $this->assertSame(Registration::PAYMENT_CANCELED, $fresh->payment_status);
        $this->assertNull($fresh->checkout_expires_at);
        $this->assertSame(0, $this->offeringA->fresh()->registration_count);
    }

    #[Test]
    public function cancelling_a_waitlisted_registration_returns_no_seat(): void
    {
        $this->offeringA->increment('registration_count');   // held by somebody else
        $registration = $this->makeRegistration([
            'status' => Registration::STATUS_WAITLISTED,
            'payment_status' => Registration::PAYMENT_NONE,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/cancel')->assertOk();

        $this->assertSame(Registration::STATUS_CANCELLED, $registration->fresh()->status);
        // A waitlisted row never held a seat — decrementing would hand out one
        // that was never taken.
        $this->assertSame(1, $this->offeringA->fresh()->registration_count);
    }

    #[Test]
    public function cancelling_is_idempotent(): void
    {
        $this->offeringA->increment('registration_count');
        $registration = $this->makeRegistration();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/cancel')->assertOk();
        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/cancel')->assertOk();

        // The counter is decremented exactly once.
        $this->assertSame(0, $this->offeringA->fresh()->registration_count);
    }

    #[Test]
    public function cancelling_also_cancels_the_stripe_subscription_when_one_exists(): void
    {
        // Subscription legs are created in T-006e; the branch is proven here
        // with a double on the ONLY method that touches the live API, so the
        // seat can never be cancelled while a subscription keeps charging.
        $service = Mockery::mock(RegistrationCheckoutService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('cancelStripeSubscription')
            ->once()
            // Direct-charge model: cancelled ON the org's connected account.
            ->with('sub_test_123', 'acct_A');
        $this->app->instance(RegistrationCheckoutService::class, $service);

        $this->offeringA->increment('registration_count');
        $registration = $this->makeRegistration([
            'status' => Registration::STATUS_CONFIRMED,
            'payment_status' => Registration::PAYMENT_ACTIVE,
            'stripe_subscription_id' => 'sub_test_123',
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/cancel')
            ->assertOk()
            ->assertJsonPath('meta.stripe_subscription_cancelled', true);

        $fresh = $registration->fresh();
        $this->assertSame(Registration::STATUS_CANCELLED, $fresh->status);
        $this->assertSame(Registration::PAYMENT_CANCELED, $fresh->payment_status);
        $this->assertSame(0, $this->offeringA->fresh()->registration_count);
    }

    #[Test]
    public function cancelling_without_a_subscription_never_calls_stripe(): void
    {
        $service = Mockery::mock(RegistrationCheckoutService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldNotReceive('cancelStripeSubscription');
        $this->app->instance(RegistrationCheckoutService::class, $service);

        $registration = $this->makeRegistration();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/cancel')
            ->assertOk()
            ->assertJsonPath('meta.stripe_subscription_cancelled', false);
    }

    #[Test]
    public function a_stripe_failure_does_not_block_the_local_cancel(): void
    {
        // An outage at Stripe must not leave an admin unable to free a seat;
        // the failure is logged and the local cancel proceeds.
        $service = Mockery::mock(RegistrationCheckoutService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('cancelStripeSubscription')
            ->once()
            ->andThrow(new \RuntimeException('stripe is down'));
        $this->app->instance(RegistrationCheckoutService::class, $service);

        $this->offeringA->increment('registration_count');
        $registration = $this->makeRegistration([
            'status' => Registration::STATUS_CONFIRMED,
            'payment_status' => Registration::PAYMENT_ACTIVE,
            'stripe_subscription_id' => 'sub_test_down',
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->rosterUrl() . '/' . $registration->id . '/cancel')
            ->assertOk()
            ->assertJsonPath('meta.stripe_subscription_cancelled', false);

        $this->assertSame(Registration::STATUS_CANCELLED, $registration->fresh()->status);
        $this->assertSame(0, $this->offeringA->fresh()->registration_count);
    }
}
