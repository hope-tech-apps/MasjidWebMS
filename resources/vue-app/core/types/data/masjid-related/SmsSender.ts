// This organisation's own text-message sender identity and the outcome of its
// A2P 10DLC carrier registration (T-009, T-041f).
//
// Mirrors App\Models\MasjidSmsSender and
// App\Http\Controllers\AdminDashboard\MasjidSmsSenderController. Both endpoints
// sit behind `super` (routes/admin.php, `{masjid_id}/sms-sender`), because
// registration is a commercial act performed by the platform on the
// organisation's behalf and a masjid admin who could declare their own sender
// "approved" would be putting unregistered traffic on the carriers in the
// platform's name. .claude/rules/broadcasts.md names that as the failure.

/**
 * The five registration outcomes, mirroring MasjidSmsSender::STATUSES.
 *
 * A PHP constant list, never a DB enum, and never a free string here either —
 * the server validates against exactly this set (UpdateSmsSenderRequest), so a
 * sixth value invented in the browser is a guaranteed 422.
 *
 * `pending` deliberately cannot send. "The paperwork is submitted" is not
 * carrier permission, and the gap between the two is measured in days.
 */
export type SmsSenderStatus =
    | 'unregistered'
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'suspended';

/**
 * The select's options, in the order an operator meets them, with the words
 * that say what each one MEANS for sending rather than just naming it. A bare
 * "Pending" in a dropdown reads like a step on the way to working; "Submitted,
 * no carrier verdict yet — cannot send" is the fact.
 */
export const SMS_SENDER_STATUS_OPTIONS: { value: SmsSenderStatus; label: string }[] = [
    { value: 'unregistered', label: 'Unregistered — nothing submitted to the carriers' },
    { value: 'pending', label: 'Pending — submitted, no carrier verdict yet (cannot send)' },
    { value: 'approved', label: 'Approved — carriers approved this brand and campaign' },
    { value: 'rejected', label: 'Rejected — the carriers refused the brand or campaign' },
    { value: 'suspended', label: 'Suspended — previously approved, then stopped' },
];

/** Short labels for the state badge, where there is no room for the sentence. */
export const SMS_SENDER_STATUS_LABELS: Record<SmsSenderStatus, string> = {
    unregistered: 'Unregistered',
    pending: 'Pending',
    approved: 'Approved',
    rejected: 'Rejected',
    suspended: 'Suspended',
};

/** The stored row. Null on the panel until an operator first records one. */
export type MasjidSmsSender = {
    id: number;
    masjid_id: number;
    /** Which provider account the number lives in ("twilio"). */
    provider: string | null;
    /**
     * E.164, normalised SERVER-side before validation. The inbound STOP webhook
     * resolves the tenant by matching its `To` against this column, so a number
     * stored as "(613) 555-0142" would never match and that organisation's
     * opt-outs would go silently unrecorded.
     */
    phone_number: string | null;
    messaging_service_sid: string | null;
    sender_label: string | null;
    registration_status: SmsSenderStatus;
    brand_registration_id: string | null;
    campaign_registration_id: string | null;
    /**
     * Stamped by the SERVER on the transition into `approved` and cleared on the
     * way out — never sent by this client. An "approved" sender always carries
     * its date and a rejected one never keeps a stale one.
     */
    approved_at: string | null;
    notes: string | null;
    created_at: string;
    updated_at: string;
};

/**
 * GET /api/admin/masjids/{masjid_id}/sms-sender → data
 *
 * `can_send` and `refusal_reason` are the SERVER's answers
 * (MasjidSmsSender::canSend / refusalReason), and the panel prints them rather
 * than re-deriving "approved and has a number" in TypeScript. The sending path
 * refuses with that exact sentence, so the screen and the delivery row say the
 * same thing — a second copy of the rule here would agree today and drift the
 * first time the rule gains a clause.
 */
export type SmsSenderPanel = {
    masjid_id: number;
    sender: MasjidSmsSender | null;
    can_send: boolean;
    /** Null exactly when `can_send` is true. */
    refusal_reason: string | null;
    /**
     * PLATFORM-level, not tenant-level: with no provider credentials on this
     * deployment nobody can send, however well registered they are. Absent from
     * the PUT response, which answers only about this tenant — hence optional.
     */
    provider_configured?: boolean;
    provider?: string;
};

/**
 * PUT body. `registration_status` is required; everything else is optional.
 *
 * NOTE what is absent: `approved_at`. It is server-stamped, and a client that
 * could set it could date a carrier approval that never happened.
 */
export type SmsSenderPayload = {
    provider?: string | null;
    phone_number?: string | null;
    messaging_service_sid?: string | null;
    sender_label?: string | null;
    registration_status: SmsSenderStatus;
    brand_registration_id?: string | null;
    campaign_registration_id?: string | null;
    notes?: string | null;
};
