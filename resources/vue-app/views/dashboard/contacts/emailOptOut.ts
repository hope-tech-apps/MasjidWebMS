/**
 * What the member record says about a suppressed email address, as plain
 * functions so they can be pinned without a browser
 * (resources/vue-app/tests/email-opt-out.test.ts). DISPLAY only: whether a
 * broadcast emails the person is decided by the server against
 * `email_suppressions`, never by this.
 *
 * The reason matters to staff because they act on it differently. An
 * unsubscribe, a complaint or an opt-out carried from the old website is the
 * person's own request and only the person can undo it. "Not opted in" (the
 * contact import) and "held" (the order-history import, for a buyer it had to
 * create) are an import's precautions: no consent to email is on record in
 * Manara, nobody asked to be left alone, and staff can lift either once the
 * person consents in Manara — which is what `canRecordConsent` offers, and
 * what the server's EmailSuppression::STAFF_LIFTABLE_REASONS allows. They are
 * not the same claim, though: the contact import writes "not opted in" after
 * reading that the old website had no consent, while the order-history import
 * holds every buyer it creates without looking at the old website's email
 * status at all, so a held buyer may well have been subscribed there. Calling
 * a hold "unsubscribed" would be untrue, and would hide the one action that
 * applies. A bounce says the address did not work there.
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
        case 'order_history_import':
            return { label: 'Emails: held (imported order, no consent on record)', canRecordConsent: true };
        case 'bounce':
            return { label: 'Emails: address bounced', canRecordConsent: false };
        default:
            return { label: 'Emails: unsubscribed', canRecordConsent: false };
    }
}

/**
 * What the "Record consent to email" dialog tells staff about the hold they
 * are lifting, by the server's reason. Each says only what that import knew:
 * the order-history import never read the old website's consent, so its
 * dialog must not claim the old website had none.
 */
export function emailConsentPrompt(reason: string | null | undefined): string {
    const ask = 'Record how they have agreed to receive this organization\'s emails.';

    if (reason === 'order_history_import') {
        return 'This address came in with an imported order, and no consent to email is on record in Manara, so it is held. ' + ask;
    }

    return 'The old website had no consent from this person to email them, so the import held their email back. ' + ask;
}

/** The consent POST body, form-encoded as ApiService posts (.claude/rules/shipping.md). */
export function emailConsentBody(evidence: string): URLSearchParams {
    const body = new URLSearchParams();
    body.append('evidence', evidence.trim());
    return body;
}
