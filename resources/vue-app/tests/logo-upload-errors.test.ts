/**
 * A refused logo upload shows the server's own sentence (the image decoding audit, 2026-09-29).
 * Both logo forms post to endpoints that refuse in the legacy envelope, {status:'failed', data:{logo:[…]}},
 * and the guards being added (a pixel ceiling, a size cap) are only useful if the admin reads why.
 * swalMethods.ts imports through the "@/" alias, which node cannot resolve, so this reads the source.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

const swal = read('../assets/ts/swalMethods.ts');

const views = {
    'the organisation details page': read('../views/dashboard/MosqueDetailsView.vue'),
    'the super-admin organisation form': read('../views/dashboard/super/masjid/MasjidFormView.vue'),
};

/** Every `.catch((e: AxiosError…) => { … })` body in a file. */
const catchBodies = (source: string): string[] =>
    [...source.matchAll(/\.catch\(\(e: AxiosError<BackendResponseData>\) => \{([\s\S]*?)\n\s*\}\)/g)].map((m) => m[1]);

for (const [name, source] of Object.entries(views)) {
    test(`${name} sends the logo and shows the server's reason when it is refused`, () => {
        assert.match(source, /\.append\(\s*[`'"]logo[`'"]\s*,\s*logoFile\.value\s*\)/, 'the logo travels as `logo`');
        assert.match(source, /import \{ getMessageFromObj \} from '@\/assets\/ts\/swalMethods'/);

        const bodies = catchBodies(source);
        assert.ok(bodies.length > 0, 'the premise: the request has an error branch');
        for (const body of bodies) {
            assert.match(body, /swalInstance\.text = getMessageFromObj\(e\);/, 'the refusal is shown as the dialog text');
        }
    });
}

test('an error response is read from errors, then the legacy data envelope, then message', () => {
    const errorBranch = swal.slice(swal.indexOf('if(isAxiosError(obj))'), swal.indexOf('} else {'));
    assert.match(
        errorBranch,
        /obj\.response\.data\?\.errors\s*\?\?\s*obj\.response\.data\?\.data\s*\?\?\s*obj\.response\.data\?\.message/,
    );
    assert.match(errorBranch, /status === 413/, 'a file too large for nginx gets its own sentence');
});

test('a field-keyed refusal is flattened to its sentences, not printed as JSON', () => {
    const flatten = swal.slice(swal.indexOf('const flattenMessage'), swal.indexOf('export const getMessageFromObj'));
    assert.match(flatten, /Array\.isArray\(payload\)/);
    assert.match(flatten, /Object\.values\(payload as Record<string, unknown>\)/);
    assert.doesNotMatch(flatten, /JSON\.stringify/);
});
