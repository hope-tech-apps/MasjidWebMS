<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\RegistrationPayment;
use App\Services\Registrations\RegistrationService;
use App\Services\Stripe\RegistrationCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-006e — installments and open-ended recurring: the money that arrives N
 * times instead of once.
 *
 * Every invariant here is a doctrine invariant:
 *
 *  - ORDER INDEPENDENCE ACROSS THE HARD PERMUTATION. For a subscription the
 *    money (`invoice.payment_succeeded`) and the link
 *    (`checkout.session.completed`) are separate events with no ordering
 *    guarantee. BOTH orders must converge on ONE confirmed registration, ONE
 *    roster materialisation, and no duplicate payment rows. The ratified design
 *    names this permutation explicitly; both arms are proved below.
 *  - ONE ROW PER INVOICE. N successful invoices produce exactly N rows; a
 *    redelivery of any of them produces none; a failed attempt and its
 *    successful retry share one row.
 *  - DUNNING NEVER EJECTS A PAYER. `invoice.payment_failed` moves the MONEY to
 *    `past_due` and leaves the SEAT alone — and the T-006f reaper still refuses
 *    to touch that registration even with an expired window forced back onto
 *    it. That exclusion exists for exactly this reason.
 *  - CANCELLING STOPS FUTURE BILLING ONLY. Settled ledger rows survive
 *    untouched; v1 refunds are the org's own action in its own dashboard.
 *  - STRIPE OWNS THE CLOCK. There is no local scheduler, retry loop or dunning
 *    engine — the only thing this codebase creates is a Subscription Schedule
 *    telling Stripe to stop after N.
 *
 * Signature verification is REAL on every payload. Stripe is mocked at its
 * seams; no test in this file touches the live API.
 */
class RegistrationInstallmentTest extends TestCase
{
    use RefreshDatabase;

    private string $platformSecret = 'whsec_platform_secret';

    private string $connectSecret = 'whsec_connect_secret';

    private Masjid $masjid;

    private Masjid $otherMasjid;

    private Group $group;

    /** @var array<int,array<string,mixed>> schedule seam calls */
    private array $schedules = [];

    /** @var array<int,array<string,mixed>> subscription-cancel seam calls */
    private array $cancellations = [];

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
        ]);

        $this->masjid = $this->makeMasjid(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $this->otherMasjid = $this->makeMasjid(['stripe_account_id' => 'acct_B', 'stripe_charges_enabled' => true]);
        $this->group = Group::factory()->create(['masjid_id' => $this->masjid->id]);

        $this->stubStripeSeams();
    }

    // ------------------------------------------------- ordering convergence

    #[Test]
    public function session_then_invoice_confirms_the_seat_and_books_one_installment(): void
    {
        $registration = $this->subscriptionPending();

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();

        // The session alone links and confirms — it books NO ledger row,
        // because a subscription's money arrives per invoice.
        $registration->refresh();
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_ACTIVE, $registration->payment_status);
        $this->assertSame('sub_reg_1', $registration->stripe_subscription_id);
        $this->assertNull($registration->checkout_expires_at);
        $this->assertDatabaseCount('registration_payments', 0);

        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();

        $this->assertConverged($registration, 1);
    }

    #[Test]
    public function invoice_before_session_converges_to_the_same_single_confirmed_registration(): void
    {
        $registration = $this->subscriptionPending();

        // THE permutation the design names: the money lands before the link.
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_ACTIVE, $registration->payment_status);
        // Self-healed from the invoice: the subscription id arrived without the
        // session that normally carries it.
        $this->assertSame('sub_reg_1', $registration->stripe_subscription_id);
        $this->assertDatabaseCount('registration_payments', 1);

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();

        // No second payment row, no second roster row, still one confirmation.
        $this->assertConverged($registration, 1);
    }

    #[Test]
    public function replaying_any_event_id_changes_nothing(): void
    {
        $registration = $this->subscriptionPending();

        $session = $this->sessionCompletedEvent($registration, ['event_id' => 'evt_sub_1']);
        $invoice = $this->invoicePaidEvent($registration, ['id' => 'in_1', 'event_id' => 'evt_inv_1']);

        $this->postWebhook($invoice)->assertOk();
        $this->postWebhook($session)->assertOk();

        // Same event ids again, in the opposite order, twice over.
        $this->postWebhook($session)->assertOk();
        $this->postWebhook($invoice)->assertOk();
        $this->postWebhook($invoice)->assertOk();

        $this->assertConverged($registration, 1);
    }

    #[Test]
    public function a_redelivered_invoice_with_a_new_event_id_still_books_only_one_row(): void
    {
        $registration = $this->subscriptionPending();

        // Dedup on the event id cannot help here — these are genuinely
        // different events carrying the SAME invoice. The per-invoice ledger
        // key is what makes them converge.
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();

        $this->assertSame(1, $this->paymentsFor($registration)->count());
    }

    // --------------------------------------------------------- the ledger

    #[Test]
    public function n_successful_invoices_produce_exactly_n_payment_rows_and_then_paid(): void
    {
        $registration = $this->subscriptionPending(FeePlan::factory()->installment(3, 10000));

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();

        foreach (['in_1', 'in_2'] as $invoiceId) {
            $this->postWebhook($this->invoicePaidEvent($registration, ['id' => $invoiceId]))->assertOk();

            // Still mid-commitment: the money machine is live, not finished.
            $this->assertSame(Registration::PAYMENT_ACTIVE, $registration->fresh()->payment_status);
        }

        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_3']))->assertOk();

        $payments = $this->paymentsFor($registration);
        $this->assertCount(3, $payments);
        $this->assertSame([10000, 10000, 10000], $payments->pluck('amount_minor')->all());
        $this->assertSame(
            ['in_1', 'in_2', 'in_3'],
            $payments->pluck('stripe_invoice_id')->sort()->values()->all()
        );

        // Three of three settled: the whole commitment is collected.
        $this->assertSame(Registration::PAYMENT_PAID, $registration->fresh()->payment_status);
        // The seat was confirmed once, by the first event to reach it.
        $this->assertSame(1, GroupMembership::withoutMasjidScope()->where('group_id', $this->group->id)->count());
    }

    #[Test]
    public function the_ledger_records_fee_and_net_only_from_an_expanded_balance_transaction(): void
    {
        $registration = $this->subscriptionPending();

        $this->postWebhook($this->invoicePaidEvent($registration, [
            'id' => 'in_1',
            'charge' => [
                'id' => 'ch_inv_1',
                'object' => 'charge',
                'balance_transaction' => [
                    'id' => 'txn_inv_1',
                    'object' => 'balance_transaction',
                    'fee' => 320,
                    'net' => 9680,
                ],
            ],
        ]))->assertOk();

        $payment = $this->paymentsFor($registration)->firstOrFail();

        $this->assertSame('ch_inv_1', $payment->stripe_charge_id);
        $this->assertSame('txn_inv_1', $payment->stripe_balance_transaction_id);
        $this->assertSame(320, $payment->stripe_fee_minor);
        $this->assertSame(9680, $payment->net_minor);
    }

    #[Test]
    public function an_open_ended_recurring_plan_stays_active_and_never_reaches_paid(): void
    {
        $registration = $this->subscriptionPending(
            FeePlan::factory()->recurring(FeePlan::INTERVAL_MONTH, 2500)
        );

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();

        foreach (['in_1', 'in_2', 'in_3', 'in_4'] as $invoiceId) {
            $this->postWebhook($this->invoicePaidEvent($registration, [
                'id' => $invoiceId,
                'amount_paid' => 2500,
            ]))->assertOk();
        }

        $this->assertCount(4, $this->paymentsFor($registration));
        // There is no finite commitment to finish, so `paid` never applies.
        $this->assertSame(Registration::PAYMENT_ACTIVE, $registration->fresh()->payment_status);
        // No schedule either: only installments are bounded.
        $this->assertNull($registration->fresh()->stripe_subscription_schedule_id);
        $this->assertSame([], $this->schedules);
    }

    // ------------------------------------------------------------- dunning

    #[Test]
    public function a_failed_invoice_moves_the_money_to_past_due_and_never_the_seat(): void
    {
        $registration = $this->subscriptionPending(FeePlan::factory()->installment(3, 10000));

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();

        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_2']))->assertOk();

        $registration->refresh();

        // THE invariant: the money is in dunning, the child is still enrolled.
        $this->assertSame(Registration::PAYMENT_PAST_DUE, $registration->payment_status);
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        // The seat is still held and the roster is untouched.
        $this->assertSame(1, $this->offeringOf($registration)->registration_count);
        $this->assertSame(1, GroupMembership::withoutMasjidScope()->where('group_id', $this->group->id)->count());

        // The settled first installment is untouched; the failure is recorded
        // on its own row, not on top of the successful one.
        $rows = $this->paymentsFor($registration);
        $this->assertCount(2, $rows);
        $this->assertSame(
            [RegistrationPayment::STATUS_FAILED, RegistrationPayment::STATUS_SUCCEEDED],
            $rows->pluck('status')->sort()->values()->all()
        );
    }

    #[Test]
    public function a_successful_retry_lifts_past_due_back_to_active_on_the_same_row(): void
    {
        $registration = $this->subscriptionPending(FeePlan::factory()->installment(3, 10000));

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();
        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_1']))->assertOk();

        // Stripe owns the retry cadence; we only observe the outcome.
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::PAYMENT_ACTIVE, $registration->payment_status);

        // One invoice, one row — the failure was upgraded, not duplicated.
        $rows = $this->paymentsFor($registration);
        $this->assertCount(1, $rows);
        $this->assertSame(RegistrationPayment::STATUS_SUCCEEDED, $rows[0]->status);
        $this->assertNotNull($rows[0]->paid_at);
    }

    #[Test]
    public function a_stale_failure_never_downgrades_a_settled_row(): void
    {
        $registration = $this->subscriptionPending();

        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();
        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_1']))->assertOk();

        $rows = $this->paymentsFor($registration);
        $this->assertCount(1, $rows);
        $this->assertSame(RegistrationPayment::STATUS_SUCCEEDED, $rows[0]->status);
    }

    #[Test]
    public function the_reaper_refuses_to_touch_a_past_due_registration(): void
    {
        $registration = $this->subscriptionPending(FeePlan::factory()->installment(3, 10000));

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();
        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_2']))->assertOk();

        // Force an expired checkout window back onto the row so the ONLY thing
        // saving this family from being swept is the reaper's past_due
        // exclusion — which exists precisely because dunning must never eject.
        $registration->fresh()->forceFill([
            'status' => Registration::STATUS_PENDING,
            'checkout_expires_at' => now()->subDays(2),
        ])->save();

        Artisan::call('registrations:reap-expired');

        $registration->refresh();
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertSame(Registration::PAYMENT_PAST_DUE, $registration->payment_status);
        $this->assertSame(1, $this->offeringOf($registration)->registration_count);
    }

    #[Test]
    public function a_never_paid_hold_is_not_made_immortal_by_a_failed_invoice(): void
    {
        $registration = $this->subscriptionPending();

        // Nothing has ever settled and the seat is not confirmed: dunning
        // protects PAYERS, and marking this `past_due` would exempt an unpaid
        // hold from the reaper forever.
        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_0']))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertNotNull($registration->checkout_expires_at);
    }

    // -------------------------------------------------- subscription lifecycle

    #[Test]
    public function the_installment_schedule_is_attached_once_and_bounds_the_plan_to_n_payments(): void
    {
        $registration = $this->subscriptionPending(FeePlan::factory()->installment(9, 10000));

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();

        $this->assertSame('sub_sched_1', $registration->fresh()->stripe_subscription_schedule_id);
        $this->assertCount(1, $this->schedules);
        $this->assertSame('sub_reg_1', $this->schedules[0]['subscription']);
        // STRIPE counts the payments and stops. Nothing local schedules a thing.
        $this->assertSame(9, $this->schedules[0]['iterations']);
        $this->assertSame('acct_A', $this->schedules[0]['account']);

        // Every later event is a no-op for the schedule.
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_2']))->assertOk();

        $this->assertCount(1, $this->schedules);
    }

    #[Test]
    public function an_invoice_arriving_first_attaches_the_schedule_without_the_session(): void
    {
        $registration = $this->subscriptionPending(FeePlan::factory()->installment(4, 10000));

        // The session event is never delivered at all.
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();

        $this->assertSame('sub_sched_1', $registration->fresh()->stripe_subscription_schedule_id);
        $this->assertSame(4, $this->schedules[0]['iterations']);
    }

    #[Test]
    public function a_completed_schedule_closes_the_commitment_as_paid(): void
    {
        $registration = $this->subscriptionPending(FeePlan::factory()->installment(3, 10000));

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();
        $this->postWebhook($this->scheduleCompletedEvent($registration))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);
        // The seat is not touched by a money event, ever.
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
    }

    #[Test]
    public function a_deleted_subscription_ends_a_finished_plan_as_paid_and_a_live_one_as_canceled(): void
    {
        $finished = $this->subscriptionPending(FeePlan::factory()->installment(2, 10000));
        $this->postWebhook($this->sessionCompletedEvent($finished))->assertOk();
        $this->postWebhook($this->invoicePaidEvent($finished, ['id' => 'in_a1']))->assertOk();
        $this->postWebhook($this->invoicePaidEvent($finished, ['id' => 'in_a2']))->assertOk();

        // Stripe cancels the subscription itself when the schedule completes.
        $this->postWebhook($this->subscriptionDeletedEvent($finished))->assertOk();

        $finished->refresh();
        $this->assertSame(Registration::PAYMENT_PAID, $finished->payment_status);
        $this->assertSame(Registration::STATUS_CONFIRMED, $finished->status);

        $live = $this->subscriptionPending(FeePlan::factory()->recurring());
        $this->postWebhook($this->sessionCompletedEvent($live, ['subscription' => 'sub_reg_2']))->assertOk();
        $this->postWebhook($this->subscriptionDeletedEvent($live, ['id' => 'sub_reg_2']))->assertOk();

        $live->refresh();
        $this->assertSame(Registration::PAYMENT_CANCELED, $live->payment_status);
        // Still enrolled: un-enrolling is an explicit admin action.
        $this->assertSame(Registration::STATUS_CONFIRMED, $live->status);
    }

    // -------------------------------------------------------- cancellation

    #[Test]
    public function cancelling_stops_future_billing_and_leaves_settled_payments_intact(): void
    {
        $registration = $this->subscriptionPending(FeePlan::factory()->installment(9, 10000));

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();
        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();

        // The two halves the admin controller runs, in its order: Stripe first,
        // then the local seat release.
        $stripeTold = app(RegistrationCheckoutService::class)->cancelSubscription($registration->fresh());
        app(RegistrationService::class)->cancel($registration->fresh());

        // The T-006d seam is REAL now: a live subscription is actually stopped.
        $this->assertTrue($stripeTold);
        $this->assertCount(1, $this->cancellations);
        $this->assertSame('sub_reg_1', $this->cancellations[0]['subscription']);
        $this->assertSame('acct_A', $this->cancellations[0]['account']);

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CANCELLED, $registration->status);
        $this->assertSame(Registration::PAYMENT_CANCELED, $registration->payment_status);
        $this->assertSame(0, $this->offeringOf($registration)->registration_count);

        // Cancellation stops FUTURE billing. It does not unwind history: the
        // settled installment keeps its succeeded row, and a refund is the
        // org's own action in its own Stripe dashboard.
        $rows = $this->paymentsFor($registration);
        $this->assertCount(1, $rows);
        $this->assertSame(RegistrationPayment::STATUS_SUCCEEDED, $rows[0]->status);
        $this->assertSame(10000, $rows[0]->amount_minor);
    }

    #[Test]
    public function a_late_invoice_on_a_cancelled_seat_is_recorded_but_resurrects_nothing(): void
    {
        $registration = $this->subscriptionPending();

        $this->postWebhook($this->sessionCompletedEvent($registration))->assertOk();
        app(RegistrationService::class)->cancel($registration->fresh());

        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_late']))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CANCELLED, $registration->status);
        $this->assertSame(Registration::PAYMENT_CANCELED, $registration->payment_status);
        // The money is in the ledger; the refund is the org's action.
        $this->assertCount(1, $this->paymentsFor($registration));
    }

    // ------------------------------------------------- tenancy and dispatch

    #[Test]
    public function another_orgs_connected_account_can_never_advance_this_registration(): void
    {
        $registration = $this->subscriptionPending();

        // Masjid B's account presenting masjid A's uuid: tenancy comes from
        // event.account, so this resolves to nothing at all.
        $this->postWebhook($this->invoicePaidEvent($registration, [
            'id' => 'in_x',
            'account' => 'acct_B',
        ]))->assertOk();

        $registration->refresh();
        $this->assertSame(Registration::STATUS_PENDING, $registration->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    #[Test]
    public function a_registration_invoice_never_books_a_donation(): void
    {
        $registration = $this->subscriptionPending();

        $this->postWebhook($this->invoicePaidEvent($registration, ['id' => 'in_1']))->assertOk();
        $this->postWebhook($this->invoiceFailedEvent($registration, ['id' => 'in_2']))->assertOk();
        $this->postWebhook($this->subscriptionDeletedEvent($registration))->assertOk();

        // The dispatch branch is the highest-risk touchpoint of this whole
        // feature: registration invoices must never leak into the donation
        // ledger. (DonationFlowTest pins the other direction, untouched.)
        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('donation_subscriptions', 0);
    }

    #[Test]
    public function an_invoice_carrying_the_uuid_in_the_newer_parent_shape_still_routes(): void
    {
        $registration = $this->subscriptionPending();

        $event = $this->invoicePaidEvent($registration, ['id' => 'in_shape']);
        // Stripe has moved subscription details under `parent` in newer API
        // versions; the routing signal must survive that move.
        unset($event['data']['object']['subscription_details'], $event['data']['object']['subscription']);
        $event['data']['object']['parent'] = [
            'subscription_details' => [
                'subscription' => 'sub_reg_1',
                'metadata' => ['registration_uuid' => $registration->uuid],
            ],
        ];

        $this->postWebhook($event)->assertOk();

        $this->assertCount(1, $this->paymentsFor($registration));
        $this->assertSame('sub_reg_1', $registration->fresh()->stripe_subscription_id);
    }

    #[Test]
    public function an_unsigned_subscription_payload_changes_nothing(): void
    {
        $registration = $this->subscriptionPending();

        $payload = json_encode($this->invoicePaidEvent($registration, ['id' => 'in_1']));

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => 't=' . time() . ',v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertUnauthorized();

        $registration->refresh();
        $this->assertSame(Registration::PAYMENT_AWAITING, $registration->payment_status);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    // ============================== helpers ==============================

    /**
     * Bind a partial RegistrationCheckoutService whose only mocked methods are
     * the two outbound Stripe seams this slice adds/uses. Everything else runs
     * for real, and the live API is never touched.
     */
    private function stubStripeSeams(): void
    {
        $service = Mockery::mock(RegistrationCheckoutService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('createInstallmentSchedule')
            ->andReturnUsing(function (string $subscription, int $iterations, string $account, array $metadata = []) {
                $this->schedules[] = compact('subscription', 'iterations', 'account', 'metadata');

                return 'sub_sched_1';
            });

        $service->shouldReceive('cancelStripeSubscription')
            ->andReturnUsing(function (string $subscription, string $account): void {
                $this->cancellations[] = compact('subscription', 'account');
            });

        $this->app->instance(RegistrationCheckoutService::class, $service);
    }

    /**
     * A subscription registration in the state a real one is in the instant
     * after the hosted Checkout URL was handed back: seat pending, money
     * awaiting, session id recorded, nothing confirmed. Built through the real
     * T-006b service — no Stripe call anywhere.
     */
    private function subscriptionPending($planFactory = null): Registration
    {
        $offering = Offering::factory()
            ->forMasjid($this->masjid)
            ->withRoster($this->group)
            ->create();

        $plan = ($planFactory ?? FeePlan::factory()->installment(9, 10000))->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = app(RegistrationService::class)->register(
            $offering,
            $plan,
            Contact::factory()->create(['masjid_id' => $this->masjid->id]),
            ['full_name' => 'Amal Yusuf']
        );

        $registration->forceFill(['stripe_checkout_session_id' => 'cs_sub_1'])->save();

        return $registration;
    }

    /** One confirmed seat, $expected ledger rows, one roster materialisation. */
    private function assertConverged(Registration $registration, int $expected): void
    {
        $registration->refresh();

        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_ACTIVE, $registration->payment_status);
        $this->assertSame('sub_reg_1', $registration->stripe_subscription_id);
        $this->assertSame('cs_sub_1', $registration->stripe_checkout_session_id);
        $this->assertNull($registration->checkout_expires_at);

        $payments = $this->paymentsFor($registration);
        $this->assertCount($expected, $payments);
        $this->assertSame(10000, $payments[0]->amount_minor);
        $this->assertSame(RegistrationPayment::STATUS_SUCCEEDED, $payments[0]->status);
        $this->assertSame('in_1', $payments[0]->stripe_invoice_id);
        $this->assertSame($this->masjid->id, $payments[0]->masjid_id);

        // confirm() is idempotent, so no event can duplicate the roster.
        $this->assertSame(1, GroupMembership::withoutMasjidScope()->where('group_id', $this->group->id)->count());
    }

    private function paymentsFor(Registration $registration)
    {
        return RegistrationPayment::withoutMasjidScope()
            ->where('registration_id', $registration->id)
            ->orderBy('id')
            ->get();
    }

    private function offeringOf(Registration $registration): Offering
    {
        return Offering::withoutMasjidScope()->whereKey($registration->offering_id)->firstOrFail();
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

    /** Post a Stripe-signed event to the webhook, signing exactly like Stripe. */
    private function postWebhook(array $event, ?string $secret = null): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret ?? $this->connectSecret);

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

    private function sessionCompletedEvent(Registration $registration, array $o = []): array
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

    /**
     * An invoice as it arrives for a subscription: our uuid lives on the
     * SUBSCRIPTION's metadata, not the invoice's own.
     */
    private function invoicePaidEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'invoice.payment_succeeded',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => array_merge([
                'id' => $o['id'] ?? 'in_1',
                'object' => 'invoice',
                'amount_paid' => $o['amount_paid'] ?? 10000,
                'subscription' => $o['subscription'] ?? 'sub_reg_1',
                'subscription_details' => [
                    'metadata' => ['registration_uuid' => $registration->uuid],
                ],
            ], array_intersect_key($o, array_flip(['charge', 'payment_intent'])))],
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
                'amount_due' => $o['amount_due'] ?? 10000,
                'amount_paid' => 0,
                'subscription' => $o['subscription'] ?? 'sub_reg_1',
                'subscription_details' => [
                    'metadata' => ['registration_uuid' => $registration->uuid],
                ],
            ]],
        ];
    }

    private function subscriptionDeletedEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'customer.subscription.deleted',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => $o['id'] ?? 'sub_reg_1',
                'object' => 'subscription',
                'status' => 'canceled',
                'metadata' => ['registration_uuid' => $registration->uuid],
            ]],
        ];
    }

    private function scheduleCompletedEvent(Registration $registration, array $o = []): array
    {
        return [
            'id' => $o['event_id'] ?? 'evt_' . uniqid(),
            'type' => 'subscription_schedule.completed',
            'account' => $o['account'] ?? 'acct_A',
            'data' => ['object' => [
                'id' => $o['id'] ?? 'sub_sched_1',
                'object' => 'subscription_schedule',
                'status' => 'completed',
                'metadata' => ['registration_uuid' => $registration->uuid],
            ]],
        ];
    }
}
