/**
 * The runs of tiles a letter tracker draws (core/helpers/letterRuns.ts):
 * English is two runs of 26 (Capitals, Lower case) with their own counts,
 * Arabic stays one run of one tile per letter.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { drillCaption, letterIdOfTile, letterRuns, toggledTileKey } from '../core/helpers/letterRuns.ts';

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

test('tapping the other case of the open letter switches to it instead of closing the card', () => {
    const runs = letterRuns(english());
    const [upper, lower] = runs;
    const capitalA = upper.tiles[0];
    const smallA = lower.tiles[0];
    // Both tiles open the SAME letter: the reason the old toggle on letterId closed the card.
    assert.equal(capitalA.letterId, smallA.letterId);

    const afterCapital = toggledTileKey(null, capitalA);
    assert.equal(afterCapital, 'a.upper');
    const afterSmall = toggledTileKey(afterCapital, smallA);
    assert.equal(afterSmall, 'a.lower', 'the card stays open on the tile just tapped');
    assert.equal(letterIdOfTile(runs, afterSmall), 'a');
    assert.equal(toggledTileKey(afterSmall, smallA), null, 'tapping the open tile again closes it');
    assert.equal(toggledTileKey(afterCapital, upper.tiles[1]), 'b.upper', 'another letter opens its own card');
});

test('an unsplit track toggles on the letter as it always did, and nothing open resolves to no letter', () => {
    const runs = letterRuns({ letters: [{ id: 'alif', glyph: 'ا', status: 'mastered' }, { id: 'ba', glyph: 'ب', status: 'not_started' }] });
    const [alif, ba] = runs[0].tiles;
    assert.equal(toggledTileKey(null, alif), 'alif');
    assert.equal(toggledTileKey('alif', alif), null);
    assert.equal(toggledTileKey('alif', ba), 'ba');
    assert.equal(letterIdOfTile(runs, 'ba'), 'ba');
    assert.equal(letterIdOfTile(runs, null), null);
    assert.equal(letterIdOfTile(runs, 'gone'), null);
});

test('a family note beside an English drill names the set in the portal language, not the server English', () => {
    const urdu = (id: string) => ({ upper: 'بڑے حروف', lower: 'چھوٹے حروف' } as Record<string, string>)[id];
    assert.equal(drillCaption({ set: 'lower', label: 'Lower case a' }, urdu), 'چھوٹے حروف');
    assert.equal(drillCaption({ set: 'upper', label: 'Capital A' }, urdu), 'بڑے حروف');
    // An Arabic drill has no set: its label is the letter's own name and stays.
    assert.equal(drillCaption({ set: null, label: 'Alif' }, urdu), 'Alif');
    assert.equal(drillCaption({ label: undefined }, urdu), '');
});

test('a tile is captioned with its letter name, and the family tooltip with the drill label', () => {
    const named = letterRuns(english());
    assert.deepEqual(named[0].tiles[0].name, 'A');
    assert.equal(named[1].tiles[0].name, 'A', 'the lower-case tile is captioned with the letter, not the drill');
    assert.equal(named[1].tiles[0].title, 'Lower case a');

    // No transliteration: the split tile falls back to the drill label, an unsplit one to its glyph.
    const bare = letterRuns({
        letters: [{ id: 'z', glyph: 'Zz', status: 'not_started', drills: [{ id: 'z.upper', set: 'upper', text: 'Z', label: 'Capital Z', status: 'not_started' }] }],
        sets: [{ id: 'upper', label: 'Capitals' }],
    });
    assert.equal(bare[0].tiles[0].name, 'Capital Z');

    const unsplit = letterRuns({ letters: [{ id: 'ba', glyph: 'ب', status: 'x' }, { id: 'ta', glyph: 'ت', transliteration: 'Ta', status: 'x' }] })[0].tiles;
    assert.deepEqual(unsplit.map((t) => [t.name, t.title]), [['ب', 'ب'], ['Ta', 'Ta']]);
});

test("a run's count is the server's set_totals, not a recount of the tiles drawn", () => {
    const t = english();
    // A payload for a class where the server counts more drills than the tiles drawn here.
    t.set_totals = [
        { id: 'upper', label: 'Capitals', mastered: 20, total: 26 },
        { id: 'lower', label: 'Lower case', mastered: 3, total: 26 },
    ];
    const [upper, lower] = letterRuns(t);
    assert.deepEqual([upper.mastered, upper.total, lower.mastered, lower.total], [20, 26, 3, 26]);
    assert.equal(typeof upper.mastered, 'number');
});

test('every screen draws the count as mastered over total, toggles by tile, and the family notes use the portal caption', () => {
    const teacher = source('../views/teacher/TeacherClass.vue');
    const office = source('../views/dashboard/groups/GroupLettersTab.vue');
    const family = source('../views/family/FamilyClass.vue');

    assert.match(teacher, /\{\{ run\.mastered \}\} \/ \{\{ run\.total \}\}/);
    assert.match(office, /\{\{ run\.mastered \}\} \/ \{\{ run\.total \}\}/);
    assert.match(family, /\{\{ run\.mastered \}\} \{\{ t\('count_of'\) \}\} \{\{ run\.total \}\}/);

    for (const [name, view] of [['teacher', teacher], ['office', office]] as const) {
        assert.match(view, /@click="openTile = toggledTileKey\(openTile, tile\)"/, `${name}: a tile toggles by its own key`);
        assert.doesNotMatch(view, /openTile === tile\.letterId|\bopenLetter\b/, `${name}: never toggles on the shared letter id`);
        assert.match(view, /letterIdOfTile\(letterRunsOf\.value, openTile\.value\)/, `${name}: the card's letter comes from the open tile`);
    }

    assert.match(family, /\{\{ noteCaption\(n\.drill\) \}\}/);
    assert.doesNotMatch(family, /\{\{ n\.drill\.label \}\}/, 'the English server label is never printed in the notes list');
});
