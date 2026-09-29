/**
 * A flyer photo the server refuses shows the server's reason, without telling the admin to try again
 * (the image decoding audit, 2026-09-29). A photo too large to cut out safely is refused with a 422 whose
 * `message` names the size and the fix; sending the same file again gets the same 422.
 * flyersStore.ts imports through the "@/" alias, which node cannot resolve, so this reads the source.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const store = readFileSync(new URL('../stores/masjid/flyersStore.ts', import.meta.url), 'utf8');
const start = store.indexOf('function uploadFailureMessage(');
const fn = store.slice(start, store.indexOf('\n    }\n', start));

test('the refusal reads the server message, then the legacy field envelope', () => {
    assert.ok(start > 0, 'the premise: uploadFailureMessage exists');
    assert.match(fn, /response\?\.data\?\.message \|\| response\?\.data\?\.data\?\.image\?\.\[0\]/);
});

test('a 422 shows the reason alone, and only other failures suggest trying again', () => {
    const refusal = fn.slice(fn.indexOf('if (response?.status === 422 && server)'));
    assert.ok(fn.includes('if (response?.status === 422 && server)'), 'a refusal has its own branch');
    const branch = refusal.slice(0, refusal.indexOf('}'));
    assert.match(branch, /return server \+ ' It is still on the flyer and will be included in the export\.';/);
    assert.doesNotMatch(branch, /try again/);
    assert.match(fn.slice(fn.indexOf(branch) + branch.length), /try again to have the background removed/);
});
