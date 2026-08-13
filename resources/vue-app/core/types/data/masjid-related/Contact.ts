export type Contact = {
    id: number;
    masjid_id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    notes: string | null;
    created_at: string;
    updated_at: string;
    /**
     * Present and non-null only in the DELETED view (`?trashed=with|only`). The
     * ordinary directory never returns a deleted member, so this is undefined
     * there rather than null — a row that omits the key and a row that carries
     * `deleted_at: null` are the same fact, and the UI tests truthiness.
     */
    deleted_at?: string | null;
};

// Shape submitted by the create/edit form (server stamps masjid_id + timestamps).
//
// NOTE what is absent: `login_email` and the other three `login_*` columns.
// They are not fillable server-side and are not editable here — a parent's
// credential is not a field on the member form. See the family-login types
// below and ContactFamilyLoginController.
export type ContactPayload = {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    notes: string;
};

/**
 * One act of opening or closing a parent's sign-in, as the API returns it.
 *
 * `actor_name` is a SNAPSHOT taken when the act happened, not a live lookup:
 * staff soft-delete, so reading the name back through the foreign key would
 * print nothing for exactly the person an audit is most often asked about.
 */
export type FamilyLoginEvent = {
    id: number;
    /**
     * Five verbs, not two. `merged` marks history CARRIED here from a record
     * this member absorbed — without it the carried rows name an address this
     * member never held and nothing explains why. `address_released` marks the
     * sign-in address being freed for another member, which is the only way a
     * `login_email` is ever cleared, and `address_claimed` is its other half —
     * written on the member who TOOK the address, because the released half sits
     * on a record that is routinely soft-deleted and therefore on no screen at
     * all. See ContactLoginEvent::ACTIONS; the column is a plain string
     * precisely so verbs can be added without a migration.
     */
    action: 'enabled' | 'revoked' | 'merged' | 'address_released' | 'address_claimed';
    login_email: string | null;
    actor_name: string;
    actor_email: string | null;
    created_at: string;
};

/**
 * A contact's parent-portal sign-in state.
 *
 * `state` is computed SERVER-side (FamilyAccessService::state) and never
 * reconstructed here from the timestamps: the portal's own liveness rule
 * already exists once, in Contact::familyLoginIsActive(), and a second copy in
 * TypeScript is a copy that agrees today and drifts tomorrow.
 */
export type FamilyLoginStatus = {
    contact_id: number;
    state: 'never_enabled' | 'enabled' | 'revoked';
    /**
     * MAY this member hold a parent sign-in at all — a separate question from
     * whether one is currently on. Computed by the same method the server
     * refuses `enable()` with (FamilyAccessService::ineligibilityReason), never
     * re-derived here: a screen that guesses eligibility either hides a member
     * who could be enabled or offers a button that answers 422 after the
     * operator has typed an address.
     *
     * `ineligible_reason` is the sentence the write would refuse with, verbatim,
     * so the screen and the server say the same thing. Non-null exactly when
     * `eligible` is false.
     */
    eligible: boolean;
    ineligible_reason: string | null;
    login_email: string | null;
    login_enabled_at: string | null;
    login_revoked_at: string | null;
    last_login_at: string | null;
    events: FamilyLoginEvent[];
};
