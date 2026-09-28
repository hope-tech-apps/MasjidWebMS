/**
 * The runs of tiles a letter tracker draws (core/helpers/letterRuns.ts):
 * English is two runs of 26 (Capitals, Lower case) with their own counts,
 * Arabic stays one run of one tile per letter.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { letterRuns } from '../core/helpers/letterRuns.ts';

const english = () => {
    const letters = 'abc'.split('').map((id) => ({
        id,
        glyph: `${id.toUpperCase()}${id}`,
        transliteration: id.toUpperCase(),
        status: 'learning',
        drills: [
            { id: `${id}.upper`, set: 'upper', text: id.toUpperCase(), label: `Capital ${id.toUpperCase()}`, status: id === 'a' ? 'mastered' : 'not_started' },
            { id: `${id}.lower`, set: 'lower', text: id, label: `Lower case ${id}`, status: 'not_started' },
        ],
    }));
    return {
        alphabet: 'english',
        letters,
        sets: [{ id: 'upper', label: 'Capitals' }, { id: 'lower', label: 'Lower case' }],
        set_totals: [
            { id: 'upper', label: 'Capitals', mastered: 1, total: 3 },
            { id: 'lower', label: 'Lower case', mastered: 0, total: 3 },
        ],
    };
};

test('a split track is one run per set, capitals first, one tile per drill', () => {
    const runs = letterRuns(english());
    assert.deepEqual(runs.map((r) => r.id), ['upper', 'lower']);
    assert.deepEqual(runs.map((r) => r.label), ['Capitals', 'Lower case']);
    assert.deepEqual(runs[0].tiles.map((t) => t.text), ['A', 'B', 'C']);
    assert.deepEqual(runs[1].tiles.map((t) => t.text), ['a', 'b', 'c']);
    assert.deepEqual(runs[0].tiles.map((t) => t.key), ['a.upper', 'b.upper', 'c.upper']);
});

test('each run carries its own count and a tile keeps its own drill status', () => {
    const [upper, lower] = letterRuns(english());
    assert.deepEqual([upper.mastered, upper.total, lower.mastered, lower.total], [1, 3, 0, 3]);
    assert.equal(upper.tiles[0].status, 'mastered');
    assert.equal(lower.tiles[0].status, 'not_started');
});

test('a tile opens its letter and names the drill for the tooltip', () => {
    const [upper] = letterRuns(english());
    assert.equal(upper.tiles[1].letterId, 'b');
    assert.equal(upper.tiles[1].drillId, 'b.upper');
    assert.equal(upper.tiles[1].title, 'Capital B');
});

test('without sets (Arabic) there is one unlabelled run of one tile per letter', () => {
    const runs = letterRuns({
        alphabet: 'arabic',
        sets: [],
        set_totals: [],
        letters: [
            { id: 'alif', glyph: 'ا', transliteration: 'Alif', status: 'mastered', drills: [] },
            { id: 'ba', glyph: 'ب', transliteration: 'Ba', status: 'not_started', drills: [] },
        ],
    });
    assert.equal(runs.length, 1);
    assert.equal(runs[0].label, null);
    assert.deepEqual(runs[0].tiles.map((t) => [t.key, t.text, t.status]), [['alif', 'ا', 'mastered'], ['ba', 'ب', 'not_started']]);
});

test('an old payload with no sets key at all still draws (a stale cached response)', () => {
    assert.equal(letterRuns({ letters: [{ id: 'a', glyph: 'Aa', status: 'not_started' }] })[0].tiles.length, 1);
    assert.deepEqual(letterRuns(null)[0].tiles, []);
});

test('a missing set_totals falls back to counting the tiles it drew', () => {
    const t = english();
    delete (t as any).set_totals;
    const [upper] = letterRuns(t);
    assert.deepEqual([upper.mastered, upper.total], [1, 3]);
});

/**
 * The three screens must draw through the helper. The suite has no component
 * renderer, so this reads their source: a screen that went back to one tile per
 * letter would show English as 26 again while the server counts 52.
 */
const source = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

test('the teacher, office and family screens each draw the runs the helper returns', () => {
    for (const path of [
        '../views/teacher/TeacherClass.vue',
        '../views/dashboard/groups/GroupLettersTab.vue',
        '../views/family/FamilyClass.vue',
    ]) {
        const view = source(path);
        assert.match(view, /from '@\/core\/helpers\/letterRuns'/, `${path} imports the helper`);
        assert.match(view, /v-for="run in letterRunsOf/, `${path} loops over the runs`);
        assert.match(view, /v-for="tile in run\.tiles"/, `${path} draws one tile per drill in a run`);
        assert.doesNotMatch(view, /v-for="l in (?:tracker\??\.letters|track\.letters)" :key="l\.id"(?:[^>]*)(?:letter-tile|letter-chip)/,
            `${path} no longer draws one tile per letter`);
    }
});

test('the family portal names both sets in every language it ships', () => {
    for (const path of [
        '../views/family/familyI18n.ts',
        '../views/family/locales/es.ts',
        '../views/family/locales/fa-AF.ts',
        '../views/family/locales/ur.ts',
        '../views/family/locales/ps.ts',
    ]) {
        const text = source(path);
        assert.match(text, /letters_set_upper:/, `${path} names the capitals`);
        assert.match(text, /letters_set_lower:/, `${path} names the lower case`);
    }
});
