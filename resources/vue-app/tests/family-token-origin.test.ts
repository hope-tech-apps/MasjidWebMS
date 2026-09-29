/**
 * The parent portal's real HTTP client, with NO configured API base
 * (VITE_APP_URL empty, relative calls): a school's bearer token must still stay
 * on the portal's own origin. With the base empty the old guard never called
 * anything foreign, and even with a base it compared URLs as text prefixes.
 *
 * Also the other direction: the STAFF bearer token the admin ApiService writes
 * onto axios's global defaults must never ride a family request that has no
 * slot of its own.
 * Run: npm run test:spa
 */
import { test, before, beforeEach, afterEach } from 'node:test';
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
// The page the portal is served from; with no API base, calls go here.
(globalThis as any).location = { origin: 'https://app.example.org' };

let FamilyApiService: any;
let axios: any;
const sent: { url: string; auth: string | null }[] = [];

function fakeNetwork() {
    FamilyApiService.client.defaults.adapter = async (config: any) => {
        sent.push({ url: config.url, auth: config.headers.get?.('Authorization') ?? config.headers.Authorization ?? null });
        return { data: {}, status: 200, statusText: '', headers: {}, config, request: {} };
    };
}

before(async () => {
    ({ default: FamilyApiService } = await import('@/core/services/FamilyApiService' as string));
    ({ default: axios } = await import('axios' as string));
});

/** What ApiService.setHeader() does when an admin or teacher signs in. */
const STAFF = 'Bearer staff-token';
const signStaffInGlobally = () => {
    axios.defaults.headers.common.Authorization = STAFF;
};

afterEach(() => {
    // The global is shared by every test in this process; leave it as found.
    delete axios.defaults.headers.common.Authorization;
    for (const method of ['get', 'post', 'put', 'patch', 'delete', 'head']) {
        delete axios.defaults.headers[method]?.Authorization;
    }
    delete axios.defaults.headers.Authorization;
});

beforeEach(() => {
    data.clear();
    sent.length = 0;
    data.set('MANARA_FAMILY_SESSIONS', JSON.stringify({
        '7': { token: 'tok-7', contact: { id: 70, masjid_id: 7 } },
    }));
});

test('no API base: a family URL on another host carries no token; a relative one and the page\'s own origin do', async () => {
    FamilyApiService.init('');
    fakeNetwork();

    await FamilyApiService.get('/api/family/masjids/7/me');
    await FamilyApiService.get('https://app.example.org/api/family/masjids/7/me');
    await FamilyApiService.get('https://evil.example/api/family/masjids/7/me');
    await FamilyApiService.get('https://app.example.org.evil.com/api/family/masjids/7/me');

    assert.deepEqual(sent.map((r) => r.auth), ['Bearer tok-7', 'Bearer tok-7', null, null]);
});

test('an API base: look-alike hosts that share its text prefix carry no token', async () => {
    FamilyApiService.init('https://app.example.org');
    fakeNetwork();

    await FamilyApiService.get('/api/family/masjids/7/me');
    await FamilyApiService.get('https://app.example.org/api/family/masjids/7/me');
    await FamilyApiService.get('https://app.example.org.evil.com/api/family/masjids/7/me');
    await FamilyApiService.get('https://app.example.org@evil.com/api/family/masjids/7/me');
    await FamilyApiService.get('https://app.example.orgevil.com/api/family/masjids/7/me');

    assert.deepEqual(sent.map((r) => r.auth), ['Bearer tok-7', 'Bearer tok-7', null, null, null]);
});

test('a staff token on the axios globals never rides a family request that has no slot', async () => {
    signStaffInGlobally();
    FamilyApiService.init('https://app.example.org');
    fakeNetwork();

    // No slot for school 9, a public read, and the sign-in call itself.
    await FamilyApiService.get('/api/family/masjids/9/me');
    await FamilyApiService.get('/api/family/masjids');
    await FamilyApiService.post('/api/family/masjids/9/sign-in', { email: 'p@example.org' });
    await FamilyApiService.put('/api/family/masjids/9/profile', {});
    await FamilyApiService.delete('/api/family/masjids/9/password');

    assert.equal(sent.length, 5);
    assert.deepEqual(sent.map((r) => r.auth), [null, null, null, null, null], 'no request carried an Authorization header');
    assert.equal(sent.some((r) => String(r.auth).includes('staff-token')), false);
});

test('with a staff token on the globals, a slot still signs its own school with the FAMILY token', async () => {
    signStaffInGlobally();
    FamilyApiService.init('https://app.example.org');
    fakeNetwork();

    await FamilyApiService.get('/api/family/masjids/7/me');
    await FamilyApiService.post('/api/family/masjids/7/messages', {});
    await FamilyApiService.get('/api/family/masjids/9/me');

    assert.deepEqual(sent.map((r) => r.auth), ['Bearer tok-7', 'Bearer tok-7', null]);
});

test('the staff token is per-method too: a bucket the admin wrote into is stripped from the family copy', async () => {
    axios.defaults.headers.common.Authorization = STAFF;
    axios.defaults.headers.post = { ...(axios.defaults.headers.post ?? {}), Authorization: STAFF };
    axios.defaults.headers.put = { ...(axios.defaults.headers.put ?? {}), Authorization: STAFF };
    axios.defaults.headers.delete = { ...(axios.defaults.headers.delete ?? {}), Authorization: STAFF };
    FamilyApiService.init('');
    fakeNetwork();

    await FamilyApiService.get('/api/family/masjids/9/me');
    await FamilyApiService.post('/api/family/masjids/9/sign-in', {});
    await FamilyApiService.put('/api/family/masjids/9/profile', {});
    await FamilyApiService.delete('/api/family/masjids/9/password');

    assert.deepEqual(sent.map((r) => r.auth), [null, null, null, null]);
});

test('a staff token written to the globals AFTER the client exists does not reach it either', async () => {
    FamilyApiService.init('https://app.example.org');
    fakeNetwork();
    signStaffInGlobally();

    await FamilyApiService.get('/api/family/masjids/9/me');

    assert.deepEqual(sent.map((r) => r.auth), [null]);
});

test('stripping the family copy leaves the admin global alone', async () => {
    signStaffInGlobally();
    FamilyApiService.init('https://app.example.org');

    assert.equal(axios.defaults.headers.common.Authorization, STAFF, 'the admin screens still hold their token');
    assert.equal(FamilyApiService.client.defaults.headers.common.Authorization, undefined);
});
