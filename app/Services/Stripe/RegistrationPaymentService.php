<?php

namespace App\Services\Stripe;

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
 */
class RegistrationPaymentService
{
    public function __construct(private RegistrationService $registrations)
    {
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

        $masjid = Masjid::where('stripe_account_id', $account)->first();

        if (! $masjid) {
            Log::warning('Registration event for an unknown connected account; ignoring.', [
                'account' => $account,
            ]);

            return null;
        }

        // Masjid-filtered by construction — a cross-tenant uuid is a miss.
        return Registration::findByUuidForMasjid($uuid, (int) $masjid->id);
    }

    /** The routing signal: our uuid in the object's metadata, or null. */
    private static function registrationUuid(array $object): ?string
    {
        $uuid = $object['metadata']['registration_uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
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
