/**
 * The child-mode client (StudentApiService), on a device where the admin ApiService has
 * written its STAFF bearer token, its credentials flag and its form-urlencoded
 * Content-Type onto axios's globals. axios.create() copies those defaults, so the
 * child's requests must be stripped of the staff token and carry only the child's own.
 * The same shape as the family client's tests (family-token-origin.test.ts).
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

let StudentApiService: any;
let STUDENT_STORAGE_KEYS: any;
let axios: any;
const sent: { url: string; auth: string | null; contentType: string | null; credentials: boolean | undefined }[] = [];

/** What ApiService.setHeader() and its content-type default do when an admin signs in. */
const STAFF = 'Bearer staff-token';
const signStaffInGlobally = () => {
    axios.defaults.headers.common.Authorization = STAFF;
    axios.defaults.headers.common['Content-Type'] = 'application/x-www-form-urlencoded';
    axios.defaults.withCredentials = true;
};

function fakeNetwork() {
    StudentApiService.client.defaults.adapter = async (config: any) => {
        sent.push({
            url: config.url,
            auth: config.headers.get?.('Authorization') ?? config.headers.Authorization ?? null,
            contentType: config.headers.get?.('Content-Type') ?? null,
            credentials: config.withCredentials,
        });
        return { data: {}, status: 200, statusText: '', headers: {}, config, request: {} };
    };
}

before(async () => {
    ({ default: StudentApiService, STUDENT_STORAGE_KEYS } = await import('@/core/services/StudentApiService' as string));
    ({ default: axios } = await import('axios' as string));
});

beforeEach(() => {
    data.clear();
    sent.length = 0;
});

afterEach(() => {
    // The globals are shared by every test in this process; leave them as found.
    delete axios.defaults.headers.common.Authorization;
    delete axios.defaults.headers.common['Content-Type'];
    for (const method of ['get', 'post', 'put', 'patch', 'delete', 'head']) {
        delete axios.defaults.headers[method]?.Authorization;
    }
    delete axios.defaults.headers.Authorization;
    axios.defaults.withCredentials = false;
});

test('a staff token on the axios globals never rides a child request that has no child token', async () => {
    signStaffInGlobally();
    StudentApiService.init('https://app.example.org');
    fakeNetwork();

    await StudentApiService.get('/api/family/masjids/7/groups/3/members/101/student/me');
    await StudentApiService.put('/api/family/masjids/7/groups/3/members/101/student/avatar', { character: 'ameer' });

    assert.equal(sent.length, 2);
    assert.deepEqual(sent.map((r) => r.auth), [null, null], 'no request carried an Authorization header');
});

test('with a staff token on the globals, a child request still carries the CHILD token', async () => {
    signStaffInGlobally();
    StudentApiService.init('https://app.example.org');
    fakeNetwork();
    data.set(STUDENT_STORAGE_KEYS.token, 'child-tok');

    await StudentApiService.get('/api/family/masjids/7/groups/3/members/101/student/me');
    await StudentApiService.put('/api/family/masjids/7/groups/3/members/101/student/avatar', { character: 'ameer' });

    assert.deepEqual(sent.map((r) => r.auth), ['Bearer child-tok', 'Bearer child-tok']);
    assert.equal(sent.some((r) => String(r.auth).includes('staff-token')), false);
});

test('the staff token is per-method too: a bucket the admin wrote into is stripped from the child copy', async () => {
    axios.defaults.headers.common.Authorization = STAFF;
    axios.defaults.headers.put = { ...(axios.defaults.headers.put ?? {}), Authorization: STAFF };
    axios.defaults.headers.post = { ...(axios.defaults.headers.post ?? {}), Authorization: STAFF };
    StudentApiService.init('');
    fakeNetwork();

    await StudentApiService.get('/api/family/masjids/7/groups/3/members/101/student/me');
    await StudentApiService.put('/api/family/masjids/7/groups/3/members/101/student/avatar', {});

    assert.deepEqual(sent.map((r) => r.auth), [null, null]);
});

test('a staff token written to the globals AFTER the client exists does not reach it either', async () => {
    StudentApiService.init('https://app.example.org');
    fakeNetwork();
    signStaffInGlobally();

    await StudentApiService.get('/api/family/masjids/7/groups/3/members/101/student/me');

    assert.deepEqual(sent.map((r) => r.auth), [null]);
});

test('stripping the child copy leaves the admin global alone', () => {
    signStaffInGlobally();
    StudentApiService.init('https://app.example.org');

    assert.equal(axios.defaults.headers.common.Authorization, STAFF, 'the admin screens still hold their token');
    assert.equal(StudentApiService.client.defaults.headers.common.Authorization, undefined);
});

test('a bearer-only realm sends no credentials, whatever the admin set globally', async () => {
    signStaffInGlobally();
    StudentApiService.init('https://app.example.org');
    fakeNetwork();

    await StudentApiService.get('/api/family/masjids/7/groups/3/members/101/student/me');

    assert.deepEqual(sent.map((r) => r.credentials), [false]);
});

test('a JSON body is declared as JSON even when the admin globals say form-urlencoded', async () => {
    signStaffInGlobally();
    StudentApiService.init('https://app.example.org');
    fakeNetwork();

    await StudentApiService.put('/api/family/masjids/7/groups/3/members/101/student/avatar', { character: 'ameer' });

    assert.equal(sent[0].contentType, 'application/json');
});
