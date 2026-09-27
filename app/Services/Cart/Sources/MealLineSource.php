<?php

namespace App\Services\Cart\Sources;

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
 */
final readonly class MealLineSource
{
    public function reprice(
        MealMenuItem $item,
        int $quantity,
        int $unitAmountShownMinor,
        ?CarbonInterface $pickupAt = null,
        ?CarbonInterface $at = null,
    ): CartLineOutcome {
        $label = (string) $item->name;
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

        if ($unitMinor !== $unitAmountShownMinor) {
            return CartLineOutcome::repriced(
                $unitMinor,
                $quantity,
                $label,
                'The price changed while this was in your basket.',
            );
        }

        return CartLineOutcome::available($unitMinor, $quantity, $label);
    }
}
