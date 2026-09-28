<?php

namespace App\Services\Lunch;

use App\Models\MealMenu;
use App\Models\MealOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writing a meal order — the one implementation, shared by the two public doors
 * (KitchenOrdersController for a catalogue, JummahLunchOrdersController for a
 * dated Friday lunch) and by the universal cart.
 *
 * WHY it is separate from the doors: the cart takes the money FIRST and records
 * the order afterwards, from the webhook. A payment that lands after ordering
 * closed, after the pickup window began, or with a dish since sold out has
 * already taken the customer's money, and the organisation must still hold the
 * record of it. So this class is the WRITE and nothing else; the GATES stay where
 * they always were, in the doors, asked in the doors' order before this is ever
 * called:
 *
 *   gates (the door's, unchanged)          the write (here)
 *   -----------------------------          ---------------------------------
 *   the module is on for the org           the order number, under the menu lock
 *   the menu is open (isOpenForOrders)     the order row: money, customer, origin
 *   the payment method is offered          the item rows, one per priced line
 *   card needs a trusted site origin
 *   pickup window and lead time
 *   the lines price (LunchOrderLines)
 *
 * It must therefore NEVER re-check any of those, and never re-price: the lines
 * arrive already priced, in the shape LunchOrderLines::price returns, and are
 * recorded as given. For the doors that is what they priced a moment ago; for the
 * cart it is the FROZEN price the shopper saw and paid, which must not move
 * because the menu was edited before the webhook arrived.
 *
 * Nor does it open, read or close a Stripe session. Opening the page is
 * MealOrderCheckoutService's job and the doors do it after this returns; the
 * cart has already been to Stripe by the time it calls here. Settlement is the
 * caller's: the cart's webhook takes the row lock and calls MealOrder::markPaid
 * and LunchOrderMailer::confirmation, which claim their sends on
 * `confirmation_sent_at` / `office_notified_at` / `customer_confirmed_sent_at`.
 * None of those is written here, so a cart order can never have been emailed
 * before it is real.
 *
 * Runs inside a transaction of its own (retried up to three times on a deadlock,
 * as both doors always did) which JOINS an outer one when the caller has already
 * opened it. The order number is issued inside it, so a rolled-back order gives
 * its number back.
 */
final class MealOrderCreator
{
    /**
     * Record an order and its lines.
     *
     * `$priced` is exactly what LunchOrderLines::price returns:
     * `['lines' => [meal_menu_item_id, item_name, unit_price_minor, quantity,
     * line_total_minor, ...], 'subtotal_minor' => int]`. The lines are written as
     * given. The one thing checked is that they add up to the subtotal, because a
     * total that disagrees with its lines is a wrong figure in the ledger and the
     * webhook's amount check would then refuse a payment already taken.
     *
     * `$customer` carries `name`, `phone`, `email` and `notes`. Name and phone are
     * trimmed; the email is DROPPED when the menu does not collect one, since
     * hiding an input does not stop a crafted request carrying an address the
     * organisation chose not to ask for.
     *
     * `$paymentMethod` is the channel, a MealOrder::METHODS value. `$donationMinor`
     * and `$feeCoveredMinor` are the Friday lunch's two extras, already computed
     * (LunchOrderExtras) and never recomputed here; the total is the subtotal plus
     * both. A kitchen order has neither.
     *
     * `$catalogue` is what only a kitchen order carries, and its KEYS say which
     * kind this is: `pickup_at` (a Carbon, the pickup instant) and
     * `preferred_payment` (the offline method the customer said they will use, or
     * null for a card order). A key that is present is written, null included;
     * one that is absent is left alone, so a dated order writes neither column.
     *
     * `$masjidId` is stamped by hand because /api/v1 and the webhook run UNBOUND,
     * and must be the menu's own organisation: an order in one organisation on
     * another's menu would take that menu's order numbers.
     *
     * @param  array{lines: array<int,array<string,mixed>>, subtotal_minor: int}  $priced
     * @param  array{name?: mixed, phone?: mixed, email?: mixed, notes?: mixed}  $customer
     * @param  array{pickup_at?: ?Carbon, preferred_payment?: ?string}  $catalogue
     *
     * @throws \InvalidArgumentException  arguments that no door or cart can honestly produce; nothing is written
     */
    public function create(
        MealMenu $menu,
        int $masjidId,
        array $priced,
        array $customer,
        string $paymentMethod,
        ?string $siteOrigin,
        int $donationMinor = 0,
        int $feeCoveredMinor = 0,
        array $catalogue = []
    ): MealOrder {
        $lines = $priced['lines'] ?? [];
        $subtotal = (int) ($priced['subtotal_minor'] ?? 0);

        if ((int) $menu->masjid_id !== $masjidId) {
            throw new \InvalidArgumentException('A meal order belongs to the organisation that owns its menu.');
        }

        if ($lines === []) {
            throw new \InvalidArgumentException('A meal order needs at least one line.');
        }

        if (! in_array($paymentMethod, MealOrder::METHODS, true)) {
            throw new \InvalidArgumentException('Unknown meal order payment method.');
        }

        if ($donationMinor < 0 || $feeCoveredMinor < 0) {
            throw new \InvalidArgumentException('A meal order extra cannot be negative.');
        }

        if ((int) array_sum(array_column($lines, 'line_total_minor')) !== $subtotal) {
            throw new \InvalidArgumentException('The lines of a meal order must add up to its subtotal.');
        }

        return DB::transaction(function () use ($menu, $masjidId, $lines, $subtotal, $customer, $paymentMethod, $siteOrigin, $donationMinor, $feeCoveredMinor, $catalogue) {
            // A pickup number unique within this menu; the count is locked so
            // two concurrent orders can't claim the same one.
            $orderNumber = MealOrder::nextOrderNumber($masjidId, (int) $menu->id);

            $order = new MealOrder([
                'meal_menu_id' => $menu->id,
                'customer_name' => trim((string) ($customer['name'] ?? '')),
                'customer_phone' => trim((string) ($customer['phone'] ?? '')),
                // Dropped when the organisation turned the field off. Hiding an
                // input does not stop a crafted request from carrying one, and
                // storing an address they deliberately chose not to ask for is
                // the whole thing they were avoiding.
                'customer_email' => $menu->collect_customer_email ? ($customer['email'] ?? null) : null,
                'customer_notes' => $customer['notes'] ?? null,
                'payment_method' => $paymentMethod,
            ]);
            // /api/v1 and the webhook run UNBOUND, so stamp the tenant explicitly.
            $order->masjid_id = $masjidId;
            $order->currency = $menu->currency;
            $order->subtotal_minor = $subtotal;
            $order->donation_minor = $donationMinor;
            $order->fee_covered_minor = $feeCoveredMinor;
            $order->total_minor = $subtotal + $donationMinor + $feeCoveredMinor;
            $order->order_number = $orderNumber;
            $order->placed_at = now();

            if (array_key_exists('pickup_at', $catalogue)) {
                $order->pickup_at = $catalogue['pickup_at'];
            }

            if (array_key_exists('preferred_payment', $catalogue)) {
                $order->preferred_payment = $catalogue['preferred_payment'];
            }

            // The site this was placed from, when the allowlist trusts it: the
            // order email is sent later (from the webhook, for a card order) and
            // its link must go back to the same site (LunchOrderLink).
            $order->site_origin = $siteOrigin;
            $order->save();

            foreach ($lines as $line) {
                $order->items()->create(array_merge($line, ['masjid_id' => $masjidId]));
            }

            return $order;
        }, 3); // retried on a deadlock rather than failing the customer
    }
}
