// Stripe Connect onboarding state for the admin portal.
//
// Mirrors App\Http\Controllers\AdminDashboard\StripeConnectController. Both
// endpoints live in the CRM route group behind `permission:manage donations`,
// so a 403 means "this admin cannot manage the money path" (or the tenant's
// CRM gate is off) — the UI hides the panel rather than erroring.

/** GET /api/admin/masjids/{masjid_id}/connect/status → data */
export type ConnectStatus = {
    /** null until the first onboarding call creates the connected account. */
    stripe_account_id: string | null;
    /** The account can take donations. */
    charges_enabled: boolean;
    /** Stripe pays the balance out. Often lags charges_enabled while Stripe
     *  reviews a new account — that gap is normal, not a failure. */
    payouts_enabled: boolean;
};

/** POST /api/admin/masjids/{masjid_id}/connect/onboarding → data */
export type ConnectOnboarding = {
    /**
     * Hosted Stripe Account Link. It EXPIRES IN MINUTES — open it immediately
     * in response to the click; never store it or offer it for copy-paste.
     */
    onboarding_url: string;
};
