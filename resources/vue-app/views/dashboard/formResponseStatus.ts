/**
 * The status select on each row of the registrations list (FormResponsesView.vue), as
 * plain functions so they can be pinned without a browser
 * (resources/vue-app/tests/form-response-status.test.ts).
 *
 * The owner asked to change a registration's status without opening it (2026-09-27), so
 * the list now saves a status on change, through the same PUT the detail modal's Save
 * uses. What that PUT does on a cancel is decided on the server
 * (FormResponsesController::update()); this file only words the question asked first, and
 * every sentence in it is something that code, or FormReservations, does.
 */
import type { FormPaymentMethod, FormPaymentStatus, FormResponseStatus } from '../../core/types/data/masjid-related/Form';

/** FormResponse::STATUSES, for an API that sends no list of its own. */
export const FALLBACK_STATUSES: FormResponseStatus[] = ['new', 'confirmed', 'waitlisted', 'cancelled'];

const STATUS_LABELS: Record<FormResponseStatus, string> = {
    new: 'New',
    confirmed: 'Confirmed',
    waitlisted: 'Waitlisted',
    cancelled: 'Cancelled'
};

/** A status in words. One the screen does not know yet is shown capitalised, never hidden. */
export function statusLabel(status: string): string {
    return STATUS_LABELS[status as FormResponseStatus] ?? (status.charAt(0).toUpperCase() + status.slice(1));
}

/**
 * The statuses a select offers, in the server's order (meta.statuses) so the list and the
 * detail modal read alike; FALLBACK_STATUSES when the server sent none.
 */
export function statusChoices(served: readonly FormResponseStatus[] | null | undefined): { value: FormResponseStatus; label: string }[] {
    const list = served && served.length ? served : FALLBACK_STATUSES;
    return list.map(value => ({ value, label: statusLabel(value) }));
}

/**
 * Only moving INTO cancelled is asked first: it closes a card page, gives up a reserved
 * date and blocks check-in. Leaving cancelled does nothing that cannot be undone by
 * choosing cancelled again, and a restore whose date someone else now holds is refused by
 * the server with the date named, so it is not asked.
 */
export function asksBeforeStatusChange(from: FormResponseStatus, to: FormResponseStatus): boolean {
    return to === 'cancelled' && from !== 'cancelled';
}

/** What the question needs to know about one registration and its form. */
export type CancelFacts = {
    id: number;
    name: string | null;
    /** The form takes payment: check-in and the payment lines only exist then. */
    paymentEnabled: boolean;
    paymentMethod: FormPaymentMethod | null;
    paymentState: FormPaymentStatus | null;
    /** The paid amount, already formatted ("$60.00"), or null. */
    paidAmount: string | null;
    /** An unpaid card registration whose Stripe page has been opened. */
    cardPageOpened: boolean;
    checkedIn: boolean;
    /** The form reserves dates from a list (meta.reservations). */
    reservesDates: boolean;
    /** The form's capacity, or null for none. */
    capacity: number | null;
};

export type CancelQuestion = {
    title: string;
    lines: string[];
    confirmText: string;
    keepText: string;
};

/** "registration #121, Reema Bianouni", the way the select's own label names it. */
export function registrationName(id: number, name: string | null): string {
    const trimmed = name?.trim();
    return trimmed ? `registration #${id}, ${trimmed}` : `registration #${id}`;
}

/**
 * The question before a cancel, one sentence per consequence, each only where it applies.
 *
 * - A card payment is never refunded by cancelling (closePageOfCancelled() says so after,
 *   and logs it); the refund is made in Stripe.
 * - An unpaid card registration's open page is closed so it cannot be paid; if Stripe says
 *   it was just paid, the server says so instead.
 * - Cash and other hand-recorded payments stay on record; cash moves to the "cancelled"
 *   column of its holder's totals (FormResponse::stampStatusChange()).
 * - A cancelled registration cannot be checked in (collect() refuses it) or paid (Take
 *   cash and Mark paid refuse it), and update() sends no email.
 * - A reserved date stops being protected at once and goes to the next payer who asks for
 *   it; a restore gets it back only if nobody has (FormReservations::reclaimForRestore()).
 * - Cancelling does not lower forms.response_count (only a delete does), so it frees no
 *   place on a form with a capacity.
 */
export function cancelQuestion(facts: CancelFacts): CancelQuestion {
    const lines: string[] = [];
    const amount = facts.paidAmount ? ` (${facts.paidAmount})` : '';
    const paid = facts.paymentState === 'paid';

    if (facts.paymentEnabled && facts.paymentMethod === 'online' && paid) {
        lines.push(`It was paid by card${amount}. Cancelling does not refund it: refund it in Stripe if it should not stand.`);
    } else if (facts.paymentEnabled && facts.paymentMethod === 'online' && facts.cardPageOpened) {
        lines.push('A card payment page was opened for it. Cancelling closes that page so it cannot be paid. If they have just paid by card, you will be told.');
    } else if (facts.paymentEnabled && facts.paymentMethod === 'cash' && paid) {
        lines.push(`The cash${amount} stays on record and moves to its holder's "cancelled" column in the cash totals. Nothing is refunded.`);
    } else if (facts.paymentEnabled && paid) {
        lines.push(`Its payment${amount} stays on record. Nothing is refunded.`);
    }

    if (facts.paymentEnabled) {
        lines.push(facts.checkedIn
            ? 'It has already been checked in; that stays on record. While cancelled it cannot be paid, and nobody is emailed.'
            : 'While cancelled it cannot be checked in or paid, and nobody is emailed.');
    } else {
        lines.push('Nobody is emailed.');
    }

    if (facts.reservesDates) {
        lines.push('If it reserved a date, that date is offered to others again. Restoring it later gets the date back only if nobody else has reserved it.');
    }

    if (facts.capacity !== null) {
        lines.push(`It still counts towards the form's limit of ${facts.capacity}; only deleting a registration frees a place.`);
    }

    lines.push('It stays in this list, and choosing another status restores it.');

    return {
        title: `Cancel ${registrationName(facts.id, facts.name)}?`,
        lines,
        confirmText: 'Cancel registration',
        keepText: 'Keep it'
    };
}

/**
 * Show the new status at once, and put the old one back if the save fails, so the select
 * never shows a status the server did not keep. The failure is rethrown for the caller to
 * word; what the server answers on success is the caller's to apply.
 */
export async function saveStatusOptimistically<R>(
    row: { status: FormResponseStatus },
    next: FormResponseStatus,
    save: () => Promise<R>
): Promise<R> {
    const previous = row.status;
    row.status = next;

    try {
        return await save();
    } catch (error) {
        row.status = previous;
        throw error;
    }
}
