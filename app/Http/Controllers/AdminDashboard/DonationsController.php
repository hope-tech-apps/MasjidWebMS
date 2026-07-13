<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: donations list (read-only for the Phase-0 spike).
 *
 * Tenant-scoped by the `tenant` middleware + BelongsToMasjid — no hand-filtering
 * by $masjid_id. See .claude/rules/tenant-scoping.md.
 */
class DonationsController extends Controller
{
    public function index(Request $request, $masjid_id)
    {
        $donations = Donation::query()
            ->with(['fund', 'receipt'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('fund_id'), fn ($q, $fundId) => $q->where('fund_id', $fundId))
            ->latest()
            ->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $donations,
        ], Response::HTTP_OK);
    }

    /**
     * Show a single donation with its fund and issued receipt eager-loaded.
     * Read-only: donations are created and advanced ONLY by Stripe webhooks,
     * never through the admin API. findOrFail is tenant-scoped, so another
     * masjid's id resolves to a 404 rather than leaking the row.
     */
    public function show($masjid_id, $donation_id)
    {
        $donation = Donation::with(['fund', 'receipt'])->findOrFail($donation_id);

        return response()->json([
            'status' => 'success',
            'data' => $donation,
        ], Response::HTTP_OK);
    }
}
