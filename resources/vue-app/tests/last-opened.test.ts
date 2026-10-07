/**
 * "Last opened this organisation" (core/helpers/lastOpened.ts), the per-organisation
 * replacement for the global last sign-in on Team & Access and the Teachers list.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { lastOpenedLabel, NOT_OPENED_TEXT, formatLastOpened } from '../core/helpers/lastOpened.ts';

const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

test('a date is formatted with its time, in the zone asked for', () => {
    assert.equal(formatLastOpened('2026-09-29T14:05:00+00:00', 'en-US', 'UTC'), 'Sep 29, 2026, 2:05 PM');
    assert.equal(formatLastOpened('2026-09-29T14:05:00+00:00', 'en-US', 'America/New_York'), 'Sep 29, 2026, 10:05 AM');
});

test('null, empty and garbage are no date at all, never "Invalid Date"', () => {
    assert.equal(formatLastOpened(null), '');
    assert.equal(formatLastOpened(undefined), '');
    assert.equal(formatLastOpened(''), '');
    assert.equal(formatLastOpened('not a date'), '');
});

test('the label says what the value is, and "not opened" does not claim they never signed in', () => {
    for (const [organization, label] of [['Masjid', 'masjid'], ['School', 'school'], ['Organization', 'organization']]) {
        assert.equal(lastOpenedLabel(organization), `Last opened this ${label}`);
    }
    assert.doesNotMatch(NOT_OPENED_TEXT, /signed in/i);
});

test('Team & Access and the Teachers list read last_seen_at and use the label, not the global sign-in', () => {
    for (const file of ['../views/dashboard/TeamView.vue', '../views/dashboard/TeachersView.vue']) {
        const view = read(file);

        assert.match(view, /\{\{ lastOpenedLabel\(masjidStore\.term\('organization'\)\) \}\}/, `${file} names the column`);
        assert.match(view, /\.last_seen_at/, `${file} reads last_seen_at`);
        assert.match(view, /formatLastOpened\(/, `${file} formats it with the helper`);
        assert.doesNotMatch(view, /last_sign_in_at/, `${file} no longer reads the global sign-in`);
        assert.doesNotMatch(view, /Last sign-in|Shared login|Not signed in yet/, `${file} has none of the old words`);
    }
});

test('the payload types carry last_seen_at and no last_sign_in_at', () => {
    const team = read('../core/types/data/Capability.ts');
    const teacher = read('../core/types/data/masjid-related/Teacher.ts');

    assert.match(team, /last_seen_at: string \| null;/);
    assert.doesNotMatch(team, /last_sign_in_at/);
    assert.match(teacher, /last_seen_at\?: string \| null;/);
});
