/**
 * The header's Calendar link across a school switch. The flag is one boolean
 * shared by every school the layout shows in turn, so it has to be cleared
 * before the new school's /me is asked, not only when that request fails.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadCalendarFlagFor } from '../views/family/calendarFlag.ts';

/** A layout stand-in: the one shared flag, the school on screen, a hand-cranked network. */
function layout() {
    const state = { flag: false, school: '7', history: [] as boolean[] };
    const pending: { url: string; resolve: (v: any) => void; reject: (e: any) => void }[] = [];

    const run = (id: string, signedIn = true) => loadCalendarFlagFor({
        id,
        signedIn,
        current: () => state.school,
        get: (url) => new Promise((resolve, reject) => pending.push({ url, resolve, reject })),
        set: (v) => { state.flag = v; state.history.push(v); },
    });

    return { state, pending, run };
}

const published = (v: boolean) => ({ data: { data: { school_calendar_published: v } } });
const tick = () => new Promise((r) => setImmediate(r));

test('moving to a school whose /me is still in flight does not show the school just left\'s link', async () => {
    const l = layout();

    // School 7 publishes a calendar.
    const first = l.run('7');
    l.pending[0].resolve(published(true));
    await first;
    assert.equal(l.state.flag, true);

    // The parent picks school 9 from "Your schools"; 9's /me has not answered.
    l.state.school = '9';
    const second = l.run('9');
    await tick();

    assert.equal(l.state.flag, false, 'school 9\'s header carries no link while its own answer is pending');
    assert.equal(l.pending[1].url, '/api/family/masjids/9/me');

    l.pending[1].resolve(published(false));
    await second;
    assert.equal(l.state.flag, false);
});

test('a late answer for the school just left still cannot set the link for the current one', async () => {
    const l = layout();

    const slowSeven = l.run('7');
    l.state.school = '9';
    const nine = l.run('9');
    l.pending[1].resolve(published(false));
    await nine;

    l.pending[0].resolve(published(true)); // school 7's answer lands last
    await slowSeven;

    assert.equal(l.state.flag, false);
});

test('the link shows when the school on screen publishes a calendar, and hides on failure', async () => {
    const l = layout();

    const ok = l.run('7');
    l.pending[0].resolve(published(true));
    await ok;
    assert.equal(l.state.flag, true);

    const failed = l.run('7');
    l.pending[1].reject(new Error('boom'));
    await failed;
    assert.equal(l.state.flag, false);
});

test('not signed in: no request, and the flag is cleared', async () => {
    const l = layout();
    l.state.flag = true;

    await l.run('7', false);

    assert.equal(l.pending.length, 0);
    assert.equal(l.state.flag, false);
});
