<?php

namespace App\Services\Stripe;

use App\Models\Masjid;
use App\Models\MealOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The INBOUND (webhook) leg of an online meal order — the source of truth for
 * an order's paid state, mirroring RegistrationPaymentService but far simpler:
 * an order has no fee plan, no installments, and no separate payment ledger, so
 * settlement is one idempotent `markPaid()` on the order row itself.
 *
 * DISPATCH SAFETY: `isOrderEvent()` is true only for an object carrying
 * `metadata.order_uuid`. StripeWebhookController routes those here and leaves
 * every donation/registration event on its existing path, byte-for-byte — an
 * order event can never touch a donation or a registration, and vice versa.
 *
 * Both success events resolve the same order and both call `markPaid()`, which
 * is idempotent, so a duplicate or out-of-order delivery (session vs
 * payment_intent) converges to one paid order with one `paid_at`.
 */
class MealOrderPaymentService
{
    /** The routing signal: our uuid in the object's metadata (top level). */
    public static function isOrderEvent(array $object): bool
    {
        return self::orderUuid($object) !== null;
    }

    /**
     * A completed Checkout Session. "Completed" only means money moved when the
     * session is `paid` — an async method can complete a session while the
     * charge is still pending, so an unpaid completion records the handle and
     * advances nothing.
     */
    public function handleCheckoutCompleted(array $session, ?string $account): void
    {
        $order = $this->resolve($session, $account);

        if (! $order) {
            return;
        }

        $sessionId = $this->stringOrNull($session['id'] ?? null);
        $paymentIntentId = $this->stringOrNull($session['payment_intent'] ?? null);

        // `payment_status`, never `status`: every completed session is
        // `complete`, including a bank debit whose money has not moved yet. That
        // one is settled later by checkout.session.async_payment_succeeded (this
        // same method, the session now `paid`) or by payment_intent.succeeded.
        $paid = ($session['payment_status'] ?? null) === 'paid';

        if (! $paid) {
            if ($sessionId && $order->stripe_checkout_session_id === null) {
                $order->stripe_checkout_session_id = $sessionId;
                $order->save();
            }

            return;
        }

        if ($sessionId && $order->stripe_checkout_session_id === null) {
            $order->stripe_checkout_session_id = $sessionId;
        }

        $this->warnIfCancelled($order);
        $order->markPaid($paymentIntentId);
    }

    /**
     * A succeeded PaymentIntent. Fills in / confirms the paid state for the
     * order the session event may have already touched, or lands first itself.
     */
    public function handlePaymentIntentSucceeded(array $pi, ?string $account): void
    {
        $order = $this->resolve($pi, $account);

        if (! $order) {
            return;
        }

        $this->warnIfCancelled($order);
        $order->markPaid($this->stringOrNull($pi['id'] ?? null));
    }

    /**
     * A delayed payment (a bank debit) for this order's page failed after the page
     * completed. Nothing was marked paid on that completion, so nothing is undone:
     * the order stays unpaid, and the failure is said out loud for whoever
     * reconciles the board.
     *
     * That page is spent. It is `complete`, so it can never be paid again, and
     * while the order still points at it staff cannot send a new one
     * (MealOrderCheckoutService::paymentLink() refuses a completed page). It is
     * forgotten under the order row lock that the payment link, cancel and Mark
     * paid take, and only while it is still the order's page and nothing paid.
     */
    public function handleAsyncPaymentFailed(array $session, ?string $account): void
    {
        $order = $this->resolve($session, $account);

        if (! $order) {
            return;
        }

        $sessionId = $this->stringOrNull($session['id'] ?? null);

        $pageForgotten = DB::transaction(function () use ($order, $sessionId): bool {
            $locked = MealOrder::withoutMasjidScope()->lockForUpdate()->find($order->id);

            if (! $locked
                || $sessionId === null
                || $locked->stripe_checkout_session_id !== $sessionId
                || $locked->payment_status === MealOrder::PAYMENT_PAID) {
                return false;
            }

            $locked->stripe_checkout_session_id = null;
            $locked->save();

            return true;
        });

        Log::warning('A delayed payment for a meal order failed; the order stays unpaid.', [
            'order_uuid' => $order->uuid,
            'masjid_id' => (int) $order->masjid_id,
            'checkout_session_id' => $sessionId,
            'page_forgotten' => $pageForgotten,
        ]);
    }

    /**
     * Money for an order staff had cancelled, paid in the moment before its page
     * was closed. It is recorded (the organisation owns the refund) and said out
     * loud, because the board leaves cancelled orders out of the kitchen count.
     */
    private function warnIfCancelled(MealOrder $order): void
    {
        if ($order->status === MealOrder::STATUS_CANCELLED && $order->payment_status !== MealOrder::PAYMENT_PAID) {
            Log::warning('A cancelled meal order was paid on Stripe; the organisation should refund it or restore the order.', [
                'order_uuid' => $order->uuid,
                'masjid_id' => (int) $order->masjid_id,
            ]);
        }
    }

    /**
     * Resolve the order this event names, tenant-safely: the masjid comes from
     * the connected account the event was raised on, and the order by uuid
     * WITHIN that masjid — so a cross-tenant uuid is a miss, never a leak. Every
     * refusal is logged; the one branch where money may have moved and nothing
     * is recorded is loud, because the org (merchant of record) owns the refund.
     */
    private function resolve(array $object, ?string $account): ?MealOrder
    {
        $uuid = self::orderUuid($object);

        if ($uuid === null) {
            return null;
        }

        if ($account === null || $account === '') {
            Log::warning('Meal-order event arrived without a connected account; ignoring.', [
                'order_uuid' => $uuid,
            ]);

            return null;
        }

        $masjid = Masjid::where('stripe_account_id', $account)->first()
            ?? Masjid::onlyTrashed()->where('stripe_account_id', $account)->first();

        if (! $masjid) {
            Log::warning('Meal-order event for an unknown connected account; ignoring.', [
                'account' => $account,
            ]);

            return null;
        }

        $order = MealOrder::findByUuidForMasjid($uuid, (int) $masjid->id);

        if ($order === null) {
            Log::warning(
                'Meal-order event named an order that does not belong to the organisation holding this '
                . 'connected account; NOTHING was recorded and Stripe will not retry. If money moved, the '
                . 'refund is the organisation\'s own action in its Stripe dashboard.',
                [
                    'order_uuid' => $uuid,
                    'masjid_id' => (int) $masjid->id,
                    'account' => $account,
                ]
            );
        }

        return $order;
    }

    /** Our uuid in the object's metadata, or null. Sessions and PaymentIntents both carry it top-level. */
    private static function orderUuid(array $object): ?string
    {
        $uuid = $object['metadata']['order_uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    private function stringOrNull($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
