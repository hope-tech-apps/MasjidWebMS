<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\FormResponse;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Services\Registrations\RegistrationException;
use App\Services\Registrations\RegistrationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-006b — RegistrationService: the intake transaction, the capacity lock,
 * snapshot pricing, adjustments, the free-path synchronous confirmation, and
 * the group/guardian writes. Free offerings ship end-to-end here; every
 * Stripe leg (sessions, webhooks) is T-006c/e and nothing in this file may
 * touch the live API — the service makes no Stripe calls at all.
 *
 * True parallelism (N concurrent last-seat attempts) is T-006f's concurrency
 * test; here the boundary is proven with sequential transactions, which is
 * what sqlite can express.
 */
class RegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private Masjid $otherMasjid;

    private RegistrationService $service;

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

        // The public register path runs UNBOUND — the service must stamp
        // masjid_id explicitly on everything it writes.
        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid();
        $this->otherMasjid = $this->makeMasjid();

        $this->service = app(RegistrationService::class);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
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

    /** @return array{0: Offering, 1: FeePlan} */
    private function makeOffering(array $offeringState = [], ?callable $planFactory = null): array
    {
        $factory = Offering::factory()->forMasjid($this->masjid);

        $offering = $factory->create($offeringState);

        $plan = ($planFactory ?: fn ($f) => $f)(FeePlan::factory())->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        return [$offering, $plan];
    }

    private function contact(?Masjid $masjid = null): Contact
    {
        return Contact::factory()->create([
            'masjid_id' => ($masjid ?: $this->masjid)->id,
        ]);
    }

    // ------------------------------------------------ free path, end to end

    #[Test]
    public function a_free_registration_confirms_synchronously_with_roster_form_response_and_group_writes(): void
    {
        $group = Group::factory()->create(['masjid_id' => $this->masjid->id]);

        $offering = Offering::factory()
            ->forMasjid($this->masjid)
            ->withRoster($group)
            ->withCapacity(10)
            ->create();
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $guardian = $this->contact();
        $childA = $this->contact();
        $childB = $this->contact();

        $registration = $this->service->register(
            $offering,
            $plan,
            $guardian,
            ['full_name' => 'Amal Yusuf'],
            [$childA, $childB]
        );

        // Confirmed synchronously — the declared carve-out: no payment leg,
        // no checkout window, no idempotency key. Nothing Stripe exists.
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_NONE, $registration->payment_status);
        $this->assertSame(0, $registration->list_total_minor);
        $this->assertSame(0, $registration->adjusted_total_minor);
        $this->assertNull($registration->checkout_expires_at);
        $this->assertNull($registration->idempotency_key);
        $this->assertNotEmpty($registration->uuid);

        // The seat is held: confirmed reserves exactly one.
        $this->assertSame(1, $offering->fresh()->registration_count);

        // Intake persisted as a NORMAL form_response, linked to the registration.
        $response = FormResponse::find($registration->form_response_id);
        $this->assertNotNull($response);
        $this->assertSame($offering->intake_form_id, $response->form_id);
        $this->assertSame($this->masjid->id, $response->masjid_id);
        $this->assertSame('Amal Yusuf', $response->data['full_name']);

        // Roster rows: one registrant per child, contacts-first.
        $this->assertEqualsCanonicalizing(
            [$childA->id, $childB->id],
            Registrant::where('registration_id', $registration->id)->pluck('contact_id')->all()
        );

        // Group writes: each child a participant `member` row...
        foreach ([$childA, $childB] as $child) {
            $this->assertTrue(
                GroupMembership::where('group_id', $group->id)
                    ->where('contact_id', $child->id)
                    ->where('role', GroupMembership::ROLE_MEMBER)
                    ->exists()
            );
        }

        // ...and the payer one explicit (guardian, ward, group) edge per child.
        foreach ([$childA, $childB] as $child) {
            $this->assertTrue(
                GroupMembership::where('group_id', $group->id)
                    ->where('contact_id', $guardian->id)
                    ->where('role', GroupMembership::ROLE_GUARDIAN)
                    ->where('guardian_of_contact_id', $child->id)
                    ->exists()
            );
        }

        $this->assertSame(4, GroupMembership::where('group_id', $group->id)->count());

        // A guardian edge records a relationship, NEVER consent.
        $this->assertSame(0, GroupMembership::where('group_id', $group->id)
            ->whereNotNull('consent_granted_at')->count());

        // Idempotent confirmation seam: re-confirming duplicates nothing.
        $this->service->confirm($registration->fresh());
        $this->assertSame(4, GroupMembership::where('group_id', $group->id)->count());
    }

    #[Test]
    public function a_free_self_registration_defaults_the_payer_as_sole_registrant_with_no_guardian_edge(): void
    {
        $group = Group::factory()->create(['masjid_id' => $this->masjid->id]);

        $offering = Offering::factory()->forMasjid($this->masjid)->withRoster($group)->create();
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $payer = $this->contact();

        $registration = $this->service->register($offering, $plan, $payer, ['full_name' => 'Self Registrant']);

        $this->assertSame(
            [$payer->id],
            Registrant::where('registration_id', $registration->id)->pluck('contact_id')->all()
        );

        // One participant row for the payer; nobody is their own guardian.
        $this->assertSame(1, GroupMembership::where('group_id', $group->id)->count());
        $this->assertSame(0, GroupMembership::where('group_id', $group->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)->count());
    }

    #[Test]
    public function a_free_registration_without_a_group_confirms_and_writes_no_memberships(): void
    {
        [$offering, ] = $this->makeOffering();
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'No Group']);

        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(0, GroupMembership::count());
    }

    // ------------------------------------------------------- paid pendings

    #[Test]
    public function a_paid_registration_reserves_at_pending_with_the_checkout_window_open(): void
    {
        [$offering, $plan] = $this->makeOffering(['capacity' => 5]);

        $registration = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'Payer']);

        // Reserve-at-pending: the seat is held while payment is outstanding...
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertSame(1, $offering->fresh()->registration_count);

        // ...bounded by checkout_expires_at so the T-006c/f reaper contract
        // holds from day one, with the Checkout idempotency key minted.
        $this->assertNotNull($registration->checkout_expires_at);
        $this->assertTrue($registration->checkout_expires_at->isFuture());
        $this->assertNotNull($registration->idempotency_key);
        $this->assertStringStartsWith('reg_checkout_', $registration->idempotency_key);

        // Snapshot totals from the plan, integer minor units.
        $this->assertSame(15000, $registration->list_total_minor);
        $this->assertSame(15000, $registration->adjusted_total_minor);

        // No Stripe object exists yet — sessions are T-006c's.
        $this->assertNull($registration->stripe_checkout_session_id);
    }

    #[Test]
    public function an_installment_plan_snapshots_the_full_commitment(): void
    {
        [$offering, $plan] = $this->makeOffering([], fn ($f) => $f->installment(9, 10000));

        $registration = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'Installments']);

        // 9 × $100.00: adjusted_total is the charged amount, always — and an
        // installment plan charges this much across its schedule.
        $this->assertSame(90000, $registration->list_total_minor);
        $this->assertSame(90000, $registration->adjusted_total_minor);
    }

    // -------------------------------------------------- capacity boundary

    #[Test]
    public function the_last_seat_goes_to_exactly_one_registration_and_the_next_is_waitlisted(): void
    {
        [$offering, $plan] = $this->makeOffering(['capacity' => 2, 'registration_count' => 1]);

        $winner = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'Winner']);
        $loser = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'Loser']);

        // Exactly one holds the last seat; the other reserves NOTHING.
        $this->assertSame(Registration::STATUS_PENDING, $winner->status);
        $this->assertSame(Registration::STATUS_WAITLISTED, $loser->status);

        // Capacity may NEVER be exceeded.
        $this->assertSame(2, $offering->fresh()->registration_count);

        // A waitlisted registration has no money in flight and no checkout
        // window — nobody pays for a seat they don't hold.
        $this->assertSame(Registration::PAYMENT_NONE, $loser->payment_status);
        $this->assertNull($loser->checkout_expires_at);
        $this->assertNull($loser->idempotency_key);

        // Its snapshot still exists (promotion in T-006d charges this).
        $this->assertSame(15000, $loser->list_total_minor);

        // A third attempt waitlists too; the counter still never moves.
        $third = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'Third']);
        $this->assertSame(Registration::STATUS_WAITLISTED, $third->status);
        $this->assertSame(2, $offering->fresh()->registration_count);
    }

    #[Test]
    public function a_free_registration_on_a_full_offering_waitlists_and_writes_no_roster(): void
    {
        $group = Group::factory()->create(['masjid_id' => $this->masjid->id]);

        $offering = Offering::factory()
            ->forMasjid($this->masjid)
            ->withRoster($group)
            ->atCapacity(3)
            ->create();
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'Late']);

        // Free never jumps the queue: no seat means no confirmation and no
        // group materialisation.
        $this->assertSame(Registration::STATUS_WAITLISTED, $registration->status);
        $this->assertSame(3, $offering->fresh()->registration_count);
        $this->assertSame(0, GroupMembership::count());

        // And a waitlisted registration cannot be confirmed by the seam —
        // promotion is T-006d's explicit admin action.
        $this->expectException(RegistrationException::class);
        $this->service->confirm($registration);
    }

    #[Test]
    public function a_closed_or_inactive_offering_refuses_intake(): void
    {
        [$closed, $closedPlan] = $this->makeOffering([
            'opens_at' => now()->subMonths(2),
            'closes_at' => now()->subMonth(),
        ]);

        try {
            $this->service->register($closed, $closedPlan, $this->contact(), ['full_name' => 'Too Late']);
            $this->fail('Expected RegistrationException for a closed offering.');
        } catch (RegistrationException $e) {
            // expected
        }

        [$inactive, $inactivePlan] = $this->makeOffering(['is_active' => false]);

        try {
            $this->service->register($inactive, $inactivePlan, $this->contact(), ['full_name' => 'Inactive']);
            $this->fail('Expected RegistrationException for an inactive offering.');
        } catch (RegistrationException $e) {
            // expected
        }

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_deactivated_fee_plan_is_not_purchasable(): void
    {
        [$offering, ] = $this->makeOffering();
        $replaced = FeePlan::factory()->inactive()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $this->expectException(RegistrationException::class);

        $this->service->register($offering, $replaced, $this->contact(), ['full_name' => 'Stale Tab']);
    }

    // ---------------------------------------------------------- adjustments

    #[Test]
    public function adjustments_reduce_the_snapshot_floor_at_zero_and_a_full_waiver_branches_to_the_free_path(): void
    {
        $group = Group::factory()->create(['masjid_id' => $this->masjid->id]);

        $offering = Offering::factory()->forMasjid($this->masjid)->withRoster($group)->create();
        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $payer = $this->contact();
        $child = $this->contact();

        $registration = $this->service->register(
            $offering, $plan, $payer, ['full_name' => 'Aid Case'], [$child]
        );

        // Partial aid: adjusted drops, the payment leg stays intact.
        $this->service->grantAdjustment($registration, RegistrationAdjustment::KIND_AID, 5000, 'Partial aid');

        $this->assertSame(15000, $registration->list_total_minor);
        $this->assertSame(10000, $registration->adjusted_total_minor);
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertNotNull($registration->idempotency_key);

        // Over-granting floors at 0 — never a negative, never a surcharge —
        // and a 0 total is the free-path carve-out: the payment leg is
        // dismantled and the pending seat confirms synchronously.
        $this->service->grantAdjustment($registration, RegistrationAdjustment::KIND_AID, 20000, 'Full waiver');

        $this->assertSame(0, $registration->adjusted_total_minor);
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_NONE, $registration->payment_status);
        $this->assertNull($registration->checkout_expires_at);
        $this->assertNull($registration->idempotency_key);

        // Confirmation materialised the roster: child member + guardian edge.
        $this->assertTrue(
            GroupMembership::where('group_id', $group->id)
                ->where('contact_id', $child->id)
                ->where('role', GroupMembership::ROLE_MEMBER)
                ->exists()
        );
        $this->assertTrue(
            GroupMembership::where('group_id', $group->id)
                ->where('contact_id', $payer->id)
                ->where('role', GroupMembership::ROLE_GUARDIAN)
                ->where('guardian_of_contact_id', $child->id)
                ->exists()
        );

        // Both grants are auditable rows.
        $this->assertSame(2, RegistrationAdjustment::where('registration_id', $registration->id)->count());
    }

    #[Test]
    public function an_adjustment_can_never_be_negative(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $registration = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'P']);

        $this->expectException(RegistrationException::class);

        $this->service->grantAdjustment($registration, RegistrationAdjustment::KIND_DISCOUNT, -100, 'Sneaky surcharge');
    }

    #[Test]
    public function an_unknown_adjustment_kind_is_refused(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $registration = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'P']);

        $this->expectException(RegistrationException::class);

        $this->service->grantAdjustment($registration, 'rebate', 100, 'Not a kind');
    }

    #[Test]
    public function adjustments_are_strictly_pre_checkout(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $registration = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'P']);

        // T-006c mints the session; from then on there is no post-hoc money
        // movement, ever.
        $registration->forceFill(['stripe_checkout_session_id' => 'cs_test_abc123'])->save();

        try {
            $this->service->grantAdjustment($registration, RegistrationAdjustment::KIND_AID, 5000, 'Too late');
            $this->fail('Expected RegistrationException after checkout.');
        } catch (RegistrationException $e) {
            // expected
        }

        $this->assertSame(0, RegistrationAdjustment::count());
        $this->assertSame(15000, $registration->fresh()->adjusted_total_minor);
    }

    // ----------------------------------------------------- snapshot pricing

    #[Test]
    public function the_snapshot_is_immune_to_later_fee_plan_edits(): void
    {
        [$offering, $plan] = $this->makeOffering();

        $registration = $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'Snapshot']);

        // Fee plans are immutable-once-referenced by doctrine; simulate the
        // forbidden edit at the DB layer to prove reads NEVER come back from
        // the plan.
        \DB::table('fee_plans')->where('id', $plan->id)->update(['amount_minor' => 99999]);

        $fresh = $registration->fresh();
        $this->assertSame(15000, $fresh->list_total_minor);
        $this->assertSame(15000, $fresh->adjusted_total_minor);

        // The adjustment recompute also derives from the registration's own
        // stored list total, not the (now-mutated) plan.
        $this->service->grantAdjustment($fresh, RegistrationAdjustment::KIND_DISCOUNT, 1000, 'Sibling');
        $this->assertSame(14000, $fresh->adjusted_total_minor);
    }

    // ------------------------------------------------- intake atomicity

    #[Test]
    public function an_intake_validation_failure_writes_nothing_at_all(): void
    {
        [$offering, $plan] = $this->makeOffering(['capacity' => 5, 'registration_count' => 2]);

        try {
            // FormFactory's schema requires `full_name`.
            $this->service->register($offering, $plan, $this->contact(), ['unrelated' => 'x']);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('full_name', $e->errors());
        }

        // No orphan rows anywhere, and the seat counter never moved.
        $this->assertSame(0, Registration::count());
        $this->assertSame(0, FormResponse::count());
        $this->assertSame(0, Registrant::count());
        $this->assertSame(2, $offering->fresh()->registration_count);
    }

    #[Test]
    public function a_mid_transaction_failure_rolls_the_whole_intake_back(): void
    {
        // A roster group that resolves to ANOTHER tenant is a data error the
        // roster writer refuses loudly — inside the transaction, after the
        // form_response, registration, registrants, and counter increment.
        // Everything must roll back together.
        $foreignGroup = Group::factory()->create(['masjid_id' => $this->otherMasjid->id]);

        $offering = Offering::factory()->forMasjid($this->masjid)->create([
            'group_id' => $foreignGroup->id,
            'capacity' => 5,
        ]);
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        try {
            $this->service->register($offering, $plan, $this->contact(), ['full_name' => 'Rollback']);
            $this->fail('Expected RegistrationException for a cross-tenant roster group.');
        } catch (RegistrationException $e) {
            // expected
        }

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, FormResponse::count());
        $this->assertSame(0, Registrant::count());
        $this->assertSame(0, GroupMembership::count());
        $this->assertSame(0, $offering->fresh()->registration_count);
    }

    // ---------------------------------------------------------- cross-tenant

    #[Test]
    public function a_registration_cannot_reference_another_tenants_fee_plan(): void
    {
        [$offering, ] = $this->makeOffering();

        $foreignOffering = Offering::factory()->forMasjid($this->otherMasjid)->create();
        $foreignPlan = FeePlan::factory()->create([
            'masjid_id' => $this->otherMasjid->id,
            'offering_id' => $foreignOffering->id,
        ]);

        try {
            $this->service->register($offering, $foreignPlan, $this->contact(), ['full_name' => 'X']);
            $this->fail('Expected RegistrationException for a foreign fee plan.');
        } catch (RegistrationException $e) {
            // expected
        }

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_registration_cannot_reference_another_tenants_contacts(): void
    {
        [$offering, $plan] = $this->makeOffering();

        // Foreign payer.
        try {
            $this->service->register($offering, $plan, $this->contact($this->otherMasjid), ['full_name' => 'X']);
            $this->fail('Expected RegistrationException for a foreign payer.');
        } catch (RegistrationException $e) {
            // expected
        }

        // Foreign registrant behind a legitimate payer.
        try {
            $this->service->register(
                $offering,
                $plan,
                $this->contact(),
                ['full_name' => 'X'],
                [$this->contact($this->otherMasjid)]
            );
            $this->fail('Expected RegistrationException for a foreign registrant.');
        } catch (RegistrationException $e) {
            // expected
        }

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, FormResponse::count());
        $this->assertSame(0, Registrant::count());
    }

    #[Test]
    public function a_bound_tenant_must_be_the_offerings_tenant(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $payer = $this->contact();

        // An admin session bound to masjid B must not be able to drive intake
        // into masjid A — the creating hooks would stamp B onto A's rows.
        app(TenantContext::class)->set($this->otherMasjid->id);

        try {
            $this->service->register($offering, $plan, $payer, ['full_name' => 'X']);
            $this->fail('Expected RegistrationException for a mismatched bound tenant.');
        } catch (RegistrationException $e) {
            // expected
        } finally {
            app(TenantContext::class)->forgetTenant();
        }

        $this->assertSame(0, Registration::withoutMasjidScope()->count());
    }

    #[Test]
    public function every_row_the_service_writes_is_stamped_with_the_offerings_tenant(): void
    {
        $group = Group::factory()->create(['masjid_id' => $this->masjid->id]);
        $offering = Offering::factory()->forMasjid($this->masjid)->withRoster($group)->create();
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->service->register(
            $offering, $plan, $this->contact(), ['full_name' => 'Stamped'], [$this->contact()]
        );

        $this->assertSame($this->masjid->id, $registration->masjid_id);
        $this->assertSame($this->masjid->id, FormResponse::find($registration->form_response_id)->masjid_id);

        foreach (Registrant::where('registration_id', $registration->id)->get() as $registrant) {
            $this->assertSame($this->masjid->id, $registrant->masjid_id);
        }

        foreach (GroupMembership::where('group_id', $group->id)->get() as $membership) {
            $this->assertSame($this->masjid->id, $membership->masjid_id);
        }
    }
}
