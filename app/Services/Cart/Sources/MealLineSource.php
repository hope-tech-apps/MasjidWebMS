<?php

namespace App\Services\Cart\Sources;

use App\Models\Masjid;
use App\Models\MealMenuItem;
use App\Services\Cart\CartLineOutcome;
use Carbon\CarbonInterface;

/**
 * Re-checks a basket line that buys food.
 *
 * ONE source covers both of the owner's "kitchen" and "Friday lunch", because
 * they are one model wearing two hats: `MealMenu::KIND_DATED` is the Jummah
 * lunch, with a service date and an ordering cutoff, and `KIND_CATALOGUE` is the
 * Halal Kitchen standing catalogue, where the customer picks a pickup time
 * subject to a lead time. Writing two sources would have meant maintaining the
 * same rules twice.
 *
 * Food expires in more ways than a ticket does, and all of them can happen while
 * a basket sits there:
 *
 *   - the menu closes (`isOpenForOrders()` — status plus `ordering_closes_at`),
 *   - a single dish sells out or is withdrawn (`is_available`),
 *   - the price is edited (`price_minor`),
 *   - and for a catalogue, the chosen pickup slips outside the window the office
 *     can actually cook for (`earliestPickup()` / `latestPickup()`).
 *
 * The pickup check is the one that has no equivalent on a form. A basket filled
 * on Thursday for a Saturday pickup, paid on Friday, can leave a pickup time that
 * is now inside the 48-hour lead — the office would be handed an order it cannot
 * fill. That line is dropped, not quietly moved to another day: only the customer
 * can say when they can collect.
 *
 * The doors' own gate is asked here, so checkout re-asks it too (brief 5, section 2): the
 * `jummah_lunch` capability. Both meal doors refuse an organisation without it
 * (JummahLunchOrdersController::lunchIsOn(), KitchenOrdersController::organisation()) and
 * answer 'Ordering is not available.', so the switch that closes the staff board also closes
 * the basket, and a basket cannot keep charging for orders nobody at the organisation can see.
 */
final readonly class MealLineSource
{
    /** The sentence both meal doors answer when the capability is off. */
    public const ORDERING_OFF = 'Ordering is not available.';

    /**
     * @param  Masjid|null  $org  the organisation the dish belongs to. CartPricer passes the one
     *                            it already loaded; a direct caller may omit it and the gate then
     *                            reads it from the dish, so it is never skipped.
     */
    public function reprice(
        MealMenuItem $item,
        int $quantity,
        int $unitAmountShownMinor,
        ?CarbonInterface $pickupAt = null,
        ?CarbonInterface $at = null,
        ?Masjid $org = null,
    ): CartLineOutcome {
        $label = (string) $item->name;

        // Fail closed: an organisation that cannot be read runs no lunch. Masjid::query()
        // leaves out a soft-deleted one.
        $org ??= Masjid::query()->find($item->masjid_id);

        if ($org === null || ! $org->hasCapability('jummah_lunch')) {
            return CartLineOutcome::gone($label, self::ORDERING_OFF);
        }

        $menu = $item->menu;

        if ($menu === null) {
            return CartLineOutcome::gone($label, 'This is no longer on any menu.');
        }

        if (! $menu->isOpenForOrders()) {
            return CartLineOutcome::gone(
                $label,
                $menu->isCatalogue()
                    ? 'The kitchen has stopped taking orders for this.'
                    : 'Ordering for this menu has closed.',
            );
        }

        // A menu may be pay-at-pickup only. Every online path already refuses it
        // (MealOrdersController, KitchenOrdersController, JummahLunchOrdersController
        // all test this before taking a card), and a basket IS an online payment, so
        // it must refuse too rather than become the one way round the setting.
        if (! $menu->allow_online_payment) {
            return CartLineOutcome::gone($label, 'This one is paid for at pickup, not online.');
        }

        if (! $item->is_available) {
            return CartLineOutcome::gone($label, 'This is no longer available.');
        }

        if ($quantity < 1) {
            return CartLineOutcome::gone($label, 'This no longer has a quantity to pay for.');
        }

        // The kitchen's per-order cap. The two public doors already disagree about
        // it on purpose, and the basket follows each one rather than picking a side:
        // KitchenOrdersController REFUSES an order over the cap, and
        // JummahLunchOrdersController CLAMPS it to the cap (LunchOrderLines::CAP_*).
        // The one thing the basket adds is that a clamp is never silent — a shopper
        // who chose five and is charged for three is told, because the reduction
        // happened after they last looked.
        $capped = false;
        $cap = $item->max_quantity;

        if ($cap !== null && $quantity > $cap) {
            if ($menu->isCatalogue()) {
                return CartLineOutcome::gone($label, "Only {$cap} × {$label} per order.");
            }

            $quantity = (int) $cap;
            $capped = true;
        }

        // Only a catalogue lets the customer choose when to collect; a dated menu
        // has one service date, and there is nothing for the customer to pick.
        if ($menu->isCatalogue() && $pickupAt !== null) {
            $now = $at ?? now();

            if ($pickupAt->lt($menu->earliestPickup($now))) {
                return CartLineOutcome::gone(
                    $label,
                    'The pickup time you chose is now too soon for the kitchen to prepare this.',
                );
            }

            if ($pickupAt->gt($menu->latestPickup($now))) {
                return CartLineOutcome::gone($label, 'The pickup time you chose is too far ahead.');
            }
        }

        $unitMinor = (int) $item->price_minor;
        $reasons = [];

        if ($capped) {
            $reasons[] = "Only {$cap} × {$label} per order, so this was reduced to {$cap}.";
        }

        if ($unitMinor !== $unitAmountShownMinor) {
            $reasons[] = 'The price changed while this was in your basket.';
        }

        // Either change means the shopper is no longer paying what they last saw,
        // so both go through `repriced` — still payable, never silent.
        if ($reasons !== []) {
            return CartLineOutcome::repriced($unitMinor, $quantity, $label, implode(' ', $reasons));
        }

        return CartLineOutcome::available($unitMinor, $quantity, $label);
    }
}
