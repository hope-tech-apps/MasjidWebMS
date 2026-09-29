<?php

namespace App\Services\Stripe;

use App\Models\Masjid;
use App\Models\Order;
use App\Services\Cart\CartCheckoutService;
use App\Services\Cart\CartSettlementResult;
use App\Services\Cart\CartSettlementService;
use Illuminate\Support\Facades\Log;

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
 * `payment_intent.succeeded` settles idempotently if the session event has not.
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

        [$currency, $amount] = $this->reportedTotal($intent, self::KIND_INTENT);

        return $this->settlement->settle(
            (int) $order->id,
            $this->stringOrNull($intent['id'] ?? null),
            $amount,
            $currency,
        );
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

        $masjid = Masjid::where('stripe_account_id', $account)->first()
            ?? Masjid::onlyTrashed()->where('stripe_account_id', $account)->first();

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
