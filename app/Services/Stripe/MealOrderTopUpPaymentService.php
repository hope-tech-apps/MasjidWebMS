<?php

namespace App\Services\Stripe;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Models\MealOrderTopUp;
use App\Services\Lunch\LunchOrderMailer;
use App\Services\Lunch\MealOrderEditor;
use App\Support\LunchLineRefusal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The INBOUND leg of a paid lunch order's top-up (owner, 2026-09-24): the webhook
 * that pays the difference is what applies the change the customer asked for.
 *
 * DISPATCH SAFETY. A top-up's session carries `metadata.kind` =
 * MealOrderTopUp::STRIPE_KIND. StripeWebhookController asks that question FIRST,
 * before the order question, because the session also carries `order_uuid` and
 * MealOrderPaymentService must never read a top-up as the order's own payment
 * (it would mark nothing new, but a hand-paid order would be logged as paid twice).
 * The payment intent carries `kind` too and no `order_uuid` at all.
 *
 * Every refusal is LOGGED at warning, never thrown: a 500 makes Stripe retry an
 * event that can never succeed. Tenancy comes from `event.account`, and the top-up
 * is looked up by id WITHIN that masjid, so another organisation's id is a miss.
 *
 * The money is never lost. When the order moved after the top-up was asked for
 * (staff edited it, cancelled it, or it is no longer paid), the plates are NOT
 * applied — the change was priced against an order that no longer exists — but the
 * payment is still recorded on the order (`settled_total_minor` += the amount), so
 * the board shows it as owed back, and the top-up is marked `conflict`.
 */
class MealOrderTopUpPaymentService
{
    public function __construct(
        private MealOrderEditor $editor,
        private LunchOrderMailer $mailer,
    ) {
    }

    /** The routing signal: `metadata.kind` on the session or its payment intent. */
    public static function isTopUpEvent(array $object): bool
    {
        return ($object['metadata']['kind'] ?? null) === MealOrderTopUp::STRIPE_KIND;
    }

    /**
     * checkout.session.completed (and async_payment_succeeded, the same session
     * later `paid`). Pages are card only, so `unpaid` should never arrive; if it
     * does, nothing is recorded until the money lands.
     */
    public function handleCheckoutCompleted(array $session, ?string $account): void
    {
        $found = $this->resolve($session, $account);

        if ($found === null) {
            return;
        }

        [$topUp, $order] = $found;

        if (($session['payment_status'] ?? null) !== 'paid') {
            Log::warning('A lunch top-up page completed without being paid; nothing was recorded until its money lands.', [
                'top_up_id' => (int) $topUp->id,
                'masjid_id' => (int) $topUp->masjid_id,
                'payment_status' => $session['payment_status'] ?? null,
            ]);

            return;
        }

        $amountTotal = $session['amount_total'] ?? null;
        $currency = strtolower((string) ($session['currency'] ?? ''));

        if (! is_int($amountTotal) || $amountTotal !== (int) $topUp->amount_minor
            || ($currency !== '' && $currency !== strtolower((string) $order->currency))) {
            Log::warning('A lunch top-up was paid for a different amount than it asked for; NOTHING was recorded. The organisation should check this payment in its Stripe dashboard.', [
                'top_up_id' => (int) $topUp->id,
                'masjid_id' => (int) $topUp->masjid_id,
                'expected_minor' => (int) $topUp->amount_minor,
                'amount_total' => $amountTotal,
                'currency' => $currency,
            ]);

            return;
        }

        $paymentIntentId = is_string($session['payment_intent'] ?? null) && $session['payment_intent'] !== ''
            ? $session['payment_intent']
            : null;

        $applied = DB::transaction(function () use ($topUp, $order, $paymentIntentId): ?MealOrderTopUp {
            $lockedTopUp = MealOrderTopUp::withoutMasjidScope()->lockForUpdate()->find($topUp->id);

            // Replays, and the second of Stripe's two success events, stop here.
            if (! $lockedTopUp
                || in_array($lockedTopUp->status, [MealOrderTopUp::STATUS_APPLIED, MealOrderTopUp::STATUS_CONFLICT], true)) {
                return null;
            }

            $row = MealOrder::withoutMasjidScope()->with('items')->lockForUpdate()->findOrFail($order->id);
            $lockedTopUp->stripe_payment_intent_id = $paymentIntentId;

            $why = $this->conflict($lockedTopUp, $row);

            if ($why === null) {
                $menu = MealMenu::withoutMasjidScope()
                    ->where('masjid_id', $row->masjid_id)
                    ->whereKey($row->meal_menu_id)
                    ->first();

                $why = $this->apply($lockedTopUp, $row, $menu);
            }

            if ($why !== null) {
                $this->recordConflict($lockedTopUp, $row, $why);

                return null;
            }

            return $lockedTopUp;
        });

        if ($applied !== null) {
            // After the commit, never inside it: a queued mail must not describe a
            // change a rollback took back.
            $this->mailer->topUpApplied(
                MealOrder::withoutMasjidScope()->with('items')->findOrFail($order->id),
                $applied
            );
        }
    }

    /**
     * checkout.session.expired: the customer never paid the difference, so the
     * change they asked for is dropped and the order is exactly as it was.
     */
    public function handleCheckoutExpired(array $session, ?string $account): void
    {
        $found = $this->resolve($session, $account);

        if ($found === null) {
            return;
        }

        [$topUp] = $found;

        MealOrderTopUp::withoutMasjidScope()
            ->whereKey($topUp->id)
            ->where('status', MealOrderTopUp::STATUS_PENDING)
            ->update(['status' => MealOrderTopUp::STATUS_EXPIRED, 'updated_at' => Carbon::now()]);
    }

    /**
     * Why the change must NOT be applied to the order as it stands now, or null.
     * Asked on the LOCKED rows.
     */
    private function conflict(MealOrderTopUp $topUp, MealOrder $row): ?string
    {
        if ($topUp->status !== MealOrderTopUp::STATUS_PENDING) {
            // Superseded by a newer change, or its page was closed: paid anyway.
            return 'the top-up had already been closed';
        }

        if ($row->status === MealOrder::STATUS_CANCELLED) {
            return 'the order was cancelled';
        }

        if ($row->payment_status !== MealOrder::PAYMENT_PAID) {
            return 'the order is no longer marked paid';
        }

        if ((int) $row->total_minor !== (int) $topUp->base_total_minor
            || $row->settledMinor() !== (int) $topUp->base_settled_minor) {
            return 'the order was changed after the top-up was asked for';
        }

        return null;
    }

    /**
     * Apply the lines through the shared editor, then record the money: paid in
     * full at the new total. Returns why it could not be applied, or null.
     *
     * The editor re-prices from the menu. If that no longer comes to the total the
     * customer paid against (a price changed, a dish went unavailable), the plates
     * are not applied and the payment is recorded as a conflict instead: charging
     * one total and writing another is the one thing this must never do.
     */
    private function apply(MealOrderTopUp $topUp, MealOrder $row, ?MealMenu $menu): ?string
    {
        if ($menu === null) {
            return 'the order\'s menu is gone';
        }

        $wanted = $topUp->wanted();

        try {
            $quote = MealOrderEditor::quote($row, $menu, $wanted);
        } catch (LunchLineRefusal $e) {
            return 'the menu no longer offers what was asked for';
        }

        if ((int) $quote['total_minor'] !== (int) $topUp->proposed_total_minor) {
            return 'the menu prices changed after the top-up was asked for';
        }

        $baseTotal = (int) $topUp->base_total_minor;
        $baseSettled = (int) $topUp->base_settled_minor;

        try {
            // Nested in the caller's transaction (a savepoint), so a refusal here
            // rolls back only the edit, and the payment is still recorded below.
            $result = $this->editor->apply(
                $row,
                $menu,
                $wanted,
                MealOrderEditor::ACTOR_CUSTOMER,
                null,
                function (MealOrder $locked) use ($baseTotal, $baseSettled): void {
                    if ((int) $locked->total_minor !== $baseTotal || $locked->settledMinor() !== $baseSettled) {
                        throw new \RuntimeException('the order was changed after the top-up was asked for');
                    }
                }
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // Before RuntimeException, which it extends: a database failure is not
            // a conflict to record. Thrown, so Stripe retries the event.
            throw $e;
        } catch (\RuntimeException $e) {
            return $e instanceof LunchLineRefusal
                ? 'the menu no longer offers what was asked for'
                : $e->getMessage();
        }

        $order = $result['order'];
        $order->settled_total_minor = $baseSettled + (int) $topUp->amount_minor;
        $order->save();

        $topUp->status = MealOrderTopUp::STATUS_APPLIED;
        $topUp->applied_at = Carbon::now();
        $topUp->save();

        return null;
    }

    /** The money is recorded, the plates are not, and somebody is told. */
    private function recordConflict(MealOrderTopUp $topUp, MealOrder $row, string $why): void
    {
        $before = $row->settledMinor();

        $row->settled_total_minor = $before + (int) $topUp->amount_minor;
        $row->save();

        $topUp->status = MealOrderTopUp::STATUS_CONFLICT;
        $topUp->save();

        Log::warning('A lunch top-up was paid but its change was NOT applied, because ' . $why . '. The payment is recorded on the order, so the board shows it as owed back; the organisation should settle it with the customer.', [
            'top_up_id' => (int) $topUp->id,
            'order_uuid' => $row->uuid,
            'masjid_id' => (int) $row->masjid_id,
            'amount_minor' => (int) $topUp->amount_minor,
            'settled_before_minor' => $before,
            'settled_after_minor' => (int) $row->settled_total_minor,
            'payment_intent_id' => $topUp->stripe_payment_intent_id,
        ]);
    }

    /**
     * The top-up and its order this session names, tenant-safely, or null (logged).
     * The masjid comes from the connected account the event was raised on; the
     * top-up by id WITHIN that masjid; and the session id, the order uuid and the
     * masjid id in the metadata must all agree with the row. Metadata alone never
     * decides anything.
     *
     * @return array{0: MealOrderTopUp, 1: MealOrder}|null
     */
    private function resolve(array $session, ?string $account): ?array
    {
        $topUpId = (int) ($session['metadata']['top_up_id'] ?? 0);

        if ($account === null || $account === '') {
            Log::warning('A lunch top-up event arrived without a connected account; ignoring.', [
                'top_up_id' => $topUpId,
            ]);

            return null;
        }

        $masjid = Masjid::where('stripe_account_id', $account)->first()
            ?? Masjid::onlyTrashed()->where('stripe_account_id', $account)->first();

        if (! $masjid) {
            Log::warning('A lunch top-up event arrived for an unknown connected account; ignoring.', [
                'account' => $account,
            ]);

            return null;
        }

        $topUp = $topUpId > 0
            ? MealOrderTopUp::withoutMasjidScope()->where('masjid_id', $masjid->id)->whereKey($topUpId)->first()
            : null;

        $order = $topUp
            ? MealOrder::withoutMasjidScope()->where('masjid_id', $masjid->id)->whereKey($topUp->meal_order_id)->first()
            : null;

        $sessionId = (string) ($session['id'] ?? '');

        if (! $topUp
            || ! $order
            || (string) ($session['metadata']['masjid_id'] ?? '') !== (string) $masjid->id
            || $topUp->stripe_session_id === null
            || $sessionId === ''
            || ! hash_equals((string) $topUp->stripe_session_id, $sessionId)
            || ! hash_equals((string) $order->uuid, (string) ($session['metadata']['order_uuid'] ?? ''))) {
            Log::warning(
                'A lunch top-up event did not match a top-up of the organisation holding this connected account; '
                . 'NOTHING was recorded and Stripe will not retry. If money moved, the organisation should check it '
                . 'in its Stripe dashboard.',
                [
                    'top_up_id' => $topUpId,
                    'masjid_id' => (int) $masjid->id,
                    'account' => $account,
                    'checkout_session_id' => $sessionId !== '' ? $sessionId : null,
                ]
            );

            return null;
        }

        return [$topUp, $order];
    }
}
