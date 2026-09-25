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

type BoardOrder = { status?: string | null; payment_method?: string | null; payment_status?: string | null };

/**
 * A card kitchen order whose card has not been paid. Until it is, it may be a
 * payment page the customer abandoned: the office was never emailed about it
 * (KitchenOrderNotifier::placed waits for the payment), and the server refuses to
 * confirm it (MealOrdersController::updateStatus). The board says so instead of
 * asking the office to act on it.
 */
export function cardNotPaid(order: BoardOrder | null | undefined, menu: { kind?: string | null } | null | undefined): boolean {
    return isCatalogue(menu)
        && order?.payment_method === 'online'
        && order?.payment_status === 'unpaid'
        && order?.status !== 'cancelled';
}

/**
 * A kitchen order waiting for the office to confirm it: pending and real. A paid
 * card order still waits (MealOrder::markPaid does not confirm a kitchen order),
 * which is the whole difference from a Friday order; an order to be paid to the
 * office waits as soon as it is placed; an unpaid card order does not (cardNotPaid).
 */
export function awaitsConfirmation(order: BoardOrder | null | undefined, menu: { kind?: string | null } | null | undefined): boolean {
    return isCatalogue(menu) && order?.status === 'pending' && !cardNotPaid(order, menu);
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

/**
 * How an unpaid kitchen order is to be paid, for the Payment column. In the
 * organisation's own words when the server sent them (`preferred_payment_label`):
 * an organisation must name its "Other", and "by other" tells the office nothing.
 */
export function unpaidHow(order: { payment_method?: string | null; preferred_payment?: string | null; preferred_payment_label?: string | null }): string {
    if (order.payment_method === 'online') return 'card online';

    const named = String(order.preferred_payment_label ?? '').trim();
    if (order.preferred_payment && named !== '') return `by ${named}`;

    const labels: Record<string, string> = {
        cash: 'cash', check: 'check', zelle: 'Zelle', bank_transfer: 'bank transfer', other: 'other',
    };

    return order.preferred_payment ? `by ${labels[order.preferred_payment] ?? order.preferred_payment}` : 'at pickup';
}

/**
 * Why staff cannot take a kitchen order with this method on "Add an order", or null
 * when they can. The methods are the organisation's accepted ones as the board
 * payload lists them (AcceptedPaymentMethods::staffList); only card can be
 * unavailable, and the server refuses it for the same two reasons
 * (MealOrdersController::store).
 */
export function staffMethodUnavailable(
    method: { online?: boolean | null; ready?: boolean | null } | null | undefined,
    menu: { allow_online_payment?: boolean | null } | null | undefined,
): string | null {
    if (!method?.online) return null;
    if (method.ready === false) return 'Stripe is not set up for this organisation yet';
    if (menu?.allow_online_payment === false) return 'online payment is switched off for this menu';

    return null;
}
