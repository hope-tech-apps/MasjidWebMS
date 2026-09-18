<?php

namespace App\Services\Lunch;

use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Models\MealOrderEdit;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\LunchOrderLines;
use App\Support\StripeFees;
use Illuminate\Support\Facades\DB;

/**
 * Changing what is ON a lunch order after it was placed — the one implementation,
 * shared by the customer's own order link and the staff board.
 *
 * The two doors differ only in WHO may edit and WHEN (the controllers ask that,
 * and ask it again here on the locked row through `$guard`). What an edit does is
 * the same either way, and is the whole reason this is not written twice:
 *
 *   - every line is RE-PRICED from the menu (LunchOrderLines). A request body
 *     carries item ids and quantities and has never priced an order;
 *   - `donation_minor` is left exactly as it was. It is the one amount the
 *     customer chose, and an edit to the food is not a decision about it;
 *   - `fee_covered_minor` is recomputed with the formula used when the order was
 *     placed, but ONLY if it was already covering the fee AND the order is still
 *     unpaid. An order that never covered the card fee does not start covering it
 *     because its plates changed, and a PAID order keeps the fee it was actually
 *     charged: from then on that column is a record, not a quote;
 *   - `total_minor` = subtotal + donation + fee;
 *   - an unpaid order's OPEN Stripe page holds the old amount, so it is closed
 *     before the new one is written. Otherwise a customer could add a plate and
 *     then pay the page they still had open for the old total, and the webhook
 *     would mark that order paid in full;
 *   - money that has already settled is recorded before the total moves
 *     (`settled_total_minor`), so `balance_minor` can say what is still owed;
 *   - the change is recorded (`meal_order_edits`) in the SAME transaction. No
 *     edit commits without its audit row.
 *
 * Nothing here touches `payment_status`, `paid_at`, `paid_via` or any Stripe id
 * beyond closing a page that can no longer be paid. An edit is never a payment.
 */
final class MealOrderEditor
{
    public const ACTOR_CUSTOMER = MealOrderEdit::ACTOR_CUSTOMER;

    public const ACTOR_STAFF = MealOrderEdit::ACTOR_STAFF;

    public function __construct(private MealOrderCheckoutService $checkout)
    {
    }

    /**
     * Apply a new set of lines to an order.
     *
     * `$wanted` is the FULL set after the edit ([item id => quantity], from
     * LunchOrderLines::wanted) — a line left out is a line removed, which is how
     * a quantity of zero reaches here as an absence.
     *
     * `changed` is false when the lines and the money come out identical to what
     * the order already said. Nothing is written then, audit row included: a
     * request that changed nothing is not an edit, and recording it would fill
     * the record of who changed a paid order with rows where nobody did.
     *
     * @param  array<int,int>  $wanted
     * @param  null|\Closure(MealOrder):void  $guard  refusals asked again on the LOCKED row
     * @return array{order: MealOrder, changed: bool, page_closed: bool}
     *
     * @throws \App\Support\LunchLineRefusal            a line that cannot be priced
     * @throws \RuntimeException                        a refusal from $guard, or a Stripe page that could not be closed
     */
    public function apply(
        MealOrder $order,
        MealMenu $menu,
        array $wanted,
        string $actor,
        ?int $userId,
        ?\Closure $guard = null
    ): array {
        return DB::transaction(function () use ($order, $menu, $wanted, $actor, $userId, $guard) {
            // The same row lock every other money path on an order takes
            // (MealOrderCheckoutService::checkout / paymentLink, markPaid,
            // cancelling), so an edit cannot interleave with a payment page being
            // made or with money being recorded. withoutMasjidScope because the
            // customer's own edit runs UNBOUND; the row was already resolved
            // within one organisation by the caller.
            $row = MealOrder::withoutMasjidScope()->with('items')->lockForUpdate()->findOrFail($order->id);

            // Every refusal asked once more on the row as the lock found it: a
            // payment, a cancellation, or the cutoff passing while this request
            // waited must all still refuse.
            if ($guard !== null) {
                $guard($row);
            }

            $priced = LunchOrderLines::price($menu, $wanted, LunchOrderLines::CAP_REFUSE);

            $before = self::snapshot($row);

            $subtotal = (int) $priced['subtotal_minor'];
            $donation = (int) $row->donation_minor;

            // The card fee is a GROSS-UP on an amount Stripe is about to process,
            // so it is recomputed only while there is still a card payment ahead
            // of this order. Once the money has landed, `fee_covered_minor` stops
            // being a quote and becomes the record of what the customer actually
            // paid Stripe — the board's "Card fees covered" tile sums that column
            // and has to stay true. Re-grossing it on a paid order would also add
            // cents of Stripe fee to a balance Stripe will never see: there is no
            // way to re-charge a paid order here, so staff settle the difference
            // by hand, and asking them for 2.9% of a plate they will be handed
            // cash for is asking for money nobody owes.
            $fee = (int) $row->fee_covered_minor;
            if ($fee > 0 && $row->payment_status === MealOrder::PAYMENT_UNPAID) {
                $fee = StripeFees::coverage($subtotal + $donation);
            }

            $total = $subtotal + $donation + $fee;

            $after = self::snapshotOf($priced['lines'], $subtotal, $donation, $fee, $total);

            if (self::comparable($after) === self::comparable($before)) {
                return ['order' => $row, 'changed' => false, 'page_closed' => false];
            }

            // An open payment page is for the amount this order USED to be. Close
            // it before the row changes, and refuse the whole edit if it cannot be
            // closed — two payable amounts for one order is the failure this
            // exists to prevent. A page Stripe reports as paid refuses here too:
            // the webhook is about to mark the order paid, and a paid order is
            // the staff board's to edit, not the customer's.
            $pageClosed = false;
            if ($row->payment_status === MealOrder::PAYMENT_UNPAID && $row->stripe_checkout_session_id) {
                $this->checkout->closePageBeforeRepricing($row);
                $pageClosed = true;
            }

            // Recorded BEFORE the total moves, once: what the masjid actually has.
            if ($row->payment_status === MealOrder::PAYMENT_PAID && $row->settled_total_minor === null) {
                $row->settled_total_minor = (int) $row->total_minor;
            }

            $row->subtotal_minor = $subtotal;
            $row->fee_covered_minor = $fee;
            $row->total_minor = $total;
            $row->save();

            // Rewritten rather than reconciled line by line: the request carries
            // the full set after the edit, and the lines snapshot the menu at this
            // moment exactly as placing the order does.
            $row->items()->delete();
            foreach ($priced['lines'] as $line) {
                $row->items()->create(array_merge($line, ['masjid_id' => $row->masjid_id]));
            }

            MealOrderEdit::record($row, $actor, $userId, $before, $after);

            return ['order' => $row->load('items'), 'changed' => true, 'page_closed' => $pageClosed];
        }, 3); // retried on a deadlock rather than failing the customer
    }

    /**
     * The lines and the money, small enough to keep for every edit — and nothing
     * else: an edit does not touch the customer's name, phone or email, so the
     * audit row does not carry them.
     *
     * @return array<string,mixed>
     */
    private static function snapshot(MealOrder $order): array
    {
        $lines = $order->items->map(fn ($item) => [
            'meal_menu_item_id' => $item->meal_menu_item_id === null ? null : (int) $item->meal_menu_item_id,
            'item_name' => (string) $item->item_name,
            'unit_price_minor' => (int) $item->unit_price_minor,
            'quantity' => (int) $item->quantity,
            'line_total_minor' => (int) $item->line_total_minor,
        ])->values()->all();

        return self::snapshotOf(
            $lines,
            (int) $order->subtotal_minor,
            (int) $order->donation_minor,
            (int) $order->fee_covered_minor,
            (int) $order->total_minor
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<string,mixed>
     */
    private static function snapshotOf(array $lines, int $subtotal, int $donation, int $fee, int $total): array
    {
        return [
            'items' => array_map(fn (array $line) => [
                'meal_menu_item_id' => $line['meal_menu_item_id'] === null ? null : (int) $line['meal_menu_item_id'],
                'item_name' => (string) $line['item_name'],
                'unit_price_minor' => (int) $line['unit_price_minor'],
                'quantity' => (int) $line['quantity'],
                'line_total_minor' => (int) $line['line_total_minor'],
            ], array_values($lines)),
            'subtotal_minor' => $subtotal,
            'donation_minor' => $donation,
            'fee_covered_minor' => $fee,
            'total_minor' => $total,
        ];
    }

    /**
     * The same snapshot with its lines in a fixed order, for asking "did anything
     * actually change?". The stored snapshots keep the order the kitchen would
     * read them in; only this comparison sorts, so re-sending the same basket in a
     * different order is not recorded as an edit.
     *
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private static function comparable(array $snapshot): array
    {
        $items = $snapshot['items'];

        usort($items, function (array $a, array $b) {
            return [$a['meal_menu_item_id'] ?? 0, $a['item_name']] <=> [$b['meal_menu_item_id'] ?? 0, $b['item_name']];
        });

        $snapshot['items'] = $items;

        return $snapshot;
    }
}
