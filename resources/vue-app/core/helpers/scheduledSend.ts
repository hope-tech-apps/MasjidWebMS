/**
 * "Send later" on a class story or a new conversation (T-002.4).
 *
 * THE CLOCK IS THE SCHOOL'S, NOT THE BROWSER'S. A teacher travelling, or an office
 * administrator in another zone, means the school's ten o'clock. So the field holds a
 * wall-clock string (`2026-10-05T10:00`, exactly what `<input type="datetime-local">`
 * produces), the screen labels it with the school's zone name, and the SERVER reads it
 * in that zone. This module never converts an instant to the browser's zone or back:
 * everything here is formatted from the string's own parts, so the machine's zone
 * cannot move the day or the hour the school named.
 *
 * The bounds the field offers (`min`, `max`) are only a convenience: the server refuses
 * a time in the past or more than `max_days_ahead` (30) days out, and is the authority.
 */

/** A wall-clock time as `<input type="datetime-local">` writes it. */
export type SchoolLocal = string;

const SHAPE = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/;

function pad(n: number): string {
    return String(n).padStart(2, '0');
}

/**
 * What time it is at the school right now (plus `addMinutes`), as a wall-clock string in
 * the school's own zone. Falls back to the browser's zone only when the zone name is
 * unusable, which the server never sends but a stale bundle could meet.
 */
export function schoolNow(timezone: string | null | undefined, now: Date = new Date(), addMinutes = 0): SchoolLocal {
    const at = new Date(now.getTime() + addMinutes * 60_000);

    try {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone || undefined,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
        }).formatToParts(at);
        const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00';

        return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
    } catch {
        return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}T${pad(at.getHours())}:${pad(at.getMinutes())}`;
    }
}

/** The latest time the field offers: `days` days on from now at the school. */
export function schoolMax(timezone: string | null | undefined, days: number, now: Date = new Date()): SchoolLocal {
    return schoolNow(timezone, now, days * 24 * 60);
}

/**
 * Why this time cannot be scheduled, or null when it can. Compared as strings: both
 * sides are the school's wall clock in the same fixed-width format, so lexical order is
 * chronological order.
 */
export function sendAtError(
    value: SchoolLocal | null | undefined,
    timezone: string | null | undefined,
    maxDays: number,
    now: Date = new Date(),
): string | null {
    if (!value) return 'Choose the date and time to send it.';
    if (!SHAPE.test(value)) return 'That is not a date and time.';

    if (value <= schoolNow(timezone, now)) return 'Choose a time in the future, or send it now.';
    if (value > schoolMax(timezone, maxDays, now)) return `It can be scheduled at most ${maxDays} days ahead.`;

    return null;
}

/**
 * The request fields for a compose box: `{ send_at }` when "Send later" is on, and
 * nothing at all when it is off, so an ordinary post is byte-identical to what it was
 * before scheduling existed.
 */
export function sendLaterFields(enabled: boolean, value: SchoolLocal | null | undefined): { send_at?: string } {
    return enabled && value ? { send_at: value } : {};
}

/**
 * A school-local time in words, from the string's own parts (the browser zone plays no
 * part), with the zone named: "Mon, Oct 5, 10:00 AM (America/New_York)".
 */
export function describeSchoolTime(value: SchoolLocal | null | undefined, timezone?: string | null): string {
    const m = SHAPE.exec(value ?? '');

    if (!m) return '';

    const at = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5])));
    const text = at.toLocaleString('en-US', {
        weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'UTC',
    });

    return timezone ? `${text} (${timezone})` : text;
}

/** One row of the Scheduled list, whichever kind of item it is. */
export type ScheduledRow = {
    id: number;
    kind: 'story' | 'message';
    /** A story's title, or a conversation's subject. May be empty for a story. */
    heading: string;
    body: string;
    /** The school-local time it goes out. */
    whenLocal: string;
    status: 'scheduled' | 'sending' | 'failed';
    /** Why it did not go, for a failed one. */
    failure: string | null;
    /** Whether THIS viewer may edit, send now or cancel it (the author and the office). */
    canChange: boolean;
    /** A conversation about one child: who. Null for a whole-class one and for a story. */
    about: string | null;
    author: string | null;
};

const personName = (c: { first_name?: string | null; last_name?: string | null } | null | undefined): string | null => {
    const name = `${c?.first_name ?? ''} ${c?.last_name ?? ''}`.trim();

    return name || null;
};

/** A story from the server's `?scheduled=1` list, as a row. */
export function storyRow(post: any): ScheduledRow {
    return {
        id: Number(post.id),
        kind: 'story',
        heading: post.title ?? '',
        body: post.body ?? '',
        whenLocal: post.published_at_local ?? '',
        status: post.status === 'failed' ? 'failed' : 'scheduled',
        failure: post.publish_failure ?? null,
        canChange: post.can_change_schedule !== false,
        about: null,
        author: post.author?.name ?? null,
    };
}

/** A scheduled conversation from `scheduled-messages`, as a row. */
export function messageRow(item: any): ScheduledRow {
    const status = item.status === 'failed' ? 'failed' : item.status === 'sending' ? 'sending' : 'scheduled';

    return {
        id: Number(item.id),
        kind: 'message',
        heading: item.subject ?? '',
        body: item.body ?? '',
        whenLocal: item.send_at_local ?? '',
        status,
        failure: item.failure_reason ?? null,
        canChange: item.can_change === true,
        about: personName(item.about?.contact),
        author: item.author?.name ?? null,
    };
}

/** The sentence under a failed row. Never blank: a failure with no reason helps nobody. */
export function failureText(row: Pick<ScheduledRow, 'status' | 'failure'>): string {
    if (row.status !== 'failed') return '';

    return row.failure || 'It could not be sent. Edit it to choose a new time and try again.';
}
