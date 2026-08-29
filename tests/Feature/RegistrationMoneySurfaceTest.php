<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Models\RegistrationPayment;
use App\Models\User;
use App\Services\Registrations\RegistrationService;
use App\Services\Stripe\RegistrationCheckoutService;
use App\Support\OfferingPublicPayload;
use App\Support\OfferingRegistrationState;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE MONEY SURFACE, third pass — the six defects cleared before a real school
 * with real classrooms and real parent logins is provisioned onto this code.
 *
 * Every case in this file was MEASURED on the branch before its fix, and each
 * one is the failing half of a fix that is either a new guard, a removal of an
 * over-reach shipped by an earlier round, or a sentence made true.
 *
 *  F1  a family pays, the platform keeps no record, and the reaper cancels her
 *      seat — because the offering's roster group was soft-deleted underneath
 *      it and `writeRosterMemberships()` threw out of a webhook transaction
 *      that had already booked her money.
 *  F2  the quote became strictly stricter than the checkout it previews.
 *  F3  `closes_at` — the NEW-registration deadline — killed an in-flight
 *      checkout for somebody who had already registered.
 *  F4  aid that leaves less than one minor unit per installment left a seat
 *      that could not be paid for and was reaped.
 *  F5  a paid plan charging nothing read `open`, published $0.00, and
 *      registered CONFIRMED FREE.
 *  F6  two documentation-vs-wire drifts.
 *
 * FOURTH PASS. F2 fixed the direction it was looking at and opened the other
 * one in the same commit: the quote's `registration_uuid` branch lost its
 * offering-level verdict (right) while `checkout()` gained two program-level
 * refusals (also right), and nobody checked the two against each other. The six
 * F2 cells below are all about the LOOSENING; none is the cell where the
 * loosened preview meets the tightened write, so all six went on passing while
 * a live hold was quoted "$150.00 — Pay now" against a checkout that answered
 * 422. The added cells drive that matrix off `checkout()`'s OWN answer rather
 * than off a list of clauses, so a clause added to checkout tomorrow cannot
 * reopen the gap by simply not appearing in this file. With them:
 *
 *  M1  the quote promised a payment the checkout refused, in the two cells the
 *      third pass created.
 *  M2  `amount_due_minor` answered "what it cost" under a name that says "what
 *      you owe" — $150 to a family who had paid, and 5¢ to a family who owed
 *      nothing at all.
 *  M3  a stale payment link was answered with a sentence about fee plans.
 */
class RegistrationMoneySurfaceTest extends TestCase
{
    use RefreshDatabase;

    private string $platformSecret = 'whsec_platform_secret';

    private Masjid $masjid;

    private Masjid $otherMasjid;

    private Group $group;

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
            'services.stripe.webhook_secret' => $this->platformSecret,
            'services.stripe.connect_webhook_secret' => 'whsec_connect_secret',
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.currency' => 'usd',
            'services.stripe.registration_checkout_window_minutes' => 30,
        ]);

        // /api/v1 never runs the tenant middleware.
        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $this->otherMasjid = $this->makeMasjid(['stripe_account_id' => 'acct_B', 'stripe_charges_enabled' => true]);
        $this->group = Group::factory()->create(['masjid_id' => $this->masjid->id, 'name' => 'Grade 3']);
    }

    // =====================================================================
    // F1 — the roster group is the one unguarded reference, and it takes money
    // =====================================================================

    /**
     * THE MEASURED CASE, end to end.
     *
     *   register -> 200 pending, seat 1, live checkout URL
     *   registrar soft-deletes group G (one click, no warning)
     *   checkout.session.completed x3 (Stripe retries) -> 500, 500, 500
     *   after retries: status=pending payment=awaiting payments=0
     *   +46 min reaper: cancelled / canceled / seat 0 / payments 0
     *
     * The money moved. The webhook is the source of truth, so it must record
     * that — the roster is a materialisation of the ORGANISATION's own
     * configuration and its absence is not a fact about the family's payment.
     */
    #[Test]
    public function a_payment_is_recorded_and_the_seat_confirmed_when_the_roster_group_has_been_deleted(): void
    {
        $offering = $this->rosterOffering();
        $registration = $this->paidPending($offering);

        // One click in the admin UI, no warning, no guard (before F1).
        $this->group->delete();

        $this->postWebhook($this->completedEvent($registration))->assertOk();

        $registration->refresh();

        // The money is recorded — the org is merchant of record and whoever
        // refunds this needs the amount and the identifiers.
        $payment = RegistrationPayment::withoutMasjidScope()
            ->where('registration_id', $registration->id)
            ->first();

        $this->assertNotNull($payment, 'the ledger row is the only local evidence a real charge happened');
        $this->assertSame(15000, $payment->amount_minor);
        $this->assertSame(RegistrationPayment::STATUS_SUCCEEDED, $payment->status);

        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);

        // And the reaper can never see it: no deadline left to sweep.
        $this->assertNull($registration->checkout_expires_at);

        // No roster was written — there is no live group to write into — and
        // nothing was written into the deleted one either.
        $this->assertSame(0, GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->group->id)->count());
    }

    /** Stripe retries. Every delivery must ack, and none may double-book. */
    #[Test]
    public function stripe_retries_of_that_event_ack_and_book_exactly_one_payment(): void
    {
        $offering = $this->rosterOffering();
        $registration = $this->paidPending($offering);
        $this->group->delete();

        foreach (['evt_1', 'evt_2', 'evt_3'] as $eventId) {
            $this->postWebhook($this->completedEvent($registration, ['event_id' => $eventId]))->assertOk();
        }

        $this->assertSame(1, RegistrationPayment::withoutMasjidScope()
            ->where('registration_id', $registration->id)->count());
    }

    /**
     * The FREE path failed the same way, more visibly: `register` answered 422
     * with an internal invariant sentence to an anonymous parent — a state no
     * read surface predicts and that is not in `REASONS`.
     */
    #[Test]
    public function the_free_path_still_registers_when_the_roster_group_has_been_deleted(): void
    {
        $offering = $this->rosterOffering();

        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $this->group->delete();

        $this->postRegister($offering, [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amal Yusuf', 'email' => 'amal@example.test'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Registration::STATUS_CONFIRMED);

        // Nothing was written into the deleted group.
        $this->assertSame(0, GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->group->id)->count());
    }

    /**
     * A group belonging to ANOTHER organisation is still never written into.
     * Skipping is what protects the other tenant's roster; throwing was only
     * ever the delivery mechanism, and it is the delivery mechanism that
     * destroyed money.
     */
    #[Test]
    public function a_cross_tenant_roster_group_is_never_written_into(): void
    {
        $foreign = Group::factory()->create(['masjid_id' => $this->otherMasjid->id]);

        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        // Only a manual edit / import can produce this: ownedRule() blocks it
        // on the admin surface.
        $offering->forceFill(['group_id' => $foreign->id])->save();

        $registration = $this->paidPending($offering);

        $this->postWebhook($this->completedEvent($registration))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);

        // The whole point of the refusal, preserved.
        $this->assertSame(0, GroupMembership::withoutMasjidScope()
            ->where('group_id', $foreign->id)->count());
    }

    /** A live roster still materialises, unchanged. */
    #[Test]
    public function a_live_roster_group_still_materialises_exactly_once(): void
    {
        $offering = $this->rosterOffering();
        $registration = $this->paidPending($offering);

        $this->postWebhook($this->completedEvent($registration))->assertOk();

        $this->assertSame(1, GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->group->id)->count());
    }

    // ---------------------------------------- F1, the other half: the deletion

    #[Test]
    public function deleting_a_group_is_refused_while_it_is_a_live_offerings_roster(): void
    {
        [$admin, $offering] = $this->adminWithRosterOffering();

        Sanctum::actingAs($admin);

        $response = $this->deleteJson($this->groupsUrl() . '/' . $this->group->id)
            ->assertStatus(422);

        // Named, so the admin knows which program is holding it.
        $this->assertStringContainsString($offering->name, (string) $response->json('message'));
        $this->assertContains($offering->name, $response->json('offerings'));

        $this->assertDatabaseHas('groups', ['id' => $this->group->id, 'deleted_at' => null]);
    }

    #[Test]
    public function deleting_a_group_no_offering_uses_is_still_allowed(): void
    {
        [$admin] = $this->adminWithRosterOffering();

        $spare = Group::factory()->create(['masjid_id' => $this->masjid->id, 'name' => 'Volunteers']);

        Sanctum::actingAs($admin);

        $this->deleteJson($this->groupsUrl() . '/' . $spare->id)->assertOk();

        $this->assertSoftDeleted('groups', ['id' => $spare->id]);
    }

    #[Test]
    public function detaching_the_offering_first_lets_the_group_be_deleted(): void
    {
        [$admin, $offering] = $this->adminWithRosterOffering();

        // The non-destructive path the refusal points at.
        $offering->forceFill(['group_id' => null])->save();

        Sanctum::actingAs($admin);

        $this->deleteJson($this->groupsUrl() . '/' . $this->group->id)->assertOk();

        $this->assertSoftDeleted('groups', ['id' => $this->group->id]);
    }

    #[Test]
    public function a_soft_deleted_offering_does_not_hold_its_roster_group_hostage(): void
    {
        [$admin, $offering] = $this->adminWithRosterOffering();

        $offering->delete();

        Sanctum::actingAs($admin);

        $this->deleteJson($this->groupsUrl() . '/' . $this->group->id)->assertOk();
    }

    #[Test]
    public function another_organisations_offering_never_blocks_this_ones_group_delete(): void
    {
        [$admin] = $this->adminWithRosterOffering();

        $foreignGroup = Group::factory()->create(['masjid_id' => $this->otherMasjid->id]);
        $foreignOffering = Offering::factory()->forMasjid($this->otherMasjid)->create();
        $foreignOffering->forceFill(['group_id' => $foreignGroup->id])->save();

        $spare = Group::factory()->create(['masjid_id' => $this->masjid->id]);

        Sanctum::actingAs($admin);

        // The block list must be masjid-filtered, not a global scan.
        $this->deleteJson($this->groupsUrl() . '/' . $spare->id)->assertOk();
    }

    // =====================================================================
    // F2 — the quote must never be stricter than the checkout it previews
    // =====================================================================

    /**
     * THE MEASURED PAIR, same fixture, same instant:
     *
     *   CHECKOUT  registration held on the deactivated plan -> 200, charged 15000
     *   QUOTE     same registration, its OWN fee_plan_id    -> 404
     */
    #[Test]
    public function the_quote_prices_an_in_flight_registration_whose_plan_has_been_deactivated(): void
    {
        $this->stubSession('cs_1', 'https://checkout.stripe.test/cs_1', 'pi_1');

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        // The documented seasonal price rise: deactivate-and-replace.
        $plan->forceFill(['is_active' => false])->save();
        FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
            'amount_minor' => 17500,
            'label' => '2027',
        ]);

        // The write this previews still succeeds and still charges the snapshot.
        $this->postCheckout($registration)->assertOk();

        $this->postQuote($offering, [
            'fee_plan_id' => $plan->id,
            'registration_uuid' => $registration->uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.fee_plan_id', $plan->id)
            ->assertJsonPath('data.fee_plan_kind', FeePlan::KIND_ONE_TIME)
            ->assertJsonPath('data.amount_due_minor', 15000);
    }

    /**
     * `fee_plan_id` was `required` even with a `registration_uuid` — so the
     * payment page could not ask "what do I owe" without naming a plan, and
     * the only plan id the endpoint accepted was one that was NOT hers.
     */
    #[Test]
    public function the_quote_accepts_a_registration_uuid_with_no_fee_plan_id(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.fee_plan_id', $plan->id)
            ->assertJsonPath('data.amount_due_minor', 15000);
    }

    /**
     * Naming a DIFFERENT purchasable plan returned that plan's id and kind
     * beside the registration's snapshot amount — `kind: recurring` for a
     * one-time $150 registration.
     */
    #[Test]
    public function the_quote_reports_the_registrations_own_plan_not_the_one_named(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $other = FeePlan::factory()->recurring()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $this->postQuote($offering, [
            'fee_plan_id' => $other->id,
            'registration_uuid' => $registration->uuid,
        ])
            ->assertOk()
            // A client can name a plan; it can never name a price, and it can
            // never restate which plan a registration is on.
            ->assertJsonPath('data.fee_plan_id', $plan->id)
            ->assertJsonPath('data.fee_plan_kind', FeePlan::KIND_ONE_TIME)
            ->assertJsonPath('data.amount_due_minor', 15000);
    }

    /**
     * She abandons Stripe, comes back to the payment page, the page asks what
     * she owes. Her checkout link works; the quote used to 404.
     */
    #[Test]
    public function the_quote_answers_an_in_flight_registration_after_the_window_closes(): void
    {
        $this->stubSession('cs_2', 'https://checkout.stripe.test/cs_2', 'pi_2');

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $offering->forceFill(['closes_at' => now()->subMinutes(5)])->save();

        $this->postCheckout($registration)->assertOk();

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.amount_due_minor', 15000);
    }

    /**
     * `requires_payment` answers "is Stripe still expected", and for an
     * EXISTING registration that is a fact about the row, not about its total.
     * Deriving it from `adjusted_total_minor` told a settled family she still
     * owed the $150 she had already paid.
     */
    #[Test]
    public function the_quote_of_a_settled_registration_asks_for_nothing_more(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $registration->forceFill([
            'payment_status' => Registration::PAYMENT_PAID,
            'status' => Registration::STATUS_CONFIRMED,
            'checkout_expires_at' => null,
        ])->save();

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            // What the place cost is never restated…
            ->assertJsonPath('data.adjusted_total_minor', 15000)
            // …but nothing more is owed, and the field NAMED "amount due" says
            // so too. It used to repeat the cost, so a renderer that printed it
            // without switching on the boolean beside it billed a paid family
            // $150 a second time (M2).
            ->assertJsonPath('data.requires_payment', false)
            ->assertJsonPath('data.amount_due_minor', 0);
    }

    /** An in-flight registration still says a payment is expected. */
    #[Test]
    public function the_quote_of_an_awaiting_registration_still_expects_payment(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.requires_payment', true);
    }

    // -------------------- F2, the cell the six above did not have: the quote
    // -------------------- got LOOSER and the checkout got TIGHTER in one pass
    //
    // The six cells above pin the loosening (the window, `is_active`, a settled
    // row, a foreign uuid). None of them is the cell where the loosened preview
    // MEETS the tightened write, and both of the clauses that moved the write
    // were added in the same pass. Measured on this branch before the fix, one
    // fixture, one instant, a live 30-minute hold on a $150 seat:
    //
    //   intake form soft-deleted      page  200 closed / no_intake_form
    //                                 quote 200 amount_due 15000, requires TRUE
    //                                 checkout 422 "This offering is not
    //                                       currently accepting registrations."
    //   stripe_charges_enabled false  page  200 closed / org_cannot_collect,
    //                                       fee_plans []
    //                                 quote 200 amount_due 15000, requires TRUE
    //                                 checkout 422 "This organization is not
    //                                       able to accept online payments yet."
    //
    // The matrix is therefore driven from `checkout()`'s own answer rather than
    // from a list of clauses, so a clause added to checkout tomorrow cannot
    // reopen the gap by simply not appearing in this file.

    /**
     * THE PROPERTY, over every program-level state a live hold can be caught
     * in: the quote never promises a payment the checkout refuses, and never
     * withholds one it would allow.
     */
    #[Test]
    public function the_quote_promises_a_payment_exactly_when_the_checkout_would_open_one(): void
    {
        $nothing = fn () => null;

        // [break the program, break the registration, may she pay?]
        $cells = [
            'healthy' => [$nothing, $nothing, true],
            'intake form soft-deleted' => [
                fn (Offering $o) => Form::query()->whereKey($o->intake_form_id)->first()->delete(),
                $nothing,
                false,
            ],
            'org can no longer collect' => [
                fn () => $this->masjid->forceFill(['stripe_charges_enabled' => false])->save(),
                $nothing,
                false,
            ],
            'org connect account removed' => [
                fn () => $this->masjid->forceFill(['stripe_account_id' => null])->save(),
                $nothing,
                false,
            ],
            // The quote 404s outright here (`findOffering()` is active-only), so
            // it promises nothing — which is why the property below is stated
            // over "did it promise", not over "what did the boolean say".
            'offering deactivated' => [
                fn (Offering $o) => $o->forceFill(['is_active' => false])->save(),
                $nothing,
                false,
            ],
            'hold already lapsed' => [
                $nothing,
                fn (Registration $r) => $r->forceFill(['checkout_expires_at' => now()->subMinute()])->save(),
                false,
            ],
            // The two loosenings that must NOT start refusing again: a preview
            // stricter than its write strands an in-flight family, which is the
            // whole reason the offering-level verdict came off this branch.
            'registration deadline passed' => [
                fn (Offering $o) => $o->forceFill(['closes_at' => now()->subMinutes(13)])->save(),
                $nothing,
                true,
            ],
            'plan superseded by the price rise' => [
                fn (Offering $o) => FeePlan::withoutMasjidScope()
                    ->where('offering_id', $o->id)->update(['is_active' => false]),
                $nothing,
                true,
            ],
        ];

        foreach ($cells as $label => [$breakProgram, $breakHold, $payable]) {
            $this->stubSession('cs_m1', 'https://checkout.stripe.test/cs_m1', 'pi_m1');

            [$offering, $plan] = $this->makeOffering();
            $registration = $this->registerThrough($offering, $plan);

            // Her hold is live at this instant.
            $this->assertSame(Registration::STATUS_PENDING, $registration->status, $label);

            $breakProgram($offering);
            $breakHold($registration);

            $quote = $this->postQuote($offering, ['registration_uuid' => $registration->uuid]);
            $checkout = $this->postCheckout($registration);

            $promised = $quote->getStatusCode() === 200
                && $quote->json('data.can_pay_now') === true;
            $opened = $checkout->getStatusCode() === 200;

            // THE PROPERTY, in one line and in both directions: the read never
            // promises a payment the write refuses, and never withholds one the
            // write would allow. Carried by `can_pay_now`, which exists because
            // this promise and the QUESTION OF WHAT SHE OWES are not the same
            // question and were briefly answered by one field — which made a
            // lapsed hold report "$0.00 due, no payment required" while her seat
            // was still held and $150 was still outstanding. See below.
            $this->assertSame($opened, $promised, "{$label}: the quote and the checkout disagree");

            // The fixture actually produced the state it claims to.
            $this->assertSame($payable, $opened, "{$label}: checkout");

            if ($quote->getStatusCode() === 200) {
                // What the place COST is never restated, in either direction —
                // that is the field the removal of the verdict exists to keep
                // answering after the window shuts and after the price rise.
                $this->assertSame(15000, $quote->json('data.adjusted_total_minor'), "{$label}: cost");

                // …and WHAT SHE OWES does not move because a door shut. Every
                // cell here is a live pending hold on a $150 seat with nothing
                // paid, so the debt is $150 in all of them — including the ones
                // she cannot act on this minute. A shut window is not a receipt.
                $this->assertTrue($quote->json('data.requires_payment'), "{$label}: still owed");
                $this->assertSame(15000, $quote->json('data.amount_due_minor'), "{$label}: amount due");

                // This cell used to assert `amount_paid_minor: 0` as well. That
                // field is no longer published to an unauthenticated caller
                // holding only a uuid (M2, `the_anonymous_quote_publishes_no_
                // payment_history`), so what it was really pinning — that a live
                // hold with nothing settled owes the whole $150 — is asserted
                // through the field that remains, one line up.
                $this->assertArrayNotHasKey('amount_paid_minor', $quote->json('data'), "{$label}: no history");
            }

            // Restore for the next cell.
            $this->masjid->forceFill([
                'stripe_account_id' => 'acct_A',
                'stripe_charges_enabled' => true,
            ])->save();
        }
    }

    /** CELL A, spelled out end to end, exactly as it was measured. */
    #[Test]
    public function a_live_hold_under_a_deleted_intake_form_is_not_offered_a_payment(): void
    {
        $this->stubSession('cs_a', 'https://checkout.stripe.test/cs_a', 'pi_a');

        [$offering, ] = $this->makeOffering();
        $registration = $this->registerThrough($offering, FeePlan::withoutMasjidScope()
            ->where('offering_id', $offering->id)->firstOrFail());

        Form::query()->whereKey($offering->intake_form_id)->first()->delete();

        $page = $this->getJson("/api/v1/offerings/{$offering->slug}", [
            'masjid-id' => (string) $this->masjid->id,
        ])->assertOk();

        $this->assertSame('closed', $page->json('data.registration_state'));
        $this->assertSame('no_intake_form', $page->json('data.registration_state_reason'));

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            // Still answered, and still says what her place cost: refusing the
            // read is not the fix, and would strand her worse.
            ->assertJsonPath('data.adjusted_total_minor', 15000)
            // She still owes it — the program stopping is not a receipt…
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.amount_due_minor', 15000)
            // …but no Pay button, because the door the checkout opens is shut.
            ->assertJsonPath('data.can_pay_now', false);

        $this->postCheckout($registration)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This offering is not currently accepting registrations.');
    }

    /** CELL B, likewise. */
    #[Test]
    public function a_live_hold_under_an_org_that_can_no_longer_collect_is_not_offered_a_payment(): void
    {
        $this->stubSession('cs_b', 'https://checkout.stripe.test/cs_b', 'pi_b');

        [$offering, ] = $this->makeOffering();
        $registration = $this->registerThrough($offering, FeePlan::withoutMasjidScope()
            ->where('offering_id', $offering->id)->firstOrFail());

        $this->masjid->forceFill(['stripe_charges_enabled' => false])->save();

        $page = $this->getJson("/api/v1/offerings/{$offering->slug}", [
            'masjid-id' => (string) $this->masjid->id,
        ])->assertOk();

        $this->assertSame('closed', $page->json('data.registration_state'));
        $this->assertSame('org_cannot_collect', $page->json('data.registration_state_reason'));
        $this->assertSame([], $page->json('data.fee_plans'));

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.adjusted_total_minor', 15000)
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.amount_due_minor', 15000)
            ->assertJsonPath('data.can_pay_now', false);

        $this->postCheckout($registration)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organization is not able to accept online payments yet.');
    }

    /**
     * THE OVER-REFUSAL GUARD, and the reason this is `canOpenCheckout()` and
     * not a fresh copy of the offering-level verdict. A CONFIRMED subscription
     * is refused by `checkout()` (`notCheckoutable`) while Stripe genuinely is
     * still expected — it bills on its own clock, and this endpoint is not the
     * door. Mirroring that refusal would tell a family with a live monthly plan
     * that no payment is coming.
     */
    #[Test]
    public function a_running_subscription_still_expects_payment_though_checkout_refuses_it(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->installment(9, 10000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        // The instant after the first invoice settles.
        $registration->forceFill([
            'status' => Registration::STATUS_CONFIRMED,
            'payment_status' => Registration::PAYMENT_ACTIVE,
            'checkout_expires_at' => null,
        ])->save();

        $this->postCheckout($registration)->assertStatus(422);

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.adjusted_total_minor', 90000)
            ->assertJsonPath('data.amount_due_minor', 90000);
    }

    /**
     * A PAYMENT PLAN IS A BALANCE, and `amount_due_minor` has to decrement.
     *
     * Measured before this: a 9 × $100.00 plan quoted `amount_due_minor: 90000`
     * on a Session that charges 10000, and STILL 90000 after three of the nine
     * had settled — nothing subtracted the ledger, so a parent six months and
     * $600 into her plan was told she owed $900. The commitment did not change
     * and is not supposed to: that is `adjusted_total_minor`, which is what the
     * place cost. What was missing is the difference between the two.
     *
     * Only SUCCEEDED rows count. A pending charge has not moved money and a
     * refunded one moved it back, so neither reduces what is outstanding —
     * asserted here because "sum the payments" is the obvious wrong version.
     */
    #[Test]
    public function a_part_paid_installment_plan_owes_the_balance_and_not_the_commitment(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->installment(9, 10000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        $registration->forceFill([
            'status' => Registration::STATUS_CONFIRMED,
            'payment_status' => Registration::PAYMENT_ACTIVE,
            'checkout_expires_at' => null,
        ])->save();

        // Three of nine settled…
        foreach (range(1, 3) as $n) {
            RegistrationPayment::withoutMasjidScope()->create([
                'masjid_id' => $this->masjid->id,
                'registration_id' => $registration->id,
                'amount_minor' => 10000,
                'currency' => 'usd',
                'status' => RegistrationPayment::STATUS_SUCCEEDED,
                'stripe_charge_id' => 'ch_paid_' . $n,
            ]);
        }

        // …one still in flight, and one refunded. Neither is money she has paid.
        RegistrationPayment::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'registration_id' => $registration->id,
            'amount_minor' => 10000,
            'currency' => 'usd',
            'status' => RegistrationPayment::STATUS_PENDING,
            'stripe_charge_id' => 'ch_pending',
        ]);

        RegistrationPayment::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'registration_id' => $registration->id,
            'amount_minor' => 10000,
            'currency' => 'usd',
            'status' => RegistrationPayment::STATUS_REFUNDED,
            'stripe_charge_id' => 'ch_refunded',
        ]);

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            // What it cost, unchanged and never restated.
            ->assertJsonPath('data.adjusted_total_minor', 90000)
            // …so what she owes is $600 — $300 of the $900 has settled, and the
            // pending and refunded rows are not money she has paid. The paid
            // figure itself is no longer published to a link-bearer (M2 below);
            // on an UNAIDED plan like this one it is exactly `adjusted − due`,
            // which is what this pair of numbers demonstrates. (That stops being
            // true once aid leaves a rounding remainder — see
            // `an_aided_installment_plan_owes_exactly_the_sum_of_its_remaining_charges`.)
            ->assertJsonPath('data.amount_due_minor', 60000)
            ->assertJsonPath('data.requires_payment', true);
    }

    // =====================================================================
    // M-F2 — `amount_due_minor` on an OPEN-ENDED plan
    // =====================================================================
    //
    // Round five made the field a balance: `max(0, adjusted_total − amount_paid)`.
    // For `kind: recurring`, `listTotalFor()` returns the PER-INTERVAL amount, so
    // the "total" is one month and the balance hits zero the moment the first
    // invoice settles — and stays there for the life of the subscription,
    // INCLUDING PAST DUE. Measured on the wire, $50/month, driven entirely by
    // signed Stripe webhooks:
    //
    //   month 0 (pending)                 adjusted=5000 due=5000 paid=0
    //   after session.completed           adjusted=5000 due=5000 paid=0
    //   after invoice 1                   adjusted=5000 due=0    paid=5000
    //   after invoice 6                   adjusted=5000 due=0    paid=30000
    //   PAST DUE (invoice.payment_failed) adjusted=5000 due=0    paid=30000
    //
    // The last row is the one a school chasing tuition actually looks at, and it
    // reported that nothing was owed. Not one assertion in the suite named
    // `amount_due_minor` on a recurring plan — every existing one is 15000,
    // 90000, 60000 or 0 — which is why round five's mutation testing did not
    // catch it.
    //
    // AN OPEN-ENDED SUBSCRIPTION HAS NO BALANCE, because it has no finite
    // commitment to take a balance against. What it has is an interval, and at
    // most one of them is ever outstanding. So the field means "the charges that
    // have not been made yet", which for a finite plan is
    // `per-charge × count − paid` and for an open-ended one is one interval,
    // outstanding exactly while the current interval has not settled.

    /**
     * THE MEASURED LIFECYCLE, month by month, every transition driven by a
     * signed webhook rather than by forceFill — because the states this got
     * wrong are exactly the ones Stripe produces and a fixture does not.
     */
    #[Test]
    public function a_recurring_subscription_owes_one_interval_until_that_interval_settles(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->recurring(FeePlan::INTERVAL_MONTH, 5000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        // Month 0 — the hold, before anything has been collected. The first
        // month is owed.
        $this->assertQuote($offering, $registration, ['adjusted' => 5000, 'due' => 5000, 'requires' => true]);

        // The subscription goes live. `checkout.session.completed` moves the
        // money state to `active` on its own, BEFORE any invoice has settled —
        // so "active" alone cannot mean "nothing is outstanding". Until this
        // round, and after it, this row owes the month it has not yet paid.
        $this->postWebhook($this->subscriptionSessionEvent($registration))->assertOk();
        $this->assertQuote($offering, $registration, ['adjusted' => 5000, 'due' => 5000, 'requires' => true]);

        // First invoice settles: the current interval is paid, and the next one
        // is Stripe's to bill on its own clock. Nothing is outstanding NOW.
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();
        $this->assertQuote($offering, $registration, ['adjusted' => 5000, 'due' => 0, 'requires' => true]);

        // …and still nothing, five months in. `requires_payment` stays true
        // because Stripe IS still expected; that is a different question from
        // what is outstanding at this instant, and they get different fields.
        foreach (range(2, 6) as $n) {
            $this->postWebhook($this->invoicePaidEvent($registration, ['id' => "in_{$n}"]))->assertOk();
        }

        $this->assertQuote($offering, $registration, ['adjusted' => 5000, 'due' => 0, 'requires' => true]);
        $this->assertSame(30000, $this->settledMinor($registration), 'Six months at $50 have settled.');
    }

    /**
     * THE HEADLINE. A declined card is the state a school chasing tuition looks
     * at, and it reported that the family owed nothing.
     */
    #[Test]
    public function a_past_due_subscription_owes_the_interval_it_failed_to_collect(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->recurring(FeePlan::INTERVAL_MONTH, 5000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        $this->postWebhook($this->subscriptionSessionEvent($registration))->assertOk();

        foreach (range(1, 6) as $n) {
            $this->postWebhook($this->invoicePaidEvent($registration, ['id' => "in_{$n}"]))->assertOk();
        }

        // Month seven declines. Dunning never touches the seat — only the money
        // state — so this is a paying family whose card expired, not an ejection.
        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_7', 'amount_due' => 5000]))->assertOk();

        $this->assertSame(Registration::PAYMENT_PAST_DUE, $registration->fresh()->payment_status);
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->fresh()->status);

        $this->assertQuote($offering, $registration, [
            'adjusted' => 5000,
            // ONE month, not zero and not six months of history.
            'due' => 5000,
            'requires' => true,
        ]);

        // Stripe's own retry succeeds and the debt clears; this codebase has no
        // dunning engine, so the only thing that may move it is a real invoice.
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_7']))->assertOk();

        $this->assertSame(Registration::PAYMENT_ACTIVE, $registration->fresh()->payment_status);
        $this->assertQuote($offering, $registration, ['adjusted' => 5000, 'due' => 0, 'requires' => true]);
    }

    /**
     * Aid on an open-ended plan reduces THE INTERVAL, because for a recurring
     * plan the snapshot IS one interval — so the outstanding month is the
     * discounted one, and `perChargeMinor()` is the single function that says so
     * for all three paid kinds.
     */
    #[Test]
    public function an_aided_recurring_subscription_owes_the_discounted_interval(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->recurring(FeePlan::INTERVAL_MONTH, 5000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        app(RegistrationService::class)->grantAdjustment(
            $registration,
            RegistrationAdjustment::KIND_AID,
            2000,
            'Hardship — $20 off the monthly'
        );

        $this->assertQuote($offering, $registration->fresh(), [
            'adjusted' => 3000,
            'due' => 3000,
            'requires' => true,
        ]);
    }

    /**
     * A recurring plan quoted with NO registration is a list price, and the
     * first interval is what signing up costs. Unchanged; asserted because the
     * branch above and this one are the two halves of one field.
     */
    #[Test]
    public function a_recurring_list_price_quotes_one_interval(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->recurring(FeePlan::INTERVAL_MONTH, 5000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $this->postQuote($offering, ['fee_plan_id' => $plan->id])
            ->assertOk()
            ->assertJsonPath('data.fee_plan_kind', FeePlan::KIND_RECURRING)
            ->assertJsonPath('data.list_total_minor', 5000)
            ->assertJsonPath('data.adjusted_total_minor', 5000)
            ->assertJsonPath('data.amount_due_minor', 5000)
            ->assertJsonPath('data.requires_payment', true);
    }

    // =====================================================================
    // M-F3 — the balance must not exceed the charges that remain
    // =====================================================================

    /**
     * On an aid-adjusted installment plan the quoted balance used to exceed the
     * sum of the remaining charges by up to N−1 minor units, because it
     * subtracted the ledger from the SNAPSHOT while Stripe is charged
     * `intdiv(snapshot, N)` per instalment. Measured, 9 × $100.00 with 10¢ of
     * aid: adjusted 89990, per-charge 9998, nine charges = 89982, quoted balance
     * 89990 — 8¢ she is told she owes and will never be asked for.
     *
     * Small, self-clearing, and in the payer's favour at the till — which is why
     * it is fixed rather than inflated. The fix is not new arithmetic: it is
     * asking `perChargeMinor()`, the one per-charge function
     * (.claude/rules/registration-billing-data.md), instead of keeping a second
     * opinion beside it.
     */
    #[Test]
    public function an_aided_installment_plan_owes_exactly_the_sum_of_its_remaining_charges(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->installment(9, 10000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        app(RegistrationService::class)->grantAdjustment(
            $registration,
            RegistrationAdjustment::KIND_AID,
            10,
            'A dime, to force the remainder'
        );

        $registration->refresh();

        $perCharge = RegistrationCheckoutService::perChargeMinor($registration, $plan);
        $this->assertSame(9998, $perCharge, 'intdiv(89990, 9) — rounding drops in the payer\'s favour.');

        // The audit trail is untouched: adjusted = list − Σ adjustments.
        $this->assertSame(89990, (int) $registration->adjusted_total_minor);

        $data = $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.adjusted_total_minor', 89990)
            // Nine charges of 9998 is what Stripe will actually be asked for.
            ->assertJsonPath('data.amount_due_minor', 89982)
            ->assertJsonPath('data.requires_payment', true)
            ->json('data');

        // THE CAVEAT THIS FIX CREATES, pinned so the M2 reasoning cannot be
        // restated carelessly: once the remainder is non-zero, `adjusted − due`
        // is NO LONGER what has been paid. Nothing has settled here, and the
        // subtraction says 8¢.
        $this->assertSame(0, $this->settledMinor($registration));
        $this->assertSame(8, $data['adjusted_total_minor'] - $data['amount_due_minor']);

        // …and it decrements by exactly one charge per settled instalment,
        // reaching zero on the ninth rather than leaving an 8¢ tail.
        foreach (range(1, 9) as $n) {
            RegistrationPayment::withoutMasjidScope()->create([
                'masjid_id' => $this->masjid->id,
                'registration_id' => $registration->id,
                'amount_minor' => $perCharge,
                'currency' => 'usd',
                'status' => RegistrationPayment::STATUS_SUCCEEDED,
                'stripe_charge_id' => 'ch_aided_' . $n,
            ]);

            $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
                ->assertOk()
                ->assertJsonPath('data.amount_due_minor', $perCharge * (9 - $n), "after instalment {$n} of 9");
        }
    }

    // =====================================================================
    // M2 — what this endpoint may say to the bearer of a link
    // =====================================================================

    /**
     * `POST /api/v1/offerings/{slug}/quote` takes NO Authorization header and no
     * cookie — a uuid and the `masjid-id` header are the whole credential.
     * Round five added `amount_paid_minor` to it: a cumulative payment-history
     * summary on an anonymous endpoint, in a 60-line docblock that never asked
     * who may read it. Measured with no auth at all:
     *
     *   {"fee_plan_kind":"installment","list_total_minor":90000,
     *    "adjusted_total_minor":45000,"amount_due_minor":40000,
     *    "amount_paid_minor":5000,"requires_payment":true}
     *
     * The uuid is an unguessable v4, but it is a BEARER token that lives in
     * payment-link URLs, in forwarded emails, in browser history and in referrer
     * headers. "This family is on financial aid and is $400 behind" should not
     * be answerable to whoever ends up holding the link.
     *
     * The field is removed rather than gated, and how big a reduction that is
     * was MEASURED rather than assumed — the first draft of this reasoning
     * claimed `paid` is always `adjusted − due` for a finite plan, which is
     * false once aid leaves a rounding remainder:
     *
     *   9 × $100, no aid, 3 settled    adjusted 90000 due 60000  → 30000 = paid
     *   9 × $100, 10¢ aid, 0 settled   adjusted 89990 due 89982  → 8     ≠ 0
     *   9 × $100, 10¢ aid, 4 settled   adjusted 89990 due 49990  → 40000 ≠ 39992
     *
     * because `amount_due_minor` is `per-charge × N − paid` and the payload
     * publishes neither the per-charge amount nor N. So:
     *
     *  - In the ORDINARY case — no aid, or aid that divides evenly — the field
     *    added nothing a link-bearer could not already compute, and a renderer
     *    can still compute it (`a_part_paid_installment_plan…` asserts that
     *    subtraction is exact there).
     *  - On an AID-ADJUSTED plan, and on an OPEN-ENDED subscription where
     *    `amount_due_minor` is one interval rather than a balance, it carried a
     *    number nothing else carries — the one with the longest memory: how many
     *    months this family has been paying, or has not.
     *  - Nothing in this repository renders it.
     *
     * So the anonymous quote answers what the place cost, what is outstanding,
     * whether payment is expected and whether the door is open. A payment
     * HISTORY belongs to the authenticated family portal, which knows who is
     * asking. This restores the pre-round-five disclosure set on an
     * unauthenticated money surface days before a real school is provisioned
     * onto it.
     */
    #[Test]
    public function the_anonymous_quote_publishes_no_payment_history(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->recurring(FeePlan::INTERVAL_MONTH, 5000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        $this->postWebhook($this->subscriptionSessionEvent($registration))->assertOk();

        foreach (range(1, 6) as $n) {
            $this->postWebhook($this->invoicePaidEvent($registration, ['id' => "in_{$n}"]))->assertOk();
        }

        // Six months of history exists locally…
        $this->assertSame(30000, $this->settledMinor($registration));

        // …and no part of it is on the wire to a caller holding only the uuid.
        $data = $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey(
            'amount_paid_minor',
            $data,
            'A bearer of a payment link must not be handed the family\'s payment history.'
        );

        // Everything the surface is actually for is still answered.
        $this->assertSame(5000, $data['adjusted_total_minor']);
        $this->assertSame(0, $data['amount_due_minor']);
        $this->assertTrue($data['requires_payment']);
    }

    /** No Authorization header is needed to reach any of this — stated, not assumed. */
    #[Test]
    public function the_quote_is_reachable_with_no_credential_but_the_uuid(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $this->assertGuest();

        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])->assertOk();
    }

    /**
     * M2. `amount_due_minor` answered "what it cost" under a name that says
     * "what you owe". Measured: a settled registration and a cancelled one both
     * quoted `amount_due_minor: 15000`, and the aid-floored installment quoted
     * `amount_due_minor: 5` — the 5¢ the controller's own docblock calls a
     * number nobody would ever ask her for.
     */
    #[Test]
    public function nothing_is_owed_on_a_registration_that_owes_nothing(): void
    {
        [$offering, $plan] = $this->makeOffering();

        $settled = $this->registerThrough($offering, $plan);
        $settled->forceFill([
            'payment_status' => Registration::PAYMENT_PAID,
            'status' => Registration::STATUS_CONFIRMED,
            'checkout_expires_at' => null,
        ])->save();

        $cancelled = $this->registerThrough($offering, $plan);
        $cancelled->forceFill([
            'status' => Registration::STATUS_CANCELLED,
            'payment_status' => Registration::PAYMENT_CANCELED,
            'checkout_expires_at' => null,
        ])->save();

        foreach (['settled' => $settled, 'cancelled' => $cancelled] as $label => $registration) {
            $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
                ->assertOk()
                // The cost is never restated — it moved to the field that says
                // cost, and it is still on the wire.
                ->assertJsonPath('data.list_total_minor', 15000)
                ->assertJsonPath('data.adjusted_total_minor', 15000)
                ->assertJsonPath('data.requires_payment', false)
                ->assertJsonPath('data.amount_due_minor', 0, "{$label}: nothing is owed");
        }
    }

    /** The 5¢, named. */
    #[Test]
    public function the_aid_floored_installment_is_never_asked_for_five_cents(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->installment(9, 10000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        app(RegistrationService::class)->grantAdjustment(
            $registration,
            RegistrationAdjustment::KIND_AID,
            89995,
            'Near-total waiver'
        );

        $this->postQuote($offering, ['registration_uuid' => $registration->fresh()->uuid])
            ->assertOk()
            ->assertJsonPath('data.requires_payment', false)
            // The audit trail is untouched…
            ->assertJsonPath('data.adjusted_total_minor', 5)
            // …and nobody is invoiced for a nickel.
            ->assertJsonPath('data.amount_due_minor', 0);
    }

    /**
     * M3. `fee_plan_id` became `required_without:registration_uuid`, so a quote
     * carrying only a uuid that does not resolve now validates and falls through
     * to the list-price branch with `(int) null = 0`. Measured: 404 "This fee
     * plan is not available." — a parent with a stale payment link told about a
     * noun she was never shown.
     */
    #[Test]
    public function a_stale_payment_link_is_told_about_the_registration_not_the_fee_plans(): void
    {
        [$offering, $plan] = $this->makeOffering();

        $foreignOffering = Offering::factory()->forMasjid($this->otherMasjid)->create();
        $foreignPlan = FeePlan::factory()->create([
            'masjid_id' => $this->otherMasjid->id,
            'offering_id' => $foreignOffering->id,
        ]);
        $foreign = app(RegistrationService::class)->register(
            $foreignOffering,
            $foreignPlan,
            Contact::factory()->create(['masjid_id' => $this->otherMasjid->id]),
            ['full_name' => 'Foreign']
        );

        // ONE answer for every non-resolving uuid — a typo, another tenant's,
        // another program's — so nothing here tells a caller which it was.
        $bodies = [];

        foreach (['not-a-real-uuid', $foreign->uuid, (string) Str::uuid()] as $uuid) {
            $response = $this->postQuote($offering, ['registration_uuid' => $uuid])
                ->assertStatus(404)
                ->assertJsonPath('message', 'This registration is not available.');

            $bodies[] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'a caller must not learn which kind of miss this was');

        // Naming a plan as well still prices that plan: there IS something to
        // answer then, which is what a page showing "here is what it costs"
        // asks for.
        $this->postQuote($offering, [
            'fee_plan_id' => $plan->id,
            'registration_uuid' => 'not-a-real-uuid',
        ])
            ->assertOk()
            ->assertJsonPath('data.fee_plan_id', $plan->id)
            ->assertJsonPath('data.amount_due_minor', 15000);
    }

    /** NEW intake is unchanged: a deactivated plan is still a 404. */
    #[Test]
    public function a_deactivated_plan_is_still_refused_to_new_intake(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $plan->forceFill(['is_active' => false])->save();

        $this->postQuote($offering, ['fee_plan_id' => $plan->id])->assertStatus(404);

        $this->postRegister($offering, [
            'fee_plan_id' => $plan->id,
            'payer' => ['email' => 'x@example.test'],
            'data' => ['full_name' => 'X'],
        ])->assertStatus(404);
    }

    /** A quote with neither a plan nor a registration is still a 422. */
    #[Test]
    public function the_quote_still_requires_a_plan_when_no_registration_is_named(): void
    {
        [$offering] = $this->makeOffering();

        $this->postQuote($offering, [])->assertStatus(422);
    }

    /** Another tenant's uuid resolves to nothing and does not become a price. */
    #[Test]
    public function the_quote_ignores_a_foreign_registration_uuid(): void
    {
        [$offering, $plan] = $this->makeOffering();

        $foreignOffering = Offering::factory()->forMasjid($this->otherMasjid)->create();
        $foreignPlan = FeePlan::factory()->create([
            'masjid_id' => $this->otherMasjid->id,
            'offering_id' => $foreignOffering->id,
        ]);
        $foreign = app(RegistrationService::class)->register(
            $foreignOffering,
            $foreignPlan,
            Contact::factory()->create(['masjid_id' => $this->otherMasjid->id]),
            ['full_name' => 'Foreign']
        );

        // Falls back to the named plan's list price, as before.
        $this->postQuote($offering, [
            'fee_plan_id' => $plan->id,
            'registration_uuid' => $foreign->uuid,
        ])
            ->assertOk()
            ->assertJsonPath('data.fee_plan_id', $plan->id)
            ->assertJsonPath('data.amount_due_minor', 15000);

        // …and with no plan named at all there is simply nothing to price:
        // never another tenant's snapshot, and never an internal invariant
        // sentence. The WORDS are the sibling endpoint's — a miss on a uuid is
        // reported as a miss on a registration, not as a fact about fee plans
        // (M3) — and every non-resolving uuid gets the same one, which is what
        // `a_stale_payment_link_is_told_about_the_registration_not_the_fee_plans`
        // pins.
        $this->postQuote($offering, ['registration_uuid' => $foreign->uuid])
            ->assertStatus(404)
            ->assertJsonPath('message', 'This registration is not available.');
    }

    // =====================================================================
    // F3 — closes_at is a NEW-registration clause
    // =====================================================================

    /**
     * Registration closes at midnight, she registers at 11:52pm, gets a
     * 30-minute hold, finds her card, returns at 12:05am.
     */
    #[Test]
    public function checkout_still_works_after_the_registration_deadline_passes(): void
    {
        $this->stubSession('cs_3', 'https://checkout.stripe.test/cs_3', 'pi_3');

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $offering->forceFill(['closes_at' => now()->subMinutes(13)])->save();

        $this->postCheckout($registration)
            ->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/cs_3');
    }

    #[Test]
    public function checkout_still_works_when_opens_at_is_moved_forward(): void
    {
        $this->stubSession('cs_4', 'https://checkout.stripe.test/cs_4', 'pi_4');

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $offering->forceFill(['opens_at' => now()->addWeek()])->save();

        $this->postCheckout($registration)->assertOk();
    }

    /** The clause that IS about the program, kept. */
    #[Test]
    public function checkout_still_refuses_a_deactivated_offering(): void
    {
        $this->stubSession('cs_5', 'https://checkout.stripe.test/cs_5', 'pi_5');

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $offering->forceFill(['is_active' => false])->save();

        $this->postCheckout($registration)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This offering is not currently accepting registrations.');
    }

    /** Argued and kept last round. */
    #[Test]
    public function checkout_still_refuses_when_the_intake_form_is_gone(): void
    {
        $this->stubSession('cs_6', 'https://checkout.stripe.test/cs_6', 'pi_6');

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        Form::query()->whereKey($offering->intake_form_id)->first()->delete();

        $this->postCheckout($registration)->assertStatus(422);
    }

    /** An expired hold is still dead, not late. */
    #[Test]
    public function checkout_still_refuses_an_expired_hold(): void
    {
        $this->stubSession('cs_7', 'https://checkout.stripe.test/cs_7', 'pi_7');

        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        $registration->forceFill(['checkout_expires_at' => now()->subMinute()])->save();

        $this->postCheckout($registration)->assertStatus(422);
    }

    /** NEW intake after the deadline is still refused — the clause still works. */
    #[Test]
    public function new_intake_is_still_refused_after_the_deadline(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $offering->forceFill(['closes_at' => now()->subDay()])->save();

        $this->postRegister($offering, [
            'fee_plan_id' => $plan->id,
            'payer' => ['email' => 'late@example.test'],
            'data' => ['full_name' => 'Late'],
        ])->assertStatus(422);
    }

    // =====================================================================
    // F4 — aid that crosses the total under the installment count
    // =====================================================================

    /**
     * MEASURED: installment 9 x 10000, grantAdjustment(aid, 89995)
     *   -> adjusted = 5, admin sees success
     *   -> checkout 422 nothingToCharge
     *   -> seat 1, checkout_expires_at still set -> reaped 46 minutes later.
     *
     * `perChargeMinor()` is intdiv(5, 9) = 0. The rounding doctrine drops the
     * remainder in the payer's favour; when the WHOLE total is smaller than the
     * count, the whole total is dropped, which is the free-path carve-out.
     */
    #[Test]
    public function aid_leaving_under_one_minor_unit_per_installment_takes_the_free_path(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->installment(9, 10000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);
        $this->assertSame(90000, (int) $registration->list_total_minor);

        app(RegistrationService::class)->grantAdjustment(
            $registration,
            RegistrationAdjustment::KIND_AID,
            89995,
            'Near-total waiver'
        );

        $registration->refresh();

        // No Stripe leg is left standing…
        $this->assertSame(Registration::PAYMENT_NONE, $registration->payment_status);
        $this->assertNull($registration->checkout_expires_at, 'the reaper must have nothing to sweep');
        $this->assertNull($registration->idempotency_key);
        // …and the seat is confirmed rather than held on a link that 422s.
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(1, $offering->fresh()->registration_count);

        // The audit trail is untouched: adjusted = list − Σ adjustments.
        $this->assertSame(5, (int) $registration->adjusted_total_minor);

        // And the surface that tells her what she owes agrees with the money
        // state rather than with the leftover total.
        $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->assertJsonPath('data.requires_payment', false);
    }

    /** Aid that still leaves a chargeable installment is unaffected. */
    #[Test]
    public function aid_leaving_a_chargeable_installment_still_expects_payment(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->installment(9, 10000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        app(RegistrationService::class)->grantAdjustment(
            $registration,
            RegistrationAdjustment::KIND_AID,
            45000,
            'Half aid'
        );

        $registration->refresh();

        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertNotNull($registration->checkout_expires_at);
        $this->assertSame(5000, RegistrationCheckoutService::perChargeMinor($registration->fresh(), $plan));
    }

    /** The one-time path is unchanged: only a total of 0 is the free path. */
    #[Test]
    public function a_one_time_registration_with_a_penny_left_still_expects_payment(): void
    {
        [$offering, $plan] = $this->makeOffering();
        $registration = $this->registerThrough($offering, $plan);

        app(RegistrationService::class)->grantAdjustment(
            $registration,
            RegistrationAdjustment::KIND_AID,
            14999,
            'Almost all of it'
        );

        $registration->refresh();

        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertSame(1, (int) $registration->adjusted_total_minor);
    }

    /**
     * The same arithmetic reaches promotion: a waitlisted row may carry an
     * adjustment, and promoting it used to hand out a pending seat that could
     * never be paid for.
     */
    #[Test]
    public function promoting_a_waitlisted_registration_under_one_unit_per_installment_confirms_it(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->atCapacity(1)->create();
        $plan = FeePlan::factory()->installment(9, 10000)->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);
        $this->assertSame(Registration::STATUS_WAITLISTED, $registration->status);

        app(RegistrationService::class)->grantAdjustment(
            $registration,
            RegistrationAdjustment::KIND_AID,
            89995,
            'Near-total waiver'
        );

        $offering->forceFill(['capacity' => 5])->save();

        app(RegistrationService::class)->promoteFromWaitlist($registration->fresh());

        $registration->refresh();

        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_NONE, $registration->payment_status);
        $this->assertNull($registration->checkout_expires_at);
    }

    // =====================================================================
    // F5 — a paid kind must charge more than zero
    // =====================================================================

    /**
     * MEASURED: one_time / recurring / installment at amount 0 all read `open`,
     * publish $0.00, quote 200, and register CONFIRMED FREE with a seat.
     *
     * `StoreFeePlanRequest` already says the missing clause in words: "A paid
     * plan must charge more than zero; a $0 charge is the free plan instead."
     */
    #[Test]
    public function a_paid_plan_that_charges_nothing_is_not_purchasable(): void
    {
        foreach ([
            'one_time' => FeePlan::factory(),
            'recurring' => FeePlan::factory()->recurring(),
            'installment' => FeePlan::factory()->installment(),
        ] as $label => $factory) {
            $offering = Offering::factory()->forMasjid($this->masjid)->create();
            $plan = $factory->create([
                'masjid_id' => $this->masjid->id,
                'offering_id' => $offering->id,
                'amount_minor' => 0,
            ]);

            $this->assertFalse(
                OfferingRegistrationState::isPurchasable($plan, true),
                "a {$label} plan charging nothing must not be purchasable"
            );

            $payload = OfferingPublicPayload::forSlug($this->masjid->id, $offering->slug);

            $this->assertSame([], $payload['fee_plans'], "{$label}: no price is published");
            $this->assertSame(OfferingPublicPayload::STATE_CLOSED, $payload['registration_state']);
            $this->assertSame(
                OfferingRegistrationState::REASON_NO_FEE_PLAN,
                $payload['registration_state_reason']
            );

            $this->postQuote($offering, ['fee_plan_id' => $plan->id])->assertStatus(404);

            $this->postRegister($offering, [
                'fee_plan_id' => $plan->id,
                'payer' => ['email' => "zero-{$label}@example.test"],
                'data' => ['full_name' => 'Zero'],
            ])->assertStatus(404);

            $this->assertSame(0, $offering->fresh()->registration_count);
        }
    }

    /**
     * The row shape the predicate deliberately does NOT refuse: a `free` plan
     * carrying a stale non-zero amount. It confirms in-request and is never
     * charged — but the page must not print "$150" beside `total_minor: 0`.
     */
    #[Test]
    public function a_free_plan_with_a_stale_amount_is_still_sellable_and_publishes_no_price(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);
        // Only a manual edit / import can produce this.
        $plan->forceFill(['amount_minor' => 15000])->save();

        $this->assertTrue(OfferingRegistrationState::isPurchasable($plan->fresh(), true));

        $payload = OfferingPublicPayload::forSlug($this->masjid->id, $offering->slug);

        $this->assertSame(OfferingPublicPayload::STATE_OPEN, $payload['registration_state']);
        $this->assertSame(0, $payload['fee_plans'][0]['amount_minor'], 'no amount travels that is not charged');
        $this->assertSame(0, $payload['fee_plans'][0]['total_minor']);
        $this->assertFalse($payload['fee_plans'][0]['requires_payment']);

        $this->postRegister($offering, [
            'fee_plan_id' => $plan->id,
            'payer' => ['email' => 'stale@example.test'],
            'data' => ['full_name' => 'Stale'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Registration::STATUS_CONFIRMED)
            ->assertJsonPath('data.amount_due_minor', 0);
    }

    /** A real free plan and a real paid plan are untouched. */
    #[Test]
    public function ordinary_plans_stay_purchasable(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();

        $free = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id, 'offering_id' => $offering->id,
        ]);
        $paid = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id, 'offering_id' => $offering->id,
        ]);

        $this->assertTrue(OfferingRegistrationState::isPurchasable($free, true));
        $this->assertTrue(OfferingRegistrationState::isPurchasable($paid, true));
    }

    // =====================================================================
    // F6 — the two documentation-vs-wire drifts
    // =====================================================================

    /**
     * The sentence "a price is never published for a plan this same payload's
     * `registration_state` declares unbuyable" was false for three states, and
     * the BEHAVIOUR is the defensible half: "opens 4 September, $150" needs the
     * price. The true claim is per PLAN, so this pins both halves of it.
     */
    #[Test]
    public function a_window_that_is_merely_shut_still_publishes_the_price(): void
    {
        foreach ([
            'not_yet_open' => ['opens_at' => now()->addMonth()],
            'closed' => ['closes_at' => now()->subDay()],
        ] as $reason => $state) {
            [$offering] = $this->makeOffering($state);

            $payload = OfferingPublicPayload::forSlug($this->masjid->id, $offering->slug);

            $this->assertSame(OfferingPublicPayload::STATE_CLOSED, $payload['registration_state']);
            $this->assertSame($reason, $payload['registration_state_reason']);
            // Deliberate: a page that says "opens 4 September" needs the price.
            $this->assertSame(15000, $payload['fee_plans'][0]['amount_minor']);
        }
    }

    /**
     * The half of that sentence that IS true, and is the one worth pinning: a
     * verdict of `no_fee_plan` / `org_cannot_collect` comes from the SAME
     * per-plan predicate that filters the list, so those two can never be
     * reported beside a published price.
     */
    #[Test]
    public function a_plan_level_refusal_never_publishes_a_price(): void
    {
        $poor = $this->makeMasjid(['stripe_account_id' => null, 'stripe_charges_enabled' => false]);

        $offering = Offering::factory()->forMasjid($poor)->create();
        FeePlan::factory()->create(['masjid_id' => $poor->id, 'offering_id' => $offering->id]);

        $payload = OfferingPublicPayload::forSlug($poor->id, $offering->slug);

        $this->assertSame(OfferingPublicPayload::STATE_CLOSED, $payload['registration_state']);
        $this->assertSame(
            OfferingRegistrationState::REASON_ORG_CANNOT_COLLECT,
            $payload['registration_state_reason']
        );
        $this->assertSame([], $payload['fee_plans']);
    }

    /**
     * `decide()` reads `null` as NOT ASKED and `false` as CANNOT COLLECT, and
     * the difference is a green "Open" versus `org_cannot_collect`. The admin
     * surface read `$offering->masjid?->canAcceptDonations()` — a belongsTo
     * respecting SoftDeletes — so an offboarded organisation's miss became
     * "not asked" while the public read 404s.
     */
    #[Test]
    public function not_asked_and_cannot_collect_are_different_verdicts(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create();
        $plan = FeePlan::factory()->make([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $this->assertSame(
            OfferingRegistrationState::STATE_OPEN,
            OfferingRegistrationState::decide($offering, true, [$plan], null)['state']
        );

        $decided = OfferingRegistrationState::decide($offering, true, [$plan], false);
        $this->assertSame(OfferingRegistrationState::STATE_CLOSED, $decided['state']);
        $this->assertSame(OfferingRegistrationState::REASON_ORG_CANNOT_COLLECT, $decided['reason']);
    }

    /**
     * The reachable half of that drift: for a LIVE organisation that cannot
     * collect, the admin verdict and the public one must be the same words.
     */
    #[Test]
    public function the_admin_verdict_matches_the_public_one_when_the_org_cannot_collect(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $poor = $this->makeMasjid(['stripe_account_id' => null, 'stripe_charges_enabled' => false]);
        $admin = $this->makeAdminFor($poor);

        $offering = Offering::factory()->forMasjid($poor)->create();
        FeePlan::factory()->create(['masjid_id' => $poor->id, 'offering_id' => $offering->id]);

        $public = OfferingPublicPayload::forSlug($poor->id, $offering->slug);

        Sanctum::actingAs($admin);

        $row = $this->getJson('/api/admin/masjids/' . $poor->id . '/offerings')
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame($public['registration_state'], $row['registration_state']);
        $this->assertSame($public['registration_state_reason'], $row['registration_state_reason']);
        $this->assertSame(0, $row['active_fee_plan_count']);
    }

    // ------------------------------------------------------------- fixtures

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

    /** @return array{0: User, 1: Offering} */
    private function adminWithRosterOffering(): array
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = $this->makeAdminFor($this->masjid);
        $offering = $this->rosterOffering();

        return [$admin, $offering];
    }

    private function groupsUrl(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjid)->id . '/groups';
    }

    private function rosterOffering(array $state = []): Offering
    {
        return Offering::factory()
            ->forMasjid($this->masjid)
            ->withRoster($this->group)
            ->create($state + ['name' => 'Weekend School 2026']);
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

    private function registerThrough(Offering $offering, FeePlan $plan): Registration
    {
        return app(RegistrationService::class)->register(
            $offering,
            $plan,
            Contact::factory()->create(['masjid_id' => $this->masjid->id]),
            ['full_name' => 'Amal Yusuf']
        );
    }

    /** Pending + awaiting, session id recorded — the instant after checkout. */
    private function paidPending(Offering $offering): Registration
    {
        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->registerThrough($offering, $plan);

        $registration->forceFill(['stripe_checkout_session_id' => 'cs_reg_1'])->save();

        return $registration;
    }

    private function stubSession(string $id, string $url, ?string $paymentIntent): void
    {
        $service = Mockery::mock(RegistrationCheckoutService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('createCheckoutSession')
            ->andReturn(['id' => $id, 'url' => $url, 'payment_intent' => $paymentIntent]);

        $this->app->instance(RegistrationCheckoutService::class, $service);
    }

    private function postQuote(Offering $offering, array $body): TestResponse
    {
        return $this->postJson(
            "/api/v1/offerings/{$offering->slug}/quote",
            $body,
            ['masjid-id' => (string) $this->masjid->id]
        );
    }

    private function postRegister(Offering $offering, array $body): TestResponse
    {
        return $this->postJson(
            "/api/v1/offerings/{$offering->slug}/register",
            $body,
            ['masjid-id' => (string) $this->masjid->id]
        );
    }

    private function postCheckout(Registration $registration): TestResponse
    {
        return $this->postJson(
            "/api/v1/registrations/{$registration->uuid}/checkout",
            [],
            ['masjid-id' => (string) $registration->masjid_id]
        );
    }

    private function postWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->platformSecret);

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [], [], [],
            [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        );
    }

    /**
     * One quote, three numbers. Written as a helper because M-F2 is a sequence
     * of states rather than a single cell, and the sequence is the evidence.
     *
     * @param  array{adjusted:int,due:int,requires:bool}  $expected
     */
    private function assertQuote(Offering $offering, Registration $registration, array $expected): void
    {
        $data = $this->postQuote($offering, ['registration_uuid' => $registration->uuid])
            ->assertOk()
            ->json('data');

        $this->assertSame($expected['adjusted'], $data['adjusted_total_minor'], 'adjusted_total_minor');
        $this->assertSame($expected['due'], $data['amount_due_minor'], 'amount_due_minor');
        $this->assertSame($expected['requires'], $data['requires_payment'], 'requires_payment');
    }

    /** What the ledger says has actually settled. */
    private function settledMinor(Registration $registration): int
    {
        return (int) RegistrationPayment::withoutMasjidScope()
            ->where('registration_id', $registration->id)
            ->where('status', RegistrationPayment::STATUS_SUCCEEDED)
            ->sum('amount_minor');
    }

    /** `checkout.session.completed` in SUBSCRIPTION mode — books no payment row. */
    private function subscriptionSessionEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'checkout.session.completed',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => $o['id'] ?? 'cs_sub_1',
                'object' => 'checkout.session',
                'mode' => 'subscription',
                'payment_status' => 'paid',
                'status' => 'complete',
                'subscription' => $o['subscription'] ?? 'sub_reg_1',
                'client_reference_id' => $registration->uuid,
                'metadata' => ['registration_uuid' => $registration->uuid],
            ]],
        ];
    }

    /** Our uuid rides on the SUBSCRIPTION's metadata, not the invoice's own. */
    private function invoicePaidEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'invoice.payment_succeeded',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => $o['id'] ?? 'in_1',
                'object' => 'invoice',
                'amount_paid' => $o['amount_paid'] ?? 5000,
                'subscription' => $o['subscription'] ?? 'sub_reg_1',
                'subscription_details' => [
                    'metadata' => ['registration_uuid' => $registration->uuid],
                ],
            ]],
        ];
    }

    private function invoiceFailedEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'invoice.payment_failed',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => $o['id'] ?? 'in_1',
                'object' => 'invoice',
                'amount_due' => $o['amount_due'] ?? 5000,
                'amount_paid' => 0,
                'subscription' => $o['subscription'] ?? 'sub_reg_1',
                'subscription_details' => [
                    'metadata' => ['registration_uuid' => $registration->uuid],
                ],
            ]],
        ];
    }

    private function completedEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'checkout.session.completed',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => 'cs_reg_1',
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'status' => 'complete',
                'amount_total' => 15000,
                'payment_intent' => 'pi_reg_1',
                'client_reference_id' => $registration->uuid,
                'metadata' => ['registration_uuid' => $registration->uuid],
            ]],
        ];
    }
}
