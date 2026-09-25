/**
 * The board's decisions about a kitchen (catalogue) menu and its orders, as pure
 * functions so npm run test:spa can pin them (tests/kitchen-board.test.ts).
 *
 * Display answers only. The server decides and enforces everything here again:
 * the lead time (KitchenOrdersController), the confirmation (MealOrder::
 * recordOfficeConfirmation), how the money came (Mark paid). A board that showed
 * a button too many would be refused, never obeyed.
 */

/** MealMenu::KIND_CATALOGUE. A menu from a server before the kind existed has none and is a Friday lunch. */
export function isCatalogue(menu: { kind?: string | null } | null | undefined): boolean {
    return menu?.kind === 'catalogue';
}

/** What a menu card says instead of a date: a catalogue has none. */
export function catalogueSummary(menu: { pickup_lead_hours?: number | string | null } | null | undefined): string {
    const hours = Number(menu?.pickup_lead_hours);
    const lead = Number.isFinite(hours) && hours > 0 ? `${hours}h notice` : 'no notice needed';

    return `Standing catalogue · ${lead}`;
}

/**
 * A kitchen order the office has not confirmed yet: pending, whatever its money.
 * A paid kitchen order is still waiting for the office (MealOrder::markPaid does
 * not confirm it), which is the whole difference from a Friday order.
 */
export function awaitsConfirmation(order: { status?: string | null } | null | undefined, menu: { kind?: string | null } | null | undefined): boolean {
    return isCatalogue(menu) && order?.status === 'pending';
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * The pickup the server sent as the ORGANISATION'S wall clock ("2026-10-03T14:00",
 * `pickup_at_local`), worded without passing through the browser's timezone.
 *
 * Parsing it with `new Date()` would read it in whatever zone the admin's laptop
 * is in and could move the time the kitchen cooks for; the digits are taken as
 * they are instead. The weekday is computed from the calendar date alone, in UTC,
 * where no daylight-saving shift can move it. Anything unreadable is shown as sent.
 */
export function pickupWords(local: string | null | undefined): string {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(String(local ?? ''));

    if (!m) return String(local ?? '');

    const [, y, mo, d, h, mi] = m;
    const weekday = WEEKDAYS[new Date(Date.UTC(Number(y), Number(mo) - 1, Number(d))).getUTCDay()];
    const hour = Number(h);
    const twelve = hour % 12 === 0 ? 12 : hour % 12;

    return `${weekday}, ${MONTHS[Number(mo) - 1]} ${Number(d)}, ${twelve}:${mi} ${hour < 12 ? 'AM' : 'PM'}`;
}

/** How an unpaid kitchen order is to be paid, for the Payment column. */
export function unpaidHow(order: { payment_method?: string | null; preferred_payment?: string | null }): string {
    if (order.payment_method === 'online') return 'card online';

    const labels: Record<string, string> = {
        cash: 'cash', check: 'check', zelle: 'Zelle', bank_transfer: 'bank transfer', other: 'other',
    };

    return order.preferred_payment ? `by ${labels[order.preferred_payment] ?? order.preferred_payment}` : 'at pickup';
}
