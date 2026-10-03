/**
 * The TV Display store, RUN, not read: stores/masjid/tvDisplayStore.ts with its three imports
 * (the API service, the organisation store, Pinia) swapped for doubles.
 *
 * tv-display-screen.test.ts mounts the page over a store double, so nothing there executes the
 * real store. Its job is small and one line of it matters a great deal: a save is refused unless
 * the settings on screen were loaded for the organisation the save would be sent to. If the line
 * that records that organisation were dropped, every administrator's Save would be refused for
 * ever, with every source-text pin still matching.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';
import { deferred } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');
const root = new URL('../', import.meta.url);

/** The real store's source with its imports replaced by `globalThis.__tvStore`, written where Node can import it. */
async function realStore(doubles: Record<string, any>) {
    const source = readFileSync(new URL('stores/masjid/tvDisplayStore.ts', root), 'utf8');
    assert.equal((source.match(/^import /gm) ?? []).length, 6, 'the store imports six things; this loader replaces exactly those');

    const body = source.replace(/^import .*$/gm, '');
    const dir = mkdtempSync(join(tmpdir(), 'tv-store-'));
    const file = join(dir, 'tvDisplayStore.ts');
    writeFileSync(file, `const { ApiService, useMasjidStore, defineStore, ref } = (globalThis as any).__tvStore;\n${body}`);
    (globalThis as any).__tvStore = doubles;

    try {
        return (await import(pathToFileURL(file).href)).useTvDisplayStore();
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
}

const answer = (title: string | null = null) => ({
    status: 'success',
    data: {
        settings: { is_enabled: null, header_title: title, carousel_interval_seconds: null, show_prayer_panel: null, show_qr: null, donate_caption: null },
        effective: { is_enabled: true, header_title: title, carousel_interval_seconds: 10, show_prayer_panel: true, show_qr: true, donate_caption: 'Scan to Donate' },
        context: { organisation_name: 'Al-Noor Centre', is_masjid: true, has_donation_link: true },
    },
});

function setUp(script: { get?: () => Promise<any>; post?: () => Promise<any> } = {}) {
    const calls: Array<{ verb: string; url: string; body?: any }> = [];
    const masjidStore: any = { masjid: { id: 7 } };
    const ApiService = {
        get: (url: string) => { calls.push({ verb: 'GET', url }); return script.get ? script.get() : Promise.resolve({ data: answer() }); },
        post: (url: string, body: any) => { calls.push({ verb: 'POST', url, body }); return script.post ? script.post() : Promise.resolve({ data: answer(body.header_title ?? null) }); },
        put: () => { throw new Error('the store must not PUT'); },
        patch: () => { throw new Error('the store must not PATCH'); },
    };
    const doubles = { ApiService, useMasjidStore: () => masjidStore, defineStore: (_id: string, setup: () => any) => () => setup(), ref: vue.ref };

    return { calls, masjidStore, doubles };
}

test('a load then a save: one GET and one POST to this organisation, and the answer is kept', async () => {
    const { calls, doubles } = setUp();
    const store = await realStore(doubles);

    await store.load();
    assert.equal(store.loadedFor.value, 7);
    assert.equal(store.payload.value.context.organisation_name, 'Al-Noor Centre');

    await store.save({ header_title: 'Friday' });

    assert.deepEqual(calls, [
        { verb: 'GET', url: '/api/admin/masjids/7/tv-display' },
        { verb: 'POST', url: '/api/admin/masjids/7/tv-display', body: { header_title: 'Friday' } },
    ]);
    assert.equal(store.payload.value.settings.header_title, 'Friday', 'redrawn from the server\'s answer');
});

test('a save before any load is refused, and nothing is sent', async () => {
    const { calls, doubles } = setUp();
    const store = await realStore(doubles);

    await assert.rejects(() => store.save({ header_title: 'Friday' }), /The organisation changed\. Reload this page, then save\./);
    assert.deepEqual(calls, []);
});

test('after a switch of organisation a save is refused BEFORE anything is sent: one organisation\'s form never lands on another', async () => {
    const { calls, masjidStore, doubles } = setUp();
    const store = await realStore(doubles);

    await store.load();
    masjidStore.masjid = { id: 8 };

    await assert.rejects(() => store.save({ is_enabled: false }), /The organisation changed/);
    assert.deepEqual(calls.filter((call) => call.verb === 'POST'), [], 'organisation 7\'s form was not posted to organisation 8');
});

test('an answer that arrives after a switch of organisation is dropped, not shown', async () => {
    const pending = deferred<any>();
    const { masjidStore, doubles } = setUp({ get: () => pending.promise });
    const store = await realStore(doubles);

    const loading = store.load();
    masjidStore.masjid = { id: 8 };
    pending.resolve({ data: answer('Organisation 7 only') });
    await loading;

    assert.equal(store.payload.value, null, 'organisation 7\'s settings are not shown under organisation 8');
    assert.equal(store.loadedFor.value, null, 'and cannot be saved from');
});

test('an answer without its three parts is a failure, never a blank form', async () => {
    const { doubles } = setUp({ get: () => Promise.resolve({ data: { status: 'success', data: { settings: {} } } }) });
    const store = await realStore(doubles);

    await assert.rejects(() => store.load(), /Could not load the TV display settings\./);
    assert.equal(store.payload.value, null);
    assert.equal(store.loadedFor.value, null);
});

test('with no organisation loaded the store asks for nothing', async () => {
    const { calls, masjidStore, doubles } = setUp();
    masjidStore.masjid = undefined;
    const store = await realStore(doubles);

    await assert.rejects(() => store.load(), /No organisation is loaded yet\./);
    assert.deepEqual(calls, []);
});
