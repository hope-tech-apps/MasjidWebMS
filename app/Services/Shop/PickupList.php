<?php

namespace App\Services\Shop;

use App\Models\Order;
use App\Models\ProductSale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The office's pickup list (shop slice B2): what has been bought, by whom, and whether it has been
 * handed over. ONE query builder behind the list, its CSV and the single row a collect answers
 * with, so the three can never disagree about which sales a filter keeps.
 *
 * ## Where each fact comes from
 *
 * The SALE (`product_sales`) says what was bought and at what price, from its own snapshot: the
 * product name, the size, the quantity, the total. It holds no buyer. The BUYER (name, e-mail,
 * phone), the paid-at time, the currency and the refund flag are read from the ORDER the sale
 * belongs to, joined on the order id AND the organisation, so a row can never pick up another
 * organisation's order. The collector's name is the user the sale was stamped with. Nothing about
 * the buyer is copied into `product_sales`.
 *
 * ## "To hand out" is not "unpaid or refunded"
 *
 * A sale exists only once its order is paid. It is TO HAND OUT while it has not been collected and
 * its order has not been refunded or disputed (`orders.charge_flag`); a refunded or disputed sale is
 * never handed out and shows only under `all`, with its flag. `partially_refunded` is NOT in that
 * list: a refund names an amount, never a line, so the office cannot tell from the order whether
 * it was this item, and such a sale stays on the list with its `charge_flag` for a person to judge
 * (DECISIONS.md 2026-10-01).
 *
 * `oversold` is the sale's own flag (a line paid after the last unit had gone): a fact, never
 * cleared. What the office DID about it is the sale's `resolution` (migration 2026_10_06_100200):
 * `refunded` (the money went back, so the sale is never to hand out, like a fully refunded order) or
 * `substituted` (a replacement is handed over, so the sale STAYS to hand out, but nobody's call is
 * needed any more); NULL is "nobody has decided". The header counts the sales that still NEED a
 * call, `oversold_open`: LINES (not units) that are oversold, whose order is not refunded or
 * disputed, that have no resolution and have not been collected.
 */
final class PickupList
{
    public const STATE_TO_HAND_OUT = 'to_hand_out';

    public const STATE_COLLECTED = 'collected';

    public const STATE_ALL = 'all';

    public const STATES = [self::STATE_TO_HAND_OUT, self::STATE_COLLECTED, self::STATE_ALL];

    /** The order charge flags that mean the money went back (or is being fought over). */
    public const REFUNDED_FLAGS = [Order::CHARGE_FLAG_REFUNDED, Order::CHARGE_FLAG_DISPUTED];

    public static function isRefundedFlag(?string $flag): bool
    {
        return $flag !== null && in_array($flag, self::REFUNDED_FLAGS, true);
    }

    /**
     * The sales this organisation's office may see, filtered, with the order's and the collector's
     * columns beside each, and NO ordering (the list sorts it; the CSV walks it by id).
     *
     * `ProductSale::query()` carries the tenant scope as `product_sales.masjid_id = <bound tenant>`.
     *
     * @param  array{state?: ?string, product_id?: ?int, variant_id?: ?int, search?: ?string}  $filters
     */
    public static function query(array $filters): Builder
    {
        $query = ProductSale::query()
            ->join('orders', static function (JoinClause $join): void {
                $join->on('orders.id', '=', 'product_sales.order_id')
                    ->on('orders.masjid_id', '=', 'product_sales.masjid_id');
            })
            ->leftJoin('users as collectors', 'collectors.id', '=', 'product_sales.collected_by_user_id')
            ->leftJoin('users as resolvers', 'resolvers.id', '=', 'product_sales.resolved_by_user_id')
            ->select([
                'product_sales.*',
                'orders.order_number as order_number',
                'orders.paid_at as order_paid_at',
                'orders.buyer_name as buyer_name',
                'orders.buyer_email as buyer_email',
                'orders.buyer_phone as buyer_phone',
                'orders.currency as order_currency',
                'orders.charge_flag as order_charge_flag',
                'collectors.name as collector_name',
                'resolvers.name as resolver_name',
            ])
            ->withCasts(['order_paid_at' => 'datetime']);

        $state = $filters['state'] ?? self::STATE_TO_HAND_OUT;

        if ($state === self::STATE_TO_HAND_OUT) {
            $query->whereNull('product_sales.collected_at')
                ->where(static function ($q): void {
                    $q->whereNull('orders.charge_flag')->orWhereNotIn('orders.charge_flag', self::REFUNDED_FLAGS);
                })
                // A sale the office resolved as refunded is never handed out; a substituted one still is.
                ->where(static function ($q): void {
                    $q->whereNull('product_sales.resolution')->orWhere('product_sales.resolution', '!=', ProductSale::RESOLUTION_REFUNDED);
                });
        } elseif ($state === self::STATE_COLLECTED) {
            $query->whereNotNull('product_sales.collected_at');
        }

        if (! empty($filters['product_id'])) {
            $query->where('product_sales.product_id', (int) $filters['product_id']);
        }

        if (! empty($filters['variant_id'])) {
            $query->where('product_sales.variant_id', (int) $filters['variant_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            // `!` is the escape character: unlike backslash it means the same inside a SQL string
            // literal on MySQL and on SQLite, which has no default escape at all. Lower-cased on both
            // sides, so the match does not depend on the column's collation.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower((string) $filters['search'])) . '%';

            $query->where(static function ($q) use ($like): void {
                $q->whereRaw("LOWER(orders.order_number) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(orders.buyer_name) LIKE ? ESCAPE '!'", [$like]);
            });
        }

        return $query;
    }

    /** One sale as the list and a collect read it, or null when it is not this organisation's. */
    public static function find(int $saleId): ?ProductSale
    {
        return self::query(['state' => self::STATE_ALL])->where('product_sales.id', $saleId)->first();
    }

    /**
     * A sale as a row of the list.
     *
     * @return array<string,mixed>
     */
    public static function row(ProductSale $sale): array
    {
        $flag = $sale->getAttribute('order_charge_flag');
        $refunded = self::isRefundedFlag($flag);
        $collectedAt = $sale->collected_at;
        $resolution = $sale->resolution;

        return [
            'id' => (int) $sale->id,
            'order_id' => (int) $sale->order_id,
            'order_number' => (string) $sale->getAttribute('order_number'),
            'paid_at' => $sale->getAttribute('order_paid_at')?->toIso8601String(),
            // The buyer is the ORDER's, as the buyer typed it at the basket page.
            'buyer_name' => $sale->getAttribute('buyer_name'),
            'buyer_email' => $sale->getAttribute('buyer_email'),
            'buyer_phone' => $sale->getAttribute('buyer_phone'),
            'product_id' => (int) $sale->product_id,
            'variant_id' => (int) $sale->variant_id,
            // The snapshot: what the buyer was sold, whatever the catalogue says now.
            'product_name' => (string) $sale->product_name,
            'variant_label' => (string) $sale->variant_label,
            'quantity' => (int) $sale->quantity,
            'unit_minor' => (int) $sale->unit_minor,
            'total_minor' => (int) $sale->total_minor,
            'currency' => strtolower((string) $sale->getAttribute('order_currency')),
            'collected_at' => $collectedAt?->toIso8601String(),
            'collected_by' => $sale->collected_by_user_id === null
                ? null
                : ['id' => (int) $sale->collected_by_user_id, 'name' => $sale->getAttribute('collector_name')],
            'oversold' => (bool) $sale->oversold,
            // What the office did about it: null | refunded | substituted, by whom, and when.
            'resolution' => $resolution,
            'resolved_at' => $sale->resolved_at?->toIso8601String(),
            'resolved_by' => $sale->resolved_by_user_id === null
                ? null
                : ['id' => (int) $sale->resolved_by_user_id, 'name' => $sale->getAttribute('resolver_name')],
            'refunded' => $refunded,
            // refunded | partially_refunded | disputed | null: the order's own word, for a banner.
            'charge_flag' => $flag,
            'to_hand_out' => $collectedAt === null && ! $refunded && $resolution !== ProductSale::RESOLUTION_REFUNDED,
        ];
    }

    /**
     * The header counts, per product and size, from ONE grouped query over the whole organisation
     * (the filters of the list do not move it): `to_hand_out` and `collected` are UNITS (a sale
     * resolved as refunded is not to hand out); `oversold_open` is LINES (sales, not units): the ones
     * that are oversold, whose order is not refunded or disputed, that carry no resolution and have
     * not been collected, so each still needs somebody's call. It replaces the units of every
     * oversold sale not refunded, which kept counting a sale the office had dealt with.
     *
     * Grouped by the names the sales carry, so a product renamed after it sold shows as two lines
     * for the same size rather than hiding one of its names.
     *
     * @return list<array{product_id:int, variant_id:int, product_name:string, variant_label:string, to_hand_out:int, collected:int, oversold_open:int}>
     */
    public static function summary(): array
    {
        // Constants of this class, never input: embedded rather than bound.
        $flags = "'" . implode("', '", self::REFUNDED_FLAGS) . "'";
        $notRefunded = "(orders.charge_flag IS NULL OR orders.charge_flag NOT IN ({$flags}))";
        $resolvedRefunded = "product_sales.resolution = '" . ProductSale::RESOLUTION_REFUNDED . "'";

        $rows = ProductSale::query()
            ->join('orders', static function (JoinClause $join): void {
                $join->on('orders.id', '=', 'product_sales.order_id')
                    ->on('orders.masjid_id', '=', 'product_sales.masjid_id');
            })
            ->selectRaw(
                'product_sales.product_id, product_sales.variant_id, product_sales.product_name, product_sales.variant_label, '
                . "COALESCE(SUM(CASE WHEN product_sales.collected_at IS NULL AND {$notRefunded} AND (product_sales.resolution IS NULL OR NOT ({$resolvedRefunded})) THEN product_sales.quantity ELSE 0 END), 0) AS to_hand_out_units, "
                . 'COALESCE(SUM(CASE WHEN product_sales.collected_at IS NOT NULL THEN product_sales.quantity ELSE 0 END), 0) AS collected_units, '
                . "COALESCE(SUM(CASE WHEN product_sales.oversold = 1 AND {$notRefunded} AND product_sales.resolution IS NULL AND product_sales.collected_at IS NULL THEN 1 ELSE 0 END), 0) AS oversold_open_lines"
            )
            ->groupBy('product_sales.product_id', 'product_sales.variant_id', 'product_sales.product_name', 'product_sales.variant_label')
            ->orderBy('product_sales.product_id')
            ->orderBy('product_sales.variant_id')
            ->orderBy('product_sales.product_name')
            ->orderBy('product_sales.variant_label')
            ->get();

        return $rows->map(static fn (ProductSale $row): array => [
            'product_id' => (int) $row->product_id,
            'variant_id' => (int) $row->variant_id,
            'product_name' => (string) $row->product_name,
            'variant_label' => (string) $row->variant_label,
            'to_hand_out' => (int) $row->getAttribute('to_hand_out_units'),
            'collected' => (int) $row->getAttribute('collected_units'),
            'oversold_open' => (int) $row->getAttribute('oversold_open_lines'),
        ])->values()->all();
    }
}
