/**
 * How the form builder reads a stored settings.fee (components/forms/formFeePricing.ts).
 * A fee read as the wrong choice is saved back as that choice, so an imported Zakat-ul-Fitr
 * or iftar form must never read as a flat price or as free. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { type FeeDraft, buildFee, feeAmountOf, feePricingOf, preservedFeeOf } from '../components/forms/formFeePricing.ts';

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

// ------------------------------------------------ what a save sends (buildFee)

const IFTAR_FEE = {
    currency: 'USD',
    perQuantityOf: 'people',
    byChoice: {
        field: 'sponsorship',
        prices: [
            { value: 'individual', amount: 18, perQuantity: true },
            { value: 'quarter', amount: 450, reservesDate: true },
        ],
    },
};

/** The draft the builder holds for a stored fee: every box filled as load() fills it. */
const draftFor = (fee: Record<string, any>, overrides: Partial<FeeDraft>): FeeDraft => ({
    pricing: feePricingOf(fee),
    currency: fee.currency ?? 'USD',
    amount: feeAmountOf(fee.amount),
    perEntryOfSection: fee.perEntryOfSection ?? null,
    perQuantityOf: fee.perQuantityOf ?? null,
    tiers: fee.tiers ?? [],
    countTiers: fee.countTiers ?? [],
    ...overrides,
});

/** Load a stored fee, change the draft, save: what the server is sent. */
const resave = (stored: Record<string, any>, overrides: Partial<FeeDraft> = {}) =>
    buildFee(preservedFeeOf(stored), draftFor(stored, overrides));

test('a price for each switched to one flat price is no longer multiplied by its number question', () => {
    const fee = resave({ amount: 17, currency: 'USD', perQuantityOf: 'people' }, { pricing: 'flat' });

    assert.equal(fee?.amount, 17);
    assert.equal('perQuantityOf' in (fee ?? {}), false, 'the number question stays behind in the draft only');
});

test('an imported form priced by answer switched to a flat price leaves its levels behind', () => {
    const fee = resave(IFTAR_FEE, { pricing: 'flat', amount: 18 });

    assert.equal(fee?.amount, 18);
    assert.equal('byChoice' in (fee ?? {}), false, 'the server refuses levels beside a flat amount');
});

test('an imported form priced by answer saves its levels back exactly as loaded', () => {
    const fee = resave(IFTAR_FEE);

    assert.deepEqual(fee?.byChoice, IFTAR_FEE.byChoice);
    assert.equal(fee?.perQuantityOf, 'people');
    assert.equal(fee?.currency, 'USD');
});

test('date steps charged per person keep their number question through a save', () => {
    const stored = { currency: 'usd', amount: 17, perQuantityOf: 'people', tiers: [{ amount: 15, until: '2027-03-01', label: 'Early' }] };
    const fee = resave(stored);

    assert.equal(feePricingOf(stored), 'dateSteps');
    assert.equal(fee?.perQuantityOf, 'people');
    assert.deepEqual(fee?.tiers, stored.tiers);
    assert.equal(fee?.currency, 'USD');
});

test('date steps charged per entry never pick up a number question', () => {
    const fee = resave({ amount: 15, perEntryOfSection: 'attendees', tiers: [{ amount: 10, until: '2026-08-14' }] }, { perQuantityOf: 'people' });

    assert.equal(fee?.perEntryOfSection, 'attendees');
    assert.equal('perQuantityOf' in (fee ?? {}), false);
});

test('No price sends no fee at all', () => {
    assert.equal(resave({ amount: 17, perQuantityOf: 'people' }, { pricing: 'none' }), null);
});
