import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { mountSfc, loadTs, click, flush } from './support/mountSfc.ts';
const require = createRequire(import.meta.url);
const vue = require('vue');
const features = [
    { id: 1, key: 'qur’an', name: 'Qur’an' }, { id: 2, key: 'hadith', name: 'Hadith' },
    { id: 3, key: 'adhkar', name: 'Adhkar' }, { id: 4, key: 'qibla', name: 'Qibla' }, { id: 5, key: 'tasbih', name: 'Tasbih' },
];
async function screen(chosen: any[] = [], on = true, refusal = false) {
    const writes: any[] = []; const requests: string[] = []; const guards: any[] = []; const listeners = new Map();
    const originalWindow = (globalThis as any).window;
    (globalThis as any).window = { addEventListener: (name: string, handler: any) => listeners.set(name,handler), removeEventListener: (name: string) => listeners.delete(name) };
    let confirm = false;
    const s = await mountSfc('views/dashboard/AppTabsView.vue', {}, {
        '@/components/contacts/AppVolunteerField.vue': { default: {} },
        '@/core/tenancy/unsavedChanges': await loadTs('core/tenancy/unsavedChanges.ts', {}),
        '@/core/types/config/BackendApiRoutes': {},
        vue, 'vue-router': { onBeforeRouteLeave: (fn: any) => guards.push(fn) },
        sweetalert2: { default: { fire: async () => ({ isConfirmed: confirm }) } },
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1, capabilities: on ? { app_bottom_tabs: true } : {} } }) },
        '@/core/services/ApiService': { default: {
            get: async (url: string) => { requests.push(url); return { data: { data: { features, chosen } } }; },
            put: async (url: string, body: any, contentType: any, headers: any) => {
                writes.push({ url, body, contentType, headers });
                if (refusal) throw { response: { data: { data: { feature_ids: ['That feature is no longer available.'] } } } };
                return { data: { data: { features, chosen: body.feature_ids.map((id: number) => ({ ...features.find(f => f.id === id), feature_id: id, available: true })) } } };
            },
        } },
    });
    await flush();
    return { ...s, writes, requests, guards, listeners, confirm: () => { confirm = true; }, close() { s.unmount(); (globalThis as any).window = originalWindow; } };
}

test('App tabs adds four, refuses fifth in words, preview locks Home first, reorders and removes by buttons', async () => {
    const s = await screen();
    try {
        assert.match(s.text(), /Your app is using its standard tabs/);
        for (let i = 0; i < 4; i++) { click(s.all(n => n.tag === 'button' && n.textContent === 'Add to tabs')[0]); await flush(); }
        assert.match(s.text(), /four more|four tabs/i);
        assert.equal(s.all(n => n.props['data-tab-preview'] !== undefined).length, 5);
        const preview = () => s.all(n => n.props['data-tab-preview'] !== undefined).map(n => n.textContent);
        assert.match(preview()[0], /Home.*Locked/);
        click(s.all(n => n.tag === 'button' && n.textContent === 'Add to tabs')[0]); await flush();
        assert.equal(preview().length, 5);
        assert.match(s.text(), /Remove a tab before adding another/);
        click(s.all(n => n.props['aria-label'] === 'Move Qur’an down')[0]); await flush();
        assert.match(preview()[1], /Hadith/);
        click(s.all(n => n.props['aria-label'] === 'Move Qur’an up')[0]); await flush();
        assert.match(preview()[1], /Qur’an/);
        click(s.all(n => n.props['aria-label'] === 'Remove Hadith')[0]); await flush();
        assert.equal(preview().length, 4);
        assert.match(s.text(), /Unsaved changes/);
        click(s.button('Save')); await flush();
        assert.deepEqual(s.writes[0].body, { feature_ids: [1,3,4] });
        assert.equal(s.writes[0].headers['Content-Type'], 'application/json');
        assert.doesNotMatch(s.text(), /Unsaved changes/);
        assert.match(s.text(), /Saved/);
    } finally { s.close(); }
});

test('App tabs retains unavailable choice until save and empty state describes defaults', async () => {
    const s = await screen([{ id: 10, feature_id: null, key: 'retired', name: 'Retired', available: false }]);
    try {
        assert.match(s.text(), /Retired.*no longer available/);
        assert.match(s.text(), /Your app is using its standard tabs/);
        click(s.button('Save')); await flush();
        assert.deepEqual(s.writes[0].body, { feature_ids: [] });
        assert.doesNotMatch(s.text(), /no longer available/);
        assert.equal(s.all(n => n.props['data-tab-preview'] !== undefined).length, 1);
    } finally { s.close(); }
});

test('App tabs asks before leaving a dirty page, covers browser close, and retains drafts on refused save', async () => {
    const s = await screen([], true, true);
    try {
        assert.equal(await s.guards[0](), true);
        click(s.all(n => n.tag === 'button' && n.textContent === 'Add to tabs')[0]); await flush();
        assert.equal(await s.guards[0](), false);
        const event = { prevented: false, returnValue: undefined, preventDefault() { this.prevented = true; } };
        s.listeners.get('beforeunload')(event);
        assert.equal(event.prevented, true);
        click(s.button('Save')); await flush();
        assert.match(s.text(), /That feature is no longer available/);
        assert.match(s.text(), /Unsaved changes/);
        s.confirm(); assert.equal(await s.guards[0](), true);
    } finally { s.close(); }
});

test('App tabs OFF does no request and has no controls', async () => {
    const s = await screen([], false);
    try { assert.deepEqual(s.requests, []); assert.equal(s.all(n => n.tag === 'button').length, 0); }
    finally { s.close(); }
});

test('App tabs menu and route match settings roles, dark for every role, and OFF menu remains literal', async () => {
    const menu = await loadTs('core/constants/dashboardAsideMenuItems.ts', { '@/core/types/config/AsideMenuItem': {} });
    const access = await loadTs('core/access/orgAccess.ts', {
        '@/core/constants/dashboardAsideMenuItems': menu,
        '@/core/types/config/AsideMenuItem': {}, '@/core/types/data/Capability': {}, '@/core/types/data/Masjid': {}, '@/core/types/data/User': {}, '@/core/types/data/Vertical': {},
    });
    const pinned = JSON.parse(readFileSync('tests/fixtures/app-bottom-tabs-menu-b531a07d.json', 'utf8'));
    for (const type of ['SuperAdmin','MasjidAdmin','Teacher','LunchStaff']) {
        for (const orgType of ['masjid','school','community']) {
            const rows = menu.MASJID_DASHBOARD_ASIDE_MENU.filter((i: any) => access.menuItemState(i,type,{capabilities:{}},orgType) !== 'hidden');
            assert.deepEqual(rows, pinned[`${type}:${orgType}`]);
        }
    }
    const item = menu.MASJID_DASHBOARD_ASIDE_MENU.find((i: any) => i.to === '/masjid/app-tabs');
    assert.equal(item.title, 'App tabs');
    for (const type of ['Teacher','LunchStaff']) assert.equal(access.menuItemState(item,type,{capabilities:{app_bottom_tabs:true}},'masjid'), 'hidden');
    for (const type of ['SuperAdmin','MasjidAdmin']) assert.equal(access.menuItemState(item,type,{capabilities:{app_bottom_tabs:true}},'community'), 'visible');
    const routes = readFileSync('resources/vue-app/router/routes/dashboardLayoutRoutes.ts', 'utf8');
    assert.match(routes, /path: 'app-tabs'[\s\S]*?allowedUsers: \['SuperAdmin', 'MasjidAdmin'\][\s\S]*?requiresAnyCapability: \['app_bottom_tabs','app_volunteer_entry'\]/);
});

test('App tabs responsive rules contain the phone preview and use logical spacing', () => {
    const s = readFileSync('resources/vue-app/views/dashboard/AppTabsView.vue', 'utf8');
    assert.match(s, /grid-template-columns: repeat\(5, minmax\(0, 1fr\)\)/);
    assert.match(s, /max-inline-size: 100%/);
    assert.match(s, /overflow-wrap: anywhere/);
    assert.doesNotMatch(s, /margin-left|margin-right|padding-left|padding-right|#[0-9a-f]{3,8}\b/i);
});


test('App tabs JSON encoding survives the real ApiService global form default', async () => {
    const axios = require('axios');
    const service = await loadTs('core/services/ApiService.ts', {
        axios: { default: axios }, vue: {}, 'vue-axios': { default: {} }, '../types/config/BackendApiRoutes': {},
        '@/core/tenancy/tenantRequests': { dropSupersededResponse() {}, isFromSupersededEpoch() {}, recordServerTenant() {}, stampTenantEpoch() {} },
    });
    let wire: any;
    const client = axios.create({ adapter: async (config: any) => { wire = config; return { data: {}, status: 200, statusText: 'OK', headers: {}, config }; } });
    service.default.VueApp = { axios: client };
    await service.default.put('/api/admin/masjids/1/app-bottom-tabs', { feature_ids: [] }, 'url', { 'Content-Type': 'application/json' });
    assert.equal(wire.headers.get('Content-Type'), 'application/json');
    assert.equal(wire.data, '{"feature_ids":[]}');
});

test('real organisation switch asks registered mounted draft before any reset and cancel preserves the tenant', async () => {
    const registration = await loadTs('core/tenancy/unsavedChanges.ts', {});
    const auth = vue.reactive({ dashboardMasjidId: 1, user: { memberships: [{ masjid_id: 1, org_name: 'One', role: 'admin' }, { masjid_id: 2, org_name: 'Two', role: 'admin' }] }, saveDashboardMasjidId(id: number) { this.dashboardMasjidId = id; } });
    const org = vue.reactive({ masjid: { id: 1, capabilities: { app_bottom_tabs: true } }, async fetchMasjid(id: number) { this.masjid = { id, capabilities: { app_bottom_tabs: true } }; } });
    let confirm = false; let prompts = 0; let resets = 0; let epoch = 0;
    const originalWindow = (globalThis as any).window;
    (globalThis as any).window = { addEventListener() {}, removeEventListener() {} };
    const screen = await mountSfc('views/dashboard/AppTabsView.vue', {}, {
        vue, 'vue-router': { onBeforeRouteLeave() {} }, sweetalert2: { default: { fire: async () => { prompts++; return { isConfirmed: confirm }; } } },
        '@/components/contacts/AppVolunteerField.vue': { default: {} },
        '@/core/tenancy/unsavedChanges': registration,
        '@/stores/authStore': { useAuthStore: () => auth }, '@/stores/masjidStore': { useMasjidStore: () => org },
        '@/core/services/ApiService': { default: { get: async () => ({ data: { data: { features, chosen: [] } } }) } },
    });
    const storeModule = await loadTs('stores/tenantSwitchStore.ts', {
        vue, pinia: { defineStore: (_: string, setup: any) => setup }, '@/router/router': { default: { push: async () => {} } },
        '@/stores/authStore': { useAuthStore: () => auth }, '@/stores/masjidStore': { useMasjidStore: () => org },
        '@/stores/plugins/tenantStoreReset': { resetTenantScopedStores: () => { resets++; org.masjid = null as any; return []; } },
        '@/core/tenancy/tenantRequests': { bumpTenantEpoch: () => epoch++, forgetServerTenant() {}, serverTenantId: vue.ref(1) },
        '@/core/types/data/Membership': { grantedMemberships: (x: any) => x, membershipLabel: () => 'Two' },
        '@/core/tenancy/unsavedChanges': registration,
    });
    try {
        await flush(); click(screen.all(n => n.textContent === 'Add to tabs' && n.tag === 'button')[0]); await flush();
        const switcher = storeModule.useTenantSwitchStore();
        assert.equal(await switcher.switchTo(2), false);
        assert.equal(prompts, 1); assert.equal(auth.dashboardMasjidId, 1); assert.equal(resets, 0); assert.equal(epoch, 0);
        assert.match(screen.text(), /Unsaved changes/);
        confirm = true; assert.equal(await switcher.switchTo(2), true);
        assert.equal(prompts, 2); assert.equal(auth.dashboardMasjidId, 2); assert.equal(resets, 1); assert.equal(epoch, 1);
    } finally { screen.unmount(); (globalThis as any).window = originalWindow; }
});
