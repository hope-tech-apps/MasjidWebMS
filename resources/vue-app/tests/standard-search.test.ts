/**
 * The standards search the gradebook uses (core/helpers/standardSearch.ts):
 * a debounced question, a stale answer never replacing a newer one, an on-screen
 * keyboard's composition left alone, and nothing invented.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    createStandardSearch,
    newStandardSearchState,
    searchable,
    weeksLabel,
    type StandardMatch,
} from '../core/helpers/standardSearch.ts';

const m = (code: string | null, focus: string): StandardMatch => ({
    standard_code: code, focus, grade_label: 'Grade 3', subject: 'Mathematics', weeks: [4], week_no: 4, in_scope: true,
});

const tick = (ms = 5) => new Promise((r) => setTimeout(r, ms));
const key = (k: string, extra: Record<string, unknown> = {}) => {
    let prevented = false;
    return { event: { key: k, preventDefault: () => { prevented = true; }, ...extra }, wasPrevented: () => prevented };
};

test('fewer than two letters or digits is not a question yet', () => {
    assert.equal(searchable(''), false);
    assert.equal(searchable(' . '), false);
    assert.equal(searchable('n'), false);
    assert.equal(searchable('NF'), true);
    assert.equal(searchable('١٢'), true, 'Arabic-Indic digits are digits');
});

test('a short entry asks nothing and clears the list', async () => {
    const asked: string[] = [];
    const state = newStandardSearchState();
    const s = createStandardSearch(state, { delayMs: 0, fetch: async (q) => { asked.push(q); return [m('NC.3.NF.1', 'x')]; }, onPick: () => {} });

    s.onInput('n');
    await tick();

    assert.deepEqual(asked, []);
    assert.equal(state.open, true);
    assert.deepEqual(state.matches, []);
    assert.equal(state.emptyFor, null, 'a question never asked has no "nothing matches" answer');
});

test('typing asks once after the pause, with the trimmed text from the box', async () => {
    const asked: string[] = [];
    const state = newStandardSearchState();
    const s = createStandardSearch(state, { delayMs: 10, fetch: async (q) => { asked.push(q); return [m('NC.3.NF.1', 'Fractions')]; }, onPick: () => {} });

    s.onInput('n'); s.onInput('nf'); s.onInput('  nf1 ');
    await tick(40);

    assert.deepEqual(asked, ['nf1'], 'three keystrokes inside the pause are one request');
    assert.equal(state.matches.length, 1);
    assert.equal(state.typed, 'nf1');
});

test('a slower answer to an older question never replaces a newer one', async () => {
    const state = newStandardSearchState();
    const resolvers: Record<string, (v: StandardMatch[]) => void> = {};
    const s = createStandardSearch(state, {
        delayMs: 0,
        fetch: (q) => new Promise((resolve) => { resolvers[q] = resolve; }),
        onPick: () => {},
    });

    s.onInput('frac'); await tick();
    s.onInput('fractions'); await tick();

    resolvers['fractions']([m('NC.3.NF.1', 'Fractions')]);
    await tick();
    resolvers['frac']([m('NC.3.NF.9', 'The OLD answer')]);
    await tick();

    assert.deepEqual(state.matches.map((x) => x.standard_code), ['NC.3.NF.1']);
});

test('closing the list discards an answer that lands afterwards', async () => {
    const state = newStandardSearchState();
    let resolve: (v: StandardMatch[]) => void = () => {};
    const s = createStandardSearch(state, { delayMs: 0, fetch: () => new Promise((r) => { resolve = r; }), onPick: () => {} });

    s.onInput('fractions'); await tick();
    s.close();
    resolve([m('NC.3.NF.1', 'Fractions')]);
    await tick();

    assert.equal(state.open, false);
    assert.deepEqual(state.matches, [], 'a list must not reopen itself under a field the teacher has left');
});

test('a question with no answer says so for that question and nothing else', async () => {
    const state = newStandardSearchState();
    const s = createStandardSearch(state, { delayMs: 0, fetch: async () => [], onPick: () => {} });

    s.onInput('arabic'); await tick();

    assert.deepEqual(state.matches, [], 'the guide has nothing for Arabic and none is made up');
    assert.equal(state.emptyFor, 'arabic');

    s.onInput('arabic al'); // a new question: the old empty answer is no longer the current one
    assert.equal(state.emptyFor, 'arabic', 'kept until the new answer lands, then compared with what is typed');
    assert.equal(state.typed, 'arabic al');
});

test('a failed search leaves an empty list and no error to the teacher', async () => {
    const state = newStandardSearchState();
    const s = createStandardSearch(state, { delayMs: 0, fetch: async () => { throw new Error('offline'); }, onPick: () => {} });

    s.onInput('fractions'); await tick();

    assert.deepEqual(state.matches, []);
});

test('arrow keys walk the list and wrap; Enter picks only a row an arrow chose', async () => {
    const picked: StandardMatch[] = [];
    const state = newStandardSearchState();
    const s = createStandardSearch(state, {
        delayMs: 0,
        fetch: async () => [m('A.1', 'one'), m('A.2', 'two'), m('A.3', 'three')],
        onPick: (x) => picked.push(x),
    });

    s.onInput('standard'); await tick();
    assert.equal(state.active, -1);

    const enterFirst = key('Enter');
    s.onKey(enterFirst.event);
    assert.deepEqual(picked, [], 'Enter does nothing until an arrow key chooses');
    assert.equal(enterFirst.wasPrevented(), false, 'and a form still gets its Enter');

    s.onKey(key('ArrowDown').event); assert.equal(state.active, 0);
    s.onKey(key('ArrowDown').event); s.onKey(key('ArrowDown').event); assert.equal(state.active, 2);
    s.onKey(key('ArrowDown').event); assert.equal(state.active, 0, 'wraps to the top');
    s.onKey(key('ArrowUp').event); assert.equal(state.active, 2, 'and back up to the bottom');

    const enter = key('Enter');
    s.onKey(enter.event);
    assert.deepEqual(picked.map((x) => x.standard_code), ['A.3']);
    assert.equal(enter.wasPrevented(), true);
    assert.equal(state.open, false, 'a pick closes the list');
});

test('keys pressed while a keyboard is composing belong to the composition', async () => {
    const state = newStandardSearchState();
    const s = createStandardSearch(state, { delayMs: 0, fetch: async () => [m('A.1', 'one')], onPick: () => {} });
    s.onInput('standard'); await tick();

    s.onKey(key('ArrowDown', { isComposing: true }).event);
    s.onKey(key('Escape', { isComposing: true }).event);

    assert.equal(state.active, -1);
    assert.equal(state.open, true);
});

test('Escape closes the list', async () => {
    const state = newStandardSearchState();
    const s = createStandardSearch(state, { delayMs: 0, fetch: async () => [m('A.1', 'one')], onPick: () => {} });
    s.onInput('standard'); await tick();

    s.onKey(key('Escape').event);

    assert.equal(state.open, false);
    assert.deepEqual(state.matches, []);
});

test('weeks read as a short phrase', () => {
    assert.equal(weeksLabel([4]), 'Week 4');
    assert.equal(weeksLabel([1, 2, 3]), 'Weeks 1, 2, 3');
    assert.equal(weeksLabel([1, 2, 3, 4, 5, 6]), 'Weeks 1, 2, 3, 4 +2');
});

test('the controller adds no suggestion of its own and calls the network nowhere', () => {
    const source = readFileSync(new URL('../core/helpers/standardSearch.ts', import.meta.url), 'utf8');

    assert.equal(/axios|fetch\(|ApiService|XMLHttpRequest/.test(source.replace(/options\.fetch|fetch:/g, '')), false);
    assert.equal(/matches\.push|matches = \[\.\.\./.test(source), false, 'matches only ever holds what the server returned');
});
