/**
 * The unread-messages badge: how the number reads, how it is kept true without a
 * reload, and how a long conversation is opened so that opening it really does
 * clear it.
 *
 * The server owns the number (App\Support\GroupThreadUnread). Nothing here counts
 * messages: it only formats what the server sent, subtracts a conversation the
 * screen has just opened (the next list is trusted over this guess), and reads a
 * conversation to its end.
 */

/** The biggest page of messages the server will serve (GroupThreadsController::MAX_MESSAGES_PER_PAGE). */
export const MESSAGE_PAGE_SIZE = 200;

/** A conversation longer than this many pages stops loading rather than looping forever. */
export const MAX_MESSAGE_PAGES = 25;

/** Wait at least this long between refreshes caused by the window regaining focus. */
export const FOCUS_REFRESH_GAP_MS = 15000;

/** A count from the wire, made safe: a missing, negative or fractional value is zero. */
export function unreadNumber(value: unknown): number {
    const n = Number(value);

    return Number.isFinite(n) && n > 0 ? Math.floor(n) : 0;
}

/** "7", and "99+" from a hundred up, so the pill never grows past its box. */
export function unreadPill(value: unknown): string {
    const n = unreadNumber(value);

    if (n === 0) return '';

    return n > 99 ? '99+' : String(n);
}

/** The words a screen reader gets for a pill, which the glyphs alone do not carry. */
export function unreadSpoken(value: unknown): string {
    const n = unreadNumber(value);

    if (n === 0) return '';

    return `${unreadPill(n)} unread message${n === 1 ? '' : 's'}`;
}

/** "3 new" beside a class name or on a conversation row; "" when there is nothing new. */
export function newChip(value: unknown): string {
    const pill = unreadPill(value);

    return pill === '' ? '' : `${pill} new`;
}

/**
 * The label on a conversation row. A server that predates `unread_count` still
 * sends the boolean, so that case keeps the plain word rather than showing nothing.
 */
export function threadNewLabel(thread: { unread_count?: unknown; unread?: unknown } | null | undefined): string {
    if (!thread) return '';

    if (thread.unread_count !== undefined && thread.unread_count !== null) return newChip(thread.unread_count);

    return thread.unread ? 'New' : '';
}

/**
 * The class number after the teacher opens a conversation: what it was, less what
 * that conversation held. A guess, kept until the next list says otherwise, and
 * never below zero.
 */
export function afterOpening(classTotal: unknown, openedThreadCount: unknown): number {
    return Math.max(0, unreadNumber(classTotal) - unreadNumber(openedThreadCount));
}

/** Should a regained window focus refresh the class number now? */
export function focusRefreshDue(lastRefreshAt: number | null, now: number, gapMs: number = FOCUS_REFRESH_GAP_MS): boolean {
    return lastRefreshAt === null || now - lastRefreshAt >= gapMs;
}

type Paginated<T> = { data?: T[]; current_page?: number; last_page?: number } | T[] | null | undefined;

const rowsOf = <T>(page: Paginated<T>): T[] => (Array.isArray(page) ? page : page?.data ?? []);

/**
 * Open a conversation to its end: the first page, then every following page, in
 * order, and return all of its messages with the thread as the LAST page reported
 * it. The server moves a reader's bookmark to the newest message it SERVED, so the
 * thread's count only reaches zero when the last page has been fetched, and the
 * reader can only be said to have read what was put in front of them.
 *
 * `fetchPage(page)` returns the payload's `data`: `{ thread, messages }`.
 */
export async function openWholeThread<T, Th>(
    fetchPage: (page: number) => Promise<{ thread?: Th; messages?: Paginated<T> } | null | undefined>,
    maxPages: number = MAX_MESSAGE_PAGES,
): Promise<{ thread: Th | undefined; messages: T[]; pages: number; truncated: boolean }> {
    const messages: T[] = [];
    let thread: Th | undefined;
    let page = 1;
    let lastPage = 1;

    while (page <= lastPage && page <= maxPages) {
        const payload = await fetchPage(page);

        messages.push(...rowsOf(payload?.messages));
        thread = payload?.thread ?? thread;

        const meta = payload?.messages;
        const reported = !Array.isArray(meta) && meta ? Number(meta.last_page) : 1;
        lastPage = Number.isFinite(reported) && reported >= 1 ? reported : 1;

        page += 1;
    }

    return { thread, messages, pages: page - 1, truncated: lastPage > maxPages };
}
