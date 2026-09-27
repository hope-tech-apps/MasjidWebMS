/**
 * The status select on each row of the registrations list
 * (views/dashboard/formResponseStatus.ts): its labels and order, which changes are asked
 * first, what the cancel question says, and the optimistic save that puts the old status
 * back when the server refuses.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    FALLBACK_STATUSES,
    asksBeforeStatusChange,
    cancelQuestion,
    registrationName,
    saveStatusOptimistically,
    statusChoices,
    statusLabel,
    type CancelFacts
} from '../views/dashboard/formResponseStatus.ts';

const facts = (over: Partial<CancelFacts> = {}): CancelFacts => ({
    id: 121,
    name: 'Reema Bianouni',
    paymentEnabled: true,
    paymentMethod: 'online',
    paymentState: 'unpaid',
    paidAmount: null,
    cardPageOpened: false,
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

test('cancelling an unpaid card registration with an open page says the page is closed', () => {
    const text = cancelQuestion(facts({ cardPageOpened: true })).lines.join(' ');

    assert.match(text, /Cancelling closes that page so it cannot be paid/);
    assert.doesNotMatch(text, /refund/);
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
