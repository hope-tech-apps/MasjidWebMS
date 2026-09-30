<?php

namespace App\Services\Stripe;

use App\Models\Masjid;
use App\Models\Order;
use App\Services\Cart\CartCheckoutService;
use App\Services\Cart\CartSettlementResult;
use App\Services\Cart\CartSettlementService;
use App\Support\CartTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbound Stripe events for a universal-cart order (slice 4b) — the fifth sibling of
 * MealOrderPaymentService, RegistrationPaymentService and FormResponsePaymentService.
 * It decides WHOSE order an event is and whether it is paid; CartSettlementService
 * does everything after that, in one transaction.
 *
 * DISPATCH SAFETY: `isCartEvent()` is true only for an object carrying
 * `metadata.cart_order_uuid` (a basket paid on the organisation's OWN account) or
 * `metadata.cart_charge_ref` (one paid on a HOLDER's account, where the uuid is never
 * sent). StripeWebhookController asks it AFTER every older question and BEFORE the
 * donation default, so:
 *
 *   - a cart event can never be booked as a Donation, a registration payment, a meal
 *     order or a form response through the old arms (without this arm it would fall to
 *     the donation default and be silently booked as one);
 *   - and because it is asked last, no payload that reached an older arm yesterday
 *     reaches this one today: the keys are new, so every existing route is unchanged.
 *
 * TENANCY comes from `event.account`, never from metadata:
 *
 *   - own account: the masjid holding that `stripe_account_id` (a trashed one too, so
 *     an offboarded organisation's money is still recorded), then the order by uuid
 *     WITHIN that masjid;
 *   - linked: the order by its opaque `charge_ref`, then `hash_equals` of its pinned
 *     `charge_account_id` against `event.account`. The holder's Stripe users can read and
 *     write that account's metadata, so a linked event is also accepted only for the page
 *     the app itself recorded on the order.
 *
 * Paid means `payment_status === 'paid'`, never `status === 'complete'` alone: a delayed
 * method completes the page with money still unmoved. The basket's page is card-only, so
 * an unpaid completion should never arrive; if it does, nothing is settled.
 * `payment_intent.succeeded` settles idempotently if the session event has not, unless it
 * is in another currency than the order's (a localised payment): that one is skipped at
 * info level and only the session event may report a refundable mismatch. A session event
 * that follows the intent's settlement backfills the payer (CartSettlementService).
 *
 * Refusals are logged at WARNING and return normally. A 500 would make Stripe retry an
 * event that can never succeed. (A refusal is not a failure to record: the settlement
 * itself, which does throw on a genuine failure so Stripe retries a paid basket that could
 * not be written, is CartSettlementService's contract.)
 */
class CartPaymentService
{
    private const KIND_SESSION = 'session';

    private const KIND_INTENT = 'intent';

    public function __construct(private readonly CartSettlementService $settlement)
    {
    }

    /** The routing signal: one of our two keys in the object's metadata (top level). */
    public static function isCartEvent(array $object): bool
    {
        return self::orderUuid($object) !== null || self::chargeRef($object) !== null;
    }

    /**
     * checkout.session.completed, and checkout.session.async_payment_succeeded (the same
     * session, later `paid`): the same handler, so a delayed method settles the moment its
     * money lands.
     */
    public function handleCheckoutCompleted(array $session, ?string $account): CartSettlementResult
    {
        $order = $this->resolve($session, $account, self::KIND_SESSION);

        if ($order === null) {
            return CartSettlementResult::none();
        }

        // Before anything that can refuse or throw: see recordPaymentIntent().
        $this->recordPaymentIntent($order, $this->stringOrNull($session['payment_intent'] ?? null));

        // `payment_status`, never `status`: every completed session is `complete`.
        if (($session['payment_status'] ?? null) !== 'paid') {
            Log::warning(
                'A cart checkout page completed without being paid; nothing was recorded. '
                . 'It settles when payment_intent.succeeded or checkout.session.async_payment_succeeded arrives.',
                ['order_id' => (int) $order->id, 'masjid_id' => (int) $order->masjid_id, 'payment_status' => $session['payment_status'] ?? null]
            );

            return CartSettlementResult::none();
        }

        [$currency, $amount] = $this->reportedTotal($session, self::KIND_SESSION);

        return $this->settlement->settle(
            (int) $order->id,
            $this->stringOrNull($session['payment_intent'] ?? null),
            $amount,
            $currency,
            $this->stringOrNull($session['id'] ?? null),
            is_array($session['customer_details'] ?? null) ? $session['customer_details'] : [],
        );
    }

    /** payment_intent.succeeded: settles the order if the session event has not already. */
    public function handlePaymentIntentSucceeded(array $intent, ?string $account): CartSettlementResult
    {
        $order = $this->resolve($intent, $account, self::KIND_INTENT);

        if ($order === null) {
            return CartSettlementResult::none();
        }

        $this->recordPaymentIntent($order, $this->stringOrNull($intent['id'] ?? null));

        [$currency, $amount] = $this->reportedTotal($intent, self::KIND_INTENT);

        // A payment intent in another currency than the order's is a localised one (Adaptive
        // Pricing is switched off on our pages, but a dashboard or an old page may still
        // carry it): its converted amount can never equal the order's total, and it says
        // nothing about a refundable mismatch, because the SESSION event reports the
        // integration's own currency and total (`currency_conversion`) and settles it. Only
        // the session event may report a mismatch as one to refund, so the intent is skipped
        // quietly and settlement is left to it.
        if ($currency !== strtolower((string) $order->currency)) {
            Log::info('A cart payment intent is in a different currency than its order; it is left to the checkout session event to settle.', [
                'order_id' => (int) $order->id,
                'masjid_id' => (int) $order->masjid_id,
                'order_currency' => strtolower((string) $order->currency),
                'intent_currency' => $currency,
            ]);

            return CartSettlementResult::none();
        }

        return $this->settlement->settle(
            (int) $order->id,
            $this->stringOrNull($intent['id'] ?? null),
            $amount,
            $currency,
        );
    }

    /**
     * Remember which payment an event says this order is, as soon as the event has identified
     * the order and the page, and BEFORE settlement is tried.
     *
     * The intent used to be written only in the save that marks the order paid, inside the
     * settlement transaction. A settlement that refused (an amount that did not match) or threw
     * (a line that could not be recorded) left the order with no intent on record, so a
     * `charge.refunded` or `charge.dispute.created` for that very payment found no order
     * (flagOrder looks orders up by intent), was logged at info and lost: Stripe does not
     * redeliver a refund or a dispute. Written here, outside the settlement's transaction, the
     * intent survives whatever settlement does, and flagOrder finds the pending order and flags it.
     *
     * HAVING AN INTENT MEANS NOTHING ABOUT PAYMENT. Only `status` says an order is paid
     * (Order::isPaid()), and only settlement moves it. Nothing reads this column as "paid":
     * the portal, the payment-state read, checkout and the prune all test `status`, and the prune
     * treats a pending order with an intent as a payment to reconcile, never as a sale.
     *
     * It is written once (`whereNull`, so a recorded intent is never replaced and two events
     * cannot race each other's value), and a failure to write it is logged and swallowed: the
     * settlement that follows is the more important write and reaches the same database.
     */
    private function recordPaymentIntent(Order $order, ?string $intentId): void
    {
        if ($intentId === null || $order->stripe_payment_intent_id !== null) {
            return;
        }

        try {
            $written = Order::withoutMasjidScope()
                ->whereKey($order->id)
                ->where('masjid_id', $order->masjid_id)
                ->whereNull('stripe_payment_intent_id')
                ->update(['stripe_payment_intent_id' => $intentId]);

            if ($written === 1) {
                $order->setAttribute('stripe_payment_intent_id', $intentId);
                $order->syncOriginalAttribute('stripe_payment_intent_id');
            }
        } catch (Throwable $e) {
            Log::warning('The payment intent of a cart order could not be recorded ahead of settlement; a refund that arrives before the order settles would not find it.', [
                'order_id' => (int) $order->id,
                'masjid_id' => (int) $order->masjid_id,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * checkout.session.expired: pending → expired, and only that. An order already paid
     * (or already expired) is untouched, so an expiry that arrives after the payment
     * changes nothing.
     */
    public function handleCheckoutExpired(array $session, ?string $account): void
    {
        $order = $this->resolve($session, $account, self::KIND_SESSION);

        if ($order === null) {
            return;
        }

        Order::withoutMasjidScope()
            ->whereKey($order->id)
            ->where('masjid_id', $order->masjid_id)
            ->where('status', Order::STATUS_PENDING)
            ->update(['status' => Order::STATUS_EXPIRED]);
    }

    /**
     * A delayed payment failed after its page completed. Nothing was recorded, so there is
     * nothing to undo: the order stays pending, and the failure is logged.
     */
    public function handleAsyncPaymentFailed(array $session, ?string $account): void
    {
        $order = $this->resolve($session, $account, self::KIND_SESSION);

        Log::warning('A delayed payment for a cart order failed; nothing was recorded.', [
            'order_id' => $order?->id,
            'masjid_id' => $order?->masjid_id,
            'checkout_session_id' => $session['id'] ?? null,
            'account' => $account,
        ]);
    }

    /**
     * charge.refunded / charge.dispute.created on a BASKET's charge: flag the ORDER, never a
     * line. A basket has one charge and one payment intent, and Stripe says only how much of
     * it was refunded, never which line, so any per-line flag would be a guess. The order
     * carries `charge_flag` (refunded | partially_refunded | disputed), `charge_refunded_minor`
     * (the latest `amount_refunded`, recorded and never added to, so a replay changes nothing)
     * and `charge_flagged_at`, and a WARNING names the order for staff to reconcile by hand.
     *
     * The order is the one whose recorded payment intent the event names, found only when its
     * pinned account equals `event.account` (`hash_equals`) and it belongs to the organisation
     * that account resolves to. A holder's account is the one exception to "the organisation
     * holding the account": a linked basket's order belongs to the CHILD, so an order that
     * carries a `charge_ref` is accepted on its pinned account alone. Metadata never decides.
     * The organisation holding the account is the same one settlement resolves (a live one
     * before a trashed one: `accountHolder()`).
     *
     * An order that names the payment but is NOT PAID yet is flagged all the same, with a
     * warning that it was flagged before settlement recorded it: Stripe does not redeliver a
     * refund or dispute, so ignoring it would lose it. An order names its payment from the first
     * session or payment-intent event that identified it, before settlement is tried
     * (recordPaymentIntent()), so a payment settlement refused or failed to record is still
     * found. A charge that is no basket's (every donation, lunch and registration refund)
     * writes nothing, exactly as before this arm existed, and is logged at info so there is a
     * trace. It never throws: a 500 would only make Stripe retry, and a lost flag is logged at
     * error.
     *
     * The form registrations such a basket settled are the cart's, not the form arm's
     * (FormResponsePaymentService::handleChargeFlag skips them).
     *
     * @param  string  $flag  Order::CHARGE_FLAG_REFUNDED | Order::CHARGE_FLAG_DISPUTED
     */
    public function handleChargeFlag(array $object, ?string $account, string $flag): void
    {
        // bin/deploy makes the code live before `migrate`: with no orders table there is no basket
        // to find, and asking would log a false error-level alarm on every refund in that window.
        if (! CartTables::has('orders')) {
            return;
        }

        try {
            $this->flagOrder($object, $account, $flag);
        } catch (Throwable $e) {
            Log::error('A refund or dispute on a cart basket\'s charge could not be recorded on its order; staff must check the order by hand.', [
                'flag' => $flag,
                'account' => $account,
                'exception' => $e::class,
            ]);
        }
    }

    private function flagOrder(array $object, ?string $account, string $flag): void
    {
        $intent = $object['payment_intent'] ?? null;
        $intentId = is_array($intent) ? $this->stringOrNull($intent['id'] ?? null) : $this->stringOrNull($intent);

        if ($intentId === null || $account === null || $account === '') {
            return;
        }

        $candidates = Order::withoutMasjidScope()->where('stripe_payment_intent_id', $intentId)->get();

        if ($candidates->isEmpty()) {
            // Nearly every charge that lands here is not a basket's (a donation, a lunch order, a
            // registration). Nothing is written, as before; the line is the trace that this arm
            // looked and found no order carrying the payment.
            Log::info('A refund or dispute names a payment that no cart order carries; it is not a basket\'s charge, so no order was flagged.', [
                'flag' => $flag,
                'account' => $account,
                'payment_intent' => $intentId,
            ]);

            return;
        }

        $holder = self::accountHolder($account);

        $order = $candidates->first(fn (Order $o): bool => hash_equals((string) $o->charge_account_id, $account)
            && ($o->charge_ref !== null || ($holder !== null && (int) $o->masjid_id === (int) $holder->id)));

        if ($order === null) {
            Log::warning('A refund or dispute named a cart order\'s payment, but on an account the order was not charged on; nothing was flagged.', [
                'flag' => $flag,
                'account' => $account,
                'payment_intent' => $intentId,
            ]);

            return;
        }

        DB::transaction(function () use ($order, $object, $flag): void {
            $locked = Order::withoutMasjidScope()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            // An order that names this payment but is not paid yet is flagged all the same: Stripe
            // will not deliver the event again, so returning quietly would lose a dispute for good.
            $beforeSettlement = ! $locked->isPaid();

            $recorded = (int) $locked->charge_refunded_minor;
            $refunded = $flag === Order::CHARGE_FLAG_DISPUTED ? null : $this->refundedMinor($object, $locked);
            // Stripe's figure is cumulative, so the latest is the largest: a late or repeated
            // delivery of an older event can never move it back, and none is ever added.
            $latest = max($recorded, $refunded ?? 0);

            $target = match (true) {
                $flag === Order::CHARGE_FLAG_DISPUTED => Order::CHARGE_FLAG_DISPUTED,
                // A dispute is never overwritten by a refund (it is the one with a deadline).
                $locked->charge_flag === Order::CHARGE_FLAG_DISPUTED => Order::CHARGE_FLAG_DISPUTED,
                $latest >= (int) $locked->total_minor,
                $refunded === null && ($object['refunded'] ?? null) === true,
                $locked->charge_flag === Order::CHARGE_FLAG_REFUNDED => Order::CHARGE_FLAG_REFUNDED,
                default => Order::CHARGE_FLAG_PARTIALLY_REFUNDED,
            };

            $changes = [];

            if ($target !== $locked->charge_flag) {
                $changes['charge_flag'] = $target;
            }

            if ($latest !== $recorded) {
                $changes['charge_refunded_minor'] = $latest;
            }

            if ($changes === []) {
                return;
            }

            $locked->forceFill($changes + ['charge_flagged_at' => now()])->save();

            Log::warning(
                'A cart basket\'s charge was ' . ($flag === Order::CHARGE_FLAG_DISPUTED ? 'disputed' : 'refunded') . ' on Stripe. '
                . "The order {$locked->order_number} is flagged, but a basket has one charge and Stripe does not say which line was refunded or disputed, "
                . 'so the lines cannot be attributed automatically: staff must reconcile the order\'s lines by hand.'
                . ($beforeSettlement
                    ? " The order is {$locked->status}, not paid: it was flagged before settlement recorded it, so check that payment as well."
                    : ''),
                [
                    'order_id' => (int) $locked->id,
                    'order_number' => (string) $locked->order_number,
                    'masjid_id' => (int) $locked->masjid_id,
                    'status' => (string) $locked->status,
                    'flag' => $locked->charge_flag,
                    'refunded_minor' => (int) $locked->charge_refunded_minor,
                    'total_minor' => (int) $locked->total_minor,
                ]
            );
        });
    }

    /** A charge's amount_refunded in the order's currency, or null when it cannot be read as one. */
    private function refundedMinor(array $charge, Order $order): ?int
    {
        $amount = $charge['amount_refunded'] ?? null;
        $currency = strtolower((string) ($charge['currency'] ?? ''));

        if (! is_int($amount) || $amount < 0 || $currency === '' || $currency !== strtolower((string) $order->currency)) {
            return null;
        }

        return $amount;
    }

    /**
     * The organisation holding a connected account: a LIVE one when there is one, else a
     * trashed one (an offboarded organisation's money is still recorded). A soft-deleted and a
     * live organisation can share an account id (the unique index covers live rows only), so
     * "the first of either" could name the trashed one; one lookup, used by settlement's
     * `resolve()`, by a refund's `flagOrder()` and by the settlement's pin of a linked
     * registration (CartSettlementService::pinToHolder), keeps them answering alike. Several
     * trashed organisations can share an id too, so the trashed lookup is ordered: the same
     * answer every time, not whichever row the database returns first.
     */
    public static function accountHolder(string $account): ?Masjid
    {
        return Masjid::where('stripe_account_id', $account)->first()
            ?? Masjid::onlyTrashed()->where('stripe_account_id', $account)->orderBy('id')->first();
    }

    /**
     * The order an event is about, decided by the event's connected account, or null (and
     * a warning) when it cannot be one of ours. Every refusal is logged; the ones where
     * money may have moved and nothing is recorded say so, because the merchant of record
     * owns the refund.
     */
    private function resolve(array $object, ?string $account, string $kind): ?Order
    {
        $ref = self::chargeRef($object);

        if ($ref !== null) {
            return $this->resolveLinked($object, $account, $kind, $ref);
        }

        $uuid = self::orderUuid($object);

        if ($uuid === null) {
            return null;
        }

        if ($account === null || $account === '') {
            Log::warning('A cart payment event arrived without a connected account; nothing was recorded.', [
                'object' => $object['id'] ?? null,
            ]);

            return null;
        }

        $masjid = self::accountHolder($account);

        if ($masjid === null) {
            Log::warning('A cart payment event is for a connected account no organisation holds; nothing was recorded.', [
                'account' => $account,
                'object' => $object['id'] ?? null,
            ]);

            return null;
        }

        // The uuid is looked up WITHIN the organisation that holds the account.
        $order = Order::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->where('uuid', $uuid)
            ->first();

        if ($order === null) {
            Log::warning(
                'A cart payment event named an order that does not belong to the organisation holding this connected account; '
                . 'NOTHING was recorded and Stripe will not retry. If money moved, the refund is the organisation\'s own action in its Stripe dashboard.',
                ['masjid_id' => (int) $masjid->id, 'account' => $account, 'object' => $object['id'] ?? null]
            );

            return null;
        }

        // The page was opened on this account and the order pins it. They cannot differ for
        // an honest event; an order that names another account is not this account's.
        if (! hash_equals((string) $order->charge_account_id, $account)) {
            Log::warning('A cart payment event came from an account other than the one the order was opened on; nothing was recorded.', [
                'order_id' => (int) $order->id,
                'masjid_id' => (int) $masjid->id,
                'account' => $account,
            ]);

            return null;
        }

        return $this->pageMatches($order, $object, $kind, false) ? $order : null;
    }

    /**
     * A linked basket's event: the order by its reference, then the pinned account. The
     * reference is what a holder's Stripe users can see, so it is never enough alone.
     */
    private function resolveLinked(array $object, ?string $account, string $kind, string $ref): ?Order
    {
        if ($account === null || $account === '') {
            Log::warning('A linked cart payment event arrived without a connected account; nothing was recorded.', [
                'object' => $object['id'] ?? null,
            ]);

            return null;
        }

        $order = Order::withoutMasjidScope()->where('charge_ref', $ref)->first();

        if ($order === null) {
            Log::warning(
                'A linked cart payment event named a charge reference no order carries; NOTHING was recorded. '
                . 'If money moved, the refund is the account holder\'s action in its Stripe dashboard.',
                ['account' => $account, 'object' => $object['id'] ?? null]
            );

            return null;
        }

        if (! hash_equals((string) $order->charge_account_id, $account)) {
            Log::warning('A linked cart payment event came from an account other than the one the order was pinned to; nothing was recorded.', [
                'order_id' => (int) $order->id,
                'masjid_id' => (int) $order->masjid_id,
                'account' => $account,
            ]);

            return null;
        }

        return $this->pageMatches($order, $object, $kind, true) ? $order : null;
    }

    /**
     * A session event must be for the page the app recorded on the order. On the
     * organisation's own account an order that has recorded no page yet is accepted (the
     * account and uuid already agree); on a holder's account the page MUST be recorded and
     * match, because the holder's own users can write metadata there. A payment intent
     * carries no session, and is held to the exact amount and currency at settlement.
     */
    private function pageMatches(Order $order, array $object, string $kind, bool $linked): bool
    {
        if ($kind !== self::KIND_SESSION) {
            return true;
        }

        $seen = $this->stringOrNull($object['id'] ?? null);
        $recorded = $this->stringOrNull($order->stripe_checkout_session_id);

        $ok = $linked
            ? ($seen !== null && $recorded !== null && hash_equals($recorded, $seen))
            : ($seen === null || $recorded === null || hash_equals($recorded, $seen));

        if (! $ok) {
            Log::warning('A cart payment event is for a checkout page the order never recorded; nothing was recorded.', [
                'order_id' => (int) $order->id,
                'masjid_id' => (int) $order->masjid_id,
                'linked' => $linked,
                'checkout_session_id' => $seen,
            ]);
        }

        return $ok;
    }

    /**
     * The currency and total an object reports in the INTEGRATION's terms: for a session,
     * `currency_conversion` (source_currency, amount_total) when Adaptive Pricing localised
     * it, else its own currency and amount_total; for a payment intent, its currency and
     * amount_received. The amount is null unless it is an integer.
     *
     * @return array{0: string, 1: ?int}
     */
    private function reportedTotal(array $object, string $kind): array
    {
        if ($kind === self::KIND_SESSION) {
            $conversion = $object['currency_conversion'] ?? null;

            if (is_array($conversion) && is_string($conversion['source_currency'] ?? null) && $conversion['source_currency'] !== '') {
                $amount = $conversion['amount_total'] ?? null;

                return [strtolower($conversion['source_currency']), is_int($amount) ? $amount : null];
            }

            $amount = $object['amount_total'] ?? null;
        } else {
            $amount = $object['amount_received'] ?? ($object['amount'] ?? null);
        }

        return [strtolower((string) ($object['currency'] ?? '')), is_int($amount) ? $amount : null];
    }

    /** Our uuid in the object's metadata, or null. Sessions and PaymentIntents both carry it top-level. */
    private static function orderUuid(array $object): ?string
    {
        $uuid = $object['metadata'][CartCheckoutService::METADATA_KEY] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /** A linked basket's opaque reference in the object's metadata, or null. */
    private static function chargeRef(array $object): ?string
    {
        $ref = $object['metadata'][CartCheckoutService::CHARGE_REF_KEY] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
