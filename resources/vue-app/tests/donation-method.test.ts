/**
 * The payment-method label every giving screen shows
 * (core/helpers/donationMethod.ts). Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    donationMethodLabel,
    historicalGivingNote,
    isHistoricalGift,
    receiptNote,
    wixOrderLabel,
    wixOrderLineSummaries,
    wixOrderPaymentLabel,
} from '../core/helpers/donationMethod.ts';

test('an imported Wix gift names its processor and never reads as a card gift', () => {
    assert.equal(donationMethodLabel({ source: 'historical', payment_method: 'paypal' }), 'PayPal (Wix)');
    assert.equal(donationMethodLabel({ source: 'historical', payment_method: 'square' }), 'Square (Wix)');
    assert.equal(donationMethodLabel({ source: 'historical', payment_method: 'wix' }), 'Wix checkout');
    assert.equal(donationMethodLabel({ source: 'historical', payment_method: null }), 'Wix checkout');
    assert.equal(isHistoricalGift({ source: 'historical' }), true);
});

test('an offline gift paid by bank transfer reads as two words, the way the ledger picker says it', () => {
    assert.equal(donationMethodLabel({ source: 'offline', payment_method: 'bank_transfer' }), 'bank transfer');
});

test('Manara-recorded gifts keep the labels they always had', () => {
    assert.equal(donationMethodLabel({ source: 'stripe' }), 'card');
    assert.equal(donationMethodLabel({ source: 'offline', payment_method: 'check' }), 'check');
    assert.equal(donationMethodLabel({ source: 'offline', payment_method: 'credit_zelle' }), 'credit/zelle');
    assert.equal(donationMethodLabel({ source: 'offline', payment_method: 'unknown' }), 'offline');
    assert.equal(isHistoricalGift({ source: 'offline' }), false);
    assert.equal(isHistoricalGift(null), false);
});

// The ledger's detail panel: why a gift with no receipt cannot get one here.
test('an imported Wix gift explains it was paid through Wix, before any card or offline wording', () => {
    const note = receiptNote({ source: 'historical', payment_method: 'paypal', status: 'succeeded', fund: { name: 'Iftar', receiptable: true } });
    assert.match(note, /imported from the old Wix site/);
    assert.match(note, /does not edit it or issue a receipt/);
    assert.doesNotMatch(note, /Stripe/);
});

test('every other gift without a receipt names what blocks it', () => {
    assert.match(receiptNote({ source: 'stripe', status: 'succeeded' }), /issued automatically when Stripe confirms/);
    assert.match(receiptNote({ source: 'offline', status: 'pending' }), /not marked succeeded/);
    assert.equal(
        receiptNote({ source: 'offline', status: 'succeeded', fund: { name: 'Building', receiptable: false } }),
        'The Building fund is set not to issue tax receipts, so no receipt can be issued for this gift.',
    );
});

// The contact record's "Total giving".
test('the Wix giving line appears only when there is Wix giving', () => {
    const dollars = (cents: number) => `$${(cents / 100).toFixed(2)}`;
    assert.equal(historicalGivingNote(4900, dollars), 'plus $49.00 on the old Wix site');
    assert.equal(historicalGivingNote(0, dollars), null);
    assert.equal(historicalGivingNote(undefined, dollars), null);
});

// The contact record's "Orders on the old Wix site".
test('a Wix order says where each line went, so a line kept on the order alone is visible as such', () => {
    const order = {
        source: 'wix_stores', order_number: '10002', provider: 'wix', status: 'paid',
        lines: [
            { name: 'Fall Festival Ticket', quantity: 2, unit_minor: 1000, recorded_as: 'registration' as const },
            { name: '$30 Food Tickets For Only $25 ', quantity: 1, unit_minor: 2500, recorded_as: 'order_only' as const },
        ],
    };

    assert.equal(wixOrderLabel(order), 'Wix order #10002');
    assert.deepEqual(wixOrderLineSummaries(order), [
        '2 × Fall Festival Ticket (recorded as a registration)',
        '1 × $30 Food Tickets For Only $25 (kept on this order only)',
    ]);
    assert.equal(wixOrderPaymentLabel(order), 'Wix checkout');
});

test('a Wix Events order names its processor, and an unpaid one says no money moved', () => {
    const paid = { source: 'wix_events', order_number: 'EVT-1', provider: 'paypal', status: 'paid', lines: [] };
    assert.equal(wixOrderLabel(paid), 'Wix Events order EVT-1');
    assert.equal(wixOrderPaymentLabel(paid), 'PayPal (Wix)');
    assert.equal(wixOrderPaymentLabel({ ...paid, status: 'canceled' }), 'Abandoned at the Wix checkout, never paid');
    assert.equal(wixOrderPaymentLabel({ ...paid, status: 'declined' }), 'Declined at the Wix checkout, never paid');
});
