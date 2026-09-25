/**
 * The order page's decisions about a PAID order's change, as plain functions so
 * they can be pinned without a browser (resources/vue-app/tests/lunch-top-up.test.ts).
 * Every one of them is a DISPLAY answer: the server asks all of it again, on the
 * locked row, whatever this page decided.
 */

/** What to tell a customer back from paying the difference, or null while it is still being confirmed. */
export type TopUpOutcome = { key: string; tone: "ok" | "warn" };

/**
 * What became of the customer's last change-and-pay, read from the status the
 * server sends (`last_top_up_status`), never worked out from the totals: a
 * conflict can leave more paid than the order costs, or less (staff added to
 * it meanwhile), and a page paid just before it closed is still `pending` until
 * the webhook lands, whatever the clock says.
 */
export function topUpOutcome(order: any): TopUpOutcome | null {
    if (!order) return null;

    switch (String(order.last_top_up_status ?? "")) {
        case "pending":
            return null;
        case "applied":
            return { key: "topup_done", tone: "ok" };
        case "conflict":
            return { key: "topup_conflict", tone: "warn" };
        case "rejected":
            return { key: "topup_unconfirmed", tone: "warn" };
        case "expired":
            return { key: "topup_expired", tone: "warn" };
        default:
            // No change-and-pay on record at all: nothing to report.
            return { key: "", tone: "ok" };
    }
}

/**
 * Why a paid order's draft cannot be saved here, as an i18n key, or null when it
 * can. Fewer plates than were paid for is the masjid's to do (no automatic
 * refunds); more plates in the last half hour before the cutoff cannot be paid for
 * online (`topup_open_until` is when that starts, from the server).
 */
export function paidDraftBlock(input: {
    isPaid: boolean;
    previewTotal: number;
    paidMinor: number;
    openUntil: string | null | undefined;
    nowMs: number;
}): string | null {
    if (!input.isPaid) return null;
    if (input.previewTotal < input.paidMinor) return "topup_reduce";

    if (input.previewTotal > input.paidMinor && input.openUntil) {
        const until = Date.parse(input.openUntil);
        if (Number.isFinite(until) && input.nowMs >= until) return "topup_too_close";
    }

    return null;
}

/**
 * The refusal codes a paid order's edit can come back with (`data.code`), and the
 * i18n key each is said with. A code this bundle does not know is shown in the
 * server's own words.
 */
export const REFUSAL_KEYS: Record<string, string> = {
    paid_reduce: "topup_reduce",
    too_close_to_cutoff: "topup_too_close",
    topup_unavailable: "topup_unavailable",
    topup_confirming: "topup_confirming_wait",
    order_moved: "order_moved",
    just_paid: "just_paid",
    topup_not_opened: "topup_not_opened",
    topup_too_small: "topup_too_small",
    paid_balance_open: "paid_balance_open",
    paid_prices_moved: "paid_prices_moved",
    closed: "edit_why_closed",
    paid: "edit_why_paid",
    refunded: "edit_why_refunded",
    cancelled: "edit_why_cancelled",
    item_gone: "edit_why_item_gone",
};

/** Why an order cannot be changed at all (`edit_notice_code`), as i18n keys. */
export const EDIT_WHY_KEYS: Record<string, string> = {
    closed: "edit_why_closed",
    paid: "edit_why_paid",
    refunded: "edit_why_refunded",
    cancelled: "edit_why_cancelled",
    item_gone: "edit_why_item_gone",
    paid_balance_open: "paid_balance_open",
    paid_prices_moved: "paid_prices_moved",
};

/**
 * The page's URL query once the `topup` return marker has been read, so a reload,
 * a bookmark or Back does not replay a note about a payment long past.
 */
export function withoutTopUpMarker(query: Record<string, unknown>): Record<string, unknown> {
    const rest: Record<string, unknown> = { ...query };
    delete rest.topup;
    return rest;
}
