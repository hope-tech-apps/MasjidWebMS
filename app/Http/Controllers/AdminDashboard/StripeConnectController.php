<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Services\Stripe\StripeConnectService;
use App\Support\Errors;
use App\Support\SiteUrl;
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
     *
     * `return_url` / `refresh_url` point at the PUBLIC landings in
     * ConnectOnboardingLandingController, not at this authenticated API group:
     * Stripe redirects the admin's browser there with no Sanctum token, so an
     * authed target renders a raw error envelope and reads as a failed
     * onboarding (observed 2026-08-10).
     */
    public function startOnboarding(Request $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            // An org whose form card payments charge through another org's
            // account must not grow an account of its own beside that link
            // (DECISIONS.md 2026-09-15): a SuperAdmin removes the link first.
            if ($masjid->forms_card_via_masjid_id !== null) {
                return response()->json([
                    'status' => 'failed',
                    'data' => 'This organisation takes form card payments through another organisation\'s Stripe account. A super admin must remove that link before it connects its own.',
                ], Response::HTTP_CONFLICT);
            }

            // On the CONFIGURED host. Stripe keeps these in the Account Link and
            // sends the admin's browser to them later, to the public landing
            // (ConnectOnboardingLandingController), which needs no token and
            // links back to config('app.url') itself — so nothing is gained
            // by following the host the admin happened to be on, and a value
            // that outlives its request must not be built from it.
            $refreshUrl = SiteUrl::route('connect.refresh', ['masjid_id' => $masjid->id]);
            $returnUrl = SiteUrl::route('connect.return', ['masjid_id' => $masjid->id]);

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
     * Authenticated connect status for the admin SPA: opportunistically refresh
     * capability flags from Stripe (the authoritative refresh still arrives via
     * account.updated) and report current connect status as JSON.
     *
     * This is NOT Stripe's redirect target — that is the public landing in
     * ConnectOnboardingLandingController. This endpoint exists so the portal can
     * poll/display connection state for a masjid it is already authorized for,
     * and unlike the public landing it may safely include the account id.
     */
    public function status(Request $request, $masjid_id)
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
                // DECISIONS.md 2026-09-15. For a child: whose account charges its
                // forms, by name, and whether that works now. For a holder: who
                // charges through it. Neither block ever carries an acct_ id: the
                // child's own stripe_account_id above stays its own (NULL).
                'forms_card_via' => FormsCardAccountController::viaSummary($masjid),
                'forms_card_for' => FormsCardAccountController::forSummary($masjid),
            ],
        ], Response::HTTP_OK);
    }
}
