/**
 * The parent's marks screen names weights, subjects, types and the school's
 * standard (T-001.1 to T-001.3). Every word it prints must exist in all six
 * languages, or a parent in Pashto reads a raw key or a sentence half in English.
 * tests/Feature/FamilyLanguagesMirrorTest.php keeps the tables' key sets equal on
 * the server side; this proves the SCREEN uses only keys that exist.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (p: string) => readFileSync(new URL(p, import.meta.url), 'utf8');

const i18n = read('../views/family/familyI18n.ts');
const screen = read('../views/family/FamilyClass.vue');
const locales: Record<string, string> = {
    ur: read('../views/family/locales/ur.ts'),
    ps: read('../views/family/locales/ps.ts'),
    'fa-AF': read('../views/family/locales/fa-AF.ts'),
    es: read('../views/family/locales/es.ts'),
};

/** The keys of one table: a line that is `key: "..."` inside it. */
const keysOf = (source: string): Map<string, string> => {
    const out = new Map<string, string>();
    for (const m of source.matchAll(/^\s+([a-zA-Z0-9_]+): "((?:[^"\\]|\\.)*)",?$/gm)) out.set(m[1], m[2]);
    return out;
};

const enStart = i18n.indexOf('    en: {');
const arStart = i18n.indexOf('    ar: {');
const tables: Record<string, Map<string, string>> = {
    en: keysOf(i18n.slice(enStart, arStart)),
    ar: keysOf(i18n.slice(arStart)),
    ...Object.fromEntries(Object.entries(locales).map(([k, v]) => [k, keysOf(v)])),
};

const NEW_KEYS = [
    'marks_weighted_average', 'marks_weighted_note', 'marks_weighted_level',
    'marks_untyped_one', 'marks_untyped_two', 'marks_untyped_few', 'marks_untyped_many',
    'marks_section_subjects', 'marks_no_subject', 'marks_section_types', 'marks_type_counts', 'marks_weighted_short',
    'mark_type_test', 'mark_type_quiz', 'mark_type_homework', 'mark_type_classwork', 'mark_type_other',
    'marks_standard_source',
];

test('the parser found the tables it is checking', () => {
    for (const [lang, table] of Object.entries(tables)) {
        assert.ok(table.size > 200, `${lang} parsed to ${table.size} keys; the regex is not reading the table`);
    }
});

test('every new marks word exists, and is not empty, in all six languages', () => {
    for (const [lang, table] of Object.entries(tables)) {
        for (const key of NEW_KEYS) {
            assert.ok(table.has(key), `${lang} is missing ${key}`);
            assert.notEqual(table.get(key)!.trim(), '', `${lang}.${key} is empty`);
        }
    }
});

test('a slot the English sentence fills is a slot every translation fills', () => {
    for (const key of NEW_KEYS) {
        const slots = (tables.en.get(key)!.match(/\{x\}/g) ?? []).length;
        for (const [lang, table] of Object.entries(tables)) {
            // Arabic writes "one" and "two" as words (عمل واحد, عملان), so those two
            // forms carry no slot by design; every other form does.
            if (lang === 'ar' && /_(one|two)$/.test(key)) continue;
            assert.equal((table.get(key)!.match(/\{x\}/g) ?? []).length, slots, `${lang}.${key} must carry {x} ${slots} time(s), like English`);
        }
    }
});

test('the singular and the plural of the untyped sentence are different sentences in English and Arabic', () => {
    assert.notEqual(tables.en.get('marks_untyped_one'), tables.en.get('marks_untyped_many'));
    assert.notEqual(tables.ar.get('marks_untyped_one'), tables.ar.get('marks_untyped_two'));
    assert.notEqual(tables.ar.get('marks_untyped_two'), tables.ar.get('marks_untyped_few'));
    assert.notEqual(tables.ar.get('marks_untyped_few'), tables.ar.get('marks_untyped_many'));
});

test('every literal key the marks screen asks for exists in English, and every counted one has all four forms', () => {
    const plain = new Set<string>();
    const counted = new Set<string>();
    for (const m of screen.matchAll(/\bt\('([a-z][a-z0-9_]*)'/g)) plain.add(m[1]);
    for (const m of screen.matchAll(/\btCount\('([a-z][a-z0-9_]*)'/g)) counted.add(m[1]);

    for (const key of plain) assert.ok(tables.en.has(key), `FamilyClass.vue asks for t('${key}'), which English does not have`);
    for (const base of counted) {
        for (const form of ['one', 'two', 'few', 'many']) {
            assert.ok(tables.en.has(`${base}_${form}`), `FamilyClass.vue counts '${base}' but English has no ${base}_${form}`);
        }
    }
});

test('a parent sees only the five types the school knows, in their own language, and never a raw key', () => {
    assert.match(screen, /const WORK_TYPES = \['test', 'quiz', 'homework', 'classwork', 'other'\]/);
    assert.match(screen, /WORK_TYPES\.includes\(type\)/);
});

test('the screen never turns a levels mean into a percentage', () => {
    // percentText is only ever applied to the weighted/points/by-type figures, all
    // of which the server computes from POINTS work.
    const uses = [...screen.matchAll(/percentText\(([^)]*)\)/g)].map((m) => m[1]);
    for (const arg of uses) assert.equal(/level/i.test(arg), false, `percentText(${arg}) must not be given a levels figure`);
});
