/**
 * "Last opened this organisation": the per-organisation replacement for the old global
 * "Last sign-in" on Team & Access and the Teachers list.
 *
 * A person's last sign-in is one fact about them at ANY school (a token is minted
 * wherever they log in), so a school that shares a teacher with another could not
 * honestly show it. The server now sends `last_seen_at`: when that person last
 * OPENED THIS organisation, from this organisation's own membership row, and null
 * when no request has opened it since the column shipped. Null is NOT "never signed
 * in", and the wording says so.
 *
 * No imports: this runs under `npm run test:spa`.
 */
/** Name the organisation using its terminology pack's organization term. */
export function lastOpenedLabel(organization: string): string {
    return `Last opened this ${organization.toLowerCase()}`;
}

export const NOT_OPENED_TEXT = 'Not opened yet';

export const NOT_OPENED_HINT = 'Nobody has opened this organisation as this person since Manara started recording it.';

/** "Sep 29, 2026, 2:05 PM" in the viewer's locale and zone; '' for null or anything that is not a date. */
export function formatLastOpened(iso: string | null | undefined, locale?: string, timeZone?: string): string {
    if (!iso) return '';

    const d = new Date(iso);

    return Number.isNaN(d.getTime())
        ? ''
        : d.toLocaleString(locale, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone });
}
