<?php

namespace App\Services\Stripe;

use App\Models\Masjid;
use App\Models\MealOrder;
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

        $paid = ($session['payment_status'] ?? null) === 'paid'
            || ($session['status'] ?? null) === 'complete';

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

        $order->markPaid($this->stringOrNull($pi['id'] ?? null));
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
