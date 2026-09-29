/**
 * "Translate" over one school's class, and the parent switches schools mid-run.
 *
 * The screen for school A is unmounted by the school switch (FamilyLayout keys
 * its router-view by school), but its async run keeps going, and the run read
 * the school id from the SHARED route on every batch. So batch 2 of a run that
 * began at school A was POSTed to school B's translations endpoint, carrying
 * A's posts and messages, under B's bearer token (tokenForUrl picks the token
 * from the URL). A run must belong to the school it started at.
 *
 * Run: npm run test:spa
 */
import { test, before, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { register } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

register(
    'data:text/javascript,' + encodeURIComponent(`
        export async function resolve(specifier, context, next) {
            if (specifier.startsWith('@/')) {
                return next(${JSON.stringify(pathToFileURL(appRoot).href)} + '/' + specifier.slice(2) + '.ts', context);
            }
            return next(specifier, context);
        }
    `),
);

const data = new Map<string, string>();
(globalThis as any).localStorage = {
    getItem: (k: string) => (data.has(k) ? data.get(k)! : null),
    setItem: (k: string, v: string) => void data.set(k, String(v)),
    removeItem: (k: string) => void data.delete(k),
};

let FamilyApiService: any;
let useContentTranslation: any;
let ref: any;
let effectScope: any;

/** Every request the client sent: url, bearer header and body. */
const sent: { url: string; auth: string | null; body: any }[] = [];
/** Called as each request is "answered", so a test can act mid-run. */
let onRequest: (n: number) => void = () => {};

before(async () => {
    ({ default: FamilyApiService } = await import('@/core/services/FamilyApiService' as string));
    ({ useContentTranslation } = await import('@/views/family/useContentTranslation' as string));
    ({ ref, effectScope } = await import('vue'));

    FamilyApiService.init('https://manara.example.test');
    FamilyApiService.client.defaults.adapter = async (config: any) => {
        const body = typeof config.data === 'string' ? JSON.parse(config.data) : config.data;
        sent.push({
            url: config.url,
            auth: config.headers.get?.('Authorization') ?? config.headers.Authorization ?? null,
            body,
        });
        onRequest(sent.length);
        const translations: Record<string, string> = {};
        for (const item of body?.items ?? []) translations[item.key] = `ur:${item.text}`;

        return { data: { data: { translations } }, status: 200, statusText: '', headers: {}, config, request: {} };
    };
});

beforeEach(() => {
    data.clear();
    sent.length = 0;
    onRequest = () => {};
    // A parent signed in to both schools, so B's token really would be attached.
    data.set('MANARA_FAMILY_SESSIONS', JSON.stringify({
        '7': { token: 'tok-7', contact: { id: 70, masjid_id: 7 } },
        '9': { token: 'tok-9', contact: { id: 90, masjid_id: 9 } },
    }));
});

/** 45 short items: three batches at the endpoint's 20-item cap. */
const items = () => Array.from({ length: 45 }, (_, i) => ({ key: `post:${i}`, text: `School A story ${i}` }));

test('a school switch mid-run stops the run: nothing of school A is sent to school B', async () => {
    const masjidId = ref('7');
    const scope = effectScope();
    const tx = scope.run(() => useContentTranslation(masjidId, { target: ref('ur') }));

    // The parent picks school B from "Your schools" while batch 1 is in flight.
    onRequest = (n) => { if (n === 1) masjidId.value = '9'; };

    await tx.translate(items());

    assert.equal(sent.length, 1, 'batch 1 was already on the wire; no later batch goes anywhere');
    assert.equal(sent[0].url, '/api/family/masjids/7/translations');
    assert.equal(sent[0].auth, 'Bearer tok-7');
    assert.equal(sent.filter((r) => r.url.includes('/masjids/9/')).length, 0, 'school B received nothing');
    assert.equal(tx.hasTranslations.value, false, 'the reply for the school left behind is not shown as translated');
    scope.stop();
});

test('an unmounted screen stops its run even if the route id has not moved yet', async () => {
    const masjidId = ref('7');
    const scope = effectScope();
    const tx = scope.run(() => useContentTranslation(masjidId, { target: ref('ur') }));

    // Unmount = the component's effect scope stops.
    onRequest = (n) => { if (n === 1) scope.stop(); };

    await tx.translate(items());

    assert.equal(sent.length, 1, 'a disposed screen sends no further batch');
});

test('a run that stays on its school still sends every batch, all to that school with its own token', async () => {
    const masjidId = ref('7');
    const scope = effectScope();
    const tx = scope.run(() => useContentTranslation(masjidId, { target: ref('ur') }));

    await tx.translate(items());

    assert.equal(sent.length, 3);
    assert.deepEqual([...new Set(sent.map((r) => r.url))], ['/api/family/masjids/7/translations']);
    assert.deepEqual([...new Set(sent.map((r) => r.auth))], ['Bearer tok-7']);
    assert.equal(tx.tx('post:44', 'School A story 44'), 'ur:School A story 44');
    scope.stop();
});
