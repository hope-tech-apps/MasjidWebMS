<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\DonationSubscription;
use App\Services\Stripe\DonationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: recurring donations (standing commitments).
 *
 * Tenant-scoped by the `tenant` middleware + BelongsToMasjid — no hand-filtering
 * by $masjid_id, so findOrFail on another masjid's subscription is a 404, never a
 * leak. Subscriptions are created and advanced ONLY by the public checkout +
 * Stripe webhooks; the one mutation here is cancel.
 */
class RecurringDonationsController extends Controller
{
    public function __construct(private DonationService $donations)
    {
    }

    public function index(Request $request, $masjid_id)
    {
        $subscriptions = DonationSubscription::query()
            ->with(['fund', 'contact'])
            ->withCount('donations')                 // how many charges booked so far
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $subscriptions,
        ], Response::HTTP_OK);
    }

    public function show($masjid_id, $subscription_id)
    {
        $subscription = DonationSubscription::with(['fund', 'contact', 'donations'])
            ->findOrFail($subscription_id);

        return response()->json([
            'status' => 'success',
            'data' => $subscription,
        ], Response::HTTP_OK);
    }

    /**
     * Cancel a recurring gift. Cancels at Stripe (so no further charges) and marks
     * the row canceled. Idempotent — canceling an already-canceled one is a no-op
     * success. The tenant scope on findOrFail prevents canceling another masjid's.
     */
    public function cancel($masjid_id, $subscription_id)
    {
        $subscription = DonationSubscription::findOrFail($subscription_id);

        if ($subscription->status !== 'canceled') {
            $this->donations->cancelSubscription($subscription);
        }

        return response()->json([
            'status' => 'success',
            'data' => $subscription->refresh(),
        ], Response::HTTP_OK);
    }
}
