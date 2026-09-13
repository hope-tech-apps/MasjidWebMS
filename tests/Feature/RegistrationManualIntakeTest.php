<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * T-041i — an administrator entering a registration BY HAND:
 * POST /api/admin/masjids/{masjid_id}/offerings/{offering_id}/registrations.
 *
 * A family pays at the desk in cash, or phones in, or has no email address, and
 * until this route existed the office had nowhere to put them. What they COULD
 * publish — the intake form as a page section — writes a `FormResponse`, which
 * takes no seat, applies no plan and charges nobody, while the roster kept
 * saying nobody had registered.
 *
 * WHAT THIS SUITE IS REALLY PINNING is two properties, and neither is "the
 * endpoint works":
 *
 *  1. IT CANNOT RECORD MONEY THAT HAS NOT MOVED. A paid plan entered by hand is
 *     `pending / awaiting` with no Stripe session, no matter what the body says.
 *     The endpoint accepts no amount, no currency, no payment status and no
 *     payment method, and the two honest routes to a paid hand-entered
 *     registration — a FREE plan, or Grant aid waiving the total to zero — are
 *     the same two the public door has.
 *
 *  2. IT WIDENS NOBODY'S ACCESS TO ANYBODY'S CHILD. This route may attach an
 *     EXISTING contact as a registrant, which the public endpoint refuses
 *     outright, because confirming writes a GUARDIAN EDGE from the payer over
 *     them and that edge is the single fact the parent portal reads to decide
 *     whose records a credential opens. Two things make that safe and both are
 *     pinned here: the route is gated on `permission:manage contacts`, and the
 *     edge is still written `self_asserted` — it lists a child and opens
 *     nothing until staff confirm it on the group screen.
 *
 * And one defect that the naive implementation shipped and this file exists to
 * keep out: a hand-entered PAID registration carrying the public path's
 * 30-minute `checkout_expires_at` would be swept by T-006f's reaper within the
 * half hour, so the child the office had just enrolled would silently vanish
 * off the roster with nothing anywhere saying why.
 *
 * THAT DEFECT HAS TWO DOORS, AND BOTH ARE PINNED HERE. Intake is the obvious
 * one. `promoteFromWaitlist()` is the one that was missed: a family who pays at
 * the desk for a FULL class is waitlisted with no window (correct), and a week
 * later Promote stamped the 30 minutes on anyway, because that branch never
 * asked `source`. It is the same silent cancellation one week later, so it gets
 * its own test — paired with its opposite, that a PUBLIC promotion still mints
 * the window and key its browser needs, so neither guarantee can be satisfied
 * by simply deleting the other.
 *
 * The refusal tests additionally pin the ROLLBACK, not merely the absence of a
 * registration. Contacts are resolved before the service runs, so a 422 that
 * leaves the typed family behind turns the admin's ordinary "fix the field and
 * submit again" into a duplicate on every retry; only `store()`'s outer
 * `DB::transaction` prevents it, and only an assertion counting contacts can
 * notice if that wrapper is ever removed.
 */
class RegistrationManualIntakeTest extends TestCase
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

    // ------------------------------------------------------------- the money

    #[Test]
    public function a_free_plan_entered_by_hand_is_confirmed_and_writes_the_roster(): void
    {
        $group = Group::factory()->create(['masjid_id' => $this->masjidA->id]);

        $offering = Offering::factory()->forMasjid($this->masjidA)
            ->withRoster($group)->withCapacity(10)->create();

        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $offering->id,
        ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf', 'email' => 'amal@example.test'],
            'registrants' => [['name' => 'Zayd Yusuf']],
            'data' => ['full_name' => 'Amal Yusuf'],
            'note' => 'Walked into the office',
        ])->assertCreated();

        // The declared free-path carve-out, unchanged by the door it came
        // through: no Stripe leg, so confirmation is synchronous in-request.
        $this->assertSame(Registration::STATUS_CONFIRMED, $response->json('data.status'));
        $this->assertSame(Registration::PAYMENT_NONE, $response->json('data.payment_status'));
        $this->assertSame(0, $response->json('data.adjusted_total_minor'));

        // Confirmed means the roster materialised, exactly as the public door does.
        $child = Contact::where('masjid_id', $this->masjidA->id)->where('first_name', 'Zayd')->firstOrFail();

        $this->assertDatabaseHas('group_memberships', [
            'group_id' => $group->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
    }

    #[Test]
    public function a_paid_plan_entered_by_hand_is_pending_and_awaiting_with_no_stripe_session(): void
    {
        [$offering, $plan] = $this->paidOffering();

        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
            'note' => 'Paid cash at the desk',
        ])->assertCreated();

        // UNPAID, and saying so. The office may have cash in a drawer; this
        // application did not see any money move and does not claim it did.
        $this->assertSame(Registration::STATUS_PENDING, $response->json('data.status'));
        $this->assertSame(Registration::PAYMENT_AWAITING, $response->json('data.payment_status'));

        $registration = Registration::findOrFail($response->json('data.id'));

        // The price is the SERVER'S, snapshotted from the immutable plan.
        $this->assertSame((int) $plan->amount_minor, (int) $registration->list_total_minor);
        $this->assertSame((int) $plan->amount_minor, (int) $registration->adjusted_total_minor);

        // No Stripe leg of any kind was opened, and none may be opened from here.
        $this->assertNull($registration->stripe_checkout_session_id);
        $this->assertNull($registration->stripe_subscription_id);
        $this->assertSame(0, $registration->payments()->count());
    }

    #[Test]
    public function a_hand_entered_paid_seat_carries_no_checkout_window_so_the_reaper_cannot_sweep_it(): void
    {
        [$offering, $plan] = $this->paidOffering();

        Sanctum::actingAs($this->adminA);

        $id = $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertCreated()->json('data.id');

        $registration = Registration::findOrFail($id);

        // `checkout_expires_at` is the lifetime of a hosted Checkout Session a
        // browser has open — NOT a general "unpaid seats expire" deadline. There
        // is no session here, so there is nothing to expire, and NULL is the
        // honest value. Stamping the public path's 30 minutes on it would have
        // T-006f's reaper release the seat before the family reached the car
        // park, with nothing on any screen saying why.
        $this->assertNull($registration->checkout_expires_at);
        $this->assertNull($registration->idempotency_key);

        // Which is exactly what the reaper's own predicate says: whatever
        // deadline it sweeps against, this row is not in the set.
        $this->assertSame(
            0,
            Registration::query()->checkoutExpiredBefore(now()->addYear())->whereKey($id)->count(),
            'a registration the office entered by hand must never be reaped as an abandoned checkout',
        );
    }

    #[Test]
    public function the_endpoint_records_no_money_whatever_the_body_claims(): void
    {
        [$offering, $plan] = $this->paidOffering();

        Sanctum::actingAs($this->adminA);

        // Every money-shaped key an admin (or a script) might reach for. None of
        // them is in the request's rules, so none reaches a column: money moves
        // only on a verified webhook (.claude/rules/stripe-payments.md).
        $id = $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
            'payment_status' => Registration::PAYMENT_PAID,
            'status' => Registration::STATUS_CONFIRMED,
            'paid' => true,
            'paid_via' => 'cash',
            'payment_method' => 'cash',
            'amount' => 1,
            'amount_minor' => 1,
            'list_total_minor' => 1,
            'adjusted_total_minor' => 1,
            'currency' => 'eur',
        ])->assertCreated()->json('data.id');

        $registration = Registration::findOrFail($id);

        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertSame((int) $plan->amount_minor, (int) $registration->adjusted_total_minor);

        // CURRENCY LIVES ON THE FEE PLAN AND NOWHERE ELSE, asserted against the
        // LITERAL the plan was created with. The previous spelling compared
        // `$plan->currency` to `$registration->feePlan->currency`, which resolve
        // to the SAME fee_plans row — the same column of the same record on both
        // sides of the assertion. It therefore passed for any behaviour at all,
        // including the endpoint writing the body's 'eur' onto the plan, inside
        // the one test whose entire subject is that a body cannot restate money.
        $this->assertSame('usd', $registration->feePlan->fresh()->currency);

        // And there is no second place the body's 'eur' could have landed: a
        // registration denominates itself THROUGH its plan, so the key has no
        // column to reach even if a future `$fillable` grew one.
        $this->assertFalse(
            Schema::hasColumn('registrations', 'currency'),
            'a registration must denominate itself through its fee plan; a currency column here would let a body price a seat',
        );

        $this->assertSame(0, $registration->payments()->count());
    }

    // ------------------------------------------------------------- the seat

    #[Test]
    public function a_staff_created_registration_takes_a_seat_under_the_same_lock(): void
    {
        [$offering, $plan] = $this->paidOffering(['capacity' => 2, 'registration_count' => 0]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertCreated();

        // The guarded counter, written only inside the locked intake
        // transaction. A staff-entered seat is a seat.
        $this->assertSame(1, (int) $offering->fresh()->registration_count);
    }

    #[Test]
    public function the_offering_being_full_waitlists_a_manual_registration_too(): void
    {
        [$offering, $plan] = $this->paidOffering(['capacity' => 1, 'registration_count' => 1]);

        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertCreated();

        // No override, no "the office says so": capacity is re-checked under the
        // offering's row lock and an administrator cannot oversell a classroom
        // any more than a parent can.
        $this->assertSame(Registration::STATUS_WAITLISTED, $response->json('data.status'));
        $this->assertSame(Registration::PAYMENT_NONE, $response->json('data.payment_status'));
        $this->assertSame(1, (int) $offering->fresh()->registration_count);
    }

    #[Test]
    public function promoting_a_hand_entered_seat_off_the_waitlist_leaves_the_reaper_nothing_to_sweep(): void
    {
        // THE SAME DEFECT, ONE WEEK LATER. The class is full when the family
        // pays at the desk, so the office's registration queues (waitlisted,
        // source=staff, no window). A place frees, the office clicks Promote,
        // and `promoteFromWaitlist()` used to stamp the public path's 30-minute
        // `checkout_expires_at` unconditionally — never asking `source`. No
        // Stripe session ever existed to null that window, so 45 minutes on
        // (30 + the reaper's 15-minute grace) the seat the office had just
        // handed out was cancelled and the counter given back. The roster showed
        // the child; then it silently did not.
        [$offering, $plan] = $this->paidOffering(['capacity' => 1, 'registration_count' => 1]);

        Sanctum::actingAs($this->adminA);

        $id = $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'registrants' => [['name' => 'Zayd Yusuf']],
            'data' => ['full_name' => 'Amal Yusuf'],
            'note' => 'Paid cash at the desk, class was full',
        ])->assertCreated()->json('data.id');

        $this->assertSame(Registration::STATUS_WAITLISTED, Registration::findOrFail($id)->status);

        // A week passes and somebody withdraws.
        $offering->forceFill(['registration_count' => 0])->save();

        $this->postJson($this->url($offering) . '/' . $id . '/promote')
            ->assertOk()
            ->assertJsonPath('data.status', Registration::STATUS_PENDING);

        $promoted = Registration::findOrFail($id);

        $this->assertSame(Registration::SOURCE_STAFF, $promoted->source);
        $this->assertSame(Registration::PAYMENT_AWAITING, $promoted->payment_status);

        // Promotion reproduces intake's carve-out rather than only its happy
        // path: NULL is the honest value on both doors, because no session
        // exists to expire and no money is in flight to lose.
        $this->assertNull($promoted->checkout_expires_at);
        $this->assertNull($promoted->idempotency_key);

        // An hour later — well past window + grace — the reaper runs for real.
        $this->travel(1)->hours();

        Artisan::call('registrations:reap-expired');

        $promoted->refresh();

        $this->assertSame(
            Registration::STATUS_PENDING,
            $promoted->status,
            'a seat the office typed by hand and then promoted must never be reaped as an abandoned checkout',
        );
        $this->assertSame(Registration::PAYMENT_AWAITING, $promoted->payment_status);
        $this->assertSame(1, (int) $offering->fresh()->registration_count);
    }

    #[Test]
    public function promoting_a_public_waitlisted_seat_still_opens_the_checkout_window_it_needs(): void
    {
        // THE OTHER HALF OF THE SAME RULE, and the reason the test above cannot
        // be satisfied by never stamping a window at all. A registrant who
        // queued through the PUBLIC door has a browser to pay in, so promotion
        // must mint the window and the key the re-mint endpoint needs — and the
        // seat it hands out is reapable, correctly, because there IS a Stripe
        // leg to abandon.
        [$offering, $plan] = $this->paidOffering(['capacity' => 5, 'registration_count' => 0]);

        $registration = Registration::factory()->waitlisted()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $offering->id,
            'fee_plan_id' => $plan->id,
            'contact_id' => Contact::factory()->create(['masjid_id' => $this->masjidA->id])->id,
        ]);

        $this->assertSame(Registration::SOURCE_PUBLIC, $registration->source);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url($offering) . '/' . $registration->id . '/promote')->assertOk();

        $registration->refresh();

        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertNotNull($registration->checkout_expires_at);
        $this->assertNotNull($registration->idempotency_key);

        // And the reaper's predicate agrees it is in the set, once the window
        // and the grace have both passed.
        $this->travel(1)->hours();

        Artisan::call('registrations:reap-expired');

        $registration->refresh();

        $this->assertSame(Registration::STATUS_CANCELLED, $registration->status);
        $this->assertSame(0, (int) $offering->fresh()->registration_count);
    }

    #[Test]
    public function a_closed_offering_refuses_a_hand_entered_registration_in_the_services_own_words(): void
    {
        [$offering, $plan] = $this->paidOffering(['is_active' => false]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(422);

        $this->assertSame(0, Registration::where('offering_id', $offering->id)->count());

        // AND THE PAYER THE CONTROLLER HAD ALREADY CREATED IS GONE WITH IT.
        // This is the assertion that pins store()'s outer DB::transaction, and
        // without it that wrapper can be deleted with the suite still green.
        // Contacts are resolved BEFORE the service runs (the service creates no
        // Contact rows), so on a refusal the typed payer survives unless the
        // whole attempt rolls back — and here the refusal is one the admin is
        // MEANT to retry: the modal names the problem, they fix it, they submit
        // again, and the directory quietly holds two of every family that ever
        // hit a validation error. Counting registrations alone cannot see that.
        $this->assertSame(0, $this->contactsNamed('Amal'));
    }

    // -------------------------------------------------------- the guardian claim

    #[Test]
    public function a_manual_registration_records_the_guardian_edge_from_the_payer(): void
    {
        $group = Group::factory()->create(['masjid_id' => $this->masjidA->id]);

        $offering = Offering::factory()->forMasjid($this->masjidA)
            ->withRoster($group)->withCapacity(10)->create();

        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $offering->id,
        ]);

        // The thing the PUBLIC endpoint refuses: naming a child who already
        // exists. Allowed here only because this route is authenticated.
        $payer = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);
        $child = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer_contact_id' => $payer->id,
            'registrants' => [['contact_id' => $child->id]],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertCreated();

        $edge = GroupMembership::query()
            ->where('group_id', $group->id)
            ->where('contact_id', $payer->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('guardian_of_contact_id', $child->id)
            ->first();

        $this->assertNotNull($edge, 'the payer must be recorded as the guardian of who they enrolled');

        // AND IT GRANTS NOTHING. `self_asserted` lists the child and opens no
        // record; only `confirmed` — an authenticated staff act on the group
        // screen — does that. Entering a registration by hand must not become a
        // side door that hands somebody a family's safeguarding records, which
        // is why the permission gate is belt and this is braces.
        $this->assertSame(GroupMembership::PROVENANCE_SELF_ASSERTED, $edge->provenance);
        $this->assertNull($edge->confirmed_by_user_id);
    }

    #[Test]
    public function an_admin_without_manage_contacts_is_refused(): void
    {
        [$offering, $plan] = $this->paidOffering();

        // Strip the bridged role and grant only the READ permission: this admin
        // may look at the roster and may not enrol anybody onto it.
        $this->adminA->syncRoles([]);
        $this->adminA->givePermissionTo('view contacts');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->url($offering))->assertOk();

        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(403);

        $this->assertSame(0, Registration::where('offering_id', $offering->id)->count());
    }

    #[Test]
    public function an_organisation_with_crm_disabled_cannot_reach_this_route(): void
    {
        [$offering, $plan] = $this->paidOffering();

        // crm_enabled defaults FALSE and provisioning never sets it, so this is
        // the state a freshly provisioned school is actually in.
        $this->masjidA->crm_enabled = false;
        $this->masjidA->save();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(403);

        $this->assertSame(0, Registration::where('offering_id', $offering->id)->count());
    }

    // ------------------------------------------------------------ tenancy

    #[Test]
    public function another_organisations_offering_is_not_reachable_from_this_route(): void
    {
        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'offering_id' => $this->offeringB->id,
        ]);

        Sanctum::actingAs($this->adminA);

        // B's offering under A's masjid: a 404, because the scoped resolve misses.
        $this->postJson($this->url($this->offeringB), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(404);

        // B's masjid in the path at all: a 403, before anything is resolved.
        $this->postJson($this->url($this->offeringB, $this->masjidB), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(403);

        $this->assertSame(0, Registration::count());
    }

    #[Test]
    public function another_tenants_fee_plan_or_contact_never_reaches_the_service(): void
    {
        [$offering] = $this->paidOffering();

        $foreignPlan = FeePlan::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'offering_id' => $this->offeringB->id,
        ]);
        $foreignContact = Contact::factory()->create(['masjid_id' => $this->masjidB->id]);

        Sanctum::actingAs($this->adminA);

        // A BODY field naming another organisation's row is a 422 on that field,
        // the same call `OfferingFormRequest::ownedRule` makes for
        // `intake_form_id` and `group_id` — the 404 belongs to the route ids.
        // Either way nothing cross-tenant is written.
        $this->postJson($this->url($offering), [
            'fee_plan_id' => $foreignPlan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(422)->assertJsonPath('status', 'failed');

        $plan = FeePlan::where('offering_id', $offering->id)->firstOrFail();

        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer_contact_id' => $foreignContact->id,
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(422);

        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'registrants' => [['contact_id' => $foreignContact->id]],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(422);

        $this->assertSame(0, Registration::count());
    }

    #[Test]
    public function a_fee_plan_of_another_offering_in_the_same_organisation_is_refused(): void
    {
        [$offering] = $this->paidOffering();

        // Right tenant, wrong price: the plan of a different program.
        $otherPlan = FeePlan::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url($offering), [
            'fee_plan_id' => $otherPlan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(422);

        $this->assertSame(0, Registration::where('offering_id', $offering->id)->count());
    }

    // ------------------------------------------------------- what it records

    #[Test]
    public function a_manual_registration_records_who_entered_it_and_why(): void
    {
        [$offering, $plan] = $this->paidOffering();

        Sanctum::actingAs($this->adminA);

        $id = $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
            'note' => 'Paid cash at the desk',
        ])->assertCreated()->json('data.id');

        $registration = Registration::findOrFail($id);

        // An assertion of authority that records no author is unfalsifiable.
        // This is what turns "an admin said so" into a name the office can ask.
        $this->assertSame(Registration::SOURCE_STAFF, $registration->source);
        $this->assertSame($this->adminA->id, (int) $registration->entered_by_user_id);
        $this->assertSame('Paid cash at the desk', $registration->staff_note);
    }

    #[Test]
    public function a_registration_from_the_public_door_is_still_recorded_as_public(): void
    {
        $group = Group::factory()->create(['masjid_id' => $this->masjidA->id]);

        $offering = Offering::factory()->forMasjid($this->masjidA)
            ->withRoster($group)->withCapacity(10)->create();

        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $offering->id,
        ]);

        // The service called WITHOUT a StaffEntry — the public path's shape.
        $registration = app(\App\Services\Registrations\RegistrationService::class)->register(
            $offering,
            $plan,
            Contact::factory()->create(['masjid_id' => $this->masjidA->id]),
            ['full_name' => 'Amal Yusuf'],
        );

        // The default is stated on the MODEL as well as the schema, so an
        // unrefreshed row cannot read NULL here while the database reads
        // 'public' — the split GroupMembership's provenance column already had.
        $this->assertSame(Registration::SOURCE_PUBLIC, $registration->source);
        $this->assertNull($registration->entered_by_user_id);
        $this->assertNull($registration->staff_note);
    }

    #[Test]
    public function an_empty_registrant_list_registers_the_payer_themselves(): void
    {
        [$offering, $plan] = $this->paidOffering();

        Sanctum::actingAs($this->adminA);

        $id = $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'registrants' => [],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertCreated()->json('data.id');

        $registration = Registration::findOrFail($id);

        // An adult signing up for an adult class. `normalizeRegistrants()` reads
        // the empty list as "the payer", so there is exactly one registrant and
        // it is them — never zero, and never a second copy of the same person.
        $this->assertSame(1, Registrant::where('registration_id', $registration->id)->count());
        $this->assertSame(
            (int) $registration->contact_id,
            (int) Registrant::where('registration_id', $registration->id)->value('contact_id'),
        );
    }

    #[Test]
    public function a_registrant_naming_both_an_existing_person_and_a_new_name_is_refused(): void
    {
        [$offering, $plan] = $this->paidOffering();
        $child = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);

        Sanctum::actingAs($this->adminA);

        // "This existing person, but called that" has no honest resolution:
        // attaching the id ignores a name the admin typed on purpose, using the
        // name ignores the person they picked. Refuse and let them choose.
        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'registrants' => [['contact_id' => $child->id, 'name' => 'Somebody Else']],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(422);

        // A row naming NEITHER is refused too — it is a blank row left behind,
        // and it would otherwise write a contact called "Registrant".
        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'registrants' => [[]],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(422);

        $this->assertSame(0, Registration::where('offering_id', $offering->id)->count());
    }

    #[Test]
    public function a_payer_naming_both_an_existing_person_and_a_new_name_is_refused(): void
    {
        [$offering, $plan] = $this->paidOffering();
        $picked = Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'first_name' => 'Huda',
        ]);

        Sanctum::actingAs($this->adminA);

        // THE SAME XOR THE REGISTRANT ROWS GET, ON THE ONE FIELD WHERE GETTING
        // IT WRONG IS WORST. `rules()` can only make the pair `required_without`
        // each other, which refuses NEITHER and permits BOTH — so this body
        // used to validate, `resolvePayer()` took the id branch, and the seat
        // was filed under Huda with 'Amal Yusuf' dropped on the floor under a
        // 201 and nothing on any screen naming a second person. The payer is
        // also one end of every guardian edge `writeRosterMemberships()` writes
        // over the children on the registration, so the silent resolution
        // attaches somebody else's child to whoever the id happened to point at.
        $response = $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer_contact_id' => $picked->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        // Named on the field the admin can act on, in the same words a
        // registrant row is refused in — one rule, one sentence, both doors.
        $this->assertSame(
            'Choose an existing person or type a new name for this row, not both.',
            $response->json('data')['payer.name'][0] ?? null,
        );

        // Nothing was filed under either person, and the name they typed did not
        // become a contact on the way to the refusal.
        $this->assertSame(0, Registration::where('offering_id', $offering->id)->count());
        $this->assertSame(0, $this->contactsNamed('Amal'));

        // The NEITHER half stays answered by `required_without` — asserted here
        // so a future rewrite of the XOR cannot close the BOTH hole by opening
        // this one, which would reach `createContact()` and file a seat under a
        // person called "Payer".
        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertStatus(422);

        $this->assertSame(0, Registration::where('offering_id', $offering->id)->count());
    }

    #[Test]
    public function a_typed_person_never_takes_an_address_another_contact_already_holds(): void
    {
        [$offering, $plan] = $this->paidOffering();

        $teacher = Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'email' => 'teacher@school.test',
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf', 'email' => 'TEACHER@School.test'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])->assertCreated();

        // A second row carrying a live address is what makes a staff identity
        // AMBIGUOUS, and ambiguous identity is no identity — it is what once
        // 403'd a teacher out of her own classroom permanently. An authenticated
        // admin typing it does not make that outcome any less bad; the new row
        // gets no address, exactly as a child with none always had, and the
        // office can add the right one afterwards.
        $this->assertSame(1, Contact::where('masjid_id', $this->masjidA->id)
            ->whereRaw('LOWER(email) = ?', ['teacher@school.test'])->count());

        $this->assertSame($teacher->id, Contact::where('masjid_id', $this->masjidA->id)
            ->whereRaw('LOWER(email) = ?', ['teacher@school.test'])->value('id'));

        $payer = Contact::where('masjid_id', $this->masjidA->id)->where('first_name', 'Amal')->firstOrFail();
        $this->assertNull($payer->email);
    }

    #[Test]
    public function intake_answers_are_validated_against_the_offerings_own_stored_schema(): void
    {
        [$offering, $plan] = $this->paidOffering();

        Sanctum::actingAs($this->adminA);

        // The factory's form declares one REQUIRED text field. The rule comes
        // from the STORED schema through App\Support\FormSchema, on the same
        // code path the public intake uses — this endpoint restates none of it.
        $this->postJson($this->url($offering), [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf'],
            'data' => [],
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['full_name']]);

        $this->assertSame(0, Registration::where('offering_id', $offering->id)->count());

        // The rollback again, on the refusal an admin is most likely to hit
        // twice: a missed answer on the offering's own form. The payer was
        // created before the service read the schema, so only store()'s outer
        // transaction takes them back out — see the closed-offering test.
        $this->assertSame(0, $this->contactsNamed('Amal'));
    }

    // ------------------------------------------------------------ the screen

    #[Test]
    public function the_roster_screen_offers_the_button_and_still_refuses_to_record_a_payment(): void
    {
        // A DOCBLOCK-VS-WIRE CHECK, in the style of
        // RosterClaimIdentityTest::the_roster_screen_still_draws_the_identity…
        // The dangerous edit here is not a broken button, it is somebody adding
        // the "mark as paid" box every operator asks for — which would put the
        // roster permanently out of step with the Stripe account that holds the
        // funds. A sentence in a comment cannot notice that; this can.
        $tab = file_get_contents(base_path(
            'resources/vue-app/views/dashboard/offerings/OfferingRegistrationsTab.vue'
        ));
        $modal = file_get_contents(base_path(
            'resources/vue-app/views/dashboard/offerings/ManualRegistrationModal.vue'
        ));

        $this->assertIsString($tab);
        $this->assertIsString($modal);

        // The screen no longer tells the operator the thing that is now false.
        $this->assertStringNotContainsString('Nothing on this screen creates one', $tab);
        $this->assertStringContainsString('Add a registration', $tab);
        $this->assertStringContainsString('ManualRegistrationModal', $tab);

        // And it still says where payment states come from.
        $this->assertStringContainsString("Payment states come from Stripe's webhooks", $tab);

        // The modal must keep saying that a paid plan is entered UNPAID.
        $this->assertStringContainsString('unpaid', $modal);
        $this->assertStringContainsString('Grant aid', $modal);

        // AND THE PAYLOAD MUST STAY MONEYLESS. Asserted on the TYPE rather than
        // on the component, because the type is the contract every caller has to
        // satisfy — and because the modal legitimately READS `amount_minor` to
        // print what a plan costs, which is exactly the distinction a naive
        // grep over the component would lose. A money control could only reach
        // the server by first appearing here.
        $types = file_get_contents(base_path(
            'resources/vue-app/core/types/data/masjid-related/Offering.ts'
        ));

        $this->assertIsString($types);

        $start = strpos($types, 'export type ManualRegistrationPayload = {');
        $this->assertNotFalse($start, 'the manual-registration payload type has been renamed or removed');

        $payloadType = substr($types, $start, strpos($types, '};', $start) - $start);

        foreach (
            [
                'amount', 'currency', 'minor', 'paid', 'payment', 'status', 'stripe',
            ] as $forbidden
        ) {
            $this->assertStringNotContainsString(
                $forbidden,
                $payloadType,
                "a hand-entered registration must carry no money field: found '{$forbidden}'",
            );
        }
    }

    // ============================================================== helpers

    /**
     * How many of A's contacts carry this first name — the orphan counter.
     *
     * Written as a helper because it is the SAME question in three refusal
     * tests, and because what it is asking is easy to misread as decoration:
     * it is the only assertion that can fail if `RegistrationsController::store`
     * loses its outer `DB::transaction`, since the contacts are created before
     * the service ever runs and a refusal that leaves them behind duplicates a
     * family on every retry.
     */
    private function contactsNamed(string $firstName): int
    {
        return Contact::where('masjid_id', $this->masjidA->id)
            ->where('first_name', $firstName)
            ->count();
    }

    private function url(?Offering $offering = null, ?Masjid $masjid = null): string
    {
        $offering ??= $this->offeringA;

        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id
            . '/offerings/' . $offering->id . '/registrations';
    }

    /**
     * An offering of A's with exactly one active, purchasable one-time plan.
     *
     * @return array{0: Offering, 1: FeePlan}
     */
    private function paidOffering(array $state = []): array
    {
        $offering = Offering::factory()->forMasjid($this->masjidA)
            ->create(array_merge(['capacity' => 10, 'registration_count' => 0], $state));

        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $offering->id,
            'kind' => FeePlan::KIND_ONE_TIME,
            'amount_minor' => 15000,
            'is_active' => true,
        ]);

        return [$offering, $plan];
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
}
