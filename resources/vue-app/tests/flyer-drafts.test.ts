import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { click, compileSfc, deferred, flush, mountSfc } from './support/mountSfc.ts';
import * as paletteFunctions from '../../flyer-templates/palette.js';
import * as flyerTypes from '../core/types/data/masjid-related/Flyer.ts';

const require = createRequire(import.meta.url);
const vue = require('vue');
const pinia = require('pinia');
const esbuild = require('esbuild');
const directory = new URL('../../flyer-templates/', import.meta.url);
const assets = { json: {} as Record<string, any>, html: {} as Record<string, any> };
for (const file of readdirSync(directory)) {
    if (file.endsWith('.json')) assets.json[file] = JSON.parse(readFileSync(new URL(file, directory), 'utf8'));
    if (file.endsWith('.html')) assets.html[file] = readFileSync(new URL(file, directory), 'utf8');
}

// Compile the real store. Only Vite's asset globs and transport are supplied by the test.
function storeWithApi(api: any, auth: any) {
    pinia.setActivePinia(pinia.createPinia());
    const source = readFileSync(new URL('../stores/masjid/flyersStore.ts', import.meta.url), 'utf8')
        .replace(/const TEMPLATE_JSON = import.meta.glob[\s\S]*?\);/, 'const TEMPLATE_JSON = __assets.json;')
        .replace(/const TEMPLATE_HTML = import.meta.glob[\s\S]*?\);/, 'const TEMPLATE_HTML = __assets.html;');
    const modules: Record<string, any> = {
        pinia, vue, axios: {},
        '@/core/services/ApiService': { __esModule: true, default: api },
        '@/stores/authStore': { useAuthStore: () => auth },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 7 } }) },
        '@/core/types/data/masjid-related/Flyer': flyerTypes,
        '../../../flyer-templates/palette.js': paletteFunctions,
    };
    const module = { exports: {} as any };
    const code = esbuild.transformSync(source, { loader: 'ts', format: 'cjs' }).code;
    new Function('require', 'module', 'exports', '__assets', code)((name: string) => {
        assert.ok(name in modules, `Unexpected store import: ${name}`);
        return modules[name];
    }, module, module.exports, assets);
    return module.exports.useFlyersStore();
}

const row = (extra: any = {}) => ({
    id: 11, flyer_template_id: 2, template_key: 'food', template_name: 'Food Drive',
    title: 'Saved dinner', content: { title: 'Saved wording', removed: 'Keep this old value' },
    palette: { ...assets.json['palettes.json'].palettes['cool-neutral'], ink: '#123456' },
    status: 'draft', cutout_status: 'none', cutout_pending: false, images: {},
    updated_at: '2026-10-06T12:00:00Z', creator: { id: 3, name: 'Sample editor' }, ...extra,
});

async function setup(extra: any = {}, confirmed = false) {
    let rows = [row(extra)];
    const calls: any[] = [];
    const questions: any[] = [];
    const api = {
        get: async (url: string) => {
            calls.push(['get', url]);
            if (url.includes('/flyer-templates')) return { data: { status: 'success', data: [{ id: 2, key: 'food', name: 'Food Drive', is_active: true }] } };
            if (url.includes('/theme')) return { data: { status: 'success', data: { primary_color: '#ff0000' } } };
            if (/\/flyers\/11$/.test(url)) return { data: { status: 'success', data: rows[0] } };
            return { data: { status: 'success', data: { data: rows, total: rows.length, per_page: 15, current_page: 1, last_page: 1 } } };
        },
        put: async (url: string, body: any) => { calls.push(['put', url, body]); rows[0] = { ...rows[0], ...body }; return { data: { status: 'success', data: rows[0] } }; },
        post: async () => { throw new Error('Re-save must not create a row'); },
        delete: async (url: string) => { calls.push(['delete', url]); rows = []; return { data: { status: 'success' } }; },
        VueApp: { axios: { get: async (url: string) => { calls.push(['blob', url]); return { data: { url } }; } } },
    };
    (globalThis as any).FileReader = class {
        result: string | null = null;
        onload: any;
        readAsDataURL(blob: any) { this.result = `data:image/png;base64,${blob.url}`; this.onload(); }
    };
    const auth = vue.reactive({ dashboardMasjidId: 7 });
    const store = storeWithApi(api, auth);
    const header = { render(this: any) { return vue.h('main', [this.$slots.headerButtons?.(), this.$slots.default?.()]); } };
    const slotForm = await compileSfc('components/flyer/FlyerSlotForm.vue', {
        '@/core/types/data/masjid-related/Flyer': flyerTypes,
        '@/stores/masjidStore': { useMasjidStore: () => ({ term: () => 'Organisation' }) },
    });
    const screen = await mountSfc('views/dashboard/FlyerStudioView.vue', {}, {
        '@/components/PageDataContainer.vue': { default: header },
        '@/components/partials/Pagination.vue': { default: await compileSfc('components/partials/Pagination.vue', { '@/core/types/elements/Pagination': {} }) },
        '@/components/flyer/FlyerSlotForm.vue': { default: slotForm },
        '@/components/flyer/FlyerPreview.vue': { default: { render: () => null } },
        '@/stores/masjid/flyersStore': { useFlyersStore: () => store },
        '@/core/types/data/masjid-related/Flyer': flyerTypes,
        '@/core/types/elements/Pagination': {},
        sweetalert2: { default: { fire: async (options: any) => { questions.push(options); return { isConfirmed: confirmed }; } } },
    });
    await flush();
    return { store, screen, calls, questions, api, auth };
}

test('Studio lists saved drafts, opens real filled form and keeps removed values and palette on PUT', async () => {
    const { store, screen, calls } = await setup();
    assert.match(screen.text(), /Saved drafts.*Saved dinner.*Food Drive.*Sample editor/);
    assert.ok(calls.some(c => c[1].includes('status=draft')));
    click(screen.button('Open')); await flush();
    assert.equal(screen.all(n => n.props.id === 'flyer-title')[0].value, 'Saved dinner');
    assert.equal(screen.all(n => n.props.id === 'slot-title')[0].value, 'Saved wording');
    assert.match(screen.text(), /Keep this old value/);
    assert.equal(store.palette.ink, '#123456');
    await store.saveDraft();
    const put = calls.find(c => c[0] === 'put');
    assert.equal(put[1], '/api/admin/masjids/7/flyers/11');
    assert.equal(put[2].content.removed, 'Keep this old value');
    assert.deepEqual(put[2].palette, row().palette);
    store.reset(); await flush();
    assert.equal(calls.filter(c => c[0] === 'delete').length, 0);
    screen.unmount();
});

for (const confirmed of [false, true]) {
    test(`Delete draft ${confirmed ? 'confirms and refreshes' : 'cancels without deleting'}`, async () => {
        const { screen, calls, questions } = await setup({}, confirmed);
        click(screen.button('Delete')); await flush();
        assert.equal(questions[0].title, 'Delete this draft?');
        assert.equal(questions[0].showCancelButton, true);
        assert.equal(calls.filter(c => c[0] === 'delete').length, confirmed ? 1 : 0);
        assert.equal(screen.text().includes('Saved dinner'), !confirmed);
        screen.unmount();
    });
}

test('gone template opens saved text and images with a clear explanation and no preview', async () => {
    const { screen, store } = await setup({ template_key: 'gone', template: null, images: { source: '/saved/source', cutout: '/saved/cutout' } });
    click(screen.button('Open')); await flush();
    assert.match(screen.text(), /This draft’s design is no longer available/);
    assert.match(screen.text(), /Saved wording/);
    assert.equal(screen.all(n => n.tag === 'img').length, 2);
    assert.equal(store.flyerId, 11);
    assert.equal(store.canSave, false);
    screen.unmount();
});

test('open fetches source and cutout through authenticated blobs and keeps the saved snapshot', async () => {
    const { store, screen, calls } = await setup({ template_key: 'food', images: { source: '/saved/source', cutout: '/saved/cutout' }, cutout_status: 'done' });
    await store.openDraft(11);
    assert.equal(calls.filter(c => c[0] === 'blob').length, 2);
    assert.equal(store.images.photo.original, 'data:image/png;base64,/saved/source');
    assert.equal(store.images.photo.cutout, 'data:image/png;base64,/saved/cutout');
    assert.equal(store.renderContent.photo, 'data:image/png;base64,/saved/cutout');
    screen.unmount();
});

test('a failed draft list is an error, never the empty state', async () => {
    const { store, screen, api } = await setup();
    api.get = async () => { throw new Error('Offline'); };
    await store.fetchDrafts(); await flush();
    assert.match(screen.text(), /Saved drafts could not be loaded/);
    assert.doesNotMatch(screen.text(), /No saved drafts yet/);
    screen.unmount();
});

test('a late open cannot repopulate a reset editor', async () => {
    const { store, screen, api } = await setup();
    const response = deferred();
    api.get = async () => response.promise;
    const opening = store.openDraft(11);
    store.reset();
    response.resolve({ data: { status: 'success', data: row() } });
    await opening;
    assert.equal(store.flyerId, null);
    screen.unmount();
});

test('a saved draft on an inactive template can still be saved from the screen', async () => {
    const { store, screen, api, calls } = await setup();
    store.templates = [];
    const originalGet = api.get;
    api.get = async (url: string) => {
        const res = await originalGet(url);
        if (/\/flyers\/11$/.test(url)) res.data.data.template = { id: 2, key: 'food', is_active: false };
        return res;
    };
    click(screen.button('Open')); await flush();
    assert.equal(screen.button('Save draft').disabled, false);
    click(screen.button('Save draft')); await flush();
    assert.equal(calls.filter(c => c[0] === 'put').length, 1);
    screen.unmount();
});

test('changing colours explicitly releases the saved snapshot', async () => {
    const { store, screen } = await setup();
    await store.openDraft(11);
    assert.equal(store.palette.ink, '#123456');
    store.paletteKey = 'brand';
    store.changePalette();
    assert.equal(store.savedPalette, null);
    assert.equal(store.paletteKey, '');
    assert.notEqual(store.palette.ink, '#123456');
    screen.unmount();
});

test('pagination uses the existing admin pager and requests the selected page', async () => {
    const { store, screen, calls } = await setup();
    store.draftsPage = { total: 31, per_page: 15, current_page: 1, last_page: 3 };
    await flush();
    click(screen.all(n => n.tag === 'button' && n.textContent === '2')[0]); await flush();
    assert.ok(calls.some(c => c[1].includes('status=draft&page=2&per_page=15')));
    screen.unmount();
});

test('a missing stored photo says what failed and still permits content to be opened', async () => {
    const { store, screen, api } = await setup({ images: { source: '/saved/source' } });
    api.VueApp.axios.get = async () => { throw new Error('Missing file'); };
    click(screen.button('Open')); await flush();
    assert.match(screen.text(), /The saved photo could not be loaded. Its stored file has been kept./);
    assert.equal(store.content.title, 'Saved wording');
    assert.equal(store.flyerId, 11);
    screen.unmount();
});


test('changing organisation clears old drafts even when the next read fails', async () => {
    const { store, screen, api, auth } = await setup();
    auth.dashboardMasjidId = 8;
    api.get = async () => { throw new Error('Offline'); };
    await store.fetchDrafts(); await flush();
    assert.equal(store.drafts.length, 0);
    assert.doesNotMatch(screen.text(), /Saved dinner/);
    screen.unmount();
});
