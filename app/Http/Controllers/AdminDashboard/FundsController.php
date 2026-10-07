<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Funds\StoreFundRequest;
use App\Http\Requests\Admin\Funds\UpdateFundRequest;
use App\Models\CartItem;
use App\Models\Donation;
use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Errors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: donation funds (designations) CRUD.
 *
 * Tenant isolation is NOT hand-rolled here. The route keeps the
 * /masjids/{masjid_id}/... prefix by convention, but the `tenant` middleware
 * binds TenantContext and the BelongsToMasjid trait auto-scopes every Fund
 * query — so we never filter by $masjid_id and never set masjid_id from client
 * input (the creating hook stamps it). See .claude/rules/tenant-scoping.md.
 */
class FundsController extends Controller
{
    public function index(Request $request, $masjid_id)
    {
        $funds = Fund::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $funds,
        ], Response::HTTP_OK);
    }

    public function store(StoreFundRequest $request, $masjid_id)
    {
        try {
            // masjid_id is intentionally omitted — the BelongsToMasjid creating
            // hook stamps it from the bound tenant.
            $fund = Fund::create($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $fund,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show a single fund. findOrFail is tenant-scoped, so another masjid's id
     * resolves to a 404 rather than leaking the row.
     */
    public function show($masjid_id, $fund_id)
    {
        $fund = Fund::findOrFail($fund_id);

        return response()->json([
            'status' => 'success',
            'data' => $fund,
        ], Response::HTTP_OK);
    }

    /**
     * Update a fund. The scoped findOrFail runs OUTSIDE the try so a
     * cross-tenant / missing id surfaces as a clean 404 instead of being
     * swallowed into a 500 by the catch below.
     */
    public function update(UpdateFundRequest $request, $masjid_id, $fund_id)
    {
        $fund = Fund::findOrFail($fund_id);

        try {
            $fund->update($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $fund,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Keep gift history, recurring commitments and payable basket gifts. Lock the
     * parent FIRST inside the transaction so InnoDB's first ordinary read sees
     * children committed before the lock was won. Child FK inserts take a shared
     * parent lock. A basket's reference to a fund is polymorphic and has no FK:
     * `basketStillNeeds()` is read under this lock, but basket checkout does not
     * take it, so a basket paid in the same instant can still lose its fund
     * (the gap that existed before this change; DECISIONS.md).
     * Scoped lookup stays outside the catch to preserve cross-tenant 404s.
     */
    public function destroy($masjid_id, $fund_id)
    {
        $fund = Fund::findOrFail($fund_id);

        try {
            return DB::transaction(function () use ($fund) {
                $fund = Fund::query()->whereKey($fund->id)->lockForUpdate()->firstOrFail();

                // Check every FK reference, including any inconsistent tenant stamp.
                // Pending/failed gifts and canceled commitments are still history.
                if (Donation::withoutMasjidScope()->where('fund_id', $fund->id)->exists()) {
                    return response()->json([
                        'status' => 'failed',
                        'data' => 'This fund has gifts recorded in it, so it cannot be deleted. Switch it to inactive instead: it is then hidden from new donations and its history is kept.',
                    ], Response::HTTP_CONFLICT);
                }

                if (DonationSubscription::withoutMasjidScope()->where('fund_id', $fund->id)->exists()) {
                    return response()->json([
                        'status' => 'failed',
                        'data' => 'This fund has recurring gifts linked to it, so it cannot be deleted. Switch it to inactive instead: it is then hidden from new donations and its history is kept.',
                    ], Response::HTTP_CONFLICT);
                }

                if ($this->basketStillNeeds($fund)) {
                    return response()->json([
                        'status' => 'failed',
                        'data' => 'This fund cannot be deleted yet: a basket gift to it is waiting for payment or has not been recorded. Deactivate it instead, or delete it once those gifts are settled.',
                    ], Response::HTTP_CONFLICT);
                }

                $fund->delete();

                return response()->json([
                    'status' => 'success',
                    'data' => $fund,
                ], Response::HTTP_OK);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e, allowDebugMessage: false),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Whether a basket line still needs this fund row: a donation line for it on a PENDING
     * order (a payment page that can still be paid, and settlement will write the gift
     * against the fund), or on a PAID order whose line has no `record_id` yet (money taken,
     * gift not yet written; a retry of settlement reads the fund again).
     *
     * The tables are the cart's, which a deploy creates in `migrate`, a step after the code
     * goes live: until then there is no basket to protect, and asking would be a 500.
     */
    private function basketStillNeeds(Fund $fund): bool
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
            return false;
        }

        return OrderItem::query()
            ->where('masjid_id', $fund->masjid_id)
            ->where('buyable_type', CartItem::TYPE_DONATION)
            ->where('buyable_id', $fund->id)
            ->where(function (Builder $line) {
                $line->whereHas('order', fn (Builder $order) => $order->where('status', Order::STATUS_PENDING))
                    ->orWhere(function (Builder $unrecorded) {
                        $unrecorded->whereNull('record_id')
                            ->whereHas('order', fn (Builder $order) => $order->where('status', Order::STATUS_PAID));
                    });
            })
            ->exists();
    }
}
