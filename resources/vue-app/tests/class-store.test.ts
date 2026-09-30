/**
 * The class store's helpers (core/helpers/classStore.ts, T-003.4): the wording, the shelf, the
 * prize form and the request ids. There is no sort by balance and no total in that file, and a
 * test below keeps it that way.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    LEDGER_KINDS, blankPrizeForm, breakdownLine, bucksLabel, createRequestIds, dayLabel, entryText, keepsRequestId, kindLabel, newRequestId,
    prizeFormFrom, prizeFormReady, prizeRequest, shelfFor, signedBucks, stockNote,
} from '../core/helpers/classStore.ts';

const prize = (over: Record<string, unknown> = {}) => ({
    id: 1, scope: 'class' as const, title: 'Pencil', description: null, cost_bucks: 5, stock: null,
    in_stock: true, is_active: true, editable: true, ...over,
});

test('every ledger kind has a teacher word and an unknown one is shown as it came', () => {
    for (const kind of LEDGER_KINDS) assert.ok(kindLabel(kind).length > 0, kind);
    assert.equal(kindLabel('cashed_out'), 'Paid out on paper');
    assert.equal(kindLabel('something_new'), 'something new');
    assert.equal(kindLabel(null), '');
});

test('bucks and signed bucks read as words and figures, one Buck being singular', () => {
    assert.equal(bucksLabel(1), '1 Buck');
    assert.equal(bucksLabel(0), '0 Bucks');
    assert.equal(bucksLabel(12), '12 Bucks');
    assert.equal(bucksLabel(undefined), '0 Bucks');
    assert.equal(signedBucks(5), '+5');
    assert.equal(signedBucks(-4), '-4');
    assert.equal(signedBucks(0), '0');
    assert.equal(signedBucks('3'), '+3');
});

test('a request id has the shape the server accepts and never repeats', () => {
    const ids = new Set(Array.from({ length: 50 }, () => newRequestId()));
    assert.equal(ids.size, 50);
    for (const id of ids) assert.match(id, /^[A-Za-z0-9_-]{8,36}$/);
});

test('a request id is kept after a failure that may have committed and dropped after one that did not', () => {
    for (const status of [undefined, null, 0, 408, 500, 502, 503, 504]) assert.equal(keepsRequestId(status), true, String(status));
    for (const status of [400, 401, 403, 404, 409, 422, 429]) assert.equal(keepsRequestId(status), false, String(status));
});

test('a retry after a dropped response sends the same id, and a new write after success or a refusal sends a new one', () => {
    let n = 0;
    const ids = createRequestIds(() => `id-${++n}-xxxxxxxx`);
    const key = 'redeem:7:3';

    const first = ids.idFor(key);
    assert.equal(ids.idFor(key), first, 'asking again before an answer is the same write');

    ids.failed(key, undefined); // the response never arrived: it may have committed
    assert.equal(ids.idFor(key), first, 'the retry is a replay, not a second deduction');
    ids.failed(key, 503);
    assert.equal(ids.idFor(key), first);

    ids.failed(key, 422); // refused for good: nothing was written
    const second = ids.idFor(key);
    assert.notEqual(second, first);

    ids.succeeded(key);
    assert.notEqual(ids.idFor(key), second, 'once it went through, the next tap is a new prize');
});

test('two different writes never share an id, and a student, a prize or an amount is a different write', () => {
    let n = 0;
    const ids = createRequestIds(() => `id-${++n}-xxxxxxxx`);

    const a = ids.idFor('redeem:7:3');
    const b = ids.idFor('redeem:7:4');
    const c = ids.idFor('redeem:8:3');
    const d = ids.idFor('cashout:7:10');
    assert.equal(new Set([a, b, c, d]).size, 4);
    assert.equal(ids.size, 4);

    ids.succeeded('redeem:7:3');
    assert.equal(ids.size, 3);
    assert.equal(ids.idFor('redeem:7:4'), b, 'the others are still waiting on their answer');
});

test('the shelf offers only active prizes and says why one cannot be given', () => {
    const shelf = shelfFor([
        prize({ id: 1, cost_bucks: 5 }),
        prize({ id: 2, cost_bucks: 9 }),
        prize({ id: 3, stock: 0, in_stock: false }),
        prize({ id: 4, is_active: false }),
    ], 6);

    assert.deepEqual(shelf.map((p) => p.id), [1, 2, 3], 'a retired prize is not on the shelf');
    assert.deepEqual(shelf.map((p) => p.available), [true, false, false]);
    assert.equal(shelf[0].why, null);
    assert.equal(shelf[1].why, 'Needs 3 Bucks more');
    assert.equal(shelf[2].why, 'Out of stock');
});

test('exactly enough is enough, one short is not, and an unreadable balance offers nothing', () => {
    assert.equal(shelfFor([prize({ cost_bucks: 5 })], 5)[0].available, true);
    assert.equal(shelfFor([prize({ cost_bucks: 5 })], 4)[0].why, 'Needs 1 Buck more');
    const unknown = shelfFor([prize({ cost_bucks: 1 })], null)[0];
    assert.equal(unknown.available, false, 'a failed read is not a permission');
    assert.equal(unknown.why, 'Balance unavailable');
});

test('unlimited stock says so and a count says how many are left', () => {
    assert.equal(stockNote({ stock: null }), 'Unlimited');
    assert.equal(stockNote({ stock: 0 }), '0 left');
    assert.equal(stockNote({ stock: 7 }), '7 left');
});

test('a blank stock field is sent as null, which the server reads as unlimited', () => {
    const form = { ...blankPrizeForm(), title: '  Bookmark ', cost: ' 4 ', description: '  ' };

    assert.deepEqual(prizeRequest(form), { title: 'Bookmark', description: null, cost_bucks: 4, stock: null, is_active: true });
    assert.deepEqual(prizeRequest({ ...form, stock: '12', description: 'Laminated' }), {
        title: 'Bookmark', description: 'Laminated', cost_bucks: 4, stock: 12, is_active: true,
    });
    assert.equal(prizeRequest({ ...form, stock: '0' }).stock, 0, 'zero is a real count, not blank');
});

test('a prize is ready only with a title, a whole price of at least one, and a whole stock or none', () => {
    const ok = { ...blankPrizeForm(), title: 'Pencil', cost: '5' };
    assert.equal(prizeFormReady(ok), true);
    assert.equal(prizeFormReady({ ...ok, stock: '3' }), true);
    for (const bad of [
        { ...ok, title: '   ' }, { ...ok, cost: '' }, { ...ok, cost: '0' }, { ...ok, cost: '2.5' }, { ...ok, cost: '-1' },
        { ...ok, cost: '10001' }, { ...ok, stock: '-2' }, { ...ok, stock: 'lots' }, { ...ok, stock: '100001' },
    ]) {
        assert.equal(prizeFormReady(bad), false, JSON.stringify(bad));
    }
});

test('editing a prize starts from what it is, and unlimited is a blank field', () => {
    assert.deepEqual(prizeFormFrom(prize({ description: 'Sharp', cost_bucks: 3, stock: 9, is_active: false })), {
        title: 'Pencil', description: 'Sharp', cost: '3', stock: '9', active: false,
    });
    assert.equal(prizeFormFrom(prize({ stock: null })).stock, '');
});

test('the paper notes read largest first with zeros left out', () => {
    assert.equal(breakdownLine({ '1': 2, '20': 2, '5': 1, '10': 0 }), '2 x 20, 1 x 5, 2 x 1');
    assert.equal(breakdownLine({ '20': 0, '10': 0, '5': 0, '1': 0 }), 'none');
    assert.equal(breakdownLine(null), 'none');
});

test('a week is a calendar day and does not move with the browser zone', () => {
    assert.equal(dayLabel('2026-10-04'), 'Oct 4');
    assert.equal(dayLabel('2026-10-04 00:00:00'), 'Oct 4');
    assert.equal(dayLabel('nonsense'), '');
    assert.equal(dayLabel(null), '');
});

test('a ledger line says what it was for', () => {
    assert.equal(entryText({ kind: 'redeemed', prize_title: 'Kite' }), 'Prize: Kite');
    assert.equal(entryText({ kind: 'reversal', prize_title: 'Kite' }), 'Given back: Kite');
    assert.equal(entryText({ kind: 'earned', week_start: '2026-10-04' }), 'Earned, week of Oct 4');
    assert.equal(entryText({ kind: 'adjusted', week_start: '2026-10-04' }), 'Adjusted, week of Oct 4');
    assert.equal(entryText({ kind: 'cashed_out', breakdown: { '20': 1, '5': 1 } }), 'Paid out on paper (1 x 20, 1 x 5)');
    assert.equal(entryText({ kind: 'expired' }), 'Expired at the end of the class or year');
    assert.equal(entryText({ kind: 'odd_kind' }), 'odd kind');
});

test('the helper file has no sort, no rank and no total: there is no leaderboard to build from it', () => {
    const source = readFileSync(new URL('../core/helpers/classStore.ts', import.meta.url), 'utf8')
        // The comment that says what is NOT here names the words; only code is checked.
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');

    assert.doesNotMatch(source, /\.sort\(\s*\(?\s*[a-z]\w*\s*,\s*[a-z]\w*\s*\)?\s*=>[^)]*balance/i, 'no sort by balance');
    assert.doesNotMatch(source, /\brank\b|\bleaderboard\b|\btotalBucks\b|\bclassTotal\b/i);
});
