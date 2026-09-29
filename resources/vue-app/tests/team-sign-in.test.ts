/**
 * Team & Access "Last sign-in" column (core/helpers/teamSignIn.ts): a sign-in the server
 * WITHHOLDS (teacher shared with another school) is not "Not signed in yet".
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { signInColumn } from '../core/helpers/teamSignIn.ts';

test('a date is a date, whatever else is set', () => {
    assert.deepEqual(signInColumn({ last_sign_in_at: '2026-09-28T10:00:00+00:00' }), { kind: 'date', at: '2026-09-28T10:00:00+00:00' });
    assert.equal(signInColumn({ last_sign_in_at: '2026-09-28T10:00:00+00:00', shared: true }).kind, 'date');
});

test('a null sign-in on a shared teacher is "shared", not "never"', () => {
    assert.deepEqual(signInColumn({ last_sign_in_at: null, shared: true }), { kind: 'shared' });
});

test('a null sign-in on anyone else, or from an older backend with no marker, is "never"', () => {
    assert.deepEqual(signInColumn({ last_sign_in_at: null, shared: false }), { kind: 'never' });
    assert.deepEqual(signInColumn({ last_sign_in_at: null }), { kind: 'never' });
    assert.deepEqual(signInColumn({}), { kind: 'never' });
});

test('the Team screen renders the shared label from the helper, and the payload type carries the marker', () => {
    const view = readFileSync(new URL('../views/dashboard/TeamView.vue', import.meta.url), 'utf8');
    const types = readFileSync(new URL('../core/types/data/Capability.ts', import.meta.url), 'utf8');

    assert.match(view, /signInColumn\(p\)\.kind === 'shared'/);
    assert.match(view, /Shared login/);
    assert.match(types, /shared\?: boolean/);
});
