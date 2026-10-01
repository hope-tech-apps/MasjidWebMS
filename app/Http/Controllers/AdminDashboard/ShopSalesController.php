<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Shop\IndexShopSalesRequest;
use App\Http\Requests\Admin\Shop\ResolveShopSaleRequest;
use App\Models\Masjid;
use App\Models\Order;
use App\Models\ProductSale;
use App\Services\Shop\PickupList;
use App\Support\MasjidTime;
use App\Support\SchoolRecordsCsv as Csv;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin: the shop's pickup list (shop slice B2): who bought what and has it been handed over, the
 * "collected" mark and its undo, and the same list as a CSV.
 *
 * Tenant-scoped by the `tenant` middleware and BelongsToMasjid, never by a hand-written filter: the
 * list, the summary and the CSV are one query (PickupList) that carries the organisation as
 * `product_sales.masjid_id`, and another organisation's sale is a 404 on collect and undo. The
 * buyer's name, e-mail and phone come from the ORDER; nothing of them is stored on the sale.
 *
 * `collect` and `uncollect` are the form roster's check-in (FormResponsesController::collect) for a
 * sale: stamped by the FIRST press only, so a second press, or a colleague's at the next table,
 * is answered with the row as it stands and never rewrites who handed it over. Both write the
 * actor to the log as the office's other actions do, and the undo also writes who it un-did, since
 * clearing the stamp would otherwise erase that.
 */
class ShopSalesController extends Controller
{
    /**
     * GET .../shop/sales[?state=to_hand_out|collected|all&product_id=&variant_id=&search=&per_page=&page=]
     *
     * Newest paid first. `meta.summary` is the header: per product and size, units still to hand
     * out, collected, and oversold and not refunded, over the whole organisation (the filters do
     * not move it).
     */
    public function index(IndexShopSalesRequest $request, $masjid_id): JsonResponse
    {
        $filters = $request->filters();

        $page = PickupList::query($filters)
            ->orderByDesc('orders.paid_at')
            ->orderByDesc('product_sales.id')
            ->paginate($request->perPage());

        $page->through(static fn (ProductSale $sale): array => PickupList::row($sale));

        return response()->json([
            'status' => 'success',
            'data' => $page,
            'meta' => [
                'state' => $filters['state'],
                'summary' => PickupList::summary(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * GET .../shop/sales.csv: the same filters, the same rows, as a file.
     *
     * Every cell a person typed (the buyer's name, e-mail and phone, the product and size names, the
     * collector's name) goes through the shared formula-injection guard of the school-records
     * exports (SchoolRecordsCsv::text), because Excel and Sheets run a cell that starts with `=`, `+`,
     * `-` or `@`. Rows come in sale-id order (the shared chunked walk needs an unordered query), which
     * is the order the sales were recorded in; a spreadsheet sorts the rest. Times are the
     * organisation's own clock, with the zone written out.
     */
    public function export(IndexShopSalesRequest $request, $masjid_id): StreamedResponse
    {
        $masjid = Masjid::query()->findOrFail($this->tenantId());
        $zone = MasjidTime::zoneFor((int) $masjid->id);
        $query = PickupList::query($request->filters());

        $filename = 'shop-sales-' . (Str::slug($masjid->name) ?: $masjid->id) . '-' . now()->format('Y-m-d') . '.csv';

        return response()->stream(function () use ($query, $zone): void {
            $out = Csv::open();

            Csv::row($out, [
                'Sale', 'Order number', 'Paid at', 'Buyer name', 'Buyer email', 'Buyer phone',
                'Product', 'Size', 'Quantity', 'Total', 'Currency',
                'Collected at', 'Collected by', 'Oversold', 'Refunded', 'Charge flag', 'Resolution',
            ]);

            // The sale id, qualified: the query joins the orders and users tables, which have an `id` too.
            $query->chunkById(500, function ($chunk) use ($out, $zone): void {
                foreach ($chunk as $sale) {
                    $row = PickupList::row($sale);

                    Csv::row($out, [
                        Csv::num($row['id']),
                        Csv::text($row['order_number']),
                        $this->moment($sale->getAttribute('order_paid_at'), $zone),
                        Csv::text($row['buyer_name']),
                        Csv::text($row['buyer_email']),
                        Csv::text($row['buyer_phone']),
                        Csv::text($row['product_name']),
                        Csv::text($row['variant_label']),
                        Csv::num($row['quantity']),
                        $this->money($row['total_minor']),
                        strtoupper($row['currency']),
                        $this->moment($sale->collected_at, $zone),
                        Csv::text($row['collected_by']['name'] ?? ''),
                        $row['oversold'] ? 'yes' : 'no',
                        // As the list says it: refunded or disputed, so never to hand out.
                        $row['refunded'] ? 'yes' : 'no',
                        // The order's own word when it carries one (refunded, partially_refunded, disputed),
                        // so a partly refunded sale, which is still to hand out, is not read as refunded.
                        Csv::text($row['charge_flag'] ?? ''),
                        // What the office did about the sale: refunded or substituted, else blank.
                        Csv::text($row['resolution'] ?? ''),
                    ]);
                }
            }, 'product_sales.id', 'id');

            fclose($out);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            // Names, e-mail addresses and phone numbers: never cached anywhere.
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * POST .../shop/sales/{sale_id}/collect: the item has been handed over.
     *
     * Stamped by the first press only. Refused, with a sentence, for a sale whose order was refunded
     * or is disputed, and for a sale the office resolved as refunded: collecting must never read as
     * "handed over" on money that went back.
     *
     * LOCKS, in this order: the SALE row, then the ORDER row (`lockForUpdate()` on its charge flag), so
     * a refund or dispute that is being recorded at this moment is WAITED FOR and seen, not raced.
     * There is no cycle with the writers of that flag: settlement locks the order and then INSERTS new
     * sales (it never locks an existing sale row), and the refund arm (CartPaymentService::flagOrder)
     * locks the order and nothing else. Only this action takes sale then order, and it takes no
     * lock a settlement or a refund waits on while holding the order.
     */
    public function collect(Request $request, $masjid_id, $sale_id): JsonResponse
    {
        $actor = $request->user();

        $outcome = DB::transaction(function () use ($actor, $sale_id): array {
            $sale = ProductSale::query()->lockForUpdate()->findOrFail($sale_id);
            $flag = Order::query()->whereKey($sale->order_id)->lockForUpdate()->value('charge_flag');

            if (PickupList::isRefundedFlag($flag)) {
                return ['refused', $flag === Order::CHARGE_FLAG_DISPUTED
                    ? 'This order is disputed with the card holder\'s bank, so do not hand anything out until that is settled.'
                    : 'This order was refunded, so there is nothing to hand out.'];
            }

            if ($sale->resolution === ProductSale::RESOLUTION_REFUNDED) {
                return ['refused', 'This sale was marked refunded, so there is nothing to hand out. Clear that first if it was a mistake.'];
            }

            if ($sale->collected_at !== null) {
                return ['already', $sale];
            }

            $sale->forceFill(['collected_at' => now(), 'collected_by_user_id' => $actor->getKey()])->save();

            return ['collected', $sale];
        });

        [$result, $detail] = $outcome;

        if ($result === 'refused') {
            return response()->json(['status' => 'failed', 'message' => $detail], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($result === 'collected') {
            Log::info('A shop sale was marked collected.', $this->logContext($detail, $actor->getKey()));
        }

        return $this->done($detail, $result === 'already' ? 'Already handed out.' : 'Handed out.');
    }

    /**
     * DELETE .../shop/sales/{sale_id}/collect: "Undo". Allowed on a refunded sale too (it only clears
     * a mark). Nothing to undo is not an error. The log keeps who had collected it and when.
     */
    public function uncollect(Request $request, $masjid_id, $sale_id): JsonResponse
    {
        $actor = $request->user();

        [$sale, $was] = DB::transaction(function () use ($sale_id): array {
            $sale = ProductSale::query()->lockForUpdate()->findOrFail($sale_id);
            $was = null;

            if ($sale->collected_at !== null) {
                $was = [
                    'at' => $sale->collected_at->toIso8601String(),
                    'by' => $sale->collected_by_user_id === null ? null : (int) $sale->collected_by_user_id,
                ];
                $sale->forceFill(['collected_at' => null, 'collected_by_user_id' => null])->save();
            }

            return [$sale, $was];
        });

        if ($was !== null) {
            Log::info('A shop sale\'s collected mark was undone.', $this->logContext($sale, $actor->getKey()) + [
                'was_collected_at' => $was['at'],
                'was_collected_by_user_id' => $was['by'],
            ]);
        }

        return $this->done($sale, $was === null ? 'This sale was not marked collected.' : 'Collected mark undone.');
    }

    /**
     * POST .../shop/sales/{sale_id}/resolve  {resolution: refunded|substituted}
     *
     * What the office did about a sale (an oversold one above all): `refunded` takes it off the
     * to-hand-out list and refuses collect; `substituted` leaves it to hand out (the substitute is what
     * is handed over) and ends the need for anyone's call. Idempotent: the same word again changes
     * nothing and keeps the FIRST resolver and moment; a DIFFERENT word replaces the resolution (the
     * current one is what the sale carries, with whoever set it and when). Locked on the sale row; the
     * actor goes to the log as collect's does, ids only.
     */
    public function resolve(ResolveShopSaleRequest $request, $masjid_id, $sale_id): JsonResponse
    {
        $actor = $request->user();
        $resolution = $request->resolution();

        [$result, $sale, $was] = DB::transaction(function () use ($actor, $sale_id, $resolution): array {
            $sale = ProductSale::query()->lockForUpdate()->findOrFail($sale_id);

            if ($sale->resolution === $resolution) {
                return ['already', $sale, null];
            }

            $was = $sale->resolution === null ? null : [
                'resolution' => (string) $sale->resolution,
                'by' => $sale->resolved_by_user_id === null ? null : (int) $sale->resolved_by_user_id,
            ];

            $sale->forceFill([
                'resolution' => $resolution,
                'resolved_at' => now(),
                'resolved_by_user_id' => $actor->getKey(),
            ])->save();

            return ['resolved', $sale, $was];
        });

        if ($result === 'resolved') {
            Log::info('A shop sale was resolved.', $this->logContext($sale, $actor->getKey()) + [
                'resolution' => $resolution,
                'was_resolution' => $was['resolution'] ?? null,
                'was_resolved_by_user_id' => $was['by'] ?? null,
            ]);
        }

        return $this->done($sale, $result === 'already' ? "Already marked {$resolution}." : "Marked {$resolution}.");
    }

    /**
     * DELETE .../shop/sales/{sale_id}/resolve: clear the resolution ("I marked the wrong one"). Nothing
     * to clear is not an error. The log keeps what it was and who had set it.
     */
    public function unresolve(Request $request, $masjid_id, $sale_id): JsonResponse
    {
        $actor = $request->user();

        [$sale, $was] = DB::transaction(function () use ($sale_id): array {
            $sale = ProductSale::query()->lockForUpdate()->findOrFail($sale_id);
            $was = null;

            if ($sale->resolution !== null) {
                $was = [
                    'resolution' => (string) $sale->resolution,
                    'by' => $sale->resolved_by_user_id === null ? null : (int) $sale->resolved_by_user_id,
                ];
                $sale->forceFill(['resolution' => null, 'resolved_at' => null, 'resolved_by_user_id' => null])->save();
            }

            return [$sale, $was];
        });

        if ($was !== null) {
            Log::info('A shop sale\'s resolution was cleared.', $this->logContext($sale, $actor->getKey()) + [
                'was_resolution' => $was['resolution'],
                'was_resolved_by_user_id' => $was['by'],
            ]);
        }

        return $this->done($sale, $was === null ? 'This sale had no resolution.' : 'Resolution cleared.');
    }

    // ------------------------------------------------------------------------- helpers

    private function done(ProductSale $sale, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => PickupList::row(PickupList::find((int) $sale->id) ?? $sale),
        ], Response::HTTP_OK);
    }

    /**
     * Ids and the order's number: never a buyer's name, e-mail or phone.
     *
     * @return array<string,mixed>
     */
    private function logContext(ProductSale $sale, mixed $actorId): array
    {
        return [
            'masjid_id' => (int) $sale->masjid_id,
            'sale_id' => (int) $sale->id,
            'order_id' => (int) $sale->order_id,
            'actor_user_id' => $actorId,
        ];
    }

    /** A moment as the organisation's own clock reads it, zone included ("2026-10-08 19:04 EDT"), or ''. */
    private function moment(?CarbonInterface $moment, string $zone): string
    {
        return $moment === null ? '' : $moment->copy()->setTimezone($zone)->format('Y-m-d H:i T');
    }

    /** Integer minor units as a plain decimal for a spreadsheet ("25.00"), with no float in between. */
    private function money(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** The organisation the SERVER bound for this request (never the URL's claim). */
    private function tenantId(): int
    {
        $masjidId = app(TenantContext::class)->get();

        if ($masjidId === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return (int) $masjidId;
    }
}
