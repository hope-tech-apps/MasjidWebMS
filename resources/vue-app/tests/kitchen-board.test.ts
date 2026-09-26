/**
 * The board's decisions about a kitchen catalogue (views/lunch/kitchenBoard.ts).
 * Display answers only; the server enforces all of it again. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { awaitsConfirmation, cardNotPaid, catalogueSummary, isCatalogue, pickupWords, staffMethodUnavailable, unpaidHow } from '../views/lunch/kitchenBoard.ts';

test('a menu is a catalogue only when the server says so; a menu with no kind is a Friday lunch', () => {
    assert.equal(isCatalogue({ kind: 'catalogue' }), true);
    assert.equal(isCatalogue({ kind: 'dated' }), false);
    assert.equal(isCatalogue({}), false);
    assert.equal(isCatalogue(null), false);
});

test('a catalogue card names its notice instead of a date', () => {
    assert.equal(catalogueSummary({ pickup_lead_hours: 48 }), 'Standing catalogue · 48h notice');
    assert.equal(catalogueSummary({ pickup_lead_hours: 0 }), 'Standing catalogue · no notice needed');
});

test('a pending kitchen order waits for the office even once paid; a Friday order never asks', () => {
    const kitchen = { kind: 'catalogue' };
    assert.equal(awaitsConfirmation({ status: 'pending' }, kitchen), true);
    assert.equal(awaitsConfirmation({ status: 'pending', payment_method: 'pickup', payment_status: 'unpaid' }, kitchen), true);
    assert.equal(awaitsConfirmation({ status: 'pending', payment_method: 'online', payment_status: 'paid' }, kitchen), true);
    assert.equal(awaitsConfirmation({ status: 'confirmed' }, kitchen), false);
    assert.equal(awaitsConfirmation({ status: 'cancelled' }, kitchen), false);
    assert.equal(awaitsConfirmation({ status: 'pending' }, { kind: 'dated' }), false);
});

test('an unpaid card kitchen order is not work for the office: it says so and asks for no confirmation', () => {
    const kitchen = { kind: 'catalogue' };
    const abandoned = { status: 'pending', payment_method: 'online', payment_status: 'unpaid' };
    assert.equal(cardNotPaid(abandoned, kitchen), true);
    assert.equal(awaitsConfirmation(abandoned, kitchen), false);
    assert.equal(cardNotPaid({ ...abandoned, payment_status: 'paid' }, kitchen), false);
    assert.equal(cardNotPaid({ ...abandoned, status: 'cancelled' }, kitchen), false);
    assert.equal(cardNotPaid({ ...abandoned, payment_method: 'pickup' }, kitchen), false);
    assert.equal(cardNotPaid(abandoned, { kind: 'dated' }), false, 'a Friday card order is not the kitchen\'s rule');
});

test('staff can take a kitchen order any way the organisation accepts; card only while Stripe and the menu allow it', () => {
    assert.equal(staffMethodUnavailable({ online: false, ready: true }, { allow_online_payment: false }), null);
    assert.equal(staffMethodUnavailable({ online: true, ready: true }, { allow_online_payment: true }), null);
    assert.equal(staffMethodUnavailable({ online: true, ready: false }, { allow_online_payment: true }), 'Stripe is not set up for this organisation yet');
    assert.equal(staffMethodUnavailable({ online: true, ready: true }, { allow_online_payment: false }), 'online payment is switched off for this menu');
});

test('the pickup is worded from the organisation clock the server sent, never shifted by the browser zone', () => {
    // 2026-10-03 is a Saturday. 14:00 stays 2:00 PM whatever zone runs the test.
    assert.equal(pickupWords('2026-10-03T14:00'), 'Sat, Oct 3, 2:00 PM');
    assert.equal(pickupWords('2026-10-04T00:30'), 'Sun, Oct 4, 12:30 AM');
    assert.equal(pickupWords('2026-12-31T12:05'), 'Thu, Dec 31, 12:05 PM');
    assert.equal(pickupWords(null), '');
    assert.equal(pickupWords('soon'), 'soon');
});

test('an unpaid kitchen order says how the customer said they would pay', () => {
    assert.equal(unpaidHow({ payment_method: 'online' }), 'card online');
    assert.equal(unpaidHow({ payment_method: 'pickup', preferred_payment: 'zelle' }), 'by Zelle');
    assert.equal(unpaidHow({ payment_method: 'pickup', preferred_payment: 'bank_transfer' }), 'by bank transfer');
    assert.equal(unpaidHow({ payment_method: 'pickup', preferred_payment: null }), 'at pickup');
    // The organisation's own name for the method, above all its "Other".
    assert.equal(unpaidHow({ payment_method: 'pickup', preferred_payment: 'other', preferred_payment_label: 'Venmo' }), 'by Venmo');
    assert.equal(unpaidHow({ payment_method: 'pickup', preferred_payment: 'other', preferred_payment_label: '  ' }), 'by other');
});
