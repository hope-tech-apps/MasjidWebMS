import { formatMinorAmount } from '../../core/types/data/masjid-related/Form.ts';
import type { FormResponseRow, FormRosterRow } from '../../core/types/data/masjid-related/Form.ts';

type PriceContext = Pick<FormResponseRow, 'price_breakdown' | 'payment_state' | 'total_minor' | 'currency' | 'price_set_by' | 'staff_code' | 'payment_method' | 'paid_via' | 'marked_paid_by'>;

/** The roster calls settlement payment_status; list/detail call it payment_state. */
export function rosterPriceContext(row: FormRosterRow): PriceContext {
    return { ...row, payment_state: row.payment_status, currency: row.price_breakdown?.currency };
}

/** New snapshots opt into price context; nullable/absent audit fields keep legacy rendering. */
export function hasPriceContext(row: PriceContext): boolean {
    return row.price_breakdown?.list_unit_minor !== null && row.price_breakdown?.list_unit_minor !== undefined;
}

/**
 * A settled registration has a price; an unpaid registration still has an amount due.
 * Every paid row, older ones included: "Amount due" over money already taken reads as
 * money still owed, which is the question an office asked about a cash entry.
 */
export function priceHeading(row: PriceContext): string {
    return row.payment_state === 'paid' ? 'Registration price' : 'Amount due';
}

/** Historical list price, never recalculated from today's form definition. */
export function listPriceText(row: PriceContext): string {
    const price = row.price_breakdown;
    if (!hasPriceContext(row) || !price || price.list_unit_minor === price.unit_minor) return '';
    const currency = price.currency || row.currency || 'usd';
    const label = price.label ? `${price.label}: ` : '';
    return `List price · ${label}${formatMinorAmount(price.list_unit_minor, currency)} × ${price.quantity} = ${formatMinorAmount(price.list_unit_minor! * price.quantity, currency)}`;
}

/** An explicit staff price is attributed even when it equals the list price. */
export function staffPriceText(row: PriceContext): string {
    if (!hasPriceContext(row) || row.price_breakdown?.staff_unit_minor === null || row.price_breakdown?.staff_unit_minor === undefined) return '';
    const setter = row.price_set_by || row.staff_code?.holder_name;
    return setter ? `Price set by staff · ${setter}` : 'Price set by staff';
}

/** Zero is complimentary only on a paid, explicitly overridden staff snapshot. */
export function isComplimentary(row: PriceContext): boolean {
    return hasPriceContext(row) && row.payment_state === 'paid' && row.price_breakdown?.staff_unit_minor === 0 && row.total_minor === 0;
}

/** The admin who later takes cash owns it; the original staff creator remains separate. */
export function cashCollectorText(row: PriceContext): string | null {
    if (row.payment_state !== 'paid' || (row.payment_method !== 'cash' && row.paid_via !== 'cash')) return null;
    if (row.marked_paid_by?.name) return `taken at the table by ${row.marked_paid_by.name}`;
    if (row.staff_code?.holder_name) return `held by ${row.staff_code.holder_name}`;
    return null;
}
