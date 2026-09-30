/**
 * Every parent-portal screen that uses a name from useFamilyLang() must take it from the call.
 *
 * FamilyClass.vue used `isAr.value` while its destructure took only `isRtl`, so a parent who
 * opened a conversation hit a ReferenceError (live 2026-09-21 to 2026-09-30). `.vue` files cannot
 * be loaded by `node --test`, so this checks each screen's source: any composable name used as
 * `name.value` or `name(` must appear in that screen's `= useFamilyLang()` destructure.
 *
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (rel: string) => readFileSync(path.join(appRoot, rel), 'utf8');

/** The names useFamilyLang() returns, read from its own return statement. */
function composableNames(): string[] {
    const src = read('views/family/familyI18n.ts');
    const block = src.slice(src.lastIndexOf('return {'));
    const body = block.slice('return {'.length, block.indexOf('};'));
    return body.split(',').map((part) => part.split(':')[0].trim()).filter((n) => /^[A-Za-z_]\w*$/.test(n));
}

function screensUsingIt(): string[] {
    const dirs = ['views/family', 'components'];
    const out: string[] = [];
    const walk = (rel: string) => {
        for (const entry of readdirSync(path.join(appRoot, rel), { withFileTypes: true })) {
            const next = path.join(rel, entry.name);
            if (entry.isDirectory()) walk(next);
            else if (entry.name.endsWith('.vue') && read(next).includes('useFamilyLang(')) out.push(next);
        }
    };
    dirs.forEach(walk);
    return out;
}

test('the composable names are read from familyI18n', () => {
    const names = composableNames();
    for (const n of ['lang', 'isAr', 'isRtl', 't']) assert.ok(names.includes(n), `useFamilyLang returns ${n}`);
});

test('no parent-portal screen uses a useFamilyLang name it did not take from the call', () => {
    const names = composableNames();
    const screens = screensUsingIt();
    assert.ok(screens.length >= 5, 'the parent-portal screens were found');

    for (const file of screens) {
        const src = read(file);
        // Code only: comments mention these words in prose ("the language toggle (…)").
        const script = src.slice(src.indexOf('<script'))
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/(^|[^:'"`])\/\/.*$/gm, '$1');
        const m = script.match(/const\s*\{([^}]*)\}\s*=\s*useFamilyLang\(\)/);
        if (!m) continue; // a screen that keeps the whole object (e.g. `const lang = useFamilyLang()`) uses it as `lang.x`
        const taken = new Set(m[1].split(',').map((p) => p.split(':').pop()!.trim()).filter(Boolean));
        for (const name of names) {
            if (taken.has(name)) continue;
            const used = new RegExp(`(?<![\\w.$])${name}(\\.value\\b|\\s*\\()`).test(script);
            assert.ok(!used, `${file} uses \`${name}\` without taking it from useFamilyLang()`);
        }
    }
});

test('FamilyClass reads the list comma from isRtl, which it takes', () => {
    const src = read('views/family/FamilyClass.vue');
    assert.match(src, /and: isRtl\.value \? '، ' : ', ',/);
    assert.doesNotMatch(src, /\bisAr\.value\b/);
});
