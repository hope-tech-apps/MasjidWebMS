<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Funds\StoreFundRequest;
use App\Models\Fund;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: donation funds (designations) CRUD — minimal (list + create) for the
 * Phase-0 money-path spike.
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
}
