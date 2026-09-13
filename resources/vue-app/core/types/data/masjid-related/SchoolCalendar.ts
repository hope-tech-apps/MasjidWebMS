/**
 * The school calendar — what a school year is, and the reads built on it.
 *
 * Mirrors app/Support/SchoolCalendarPayload.php. A year is a date range; the
 * weekday classes meet on is the weekday of its FIRST day. The server derives
 * `meeting_weekday` and `meeting_days` from the dates and never stores them, so
 * the two cannot disagree. Closures are dated exceptions, each with a reason.
 *
 * Every date here is a calendar day in the SCHOOL's time zone, as 'Y-m-d'.
 * They are formatted with `timeZone: 'UTC'` against a UTC-midnight Date on
 * purpose: `new Date('2026-10-11')` IS UTC midnight, which a browser west of
 * Greenwich prints as Saturday the 10th — the "renders a day early" trap the
 * guardian consent dates already fell into. Compare them as strings; 'Y-m-d'
 * sorts correctly as text.
 */

export type SchoolClosure = {
    id: number;
    closed_on: string;
    reason: string;
};

export type SchoolYear = {
    id: number;
    label: string;
    first_day: string;
    last_day: string;
    /** 0 = Sunday … 6 = Saturday — the weekday of first_day. */
    meeting_weekday: number;
    /** Every meeting day from first_day to last_day, closed ones included. */
    meeting_days: string[];
    closures: SchoolClosure[];
};

/** GET /api/admin/masjids/{masjid_id}/school-calendar — and what every write under it answers. */
export type SchoolCalendarPayload = {
    timezone: string;
    /** Today in the school's time zone, not the browser's. */
    today: string;
    years: SchoolYear[];
};

export type SchoolCalendarUpcomingDay = {
    date: string;
    closed: boolean;
    reason: string | null;
};

/** The teacher and family reads: the same years, plus the next meeting days. */
export type SchoolCalendarReadPayload = SchoolCalendarPayload & {
    upcoming: SchoolCalendarUpcomingDay[];
};

/** `data.school_day` on the teacher attendance GET. */
export type SchoolDayStatus = {
    has_calendar: boolean;
    in_year: boolean;
    meeting_day: boolean;
    closed: boolean;
    reason: string | null;
};

export type SchoolYearPayload = {
    label: string;
    first_day: string;
    last_day: string;
};

export type SchoolClosureCreatePayload = {
    school_year_id: number;
    closed_on: string;
    reason: string;
};

export type SchoolClosureUpdatePayload = {
    reason: string;
};

/** One meeting day as a screen lists it. */
export type SchoolCalendarDay = {
    date: string;
    closed: boolean;
    reason: string | null;
    closureId: number | null;
};

export type SchoolCalendarMonth = {
    /** 'YYYY-MM' */
    key: string;
    firstDate: string;
    days: SchoolCalendarDay[];
};

const ISO_DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

/** The first ten characters, so a stray 'T00:00:00.000000Z' cannot move a day. */
const isoDay = (value: unknown): string => String(value ?? '').slice(0, 10);

/** The browser's own calendar day — only a fallback for a payload with no `today`. */
export function localIsoDay(d: Date = new Date()): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function isoDayToUtcDate(iso: string): Date | null {
    const match = ISO_DAY.exec(iso ?? '');
    if (!match) return null;
    const d = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    return Number.isNaN(d.getTime()) ? null : d;
}

/** A 'Y-m-d' day in words, in `locale`. Falls back to the raw text rather than inventing a date. */
export function formatSchoolDay(
    iso: string,
    locale: string,
    options: Intl.DateTimeFormatOptions = { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' },
): string {
    const d = isoDayToUtcDate(iso);
    if (!d) return iso;
    try {
        return d.toLocaleDateString(locale, { ...options, timeZone: 'UTC' });
    } catch {
        return iso;
    }
}

/** 0 = Sunday … 6 = Saturday, or null for anything that is not a 'Y-m-d' day. */
export function weekdayOfIso(iso: string): number | null {
    const d = isoDayToUtcDate(iso);
    return d ? d.getUTCDay() : null;
}

/** The weekday's name in `locale`. 2026-01-04 was a Sunday, so the 4th + n is weekday n. */
export function weekdayName(weekday: number, locale: string): string {
    const n = ((Math.trunc(weekday) % 7) + 7) % 7;
    return new Date(Date.UTC(2026, 0, 4 + n)).toLocaleDateString(locale, { weekday: 'long', timeZone: 'UTC' });
}

function readYear(raw: any): SchoolYear {
    const firstDay = isoDay(raw?.first_day);
    const weekday = Number(raw?.meeting_weekday);

    return {
        id: Number(raw?.id),
        label: String(raw?.label ?? ''),
        first_day: firstDay,
        last_day: isoDay(raw?.last_day),
        meeting_weekday: Number.isInteger(weekday) && weekday >= 0 && weekday <= 6
            ? weekday
            : (weekdayOfIso(firstDay) ?? 0),
        meeting_days: Array.isArray(raw?.meeting_days) ? raw.meeting_days.map(isoDay) : [],
        closures: Array.isArray(raw?.closures)
            ? raw.closures.map((c: any) => ({
                id: Number(c?.id),
                closed_on: isoDay(c?.closed_on),
                reason: String(c?.reason ?? ''),
            }))
            : [],
    };
}

/**
 * The calendar out of a response's `data`, or NULL when it is not a calendar.
 *
 * Null is not "no calendar": a published-but-empty calendar is `years: []`. A
 * caller that gets null must show an error, never the quiet "not published yet"
 * message — a failed or malformed read presented as an empty calendar tells a
 * teacher there is school on a day there is not.
 */
export function readSchoolCalendar(data: any): SchoolCalendarPayload | null {
    if (!data || typeof data !== 'object' || !Array.isArray(data.years)) return null;

    const years = data.years.map(readYear)
        .sort((a: SchoolYear, b: SchoolYear) => (a.first_day < b.first_day ? -1 : a.first_day > b.first_day ? 1 : 0));

    return {
        timezone: String(data.timezone ?? ''),
        today: ISO_DAY.test(isoDay(data.today)) ? isoDay(data.today) : localIsoDay(),
        years,
    };
}

/** {@see readSchoolCalendar}, for the teacher and family reads that also carry `upcoming`. */
export function readSchoolCalendarRead(data: any): SchoolCalendarReadPayload | null {
    const base = readSchoolCalendar(data);
    if (!base) return null;

    return {
        ...base,
        upcoming: Array.isArray(data.upcoming)
            ? data.upcoming.map((d: any) => ({
                date: isoDay(d?.date),
                closed: !!d?.closed,
                reason: d?.reason ? String(d.reason) : null,
            }))
            : [],
    };
}

/**
 * A year's meeting days with their closures folded in, in date order.
 *
 * A closure the server lists that is NOT on a meeting day is still shown. The
 * server refuses to create one, so it should never happen — but hiding a
 * closure because it is somewhere unexpected is how a no-school day goes
 * unnoticed.
 */
export function daysOfYear(year: SchoolYear): SchoolCalendarDay[] {
    const closures = new Map<string, SchoolClosure>();
    for (const closure of year.closures) closures.set(closure.closed_on, closure);

    const dates = new Set<string>([...year.meeting_days, ...closures.keys()]);

    return [...dates].sort().map((date) => {
        const closure = closures.get(date) ?? null;
        return {
            date,
            closed: closure !== null,
            reason: closure?.reason ?? null,
            closureId: closure?.id ?? null,
        };
    });
}

/** Days grouped by calendar month, in order. */
export function monthsOf(days: SchoolCalendarDay[]): SchoolCalendarMonth[] {
    const months: SchoolCalendarMonth[] = [];

    for (const day of days) {
        const key = day.date.slice(0, 7);
        const last = months[months.length - 1];
        if (last && last.key === key) {
            last.days.push(day);
        } else {
            months.push({ key, firstDate: day.date, days: [day] });
        }
    }

    return months;
}

/**
 * The year a screen opens on: the one today falls in, else the next one to
 * start, else the most recent. `years` must be sorted by first_day.
 */
export function defaultYear(years: SchoolYear[], today: string): SchoolYear | null {
    if (!years.length) return null;

    return years.find((y) => y.first_day <= today && today <= y.last_day)
        ?? years.find((y) => y.first_day > today)
        ?? years[years.length - 1];
}
