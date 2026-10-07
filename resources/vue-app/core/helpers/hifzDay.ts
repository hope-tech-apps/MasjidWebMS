/**
 * The DAY a recitation was heard, read the same way on every screen.
 *
 * `recited_at` is an instant. A recitation recorded as it is heard carries the
 * real time. One entered for an earlier day carries only a DATE, and until
 * 2026-10-07 the form sent that bare date, which the server stores as midnight
 * UTC. Every screen then formatted the instant in the reader's own time zone,
 * so west of Greenwich a recitation dated "5 October" read "Oct 4": the teacher
 * picked one day and the list, the family and the office all showed the day
 * before. This file is the one reading:
 *
 *  - an instant at exactly 00:00:00 or 12:00:00 UTC is a DATE somebody chose
 *    (midnight is every row written before the fix; noon is what the form sends
 *    now), and its day is its UTC calendar day, wherever it is read;
 *  - any other instant is a real moment, and its day is the reader's local day.
 *
 * A recitation really heard at 00:00:00.000 or 12:00:00.000 UTC to the second
 * would be read as a date too. It is the same day in every time zone the app is
 * used in, so nothing shows wrong for it.
 */

const pad = (n: number): string => String(n).padStart(2, '0');

const parse = (iso: string | null | undefined): Date | null => {
    if (!iso) return null;
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? null : d;
};

const isChosenDate = (d: Date): boolean =>
    d.getUTCMinutes() === 0 && d.getUTCSeconds() === 0 && d.getUTCMilliseconds() === 0
    && (d.getUTCHours() === 0 || d.getUTCHours() === 12);

/** `YYYY-MM-DD`, or '' when the instant is missing or unreadable. */
export function hifzDayOf(iso: string | null | undefined): string {
    const d = parse(iso);
    if (!d) return '';
    return isChosenDate(d)
        ? `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`
        : `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** The day as the lists write it ("Oct 5, 2026"), or '' when there is none. */
export function hifzDayLabel(iso: string | null | undefined, locale?: string): string {
    const day = hifzDayOf(iso);
    if (!day) return '';
    const [y, m, d] = day.split('-').map(Number);
    // Built from the three numbers in the reader's zone and formatted there, so
    // the label is that calendar day and no zone can move it.
    return new Date(y, m - 1, d).toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' });
}

/**
 * What to send as `recited_at` for a day chosen in the date box: noon UTC of
 * that day. A bare date becomes midnight UTC on the server, which is the
 * evening before in the Americas; noon UTC is that same calendar day from
 * Honolulu to Auckland, for every reader, including ones that do not use this
 * file.
 */
export const hifzDayToSend = (day: string): string => `${day}T12:00:00Z`;
