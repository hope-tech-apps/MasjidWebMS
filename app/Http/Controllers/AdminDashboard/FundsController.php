<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Funds\StoreFundRequest;
use App\Http\Requests\Admin\Funds\UpdateFundRequest;
use App\Models\Fund;
use App\Support\Errors;
use Illuminate\Http\Request;
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
     * Delete a fund. Funds are NOT soft-deleted, and donations.fund_id is a
     * non-cascading FK, so deleting a fund that still has donations attached
     * raises a QueryException — caught here and returned as a clean failed
     * envelope rather than an unhandled 500. Scoped findOrFail → 404
     * cross-tenant (kept outside the try so a miss is not swallowed).
     */
    public function destroy($masjid_id, $fund_id)
    {
        $fund = Fund::findOrFail($fund_id);

        try {
            $fund->delete();

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
}
