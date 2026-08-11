<?php

namespace App\Http\Controllers;

use App\Models\Masjid;
use App\Services\Stripe\StripeConnectService;
use App\Support\Errors;
use Illuminate\Contracts\View\View;

/**
 * Public browser landings for Stripe Connect hosted onboarding.
 *
 * Stripe redirects the ORG ADMIN'S BROWSER to the Account Link's
 * `return_url` / `refresh_url`. That browser carries no Sanctum token, so these
 * cannot sit behind `auth:sanctum`: pointing Stripe at the authenticated API
 * route rendered a raw `{"status":"error","message":"Request failed."}` body,
 * which a real org read as a failure and abandoned onboarding halfway
 * (2026-08-10, Burlington Masjid — left with `requirements.past_due`).
 *
 * Because they are unauthenticated, these pages stay information-thin: they
 * report only whether the masjid can currently take charges/payouts. The
 * connected-account id, Stripe requirement details, and anything key-shaped are
 * deliberately never rendered.
 */
class ConnectOnboardingLandingController extends Controller
{
    public function __construct(private StripeConnectService $connect)
    {
    }

    /**
     * Landing shown when Stripe returns the admin from hosted onboarding.
     *
     * Refreshes capability flags straight from Stripe's API so the page reflects
     * reality immediately instead of waiting on webhook delivery. This is a
     * CONVENIENCE read, not the authoritative path — `account.updated` on the
     * signed webhook remains the source of truth (see
     * `.claude/rules/stripe-payments.md`). Nothing here trusts browser input:
     * the route id only selects which account to re-read from Stripe.
     */
    public function complete(string $masjid_id): View
    {
        $masjid = Masjid::find($masjid_id);

        if (! $masjid) {
            return $this->page('unknown');
        }

        try {
            $masjid = $this->connect->refreshFromStripe($masjid);
        } catch (\Throwable $e) {
            // Non-fatal by design. Stripe being briefly unreachable must not show
            // the org an error after they just finished onboarding — the webhook
            // reconciles the flags regardless. Errors::publicMessage logs it.
            Errors::publicMessage($e);
        }

        return $this->page(
            $masjid->stripe_charges_enabled ? 'connected' : 'pending',
            $masjid->name,
            (bool) $masjid->stripe_payouts_enabled,
        );
    }

    /**
     * Landing shown when the Account Link has expired.
     *
     * Stripe GETs `refresh_url` once a link is no longer usable. This
     * deliberately does NOT mint a replacement link: minting from an
     * unauthenticated route would let anyone generate hosted onboarding for any
     * masjid and submit their own bank account as the payout destination. The
     * admin re-triggers onboarding through the authenticated endpoint instead.
     */
    public function expired(string $masjid_id): View
    {
        $masjid = Masjid::find($masjid_id);

        return $this->page($masjid ? 'expired' : 'unknown', $masjid?->name);
    }

    /**
     * Render the shared status page.
     *
     * @param  string  $state  one of: connected, pending, expired, unknown
     */
    private function page(string $state, ?string $masjidName = null, bool $payoutsEnabled = false): View
    {
        return view('connect.onboarding-status', [
            'state' => $state,
            'masjidName' => $masjidName,
            'payoutsEnabled' => $payoutsEnabled,
            'portalUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }
}
