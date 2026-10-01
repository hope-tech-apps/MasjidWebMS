<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What can still be bought of a product size, and the locks that keep that true (shop slice B1;
 * DECISIONS.md 2026-09-30). There is NO hold table.
 *
 *     available(variant) = stock - sold_count - held          (stock NULL = unlimited, always available)
 *
 * `held` is the quantity on the order lines of PENDING orders for that variant whose payment page
 * has not lapsed: `orders.checkout_expires_at` plus a grace (`cart.shop_hold_grace_minutes`, 15) is
 * still in the future. A pending order is the hold, and it ends by itself: a paid order moves its
 * quantity into `sold_count` in the settlement transaction, an expired or lapsed one simply stops
 * matching, and `cart:prune` deletes old unpaid orders with their lines. Nothing releases anything.
 * The grace is for a payment that lands as the page expires and reaches the webhook late: until it
 * passes, the units stay held for it. A webhook later than that can still oversell, and settlement
 * then records the line, flags it and tells ops (CartSettlementService::settleProduct); it never
 * refuses money and never refunds.
 *
 * ## Three places ask, and only one decides
 *
 *   - CartPricer, for the basket's lines (advisory: a notice before the card screen), excluding the
 *     shopper's OWN cart's pending orders, because those are the page this basket is about to reuse
 *     or replace and must not count against itself;
 *   - CartCheckoutService::createPendingOrder, which decides, inside the cart lock and the
 *     transaction, after locking every variant of the basket FOR UPDATE in ascending id order,
 *     excluding only the order it has just made;
 *   - CartSettlementService, which takes the same locks and moves `sold_count`.
 *
 * ## Why the checkout locks BEFORE it reads anything
 *
 * Under InnoDB's REPEATABLE READ the first plain SELECT of a transaction fixes the snapshot every
 * later plain read sees (the reason CartSettlementService::settleLocked() opens with locking reads
 * only). A checkout that read the basket, priced it, and only then took the variant locks would
 * wait behind a competing checkout and then sum the held lines from a snapshot that predates the
 * winner's commit: both would see the last unit free. So lockBasket() runs straight after the
 * cart's own lock, with locking reads only, ahead of the pricer's first plain read; a locking read
 * sees the latest committed rows and fixes no snapshot, so everything read afterwards is read
 * after the locks are held. The held SUM is a plain read on purpose: a locking read over `orders`
 * would queue behind a settlement holding the order row while that settlement waits for the
 * variant this checkout holds, and the two would deadlock (cart, order, variant is the one order).
 * SQLite serialises writers and compiles `lockForUpdate()` to nothing, so it cannot show any of
 * this; tests/Feature/Shop/ShopStockTest pins the statement order, and tests/Mysql runs the real
 * statements against the engine.
 */
final class ProductStock
{
    /** How long after its page lapses a pending order still holds its units. */
    public static function graceMinutes(): int
    {
        return max(0, (int) config('cart.shop_hold_grace_minutes', 15));
    }

    /**
     * Units held by pending orders, per variant: the SUM of the quantities on the lines of the
     * orders that are pending and whose page (plus the grace) has not lapsed.
     *
     * @param  list<int>  $variantIds
     * @param  int|null  $exceptOrderId  one order that must not count: the order a checkout has just made
     * @param  int|null  $exceptCartId   a basket whose own pending orders must not count: the pricer's
     * @return array<int, int> variant id => units held (a variant with none is absent)
     */
    public static function held(
        int $masjidId,
        array $variantIds,
        ?CarbonInterface $at = null,
        ?int $exceptOrderId = null,
        ?int $exceptCartId = null,
    ): array {
        if ($variantIds === []) {
            return [];
        }

        $at ??= now();

        // Both tables carry the organisation, and both are asked: an id from the browser, or a
        // line of another organisation, can never be counted here. The join reads only; see the
        // class docblock for why it is not a locking read.
        $query = OrderItem::withoutMasjidScope()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.masjid_id', $masjidId)
            ->where('orders.masjid_id', $masjidId)
            ->where('order_items.buyable_type', CartItem::TYPE_PRODUCT)
            ->whereIn('order_items.buyable_id', $variantIds)
            ->where('orders.status', Order::STATUS_PENDING)
            ->where('orders.checkout_expires_at', '>', $at->copy()->subMinutes(self::graceMinutes()));

        if ($exceptOrderId !== null) {
            $query->where('orders.id', '!=', $exceptOrderId);
        }

        if ($exceptCartId !== null) {
            // An order whose basket was pruned has a NULL cart_id, and `cart_id != x` is not true for
            // NULL: it is somebody's order and must still count.
            $query->where(fn ($q) => $q->whereNull('orders.cart_id')->orWhere('orders.cart_id', '!=', $exceptCartId));
        }

        return $query
            ->groupBy('order_items.buyable_id')
            ->selectRaw('order_items.buyable_id as variant_id, SUM(order_items.quantity) as held_units')
            ->pluck('held_units', 'variant_id')
            ->map(static fn ($units): int => (int) $units)
            ->mapWithKeys(static fn (int $units, $variantId): array => [(int) $variantId => $units])
            ->all();
    }

    /**
     * What can still be bought of a variant, given what pending orders hold: null when its stock is
     * unlimited, else never below zero (a variant that has oversold has none left, not a negative
     * number).
     */
    public static function available(ProductVariant $variant, int $held): ?int
    {
        if ($variant->stock === null) {
            return null;
        }

        return max(0, (int) $variant->stock - (int) $variant->sold_count - $held);
    }

    /**
     * Lock variants FOR UPDATE, in ASCENDING id order (the one order every transaction that takes
     * several uses, so two baskets that share two sizes cannot deadlock). Trashed rows are included:
     * a sale of a size withdrawn since is still recorded against it. A locking read, so what comes
     * back is the latest committed `stock` and `sold_count`.
     *
     * @param  list<int>  $variantIds
     * @return Collection<int, ProductVariant> by id
     */
    public static function lock(int $masjidId, array $variantIds): Collection
    {
        if ($variantIds === []) {
            return new Collection;
        }

        return ProductVariant::withoutMasjidScope()
            ->withTrashed()
            ->where('masjid_id', $masjidId)
            ->whereIn('id', $variantIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Take the locks for every product line of a basket, as the FIRST thing a checkout does after
     * the cart's own lock and before it reads or prices anything (see the class docblock). The
     * lines are read with a locking read for the same reason. A basket with no product line costs
     * this one statement and takes nothing.
     */
    public static function lockBasket(Cart $cart): void
    {
        $ids = CartItem::withoutMasjidScope()
            ->where('cart_id', $cart->id)
            ->where('masjid_id', $cart->masjid_id)
            ->where('buyable_type', CartItem::TYPE_PRODUCT)
            ->lockForUpdate()
            ->pluck('buyable_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        self::lock((int) $cart->masjid_id, $ids);
    }

    /**
     * The sentence a checkout is refused with when a basket's product lines ask for more of a size
     * than is left, or null when every size has enough. THE DECISION: it runs inside the cart lock
     * and the checkout's transaction, after the order and its lines exist, and a refusal rolls them
     * back, so no page is opened. Every variant of the basket is locked here (a no-op after
     * lockBasket(), kept so the check is correct on its own), and what pending orders hold is
     * counted EXCLUDING `$exceptOrderId`, the order this checkout has just made: it would otherwise
     * hold units against itself.
     *
     * The wording is the line's own label: "School Polo (M): sold out." or "School Polo (M): only 2 left."
     */
    public static function refusal(int $masjidId, PricedBasket $priced, int $exceptOrderId, ?CarbonInterface $at = null): ?string
    {
        $need = [];
        $labels = [];

        foreach ($priced->lines as ['item' => $item, 'outcome' => $outcome]) {
            if ($item->buyable_type !== CartItem::TYPE_PRODUCT || ! $outcome->isPayable()) {
                continue;
            }

            $id = (int) $item->buyable_id;
            $need[$id] = ($need[$id] ?? 0) + $outcome->quantity;
            $labels[$id] ??= $outcome->label;
        }

        if ($need === []) {
            return null;
        }

        ksort($need);

        $variants = self::lock($masjidId, array_keys($need));
        $held = self::held($masjidId, array_keys($need), $at, $exceptOrderId);

        foreach ($need as $id => $units) {
            $variant = $variants->get($id);

            // Priced a moment ago in this same transaction, and gone now: it cannot be sold.
            $left = $variant === null ? 0 : self::available($variant, $held[$id] ?? 0);

            if ($left === null || $left >= $units) {
                continue;
            }

            return $left < 1
                ? "{$labels[$id]}: sold out."
                : "{$labels[$id]}: only {$left} left.";
        }

        return null;
    }
}
