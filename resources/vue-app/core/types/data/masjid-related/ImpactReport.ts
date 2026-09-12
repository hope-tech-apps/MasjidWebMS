/**
 * The impact report — the figures a grant application or a funder report asks
 * for, computed from the rows this organization already holds.
 *
 * Mirrors `App\Support\ImpactMetrics` (the constants at ImpactMetrics.php:94-142)
 * and the response `ImpactMetricsController::report()` returns. Nothing is
 * derived here: the definitions, the money rules and the vertical-aware
 * selection live once in PHP, and a second implementation in TypeScript would
 * give a funder two answers to the same question.
 *
 * Three things about this payload are load-bearing, and a screen that gets any
 * of them wrong files a wrong number under an organization's letterhead:
 *
 *  1. `value` for a `money_minor` metric is an INTEGER IN MINOR UNITS. Print
 *     `formatted`, which is the only place the server divides it. Dividing
 *     `value` by 100 in the view is how the report and the giving dashboard
 *     start disagreeing (.claude/rules/impact-metrics.md, "Money").
 *  2. `basis` is not decoration. A `period` figure is a FLOW inside the window;
 *     an `as_of` figure is a STOCK at one date; a `current` figure describes
 *     TODAY even when the reader asked about 2019, because the platform keeps
 *     no history of the flag behind it. Rendering all three under one date
 *     header is the single easiest way to make this report wrong.
 *  3. `provenance.definition` is the deliverable, not the number. Every figure
 *     must travel with what exactly it counted — including what the data
 *     cannot support ("scheduled" is booked, not attended). A figure printed
 *     without its definition is worth less than no figure.
 */

/**
 * Every machine key `ImpactMetrics` can emit, in catalogue order.
 *
 * APPEND-ONLY, exactly as the PHP constants are: a filed report refers back to
 * these, so renaming one breaks a document an organization has already sent to
 * a funder. Keep in step with ImpactMetrics.php:94-110.
 */
export type ImpactMetricKey =
    | 'appointment_requests_received'
    | 'appointment_requests_scheduled'
    | 'appointment_requests_closed'
    | 'credentialed_volunteers'
    | 'credentials_valid'
    | 'credentials_expired'
    | 'donations_total'
    | 'donors_identified'
    | 'donations_count'
    | 'form_submissions'
    | 'form_submission_people'
    | 'active_groups'
    | 'group_participants'
    | 'active_offerings'
    | 'registrations_confirmed'
    | 'registration_participants'
    | 'program_fees_collected';

/**
 * How a figure relates to time (ImpactMetrics.php:126-128). The screen groups
 * the cards by this and states the difference in words, because "412" under a
 * date range the figure does not actually cover is a false statement.
 */
export type ImpactMetricBasis = 'period' | 'as_of' | 'current';

/** `money_minor` is an integer in the currency's minor units (ImpactMetrics.php:131-132). */
export type ImpactUnit = 'count' | 'money_minor';

/**
 * Why a computed figure is NOT in the response (ImpactMetrics.php:141-142).
 * Reported rather than dropped so a reader can tell "we did not ask" from
 * "the answer was zero" — the screen has to say both out loud.
 */
export type ImpactOmissionReason =
    | 'not_in_default_set_and_no_data'
    | 'requires_view_donations_permission';

/**
 * The window a metric ACTUALLY covers, which is not always the window that was
 * asked for: a stock figure describes one instant, and `as_of` is clamped to
 * today by the server so a credential expiring in October is not reported as
 * lapsed. Nulls mean unbounded on that side.
 */
export type ImpactMetricPeriod = {
    from: string | null;
    to: string | null;
    as_of: string | null;
    timezone: string;
};

/** Where the figure came from and what exactly it counted. Print both. */
export type ImpactMetricProvenance = {
    /** The tables behind it, e.g. "group_memberships, groups, contacts". */
    source: string;
    /** The sentence a grant footnote is written from. Never paraphrase it. */
    definition: string;
};

export type ImpactMetric = {
    key: ImpactMetricKey | string;
    /** Already in the tenant's own vocabulary — "Active Halaqat" / "Active Classrooms". */
    label: string;
    /** Minor units for `money_minor`. Never divide this in the view. */
    value: number;
    unit: ImpactUnit;
    /** Non-null only for money — a count has no currency. */
    currency: string | null;
    /** The one string that is safe to print, and the only one a funder should read. */
    formatted: string;
    basis: ImpactMetricBasis;
    period: ImpactMetricPeriod;
    provenance: ImpactMetricProvenance;
};

/** `{key, reason}` and nothing else — the server sends NO label here. */
export type ImpactOmission = {
    key: ImpactMetricKey | string;
    reason: ImpactOmissionReason | string;
};

export type ImpactReportMeta = {
    org_type: string;
    timezone: string;
    currency: string;
    /** Null bounds mean all time; the server says so rather than defaulting to a hidden window. */
    period: {
        from: string | null;
        to: string | null;
        as_of: string | null;
    } | null;
    generated_at: string;
    omitted: ImpactOmission[];
};

/** Both bounds optional; `to` must not precede `from` or the server 422s. */
export type ImpactReportFilters = {
    from: string;
    to: string;
};

/**
 * English labels for the omitted keys.
 *
 * `meta.omitted` carries `{key, reason}` with NO label (ImpactMetricsController.php:59),
 * so the "not included" panel has to supply one. This mirrors the PHP catalogue
 * at ImpactMetrics.php:94-110 label for label; the three vocabulary-bearing
 * labels take the tenant's own term, the way the server's own labels do, so a
 * school reads "Active Classrooms" in both halves of the page.
 *
 * Machine keys are append-only, so a key this map does not know can only mean a
 * newer server — `impactMetricLabel()` humanises it rather than printing a
 * blank line under "Not included in this report", which would read as a bug.
 */
export function impactMetricLabels(
    groupsTerm: string,
    programsTerm: string
): Record<string, string> {
    return {
        appointment_requests_received: 'Appointment requests received',
        appointment_requests_scheduled: 'Appointment requests scheduled',
        appointment_requests_closed: 'Appointment requests closed',
        credentialed_volunteers: 'Credentialed volunteers',
        credentials_valid: 'Valid credentials on file',
        credentials_expired: 'Expired credentials on file',
        donations_total: 'Donations received',
        donors_identified: 'Identified donors',
        donations_count: 'Gifts received',
        form_submissions: 'Form submissions',
        form_submission_people: 'People represented by form submissions',
        active_groups: `Active ${groupsTerm}`,
        group_participants: `People in active ${groupsTerm}`,
        active_offerings: `Active ${programsTerm}`,
        registrations_confirmed: 'Confirmed registrations',
        registration_participants: 'People enrolled through registration',
        program_fees_collected: 'Program fees collected',
    };
}

/** A label for one key, falling back to the humanised key rather than blank. */
export function impactMetricLabel(
    key: string,
    labels: Record<string, string>
): string {
    if (labels[key]) return labels[key];

    const words = key.replace(/_/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}
