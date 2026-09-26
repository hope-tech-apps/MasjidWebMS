/**
 * How a gift was paid, in the words the ledger, the giving dashboard and the
 * contact record all use — one implementation, because three copies of this
 * label drifted before (DonationsView and DonationsDashboardView each carried
 * their own).
 *
 *  - `stripe`      → "card": Manara's own checkout.
 *  - `offline`     → the method staff recorded (cash, check, zelle, …).
 *  - `historical`  → an order imported from the old Wix site, paid through
 *                    Square or PayPal before the organisation used Manara
 *                    (App\Models\Donation::SOURCE_HISTORICAL; DECISIONS.md
 *                    2026-09-25, "Wix order history"). It is labelled as Wix
 *                    history so no screen reads it as money Manara took.
 *
 * Pure and dependency-free so `npm run test:spa` can run it directly.
 */

export type GiftLike = {
    source?: string | null;
    payment_method?: string | null;
};

/** The import stores the processor as `payment_method`: square | paypal | wix. */
const HISTORY_PROVIDERS: Record<string, string> = {
    square: 'Square (Wix)',
    paypal: 'PayPal (Wix)',
    wix: 'Wix checkout',
};

export function isHistoricalGift(gift: GiftLike | null | undefined): boolean {
    return gift?.source === 'historical';
}

/** The processor of an imported Wix order or gift: square | paypal | wix. */
export function historyProviderLabel(provider: string | null | undefined): string {
    return HISTORY_PROVIDERS[provider ?? ''] ?? 'Wix checkout';
}

export function donationMethodLabel(gift: GiftLike): string {
    if (isHistoricalGift(gift)) {
        return historyProviderLabel(gift.payment_method);
    }

    if (gift.source === 'offline') {
        return (gift.payment_method && gift.payment_method !== 'unknown')
            ? gift.payment_method.replace(/_/g, '/')
            : 'offline';
    }

    return 'card';
}

export type ReceiptGift = GiftLike & {
    status?: string | null;
    fund?: { name?: string | null; receiptable?: boolean | null } | null;
};

/**
 * Why a gift with no receipt cannot be given one here — the sentence the
 * ledger's detail panel shows once the "issue receipt" case is ruled out.
 * Each branch names what blocks the receipt; the historical and Stripe
 * sentences mirror the server's own refusals
 * (DonationsController::historicalRefusal and issueReceipt), so the panel and
 * the API never tell the admin different things. Checked in this order, as the
 * panel always has: imported history first, because it is neither a card gift
 * nor an offline one.
 */
export function receiptNote(gift: ReceiptGift): string {
    if (isHistoricalGift(gift)) {
        return 'This gift is part of the order history imported from the old Wix site. It was paid '
            + 'through Wix, not Manara, so Manara does not edit it or issue a receipt for it.';
    }

    if (gift.source !== 'offline') {
        return 'Receipts for card gifts are issued automatically when Stripe confirms the payment.';
    }

    if (gift.status !== 'succeeded') {
        return 'This gift is not marked succeeded, so it cannot be receipted yet.';
    }

    return `The ${gift.fund?.name ?? 'chosen'} fund is set not to issue tax receipts, `
        + 'so no receipt can be issued for this gift.';
}

/**
 * The line under a contact's "Total giving": what they gave on the old Wix
 * site, summed apart so it never reads as money Manara recorded
 * (ContactsController::show `historical_giving_total`). Null when there is
 * none, so the line is not drawn at all rather than reading "plus $0.00".
 */
export function historicalGivingNote(
    totalMinor: number | null | undefined,
    formatCents: (cents: number) => string,
): string | null {
    return totalMinor && totalMinor > 0
        ? `plus ${formatCents(totalMinor)} on the old Wix site`
        : null;
}

// ---------------------------------------------------------------- Wix orders
//
// The words a contact record uses for the orders imported from the old Wix
// site (ContactsController::show `historical_orders`; DECISIONS.md 2026-09-25).
// The contact record is the only screen where an order's Wix number, processor
// and fee sit together, and the only one where a line the import kept on the
// order alone — a festival food ticket, a prayer rug, a Wix Events food
// purchase — can be seen at all: those lines are neither a gift nor a seat, so
// no ledger holds them. Kept in this file rather than its own so the node test
// runner can load it without a runtime import between helpers.

export type WixOrderLine = {
    name: string;
    quantity: number;
    unit_minor: number;
    discount_minor?: number;
    recorded_as?: 'donation' | 'registration' | 'order_only' | null;
};

export type WixOrder = {
    source: 'wix_stores' | 'wix_events' | string;
    order_number: string;
    provider?: string | null;
    status: 'paid' | 'canceled' | 'declined' | string;
    lines: WixOrderLine[];
};

/** How Wix numbered it: store orders are "#10001", Wix Events orders are not. */
export function wixOrderLabel(order: WixOrder): string {
    return order.source === 'wix_events'
        ? `Wix Events order ${order.order_number}`
        : `Wix order #${order.order_number}`;
}

const RECORDED_AS: Record<string, string> = {
    donation: 'recorded as a gift',
    registration: 'recorded as a registration',
    order_only: 'kept on this order only',
};

/**
 * One line per product: "2 × Fall Festival Ticket (recorded as a
 * registration)". Saying where each line went is the point — an order-only
 * line is otherwise indistinguishable from one the ledgers hold.
 */
export function wixOrderLineSummaries(order: WixOrder): string[] {
    return (order.lines ?? []).map((line) => {
        const where = RECORDED_AS[line.recorded_as ?? ''];

        return `${line.quantity} × ${line.name.trim()}${where ? ` (${where})` : ''}`;
    });
}

/** Paid through whom, or why no money moved. */
export function wixOrderPaymentLabel(order: WixOrder): string {
    if (order.status === 'paid') {
        return historyProviderLabel(order.provider);
    }

    return order.status === 'declined'
        ? 'Declined at the Wix checkout, never paid'
        : 'Abandoned at the Wix checkout, never paid';
}
