/**
 * The payment-method label every giving screen shows
 * (core/helpers/donationMethod.ts). Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { donationMethodLabel, isHistoricalGift } from '../core/helpers/donationMethod.ts';

test('an imported Wix gift names its processor and never reads as a card gift', () => {
    assert.equal(donationMethodLabel({ source: 'historical', payment_method: 'paypal' }), 'PayPal (Wix)');
    assert.equal(donationMethodLabel({ source: 'historical', payment_method: 'square' }), 'Square (Wix)');
    assert.equal(donationMethodLabel({ source: 'historical', payment_method: 'wix' }), 'Wix checkout');
    assert.equal(donationMethodLabel({ source: 'historical', payment_method: null }), 'Wix checkout');
    assert.equal(isHistoricalGift({ source: 'historical' }), true);
});

test('Manara-recorded gifts keep the labels they always had', () => {
    assert.equal(donationMethodLabel({ source: 'stripe' }), 'card');
    assert.equal(donationMethodLabel({ source: 'offline', payment_method: 'check' }), 'check');
    assert.equal(donationMethodLabel({ source: 'offline', payment_method: 'credit_zelle' }), 'credit/zelle');
    assert.equal(donationMethodLabel({ source: 'offline', payment_method: 'unknown' }), 'offline');
    assert.equal(isHistoricalGift({ source: 'offline' }), false);
    assert.equal(isHistoricalGift(null), false);
});
