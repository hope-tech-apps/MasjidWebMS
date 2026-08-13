<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\FeePlan;
use App\Models\Fund;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\RegistrationPayment;
use App\Services\Registrations\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-006c — the webhook half, which is the ONLY thing that may advance a
 * registration to paid/confirmed.
 *
 * Every invariant this file pins is a doctrine invariant, not a nicety:
 *
 *  - ORDER INDEPENDENCE. `checkout.session.completed` and
 *    `payment_intent.succeeded` both fire for one one-time charge. Either
 *    order, both permutations, must converge on ONE confirmed registration,
 *    ONE registration_payments row, and ONE roster materialisation.
 *  - REPLAY. The same event id twice changes nothing (dedup on
 *    stripe_webhook_events).
 *  - THE BROWSER NEVER DECIDES. An unsigned/badly-signed payload is rejected
 *    and leaves the registration exactly as it was.
 *  - EXPIRY RELEASES EXACTLY ONE SEAT, idempotently, and never a seat that has
 *    been paid for or a session that has been superseded.
 *  - TENANCY COMES FROM `event.account`. Masjid A's connected account can never
 *    advance masjid B's registration — metadata alone decides nothing.
 *  - DISPATCH SAFETY. Donation events still book donations; registration events
 *    never create a Donation. (DonationFlowTest pins the donation side in full
 *    and must pass untouched.)
 *
 * Signature verification is REAL: every payload is signed exactly as Stripe
 * signs, against either the PLATFORM or the CONNECT secret. No Stripe API call
 * happens anywhere in this file.
 */
class RegistrationWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $platformSecret = 'whsec_platform_secret';

    private string $connectSecret = 'whsec_connect_secret';

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
            'services.stripe.connect_webhook_secret' => $this->connectSecret,
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.currency' => 'usd',
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
        ]);

        $this->masjid = $this->makeMasjid(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $this->otherMasjid = $this->makeMasjid(['stripe_account_id' => 'acct_B', 'stripe_charges_enabled' => true]);
        $this->group = Group::factory()->create(['masjid_id' => $this->masjid->id]);
    }

    // ------------------------------------------------- single-event success

    #[Test]
    public function checkout_completed_confirms_the_seat_and_books_one_payment(): void
    {
        $registration = $this->paidPending();

        $this->postWebhook($this->completedEvent($registration))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);
        // The seat is no longer time-boxed, so the reaper must not see it.
        $this->assertNull($registration->checkout_expires_at);

        $payments = RegistrationPayment::withoutMasjidScope()
            ->where('registration_id', $registration->id)->get();

        $this->assertCount(1, $payments);
        $this->assertSame(15000, $payments[0]->amount_minor);
        $this->assertSame(RegistrationPayment::STATUS_SUCCEEDED, $payments[0]->status);
        $this->assertSame($this->masjid->id, $payments[0]->masjid_id);
        $this->assertNotNull($payments[0]->paid_at);

        // The paid path converges on the SAME roster materialisation the free
        // path uses — participant row for the registrant, no self-guardian edge.
        $this->assertSame(1, GroupMembership::withoutMasjidScope()->where('group_id', $this->group->id)->count());
    }

    #[Test]
    public function payment_intent_succeeded_alone_confirms_and_records_the_balance_transaction(): void
    {
        $registration = $this->paidPending();

        $this->postWebhook($this->succeededEvent($registration))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);

        $payment = RegistrationPayment::withoutMasjidScope()
            ->where('registration_id', $registration->id)->firstOrFail();

        $this->assertSame('ch_reg_1', $payment->stripe_charge_id);
        $this->assertSame('txn_reg_1', $payment->stripe_balance_transaction_id);
        $this->assertSame(465, $payment->stripe_fee_minor);
        $this->assertSame(14535, $payment->net_minor);
    }

    #[Test]
    public function an_unpaid_completion_records_the_session_but_advances_nothing(): void
    {
        $registration = $this->paidPending();

        $this->postWebhook($this->completedEvent($registration, [
            'payment_status' => 'unpaid',
            'status' => 'open',
            'id' => 'cs_async_1',
        ]))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    // --------------------------------------------------- order independence

    #[Test]
    public function completed_then_succeeded_converge_to_one_confirmation_and_one_payment(): void
    {
        $registration = $this->paidPending();

        $this->postWebhook($this->completedEvent($registration))->assertOk();
        $this->postWebhook($this->succeededEvent($registration))->assertOk();

        $this->assertConverged($registration);
    }

    #[Test]
    public function succeeded_then_completed_converge_to_one_confirmation_and_one_payment(): void
    {
        $registration = $this->paidPending();

        // The exact same two events in the OPPOSITE order.
        $this->postWebhook($this->succeededEvent($registration))->assertOk();
        $this->postWebhook($this->completedEvent($registration))->assertOk();

        $this->assertConverged($registration);
    }

    #[Test]
    public function replaying_the_same_event_id_changes_nothing(): void
    {
        $registration = $this->paidPending();
        $event = $this->completedEvent($registration, ['event_id' => 'evt_reg_dup']);

        $this->postWebhook($event)->assertOk();
        $paidAt = RegistrationPayment::withoutMasjidScope()->firstOrFail()->paid_at;

        $this->postWebhook($event)->assertOk();   // duplicate delivery

        $this->assertSame(1, RegistrationPayment::withoutMasjidScope()->count());
        $this->assertEquals($paidAt, RegistrationPayment::withoutMasjidScope()->firstOrFail()->paid_at);
        $this->assertSame(1, GroupMembership::withoutMasjidScope()->count());
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->fresh()->status);
    }

    // ------------------------------------------------------- the trust gate

    #[Test]
    public function an_unsigned_completed_event_is_rejected_and_confirms_nothing(): void
    {
        $registration = $this->paidPending();
        $payload = json_encode($this->completedEvent($registration));

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertStatus(401);

        $registration->refresh();
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    #[Test]
    public function an_event_signed_with_the_connect_secret_is_accepted(): void
    {
        $registration = $this->paidPending();

        // Connected-account events arrive on the CONNECT endpoint, which signs
        // with its own secret.
        $this->postWebhook($this->completedEvent($registration), $this->connectSecret)->assertOk();

        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->fresh()->status);
    }

    #[Test]
    public function the_webhook_fails_closed_when_no_secret_is_configured(): void
    {
        config([
            'services.stripe.webhook_secret' => null,
            'services.stripe.connect_webhook_secret' => null,
        ]);

        $registration = $this->paidPending();

        $this->postWebhook($this->completedEvent($registration))->assertStatus(401);

        $this->assertSame(Registration::STATUS_PENDING, $registration->fresh()->status);
    }

    // ------------------------------------------------------------- tenancy

    #[Test]
    public function another_tenants_connected_account_cannot_advance_a_registration(): void
    {
        $registration = $this->paidPending();

        // Masjid A's registration uuid, delivered on masjid B's account.
        $this->postWebhook($this->completedEvent($registration, ['account' => 'acct_B']))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    #[Test]
    public function an_event_from_an_unknown_connected_account_is_ignored(): void
    {
        $registration = $this->paidPending();

        $this->postWebhook($this->completedEvent($registration, ['account' => 'acct_nobody']))->assertOk();

        $this->assertSame(Registration::STATUS_PENDING, $registration->fresh()->status);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    // ------------------------------------------------------- seat release

    #[Test]
    public function expiry_releases_exactly_one_seat_and_a_second_delivery_is_a_no_op(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->paidPending($offering);

        $this->assertSame(1, $offering->fresh()->registration_count);

        $this->postWebhook($this->expiredEvent($registration, ['event_id' => 'evt_exp_1']))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CANCELLED, $registration->status);
        $this->assertSame(Registration::PAYMENT_CANCELED, $registration->payment_status);
        $this->assertSame(0, $offering->fresh()->registration_count);

        // A second expired for the same session releases NOTHING further —
        // the counter can never go negative or double-return a seat.
        $this->postWebhook($this->expiredEvent($registration, ['event_id' => 'evt_exp_2']))->assertOk();

        $this->assertSame(0, $offering->fresh()->registration_count);
        $this->assertSame(Registration::STATUS_CANCELLED, $registration->fresh()->status);
    }

    #[Test]
    public function a_superseded_session_expiry_does_not_release_a_live_seat(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->paidPending($offering);

        // The registrant re-minted: cs_live is current, cs_stale abandoned.
        $registration->forceFill(['stripe_checkout_session_id' => 'cs_live'])->save();

        $this->postWebhook($this->expiredEvent($registration, ['id' => 'cs_stale']))->assertOk();

        $this->assertSame(Registration::STATUS_PENDING, $registration->fresh()->status);
        $this->assertSame(1, $offering->fresh()->registration_count);
    }

    #[Test]
    public function expiry_never_releases_a_seat_that_has_been_paid_for(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->paidPending($offering);

        $this->postWebhook($this->completedEvent($registration))->assertOk();
        // Out-of-order straggler.
        $this->postWebhook($this->expiredEvent($registration, ['event_id' => 'evt_late']))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(1, $offering->fresh()->registration_count);
    }

    // ------------------------------------------------------ dispatch safety

    #[Test]
    public function a_donation_event_still_books_a_donation_and_no_registration_payment(): void
    {
        $fund = Fund::create([
            'masjid_id' => $this->masjid->id,
            'name' => 'General Fund',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);

        $donation = Donation::factory()->create([
            'masjid_id' => $this->masjid->id,
            'fund_id' => $fund->id,
            'intended_amount' => 10000,
            'charged_amount' => 10000,
            'status' => 'pending',
        ]);

        // No registration_uuid metadata → today's donation path, untouched.
        $this->postWebhook([
            'id' => 'evt_donation_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_don_1',
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'status' => 'complete',
                'payment_intent' => 'pi_don_1',
                'client_reference_id' => $donation->uuid,
                'metadata' => ['donation_uuid' => $donation->uuid],
            ]],
        ])->assertOk();

        $this->assertSame('succeeded', $donation->fresh()->status);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    #[Test]
    public function a_registration_event_never_creates_a_donation(): void
    {
        $registration = $this->paidPending();

        $this->postWebhook($this->completedEvent($registration))->assertOk();
        $this->postWebhook($this->succeededEvent($registration))->assertOk();

        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->fresh()->status);
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('donation_receipts', 0);
    }

    // ------------------------------------------- an offboarded organisation

    /**
     * MONEY THAT ALREADY MOVED IS RECORDED, WHATEVER HAPPENED TO THE ORG.
     *
     * `masjids` SOFT-deletes and `Masjid::query()` excludes trashed rows, so
     * `resolve()` returned null for an organisation offboarded while a Checkout
     * Session was live. Measured before the fix: the family's card WAS charged
     * at Stripe, and the event was dropped with a log line — no
     * `registration_payments` row, `payment_status` still `awaiting`,
     * `checkout_expires_at` still set, so T-006f's reaper swept the seat and
     * cancelled it. Money taken, nothing recorded, seat gone.
     *
     * Refusing the webhook is the WRONG direction: the money has already moved,
     * and dropping the event destroys the only local evidence of a real charge —
     * the amount, the charge id and the balance transaction that whoever issues
     * the refund needs. The organisation is merchant of record, so the refund is
     * its own action in its own dashboard; this codebase's job is to be a
     * truthful ledger and to say loudly that there is one to make.
     *
     * The asymmetry with the OUTBOUND leg is deliberate and both halves are
     * right: `RegistrationCheckoutService` still REFUSES to open a new Session
     * for a trashed organisation (PublicTenantLifecycleTest::
     * a_priced_registration_cannot_open_a_checkout_session_for_a_deleted_organisation).
     * Money not yet moved: refuse. Money already moved: record it.
     */
    #[Test]
    public function a_payment_for_an_offboarded_organisation_is_recorded_rather_than_dropped(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->paidPending($offering);

        // Offboarded mid-session: the family is already on Stripe's hosted page.
        $this->masjid->delete();
        $this->assertTrue(Masjid::onlyTrashed()->whereKey($this->masjid->id)->exists());
        $this->assertNull(Masjid::find($this->masjid->id), 'the soft delete is what used to drop the event');

        Log::spy();

        $this->postWebhook($this->completedEvent($registration))->assertOk();
        $this->postWebhook($this->succeededEvent($registration))->assertOk();

        $registration->refresh();

        // The ledger row exists, carrying what a refund needs.
        $payments = RegistrationPayment::withoutMasjidScope()
            ->where('registration_id', $registration->id)->get();

        $this->assertCount(1, $payments, 'the charge left no local record at all');
        $this->assertSame(15000, $payments[0]->amount_minor);
        $this->assertSame(RegistrationPayment::STATUS_SUCCEEDED, $payments[0]->status);
        $this->assertSame('ch_reg_1', $payments[0]->stripe_charge_id);
        $this->assertSame('txn_reg_1', $payments[0]->stripe_balance_transaction_id);
        $this->assertSame($this->masjid->id, $payments[0]->masjid_id);

        // The seat states what actually happened. A settled one-time charge left
        // `pending` is a lie about a registration nothing is waiting on, and it
        // is indistinguishable on every screen from an abandoned checkout.
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);

        // And it can no longer be reaped: no deadline, and `paid` is refused by
        // releaseSeat() in EXPIRY mode anyway. That was the second half of the
        // damage — the family paid and a background job cancelled them.
        $this->assertNull($registration->checkout_expires_at);
        $this->assertSame(1, $offering->fresh()->registration_count);

        // Loud enough for a human to find, because a human has to refund it.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (...$args) => is_string($args[0] ?? null)
                && str_contains($args[0], 'OFFBOARDED'))
            ->atLeast()->once();
    }

    #[Test]
    public function the_reaper_can_no_longer_cancel_a_seat_that_was_paid_for_after_offboarding(): void
    {
        // The consequence chain, end to end: it is not enough that the row is
        // written — the seat must survive the sweep that used to take it.
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->paidPending($offering);

        $this->masjid->delete();

        $this->postWebhook($this->completedEvent($registration))->assertOk();

        $this->travel(2)->days();

        \Illuminate\Support\Facades\Artisan::call('registrations:reap-expired');

        $registration->refresh();

        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);
        $this->assertSame(1, $offering->fresh()->registration_count);
    }

    /**
     * THE ONE BRANCH THAT USED TO BE SILENT.
     *
     * When an event names a registration that does not belong to the
     * organisation holding the connected account, `resolve()` returns null and
     * the handler returns — answering Stripe 200, so it never retries. If money
     * moved, it is unrecorded here and the reaper will release the seat 45
     * minutes later. There was no log line at all on that path, so the first
     * anybody heard of it was the family.
     *
     * A mutation test is what put this test here: deleting the warning left the
     * whole suite green, which meant the only evidence a payment had been
     * dropped was constrained by nothing.
     */
    #[Test]
    public function a_dropped_event_says_what_it_dropped(): void
    {
        Log::spy();

        $registration = $this->paidPending();

        // A second live organisation, its own Connect account, and an event
        // whose account routes there while the uuid belongs to the first.
        $other = $this->makeMasjid(['stripe_account_id' => 'acct_OTHER', 'stripe_charges_enabled' => true]);

        $event = $this->completedEvent($registration);
        $event['account'] = 'acct_OTHER';

        $this->postWebhook($event)->assertOk();

        $registration->refresh();

        // Nothing was recorded — that is the behaviour, and it is why the line
        // has to exist.
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertSame(
            0,
            RegistrationPayment::withoutMasjidScope()->where('registration_id', $registration->id)->count(),
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($registration, $other): bool {
                return str_contains($message, 'does not belong to the organisation')
                    && $context['registration_uuid'] === $registration->uuid
                    && (int) $context['masjid_id'] === (int) $other->id
                    && $context['account'] === 'acct_OTHER';
            })
            ->once();
    }

    #[Test]
    public function a_live_organisation_is_preferred_over_a_trashed_one_on_the_same_account_id(): void
    {
        // A Connect account belongs to exactly one LIVE organisation, and
        // `masjids_active_stripe_account_unique` now enforces that — so the
        // ghost has to reach this state the way a real one does: it held the
        // account, it was offboarded, and the account was later onboarded by
        // somebody else. The index covers live rows only, precisely so that
        // this remains possible; money arriving for an offboarded organisation
        // must still be RECORDED, which is what the trashed fallback is for.
        //
        // Hence the order: live under its own account, soft-deleted, and only
        // then moved onto acct_A — which the partial index permits because the
        // row is no longer live. Creating it live on acct_A alongside the real
        // organisation is the state the index exists to make unreachable.
        $ghost = $this->makeMasjid(['stripe_account_id' => 'acct_GHOST', 'stripe_charges_enabled' => true]);
        $ghost->delete();
        $ghost->forceFill(['stripe_account_id' => 'acct_A'])->saveQuietly();

        $registration = $this->paidPending();

        $this->postWebhook($this->completedEvent($registration))->assertOk();

        $registration->refresh();

        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(
            $this->masjid->id,
            (int) RegistrationPayment::withoutMasjidScope()
                ->where('registration_id', $registration->id)->firstOrFail()->masjid_id
        );
    }

    // ============================= helpers =============================

    /** One confirmed seat, one payment row carrying BOTH payloads' ids, one roster. */
    private function assertConverged(Registration $registration): void
    {
        $registration->refresh();

        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);

        $payments = RegistrationPayment::withoutMasjidScope()
            ->where('registration_id', $registration->id)->get();

        $this->assertCount(1, $payments);

        $payment = $payments[0];
        $this->assertSame(15000, $payment->amount_minor);
        $this->assertSame(RegistrationPayment::STATUS_SUCCEEDED, $payment->status);
        // The session event brought the payment intent; the payment-intent
        // event brought the charge and its balance transaction. One row holds
        // both, whichever arrived first.
        $this->assertSame('pi_reg_1', $payment->stripe_payment_intent_id);
        $this->assertSame('ch_reg_1', $payment->stripe_charge_id);
        $this->assertSame('txn_reg_1', $payment->stripe_balance_transaction_id);
        $this->assertSame(465, $payment->stripe_fee_minor);
        $this->assertSame(14535, $payment->net_minor);

        // Exactly one roster row — confirm() is idempotent, so the second event
        // cannot duplicate the group membership.
        $this->assertSame(1, GroupMembership::withoutMasjidScope()->where('group_id', $this->group->id)->count());
        $this->assertSame('cs_reg_1', $registration->stripe_checkout_session_id);
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
        ], $overrides));
    }

    private function makeOffering(array $state = []): Offering
    {
        return Offering::factory()
            ->forMasjid($this->masjid)
            ->withRoster($this->group)
            ->create($state);
    }

    /**
     * A paid one-time registration in the state a real one is in the instant
     * after RegistrationCheckoutService handed back its URL: seat pending,
     * money awaiting, session id recorded, nothing confirmed. Built through the
     * real T-006b service — no Stripe call anywhere.
     */
    private function paidPending(?Offering $offering = null): Registration
    {
        $offering ??= $this->makeOffering();

        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = app(RegistrationService::class)->register(
            $offering,
            $plan,
            Contact::factory()->create(['masjid_id' => $this->masjid->id]),
            ['full_name' => 'Amal Yusuf']
        );

        $registration->forceFill(['stripe_checkout_session_id' => 'cs_reg_1'])->save();

        return $registration;
    }

    /** Post a Stripe-signed event to the webhook, signing exactly like Stripe. */
    private function postWebhook(array $event, ?string $secret = null): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret ?? $this->platformSecret);

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

    private function completedEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'checkout.session.completed',
            // Direct charges live on the ORG's connected account, so the event
            // carries it — and that is what decides tenancy.
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => $o['id'] ?? 'cs_reg_1',
                'object' => 'checkout.session',
                'payment_status' => $o['payment_status'] ?? 'paid',
                'status' => $o['status'] ?? 'complete',
                'amount_total' => $o['amount_total'] ?? 15000,
                'payment_intent' => 'pi_reg_1',
                'client_reference_id' => $registration->uuid,
                'metadata' => ['registration_uuid' => $registration->uuid],
            ]],
        ];
    }

    private function succeededEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => 'pi_reg_1',
                'object' => 'payment_intent',
                'amount_received' => 15000,
                'metadata' => ['registration_uuid' => $registration->uuid],
                // Charge + balance transaction expanded: the real fee/net, so
                // nothing is fetched from Stripe and nothing is estimated.
                'latest_charge' => [
                    'id' => 'ch_reg_1',
                    'object' => 'charge',
                    'balance_transaction' => [
                        'id' => 'txn_reg_1',
                        'object' => 'balance_transaction',
                        'fee' => 465,
                        'net' => 14535,
                    ],
                ],
            ]],
        ];
    }

    private function expiredEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'checkout.session.expired',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => $o['id'] ?? 'cs_reg_1',
                'object' => 'checkout.session',
                'status' => 'expired',
                'client_reference_id' => $registration->uuid,
                'metadata' => ['registration_uuid' => $registration->uuid],
            ]],
        ];
    }
}
