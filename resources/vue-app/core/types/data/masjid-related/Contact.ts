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
    action: 'enabled' | 'revoked';
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
    login_email: string | null;
    login_enabled_at: string | null;
    login_revoked_at: string | null;
    last_login_at: string | null;
    events: FamilyLoginEvent[];
};
