/**
 * The status select on each row of the registrations list
 * (views/dashboard/formResponseStatus.ts): its labels and order, which changes are asked
 * first, what the cancel question says, the optimistic save that puts the old status back
 * when the server refuses, and statusSelectController(), which decides when a status is
 * saved (chosen, never an arrow-key step) and holds the row lock from question to answer.
 * The last tests read FormResponsesView.vue and the store as text, as
 * newsletter-blocks.test.ts does, to pin that the view is wired to all of it: the SPA has no
 * DOM test harness.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    FALLBACK_STATUSES,
    asksBeforeStatusChange,
    cancelDialogOptions,
    cancelQuestion,
    decideInlineChange,
    movesWithoutCommitting,
    refreshesAfterStatusChange,
    registrationName,
    saveStatusOptimistically,
    statusChoices,
    statusLabel,
    statusSelectController,
    statusSelectLabel,
    type CancelFacts,
    type StatusRow
} from '../views/dashboard/formResponseStatus.ts';
import type { FormResponseStatus } from '../core/types/data/masjid-related/Form.ts';

const facts = (over: Partial<CancelFacts> = {}): CancelFacts => ({
    id: 121,
    name: 'Reema Bianouni',
    paymentEnabled: true,
    paymentMethod: 'online',
    paymentState: 'unpaid',
    paidAmount: null,
    cardPageOpened: false,
    pageUnreachable: false,
    chargedThrough: null,
    refundedAmount: null,
    refundedInFull: false,
    hasPayment: true,
    checkedIn: false,
    reservesDates: false,
    capacity: null,
    ...over
});

test('the select offers the four statuses in words, in FormResponse::STATUSES order, when the server sends none', () => {
    assert.deepEqual(statusChoices(undefined).map(choice => choice.label), ['New', 'Confirmed', 'Waitlisted', 'Cancelled']);
    assert.deepEqual(statusChoices([]).map(choice => choice.value), FALLBACK_STATUSES);
});

test('the select keeps the order the server sends, so the list and the detail modal read alike', () => {
    assert.deepEqual(statusChoices(['cancelled', 'new']).map(choice => choice.label), ['Cancelled', 'New']);
});

test('a status the screen does not know yet is shown capitalised rather than dropped', () => {
    assert.equal(statusLabel('archived'), 'Archived');
});

test('only a move into cancelled is asked first', () => {
    assert.equal(asksBeforeStatusChange('new', 'cancelled'), true);
    assert.equal(asksBeforeStatusChange('confirmed', 'cancelled'), true);
    assert.equal(asksBeforeStatusChange('cancelled', 'new'), false);
    assert.equal(asksBeforeStatusChange('new', 'confirmed'), false);
    assert.equal(asksBeforeStatusChange('waitlisted', 'new'), false);
});

test('the cancel question names the registration the way the select is labelled', () => {
    assert.equal(cancelQuestion(facts()).title, 'Cancel registration #121, Reema Bianouni?');
    assert.equal(registrationName(7, '  '), 'registration #7');
});

test('cancelling a card-paid registration says plainly that it is not refunded', () => {
    const text = cancelQuestion(facts({ paymentState: 'paid', paidAmount: '$60.00' })).lines.join(' ');

    assert.match(text, /paid by card \(\$60\.00\)\. Cancelling does not refund it/);
    assert.match(text, /cannot be checked in or paid/);
});

test('cancelling an unpaid card registration with an open page says the server tries to close it, and will say if it cannot', () => {
    const text = cancelQuestion(facts({ cardPageOpened: true })).lines.join(' ');

    assert.match(text, /Cancelling tries to close that page so it cannot be paid; if it cannot, or they have just paid by card, you will be told\./);
    assert.doesNotMatch(text, /Cancelling closes that page/);
    assert.doesNotMatch(text, /refund/);
});

test('a card page known to be beyond checking is said not to be closable, on its holder\'s account, until it expires', () => {
    const text = cancelQuestion(facts({ cardPageOpened: true, pageUnreachable: true, chargedThrough: 'MAS Youth' })).lines.join(' ');

    assert.match(text, /opened for it on MAS Youth's Stripe account, which no longer lets us check or close it\. Cancelling cannot close that page, so it may still take a payment until it expires\./);
    assert.doesNotMatch(text, /tries to close/);
});

test('a card payment taken through another organisation is refunded only by that organisation, as the server words it', () => {
    const text = cancelQuestion(facts({ paymentState: 'paid', paidAmount: '$60.00', chargedThrough: 'MAS Youth' })).lines.join(' ');

    assert.match(text, /paid by card \(\$60\.00\) through MAS Youth's Stripe account\. Cancelling does not refund it: only MAS Youth can refund it, in its own Stripe dashboard/);
});

test('a card payment on this organisation\'s own account is refunded in the account it was charged to', () => {
    const text = cancelQuestion(facts({ paymentState: 'paid', paidAmount: '$60.00' })).lines.join(' ');

    assert.match(text, /refund it in the Stripe account it was charged to if it should not stand/);
    assert.doesNotMatch(text, /only .* can refund/);
});

test('a card payment already refunded in full is not sent to Stripe to be refunded again', () => {
    const text = cancelQuestion(facts({ paymentState: 'paid', paidAmount: '$60.00', refundedAmount: '$60.00', refundedInFull: true })).lines.join(' ');

    assert.match(text, /has since been refunded in full\. Cancelling does not change the payment\./);
    assert.doesNotMatch(text, /refund it in|can refund it/);
});

test('a card payment refunded in part says how much, and that the rest is not refunded by cancelling', () => {
    const text = cancelQuestion(facts({ paymentState: 'paid', paidAmount: '$60.00', refundedAmount: '$2.04' })).lines.join(' ');

    assert.match(text, /and \$2\.04 of it has since been refunded\. Cancelling does not refund the rest: refund it in the Stripe account/);
});

test('an external or office payment stays on record and nothing is refunded', () => {
    const text = cancelQuestion(facts({ paymentMethod: 'external', paymentState: 'paid', paidAmount: '$15.00' })).lines.join(' ');

    assert.match(text, /Its payment \(\$15\.00\) stays on record\. Nothing is refunded\./);
});

test('a registration already checked in says so, and does not say it can be checked in', () => {
    const text = cancelQuestion(facts({ checkedIn: true })).lines.join(' ');

    assert.match(text, /It has already been checked in; that stays on record\. While cancelled it cannot be paid/);
    assert.doesNotMatch(text, /can still be checked in|cannot be checked in/);
});

test('every cancel question ends by saying it stays listed and another status restores it', () => {
    for (const over of [{}, { paymentEnabled: false, paymentMethod: null, hasPayment: false }, { reservesDates: true, capacity: 10 }] as Partial<CancelFacts>[]) {
        const lines = cancelQuestion(facts(over)).lines;
        assert.equal(lines[lines.length - 1], 'It stays in this list, and choosing another status restores it.');
    }
});

test('the cancel question\'s buttons say what they do, never a bare "Cancel" or "OK"', () => {
    const question = cancelQuestion(facts());

    assert.equal(question.confirmText, 'Cancel registration');
    assert.equal(question.keepText, 'Keep it');
});

test('a registration with a payment is not told a delete would free its place, since it can never be deleted', () => {
    const paying = cancelQuestion(facts({ capacity: 200, hasPayment: true })).lines.join(' ');
    assert.match(paying, /still counts towards the form's limit of 200, and a registration with a payment cannot be deleted, so cancelling does not free a place\./);
    assert.doesNotMatch(paying, /only deleting/);

    const free = cancelQuestion(facts({ capacity: 200, hasPayment: false, paymentEnabled: false, paymentMethod: null })).lines.join(' ');
    assert.match(free, /still counts towards the form's limit of 200; only deleting a registration frees a place\./);
});

test('the cancel dialog sets the title as text, never as HTML: it carries the respondent\'s own name', () => {
    const question = cancelQuestion(facts({ name: '<img src=x onerror=alert(1)>' }));
    const options = cancelDialogOptions(question, 'body') as Record<string, unknown>;

    assert.equal(options.titleText, question.title);
    assert.equal('title' in options, false);
    assert.equal(options.confirmButtonText, 'Cancel registration');
    assert.equal(options.cancelButtonText, 'Keep it');
    assert.equal(options.focusCancel, true);
});

test('the row select is named after its registration: "Status for registration #id, name"', () => {
    assert.equal(statusSelectLabel(121, 'Reema Bianouni'), 'Status for registration #121, Reema Bianouni');
    assert.equal(statusSelectLabel(7, null), 'Status for registration #7');
});

test('a cancel or restore re-reads the cash, and the reserved dates on a form that reserves them, open or folded', () => {
    assert.deepEqual(refreshesAfterStatusChange('new', 'cancelled', true), { cash: true, reservations: true });
    assert.deepEqual(refreshesAfterStatusChange('cancelled', 'confirmed', true), { cash: true, reservations: true });
    assert.deepEqual(refreshesAfterStatusChange('cancelled', 'confirmed', false), { cash: true, reservations: false });
    assert.deepEqual(refreshesAfterStatusChange('new', 'confirmed', true), { cash: false, reservations: false });
});

test('cancelling a cash registration keeps the cash on record in the cancelled column', () => {
    const text = cancelQuestion(facts({ paymentMethod: 'cash', paymentState: 'paid', paidAmount: '$45.00' })).lines.join(' ');

    assert.match(text, /The cash \(\$45\.00\) stays on record and moves to its holder's "cancelled" column/);
});

test('the date and capacity sentences appear only on forms that reserve dates or have a limit', () => {
    const plain = cancelQuestion(facts()).lines.join(' ');
    assert.doesNotMatch(plain, /date/);
    assert.doesNotMatch(plain, /limit/);

    const both = cancelQuestion(facts({ reservesDates: true, capacity: 200 })).lines.join(' ');
    assert.match(both, /that date is offered to others again/);
    assert.match(both, /still counts towards the form's limit of 200/);
});

test('a form that takes no payment is not told about check-in or payment', () => {
    const text = cancelQuestion(facts({ paymentEnabled: false, paymentMethod: null })).lines.join(' ');

    assert.doesNotMatch(text, /checked in|paid|refund/);
    assert.match(text, /Nobody is emailed/);
});

test('an optimistic save shows the new status while it runs and keeps it once saved', async () => {
    const row = { status: 'new' as const } as { status: 'new' | 'confirmed' };
    let seenDuringSave: string | null = null;

    const answer = await saveStatusOptimistically(row, 'confirmed', async () => {
        seenDuringSave = row.status;
        return 'saved';
    });

    assert.equal(seenDuringSave, 'confirmed');
    assert.equal(row.status, 'confirmed');
    assert.equal(answer, 'saved');
});

test('a refused save puts the old status back and passes the refusal on', async () => {
    const row: { status: 'cancelled' | 'new' } = { status: 'cancelled' };
    const refusal = new Error('That date has since been reserved by someone else.');

    await assert.rejects(saveStatusOptimistically(row, 'new', async () => { throw refusal; }), refusal);
    assert.equal(row.status, 'cancelled');
});

// --- When the list saves: statusSelectController() --------------------------------

/** A select, its row, and a record of every call the controller makes. */
function harness(options: { status?: FormResponseStatus; ask?: boolean; refuse?: unknown; busy?: boolean } = {}) {
    const row: StatusRow = { id: 121, status: options.status ?? 'new' };
    const select = { value: row.status as string };
    const calls: string[] = [];
    const saves: { next: FormResponseStatus; expected: FormResponseStatus }[] = [];
    let lockedBy: number | null = options.busy ? 99 : null;
    const held = new Map<number, FormResponseStatus>();

    const controller = statusSelectController<{ status: FormResponseStatus }>({
        held,
        isBusy: () => lockedBy !== null,
        lock: id => { lockedBy = id; calls.push(id === null ? 'unlock' : `lock ${id}`); },
        saving: id => { calls.push(id === null ? 'saving off' : `saving ${id}`); },
        ask: async () => { calls.push(`ask while ${lockedBy === row.id ? 'locked' : 'UNLOCKED'}`); return options.ask ?? true; },
        save: async (_row, next, expected) => {
            calls.push(`PUT ${next} while ${lockedBy === row.id ? 'locked' : 'UNLOCKED'}, row shows ${row.status}`);
            saves.push({ next, expected });
            if (options.refuse) throw options.refuse;
            return { status: next };
        },
        saved: async (_row, from, next, result) => { calls.push(`applied ${result.status} (${from} -> ${next}) while ${lockedBy === row.id ? 'locked' : 'UNLOCKED'}`); },
        refused: async (_row, error) => { calls.push(`refused: ${(error as Error).message} while ${lockedBy === row.id ? 'locked' : 'UNLOCKED'}`); }
    });

    const key = (name: string, extra: { altKey?: boolean } = {}) => {
        let prevented = false;
        const done = controller.keydown(row, { key: name, ...extra, preventDefault: () => { prevented = true; } }, select);
        return { done, prevented: () => prevented };
    };

    /** A closed select on Windows: the key moves the value and fires change at once. */
    const arrowTo = async (status: FormResponseStatus, name = 'ArrowDown') => {
        await key(name).done;
        select.value = status;
        await controller.change(row, select);
    };

    /** Picked from the open list with the mouse. */
    const pick = async (status: FormResponseStatus) => {
        controller.pointerdown();
        select.value = status;
        return controller.change(row, select);
    };

    return { row, select, calls, saves, held, controller, key, arrowTo, pick, lockedBy: () => lockedBy };
}

test('arrow and letter keys move a closed select without choosing; Alt+ArrowDown and Space open it', () => {
    for (const key of ['ArrowUp', 'ArrowDown', 'Home', 'End', 'PageDown', 'c']) {
        assert.equal(movesWithoutCommitting({ key }), true, key);
    }
    for (const event of [{ key: 'ArrowDown', altKey: true }, { key: ' ' }, { key: 'Enter' }, { key: 'Tab' }, { key: 'F4' }]) {
        assert.equal(movesWithoutCommitting(event), false, JSON.stringify(event));
    }
});

test('the commit rule: a keyboard step is held, a choice into cancelled is asked, any other choice is saved', () => {
    assert.equal(decideInlineChange({ from: 'new', next: 'confirmed', busy: false, keyboard: true }), 'hold');
    assert.equal(decideInlineChange({ from: 'new', next: 'cancelled', busy: false, keyboard: true }), 'hold');
    assert.equal(decideInlineChange({ from: 'new', next: 'cancelled', busy: false, keyboard: false }), 'ask');
    assert.equal(decideInlineChange({ from: 'cancelled', next: 'new', busy: false, keyboard: false }), 'save');
    assert.equal(decideInlineChange({ from: 'new', next: 'new', busy: false, keyboard: false }), 'ignore');
    assert.equal(decideInlineChange({ from: 'new', next: 'confirmed', busy: true, keyboard: false }), 'ignore');
});

test('arrowing through the statuses saves nothing on the way, and Enter saves the one it stopped on', async () => {
    const h = harness({ status: 'cancelled' });

    await h.arrowTo('new', 'ArrowUp');
    await h.arrowTo('confirmed');
    await h.arrowTo('waitlisted');

    assert.deepEqual(h.saves, [], 'a cancelled registration is not restored by passing New');
    assert.equal(h.row.status, 'cancelled');
    assert.equal(h.held.get(121), 'waitlisted', 'the step is shown, held');

    const enter = h.key('Enter');
    await enter.done;

    assert.equal(enter.prevented(), true);
    assert.deepEqual(h.saves, [{ next: 'waitlisted', expected: 'cancelled' }]);
    assert.equal(h.row.status, 'waitlisted');
    assert.equal(h.held.has(121), false);
});

test('a keyboard step is saved when focus leaves the select, and Escape puts the saved status back instead', async () => {
    const tabbed = harness();
    await tabbed.arrowTo('confirmed');
    await tabbed.controller.blur(tabbed.row, tabbed.select);
    assert.deepEqual(tabbed.saves, [{ next: 'confirmed', expected: 'new' }]);

    const escaped = harness();
    await escaped.arrowTo('confirmed');
    const escape = escaped.key('Escape');
    await escape.done;
    await escaped.controller.blur(escaped.row, escaped.select);

    assert.equal(escape.prevented(), true);
    assert.deepEqual(escaped.saves, []);
    assert.equal(escaped.select.value, 'new');
    assert.equal(escaped.held.has(121), false);
});

test('arrowing back to the saved status holds nothing and saves nothing', async () => {
    const h = harness();
    await h.arrowTo('confirmed');
    await h.arrowTo('new', 'ArrowUp');
    await h.controller.blur(h.row, h.select);

    assert.equal(h.held.has(121), false);
    assert.deepEqual(h.saves, []);
});

test('a status picked from the open list is saved at once, under the lock, with the status the list showed', async () => {
    const h = harness();
    await h.pick('confirmed');

    assert.deepEqual(h.calls, ['lock 121', 'saving 121', 'PUT confirmed while locked, row shows confirmed', 'saving off', 'applied confirmed (new -> confirmed) while locked', 'unlock']);
    assert.deepEqual(h.saves, [{ next: 'confirmed', expected: 'new' }]);
});

test('a status chosen after Alt+ArrowDown opened the list is a choice, and is saved', async () => {
    const h = harness();
    await h.key('ArrowDown', { altKey: true }).done;
    h.select.value = 'waitlisted';
    await h.controller.change(h.row, h.select);

    assert.deepEqual(h.saves, [{ next: 'waitlisted', expected: 'new' }]);
});

test('a cancel is asked, under the lock, before any PUT', async () => {
    const h = harness();
    await h.pick('cancelled');

    assert.equal(h.calls[0], 'lock 121');
    assert.equal(h.calls[1], 'ask while locked');
    assert.match(h.calls[3], /^PUT cancelled/);
});

test('"Keep it" sends no PUT, puts the select back on the old status and releases the lock', async () => {
    const h = harness({ ask: false });
    await h.pick('cancelled');

    assert.deepEqual(h.saves, []);
    assert.equal(h.select.value, 'new');
    assert.equal(h.row.status, 'new');
    assert.deepEqual(h.calls, ['lock 121', 'ask while locked', 'unlock']);
    assert.equal(h.lockedBy(), null);
});

test('arrowing into Cancelled and pressing Enter still asks first', async () => {
    const h = harness({ ask: false });
    await h.arrowTo('cancelled', 'c');
    assert.deepEqual(h.calls, [], 'the step alone asks nothing');

    await h.key('Enter').done;

    assert.equal(h.calls[1], 'ask while locked');
    assert.deepEqual(h.saves, []);
    assert.equal(h.select.value, 'new');
});

test('a refused save puts the row and the select back, and says why under the lock', async () => {
    const h = harness({ status: 'cancelled', refuse: new Error('That date has since been reserved by someone else.') });
    await h.pick('new');

    assert.equal(h.row.status, 'cancelled');
    assert.equal(h.select.value, 'cancelled');
    assert.deepEqual(h.calls.slice(-2), ['refused: That date has since been reserved by someone else. while locked', 'unlock']);
});

test('while another save or row action holds the lock, a change is put back and nothing is saved', async () => {
    const h = harness({ busy: true });
    await h.pick('confirmed');

    assert.deepEqual(h.saves, []);
    assert.equal(h.select.value, 'new');
});

test('a follow-up the answer asks for (closing the card page again) runs only once the lock is released', async () => {
    const row: StatusRow = { id: 5, status: 'new' };
    const order: string[] = [];
    let locked = false;

    const controller = statusSelectController<string>({
        held: new Map(),
        isBusy: () => locked,
        lock: id => { locked = id !== null; },
        saving: () => {},
        ask: async () => true,
        save: async () => 'saved',
        saved: async () => () => { order.push(`reclose while ${locked ? 'locked' : 'unlocked'}`); return Promise.resolve(); },
        refused: async () => {}
    });

    controller.pointerdown();
    await controller.change(row, { value: 'cancelled' });

    assert.deepEqual(order, ['reclose while unlocked']);
});

// --- The view is wired to all of the above -------------------------------------------

const view = readFileSync(new URL('../views/dashboard/FormResponsesView.vue', import.meta.url), 'utf8');
const store = readFileSync(new URL('../stores/masjid/formResponsesStore.ts', import.meta.url), 'utf8');
const statusSelectTag = view.match(/<select\s+class="form-select form-select-sm status-select"[\s\S]*?>/)?.[0] ?? '';

test('the row select is disabled while any row action or status save holds the lock, and named after its registration', () => {
    assert.notEqual(statusSelectTag, '', 'the row status select is where the test expects it');
    assert.match(statusSelectTag, /:disabled="busyRowId !== null"/);
    assert.match(statusSelectTag, /:aria-label="statusSelectLabel\(response\.id, response\.respondent_name\)"/);
});

test('the row select saves through the controller, never straight from a change event', () => {
    for (const event of ['keydown', 'change', 'blur']) {
        assert.match(statusSelectTag, new RegExp(`@${event}="onStatusEvent\\('${event}', response, \\$event\\)"`));
    }
    assert.match(statusSelectTag, /@pointerdown="statusSelect\.pointerdown\(\)"/);
    assert.match(view, /const statusSelect = statusSelectController<FormResponseActionResult>\(\{/);
});

test('the view locks with busyRowId, applies the saved row, sends the shown status, and re-reads the dates even when folded', () => {
    assert.match(view, /lock: rowId => \{ busyRowId\.value = rowId; \}/);
    assert.match(view, /isBusy: \(\) => busyRowId\.value !== null/);
    assert.match(view, /updateResponse\(selectedFormId\.value, row\.id, \{ status: next, expected_status: expected \}\)/);
    assert.match(view, /saved: async \(row, from, next, result\) => \{\s*applyRow\(result\.data\);/);
    assert.match(view, /if \(moves\.reservations\) loadReservations\(\);/);
    assert.match(view, /Swal\.fire\(cancelDialogOptions\(question, body\)\)/);
});

test('the row\'s View button waits while that row is saving, and the detail starts from the status the server has', () => {
    assert.match(view, /:disabled="busyRowId === response\.id"\s*@click="openDetail\(response\)"/);
    assert.match(view, /if \(editStatus\.value === response\.status\) editStatus\.value = full\.status;/);
});

test('the store\'s fallback messages for the door say "check in", as the screen does', () => {
    assert.match(store, /'Could not check this registration in\.'/);
    assert.match(store, /'Could not undo the check-in\.'/);
    assert.doesNotMatch(store, /mark this registration collected|undo the collection/);
});
