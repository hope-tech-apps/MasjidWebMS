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

export function donationMethodLabel(gift: GiftLike): string {
    if (isHistoricalGift(gift)) {
        return HISTORY_PROVIDERS[gift.payment_method ?? ''] ?? 'Wix checkout';
    }

    if (gift.source === 'offline') {
        return (gift.payment_method && gift.payment_method !== 'unknown')
            ? gift.payment_method.replace(/_/g, '/')
            : 'offline';
    }

    return 'card';
}
