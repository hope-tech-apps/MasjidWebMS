<?php

namespace Tests\Feature;

use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Models\RegistrationPayment;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The T-006a data layer's own invariants: the constant sets the request
 * boundaries will validate against (pinned so a rename shows up as a failing
 * test, not a silently-invalid Rule::in), the doc-exact spellings, defensive
 * defaults, snapshot/counter protections, and a smoke test over every factory
 * and its realistic states.
 *
 * Deliberately NO service behaviour here — capacity locking, pricing, webhooks
 * are T-006b..f. This file covers what the schema + models guarantee on their
 * own.
 */
class RegistrationBillingModelTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

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

        app(TenantContext::class)->forgetTenant();

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    // ---------------- constant sets (the validation boundary's authority)

    #[Test]
    public function the_constant_sets_match_the_ratified_design(): void
    {
        $this->assertSame(
            ['event', 'program', 'admission', 'membership', 'appointment'],
            Offering::KINDS
        );
        $this->assertSame(
            ['free', 'one_time', 'installment', 'recurring'],
            FeePlan::KINDS
        );
        $this->assertSame(
            ['pending', 'confirmed', 'waitlisted', 'cancelled'],
            Registration::STATUSES
        );
        $this->assertSame(
            ['none', 'awaiting', 'active', 'paid', 'past_due', 'canceled'],
            Registration::PAYMENT_STATUSES
        );
        $this->assertSame(
            ['aid', 'discount', 'code'],
            RegistrationAdjustment::KINDS
        );
        $this->assertSame(
            ['pending', 'succeeded', 'failed', 'refunded'],
            RegistrationPayment::STATUSES
        );
    }

    #[Test]
    public function the_two_cancel_spellings_stay_distinct_on_purpose(): void
    {
        // Seat: en-GB "cancelled" (ours). Money: en-US "canceled" (Stripe's
        // subscription vocabulary). The doc fixes both; a well-meaning
        // "consistency" cleanup must trip over this test.
        $this->assertSame('cancelled', Registration::STATUS_CANCELLED);
        $this->assertSame('canceled', Registration::PAYMENT_CANCELED);
    }

    #[Test]
    public function an_unrecognized_offering_kind_degrades_to_event(): void
    {
        $offering = new Offering(['kind' => 'banquet']);

        $this->assertSame(Offering::KIND_EVENT, $offering->kind());
    }

    #[Test]
    public function a_fee_plan_is_free_only_by_explicit_kind(): void
    {
        // Money kinds never degrade (no FeePlan::kind() helper on purpose —
        // guessing "free" would waive a fee). Only the exact constant is free.
        $this->assertTrue((new FeePlan(['kind' => FeePlan::KIND_FREE]))->isFree());
        $this->assertFalse((new FeePlan(['kind' => FeePlan::KIND_ONE_TIME]))->isFree());
        $this->assertFalse((new FeePlan(['kind' => 'gratis']))->isFree());

        $this->assertTrue((new FeePlan(['kind' => FeePlan::KIND_INSTALLMENT]))->isSubscriptionBased());
        $this->assertTrue((new FeePlan(['kind' => FeePlan::KIND_RECURRING]))->isSubscriptionBased());
        $this->assertFalse((new FeePlan(['kind' => FeePlan::KIND_ONE_TIME]))->isSubscriptionBased());
    }

    // ---------------- model-level defaults and protections

    #[Test]
    public function a_new_registration_defaults_to_pending_none_and_mints_a_uuid(): void
    {
        $registration = Registration::factory()->create([
            'masjid_id' => $this->masjid->id,
            'uuid' => null,
            'status' => Registration::STATUS_PENDING,
            'payment_status' => Registration::PAYMENT_NONE,
        ]);

        // The creating hook mints the opaque public handle when none is given.
        $this->assertNotEmpty($registration->uuid);
        $this->assertTrue($registration->isPending());
        $this->assertFalse($registration->isConfirmed());
    }

    #[Test]
    public function money_columns_round_trip_as_integers(): void
    {
        $registration = Registration::factory()->create([
            'masjid_id' => $this->masjid->id,
            'list_total_minor' => 15000,
            'adjusted_total_minor' => 12500,
        ])->fresh();

        $this->assertSame(15000, $registration->list_total_minor);
        $this->assertSame(12500, $registration->adjusted_total_minor);
    }

    #[Test]
    public function registration_count_cannot_be_mass_assigned(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();

        // An admin edit (mass assignment) must never rewrite the seat counter;
        // only T-006b's locked transaction may. Mirrors forms.response_count.
        $offering->update(['registration_count' => 999, 'name' => 'Renamed']);

        $this->assertSame(0, $offering->fresh()->registration_count);
        $this->assertSame('Renamed', $offering->fresh()->name);
    }

    #[Test]
    public function the_same_person_cannot_be_a_registrant_twice_on_one_registration(): void
    {
        $registrant = Registrant::factory()->create(['masjid_id' => $this->masjid->id]);

        $this->expectException(QueryException::class);

        // Neither column is nullable, so the DB unique dedupes exactly —
        // unlike group_memberships, no controller backstop is needed.
        Registrant::create([
            'masjid_id' => $registrant->masjid_id,
            'registration_id' => $registrant->registration_id,
            'contact_id' => $registrant->contact_id,
        ]);
    }

    // ---------------- factory smoke: every model, realistic states

    #[Test]
    public function every_factory_produces_a_persistable_row(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);
        $registration = Registration::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
            'fee_plan_id' => $plan->id,
        ]);
        $registrant = Registrant::factory()->create([
            'masjid_id' => $this->masjid->id,
            'registration_id' => $registration->id,
        ]);
        $adjustment = RegistrationAdjustment::factory()->create([
            'masjid_id' => $this->masjid->id,
            'registration_id' => $registration->id,
        ]);
        $payment = RegistrationPayment::factory()->create([
            'masjid_id' => $this->masjid->id,
            'registration_id' => $registration->id,
        ]);

        $this->assertSame($offering->id, $registration->offering->id);
        $this->assertSame($plan->id, $registration->feePlan->id);
        $this->assertSame($registration->id, $registrant->registration->id);
        $this->assertSame($this->masjid->id, $registrant->contact->masjid_id);
        $this->assertCount(1, $registration->adjustments);
        $this->assertCount(1, $registration->payments);
        $this->assertSame($adjustment->id, $registration->adjustments->first()->id);
        $this->assertSame($payment->id, $registration->payments->first()->id);
    }

    #[Test]
    public function the_free_state_is_the_synchronous_no_stripe_path(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();

        $registration = Registration::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $this->assertTrue($registration->feePlan->isFree());
        $this->assertSame(0, $registration->feePlan->amount_minor);
        $this->assertSame(0, $registration->adjusted_total_minor);
        $this->assertTrue($registration->isConfirmed());
        $this->assertSame(Registration::PAYMENT_NONE, $registration->payment_status);
        // No Stripe leg: nothing to expire, nothing to key.
        $this->assertNull($registration->checkout_expires_at);
        $this->assertNull($registration->idempotency_key);
    }

    #[Test]
    public function the_paid_state_holds_both_machines_in_their_terminal_good_states(): void
    {
        $registration = Registration::factory()->paid()->create([
            'masjid_id' => $this->masjid->id,
        ]);

        $this->assertTrue($registration->isConfirmed());
        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);
        $this->assertNotNull($registration->stripe_checkout_session_id);
    }

    #[Test]
    public function capacity_states_bound_the_counter_by_the_capacity(): void
    {
        $open = Offering::factory()->forMasjid($this->masjid)->withCapacity(10, 3)->create();
        $full = Offering::factory()->forMasjid($this->masjid)->atCapacity(5)->create();
        $unlimited = Offering::factory()->forMasjid($this->masjid)->create();

        $this->assertFalse($open->isAtCapacity());
        $this->assertSame(3, $open->registration_count);

        $this->assertTrue($full->isAtCapacity());
        $this->assertSame(5, $full->registration_count);

        // Null capacity is unlimited (forms convention).
        $this->assertNull($unlimited->capacity);
        $this->assertFalse($unlimited->isAtCapacity());
    }

    #[Test]
    public function fee_plan_states_carry_their_billing_shape(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $base = ['masjid_id' => $this->masjid->id, 'offering_id' => $offering->id];

        $installment = FeePlan::factory()->installment(9, 10000)->create($base);
        $recurring = FeePlan::factory()->recurring()->create($base);
        $oneTime = FeePlan::factory()->create($base);

        $this->assertSame(FeePlan::KIND_INSTALLMENT, $installment->kind);
        $this->assertSame(9, $installment->installment_count);
        $this->assertSame(FeePlan::INTERVAL_MONTH, $installment->billing_interval);
        $this->assertSame(10000, $installment->amount_minor);

        $this->assertSame(FeePlan::KIND_RECURRING, $recurring->kind);
        $this->assertNull($recurring->installment_count);
        $this->assertContains($recurring->billing_interval, FeePlan::BILLING_INTERVALS);

        $this->assertNull($oneTime->billing_interval);
        $this->assertNull($oneTime->installment_count);
    }

    #[Test]
    public function a_settled_payment_nets_amount_minus_stripe_fee(): void
    {
        $payment = RegistrationPayment::factory()->create([
            'masjid_id' => $this->masjid->id,
        ]);

        $this->assertTrue($payment->isSucceeded());
        $this->assertSame(
            $payment->amount_minor - $payment->stripe_fee_minor,
            $payment->net_minor
        );

        $refunded = RegistrationPayment::factory()->refunded()->create([
            'masjid_id' => $this->masjid->id,
        ]);

        $this->assertFalse($refunded->isSucceeded());
        $this->assertSame(RegistrationPayment::STATUS_REFUNDED, $refunded->status);
    }
}
