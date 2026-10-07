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
 * WHEN THE ROW SAYS WHEN IT WAS TYPED (`created_at`, which the teacher's and the
 * office's payloads carry and the family's does not): a recitation recorded as
 * it was heard is stamped with the same second it was created in, so an instant
 * within two seconds of its `created_at` is a real moment even if it happens to
 * be exactly midnight or noon UTC (8 pm or 8 am in New York). Without
 * `created_at` that one-in-43,200 recording would read as the UTC day, which in
 * the Americas is the day after for the midnight one.
 */

const pad = (n: number): string => String(n).padStart(2, '0');

const parse = (iso: string | null | undefined): Date | null => {
    if (!iso) return null;
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? null : d;
};

const isDateOnly = (d: Date): boolean =>
    d.getUTCMinutes() === 0 && d.getUTCSeconds() === 0 && d.getUTCMilliseconds() === 0
    && (d.getUTCHours() === 0 || d.getUTCHours() === 12);

const utcDay = (d: Date): string => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
const localDayOf = (d: Date): string => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

/**
 * `YYYY-MM-DD`, or '' when the instant is missing or unreadable.
 *
 * @param createdIso  the row's `created_at`, when the payload has it
 */
export function hifzDayOf(iso: string | null | undefined, createdIso?: string | null): string {
    const d = parse(iso);
    if (!d) return '';
    const created = parse(createdIso);
    const heardAsTyped = created !== null && Math.abs(d.getTime() - created.getTime()) <= 2000;
    return !heardAsTyped && isDateOnly(d) ? utcDay(d) : localDayOf(d);
}

/** The day as the lists write it ("Oct 5, 2026"), or '' when there is none. */
export function hifzDayLabel(iso: string | null | undefined, locale?: string, createdIso?: string | null): string {
    const day = hifzDayOf(iso, createdIso);
    if (!day) return '';
    const [y, m, d] = day.split('-').map(Number);
    // Built from the three numbers in the reader's zone and formatted there, so
    // the label is that calendar day and no zone can move it.
    return new Date(y, m - 1, d).toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' });
}

/**
 * What to send as `recited_at` for a day chosen in the date box. The server
 * refuses an instant in the future, so the first of these that is not after
 * `now`:
 *
 *  1. noon UTC of that day: the same calendar day for every reader from
 *     Honolulu to Auckland, including readers that do not use this file;
 *  2. midnight UTC of that day (the bare date this form used to send): east of
 *     Greenwich, early on a day, yesterday's noon UTC has not happened yet;
 *  3. ONLY when the chosen day is the reader's own today: a second ago. East of
 *     Greenwich, before UTC has reached that day, neither of the above has
 *     happened yet; a second ago has, and as a real moment it reads as the
 *     reader's local day, which is that day.
 *
 * A day that is really in the future gets (1), unchanged, and the server says
 * so: this never swaps a day somebody typed for another one.
 */
export function hifzDayToSend(day: string, now: Date = new Date()): string {
    const noon = `${day}T12:00:00Z`;
    if (new Date(noon).getTime() <= now.getTime()) return noon;
    if (new Date(`${day}T00:00:00Z`).getTime() <= now.getTime()) return day;
    if (day === localDayOf(now)) {
        const [y, m, d] = day.split('-').map(Number);
        const startOfDay = new Date(y, m - 1, d, 0, 0, 0, 0).getTime();
        // A second ago, to the second: a real moment of the reader's today that
        // has happened. Never earlier than the start of that day, and never one
        // of the two instants that MEAN "a date" (hifzDayOf): twelve hours east
        // of Greenwich the start of the day is exactly noon UTC, and sent as it
        // is it read back as the day before.
        let moment = Math.max(Math.floor(now.getTime() / 1000) * 1000 - 1000, startOfDay);
        if (isDateOnly(new Date(moment))) moment += 1000;
        return new Date(moment).toISOString();
    }
    return noon;
}
