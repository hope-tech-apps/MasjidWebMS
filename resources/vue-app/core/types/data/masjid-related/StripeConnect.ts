// Stripe Connect onboarding state for the admin portal.
//
// Mirrors App\Http\Controllers\AdminDashboard\StripeConnectController. Both
// endpoints live in the CRM route group behind `permission:manage donations`,
// so a 403 means "this admin cannot manage the money path" (or the tenant's
// CRM gate is off) — the UI hides the panel rather than erroring.

/** An organisation as the form card link names it: an id and a name, never an account id. */
export type FormsCardOrg = {
    id: number;
    name: string;
};

/**
 * This organisation's FORM card payments go through another organisation's Stripe account
 * (masjids.forms_card_via_masjid_id, DECISIONS.md 2026-09-15). Only a SuperAdmin sets it,
 * and only to the organisation's parent. The holder's Stripe account id is NEVER sent.
 */
export type FormsCardVia = {
    /** name is null only when the holder row cannot be found at all (FormsCardAccountController::viaSummary()). */
    holder: { id: number; name: string | null };
    /** FormChargeAccount::for() would charge through the holder right now. */
    ready: boolean;
    /** FormChargeAccount::linkProblem()'s reason code when not ready, else null. */
    problem: string | null;
};

/** GET /api/admin/masjids/{masjid_id}/connect/status → data */
export type ConnectStatus = {
    /** null until the first onboarding call creates the connected account. */
    stripe_account_id: string | null;
    /** The account can take donations. */
    charges_enabled: boolean;
    /** Stripe pays the balance out. Often lags charges_enabled while Stripe
     *  reviews a new account — that gap is normal, not a failure. */
    payouts_enabled: boolean;
    /**
     * Set when this organisation's form card payments go through another organisation.
     * Onboarding is refused (409) while it is set, so the panel offers no Connect button.
     * Absent from an API older than the link.
     */
    forms_card_via?: FormsCardVia | null;
    /** The organisations whose form card payments go through THIS organisation's account. */
    forms_card_for?: FormsCardOrg[];
};

/** POST /api/admin/masjids/{masjid_id}/connect/onboarding → data */
export type ConnectOnboarding = {
    /**
     * Hosted Stripe Account Link. It EXPIRES IN MINUTES — open it immediately
     * in response to the click; never store it or offer it for copy-paste.
     */
    onboarding_url: string;
};

/**
 * GET /api/admin/masjids/{masjid_id}/forms/card-account → data
 *
 * Whether a form on this organisation can take a card payment right now, and through whom.
 * In the forms route group, so it needs no `manage donations` permission (the form builder
 * reads it). Never carries an account id.
 *  - own:         the organisation's own Stripe account can take the payment.
 *  - linked:      the payment goes through `holder`'s account, and it is ready.
 *  - unavailable: card payment will be refused; `holder` is set when the organisation is
 *                 linked, and `problem` says why when the server knows.
 */
export type FormsCardAccountState = 'own' | 'linked' | 'unavailable';

export type FormsCardAccount = {
    state: FormsCardAccountState;
    holder: FormsCardOrg | null;
    problem: string | null;
};

/**
 * PATCH /api/admin/masjids/{masjid_id}/forms-card-account (SuperAdmin only).
 * Linking sends all three; unlinking sends `via_masjid_id: null` alone.
 */
export type FormsCardLinkPayload = {
    via_masjid_id: number | null;
    /** Must equal the holder's name exactly. Required when linking. */
    typed_holder_name?: string;
    /** Where the consent is recorded. Required when linking, at most 1000 characters. */
    consent_reference?: string;
};

/** The PATCH answer's data. */
export type FormsCardLinkResult = {
    forms_card_via: FormsCardVia | null;
};

/** consent_reference's server-side limit. */
export const FORMS_CARD_CONSENT_MAX = 1000;

/**
 * The reason codes in plain words: FormChargeAccount::PROBLEMS, plus the three
 * FormsCardAccountController adds on a read (`not_connected` and `charges_disabled` for an
 * organisation that is not linked, `unavailable` for a link with no more specific reason).
 * Each is a clause that follows "because". The server owns the codes; one this screen does
 * not know is shown humanised, so a new code is never blank.
 */
const FORMS_CARD_PROBLEM_TEXT: Record<string, string> = {
    organisation_missing: 'this organisation has been archived',
    // Only ever read back for a LINKED organisation (FormChargeAccount::for refuses a link
    // whose child has an account of its own), so its forms charge on neither account.
    has_own_account: 'this organisation also has a Stripe account of its own, which a link to another organisation does not allow',
    same_organisation: 'it is linked to itself',
    not_parent: 'the organisation it is linked to is not its parent organisation',
    holder_missing: 'the organisation it is linked to no longer exists or has been archived',
    holder_linked: 'the organisation it is linked to charges its own form payments through another organisation',
    holder_not_onboarded: 'the organisation it is linked to has not connected a Stripe account',
    holder_charges_disabled: 'Stripe does not let the organisation it is linked to take card payments right now',
    is_holder: 'other organisations already take form card payments through this one',
    not_connected: 'this organisation has not connected a Stripe account',
    charges_disabled: 'Stripe does not let this organisation take card payments right now',
    unavailable: 'the link to the other organisation cannot be used right now',
};

/** The codes that describe a LINK (fixed by the holder or Manara), not the organisation's own account. */
const FORMS_CARD_LINK_PROBLEMS = [
    'same_organisation',
    'not_parent',
    'holder_missing',
    'holder_linked',
    'holder_not_onboarded',
    'holder_charges_disabled',
    'unavailable',
];

export function formsCardProblemIsLink(problem: string | null | undefined): boolean {
    return !!problem && FORMS_CARD_LINK_PROBLEMS.includes(problem);
}

/** A reason code as a clause ("the organisation it is linked to has been archived"). */
export function formsCardProblemText(problem: string | null | undefined): string {
    if (!problem) return '';

    const known = FORMS_CARD_PROBLEM_TEXT[problem];
    if (known) return known;

    return problem.replace(/[_-]+/g, ' ').trim();
}
