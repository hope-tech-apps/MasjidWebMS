/**
 * The weekly view of a class's points (T-003.2), as plain functions with no Vue in
 * them so `npm run test:spa` covers them.
 *
 * The server owns where a week starts and ends (App\Support\PointsWeek, on the
 * school's clock) and sends its two ends as local calendar dates. Nothing here
 * works a week out from "now" in the browser: the browser's zone is the parent's,
 * not the school's, and a Saturday-night award would move to another week.
 */

export type PointsPeriod = 'running' | 'weekly';

/** What `week` carries on the totals, summary and listing payloads. */
export interface PointsWeekPayload {
    /** First local day, 'YYYY-MM-DD'. */
    start: string;
    /** Last local day, 'YYYY-MM-DD'. */
    end: string;
    previous: string;
    next: string;
    timezone?: string;
    is_current: boolean;
}

/** A class reads a week at a time only when it says so; anything else is the running total. */
export function isWeekly(period: string | null | undefined): boolean {
    return period === 'weekly';
}

/** "+3", "-1", "0" - the same text `awardPointsLabel` gives a single award. */
export function signedPoints(n: number | string | null | undefined): string {
    const value = Number(n ?? 0);

    return `${value > 0 ? '+' : ''}${value}`;
}

/** 'YYYY-MM-DD' as a UTC calendar day, or null when it is not one. */
function calendarDay(iso: string | null | undefined): Date | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso ?? ''));
    if (!m) return null;

    const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));

    return d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() === Number(m[2]) - 1 && d.getUTCDate() === Number(m[3])
        ? d
        : null;
}

/**
 * "Oct 4 - Oct 10" (or "Oct 4, 2026 - Jan 2, 2027" across a year), in the parent's
 * language. Formatted in UTC from the server's calendar dates, so the browser's own
 * zone can never shift a date by one.
 */
export function weekRangeLabel(start: string, end: string, locale = 'en'): string {
    const a = calendarDay(start);
    const b = calendarDay(end);

    if (!a || !b) return `${start} - ${end}`;

    const sameYear = a.getUTCFullYear() === b.getUTCFullYear();
    const short = new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric', timeZone: 'UTC' });
    const long = new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });

    return sameYear
        ? `${short.format(a)} - ${long.format(b)}`
        : `${long.format(a)} - ${long.format(b)}`;
}

/**
 * The figure a class's screen LEADS with. A weekly class leads with the week and
 * keeps the running history beside it; every other class leads with the running
 * total and shows the week as a second line. Both figures are always the server's:
 * nothing is summed in the browser (a total built from one page of awards is a
 * wrong number).
 */
export function pointsHeadline(
    period: string | null | undefined,
    row: { points?: number; awards?: number; week_points?: number; week_awards?: number } | null | undefined,
): { lead: 'week' | 'running'; points: number; awards: number; other: { points: number; awards: number } } {
    const running = { points: Number(row?.points ?? 0), awards: Number(row?.awards ?? 0) };
    const week = { points: Number(row?.week_points ?? 0), awards: Number(row?.week_awards ?? 0) };

    return isWeekly(period)
        ? { lead: 'week', ...week, other: running }
        : { lead: 'running', ...running, other: week };
}
