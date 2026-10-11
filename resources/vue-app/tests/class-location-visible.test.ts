import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as vue from 'vue';
import { createPinia, setActivePinia } from 'pinia';
import { mountSfc, flush, click, type, submit, Node, withDocumentKeys } from './support/mountSfc.ts';
import { realClassModules } from './support/classSubjectModules.ts';

withDocumentKeys();
(Node.prototype as any).__v_skip = true;
(globalThis as any).document.body = { style: {} };
(globalThis as any).window = { innerWidth: 390, innerHeight: 900, addEventListener() {}, removeEventListener() {}, matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }) };
const ok = (data: any, meta: any = {}) => ({ data: { status: 'success', data, meta } });
const paths = { 'office-list':'views/dashboard/GroupsView.vue', 'office-page':'views/dashboard/GroupDetailView.vue', 'teacher-list':'views/teacher/TeacherClasses.vue', 'teacher-page':'views/teacher/TeacherClass.vue' };
type Surface = keyof typeof paths;
async function screen(surface: Surface, on: boolean, location: string | null = null, width = 390, hash = '', name = 'Practice Class') {
    setActivePinia(createPinia()); (globalThis as any).window.innerWidth = width;
    const calls: string[] = [];
    const group: any = { id: 2, masjid_id: 1, name, slug: 'practice-class', kind: 'class', is_active: true, description: null, teachers: [], students: [], memberships: [], my_subjects: null, subject_labels: {}, unread_messages: 0, ...(on ? { location_name: location } : {}) };
    const masjid = { masjid: { id: 1, capabilities: { school_timetable: on } }, orgType: 'school', term: (x: string) => x === 'groups' ? 'Classrooms' : x };
    const store: any = vue.reactive({ groupsPaginated: undefined, groupsMeta: undefined, memberships: [], rosterMeta: {}, pendingClaims: 0, contestedClaims: 0 });
    const get = async (url: string) => {
        calls.push(url);
        if (url.endsWith('/location')) return ok({ location: location ? { id: 4, name: location } : null, locations: [{ id: 4, name: 'Room 3' }] });
        if (url.endsWith('/groups')) return ok([group]);
        if (url.endsWith('/groups/2')) return ok(group);
        return ok([]);
    };
    const api: any = { get, put: async (url: string, body: any) => { calls.push(url); return ok({ location: body.name ? { id: 4, name: body.name } : null, locations: [] }); } };
    store.fetchGroup = async () => (await get('/api/admin/masjids/1/groups/2')).data.data;
    store.fetchMemberships = async () => { calls.push('/api/admin/masjids/1/groups/2/memberships'); };
    store.fetchGroups = async () => { calls.push('/api/admin/masjids/1/groups'); store.groupsPaginated = { data: [group], current_page: 1, total: 1, per_page: 15 }; store.groupsMeta = { group_label: 'Classrooms', kinds: ['class'], roles: [] }; };
    const file = paths[surface];
    const modules = await realClassModules(file, {
        'vue-router': { useRoute: () => ({ params: { groupId: '2' }, query: {}, hash }), useRouter: () => ({ replace() {}, push() {} }) },
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1, user: { type: surface.startsWith('teacher') ? 'Teacher' : 'SuperAdmin', id: 5 } }) },
        '@/stores/masjidStore': { useMasjidStore: () => masjid }, '../masjidStore': { useMasjidStore: () => masjid },
        '@/stores/masjid/groupsStore': { useGroupsStore: () => store },
        '@/core/services/ApiService': { default: api }, '@/core/services/TeacherApiService': { default: api, rowsOf: (x: any) => Array.isArray(x) ? x : x?.data ?? [] },
        '@/core/constants/appConfigConstants': { API_CONFIG: { base_url: '' }, LOCAL_STORAGE_KEYS: {} },
        sweetalert2: { default: { fire: async () => ({ isConfirmed: false }), mixin: () => ({ fire: async () => ({ isConfirmed: false }) }) } },
    });
    // Retain RouterLink destinations in the rendered tree for the location-field link assertion.
    modules.vue = { ...modules.vue, resolveComponent: (name: string) => name === 'router-link'
        ? vue.defineComponent({ props: ['to'], setup: (p: any, { slots }: any) => () => vue.h('a', { to: p.to }, slots.default?.()) }) : vue.resolveComponent(name) };
    const s = await mountSfc(file, {}, modules); await flush(10);
    return { ...s, calls, group };
}
function html(node: Node): string {
    if (node.kind === 'text') return node.text;
    if (node.kind === 'comment') return `<!--${node.text}-->`;
    const attrs = Object.entries(node.props).filter(([key,value]) => !/^on[A-Z]/.test(key) && typeof value !== 'function' && key !== 'ref')
        .sort(([a],[b]) => a.localeCompare(b)).map(([key,value]) => `${key}=${JSON.stringify(value)}`).join(' ');
    return `<${node.tag}${attrs ? ' '+attrs : ''}>${node.children.map(html).join('')}</${node.tag}>`;
}
test('location OFF literal 52daab37 mounted bytes and request lists for all four screens', async () => {
    const actual: any = {};
    for (const surface of Object.keys(paths) as Surface[]) {
        const s = await screen(surface, false);
        try { actual[surface] = { html: html(s.root), calls: s.calls }; } finally { s.unmount(); }
    }
    const expected = JSON.parse(readFileSync('resources/vue-app/tests/fixtures/class-location-screens-off-52daab37.json', 'utf8'));
    // Office attendance is an unconditional new class tab. Keep the original location fixture
    // and every other rendered byte/request pinned; add only this exact new navigation line.
    const story = '<li class="nav-item"><button class="nav-link" type="button"><i class="bi bi-journal-text me-1"></i>';
    const attendance = '<li class="nav-item"><button class="nav-link" type="button"><i class="bi bi-calendar-check me-1"></i> Attendance <!--v-if--></button></li>';
    assert.equal(expected['office-page'].html.split(story).length, 2);
    expected['office-page'].html = expected['office-page'].html.replace(story, attendance + story);
    assert.deepEqual(JSON.parse(JSON.stringify(actual)), expected);
});
for (const surface of Object.keys(paths) as Surface[]) for (const location of ['Room 3', null]) {
    test(`location mounted ${surface} with ${location ?? 'no location'}`, async () => {
        const s = await screen(surface, true, location);
        try {
            assert.match(s.text(), /Practice Class/);
            const displays = s.all(n => n.props['data-class-location'] !== undefined);
            if (location) { assert.equal(displays.length, 1); assert.equal(displays[0].textContent, 'Location: Room 3'); }
            else if (surface.startsWith('teacher')) { assert.equal(displays.length, 0); assert.doesNotMatch(s.text(), /Location:|No location/); }
            else {
                assert.equal(displays[0].textContent, 'No location set');
                if (surface === 'office-list') assert.deepEqual(displays[0].props.to, { name: 'masjid.groupDetail', params: { groupId: 2 }, hash: '#class-location' });
            }
            if (surface === 'office-page') {
                const title = s.all(n => String(n.props.class).includes('card-title'))[0];
                assert.match(title.textContent, /Practice Class/); assert.ok(displays[0].parent === title);
                assert.equal(s.all(n => n.tag === 'input' && n.props.id === 'class-location').length, 1);
                type(s.all(n => n.props.id === 'class-location')[0], 'New Hall'); await flush();
                submit(s.all(n => n.tag === 'form' && n.textContent.includes('Save location'))[0]); await flush();
                assert.equal(s.all(n => n.props['data-class-location'] !== undefined)[0].textContent, 'Location: New Hall');
                type(s.all(n => n.props.id === 'class-location')[0], ''); await flush();
                submit(s.all(n => n.tag === 'form' && n.textContent.includes('Save location'))[0]); await flush();
                assert.equal(s.all(n => n.props['data-class-location'] !== undefined)[0].textContent, 'No location set');
            }
            else assert.equal(s.calls.filter(u => u.endsWith('/location')).length, 0);
        } finally { s.unmount(); }
    });
}
test('location names stay on a separate wrapping line at phone widths with RTL-safe classes', async () => {
    // The renderer does not measure CSS geometry: assert the wrapping contract, then verify in a browser separately.
    for (const width of [390,320]) for (const surface of Object.keys(paths) as Surface[]) {
        const s = await screen(surface,true,'Practice Hall with a very long location name',width);
        try {
            const display = s.all(n => n.props['data-class-location'] !== undefined)[0];
            assert.match(String(display.props.class), /text-break/); assert.match(String(display.props.class), /d-block/);
            assert.match(s.text(), /Practice Class/);
        } finally { s.unmount(); }
    }
});

test('No location set opens and focuses the existing Location field', async () => {
    const s = await screen('office-page',true,null,320,'#class-location');
    try {
        const input = s.all(n => n.props.id === 'class-location')[0];
        assert.equal((globalThis as any).document.activeElement,input);
    } finally { s.unmount(); }
});
