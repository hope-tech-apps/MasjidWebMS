/**
 * The staff "Seen by 4 of 7 parents" line, for a story that predates recording.
 *
 * Read receipts are only kept from the day the school switched them on, so a
 * story older than that with no read on it has NOT been read by nobody: it has
 * not been tracked. The server says so (`seen_tracked: false`, and `seen_since`,
 * a school-local `Y-m-d`), and the screen must say "Not tracked before <date>"
 * rather than "Seen by 0 of 7", which reads as "no parent opened it".
 */
export function notTrackedLabel(since: string | null | undefined): string {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(since ?? '');

    if (!m) return 'Not tracked before recording began';

    // Built in UTC and printed in UTC, so the browser's own zone cannot move the
    // day the SCHOOL named.
    const day = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));

    return `Not tracked before ${day.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })}`;
}
