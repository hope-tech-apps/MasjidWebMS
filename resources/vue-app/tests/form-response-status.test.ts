/**
 * The status select on each row of the registrations list
 * (views/dashboard/formResponseStatus.ts): its labels and order, which changes are asked
 * first, what the cancel question says, the optimistic save that puts the old status back
 * when the server refuses, and statusSelectController(), which decides when a status is
 * saved (chosen, never an arrow-key step) and holds the row lock from question to answer.
 * Then the row's Delete: which registrations it deletes, which it sends to be cancelled
 * first and which it never deletes (deleteStep(), the mirror of
 * FormResponsesController::destroy()), and what its question says.
 * The last tests read FormResponsesView.vue, the store and the controller as text, as
 * newsletter-blocks.test.ts does, to pin that the view is wired to all of it and says the
 * server's own words: the SPA has no DOM test harness.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    DELETE_CANCEL_FIRST,
    DELETE_REFUSED,
    FALLBACK_STATUSES,
    asksBeforeStatusChange,
    cancelDialogOptions,
    cancelQuestion,
    cardPageOnRecord,
    decideInlineChange,
    deleteBlocked,
    deleteButtonLabel,
    deleteDialogOptions,
    deleteFailure,
    deleteQuestion,
    deleteStep,
    movesWithoutCommitting,
    refreshesAfterStatusChange,
    registrationName,
    saveStatusOptimistically,
    statusChoices,
    statusLabel,
    statusSelectController,
    statusSelectLabel,
    type CancelFacts,
    type DeleteFacts,
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
    // An unpaid card registration, the default here, is one Delete sends to be cancelled first.
    deleteStep: 'cancel-first',
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
    const variants: Partial<CancelFacts>[] = [
        { paymentState: 'paid', deleteStep: 'never' },
        { paymentEnabled: false, paymentMethod: null, deleteStep: 'delete' },
        { paymentMethod: 'cash', paymentState: 'paid', deleteStep: 'never', reservesDates: true, capacity: 10 }
    ];

    for (const over of variants) {
        const lines = cancelQuestion(facts(over)).lines;
        assert.equal(lines[lines.length - 1], 'It stays in this list, and choosing another status restores it.');
    }
});

test('cancelling a registration that was never paid ends by saying the cancel is what lets it be deleted', () => {
    for (const over of [{}, { paymentMethod: 'office' }, { cardPageOpened: true, reservesDates: true, capacity: 10 }] as Partial<CancelFacts>[]) {
        const lines = cancelQuestion(facts(over)).lines;
        assert.equal(
            lines[lines.length - 1],
            'It stays in this list, and choosing another status restores it. It was never paid, so once it is cancelled it can also be deleted.'
        );
    }
});

test('a never-paid registration whose card page is known to be beyond checking is not promised a delete the server will refuse', () => {
    const last = 'It stays in this list, and choosing another status restores it. '
        + 'It was never paid, but it stays cancelled and cannot be deleted unless Stripe can be asked about its card payment page.';

    // As the list sends it: the page opened on a holder's account that is no longer checkable.
    const pinned = cancelQuestion(facts({ cardPageOpened: true, pageUnreachable: true, chargedThrough: 'Holder Organisation', capacity: 200 })).lines;
    assert.equal(pinned[pinned.length - 1], last);
    assert.doesNotMatch(pinned.join(' '), /can also be deleted|deleting it after the cancel frees/);
    // Nor the place a delete would free: only that a delete is what frees one.
    assert.ok(pinned.includes('It still counts towards the form\'s limit of 200; only deleting a registration frees a place.'));

    // The row's own flag decides, so a form that has lost its payment settings (no card
    // lines, `cardPageOpened` false) is told the same.
    const lostSettings = cancelQuestion(facts({ paymentEnabled: false, pageUnreachable: true })).lines;
    assert.equal(lostSettings[lostSettings.length - 1], last);

    // A page Stripe can still be asked about keeps the promise, and so does a paid row its own line.
    const checkable = cancelQuestion(facts({ cardPageOpened: true })).lines;
    assert.match(checkable[checkable.length - 1], /so once it is cancelled it can also be deleted\.$/);
    const paid = cancelQuestion(facts({ paymentState: 'paid', deleteStep: 'never', pageUnreachable: true })).lines;
    assert.equal(paid[paid.length - 1], 'It stays in this list, and choosing another status restores it.');
});

test('the cancel question\'s buttons say what they do, never a bare "Cancel" or "OK"', () => {
    const question = cancelQuestion(facts());

    assert.equal(question.confirmText, 'Cancel registration');
    assert.equal(question.keepText, 'Keep it');
});

test('the capacity line says what frees the place: nothing for a paid registration, a delete after the cancel for one never paid', () => {
    // A payment on record: never deleted, so it is not pointed at a delete the server refuses.
    const paid = cancelQuestion(facts({ capacity: 200, paymentState: 'paid', deleteStep: 'never' })).lines.join(' ');
    assert.match(paid, /still counts towards the form's limit of 200, and a registration with a payment cannot be deleted, so cancelling does not free a place\./);
    assert.doesNotMatch(paid, /only deleting|after the cancel/);

    // Never paid: the cancel alone frees nothing, and the delete it makes possible does.
    const neverPaid = cancelQuestion(facts({ capacity: 200, deleteStep: 'cancel-first' })).lines.join(' ');
    assert.match(neverPaid, /still counts towards the form's limit of 200 while it is cancelled; deleting it after the cancel frees its place\./);
    assert.doesNotMatch(neverPaid, /cannot be deleted|only deleting/);

    // No payment method at all: as it always read.
    const free = cancelQuestion(facts({ capacity: 200, deleteStep: 'delete', paymentEnabled: false, paymentMethod: null })).lines.join(' ');
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
    // No payment method, so Delete was always available: nothing about payment in any line.
    const text = cancelQuestion(facts({ paymentEnabled: false, paymentMethod: null, deleteStep: 'delete' })).lines.join(' ');

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

// --- Delete: deleteStep(), deleteBlocked(), deleteQuestion() ----------------------

test('a registration with no payment method is deleted whatever its status, as it always was', () => {
    for (const status of FALLBACK_STATUSES) {
        assert.equal(deleteStep({ status, payment_method: null, payment_status: null }), 'delete', status);
    }

    // A Wix-fallback row reads "unpaid" on the badge (payment_state) and still has no method.
    assert.equal(deleteStep({ status: 'new' }), 'delete');
});

test('a registration that was never paid is sent to be cancelled first, card or office, and deleted once it is', () => {
    for (const payment_method of ['online', 'office']) {
        for (const status of ['new', 'confirmed', 'waitlisted']) {
            assert.equal(deleteStep({ status, payment_method, payment_status: 'unpaid' }), 'cancel-first', `${payment_method}, ${status}`);
        }

        assert.equal(deleteStep({ status: 'cancelled', payment_method, payment_status: 'unpaid' }), 'delete', payment_method);
    }
});

test('a registration a payment was recorded on is never deleted, cancelled or not', () => {
    for (const payment_method of ['online', 'cash', 'external']) {
        for (const status of FALLBACK_STATUSES) {
            assert.equal(deleteStep({ status, payment_method, payment_status: 'paid' }), 'never', `${payment_method}, ${status}`);
        }
    }
});

test('a state nothing writes is "never", not "delete": the rule names what it allows', () => {
    // Cash or an external payment that reads unpaid, a method with no status, a method this
    // screen does not know: each goes to a person, as FormResponse::neverRecordedAPayment() sends it.
    assert.equal(deleteStep({ status: 'cancelled', payment_method: 'cash', payment_status: 'unpaid' }), 'never');
    assert.equal(deleteStep({ status: 'cancelled', payment_method: 'external', payment_status: 'unpaid' }), 'never');
    assert.equal(deleteStep({ status: 'cancelled', payment_method: 'online', payment_status: null }), 'never');
    assert.equal(deleteStep({ status: 'cancelled', payment_method: 'online' }), 'never');
    assert.equal(deleteStep({ status: 'cancelled', payment_method: 'voucher', payment_status: 'unpaid' }), 'never');
});

test('the rule reads payment_status, never payment_state: a form that lost its payment settings sends that null on every row', () => {
    // As serialize() sends them once Form::hasPaymentSettings() is false.
    const neverPaid = { status: 'cancelled', payment_method: 'online', payment_status: 'unpaid', payment_state: null };
    const paid = { status: 'cancelled', payment_method: 'online', payment_status: 'paid', payment_state: null };

    assert.equal(deleteStep(neverPaid), 'delete');
    assert.equal(deleteStep({ ...neverPaid, status: 'new' }), 'cancel-first');
    assert.equal(deleteStep(paid), 'never', 'a paid registration is never offered a delete because its badge went blank');
});

test('the rule reads status, method and payment status alone, by choice: the row carries more, and the server refuses on it', () => {
    // An unpaid row carrying a trace of a payment is a state nothing writes. The screen
    // offers its delete; FormResponse::neverRecordedAPayment() refuses it on the locked
    // row, and the screen shows that sentence.
    const row = { status: 'cancelled', payment_method: 'online', payment_status: 'unpaid' };

    for (const trace of [{ paid_at: '2027-01-01T00:00:00+00:00' }, { stripe_payment_intent_id: 'pi_test_stray_0001' }, { charge_flag: 'refunded' }]) {
        assert.equal(deleteStep({ ...row, ...trace }), 'delete', Object.keys(trace)[0]);
    }
});

test('a dimmed Delete says why in the server\'s own words, and a live one says nothing', () => {
    assert.deepEqual(deleteBlocked('never'), { title: 'This registration cannot be deleted', text: DELETE_REFUSED });
    assert.deepEqual(deleteBlocked('cancel-first'), { title: 'Cancel it first', text: DELETE_CANCEL_FIRST });
    assert.equal(deleteBlocked('delete'), null);
});

test('the Delete button is named for what it does, why it will not, or that it is deleting', () => {
    assert.equal(deleteButtonLabel(121, null, false), 'Delete registration #121');
    assert.equal(deleteButtonLabel(121, deleteBlocked('cancel-first'), false), `Delete is not available: ${DELETE_CANCEL_FIRST}`);
    assert.equal(deleteButtonLabel(121, deleteBlocked('never'), false), `Delete is not available: ${DELETE_REFUSED}`);
    // Only a live Delete ever sends a request, but the name never lags behind the spinner.
    assert.equal(deleteButtonLabel(121, null, true), 'Deleting registration #121');
    // While its request runs "deleting" wins over a reason the row has just been given.
    assert.equal(deleteButtonLabel(121, deleteBlocked('never'), true), 'Deleting registration #121');
});

const deleteFacts = (over: Partial<DeleteFacts> = {}): DeleteFacts => ({
    id: 121,
    name: 'Test Registrant',
    paymentMethod: 'online',
    cardPageOpened: false,
    reservesDates: false,
    capacity: null,
    ...over
});

test('the delete question for a never-paid registration names it, says it was never paid and that it cannot be undone', () => {
    const question = deleteQuestion(deleteFacts());

    assert.equal(question.title, 'Delete registration #121, Test Registrant?');
    assert.deepEqual(question.lines, [
        'It is cancelled and was never paid.',
        'If a card payment page was opened for it, that page is checked first; if it was paid, nothing is deleted.',
        'Its answers and any files uploaded with it are removed.',
        'This cannot be undone.'
    ]);
    assert.equal(question.confirmText, 'Delete registration');
    assert.equal(question.keepText, 'Keep it');
});

test('deleting an office registration asks that the office received no payment for it', () => {
    const text = deleteQuestion(deleteFacts({ paymentMethod: 'office' })).lines.join(' ');

    assert.match(text, /It was to be paid at the office\. Delete it only if the office received no payment for it\./);
    assert.doesNotMatch(text, /card payment page/);
});

test('deleting a card registration whose page was opened says the page is checked first, and nothing is deleted if it was paid', () => {
    const opened = deleteQuestion(deleteFacts({ cardPageOpened: true })).lines.join(' ');
    assert.match(opened, /Its card payment page is checked first; if it was paid, nothing is deleted\./);
    assert.doesNotMatch(opened, /If a card payment page was opened/);
});

test('a card registration whose row does not say a page was opened is still told the page is checked first, if there is one', () => {
    // A form that has lost its payment settings sends card_page_opened false on every row,
    // and the server still asks Stripe about a page such a row carries.
    const unsaid = deleteQuestion(deleteFacts({ cardPageOpened: false })).lines.join(' ');
    assert.match(unsaid, /If a card payment page was opened for it, that page is checked first; if it was paid, nothing is deleted\./);
    assert.doesNotMatch(unsaid, /Its card payment page is checked first/);

    // Never said of a registration that has no card leg at all.
    for (const paymentMethod of ['office', null] as const) {
        assert.doesNotMatch(deleteQuestion(deleteFacts({ paymentMethod })).lines.join(' '), /card payment page/);
    }
});

test('what the row says about a card page is read from its own two flags', () => {
    assert.equal(cardPageOnRecord({ card_page_opened: true, page_unreachable: false }), true);
    // Only ever true of a page on the row, and not computed from the form's payment reading.
    assert.equal(cardPageOnRecord({ card_page_opened: false, page_unreachable: true }), true);
    assert.equal(cardPageOnRecord({ card_page_opened: false, page_unreachable: false }), false);
    // An API older than either flag says nothing.
    assert.equal(cardPageOnRecord({}), false);
});

test('the delete question says what goes with the registration: its reserved date and its place, only where the form has them', () => {
    const plain = deleteQuestion(deleteFacts()).lines.join(' ');
    assert.doesNotMatch(plain, /date|limit/);

    const both = deleteQuestion(deleteFacts({ reservesDates: true, capacity: 40 })).lines;
    assert.ok(both.includes('If it reserved a date, that reservation is removed with it.'));
    assert.ok(both.includes('Its place towards the form\'s limit of 40 is freed.'));
    assert.equal(both[both.length - 1], 'This cannot be undone.');
});

test('a registration with no payment method is asked the question it always was', () => {
    const question = deleteQuestion(deleteFacts({ paymentMethod: null, cardPageOpened: false, reservesDates: true, capacity: 40 }));

    assert.equal(question.title, 'Are you sure?');
    assert.deepEqual(question.lines, ['Delete the response from Test Registrant? This cannot be undone.']);
    assert.equal(question.confirmText, 'Yes, delete it!');

    assert.deepEqual(deleteQuestion(deleteFacts({ paymentMethod: null, name: '  ' })).lines, ['Delete the response from this respondent? This cannot be undone.']);
});

test('the delete dialog sets the title as text and starts on the button that deletes nothing', () => {
    const question = deleteQuestion(deleteFacts({ name: '<img src=x onerror=alert(1)>' }));
    const options = deleteDialogOptions(question, 'body') as Record<string, unknown>;

    assert.equal(options.titleText, question.title);
    assert.equal('title' in options, false);
    assert.equal(options.html, 'body');
    assert.equal(options.focusCancel, true);
    assert.equal(options.confirmButtonText, 'Delete registration');
    assert.equal(options.cancelButtonText, 'Keep it');
});

test('a refused delete shows the server\'s sentence, and a registration already gone says so instead of "Request failed."', () => {
    const paidOnStripe = 'This registration was paid by card, so it was not deleted. If it still shows as unpaid later, check this payment in Stripe. Refund it in Stripe if it should not stand.';

    assert.deepEqual(deleteFailure(422, paidOnStripe), { title: 'Not deleted', text: paidOnStripe });
    assert.deepEqual(deleteFailure(503, 'Try again in a moment.'), { title: 'Not deleted', text: 'Try again in a moment.' });
    assert.deepEqual(deleteFailure(undefined, 'Failed to delete the response.'), { title: 'Not deleted', text: 'Failed to delete the response.' });

    const gone = deleteFailure(404, 'Request failed.');
    assert.equal(gone.title, 'Already deleted');
    assert.match(gone.text, /had already been deleted/);
    assert.doesNotMatch(gone.text, /Request failed/);
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

// --- The row's Delete is wired to deleteStep(), and says the server's words -------

const controller = readFileSync(new URL('../../../app/Http/Controllers/AdminDashboard/FormResponsesController.php', import.meta.url), 'utf8');
const deleteButtonTag = view.match(/<button\s+class="btn btn-outline-danger"[\s\S]*?>/)?.[0] ?? '';
const confirmDeleteBody = view.match(/const confirmDelete = async[\s\S]*?\n};\n/)?.[0] ?? '';

test('the two refusals the screen shows without asking are the controller\'s own, word for word', () => {
    assert.ok(controller.includes(`private const DELETE_PAID = '${DELETE_REFUSED}';`), 'the paid sentence');
    assert.ok(controller.includes(`private const DELETE_CANCEL_FIRST = '${DELETE_CANCEL_FIRST}';`), 'the cancel-first sentence');

    // And the view carries no copy of either: one place to change, beside the pin above.
    assert.equal(view.includes(DELETE_REFUSED), false);
    assert.equal(view.includes(DELETE_CANCEL_FIRST), false);
});

test('the Delete button is dimmed, named and explained by deleteStep(), and stays clickable so the reason can be read', () => {
    assert.notEqual(deleteButtonTag, '', 'the row Delete button is where the test expects it');
    assert.match(view, /const deleteBlockedFor = \(row: FormResponseRow\) => deleteBlocked\(deleteStep\(row\)\);/);
    assert.match(deleteButtonTag, /:class="\{ 'opacity-50': deleteBlockedFor\(response\) !== null \}"/);
    assert.match(deleteButtonTag, /:aria-disabled="deleteBlockedFor\(response\) !== null \? 'true' : undefined"/);
    assert.match(deleteButtonTag, /:title="deleteBlockedFor\(response\)\?\.text \?\? 'Delete'"/);
    assert.match(deleteButtonTag, /:aria-label="deleteButtonLabel\(response\.id, deleteBlockedFor\(response\), deletingId === response\.id\)"/);
    assert.match(deleteButtonTag, /@click="confirmDelete\(response\)"/);
    assert.doesNotMatch(deleteButtonTag, /:disabled=/);

    // The old rule, "any payment method at all", is gone from the view.
    assert.doesNotMatch(view, /isMoneyRow/);
});

test('confirmDelete asks nothing of a dimmed row, and holds the row lock from its question to the answer', () => {
    assert.notEqual(confirmDeleteBody, '', 'confirmDelete is where the test expects it');

    const at = (needle: string): number => {
        const index = confirmDeleteBody.indexOf(needle);
        assert.notEqual(index, -1, `confirmDelete has: ${needle}`);
        return index;
    };

    const guard = at('if (busyRowId.value !== null) return;');
    const blocked = at('const blocked = deleteBlockedFor(response);');
    const lock = at('busyRowId.value = response.id;');
    const question = at('Swal.fire(deleteDialogOptions(question, questionBody(question.lines)))');
    const request = at('formResponsesStore.deleteResponse(selectedFormId.value, response.id)');
    const release = at('busyRowId.value = null;');

    assert.ok(guard < blocked && blocked < lock && lock < question && question < request && request < release);
    assert.match(confirmDeleteBody, /\} finally \{\s*deletingId\.value = null;\s*busyRowId\.value = null;\s*\}/);
});

test('while its DELETE runs the pressed row\'s Delete shows a spinner and is aria-busy, and a second press stays harmless', () => {
    // The sign: the status select's spinner in place of the bin, on that row alone.
    assert.match(deleteButtonTag, /:aria-busy="deletingId === response\.id \? 'true' : undefined"/);
    assert.match(
        view,
        /@click="confirmDelete\(response\)"\s*>\s*<span v-if="deletingId === response\.id" class="spinner-border spinner-border-sm" role="status">\s*<span class="visually-hidden">Deleting registration #\{\{ response\.id \}\}<\/span>\s*<\/span>\s*<i v-else class="bi bi-trash" aria-hidden="true"><\/i>\s*<\/button>/
    );

    // Shown from the confirmed question to the answer, and never left on.
    const asked = confirmDeleteBody.indexOf('if (!choice.isConfirmed || !selectedFormId.value) return;');
    const shown = confirmDeleteBody.indexOf('deletingId.value = response.id;');
    const request = confirmDeleteBody.indexOf('formResponsesStore.deleteResponse(');
    const hidden = confirmDeleteBody.indexOf('deletingId.value = null;');

    assert.ok(asked !== -1 && asked < shown && shown < request && request < hidden);
    assert.match(view, /const deletingId = ref<number \| null>\(null\);/);

    // The second press is still turned away before anything else: the button is not
    // disabled, so the guard is what keeps it to one request.
    assert.ok(confirmDeleteBody.indexOf('if (busyRowId.value !== null) return;') < confirmDeleteBody.indexOf('const blocked'));
    assert.doesNotMatch(deleteButtonTag, /:disabled=/);
});

test('the delete question takes what the row itself says about a card page, never payment_state', () => {
    assert.match(confirmDeleteBody, /cardPageOpened: cardPageOnRecord\(response\),/);
    assert.doesNotMatch(confirmDeleteBody, /payment_state|cardPageStarted/);

    // And the row does carry what deleteStep() chooses not to read (its doc comment says so).
    for (const column of ['paid_at', 'stripe_payment_intent_id', 'charge_flag']) {
        assert.match(controller, new RegExp(`'${column}' => `), `serialize() sends ${column}`);
    }
});

test('after a delete the list is re-read, and the reserved dates on a form that reserves them', () => {
    const request = confirmDeleteBody.indexOf('formResponsesStore.deleteResponse(');
    const after = confirmDeleteBody.slice(confirmDeleteBody.lastIndexOf('await loadData(paginationOptions.value?.currentPage || 1);'));

    assert.ok(request !== -1 && confirmDeleteBody.lastIndexOf('await loadData(') > request);
    assert.match(after, /if \(meta\.value\?\.reservations === true\) loadReservations\(\);/);
    assert.match(after, /title: 'Deleted!'/);
});

test('a refused delete re-reads the row and shows the server\'s sentence through deleteFailure()', () => {
    assert.match(confirmDeleteBody, /if \(status === 404\) \{\s*await loadData\(/);
    assert.match(confirmDeleteBody, /else if \(status === 422 \|\| status === 409 \|\| status === 503\) \{\s*await refreshRow\(response\.id\);/);
    assert.match(confirmDeleteBody, /Swal\.fire\(\{ icon: 'error', \.\.\.deleteFailure\(status, serverMessage\(error, 'Failed to delete the response\.'\)\) \}\);/);
});

test('the cancel question is told what Delete does for the row, and the detail\'s payment hint shows only for a paid one', () => {
    assert.match(view, /deleteStep: deleteStep\(row\),/);
    assert.match(view, /v-if="paymentEnabled && selectedResponse\.payment_state === 'paid' && editStatus === 'cancelled' && selectedResponse\.status !== 'cancelled'"/);
});
