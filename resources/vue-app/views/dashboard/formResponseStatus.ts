/**
 * The status select on each row of the registrations list (FormResponsesView.vue), as
 * plain functions so they can be pinned without a browser
 * (resources/vue-app/tests/form-response-status.test.ts).
 *
 * The owner asked to change a registration's status without opening it (2026-09-27), so
 * the list saves a status through the same PUT the detail modal's Save uses. What that PUT
 * does on a cancel is decided on the server (FormResponsesController::update()); this file
 * decides WHEN the list saves (statusSelectController()), and words the question asked
 * before a cancel, every sentence of which is something that code, or FormReservations,
 * does.
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
    /**
     * That page is KNOWN to be beyond checking (the row's `page_unreachable`): the account it
     * was opened on is no longer one Stripe lets us act on, so the cancel cannot close it.
     */
    pageUnreachable: boolean;
    /**
     * The organisation whose Stripe account the card payment went through, when that is not
     * this one (the row's `charged_through.name`): only it can refund the charge
     * (FormChargeAccount::refundInstruction()).
     */
    chargedThrough: string | null;
    /** How much of the card payment has been refunded already, formatted, or null for none. */
    refundedAmount: string | null;
    /** That refund covers the whole payment (charge_refunded_minor >= total_minor). */
    refundedInFull: boolean;
    /**
     * The registration has a payment method (a money leg): it can never be deleted, only
     * cancelled (FormResponsesController::destroy(), the list's DELETE_REFUSED).
     */
    hasPayment: boolean;
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
 *   and logs it). Taken through another organisation's account, only that organisation can
 *   refund it (FormChargeAccount::refundInstruction(), DECISIONS.md 2026-09-15). One refunded
 *   in full already is said to be; one refunded in part says how much.
 * - An unpaid card registration's open page: the server TRIES to close it, and says so
 *   when it cannot (card_page 'unconfirmed', 'unchecked', 'unreachable') or when Stripe says
 *   it was just paid. A page already known to be beyond checking (`page_unreachable`) is
 *   said up front not to be closable.
 * - Cash and other hand-recorded payments stay on record; cash moves to the "cancelled"
 *   column of its holder's totals (FormResponse::stampStatusChange()).
 * - A cancelled registration cannot be checked in (collect() refuses it) or paid (Take
 *   cash and Mark paid refuse it), and update() sends no email.
 * - A reserved date stops being protected at once and goes to the next payer who asks for
 *   it; a restore gets it back only if nobody has (FormReservations::reclaimForRestore()).
 * - Cancelling does not lower forms.response_count (only a delete does), so it frees no
 *   place on a form with a capacity; and a registration with a payment is never deleted,
 *   so for one of those nothing frees it. Pinned on the server by
 *   FormResponsesAdminTest::cancelling_a_registration_keeps_its_place_and_only_deleting_frees_one:
 *   change the sentence and that test together.
 */
export function cancelQuestion(facts: CancelFacts): CancelQuestion {
    const lines: string[] = [];
    const amount = facts.paidAmount ? ` (${facts.paidAmount})` : '';
    const paid = facts.paymentState === 'paid';
    const card = facts.paymentEnabled && facts.paymentMethod === 'online';

    if (card && paid) {
        lines.push(cardPaidLine(facts, amount));
    } else if (card && facts.cardPageOpened && facts.pageUnreachable) {
        const holder = facts.chargedThrough ?? 'another organisation';
        lines.push(`A card payment page was opened for it on ${holder}'s Stripe account, which no longer lets us check or close it. `
            + 'Cancelling cannot close that page, so it may still take a payment until it expires.');
    } else if (card && facts.cardPageOpened) {
        lines.push('A card payment page was opened for it. Cancelling tries to close that page so it cannot be paid; '
            + 'if it cannot, or they have just paid by card, you will be told.');
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
        lines.push(facts.hasPayment
            ? `It still counts towards the form's limit of ${facts.capacity}, and a registration with a payment cannot be deleted, so cancelling does not free a place.`
            : `It still counts towards the form's limit of ${facts.capacity}; only deleting a registration frees a place.`);
    }

    lines.push('It stays in this list, and choosing another status restores it.');

    return {
        title: `Cancel ${registrationName(facts.id, facts.name)}?`,
        lines,
        confirmText: 'Cancel registration',
        keepText: 'Keep it'
    };
}

/** The card-paid sentence: who can refund it, and what has been refunded already. */
function cardPaidLine(facts: CancelFacts, amount: string): string {
    const through = facts.chargedThrough ? ` through ${facts.chargedThrough}'s Stripe account` : '';

    if (facts.refundedInFull) {
        return `It was paid by card${amount}${through}, and that payment has since been refunded in full. Cancelling does not change the payment.`;
    }

    const refund = facts.chargedThrough
        ? `only ${facts.chargedThrough} can refund it, in its own Stripe dashboard, if it should not stand.`
        : 'refund it in the Stripe account it was charged to if it should not stand.';

    if (facts.refundedAmount) {
        return `It was paid by card${amount}${through}, and ${facts.refundedAmount} of it has since been refunded. Cancelling does not refund the rest: ${refund}`;
    }

    return `It was paid by card${amount}${through}. Cancelling does not refund it: ${refund}`;
}

/**
 * The cancel question as SweetAlert options. The title goes in `titleText`, which Swal
 * sets as text, never `title`, which it parses as HTML: it carries the respondent's own
 * name. `body` is the lines, already built as DOM text by the caller.
 */
export function cancelDialogOptions<B>(question: CancelQuestion, body: B) {
    return {
        icon: 'warning' as const,
        titleText: question.title,
        html: body,
        showCancelButton: true,
        focusCancel: true,
        confirmButtonColor: '#d33',
        confirmButtonText: question.confirmText,
        cancelButtonText: question.keepText
    };
}

/** The row select's accessible name: "Status for registration #121, Reema Bianouni". */
export function statusSelectLabel(id: number, name: string | null): string {
    return `Status for ${registrationName(id, name)}`;
}

/**
 * What else on screen a saved status moves. Only a move into or out of cancelled does: the
 * cash totals move cash between columns, and a reserved date is given up or taken back,
 * which changes the reserved-dates board's conflict count. That count is shown on the
 * board's header even while it is folded (reservationConflicts), so it is re-read whether
 * or not the board is open.
 */
export function refreshesAfterStatusChange(from: FormResponseStatus, to: FormResponseStatus, reservesDates: boolean): { cash: boolean; reservations: boolean } {
    const crossesCancelled = from === 'cancelled' || to === 'cancelled';
    return { cash: crossesCancelled, reservations: crossesCancelled && reservesDates };
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

// --- When the list saves -------------------------------------------------------------

/**
 * Keys that change a CLOSED select's value without opening it. In Chrome and Edge on
 * Windows, and in Firefox, ArrowUp/ArrowDown (and Home, End, Page keys, and typing a
 * letter) on a focused, closed select move it to the next status and fire `change` at
 * once. A change they cause is only a step on the way, never a choice (WCAG 3.2.2), so it
 * is held (statusSelectController()). Alt+ArrowDown, F4 and Space open the list instead:
 * the change that follows is a choice made in the open list, and is saved.
 */
const MOVING_KEYS = new Set(['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'PageUp', 'PageDown']);

export function movesWithoutCommitting(event: { key: string; altKey?: boolean; ctrlKey?: boolean; metaKey?: boolean }): boolean {
    if (event.altKey || event.ctrlKey || event.metaKey) return false;
    if (MOVING_KEYS.has(event.key)) return true;
    // Type-ahead: "c" moves a closed select to Cancelled. Space opens the list instead.
    return event.key.length === 1 && event.key !== ' ';
}

/**
 * What to do with a status the select now shows.
 *  - ignore: nothing (the same status, or another save or row action is running).
 *  - hold:   a keyboard step; kept on screen, saved on Enter or when focus leaves.
 *  - ask:    a move into cancelled; asked first (asksBeforeStatusChange()).
 *  - save:   saved now.
 */
export type InlineStep = 'ignore' | 'hold' | 'ask' | 'save';

export function decideInlineChange(input: { from: FormResponseStatus; next: FormResponseStatus; busy: boolean; keyboard: boolean }): InlineStep {
    if (input.busy || input.next === input.from) return 'ignore';
    if (input.keyboard) return 'hold';
    return asksBeforeStatusChange(input.from, input.next) ? 'ask' : 'save';
}

/** A row as the controller needs it. `status` is the reactive row's, so the select follows it. */
export type StatusRow = { id: number; status: FormResponseStatus };

/** The select element, or anything with a value (a test's fake). */
export type StatusSelectElement = { value: string };

/** Only what a key press says about itself. */
export type StatusKeyEvent = { key: string; altKey?: boolean; ctrlKey?: boolean; metaKey?: boolean; preventDefault: () => void };

export type StatusSelectDeps<R> = {
    /** Keyboard steps not yet saved, by row id. Reactive in the view, so the select shows them. */
    held: Map<number, FormResponseStatus>;
    /** Another status save or door action is running (the view's busyRowId). */
    isBusy: () => boolean;
    /**
     * Take (row id) or release (null) the one lock the list's row actions share. Held from the
     * question to the answer, so every select and door button waits, no second popup can
     * replace this one's question or its must-act answer (SweetAlert shows one at a time),
     * and no other answer can overwrite this row out of order.
     */
    lock: (rowId: number | null) => void;
    /** Mark (row id) or clear (null) the row whose PUT is in flight: its spinner. */
    saving: (rowId: number | null) => void;
    /** Asked before a move into cancelled. True: go ahead. */
    ask: (row: StatusRow) => Promise<boolean>;
    /** The PUT, with the status the list showed as its precondition. */
    save: (row: StatusRow, next: FormResponseStatus, expected: FormResponseStatus) => Promise<R>;
    /**
     * Saved: apply the answer to the row and say so, still under the lock. May return what to
     * do once the lock is released (closing a card page again goes through the lock itself).
     */
    saved: (row: StatusRow, from: FormResponseStatus, next: FormResponseStatus, result: R) => Promise<void | (() => Promise<unknown>)>;
    /** Refused or failed: the row already shows its old status again; say why. Under the lock. */
    refused: (row: StatusRow, error: unknown) => Promise<void>;
};

/**
 * The row select's event handlers. A status is saved only when it is CHOSEN: picked from
 * the open list (by mouse, or Enter in it), or, after arrow-key steps, on Enter or when
 * focus leaves the select. Escape puts back the saved status. So arrowing from Cancelled
 * past New to Waitlisted saves nothing on the way, and a cancelled registration is never
 * restored because a key was held down.
 */
export function statusSelectController<R>(deps: StatusSelectDeps<R>) {
    let keyboard = false;

    const run = async (row: StatusRow, next: FormResponseStatus, select: StatusSelectElement): Promise<'kept' | 'saved' | 'refused'> => {
        const from = row.status;
        let after: void | (() => Promise<unknown>);
        deps.lock(row.id);

        try {
            if (asksBeforeStatusChange(from, next) && !(await deps.ask(row))) {
                // Nothing changed, so nothing re-renders the select: put it back by hand.
                select.value = row.status;
                return 'kept';
            }

            deps.saving(row.id);
            let result: R;

            try {
                result = await saveStatusOptimistically(row, next, () => deps.save(row, next, from));
            } catch (error) {
                deps.saving(null);
                select.value = row.status;
                await deps.refused(row, error);
                return 'refused';
            }

            deps.saving(null);
            after = await deps.saved(row, from, next, result);
        } finally {
            deps.lock(null);
        }

        if (typeof after === 'function') await after();
        return 'saved';
    };

    const commitHeld = async (row: StatusRow, select: StatusSelectElement) => {
        const next = deps.held.get(row.id);
        if (next === undefined) return null;

        deps.held.delete(row.id);
        if (next === row.status) return null;

        return run(row, next, select);
    };

    return {
        keydown(row: StatusRow, event: StatusKeyEvent, select: StatusSelectElement) {
            if (event.key === 'Enter' && deps.held.has(row.id)) {
                event.preventDefault();
                if (deps.isBusy()) return Promise.resolve(null);
                return commitHeld(row, select);
            }

            if (event.key === 'Escape' && deps.held.has(row.id)) {
                event.preventDefault();
                deps.held.delete(row.id);
                select.value = row.status;
                return Promise.resolve(null);
            }

            keyboard = movesWithoutCommitting(event);
            return Promise.resolve(null);
        },

        /** A pointer press: the next change is picked from the open list. */
        pointerdown() {
            keyboard = false;
        },

        change(row: StatusRow, select: StatusSelectElement) {
            const next = select.value as FormResponseStatus;
            const step = decideInlineChange({ from: row.status, next, busy: deps.isBusy(), keyboard });

            if (step === 'ignore') {
                if (!deps.isBusy()) deps.held.delete(row.id);
                select.value = deps.held.get(row.id) ?? row.status;
                return Promise.resolve(null);
            }

            if (step === 'hold') {
                deps.held.set(row.id, next);
                return Promise.resolve(null);
            }

            deps.held.delete(row.id);
            return run(row, next, select);
        },

        /** Focus left the select: a held keyboard choice is the admin's choice now. */
        blur(row: StatusRow, select: StatusSelectElement) {
            if (!deps.held.has(row.id) || deps.isBusy()) return Promise.resolve(null);
            return commitHeld(row, select);
        }
    };
}
