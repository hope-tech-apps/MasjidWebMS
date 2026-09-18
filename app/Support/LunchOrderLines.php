<?php

namespace App\Support;

use App\Models\MealMenu;
use App\Models\MealMenuItem;

/**
 * The one place a Jummah-lunch order's FOOD lines are priced.
 *
 * Every door an order comes through — the public order page, an order taken by
 * staff on the board, and the two edit endpoints — asks this class what the
 * lines cost, for the reason LunchOrderExtras exists: two hand-written copies of
 * a money calculation are how two parts of one system quietly start charging
 * different amounts. Until this was extracted the public page and the staff
 * board each had their own copy of the loop below, and an edit endpoint would
 * have been a third.
 *
 * PRICES ARE NEVER TAKEN FROM THE REQUEST. The body carries item ids and
 * quantities; every unit price, and therefore every total, is read from the
 * menu item's own `price_minor` here.
 *
 * The items are resolved against the MENU's masjid_id with the tenant scope
 * lifted, which is the same set under either idiom: the public path runs
 * UNBOUND (so there would be no filter at all), and the admin path is bound to
 * the organisation the menu already belongs to. An id from another masjid — or
 * from another menu, or an item marked unavailable — simply is not in the set,
 * and the whole request is refused rather than silently priced short.
 */
final class LunchOrderLines
{
    /** Over the kitchen's cap: trim to it (what the public page has always done). */
    public const CAP_CLAMP = 'clamp';

    /** Over the kitchen's cap: refuse and say so (the staff board, and both edits). */
    public const CAP_REFUSE = 'refuse';

    /**
     * Combine a request's item rows into [item id => quantity].
     *
     * Rows naming nothing, and rows of none, are dropped: on the ordering
     * endpoints a zero was never orderable, and on the edit endpoints a zero is
     * precisely how a customer removes a line. A body repeating an id is summed,
     * so "2 plates and 1 plate" is one line of three rather than two lines the
     * kitchen reads as separate.
     *
     * @param  array<int,mixed>  $rows
     * @return array<int,int>
     */
    public static function wanted(array $rows, string $idKey = 'item_id'): array
    {
        $wanted = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row[$idKey] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);

            if ($id > 0 && $qty > 0) {
                $wanted[$id] = ($wanted[$id] ?? 0) + $qty;
            }
        }

        return $wanted;
    }

    /**
     * Price every wanted line from the menu.
     *
     * @param  array<int,int>  $wanted  [item id => quantity], from wanted()
     * @return array{lines: array<int,array<string,mixed>>, subtotal_minor: int}
     *
     * @throws LunchLineRefusal
     */
    public static function price(MealMenu $menu, array $wanted, string $overCap = self::CAP_CLAMP): array
    {
        if ($wanted === []) {
            throw LunchLineRefusal::emptyOrder();
        }

        $menuItems = MealMenuItem::withoutMasjidScope()
            ->where('masjid_id', $menu->masjid_id)
            ->where('meal_menu_id', $menu->id)
            ->where('is_available', true)
            ->whereIn('id', array_keys($wanted))
            ->get()
            ->keyBy('id');

        // Every requested item must resolve to a live item on THIS menu — a
        // missing one means the menu changed under the customer, or the body
        // named something that was never theirs to order.
        if ($menuItems->count() !== count($wanted)) {
            throw LunchLineRefusal::unavailable();
        }

        $lines = [];
        $subtotal = 0;

        foreach ($wanted as $id => $qty) {
            /** @var MealMenuItem $item */
            $item = $menuItems->get($id);

            if ($item->max_quantity !== null && $qty > $item->max_quantity) {
                if ($overCap === self::CAP_REFUSE) {
                    throw LunchLineRefusal::overCap($item);
                }

                $qty = (int) $item->max_quantity; // clamp to the kitchen's cap
            }

            $lineTotal = (int) $item->price_minor * $qty;
            $subtotal += $lineTotal;

            $lines[] = [
                'meal_menu_item_id' => $item->id,
                'item_name' => $item->name,
                'unit_price_minor' => (int) $item->price_minor,
                'quantity' => $qty,
                'line_total_minor' => $lineTotal,
            ];
        }

        return ['lines' => $lines, 'subtotal_minor' => $subtotal];
    }
}
