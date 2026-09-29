/**
 * The parent portal's real HTTP client, with NO configured API base
 * (VITE_APP_URL empty, relative calls): a school's bearer token must still stay
 * on the portal's own origin. With the base empty the old guard never called
 * anything foreign, and even with a base it compared URLs as text prefixes.
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
// The page the portal is served from; with no API base, calls go here.
(globalThis as any).location = { origin: 'https://app.example.org' };

let FamilyApiService: any;
const sent: { url: string; auth: string | null }[] = [];

function fakeNetwork() {
    FamilyApiService.client.defaults.adapter = async (config: any) => {
        sent.push({ url: config.url, auth: config.headers.get?.('Authorization') ?? config.headers.Authorization ?? null });
        return { data: {}, status: 200, statusText: '', headers: {}, config, request: {} };
    };
}

before(async () => {
    ({ default: FamilyApiService } = await import('@/core/services/FamilyApiService' as string));
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
