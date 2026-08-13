<?php

namespace App\Services\Stripe;

use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Registration;
use App\Models\RegistrationPayment;
use App\Services\Registrations\RegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RegistrationPaymentService — the INBOUND half of a paid registration: the
 * webhook handlers that are the SOURCE OF TRUTH for its money and its seat
 * (T-006c, docs/t006-registration-billing-design.md).
 *
 * Nothing else may advance a registration to paid/confirmed. The browser
 * redirect off Stripe's hosted page proves nothing — it can be spoofed,
 * dropped, or fire before the money moves — so `RegistrationCheckoutService`
 * only ever hands back a URL, and state changes exclusively here, from a
 * signature-verified event.
 *
 * ORDER INDEPENDENCE (the invariant this class exists to hold):
 * `checkout.session.completed` and `payment_intent.succeeded` both fire for one
 * one-time charge, in EITHER order, possibly more than once. Both route into
 * the same `settle()`, which is keyed by a DETERMINISTIC idempotency key
 * derived from the registration uuid — a one-time plan is exactly one charge by
 * construction, so whichever event arrives first CREATES the single
 * `registration_payments` row and the other one MERGES into it, filling in
 * whichever Stripe identifiers its payload happens to carry (the session brings
 * the session id, the payment intent brings the charge + balance transaction).
 * Confirmation then routes through `RegistrationService::confirm()`, which is
 * idempotent and pending-only, so the roster and guardian edges are written
 * exactly once. Two events, one row, one confirmation, one roster — in any
 * order. Event-id replay never reaches here at all: the controller dedups on
 * `stripe_webhook_events` first.
 *
 * THE FREE PATH AND THE PAID PATH CONVERGE. This class never flips `status`
 * itself; it calls the SAME confirmation seam the free path uses, so a paid
 * registration materialises its roster through exactly the code a free one
 * does. That convergence is the whole point of the seam
 * (.claude/rules/registration-billing-data.md).
 *
 * TENANCY IS DERIVED FROM `event.account`, NEVER FROM METADATA. A direct charge
 * lives on the ORG's connected account, so the event carries that account id;
 * it is matched against `masjids.stripe_account_id` and the resulting masjid is
 * then used to look the registration up (`findByUuidForMasjid`). A uuid from
 * masjid A presented on masjid B's account resolves to nothing. The webhook
 * runs UNBOUND (no tenant middleware), so every row written here sets
 * `masjid_id` explicitly — the BelongsToMasjid creating hook stamps nothing
 * here (.claude/rules/stripe-payments.md, Tenancy note).
 *
 * ------------------------------------------------------------------ T-006e
 *
 * SUBSCRIPTIONS (installments and open-ended recurring) add four handlers and
 * change nothing above.
 *
 * ORDER INDEPENDENCE, AGAIN, ACROSS A HARDER PERMUTATION: for a subscription
 * the money arrives as `invoice.payment_succeeded` while the seat link arrives
 * as `checkout.session.completed`, and Stripe makes no promise about which
 * lands first. The two are made commutative by giving each a job the other
 * never does — the INVOICE owns the ledger row (keyed `reg_invoice_{id}`, so
 * one row per invoice and never the one-time key), the SESSION owns the
 * subscription link — while BOTH converge the seat through the same idempotent
 * `RegistrationService::confirm()` and both may attach the installment
 * schedule. Invoice-first therefore confirms the seat and books the payment,
 * and the session that follows only fills in the ids; session-first confirms
 * the seat and links, and the invoice that follows only books. One confirmed
 * registration, no duplicate rows, either way.
 *
 * DUNNING NEVER EJECTS A PAYER. `invoice.payment_failed` moves `payment_status`
 * to `past_due` and is FORBIDDEN from touching `status` — un-enrolling a
 * mid-semester child is an explicit admin action, never an automatic
 * consequence of an expired card. This is the reason T-006f's reaper excludes
 * `past_due` from its sweep set. Stripe owns the retry cadence and the dunning
 * emails; there is no scheduler, no retry loop and no dunning engine here, and
 * a later successful retry simply moves the state back to `active`.
 */
class RegistrationPaymentService
{
    public function __construct(
        private RegistrationService $registrations,
        private RegistrationCheckoutService $checkout,
    ) {
    }

    /**
     * Is this Stripe object one of OURS — i.e. does the webhook controller hand
     * it to this service rather than to the (locked) donation path?
     *
     * The signal is `metadata.registration_uuid`, which
     * RegistrationCheckoutService writes onto both the session and the payment
     * intent. Anything WITHOUT it is left to today's donation dispatch,
     * unchanged and unperturbed — that is the dispatch-safety contract the
     * ratified design names as the highest-risk touchpoint of this slice.
     */
    public static function isRegistrationEvent(array $object): bool
    {
        return self::registrationUuid($object) !== null;
    }

    /**
     * A completed Checkout Session.
     *
     * "Completed" only means MONEY MOVED when the session is paid; an async
     * payment method can complete a session while the charge is still pending,
     * so an unpaid completion records the session handle and advances nothing.
     *
     * A SUBSCRIPTION-mode session books no ledger row here (T-006e): the money
     * for a subscription arrives per invoice, keyed per invoice. This event
     * links the subscription, confirms the seat, and bounds an installment plan
     * with its schedule — nothing else.
     */
    public function handleCheckoutCompleted(array $session, ?string $account): void
    {
        $registration = $this->resolve($session, $account);

        if (! $registration) {
            return;
        }

        $paid = ($session['payment_status'] ?? null) === 'paid'
            || ($session['status'] ?? null) === 'complete';

        $sessionId = $this->stringOrNull($session['id'] ?? null);

        if (! $paid) {
            if ($sessionId && $registration->stripe_checkout_session_id === null) {
                $registration->stripe_checkout_session_id = $sessionId;
                $registration->save();
            }

            return;
        }

        if ($this->isSubscriptionCheckout($registration, $session)) {
            $this->linkSubscriptionCheckout($registration, $session);

            return;
        }

        $this->settle(
            $registration,
            [
                'stripe_checkout_session_id' => $sessionId,
                'stripe_payment_intent_id' => $this->stringOrNull($session['payment_intent'] ?? null),
            ],
            isset($session['amount_total']) ? (int) $session['amount_total'] : null,
            null,
            null
        );
    }

    /**
     * A succeeded PaymentIntent. Carries the charge and (when expanded) its
     * balance transaction, which is the only trustworthy source of the real
     * fee/net — so this event typically FILLS IN the financial columns of the
     * row the session event created, or creates that row itself when it lands
     * first.
     */
    public function handlePaymentIntentSucceeded(array $pi, ?string $account): void
    {
        $registration = $this->resolve($pi, $account);

        if (! $registration) {
            return;
        }

        [$chargeId, $balanceTxnId, $fee, $net] = $this->chargeFinancials($pi);

        $amount = null;
        foreach (['amount_received', 'amount'] as $key) {
            if (isset($pi[$key]) && (int) $pi[$key] > 0) {
                $amount = (int) $pi[$key];
                break;
            }
        }

        $this->settle(
            $registration,
            [
                'stripe_payment_intent_id' => $this->stringOrNull($pi['id'] ?? null),
                'stripe_charge_id' => $chargeId,
                'stripe_balance_transaction_id' => $balanceTxnId,
            ],
            $amount,
            $fee,
            $net
        );
    }

    /**
     * The Checkout Session expired without payment: the held seat goes back.
     *
     * Guarded against a SUPERSEDED session — if the registrant re-minted their
     * checkout, the abandoned first session still expires half an hour later,
     * and releasing on it would cancel a seat somebody is actively paying for.
     * Only the registration's CURRENT session releases. A second delivery for
     * the same session is a no-op because the release itself is idempotent
     * (only a pending seat releases).
     */
    public function handleCheckoutExpired(array $session, ?string $account): void
    {
        $registration = $this->resolve($session, $account);

        if (! $registration) {
            return;
        }

        $sessionId = $this->stringOrNull($session['id'] ?? null);
        $current = $registration->stripe_checkout_session_id;

        if ($sessionId !== null && $current !== null && $current !== $sessionId) {
            return; // a stale session; the live one still holds the seat.
        }

        $this->registrations->releaseSeat($registration);
    }

    // --------------------------------------------- T-006e: subscription money

    /**
     * A subscription invoice was PAID — one installment, or one interval of an
     * open-ended plan.
     *
     * Appends exactly ONE `registration_payments` row per invoice, keyed by the
     * invoice id, so N successful invoices produce N rows and a redelivery of
     * any of them produces none. The seat is confirmed through the same
     * `RegistrationService::confirm()` seam the free and one-time paths use, so
     * whether this event or `checkout.session.completed` lands first, the
     * roster materialises once, through identical code.
     *
     * The money state is derived, never assumed: an installment plan whose
     * successful invoices have reached its `installment_count` is `paid` (the
     * full commitment is settled); anything else with a live subscription is
     * `active`. A successful retry after a decline therefore lifts `past_due`
     * back to `active` on its own — Stripe's dunning resolving itself, with no
     * local retry machinery involved.
     */
    public function handleInvoicePaid(array $invoice, ?string $account): void
    {
        $registration = $this->resolve($invoice, $account);

        if (! $registration) {
            return;
        }

        $invoiceId = $this->stringOrNull($invoice['id'] ?? null);

        if ($invoiceId === null) {
            // Without an invoice id there is no key that can dedupe a
            // redelivery, and an unkeyed row could be booked twice. Recording
            // nothing is the safe failure for a ledger.
            Log::warning('Registration invoice event carried no invoice id; nothing booked.', [
                'registration_id' => $registration->id,
            ]);

            return;
        }

        $subscriptionId = $this->subscriptionIdFrom($invoice);
        [$paymentIntentId, $chargeId, $balanceTxnId, $fee, $net] = $this->invoiceFinancials($invoice);

        $amount = isset($invoice['amount_paid']) ? (int) $invoice['amount_paid'] : null;

        DB::transaction(function () use (
            $registration, $invoiceId, $subscriptionId, $paymentIntentId,
            $chargeId, $balanceTxnId, $fee, $net, $amount
        ): void {
            $locked = Registration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            $payment = $this->lockPaymentByKey(self::invoicePaymentKey($invoiceId));

            if (! $payment) {
                $payment = new RegistrationPayment();
                // Explicit: the webhook is unbound, so nothing stamps this.
                $payment->masjid_id = $locked->masjid_id;
                $payment->registration_id = $locked->id;
                $payment->idempotency_key = self::invoicePaymentKey($invoiceId);
                $payment->amount_minor = $amount ?? $this->perChargeFallback($locked);
            }

            $this->mergeIdentifiers($payment, [
                'stripe_invoice_id' => $invoiceId,
                'stripe_payment_intent_id' => $paymentIntentId,
                'stripe_charge_id' => $chargeId,
                'stripe_balance_transaction_id' => $balanceTxnId,
            ], $fee, $net);

            // A failed attempt on this same invoice is UPGRADED here — one row
            // per invoice, whatever it took to collect it.
            if (! $payment->isSucceeded()) {
                $payment->status = RegistrationPayment::STATUS_SUCCEEDED;
                $payment->paid_at = now();
            }

            $payment->save();

            if ($subscriptionId !== null && $locked->stripe_subscription_id === null) {
                // Self-heal: the invoice reached us before the session did.
                $locked->stripe_subscription_id = $subscriptionId;
            }

            // Money has landed, so the seat is no longer time-boxed and the
            // reaper must never see a deadline on it.
            $locked->checkout_expires_at = null;

            if ($locked->status === Registration::STATUS_CANCELLED) {
                // A late invoice on a dead seat: the ledger row above records
                // it, but nothing resurrects the money machine. The refund is
                // the org's own action in its dashboard — it is merchant of
                // record. Never throw; a 500 has Stripe retry forever.
                Log::warning('Registration invoice settled against a cancelled seat.', [
                    'registration_id' => $locked->id,
                    'stripe_invoice_id' => $invoiceId,
                ]);

                $locked->save();

                return;
            }

            $locked->payment_status = $this->settledMoneyState($locked);
            $locked->save();

            if (in_array($locked->status, [
                Registration::STATUS_PENDING,
                Registration::STATUS_CONFIRMED,
            ], true)) {
                // THE shared seam — the same roster/guardian materialisation
                // the free and one-time paths run. Idempotent, so invoice two
                // through nine change nothing.
                $this->registrations->confirm($locked);
            }

            $registration->refresh();
        });

        // OUTSIDE the transaction, because it may talk to Stripe: bound an
        // installment plan to N payments the first time we know its
        // subscription id. Idempotent and non-fatal by construction.
        $this->checkout->ensureInstallmentSchedule($registration->refresh(), $subscriptionId);
    }

    /**
     * A subscription invoice FAILED — the dunning event.
     *
     * THE ONE THING THIS MUST NEVER DO IS TOUCH THE SEAT. `status` is not
     * written here under any circumstance: a declined card does not un-enrol a
     * child mid-semester, and the reaper's exclusion of `past_due` exists
     * precisely so nothing downstream ejects them either. Stripe owns the retry
     * schedule and the dunning emails; a later success flips the state back.
     *
     * The failed attempt is recorded on the SAME per-invoice row a success
     * would use, so a retry upgrades that row rather than adding a second one,
     * and a succeeded row is never downgraded by a stale delivery.
     *
     * A registration that has NEVER paid anything and still holds an unconfirmed
     * seat is deliberately left in `awaiting`: dunning protects payers, and
     * marking a never-paid hold `past_due` would make it permanently immune to
     * the reaper — an immortal seat nobody is paying for.
     */
    public function handleInvoiceFailed(array $invoice, ?string $account): void
    {
        $registration = $this->resolve($invoice, $account);

        if (! $registration) {
            return;
        }

        $invoiceId = $this->stringOrNull($invoice['id'] ?? null);
        $subscriptionId = $this->subscriptionIdFrom($invoice);
        [$paymentIntentId, $chargeId, $balanceTxnId] = $this->invoiceFinancials($invoice);

        $amount = null;

        foreach (['amount_due', 'amount_paid'] as $key) {
            if (isset($invoice[$key]) && (int) $invoice[$key] > 0) {
                $amount = (int) $invoice[$key];
                break;
            }
        }

        DB::transaction(function () use (
            $registration, $invoiceId, $subscriptionId,
            $paymentIntentId, $chargeId, $balanceTxnId, $amount
        ): void {
            $locked = Registration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            if ($invoiceId !== null) {
                $payment = $this->lockPaymentByKey(self::invoicePaymentKey($invoiceId));

                if (! $payment) {
                    $payment = new RegistrationPayment();
                    $payment->masjid_id = $locked->masjid_id;
                    $payment->registration_id = $locked->id;
                    $payment->idempotency_key = self::invoicePaymentKey($invoiceId);
                    $payment->amount_minor = $amount ?? $this->perChargeFallback($locked);
                }

                $this->mergeIdentifiers($payment, [
                    'stripe_invoice_id' => $invoiceId,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'stripe_charge_id' => $chargeId,
                    'stripe_balance_transaction_id' => $balanceTxnId,
                ], null, null);

                // Never downgrade money that already settled — a stale failure
                // delivery must not restate a succeeded row.
                if (! $payment->isSucceeded()) {
                    $payment->status = RegistrationPayment::STATUS_FAILED;
                }

                $payment->save();
            }

            if ($subscriptionId !== null && $locked->stripe_subscription_id === null) {
                $locked->stripe_subscription_id = $subscriptionId;
            }

            $everPaid = RegistrationPayment::withoutMasjidScope()
                ->where('registration_id', $locked->id)
                ->where('status', RegistrationPayment::STATUS_SUCCEEDED)
                ->exists();

            $isPayer = $everPaid || $locked->status === Registration::STATUS_CONFIRMED;

            // Terminal money states are not restated by a decline: a cancelled
            // commitment stays cancelled, a fully-settled one stays paid.
            $restatable = ! in_array($locked->payment_status, [
                Registration::PAYMENT_CANCELED,
                Registration::PAYMENT_PAID,
            ], true);

            if ($isPayer && $restatable && $locked->status !== Registration::STATUS_CANCELLED) {
                $locked->payment_status = Registration::PAYMENT_PAST_DUE;
                // Belt and braces: a payer in dunning carries no checkout
                // deadline for anything to sweep, on top of the reaper's
                // explicit past_due exclusion.
                $locked->checkout_expires_at = null;
            }

            // The seat is untouched. Deliberately, and by doctrine.
            $locked->save();

            $registration->refresh();
        });
    }

    /**
     * Stripe ended the subscription — the schedule ran out, an admin cancelled,
     * or the org cancelled it in their own dashboard.
     *
     * Money only: the SEAT IS NEVER TOUCHED here either. An installment plan
     * whose invoices all settled ends `paid` (this is how a completed schedule
     * signs off, since Stripe cancels the subscription when it finishes);
     * anything else ends `canceled`. Settled ledger rows are left exactly as
     * they are — cancelling stops future billing, it does not unwind history.
     */
    public function handleSubscriptionDeleted(array $subscription, ?string $account): void
    {
        $registration = $this->resolve($subscription, $account);

        if (! $registration) {
            return;
        }

        $subscriptionId = $this->stringOrNull($subscription['id'] ?? null);

        DB::transaction(function () use ($registration, $subscriptionId): void {
            $locked = Registration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            // A superseded subscription must not end the live one's billing —
            // the same guard shape `checkout.session.expired` uses for sessions.
            if ($subscriptionId !== null
                && $locked->stripe_subscription_id !== null
                && $locked->stripe_subscription_id !== $subscriptionId) {
                return;
            }

            if ($locked->status === Registration::STATUS_CANCELLED
                || $locked->payment_status === Registration::PAYMENT_PAID) {
                return;
            }

            $locked->payment_status = $this->isFullySettled($locked)
                ? Registration::PAYMENT_PAID
                : Registration::PAYMENT_CANCELED;

            $locked->checkout_expires_at = null;
            $locked->save();

            $registration->refresh();
        });
    }

    /**
     * A Subscription Schedule ran to completion: every installment in the
     * commitment has been billed, so the money machine is done.
     *
     * Seat untouched, ledger untouched — this only closes out `payment_status`.
     */
    public function handleScheduleCompleted(array $schedule, ?string $account): void
    {
        $registration = $this->resolve($schedule, $account);

        if (! $registration) {
            return;
        }

        $scheduleId = $this->stringOrNull($schedule['id'] ?? null);

        DB::transaction(function () use ($registration, $scheduleId): void {
            $locked = Registration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            if ($scheduleId !== null && $locked->stripe_subscription_schedule_id === null) {
                $locked->stripe_subscription_schedule_id = $scheduleId;
            }

            // A cancelled seat's money state is already settled one way or the
            // other; a completed schedule does not restate it.
            if ($locked->status !== Registration::STATUS_CANCELLED) {
                $locked->payment_status = Registration::PAYMENT_PAID;
                $locked->checkout_expires_at = null;
            }

            $locked->save();

            $registration->refresh();
        });
    }

    // ------------------------------------------------------------ convergence

    /**
     * The deterministic ledger key both success events share.
     *
     * A one-time plan is ONE charge by construction, so the registration uuid
     * IS the natural key for its single `registration_payments` row. (T-006e's
     * installments key per invoice instead, which cannot collide with this
     * prefix.) The column is nullable-UNIQUE, so the database itself is the
     * last-resort guard if two deliveries ever raced past the row lock.
     */
    public static function paymentKey(Registration $registration): string
    {
        return 'reg_payment_' . $registration->uuid;
    }

    /**
     * The per-INVOICE ledger key (T-006e).
     *
     * A subscription is N charges, not one, so the registration uuid is the
     * wrong grain — the invoice id is the natural key, and every event about
     * one invoice (a failure, its retry, the eventual success, any redelivery
     * of those) derives the SAME key and therefore the same single row. The
     * `reg_invoice_` prefix cannot collide with `reg_payment_`, so a
     * subscription registration and a one-time one can never contend for a row.
     */
    public static function invoicePaymentKey(string $stripeInvoiceId): string
    {
        return 'reg_invoice_' . $stripeInvoiceId;
    }

    /**
     * Book the money and confirm the seat — idempotently, from either event.
     *
     * @param  array<string,?string>  $ids  Stripe identifiers this payload carried
     */
    private function settle(
        Registration $registration,
        array $ids,
        ?int $amountMinor,
        ?int $fee,
        ?int $net
    ): void {
        $key = self::paymentKey($registration);

        DB::transaction(function () use ($registration, $ids, $amountMinor, $fee, $net, $key): void {
            // Lock the registration so the two success events (or two
            // deliveries of one) serialize instead of interleaving.
            $locked = Registration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            $payment = RegistrationPayment::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                $payment = new RegistrationPayment();
                // Explicit: the webhook is unbound, so nothing stamps this.
                $payment->masjid_id = $locked->masjid_id;
                $payment->registration_id = $locked->id;
                $payment->idempotency_key = $key;
                // What Stripe says moved, falling back to the snapshot that was
                // charged. Both are integer minor units.
                $payment->amount_minor = $amountMinor ?? (int) $locked->adjusted_total_minor;
            }

            // Merge-only: an identifier already recorded is never overwritten,
            // and a payload that lacks one never blanks it. This is what makes
            // the second event additive rather than destructive.
            foreach ([
                'stripe_payment_intent_id',
                'stripe_charge_id',
                'stripe_balance_transaction_id',
            ] as $column) {
                if (! empty($ids[$column]) && $payment->{$column} === null) {
                    $payment->{$column} = $ids[$column];
                }
            }

            if ($fee !== null && $payment->stripe_fee_minor === null) {
                $payment->stripe_fee_minor = $fee;
            }

            if ($net !== null && $payment->net_minor === null) {
                $payment->net_minor = $net;
            }

            if (! $payment->isSucceeded()) {
                $payment->status = RegistrationPayment::STATUS_SUCCEEDED;
                $payment->paid_at = now();
            }

            $payment->save();

            if (! empty($ids['stripe_checkout_session_id']) && $locked->stripe_checkout_session_id === null) {
                $locked->stripe_checkout_session_id = $ids['stripe_checkout_session_id'];
            }

            // A one-time plan is paid in full by its single charge. The seat is
            // no longer time-boxed, so the reaper must not see a deadline.
            $locked->payment_status = Registration::PAYMENT_PAID;
            $locked->checkout_expires_at = null;
            $locked->save();

            if (in_array($locked->status, [
                Registration::STATUS_PENDING,
                Registration::STATUS_CONFIRMED,
            ], true)) {
                // THE shared seam: the same confirmation (and the same roster +
                // guardian materialisation) the free path runs. Idempotent, so
                // the second event changes nothing.
                $this->registrations->confirm($locked);
            } else {
                // Money landed on a seat that is gone (a released/expired hold
                // whose payment arrived late, or an admin cancellation). The
                // ledger row above records it; the refund is an admin action in
                // the org's own Stripe dashboard — the org is merchant of
                // record. Never throw: a 500 here would have Stripe retry
                // forever.
                Log::warning('Registration payment landed on a non-pending seat.', [
                    'registration_id' => $locked->id,
                    'status' => $locked->status,
                ]);
            }

            $registration->refresh();
        });
    }

    // ------------------------------------------------ T-006e: subscription glue

    /**
     * Is this completed session the SUBSCRIPTION shape?
     *
     * Authoritative source first — the registration's own immutable fee plan,
     * which cannot lie about what was sold. The payload's `mode`/`subscription`
     * fields are accepted as corroboration for the (unlikely) case where a plan
     * row cannot be loaded.
     */
    private function isSubscriptionCheckout(Registration $registration, array $session): bool
    {
        $feePlan = FeePlan::withoutMasjidScope()->whereKey($registration->fee_plan_id)->first();

        if ($feePlan) {
            return $feePlan->isSubscriptionBased();
        }

        return ($session['mode'] ?? null) === 'subscription'
            || $this->stringOrNull($session['subscription'] ?? null) !== null;
    }

    /**
     * The subscription half of `checkout.session.completed`: link the Stripe
     * objects, confirm the seat, bound an installment plan with its schedule.
     *
     * Books NO ledger row — the money for a subscription is booked per invoice.
     * That division of labour is what makes this event and
     * `invoice.payment_succeeded` commutative.
     */
    private function linkSubscriptionCheckout(Registration $registration, array $session): void
    {
        $sessionId = $this->stringOrNull($session['id'] ?? null);
        $subscriptionId = $this->stringOrNull($session['subscription'] ?? null);

        DB::transaction(function () use ($registration, $sessionId, $subscriptionId): void {
            $locked = Registration::query()->whereKey($registration->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            // Merge-only, exactly like the one-time path: never overwrite an
            // identifier we already hold, never blank one a payload omits.
            if ($sessionId !== null && $locked->stripe_checkout_session_id === null) {
                $locked->stripe_checkout_session_id = $sessionId;
            }

            if ($subscriptionId !== null && $locked->stripe_subscription_id === null) {
                $locked->stripe_subscription_id = $subscriptionId;
            }

            // The commitment is live, so the seat is no longer time-boxed.
            $locked->checkout_expires_at = null;

            // Only ever an UPGRADE out of the pre-payment states. If the first
            // invoice already landed the money state is `active` or `paid`
            // already, and a decline that beat us here left `past_due` — none
            // of which this event may restate.
            if (in_array($locked->payment_status, [
                Registration::PAYMENT_NONE,
                Registration::PAYMENT_AWAITING,
            ], true)) {
                $locked->payment_status = Registration::PAYMENT_ACTIVE;
            }

            $locked->save();

            if (in_array($locked->status, [
                Registration::STATUS_PENDING,
                Registration::STATUS_CONFIRMED,
            ], true)) {
                $this->registrations->confirm($locked);
            } else {
                Log::warning('Subscription checkout completed on a non-pending seat.', [
                    'registration_id' => $locked->id,
                    'status' => $locked->status,
                ]);
            }

            $registration->refresh();
        });

        // OUTSIDE the transaction — it may talk to Stripe. Idempotent, and a
        // failure is logged rather than thrown (see the checkout service).
        $this->checkout->ensureInstallmentSchedule($registration->refresh(), $subscriptionId);
    }

    /** The single ledger row for a key, locked for this transaction. */
    private function lockPaymentByKey(string $key): ?RegistrationPayment
    {
        return RegistrationPayment::withoutMasjidScope()
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Merge-only identifier and financial writes: an identifier already
     * recorded is never overwritten and a payload that lacks one never blanks
     * it, so a second delivery is additive rather than destructive.
     *
     * Fee/net come ONLY from an expanded balance transaction. No read-back and
     * no fee formula: a registration issues no receipt, so an estimated fee
     * would be a fabricated number sitting in a financial ledger
     * (.claude/rules/stripe-payments.md).
     *
     * @param  array<string,?string>  $ids
     */
    private function mergeIdentifiers(RegistrationPayment $payment, array $ids, ?int $fee, ?int $net): void
    {
        foreach ($ids as $column => $value) {
            if (! empty($value) && $payment->{$column} === null) {
                $payment->{$column} = $value;
            }
        }

        if ($fee !== null && $payment->stripe_fee_minor === null) {
            $payment->stripe_fee_minor = $fee;
        }

        if ($net !== null && $payment->net_minor === null) {
            $payment->net_minor = $net;
        }
    }

    /**
     * What one charge on this registration is worth, used only when an invoice
     * payload carries no amount at all. Derived from the registration's own
     * snapshot through the same helper the outbound leg priced the
     * subscription with, so the two can never disagree.
     */
    private function perChargeFallback(Registration $registration): int
    {
        $feePlan = FeePlan::withoutMasjidScope()->whereKey($registration->fee_plan_id)->first();

        if (! $feePlan) {
            return (int) $registration->adjusted_total_minor;
        }

        try {
            return RegistrationCheckoutService::perChargeMinor($registration, $feePlan);
        } catch (\Throwable $e) {
            return (int) $registration->adjusted_total_minor;
        }
    }

    /**
     * The money state after a successful invoice: `paid` once an installment
     * plan's commitment is fully settled, `active` while a subscription is
     * still live. Derived from the ledger, never assumed from the event.
     */
    private function settledMoneyState(Registration $registration): string
    {
        if ($registration->payment_status === Registration::PAYMENT_PAID) {
            return Registration::PAYMENT_PAID;
        }

        return $this->isFullySettled($registration)
            ? Registration::PAYMENT_PAID
            : Registration::PAYMENT_ACTIVE;
    }

    /**
     * Has this registration's whole commitment been collected?
     *
     * Only an installment plan has a finite answer: N successful invoice rows
     * against an `installment_count` of N. An open-ended recurring plan never
     * finishes, so it is never "fully settled".
     */
    private function isFullySettled(Registration $registration): bool
    {
        $feePlan = FeePlan::withoutMasjidScope()->whereKey($registration->fee_plan_id)->first();

        if (! $feePlan || $feePlan->kind !== FeePlan::KIND_INSTALLMENT) {
            return false;
        }

        $expected = (int) $feePlan->installment_count;

        if ($expected < 1) {
            return false;
        }

        $settled = RegistrationPayment::withoutMasjidScope()
            ->where('registration_id', $registration->id)
            ->where('status', RegistrationPayment::STATUS_SUCCEEDED)
            ->count();

        return $settled >= $expected;
    }

    // --------------------------------------------------------------- resolving

    /**
     * Resolve the registration this event belongs to — tenant FIRST.
     *
     * `event.account` is matched against the masjid's stored connected-account
     * id and the uuid is then looked up WITHIN that masjid, so metadata alone
     * can never decide tenancy: masjid A's event can never advance masjid B's
     * registration. An event with no account (a platform-level event) carries
     * no verifiable tenant and is ignored — direct charges always arrive on the
     * connected account.
     *
     * ## AN OFFBOARDED ORGANISATION STILL GETS ITS LEDGER ROW
     *
     * `masjids` SOFT-deletes and `Masjid::query()` excludes trashed rows, so
     * until 2026-08-12 this lookup resolved to null for an organisation
     * offboarded while a Checkout Session was live: the family's card WAS
     * charged at Stripe, and the event was dropped with a log line. No
     * `registration_payments` row, `payment_status` left at `awaiting`,
     * `checkout_expires_at` still set — so T-006f's reaper swept the seat and
     * cancelled it. Money taken, nothing recorded, seat gone.
     *
     * Refusing the event is the WRONG direction, because the money has already
     * moved. A webhook's job is to state truthfully what Stripe did; offboarding
     * is a fact about the ORGANISATION, not about the family's payment, and
     * discarding the only local evidence of a real charge serves nobody — least
     * of all whoever has to refund it, who needs the amount, the charge id and
     * the balance transaction to do so. So the org resolves WITH TRASHED and the
     * event runs the ordinary path: ledger row booked, `payment_status` moved to
     * paid/active, seat confirmed through the same seam every other paid
     * registration uses.
     *
     * Confirming the seat is deliberate, not incidental. A settled one-time
     * charge left `pending` is a lie about a registration nothing is waiting on,
     * and it is indistinguishable on every admin screen from an abandoned
     * checkout. `paid` + `confirmed` is what actually happened; whether the
     * program can still run is the organisation's offboarding to answer, not the
     * ledger's.
     *
     * The asymmetry with the OUTBOUND leg is the point, and both directions are
     * right: `RegistrationCheckoutService` resolves the org with `Masjid::find()`
     * and therefore REFUSES to open a new Session for a trashed organisation
     * (PublicTenantLifecycleTest pins it), while this path records one that
     * already completed. Money not yet moved: refuse. Money already moved:
     * record it, and page a human.
     *
     * The live row is preferred over a trashed one should a connected account id
     * ever appear on both — a Connect account belongs to exactly one
     * organisation, so that is a data error, and routing a live organisation's
     * payment into an offboarded twin would be the expensive way to discover it.
     */
    private function resolve(array $object, ?string $account): ?Registration
    {
        $uuid = self::registrationUuid($object);

        if ($uuid === null) {
            return null;
        }

        if ($account === null || $account === '') {
            Log::warning('Registration event arrived without a connected account; ignoring.', [
                'registration_uuid' => $uuid,
            ]);

            return null;
        }

        $masjid = Masjid::where('stripe_account_id', $account)->first()
            ?? Masjid::onlyTrashed()->where('stripe_account_id', $account)->first();

        if (! $masjid) {
            Log::warning('Registration event for an unknown connected account; ignoring.', [
                'account' => $account,
            ]);

            return null;
        }

        if ($masjid->trashed()) {
            // Loud, searchable, and carrying everything a refund needs. The
            // organisation is merchant of record, so the refund is its own
            // action in its own Stripe dashboard — this line is how anybody
            // learns there is one to make.
            Log::warning(
                'Registration payment event for an OFFBOARDED organisation — recorded anyway; '
                . 'the money moved and a refund is the organisation\'s own action in its Stripe dashboard.',
                [
                    'masjid_id' => (int) $masjid->id,
                    'deleted_at' => optional($masjid->deleted_at)->toISOString(),
                    'account' => $account,
                    'registration_uuid' => $uuid,
                ]
            );
        }

        // Masjid-filtered by construction — a cross-tenant uuid is a miss.
        $registration = Registration::findByUuidForMasjid($uuid, (int) $masjid->id);

        if ($registration === null) {
            // THE ONE BRANCH THAT USED TO BE SILENT, and the only one where the
            // money has already moved and we are about to do nothing about it.
            //
            // Stripe receives a 200 for a return of null, so it never retries;
            // the reaper then releases the seat the family paid for; and there
            // was no log line anywhere, so the first anybody heard of it was the
            // family. Every other refusal in this method already says so. The
            // uuid is enough to find the charge in the organisation's own
            // dashboard, which is where the refund has to happen.
            Log::warning(
                'Registration event named a registration that does not belong to the organisation holding '
                . 'this connected account; NOTHING was recorded and Stripe will not retry. If money moved, '
                . 'it is unrecorded here and the refund is the organisation\'s own action.',
                [
                    'registration_uuid' => $uuid,
                    'masjid_id' => (int) $masjid->id,
                    'account' => $account,
                ]
            );
        }

        return $registration;
    }

    /**
     * The routing signal: our uuid in the object's metadata, or null.
     *
     * Checkout Sessions, PaymentIntents, Subscriptions and Subscription
     * Schedules all carry it at the top level, because this codebase writes it
     * there. An INVOICE does not: its metadata is its own, and what it inherits
     * is the SUBSCRIPTION's — which Stripe has exposed under different keys
     * across API versions. All the known locations are checked (the same
     * defence DonationService uses for its own uuid), so an invoice routes
     * correctly regardless of pinned version.
     *
     * Everything without the uuid in ANY of these places is left to today's
     * donation dispatch, untouched — that is the whole dispatch-safety
     * contract, and it is why these additions cannot perturb donations.
     */
    private static function registrationUuid(array $object): ?string
    {
        foreach ([
            ['metadata', 'registration_uuid'],
            ['subscription_details', 'metadata', 'registration_uuid'],
            ['parent', 'subscription_details', 'metadata', 'registration_uuid'],
            ['lines', 'data', 0, 'metadata', 'registration_uuid'],
        ] as $path) {
            $uuid = self::walk($object, $path);

            if (is_string($uuid) && $uuid !== '') {
                return $uuid;
            }
        }

        return null;
    }

    /**
     * The Stripe subscription id behind an invoice — same multi-version defence
     * as above.
     */
    private function subscriptionIdFrom(array $invoice): ?string
    {
        foreach ([
            ['subscription'],
            ['parent', 'subscription_details', 'subscription'],
            ['subscription_details', 'subscription'],
        ] as $path) {
            $id = $this->idOf(self::walk($invoice, $path));

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Payment intent, charge, balance transaction, fee and net for an invoice —
     * strictly from what the payload already carries.
     *
     * @return array{0:?string,1:?string,2:?string,3:?int,4:?int}
     */
    private function invoiceFinancials(array $invoice): array
    {
        $charge = $invoice['charge'] ?? null;

        $chargeId = $this->idOf($charge)
            ?? $this->idOf(self::walk($invoice, ['payments', 'data', 0, 'payment', 'charge']));

        $paymentIntentId = $this->idOf($invoice['payment_intent'] ?? null)
            ?? $this->idOf(self::walk($invoice, ['payments', 'data', 0, 'payment', 'payment_intent']));

        $balanceTxnId = $fee = $net = null;

        if (is_array($charge)) {
            $bt = $charge['balance_transaction'] ?? null;

            if (is_array($bt)) {
                $balanceTxnId = $this->stringOrNull($bt['id'] ?? null);
                $fee = isset($bt['fee']) ? (int) $bt['fee'] : null;
                $net = isset($bt['net']) ? (int) $bt['net'] : null;
            } else {
                $balanceTxnId = $this->stringOrNull($bt);
            }
        }

        return [$paymentIntentId, $chargeId, $balanceTxnId, $fee, $net];
    }

    /** A Stripe id whether the payload gave us the string or the expanded object. */
    private function idOf($value): ?string
    {
        if (is_array($value)) {
            return $this->stringOrNull($value['id'] ?? null);
        }

        return $this->stringOrNull($value);
    }

    /**
     * Walk a list of keys/indexes through a nested payload, tolerating every
     * missing branch. Returns null rather than throwing on a shape we did not
     * expect — an unexpected payload must never 500 a webhook.
     *
     * @param  array<int,string|int>  $path
     */
    private static function walk(array $data, array $path)
    {
        $node = $data;

        foreach ($path as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }

    /**
     * Charge id, balance transaction id, fee and net — read from what the
     * payload already carries.
     *
     * Deliberately NO Stripe read-back and NO fee formula here: unlike a
     * donation, a registration payment issues no receipt, so an estimated fee
     * would be a fabricated number sitting in a financial ledger. Absent
     * balance-transaction data leaves the columns null and reconciliation
     * fills them later.
     *
     * @return array{0:?string,1:?string,2:?int,3:?int}
     */
    private function chargeFinancials(array $pi): array
    {
        $latest = $pi['latest_charge'] ?? null;

        $chargeId = is_array($latest)
            ? $this->stringOrNull($latest['id'] ?? null)
            : $this->stringOrNull($latest);

        $balanceTxnId = $fee = $net = null;

        if (is_array($latest)) {
            $bt = $latest['balance_transaction'] ?? null;

            if (is_array($bt)) {
                $balanceTxnId = $this->stringOrNull($bt['id'] ?? null);
                $fee = isset($bt['fee']) ? (int) $bt['fee'] : null;
                $net = isset($bt['net']) ? (int) $bt['net'] : null;
            } else {
                $balanceTxnId = $this->stringOrNull($bt);
            }
        }

        return [$chargeId, $balanceTxnId, $fee, $net];
    }

    private function stringOrNull($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
