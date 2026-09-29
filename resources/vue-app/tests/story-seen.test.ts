/**
 * The staff "Seen by" line for a story that predates recording, the shape of the
 * family payload's `story_reads`, and what the parent-facing notice promises.
 *
 * `.vue` files cannot be loaded by `node --test`, so the wiring is pinned as
 * source text, the way letter-runs.test.ts does it.
 *
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = (rel: string) => readFileSync(path.join(appRoot, rel), 'utf8');

test('a story that predates recording says so, in the school\'s own day', async () => {
    const { notTrackedLabel } = await import(path.join(appRoot, 'core/helpers/storySeen.ts'));

    assert.equal(notTrackedLabel('2026-10-05'), 'Not tracked before Oct 5, 2026');
    // The browser's zone must not move the day the school named.
    assert.equal(notTrackedLabel('2026-01-01'), 'Not tracked before Jan 1, 2026');
    assert.equal(notTrackedLabel(null), 'Not tracked before recording began');
    assert.equal(notTrackedLabel('soon'), 'Not tracked before recording began');
});

test('both staff screens hand the line the tracked flag and the date, and the line draws them', () => {
    for (const rel of ['views/dashboard/groups/GroupStoryTab.vue', 'views/teacher/TeacherClass.vue']) {
        const view = source(rel);
        assert.match(view, /:tracked="post\.seen_tracked"/, `${rel} passes seen_tracked`);
        assert.match(view, /:since="post\.seen_since"/, `${rel} passes seen_since`);
    }

    const line = source('components/common/StorySeenLine.vue');
    assert.match(line, /v-if="enabled && untracked"/, 'an untracked story never falls through to "Seen by 0 of N"');
    assert.match(line, /notTrackedLabel\(since\)/);
    assert.match(line, /props\.tracked === false/);
});

test('the family screen reads the same object shape the staff payloads use', () => {
    const view = source('views/family/FamilyClass.vue');

    assert.match(view, /meta\?\.story_reads\?\.enabled === true/, 'the switch is `meta.story_reads.enabled`, not a bare bool');
    assert.ok(!/meta\?\.story_reads === true/.test(view));
});

test('the notice says PARENTS and WHEN in every language: staff see each guardian by name with a time', () => {
    const noticeIn = (rel: string) => [...source(rel).matchAll(/story_seen_notice:\s*"([^"]+)"/g)].map((m) => m[1]);

    const en = noticeIn('views/family/familyI18n.ts');
    assert.equal(en.length, 2, 'English and Arabic live in familyI18n.ts');
    assert.match(en[0], /which parents have opened/);
    assert.match(en[0], /and when/);
    assert.ok(!/famil/i.test(en[0]), 'the school sees parents, not "families"');

    // [file, the word for parents, the old word for families that must be gone]
    const others: [string, RegExp, RegExp][] = [
        ['views/family/familyI18n.ts', /أولياء الأمور/, /العائلات/],
        ['views/family/locales/es.ts', /padres/, /familias/],
        ['views/family/locales/ur.ts', /والدین/, /خاندان/],
        ['views/family/locales/ps.ts', /والدینو/, /کورن/],
        ['views/family/locales/fa-AF.ts', /والدین/, /خانواده/],
    ];

    for (const [rel, parents, families] of others) {
        const text = rel.endsWith('familyI18n.ts') ? en[1] : noticeIn(rel)[0];
        assert.match(text, parents, `${rel} names parents`);
        assert.ok(!families.test(text), `${rel} no longer says families`);
    }
});
