/**
 * The Payment Methods screen's draft (views/dashboard/paymentMethods.ts). The server
 * validates and replaces the set; these pin what the screen sends it. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { draftFrom, draftProblem, moved, saveBody } from '../views/dashboard/paymentMethods.ts';

const catalogue = [
    { method: 'card', label: 'Card (online)', online: true },
    { method: 'cash', label: 'Cash' },
    { method: 'check', label: 'Check' },
    { method: 'zelle', label: 'Zelle' },
    { method: 'bank_transfer', label: 'Bank transfer' },
    { method: 'other', label: 'Other' },
];

test('saved methods come first, ticked and in their order; the rest follow unticked', () => {
    const rows = draftFrom([{ method: 'zelle', instructions: 'Send to pay@org.example.test' }, { method: 'cash' }], catalogue);
    assert.deepEqual(rows.map((r) => [r.method, r.accepted]), [
        ['zelle', true], ['cash', true], ['card', false], ['check', false], ['bank_transfer', false], ['other', false],
    ]);
    assert.equal(rows[0].instructions, 'Send to pay@org.example.test');
    assert.equal(rows[2].online, true);
});

test('a saved method the vocabulary no longer names is not offered back', () => {
    assert.deepEqual(draftFrom([{ method: 'bitcoin' }], catalogue).filter((r) => r.accepted), []);
});

test('the save sends only the ticked rows, in screen order, with empty text as null', () => {
    let rows = draftFrom([{ method: 'cash', instructions: '  At the office.  ' }], catalogue);
    rows = rows.map((r) => (r.method === 'zelle' ? { ...r, accepted: true, instructions: '' } : r));
    rows = moved(rows, rows.findIndex((r) => r.method === 'zelle'), -1);
    rows = moved(rows, rows.findIndex((r) => r.method === 'zelle'), -1);
    rows = moved(rows, rows.findIndex((r) => r.method === 'zelle'), -1);

    assert.deepEqual(saveBody(rows), {
        methods: [
            { method: 'zelle', label: null, instructions: null },
            { method: 'cash', label: null, instructions: 'At the office.' },
        ],
    });
});

test('nothing ticked is still a list, so clearing is deliberate and never a missing field', () => {
    assert.deepEqual(saveBody(draftFrom([], catalogue)), { methods: [] });
});

test('moving past either end leaves the list alone', () => {
    const rows = draftFrom([], catalogue);
    assert.equal(moved(rows, 0, -1), rows);
    assert.equal(moved(rows, rows.length - 1, 1), rows);
});

test('an unnamed Other is caught before saving', () => {
    const rows = draftFrom([{ method: 'other' }], catalogue);
    assert.match(String(draftProblem(rows)), /Give "Other" a name/);
    assert.equal(draftProblem(rows.map((r) => (r.method === 'other' ? { ...r, label: 'PayPal' } : r))), null);
});
