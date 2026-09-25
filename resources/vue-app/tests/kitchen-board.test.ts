/**
 * The board's decisions about a kitchen catalogue (views/lunch/kitchenBoard.ts).
 * Display answers only; the server enforces all of it again. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { awaitsConfirmation, catalogueSummary, isCatalogue, pickupWords, unpaidHow } from '../views/lunch/kitchenBoard.ts';

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
    assert.equal(awaitsConfirmation({ status: 'confirmed' }, kitchen), false);
    assert.equal(awaitsConfirmation({ status: 'cancelled' }, kitchen), false);
    assert.equal(awaitsConfirmation({ status: 'pending' }, { kind: 'dated' }), false);
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
});
