/**
 * How the form builder reads a stored settings.fee (components/forms/formFeePricing.ts).
 * A fee read as the wrong choice is saved back as that choice, so an imported Zakat-ul-Fitr
 * or iftar form must never read as a flat price or as free. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { feeAmountOf, feePricingOf } from '../components/forms/formFeePricing.ts';

test('a form priced by the answer to a question reads as that, never as free', () => {
    const iftar = {
        currency: 'USD',
        perQuantityOf: 'people',
        byChoice: { field: 'sponsorship', prices: [{ value: 'quarter', amount: 450, reservesDate: true }] },
    };

    // It has no amount: read the old way it was "No price", and saved without its prices.
    assert.equal(feePricingOf(iftar), 'choice');
});

test('a price times a number question reads as that, never as one price per submission', () => {
    assert.equal(feePricingOf({ amount: 17, currency: 'USD', perQuantityOf: 'people' }), 'perQuantity');
});

test('every pricing that existed before keeps its reading', () => {
    assert.equal(feePricingOf({}), 'none');
    assert.equal(feePricingOf({ amount: 15 }), 'flat');
    assert.equal(feePricingOf({ amount: 15, perEntryOfSection: 'attendees' }), 'perEntry');
    assert.equal(feePricingOf({ amount: 15, tiers: [{ amount: 10, until: '2026-08-14' }] }), 'dateSteps');
    assert.equal(feePricingOf({ perEntryOfSection: 'children', countTiers: [{ min: 1, amount: 100 }] }), 'count');
});

test('a stored price is read as a number only when it is one', () => {
    assert.equal(feeAmountOf(17), 17);
    assert.equal(feeAmountOf('17.50'), 17.5);
    assert.equal(feeAmountOf(''), null);
    assert.equal(feeAmountOf('lots'), null);
    assert.equal(feeAmountOf(Number.POSITIVE_INFINITY), null);
});
