/**
 * The parent portal's real HTTP client and store, end to end, with the network
 * replaced by a recording adapter: two schools signed in at once, each request
 * signed with its own school's token, one school's sign-out or 401 leaving the
 * other alone.
 *
 * The app imports through the `@/` alias, which node does not know, so a small
 * resolve hook maps it before the modules are loaded.
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

// A localStorage the modules under test see as the browser's.
const data = new Map<string, string>();
(globalThis as any).localStorage = {
    getItem: (k: string) => (data.has(k) ? data.get(k)! : null),
    setItem: (k: string, v: string) => void data.set(k, String(v)),
    removeItem: (k: string) => void data.delete(k),
};

let FamilyApiService: any;
let useFamilyStore: any;
let setActivePinia: any;
let createPinia: any;

/** Every request the client sent: url, method and Authorization header. */
const sent: { url: string; method: string; auth: string | null }[] = [];
/** What the fake network answers, by URL substring; default 200 {}. */
let respond: (url: string) => { status: number; data?: any } = () => ({ status: 200, data: {} });

before(async () => {
    ({ default: FamilyApiService } = await import('@/core/services/FamilyApiService' as string));
    ({ useFamilyStore } = await import('@/stores/familyStore' as string));
    ({ setActivePinia, createPinia } = await import('pinia'));

    FamilyApiService.init('https://manara.example.test');
    FamilyApiService.client.defaults.adapter = async (config: any) => {
        const url = config.url as string;
        sent.push({ url, method: config.method, auth: config.headers.get?.('Authorization') ?? config.headers.Authorization ?? null });
        const { status, data: body } = respond(url);
        const response = { data: body ?? {}, status, statusText: '', headers: {}, config, request: {} };
        if (status >= 400) {
            const err: any = new Error(`Request failed with status code ${status}`);
            err.config = config;
            err.response = response;
            throw err;
        }
        return response;
    };
});

beforeEach(() => {
    data.clear();
    sent.length = 0;
    respond = () => ({ status: 200, data: {} });
    setActivePinia(createPinia());
});

const contact = (masjid_id: number) => ({ id: masjid_id * 10, masjid_id, first_name: 'Amal', last_name: 'P', login_email: 'a@example.test' });

/** Sign in to one school through the store's real password door. */
async function signIn(store: any, masjidId: number, token: string) {
    respond = () => ({ status: 200, data: { data: { token, contact: contact(masjidId) } } });
    await store.signInWithPassword(String(masjidId), 'a@example.test', 'pw');
    respond = () => ({ status: 200, data: {} });
}

test('two slots coexist: signing in to B does not sign out A', async () => {
    const store = useFamilyStore();

    await signIn(store, 7, 'tok-7');
    await signIn(store, 9, 'tok-9');

    assert.equal(store.isSignedInTo(7), true);
    assert.equal(store.isSignedInTo(9), true);
    assert.deepEqual(store.signedInMasjidIds, ['7', '9']);
    assert.equal(store.displayNameFor(7), 'Amal P');
});

test('switching schools uses the right token: each request carries only its own school\'s', async () => {
    const store = useFamilyStore();
    await signIn(store, 7, 'tok-7');
    await signIn(store, 9, 'tok-9');
    sent.length = 0;

    await FamilyApiService.get('/api/family/masjids/7/groups');
    await FamilyApiService.get('/api/family/masjids/9/groups');
    await FamilyApiService.post('/api/family/masjids/7/groups/3/threads', { body: 'x' });
    await FamilyApiService.get('/api/family/masjids/9/me');

    assert.deepEqual(sent.map((r) => r.auth), ['Bearer tok-7', 'Bearer tok-9', 'Bearer tok-7', 'Bearer tok-9']);
});

test('a public directory read and a foreign host carry no family token', async () => {
    const store = useFamilyStore();
    await signIn(store, 7, 'tok-7');
    sent.length = 0;

    await FamilyApiService.get('/api/mobile/masjids/7');
    await FamilyApiService.get('https://elsewhere.example.test/api/family/masjids/7/me');

    assert.deepEqual(sent.map((r) => r.auth), [null, null]);
});

test('a school the parent has not signed in to is asked without any token', async () => {
    const store = useFamilyStore();
    await signIn(store, 7, 'tok-7');
    sent.length = 0;

    await FamilyApiService.get('/api/family/masjids/9/groups');

    assert.equal(sent[0].auth, null);
});

test('signing out of one school keeps the other signed in, and its requests still work', async () => {
    const store = useFamilyStore();
    await signIn(store, 7, 'tok-7');
    await signIn(store, 9, 'tok-9');

    store.signOut(7);

    assert.equal(store.isSignedInTo(7), false);
    assert.equal(store.isSignedInTo(9), true);
    assert.equal(data.has('MANARA_FAMILY_MASJID_ID'), false, 'no masjid key left behind');

    sent.length = 0;
    await FamilyApiService.get('/api/family/masjids/7/groups');
    await FamilyApiService.get('/api/family/masjids/9/groups');
    assert.deepEqual(sent.map((r) => r.auth), [null, 'Bearer tok-9']);
});

test('a stale slot is dropped on 401 and the other school is untouched', async () => {
    const store = useFamilyStore();
    await signIn(store, 7, 'tok-7');
    await signIn(store, 9, 'tok-9');

    respond = () => ({ status: 401 });
    let caught: any;
    try { await FamilyApiService.get('/api/family/masjids/7/groups'); } catch (e) { caught = e; }

    assert.equal(store.handleAuthFailure(caught, 7), true, 'the school on screen lost its session: go to its sign-in');
    assert.equal(store.isSignedInTo(7), false);
    assert.equal(store.isSignedInTo(9), true);
    assert.equal(data.get('MANARA_FAMILY_SESSIONS')!.includes('tok-9'), true);
    assert.equal(data.get('MANARA_FAMILY_SESSIONS')!.includes('tok-7'), false);
});

test('a late 401 from a school the parent has left ends that school, not the one on screen', async () => {
    const store = useFamilyStore();
    await signIn(store, 7, 'tok-7');
    await signIn(store, 9, 'tok-9');

    respond = () => ({ status: 403 });
    let caught: any;
    try { await FamilyApiService.get('/api/family/masjids/7/groups'); } catch (e) { caught = e; }

    // The parent is looking at school 9 by the time the answer lands.
    assert.equal(store.handleAuthFailure(caught, 9), false, 'no redirect off the page they are on');
    assert.equal(store.isSignedInTo(7), false);
    assert.equal(store.isSignedInTo(9), true);
});

test('a 500 or a 422 does not sign anyone out', async () => {
    const store = useFamilyStore();
    await signIn(store, 7, 'tok-7');

    for (const status of [422, 500]) {
        respond = () => ({ status });
        let caught: any;
        try { await FamilyApiService.get('/api/family/masjids/7/groups'); } catch (e) { caught = e; }
        assert.equal(store.handleAuthFailure(caught, 7), false);
    }
    assert.equal(store.isSignedInTo(7), true);
});

test('a sign-in response with no token, or for another school, is refused and stores nothing', async () => {
    const store = useFamilyStore();

    respond = () => ({ status: 200, data: { data: { contact: contact(7) } } });
    await assert.rejects(store.signInWithPassword('7', 'a@example.test', 'pw'));

    respond = () => ({ status: 200, data: { data: { token: 't', contact: contact(9) } } });
    await assert.rejects(store.signInWithPassword('7', 'a@example.test', 'pw'));

    assert.deepEqual(store.signedInMasjidIds, []);
    assert.equal(data.size, 0);
});

test('a reload keeps both schools: a new store reads them back', async () => {
    await signIn(useFamilyStore(), 7, 'tok-7');
    await signIn(useFamilyStore(), 9, 'tok-9');

    setActivePinia(createPinia());
    const reloaded = useFamilyStore();

    assert.deepEqual(reloaded.signedInMasjidIds, ['7', '9']);
});

test('syncFromStorage picks up a sign-in made in another tab', async () => {
    const store = useFamilyStore();
    await signIn(store, 7, 'tok-7');

    data.set('MANARA_FAMILY_SESSIONS', JSON.stringify({
        '7': { token: 'tok-7', contact: contact(7) },
        '9': { token: 'tok-9', contact: contact(9) },
    }));
    assert.equal(store.isSignedInTo(9), false, 'this tab has not looked yet');

    store.syncFromStorage();
    assert.equal(store.isSignedInTo(9), true);
});
