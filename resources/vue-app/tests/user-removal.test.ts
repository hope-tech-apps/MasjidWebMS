/**
 * The SuperAdmin's delete and archive confirmations (core/helpers/userRemoval.ts).
 * Both actions are global; a login can belong to several organisations now, so the
 * dialog must name every one and say "all N". Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { removalReach, removalWarning } from '../core/helpers/userRemoval.ts';

const identity = (s: string) => s;
const escape = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const alrazi = { name: 'Al-Razi School' };
const biss = { name: 'BISS' };

test('a login in two organisations is told it removes them from ALL 2, by name', () => {
    const text = removalWarning('delete', 'Moneeb', [alrazi, biss], identity);

    assert.match(text, /from all 2 organisations: Al-Razi School, BISS/);
    assert.match(text, /Moneeb/);
    assert.match(text, /erases their login for good/);
});

test('archive says the same count, and that the lockout covers every organisation', () => {
    const text = removalWarning('archive', 'Moneeb', [alrazi, biss, { name: 'Gamma' }], identity);

    assert.match(text, /from all 3 organisations: Al-Razi School, BISS, Gamma/);
    assert.match(text, /locks their login out/);
    assert.doesNotMatch(text, /erases/);
});

test('the dialog points at the per-school screens for a one-school removal', () => {
    assert.match(removalWarning('delete', 'x', [alrazi, biss], identity), /that school's own Teachers or Team & Access screen/);
});

test('one organisation is named, but is not "all N"', () => {
    const text = removalWarning('delete', 'Moneeb', [alrazi], identity);

    assert.match(text, /who belongs to Al-Razi School/);
    assert.doesNotMatch(text, /all \d+ organisations/);
});

test('a login in no organisation keeps the plain wording', () => {
    assert.equal(removalWarning('delete', 'Moneeb', [], identity), 'You are going to delete Moneeb !');
    assert.equal(removalWarning('archive', 'Moneeb', undefined, identity), 'You are going to archive Moneeb !');
    assert.equal(removalWarning('archive', '', null, identity), 'You are going to archive this user !');
});

test('an archived organisation is still listed, and flagged', () => {
    assert.deepEqual(removalReach([alrazi, { name: 'Old School', archived: true }]), ['Al-Razi School', 'Old School (archived)']);
});

test('blank names are dropped, so the count is never padded', () => {
    assert.deepEqual(removalReach([alrazi, { name: '  ' }, { name: '' }]), ['Al-Razi School']);
    assert.match(removalWarning('delete', 'x', [alrazi, { name: '' }], identity), /who belongs to Al-Razi School/);
});

test('names go through the dialog escaper, so an organisation cannot inject markup', () => {
    const text = removalWarning('delete', '<b>Eve</b>', [{ name: '<img src=x onerror=alert(1)>' }, biss], escape);

    assert.doesNotMatch(text, /<img/);
    assert.match(text, /&lt;img src=x onerror=alert\(1\)&gt;/);
    assert.match(text, /&lt;b&gt;Eve&lt;\/b&gt;/);
});

test('both buttons on the user screen build their words with it', () => {
    const view = readFileSync(new URL('../views/dashboard/super/user/UserDetailsView.vue', import.meta.url), 'utf8');

    assert.match(view, /removalWarning\('delete', user\.value\?\.name \?\? '', user\.value\?\.organisations, escapeHtml\)/);
    assert.match(view, /removalWarning\('archive', user\.value\?\.name \?\? '', user\.value\?\.organisations, escapeHtml\)/);
    assert.doesNotMatch(view, /'You are going to (delete|archive) this user !'/, 'the single-organisation wording is gone');
});
