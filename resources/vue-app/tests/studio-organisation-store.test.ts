/**
 * The live-organisation store's logic (stores/super/studioOrganisationStore.ts),
 * run as the store itself (tests/helpers/organisationStoreHarness.ts) against a
 * transport the tests answer by hand (Studio W2 S9 review follow-ups):
 *
 *  - no writer sends anything until its confirm is accepted;
 *  - a slow answer to an older preview or read never replaces a newer one;
 *  - the four colours go back exactly as stored;
 *  - a half-typed colour is not passed off as a preview of the unsaved changes;
 *  - a save whose re-read fails keeps the saved values on screen and says so.
 *
 * Run: npm run test:spa
 */
import { afterEach, beforeEach, test } from 'node:test';
import assert from 'node:assert/strict';
import { loadOrganisationStore, type Call } from './helpers/organisationStoreHarness.ts';

const { useStore, api, SAVED_BUT_STALE } = await loadOrganisationStore();

const wait = (ms = 20) => new Promise((resolve) => setTimeout(resolve, ms));

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

type Colours = Record<'primary_color' | 'secondary_color' | 'accent_color' | 'background_color', string | null>;

const STORED: Colours = { primary_color: '#fa0', secondary_color: '#0A3D62FF', accent_color: '#0a3d62', background_color: '#FFFFFF' };

function snapshotOf(id: number, options: { colours?: Colours | null; events?: boolean } = {}) {
    return {
        org: { id, name: `Org ${id}`, org_type: 'masjid', slug: null },
        sections: {
            features: {
                edit_in: 'studio',
                data: [{
                    key: 'community', label: 'Community', entries: [
                        { key: 'events', label: 'Events', writer: 'capability', enabled: options.events ?? true, decided: false },
                        { key: 'crm', label: 'Members', writer: 'crm', enabled: true, decided: true },
                    ],
                }],
            },
            brand: {
                edit_in: 'studio',
                data: {
                    colours: options.colours === undefined ? { ...STORED } : options.colours,
                    theme_layout: null, has_theme: true,
                    logo_url: null, favicon_url: null, touch_icon_url: null, share_image_url: null, has_derivatives: false,
                },
            },
        },
    };
}

const ORG = '/api/admin/studio/organisations/5';
const yes = async () => true;
const no = async () => false;

/** Answers the snapshot read (and every preview) so a test can start from an open organisation. */
async function open(snapshot = snapshotOf(5)) {
    api.on('get', ORG, async () => ({ data: { data: snapshot } }));
    api.on('post', /\/preview$/, async () => ({ data: { data: { tag: 'ok' } } }));
    const store = useStore();
    assert.equal(await store.fetchSnapshot(5), true);
    await wait();
    return store;
}

const writes = () => api.calls.filter((call: Call) => call.method !== 'get' && !call.url.endsWith('/preview'));

beforeEach(() => api.reset());
// A store schedules its preview on a timer; let it fire while this test's answers are still in place.
afterEach(() => wait(15));

test('no writer sends anything while its confirm is declined, fails, or is answered for another organisation', async () => {
    const store = await open();
    api.on('patch', '/api/admin/masjids/5/capabilities', async () => ({ data: { meta: { changed: ['events'], unchanged: [] } } }));
    api.on('post', '/api/admin/masjids/5/theme', async () => ({ data: {} }));
    api.on('post', '/api/admin/masjids/5/brand-assets/regenerate', async () => ({ data: { data: {} } }));

    const attempts = {
        features: (confirm: () => Promise<boolean>) => store.saveFeatures({ events: false }, confirm),
        colours: (confirm: () => Promise<boolean>) => store.saveColours({ ...STORED, primary_color: '#112233' }, confirm),
        images: (confirm: () => Promise<boolean>) => store.regenerateBrandAssets(confirm),
    };

    for (const [name, attempt] of Object.entries(attempts)) {
        const declined = await attempt(no);
        assert.deepEqual([declined.ok, declined.cancelled], [false, true], `${name}: declined`);

        const broken = await attempt(async () => { throw new Error('dialog failed'); });
        assert.deepEqual([broken.ok, broken.cancelled], [false, true], `${name}: a confirm that throws is a no`);

        // Not a boolean true: a truthy answer that is not `true` is not an acceptance.
        const vague = await attempt((async () => 'yes') as unknown as () => Promise<boolean>);
        assert.equal(vague.ok, false, `${name}: only true accepts`);
    }
    assert.deepEqual(writes(), [], 'nothing was sent by any writer');

    // Answered after another organisation was opened: still nothing for the first one.
    api.on('get', '/api/admin/studio/organisations/6', async () => ({ data: { data: snapshotOf(6) } }));
    const late = deferred<boolean>();
    const pending = store.saveFeatures({ events: false }, () => late.promise);
    await store.fetchSnapshot(6);
    late.resolve(true);
    assert.equal((await pending).cancelled, true);
    assert.deepEqual(writes(), []);
});

test('each writer asks before it sends, and sends once it is told yes', async () => {
    const store = await open();
    const order: string[] = [];
    api.on('patch', '/api/admin/masjids/5/capabilities', async (call) => { order.push('patch'); return { data: { meta: { changed: ['events'], unchanged: [] }, sent: call.body?.toString() } }; });
    api.on('post', '/api/admin/masjids/5/theme', async () => { order.push('theme'); return { data: {} }; });
    api.on('post', '/api/admin/masjids/5/brand-assets/regenerate', async () => { order.push('regenerate'); return { data: { data: { logo_url: null, favicon_url: null, touch_icon_url: null, share_image_url: null } } }; });
    const ask = async () => { order.push('ask'); return true; };

    assert.equal((await store.saveFeatures({ events: false }, ask)).ok, true);
    assert.equal((await store.saveColours({ ...STORED, primary_color: '#112233' }, ask)).ok, true);
    assert.equal((await store.regenerateBrandAssets(ask)).ok, true);

    assert.deepEqual(order, ['ask', 'patch', 'ask', 'theme', 'ask', 'regenerate']);
    assert.equal(writes()[0].body?.get('capabilities[events]'), '0', 'booleans go as 1/0');
});

test('a slow answer to an older preview never replaces a newer one, nor one for another organisation', async () => {
    api.on('get', ORG, async () => ({ data: { data: snapshotOf(5) } }));
    api.on('get', '/api/admin/studio/organisations/6', async () => ({ data: { data: snapshotOf(6) } }));
    const inFlight: ReturnType<typeof deferred<{ data: any }>>[] = [];
    api.on('post', /\/preview$/, () => { const d = deferred<{ data: any }>(); inFlight.push(d); return d.promise; });

    const store = useStore();
    await store.fetchSnapshot(5);
    await wait();
    store.setCapability('events', false);
    await wait();
    assert.equal(inFlight.length, 2, 'two previews are out');

    inFlight[1].resolve({ data: { data: { tag: 'newer' } } });
    await wait();
    assert.equal(store.preview.tag, 'newer');

    inFlight[0].resolve({ data: { data: { tag: 'older' } } });
    await wait();
    assert.equal(store.preview.tag, 'newer', 'the older answer is dropped');
    assert.equal(store.previewLoading, false);

    // Another organisation opens while a preview for this one is still out.
    store.setCapability('events', true);
    await wait();
    const stale = inFlight[inFlight.length - 1];
    await store.fetchSnapshot(6);
    stale.resolve({ data: { data: { tag: 'for-org-5' } } });
    await wait();
    assert.notEqual(store.preview?.tag, 'for-org-5', 'a preview for the previous organisation never lands');
});

test('a slow read never overwrites a newer one', async () => {
    // The organisation is already open, so the two reads are re-reads of it and neither resets the store.
    const store = await open();
    api.reset();
    const reads: ReturnType<typeof deferred<{ data: any }>>[] = [];
    api.on('get', ORG, () => { const d = deferred<{ data: any }>(); reads.push(d); return d.promise; });
    api.on('post', /\/preview$/, async () => ({ data: { data: {} } }));

    const first = store.fetchSnapshot(5);
    const second = store.fetchSnapshot(5);

    reads[1].resolve({ data: { data: snapshotOf(5, { events: false }) } });
    assert.equal(await second, true);
    assert.equal(store.featureEntries[0].enabled, false);

    reads[0].resolve({ data: { data: snapshotOf(5, { events: true }) } });
    assert.equal(await first, false, 'the older read reports that it landed nothing');
    assert.equal(store.featureEntries[0].enabled, false, 'the newer read stands');
    assert.equal(store.loading, false);
});

test('colours are shown, previewed and saved exactly as stored, in whatever form the theme holds them', async () => {
    const store = await open();
    assert.deepEqual({ ...store.colours }, STORED);

    // Case alone is not a change.
    store.setColour('primary_color', '#FA0');
    assert.deepEqual(store.changedColours, []);

    store.setColour('accent_color', '#112233');
    await wait();
    assert.deepEqual(store.changedColours, ['accent_color']);

    const preview = api.calls.filter((call: Call) => call.url.endsWith('/preview')).pop() as Call;
    assert.equal(preview.body?.get('brand[primary_color]'), '#fa0', 'the untouched colour is sent as stored');
    assert.equal(preview.body?.get('brand[secondary_color]'), '#0A3D62FF');
    assert.equal(preview.body?.get('brand[accent_color]'), '#112233');

    api.on('post', '/api/admin/masjids/5/theme', async () => ({ data: {} }));
    const saved = await store.saveColours({ ...store.colours }, yes);
    assert.equal(saved.ok, true);
    const sent = writes()[0].body as URLSearchParams;
    assert.equal(sent.get('primary_color'), '#fa0');
    assert.equal(sent.get('secondary_color'), '#0A3D62FF');
    assert.equal(sent.get('accent_color'), '#112233');
    assert.equal(sent.get('background_color'), '#FFFFFF');
    assert.deepEqual([...sent.keys()], ['primary_color', 'secondary_color', 'accent_color', 'background_color'], 'the four colours and nothing else, so stored tokens survive');
});

test('a half-typed colour is marked as not previewed, and the server is not sent the colours', async () => {
    const store = await open();
    assert.equal(store.previewColoursIncomplete, false);

    store.setColour('primary_color', '#0A3D6');
    store.setCapability('events', false);
    await wait();

    assert.equal(store.previewColoursIncomplete, true);
    const partial = api.calls.filter((call: Call) => call.url.endsWith('/preview')).pop() as Call;
    assert.equal([...(partial.body as URLSearchParams).keys()].some((key) => key.startsWith('brand[')), false, 'no colours while one is incomplete');
    assert.equal(partial.body?.get('capabilities[events]'), '0', 'the switch changes still go');

    store.setColour('primary_color', '#0A3D62');
    await wait();
    assert.equal(store.previewColoursIncomplete, false);
    const complete = api.calls.filter((call: Call) => call.url.endsWith('/preview')).pop() as Call;
    assert.equal(complete.body?.get('brand[primary_color]'), '#0A3D62');

    store.setColour('primary_color', '');
    assert.equal(store.previewColoursIncomplete, true, 'an emptied field is incomplete too');
    store.discardColours();
    assert.equal(store.previewColoursIncomplete, false);
});

test('a save whose re-read fails keeps the saved colours on screen and says so', async () => {
    const store = await open();
    store.setColour('accent_color', '#112233');
    api.on('post', '/api/admin/masjids/5/theme', async () => ({ data: {} }));
    api.reset();
    api.on('post', '/api/admin/masjids/5/theme', async () => ({ data: {} }));
    api.on('post', /\/preview$/, async () => ({ data: { data: {} } }));
    api.on('get', ORG, async () => { throw Object.assign(new Error('Network Error'), { response: { status: 500, data: { message: 'Server error' } } }); });

    const result = await store.saveColours({ ...store.colours }, yes);

    assert.equal(result.ok, true, 'the colours were saved');
    assert.equal(SAVED_BUT_STALE, "Saved, but couldn't refresh. Reload to see the latest.");
    assert.equal(store.refreshNotice, SAVED_BUT_STALE);
    assert.equal(store.error, null, 'not the load error, which reads as if the save failed');
    assert.equal(store.colours.accent_color, '#112233', 'the saved value stays');
    assert.equal(store.savedColours.accent_color, '#112233');
    assert.deepEqual(store.changedColours, [], 'nothing is left looking unsaved');
    assert.equal(store.hasUnsavedChanges(), false);

    // A later good read clears the notice.
    api.reset();
    api.on('get', ORG, async () => ({ data: { data: snapshotOf(5, { colours: { ...STORED, accent_color: '#112233' } }) } }));
    api.on('post', /\/preview$/, async () => ({ data: { data: {} } }));
    assert.equal(await store.fetchSnapshot(5), true);
    assert.equal(store.refreshNotice, null);
});

test('a switch save whose re-read fails keeps the saved switch on screen and says so', async () => {
    const store = await open();
    store.setCapability('events', false);
    api.reset();
    api.on('patch', '/api/admin/masjids/5/capabilities', async () => ({ data: { meta: { changed: ['events'], unchanged: [] } } }));
    api.on('post', /\/preview$/, async () => ({ data: { data: {} } }));
    api.on('get', ORG, async () => { throw new Error('offline'); });

    const result = await store.saveFeatures({ events: false }, yes);

    assert.equal(result.ok, true);
    assert.equal(store.refreshNotice, SAVED_BUT_STALE);
    assert.equal(store.featureEntries[0].enabled, false, 'the switch shows what was saved, not the old value');
    assert.deepEqual(store.capabilityChanges, {});
    assert.equal(store.hasUnsavedChanges(), false);
});

test('an ordinary failed read still says the organisation could not be loaded', async () => {
    api.on('get', ORG, async () => { throw Object.assign(new Error('x'), { response: { status: 500, data: { message: 'Down for a moment' } } }); });
    const store = useStore();

    assert.equal(await store.fetchSnapshot(5), false);
    assert.equal(store.error, 'Down for a moment');
    assert.equal(store.refreshNotice, null);
});
