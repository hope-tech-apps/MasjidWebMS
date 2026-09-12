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

    /**
     * TEXT-MESSAGE CONSENT (T-009). Five columns, not one boolean, and the
     * server serialises all five (`Contact::$hidden` is `['password']` only).
     *
     * The flag alone is not consent and is never read alone — see
     * `smsConsentState` below for the rule and for why it is written once.
     * `sms_consent_source` and `sms_consent_evidence` are the provenance: the
     * constant makes consent queryable, the free text makes it provable.
     *
     * Optional keys, because the directory listing and the show endpoint both
     * answer with the model's own `toArray()` and an older cached payload may
     * predate the columns — a panel that read `undefined` as "consented" would
     * be the worst possible failure of this screen, so the state helper treats
     * anything short of the full four-part rule as no consent.
     *
     * THEY ARE TIED TO `phone` AND DIE WITH IT. Saving a different number on a
     * contact clears all four server-side (`Contact::booted()`), because consent
     * was given for a number and the new one has given none. Any screen that
     * edits `phone` and then keeps rendering a cached copy of this row is
     * showing a consent record the server has already retracted — re-read the
     * contact after an edit rather than patching the fields you sent.
     */
    sms_opt_in?: boolean | null;
    /** Server time, stamped by SmsConsentService::grant. Never client-set. */
    sms_consent_at?: string | null;
    sms_consent_source?: SmsConsentSource | null;
    sms_consent_evidence?: string | null;
    /**
     * Set by a recorded withdrawal or an inbound STOP. It overrides everything
     * above, and the durable `sms_suppressions` row it is written beside
     * outlives this record entirely — a merge, a re-import, a delete-and-re-add.
     */
    sms_opted_out_at?: string | null;
};

/**
 * How consent was obtained. Mirrors `Contact::SMS_CONSENT_SOURCES` — a PHP
 * constant list, never a DB enum.
 */
export type SmsConsentSource =
    | 'web_form'
    | 'paper_form'
    | 'in_person'
    | 'phone_call'
    | 'sms_reply_start'
    | 'imported_with_proof';

/**
 * What an ADMIN may claim, mirroring
 * `StoreSmsConsentRequest::adminSelectableSources()`.
 *
 * `sms_reply_start` is absent, and its absence is the point: it means the
 * subscriber texted START from their own handset, which is a fact only the
 * inbound webhook can witness. Offering it in a dropdown would let a staff
 * member assert that a person sent a message they never sent — so the list is
 * built by SUBTRACTION from the full set here exactly as the server builds it,
 * rather than being retyped as five literals that a sixth source would silently
 * escape. The server rejects it with a 422 regardless; this keeps the screen
 * from ever asking.
 */
export const SMS_CONSENT_SOURCES: SmsConsentSource[] = [
    'web_form',
    'paper_form',
    'in_person',
    'phone_call',
    'sms_reply_start',
    'imported_with_proof',
];

/** Webhook-only sources — never selectable by a person. */
export const WEBHOOK_ONLY_SMS_CONSENT_SOURCES: SmsConsentSource[] = ['sms_reply_start'];

export const ADMIN_SELECTABLE_SMS_CONSENT_SOURCES: SmsConsentSource[] =
    SMS_CONSENT_SOURCES.filter(source => !WEBHOOK_ONLY_SMS_CONSENT_SOURCES.includes(source));

/**
 * The words a staff member reads, phrased as an answer to "how was consent
 * obtained?" rather than as a category name. `imported_with_proof` is spelled
 * out because "imported" on its own is what a spreadsheet full of numbers looks
 * like, and a spreadsheet is not consent.
 */
export const SMS_CONSENT_SOURCE_LABELS: Record<SmsConsentSource, string> = {
    web_form: 'Web form they submitted',
    paper_form: 'Paper form they signed',
    in_person: 'In person, asked and agreed',
    phone_call: 'On a phone call',
    sms_reply_start: 'They texted START (recorded automatically)',
    imported_with_proof: 'Imported from another system, with the proof retained',
};

/** The three states of a member's text-message consent. */
export type SmsConsentState = 'consented' | 'opted_out' | 'none';

/**
 * THE ONE PLACE this rule is written in TypeScript.
 *
 * It mirrors `App\Models\Contact::hasSmsConsent()` — opt-in AND a timestamp AND
 * a source AND no opt-out — plus the opt-out as its own state, because "never
 * asked" and "asked us to stop" are different facts about a person and
 * collapsing them into one "off" is how an opt-out gets quietly re-granted.
 *
 * It lives here, beside the type, rather than in the component, so there is a
 * single copy to correct if the legal definition gains a clause. It is still a
 * SECOND copy of a rule the server owns, and the better fix is for the contact
 * payload to carry the server's own answer (the way `FamilyLoginStatus.state`
 * does); until it does, this function is what a screen may read and the server
 * remains the thing that decides — `SmsConsentService::grant()` refuses a
 * suppressed number no matter what this returns.
 */
export const smsConsentState = (contact: Contact | null | undefined): SmsConsentState => {
    if (!contact) return 'none';
    if (contact.sms_opted_out_at) return 'opted_out';

    return contact.sms_opt_in === true
        && !!contact.sms_consent_at
        && !!contact.sms_consent_source
        ? 'consented'
        : 'none';
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
