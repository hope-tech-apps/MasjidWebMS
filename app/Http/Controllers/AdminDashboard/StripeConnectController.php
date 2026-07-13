<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Services\Stripe\StripeConnectService;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: Stripe Connect (Standard account) onboarding for a masjid.
 *
 * Tenant isolation is enforced by the `tenant` middleware (ResolveMasjidTenant):
 * a MasjidAdmin targeting another masjid in the route is 403'd before reaching
 * here. Masjid is the tenant root (not a BelongsToMasjid model), so we resolve
 * it by the route id directly.
 */
class StripeConnectController extends Controller
{
    public function __construct(private StripeConnectService $connect)
    {
    }

    /**
     * Begin (or resume) onboarding: ensure the connected account exists and
     * return a hosted Account Link the admin is redirected to.
     */
    public function startOnboarding(Request $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $base = rtrim((string) config('app.url'), '/');
            $refreshUrl = $base . "/api/admin/masjids/{$masjid->id}/connect/onboarding";
            $returnUrl = $base . "/api/admin/masjids/{$masjid->id}/connect/return";

            $url = $this->connect->createOnboardingLink($masjid, $refreshUrl, $returnUrl);

            return response()->json([
                'status' => 'success',
                'data' => ['onboarding_url' => $url],
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Onboarding return landing: opportunistically refresh capability flags
     * from Stripe (the authoritative refresh still arrives via account.updated)
     * and report current connect status.
     */
    public function onboardingReturn(Request $request, $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        try {
            $masjid = $this->connect->refreshFromStripe($masjid);
        } catch (\Throwable $e) {
            // Non-fatal: fall back to stored flags (webhook will reconcile).
            Errors::publicMessage($e);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'stripe_account_id' => $masjid->stripe_account_id,
                'charges_enabled' => (bool) $masjid->stripe_charges_enabled,
                'payouts_enabled' => (bool) $masjid->stripe_payouts_enabled,
            ],
        ], Response::HTTP_OK);
    }
}
