/**
 * The parent portal's six word tables (familyI18n.ts en and ar, locales/ ur, ps, fa-AF, es):
 * every table carries every key exactly once.
 *
 * A JS object literal keeps the LAST of two equal keys without a word, so a block pasted into
 * the wrong table does not fail: the English portal quietly prints Arabic (this is the bug
 * that put the weekly report's Arabic labels on an English page during T-003.3), and a table
 * missing a key falls back to English mid-screen. FamilyLanguagesMirrorTest.php already checks
 * the four locale files against English; this also covers English against Arabic and
 * duplicates inside any one table.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const dir = new URL('../views/family/', import.meta.url);
const read = (rel: string) => readFileSync(new URL(rel, dir), 'utf8');

/** Keys of one object-literal body, in order, one `key: "value",` per line (the layout the mirror test also relies on). */
const keysOf = (body: string, indent: number): string[] =>
    [...body.matchAll(new RegExp(`^ {${indent}}([a-zA-Z0-9_]+):`, 'gm'))].map((m) => m[1]);

const i18n = read('familyI18n.ts');
const enStart = i18n.indexOf('\n    en: {');
const arStart = i18n.indexOf('\n    ar: {');
const tables: Record<string, string[]> = {
    en: keysOf(i18n.slice(enStart, arStart), 8),
    ar: keysOf(i18n.slice(arStart), 8),
    ur: keysOf(read('locales/ur.ts'), 4),
    ps: keysOf(read('locales/ps.ts'), 4),
    'fa-AF': keysOf(read('locales/fa-AF.ts'), 4),
    es: keysOf(read('locales/es.ts'), 4),
};

test('the tables were found and are not trivially small', () => {
    for (const [lang, keys] of Object.entries(tables)) {
        assert.ok(keys.length > 150, `${lang} has only ${keys.length} keys: has the layout changed?`);
    }
});

test('no table holds the same key twice', () => {
    for (const [lang, keys] of Object.entries(tables)) {
        const dupes = keys.filter((k, i) => keys.indexOf(k) !== i);
        assert.deepEqual(dupes, [], `${lang} repeats a key: the last one silently wins`);
    }
});

test('every table has exactly the English keys', () => {
    const english = new Set(tables.en);
    for (const [lang, keys] of Object.entries(tables)) {
        if (lang === 'en') continue;
        const set = new Set(keys);
        assert.deepEqual([...english].filter((k) => !set.has(k)), [], `${lang} is missing keys`);
        assert.deepEqual([...set].filter((k) => !english.has(k)), [], `${lang} has keys English does not`);
    }
});
