/**
 * What the member record says about a suppressed email address, as plain
 * functions so they can be pinned without a browser
 * (resources/vue-app/tests/email-opt-out.test.ts). DISPLAY only: whether a
 * broadcast emails the person is decided by the server against
 * `email_suppressions`, never by this.
 *
 * The reason matters to staff because they act on it differently. An
 * unsubscribe, a complaint or an opt-out carried from the old website is the
 * person's own request and only the person can undo it. "Not opted in" is an
 * import's precaution (the old website never had their consent), and staff can
 * lift it once the person consents in Manara — which is what `canRecordConsent`
 * offers. A bounce says the address did not work there.
 */

export type EmailOptOutBadge = {
    /** The badge text, before the date. */
    label: string;
    /** Staff may record consent given in Manara, lifting the precaution. */
    canRecordConsent: boolean;
};

/**
 * The badge for a contact, or null when the address is mailable. `reason` is
 * the server's `email_opt_out_reason`; a record loaded from the directory list
 * carries no reason yet, and reads as the stricter "unsubscribed" until the
 * full record arrives.
 */
export function emailOptOutBadge(optedOutAt: string | null | undefined, reason: string | null | undefined): EmailOptOutBadge | null {
    if (!optedOutAt) return null;

    switch (reason) {
        case 'not_opted_in':
            return { label: 'Emails: not opted in (imported)', canRecordConsent: true };
        case 'bounce':
            return { label: 'Emails: address bounced', canRecordConsent: false };
        default:
            return { label: 'Emails: unsubscribed', canRecordConsent: false };
    }
}

/** The consent POST body, form-encoded as ApiService posts (.claude/rules/shipping.md). */
export function emailConsentBody(evidence: string): URLSearchParams {
    const body = new URLSearchParams();
    body.append('evidence', evidence.trim());
    return body;
}
