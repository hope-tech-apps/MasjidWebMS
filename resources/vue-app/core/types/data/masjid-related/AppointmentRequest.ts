/**
 * Appointment requests — the Community vertical's intake queue (T-021).
 *
 * A visitor submits the public appointment form; staff work the queue here. The
 * pilot is a free clinic, so these rows describe a person's HEALTH CONTACT and
 * the shapes below encode where each field is allowed to appear:
 *
 *   - `AppointmentRequestQueueRow` is what the LIST endpoint serves. It has no
 *     `reason` and no `date_of_birth` because the controller never selects
 *     them: those columns are encrypted at rest and a queue renders fifteen
 *     people at a time on a front-desk screen.
 *   - `AppointmentRequest` is the one-person `show` payload, which does carry
 *     them, plus the notes thread.
 *
 * Consequences worth stating once: never send a `reason` or a note body to a
 * console, a log, an analytics call or a URL, and never assume the list row has
 * them. See .claude/rules/appointments.md.
 */

/**
 * Mirrors `AppointmentRequest::STATUSES` (PHP constants, not a DB enum).
 *
 * These are TRIAGE LABELS, not a state machine: any status may be set in any
 * order, on purpose — a closed request reopens the moment the patient calls
 * back. Nothing in the SPA may add ordering rules the server does not have.
 */
export type AppointmentRequestStatus = 'new' | 'contacted' | 'scheduled' | 'closed';

/**
 * The vocabulary as a literal fallback for a cold load. The SERVER's
 * `meta.statuses` is the authority; this only keeps the filter bar from being
 * blank before the first response lands (same pattern as the group `kinds`).
 */
export const APPOINTMENT_REQUEST_STATUSES: AppointmentRequestStatus[] = [
    'new',
    'contacted',
    'scheduled',
    'closed'
];

/** How the queue is ordered by submission time. Mirrors the `?sort=` the controller reads. */
export type AppointmentRequestSort = 'newest' | 'oldest';

/**
 * One row of the triage queue.
 *
 * `notes_count` rides along so an operator can see which requests have already
 * been worked without opening (and therefore disclosing) each one.
 */
export type AppointmentRequestQueueRow = {
    id: number;
    masjid_id: number;
    applicant_name: string;
    phone: string;
    email: string | null;
    /** Free text ("weekday mornings"), never a slot — scheduling is a later slice. */
    preferred_window: string | null;
    status: AppointmentRequestStatus;
    /** Where it came from; 'web' is the public form. */
    source: string;
    created_at: string;
    updated_at: string;
    notes_count?: number;
};

/** One internal staff note. `body` is encrypted at rest and decrypts only for this screen. */
export type AppointmentRequestNote = {
    id: number;
    masjid_id: number;
    appointment_request_id: number;
    user_id: number | null;
    body: string;
    created_at: string;
    updated_at: string;
    /** Null once the author's account is deleted — the note is the clinic's record, not theirs. */
    author: {
        id: number;
        name: string;
        email: string;
    } | null;
};

/**
 * One request in full, as the `show` endpoint serves it: the queue row plus the
 * two encrypted fields and the notes thread.
 */
export type AppointmentRequest = AppointmentRequestQueueRow & {
    /** Y-m-d. Encrypted at rest; shown only on this one screen. */
    date_of_birth: string;
    /** Why they are asking for an appointment. Encrypted at rest. */
    reason: string;
    ip_address?: string | null;
    user_agent?: string | null;
    notes?: AppointmentRequestNote[];
};

/**
 * `meta` on the appointment-request endpoints.
 *
 * `status_counts` describes the WHOLE queue rather than the filtered page — it
 * labels the filter tabs, so it must not move when a filter is applied. It is
 * served by the list endpoint only.
 */
export type AppointmentRequestsMeta = {
    statuses: AppointmentRequestStatus[];
    status_counts?: Partial<Record<AppointmentRequestStatus, number>>;
};

/**
 * The list endpoint's read filters. An empty `status` means "every status",
 * which is why it is not simply `AppointmentRequestStatus`.
 */
export type AppointmentRequestFilters = {
    status: AppointmentRequestStatus | '';
    search: string;
    sort: AppointmentRequestSort;
};
