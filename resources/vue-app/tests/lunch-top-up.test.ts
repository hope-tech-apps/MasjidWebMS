/**
 * The lunch order page's decisions about a PAID order's change
 * (views/lunch/lunchTopUp.ts). Display answers only; the server refuses all of it
 * again on the locked row. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    EDIT_WHY_KEYS,
    REFUSAL_KEYS,
    paidDraftBlock,
    topUpOutcome,
    withoutTopUpMarker,
} from '../views/lunch/lunchTopUp.ts';

test('a conflict is reported as a conflict even when the order now costs more than was paid', () => {
    // Staff added a $10 plate (total 2000) while a $5 top-up was pending on a
    // $10 order; the payment landed as a conflict and paid is 1500 < 2000. The
    // old rule (conflict only when paid > total) said "updated" here.
    const outcome = topUpOutcome({ total_minor: 2000, paid_minor: 1500, top_up: null, last_top_up_status: 'conflict' });
    assert.deepEqual(outcome, { key: 'topup_conflict', tone: 'warn' });
});

test('a payment made just before its page closed keeps the page waiting until the webhook records it', () => {
    // The pending top-up is no longer offered (its page time passed), so `top_up`
    // is null, but nothing has been recorded yet.
    assert.equal(topUpOutcome({ total_minor: 800, paid_minor: 800, top_up: null, last_top_up_status: 'pending' }), null);
});

test('each recorded outcome has its own note', () => {
    assert.deepEqual(topUpOutcome({ last_top_up_status: 'applied' }), { key: 'topup_done', tone: 'ok' });
    assert.deepEqual(topUpOutcome({ last_top_up_status: 'rejected' }), { key: 'topup_unconfirmed', tone: 'warn' });
    assert.deepEqual(topUpOutcome({ last_top_up_status: 'expired' }), { key: 'topup_expired', tone: 'warn' });
    assert.deepEqual(topUpOutcome({ last_top_up_status: null }), { key: '', tone: 'ok' });
    assert.equal(topUpOutcome(null), null);
});

test('fewer plates than were paid for cannot be saved, the same and more can', () => {
    const base = { isPaid: true, paidMinor: 1600, openUntil: null, nowMs: 0 };
    assert.equal(paidDraftBlock({ ...base, previewTotal: 800 }), 'topup_reduce');
    assert.equal(paidDraftBlock({ ...base, previewTotal: 1600 }), null);
    assert.equal(paidDraftBlock({ ...base, previewTotal: 2400 }), null);
    assert.equal(paidDraftBlock({ ...base, isPaid: false, previewTotal: 800 }), null);
});

test('in the last half hour, adding to a paid order is refused before the tap, a swap is not', () => {
    const openUntil = '2027-01-08T14:30:00Z';
    const after = Date.parse('2027-01-08T14:40:00Z');
    const before = Date.parse('2027-01-08T14:00:00Z');
    const base = { isPaid: true, paidMinor: 800, openUntil };
    assert.equal(paidDraftBlock({ ...base, previewTotal: 1600, nowMs: after }), 'topup_too_close');
    assert.equal(paidDraftBlock({ ...base, previewTotal: 1600, nowMs: before }), null);
    assert.equal(paidDraftBlock({ ...base, previewTotal: 800, nowMs: after }), null);
});

test('the topup marker is dropped from the URL once read, and nothing else is', () => {
    assert.deepEqual(withoutTopUpMarker({ topup: 'success', cancelled: '1' }), { cancelled: '1' });
    assert.deepEqual(withoutTopUpMarker({}), {});
});

test('every code the server names is said in both English and Arabic', () => {
    const source = readFileSync(new URL('../views/lunch/lunchI18n.ts', import.meta.url), 'utf8');
    const [en, ar] = source.split(/\n\s{4}ar: \{/);
    assert.ok(en && ar, 'lunchI18n.ts has an English and an Arabic block');

    const keys = new Set([...Object.values(REFUSAL_KEYS), ...Object.values(EDIT_WHY_KEYS),
        'topup_done', 'topup_conflict', 'topup_unconfirmed', 'topup_expired', 'topup_waiting']);
    for (const key of keys) {
        assert.match(en, new RegExp(`\\n\\s+${key}: "`), `English has ${key}`);
        assert.match(ar, new RegExp(`\\n\\s+${key}: "`), `Arabic has ${key}`);
    }

    // The codes the API sends (JummahLunchOrdersController) all have a key here.
    const controller = readFileSync(new URL('../../../app/Http/Controllers/Api/V1/JummahLunchOrdersController.php', import.meta.url), 'utf8');
    const sent = [...controller.matchAll(/=> '([a-z_]+)',\n/g)].map((m) => m[1]);
    for (const code of ['paid_balance_open', 'paid_prices_moved', 'topup_too_small', 'topup_not_opened', 'just_paid']) {
        assert.ok(sent.includes(code), `the controller sends ${code}`);
        assert.ok(REFUSAL_KEYS[code], `the page knows ${code}`);
    }
});
