import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { createPinia, setActivePinia } from 'pinia';
import { click, deferred, flush, httpError, loadTs, mountSfc, Node, pressKey, chooseOption, submit, type, withDocumentKeys } from './support/mountSfc.ts';
import { realClassModules } from './support/classSubjectModules.ts';

const doc = (globalThis as any).document;
withDocumentKeys();
doc.body = { style: {} };
const resizeHandlers = new Set<() => void>();
(globalThis as any).window = { innerHeight: 900, scrollY: 0, innerWidth: 1280, addEventListener(event: string, fn: () => void) { if (event === 'resize') resizeHandlers.add(fn); }, removeEventListener(event: string, fn: () => void) { if (event === 'resize') resizeHandlers.delete(fn); }, matchMedia: () => ({ matches: (globalThis as any).window.innerWidth < 768, addEventListener() {}, removeEventListener() {} }) };
// DOM elements are opaque to Vue; model that here so ref focus identity stays real.
(Node.prototype as any).__v_skip = true;
// The custom renderer models focus and inert attributes, not CSS geometry.
(Node.prototype as any).querySelectorAll = function(selector: string) {
    const out: Node[] = [];
    const walk = (n: Node) => { for (const c of n.children) { if (c.kind === 'el' && (c.tag === 'button' || c.tag === 'a')) out.push(c); walk(c); } };
    walk(this); return out;
};
(Node.prototype as any).getAttribute = function(key: string) { return this.props[key]; };
(Node.prototype as any).getBoundingClientRect = function() { return { top: 0, bottom: 0 }; };
(Node.prototype as any).getClientRects = function() { return [{}]; };
(Node.prototype as any).scrollIntoView = function() {};
(Node.prototype as any).closest = function(selector: string): any { return selector === '[inert]' && this.props.inert !== undefined ? this : this.parent?.closest(selector); };
(Node.prototype as any).contains = function(n: Node): boolean { return n === this || this.children.some((c: any) => c.contains(n)); };
(Node.prototype as any).hasAttribute = function(key: string): boolean { return key in this.props; };
const ok = (data: any, meta: any = {}) => ({ data: { status: 'success', data, meta } });
const subjects = [
    { id: 101, name: "Qur'an", tool: 'hifdh', position: 0 },
    { id: 102, name: 'Arabic', tool: 'arabic_letters', position: 1 },
    { id: 103, name: 'ELA', tool: 'english_letters', position: 2 },
    { id: 104, name: 'Healthful Living', tool: null, position: 3 },
].map((s) => ({ ...s, guide_subject: null, hidden_at: null, group_id: 2, masjid_id: 1 }));
const student = { membership_id: 9, grade_label: '1', contact: { id: 3, first_name: 'Practice student', last_name: '' } };
const membership = { id: 9, role: 'member', provenance: 'confirmed', left_on: null, ...student };
const row = { id: 7, membership_id: 9, kind: 'sabak', quality: 'good', whole_surah: false, from: { surah: 1, surah_name: 'Al-Fatihah', ayah: 1 }, to: { surah: 1, surah_name: 'Al-Fatihah', ayah: 3 }, recited_at: '2026-10-01', note: 'Saved practice note' };
const trackerFor = (alphabet: string) => ({
    alphabet, direction: alphabet === 'english' ? 'ltr' : 'rtl', student,
    stage: null, stages: [], groups: [], totals: { mastered: 1, total: 2 },
    ...(alphabet === 'english' ? {
        letters: [{ id: 'a', glyph: 'Aa', status: 'learning', drills: [
            { id: 'a.upper', set: 'upper', text: 'A', label: 'Capital A', status: 'mastered' },
            { id: 'a.lower', set: 'lower', text: 'a', label: 'Lower case a', status: 'not_started' },
        ] }],
        sets: [{ id: 'upper', label: 'Capitals' }, { id: 'lower', label: 'Lower case' }],
        set_totals: [{ id: 'upper', mastered: 1, total: 1 }, { id: 'lower', mastered: 0, total: 1 }],
    } : {
        letters: [{ id: 'alif', glyph: 'ا', transliteration: 'Alif', status: 'mastered', drills: [] }],
        sets: [], set_totals: [],
    }),
});
const teacherWords = ['Roster', 'Attendance', 'Letters', 'Points', 'Hifdh', 'Class Story', 'Messages'];
const moreWords = ['Lesson Plans', 'Grades', 'Reports', 'Files'];
const officeWords = ['Roster', 'Class Story', 'Points', 'Letters', 'Gradebook', 'Lesson Plans', 'Hifdh', 'Messages', 'Files'];
const navLinks = (screen: any) => screen.all((n: Node) => n.tag === 'a' && n.props['data-class-choice'] !== undefined);
const exactButton = (screen: any, words: string) => {
    const found = screen.all((n: Node) => n.tag === 'button' && (n.textContent === words || n.props['aria-label'] === words));
    assert.equal(found.length, 1, words); return found[0];
};
const field = (screen: any, name: string) => { const result = screen.all((n: Node) => n.props['data-subject-field'] === name); assert.equal(result.length, 1, name); return result[0]; };
async function pick(screen: any, words: string) {
    const link = navLinks(screen).find((n: Node) => n.textContent === words);
    assert.ok(link, `${words}: ${screen.text()}`); click(link); await flush(10);
}

async function setup(realm: 'teacher' | 'office', options: any = {}) {
    setActivePinia(createPinia());
    (globalThis as any).window.innerWidth = options.width ?? 1280;
    const data = vue.reactive<any>({ id: 2, name: 'Practice class', kind: 'class', is_active: true, students: [student], memberships: [membership],
        ...(options.flag === false ? {} : { class_subjects_enabled: true, class_subjects: structuredClone(subjects), my_class_subject_ids: options.mine ?? null }), ...options.data });
    let catalog = structuredClone(options.subjects ?? subjects);
    const calls: any[] = [];
    const alerts: any[] = [];
    const pending = options.detail;
    const route = vue.reactive<any>({ params: { masjidId: '1', groupId: '2' }, query: { ...options.query } });
    const history: any[] = [{ ...route.query }];
    const router = {
        resolve: (where: any) => ({ href: '/class?' + new URLSearchParams(where.query).toString() }),
        push: async (where: any) => { route.query = { ...where.query }; history.push({ ...where.query }); },
        replace: async (where: any) => { route.query = { ...where.query }; history[history.length - 1] = { ...where.query }; },
        back: () => { history.pop(); route.query = { ...history[history.length - 1] }; },
    };
    const get = async (url: string) => {
        calls.push({ method: 'get', url });
        if (options.read) { const response = options.read(url); if (response !== undefined) return response; }
        if (url.endsWith('/groups/2')) return ok(data);
        if (url.endsWith('/school-subjects')) return ok([{ name: 'Science' }, { name: 'Mathematics' }]);
        if (url.endsWith('/subjects')) return ok(structuredClone(catalog), { guide_subjects: ['English Language Arts', 'Science'], tools: ['hifdh', 'arabic_letters', 'english_letters'] });
        if (/\/subjects\/\d+$/.test(url)) {
            if (pending) return pending(url);
            const id = Number(url.split('/').pop());
            const subject = catalog.find((s: any) => s.id === id && !s.hidden_at && (!options.mine?.length || options.mine.includes(id)));
            if (!subject) throw httpError(404, { message: 'Not found.' });
            return ok(structuredClone(subject));
        }
        if (options.read) { const response = options.read(url); if (response !== undefined) return response; }
        if (url.includes('/letters')) return ok(url.includes('/members/') ? trackerFor(url.includes('english') ? 'english' : 'arabic') : { students: [{ ...student, name: 'Practice student', mastered: 4, total: 26 }], stage: null, stages: [], total: 26 });
        if (/\/(posts|threads|awards)\?/.test(url)) return ok({ data: [], current_page: 1, total: 0, per_page: 25, last_page: 1 });
        if (url.endsWith('/hifz')) return ok([row]);
        if (url.endsWith('/awards/summary')) return ok({ totals: { awards: 0, points: 0 }, by_polarity: { positive: { points: 0 }, negative: { points: 0 } } });
        if (url.includes('/progress')) return ok({});
        if (url.includes('/behavior-skills')) return ok([]);
        if (url.includes('/points')) return ok({ students: [], awards: [], totals: [], window: { kind: 'week', week_of: '2026-09-27', from: '2026-09-27', to: '2026-10-03' } });
        return ok([]);
    };
    const write = async (method: string, url: string, body: any) => {
        calls.push({ method, url, body });
        if (options.write) { const response = options.write(method, url, body); if (response !== undefined) return response; }
        if (options.refuse?.(method, url, body)) throw httpError(422, options.refuse(method, url, body));
        const id = Number(url.match(/subjects\/(\d+)/)?.[1]);
        if (url.endsWith('/reorder')) catalog = body.subject_ids.map((id: number, position: number) => ({ ...catalog.find((s: any) => s.id === id), position }));
        else if (url.endsWith('/add-for-current-grades')) catalog.push({ ...subjects[3], id: 106, name: 'Mathematics', position: catalog.length });
        else if (method === 'post') catalog.push({ ...subjects[3], ...body, id: Math.max(...catalog.map((s: any) => s.id)) + 1, position: catalog.length });
        else if (url.endsWith('/restore')) catalog = catalog.map((s: any) => s.id === id ? { ...s, hidden_at: null } : s);
        else if (method === 'delete') catalog = catalog.map((s: any) => s.id === id ? { ...s, hidden_at: '2026-10-08' } : s);
        else catalog = catalog.map((s: any) => s.id === id ? { ...s, ...body } : s);
        return ok(catalog.find((s: any) => s.id === id) ?? catalog);
    };
    const api: any = { get, post: (u: string, b: any) => write('post', u, b), put: (u: string, b: any) => write('put', u, b), delete: (u: string, b?: any) => write('delete', u, b), blobUrl: async () => 'blob:practice' };
    api.VueApp = { axios: { get: api.get, post: api.post, put: api.put } };
    const groupsStore = vue.reactive<any>({ memberships: data.memberships, rosterMeta: { teaches_students: true, school_today: '2026-10-08' }, pendingClaims: 0, contestedClaims: 0,
        fetchGroup: async () => (await get('/api/admin/masjids/1/groups/2')).data.data,
        fetchMemberships: async () => { calls.push({ method: 'get', url: '/api/admin/masjids/1/groups/2/memberships' }); } });
    const hifzStore = vue.reactive<any>({ entriesPaginated: { data: [row], current_page: 1, per_page: 25, total: 26, last_page: 2 }, progressByMembership: {}, surahs: [],
        fetchEntries: async (_id: any, p = 1) => { calls.push({ method: 'hifz-page', page: p }); hifzStore.entriesPaginated.current_page = p; }, fetchProgress: async () => {}, fetchSurahs: async () => {}, ...options.hifzStore });
    const masjid = { masjid: { id: 1 }, term: (x: string) => x === 'groups' ? 'Classrooms' : x, orgType: 'school' };
    const overrides: any = {
        '../masjidStore': { useMasjidStore: () => masjid },
        '@/core/constants/appConfigConstants': { API_CONFIG: { base_url: '' }, LOCAL_STORAGE_KEYS: {} },
        'vue-router': { useRoute: () => route, useRouter: () => router },
        '@/core/services/TeacherApiService': { default: api, rowsOf: (x: any) => Array.isArray(x) ? x : x?.data ?? [] },
        '@/core/services/ApiService': { default: api },
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1, user: { type: 'Admin', id: 5 } }) },
        '@/stores/masjidStore': { useMasjidStore: () => masjid },
        '@/stores/masjid/groupsStore': { useGroupsStore: () => groupsStore },
        '@/stores/masjid/hifzStore': { useHifzStore: () => hifzStore },
        sweetalert2: { default: { fire: async (notice: any) => { alerts.push(notice); return { isConfirmed: options.confirm ?? false }; }, mixin: () => ({ fire: async () => ({ isConfirmed: false }) }) } },
    };
    Object.assign(overrides, options.modules);
    const file = realm === 'teacher' ? 'views/teacher/TeacherClass.vue' : 'views/dashboard/GroupDetailView.vue';
    const screen = await mountSfc(file, {}, await realClassModules(file, overrides));
    await flush(10);
    if (realm === 'office' && !options.closed) {
        const disclosure = screen.all((n: Node) => n.tag === 'button' && /^Class subjects \(\d+\)$/.test(n.textContent))[0];
        if (disclosure) { click(disclosure); await flush(); }
    }
    return { screen, data, calls, router, route, hifzStore, alerts };
}

test('check 1: OFF pins every legacy tab word and its original bootstrap requests, both views', async () => {
    for (const realm of ['teacher', 'office'] as const) {
        const { screen, calls } = await setup(realm, { flag: false });
        try {
            assert.deepEqual(screen.all((n) => n.tag === 'button' && String(n.props.class).includes('nav-link')).map((n) => n.textContent), realm === 'teacher' ? teacherWords : officeWords);
            assert.equal(navLinks(screen).length, 0);
            if (realm === 'teacher') { click(exactButton(screen, 'More')); await flush(); assert.deepEqual(screen.all((n) => n.tag === 'button' && moreWords.includes(n.textContent)).map((n) => n.textContent), moreWords); }
            assert.deepEqual(calls.map((c) => c.url), realm === 'teacher' ? ['/api/teacher/masjids/1/groups/2'] : ['/api/admin/masjids/1/groups/2', '/api/admin/masjids/1/groups/2/memberships']);
        } finally { screen.unmount(); }
    }
});
test('check 2: ON headings/order, teacher lines and school-only Class Store', async () => {
    for (const store of [false, true]) {
        const { screen } = await setup('teacher', { data: { class_store: store } });
        try {
            assert.deepEqual(screen.all((n) => n.props['data-class-section'] !== undefined).map((n) => n.textContent), ['Class', 'Subjects', 'Families', 'Planning and marks']);
            assert.deepEqual(navLinks(screen).map((n) => n.textContent), ['Roster', 'Attendance', 'Points', ...(store ? ['Class Store'] : []), ...subjects.map((s) => s.name), 'Class Story', 'Messages', 'Lesson Plans', 'Grades', 'Reports', 'Files']);
            assert.equal(screen.all((n) => n.tag === 'button' && n.textContent === 'More').length, 0);
            assert.ok(navLinks(screen).find((n) => n.textContent === 'Roster')?.props['aria-current']);
        } finally { screen.unmount(); }
    }
});
test('check 3: 390 and 320 phone panel closes on choice and identifies the page', async () => {
    for (const width of [390, 320]) {
        const { screen } = await setup('teacher', { width });
        try {
            assert.equal(navLinks(screen).length, 0);
            const trigger = exactButton(screen, 'Class menu'); click(trigger); await flush();
            assert.equal(trigger.props['aria-expanded'], 'true');
            assert.equal(navLinks(screen).length, 13);
            await pick(screen, 'Healthful Living');
            assert.equal(navLinks(screen).length, 0);
            assert.equal(screen.all((n) => n.tag === 'h2')[0].textContent, 'Healthful Living');
            assert.match(screen.text(), /There is nothing here yet\./);
        } finally { screen.unmount(); }
    }
});
test('check 4: subject order comes from the server, hidden rows do not enter the menu', async () => {
    const { screen } = await setup('teacher', { data: { class_subjects: [{ ...subjects[3], position: 0 }, { ...subjects[0], position: 1 }, { ...subjects[1], hidden_at: '2026-10-08' }] } });
    try { assert.deepEqual(navLinks(screen).filter((n) => String(n.props.href).includes('subject=')).map((n) => n.textContent), ['Healthful Living', "Qur'an"]); } finally { screen.unmount(); }
});
test('check 5: existing teacher Hifdh and both letter tools mount within subjects, with fixed alphabet and saved work', async () => {
    const { screen, calls } = await setup('teacher');
    try {
        await pick(screen, "Qur'an");
        assert.match(screen.text(), /recitation log for one student/);
        const select = screen.all((n) => n.tag === 'select' && n.children.some((c) => c.props.value === 9))[0]; chooseOption(select, 9); await flush();
        assert.match(screen.text(), /Saved practice note/);
        for (const [name, alphabet] of [['Arabic', 'arabic'], ['ELA', 'english'], ['Arabic', 'arabic']]) {
            await pick(screen, name);
            assert.equal(screen.all((n) => n.props['aria-label'] === 'Alphabet').length, 0);
            assert.ok(calls.some((c) => c.url?.endsWith(`/letters?alphabet=${alphabet}`)));
            click(screen.button('Practice student')); await flush();
            assert.ok(calls.some((c) => c.url?.endsWith(`/members/9/letters?alphabet=${alphabet}`)));
            assert.match(screen.text(), /1 of 2 mastered/);
            assert.ok(screen.all((n) => String(n.props.class).includes('letter-tile--mastered')).length);
            if (alphabet === 'english') { assert.match(screen.text(), /Capitals/); assert.match(screen.text(), /Lower case/); }
        }
        await pick(screen, 'Healthful Living'); assert.match(screen.text(), /There is nothing here yet\./);
    } finally { screen.unmount(); }
});
test('check 6: office actions add, rename, order including hidden, hide and bring back; teacher has no manager', async () => {
    const { screen, calls } = await setup('office', { subjects: [...subjects, { ...subjects[3], id: 100, name: 'Hidden elective', position: 4, hidden_at: '2026-10-08' }] });
    try {
        assert.match(screen.text(), /Class subjects/);
        type(field(screen, 'name'), 'Science'); submit(screen.all((n) => n.props['data-subject-form'] !== undefined)[0]); await flush();
        assert.ok(navLinks(screen).some((n) => n.textContent === 'Science'), JSON.stringify(calls) + screen.text());
        click(exactButton(screen, 'Rename Science')); await flush(); type(field(screen, 'name'), 'Natural Science'); submit(screen.all((n) => n.props['data-subject-form'] !== undefined)[0]); await flush();
        assert.ok(navLinks(screen).some((n) => n.textContent === 'Natural Science'));
        click(exactButton(screen, 'Move Natural Science up')); await flush();
        assert.deepEqual(new Set(calls.find((c) => c.url?.endsWith('/reorder')).body.subject_ids), new Set([100, 101, 102, 103, 104, 105]));
        click(exactButton(screen, 'Remove Natural Science')); await flush();
        assert.match(screen.text(), /Hide Natural Science\? All its work is kept\. Nothing is deleted\./);
        click(exactButton(screen, 'Hide subject')); await flush();
        assert.equal(navLinks(screen).some((n) => n.textContent === 'Natural Science'), false); assert.match(screen.text(), /Hidden/);
        click(exactButton(screen, 'Bring back Natural Science')); await flush();
        assert.ok(navLinks(screen).some((n) => n.textContent === 'Natural Science'));
    } finally { screen.unmount(); }
    const teacher = await setup('teacher'); try { assert.doesNotMatch(teacher.screen.text(), /Class subjects|Add subject|Bring back|Follows the curriculum for/); } finally { teacher.screen.unmount(); }
});
test('check 7: rename keeps letters, Holds and curriculum pickers save, each server refusal is shown verbatim', async () => {
    const { screen, calls } = await setup('office');
    try {
        click(exactButton(screen, 'Rename Arabic')); await flush(); type(field(screen, 'name'), 'Arabic Language');
        chooseOption(field(screen, 'guide'), 'Science'); submit(screen.all((n) => n.props['data-subject-form'] !== undefined)[0]); await flush();
        // With subject notes and marks off the manager is main's: one picker, and no list is ever sent.
        assert.equal(calls.filter(c => c.method === 'put').at(-1).body.guide_subject, 'Science');
        assert.equal('guide_subjects' in calls.filter(c => c.method === 'put').at(-1).body, false);
        await pick(screen, 'Arabic Language'); assert.match(screen.text(), /Practice student/);
        assert.ok(calls.some((c) => c.url?.endsWith('/letters?alphabet=arabic')));
        await pick(screen, 'Roster'); click(exactButton(screen, 'Class subjects (4)')); await flush();
        click(exactButton(screen, 'Rename Healthful Living')); await flush(); chooseOption(field(screen, 'tool'), 'english_letters'); submit(screen.all((n) => n.props['data-subject-form'] !== undefined)[0]); await flush();
        assert.equal(calls.filter((c) => c.method === 'put').at(-1).body.tool, 'english_letters');
    } finally { screen.unmount(); }
    for (const body of [
        { status: 'failed', data: { tool: ['This class already has a subject holding English letters.'] } },
        { status: 'failed', data: { name: ['That subject name is already in use.'] } },
        { status: 'error', message: 'That name would collide with saved work.' },
    ]) {
        const refused = await setup('office', { refuse: () => body });
        try { type(field(refused.screen, 'name'), 'Science'); submit(refused.screen.all((n) => n.props['data-subject-form'] !== undefined)[0]); await flush(); assert.ok(refused.screen.text().includes(body.message ?? Object.values(body.data!)[0][0])); } finally { refused.screen.unmount(); }
    }
});
test('check 8: limited teacher list, refused subject GET and bad-id address land quietly on Roster', async () => {
    for (const id of ['101', '999']) {
        const { screen, calls, route } = await setup('teacher', { mine: [102], query: { subject: id } });
        try {
            assert.deepEqual(navLinks(screen).filter((n) => String(n.props.href).includes('subject=')).map((n) => n.textContent), ['Arabic']);
            assert.match(screen.text(), /That subject is not available\. Showing Roster\./);
            assert.equal(screen.all((n) => n.tag === 'h2')[0].textContent, 'Roster');
            assert.ok(calls.some((c) => c.url?.endsWith(`/subjects/${id}`))); assert.equal(route.query.subject, undefined);
        } finally { screen.unmount(); }
    }
});
test('check 9: weekly Points address stays on its week; subject refresh and Back keep their page', async () => {
    const points = await setup('teacher', { query: { tab: 'points', week: '2026-09-27' } });
    try { assert.equal(points.screen.all((n) => n.tag === 'h2')[0].textContent, 'Points'); assert.ok(points.calls.some((c) => c.url?.includes('week=2026-09-27'))); } finally { points.screen.unmount(); }
    const { screen, route, router } = await setup('teacher', { query: { subject: '104' } });
    try {
        assert.match(screen.text(), /There is nothing here yet\./); assert.equal(screen.all((n) => n.tag === 'h2')[0].textContent, 'Healthful Living');
        await pick(screen, 'Arabic'); assert.equal(route.query.subject, '102'); router.back(); await flush();
        assert.equal(screen.all((n) => n.tag === 'h2')[0].textContent, 'Healthful Living');
    } finally { screen.unmount(); }
});
test('check 10: empty new-class payload uses the server seed and Add subjects for current grades merges the roster grades', async () => {
    const { screen, calls } = await setup('office', { data: { students: [], memberships: [] } });
    try {
        assert.ok(navLinks(screen).some((n) => n.textContent === 'ELA'));
        click(exactButton(screen, 'Add subjects for current grades')); await flush();
        assert.ok(calls.some((c) => c.method === 'post' && c.url.endsWith('/add-for-current-grades')));
        assert.ok(navLinks(screen).some((n) => n.textContent === 'Mathematics'));
    } finally { screen.unmount(); }
});
test('check 11: flag OFF and ON in one session restores legacy view and the same subject in both realms', async () => {
    for (const realm of ['teacher', 'office'] as const) {
        const { screen, data } = await setup(realm); try {
            await pick(screen, 'Healthful Living'); data.class_subjects_enabled = false; await flush();
            assert.deepEqual(screen.all((n) => n.tag === 'button' && String(n.props.class).includes('nav-link')).map((n) => n.textContent), realm === 'teacher' ? teacherWords : officeWords);
            assert.match(screen.text(), realm === 'teacher' ? /Enrolment is managed/ : /Practice student/);
            data.class_subjects_enabled = true; await flush(); assert.equal(screen.all((n) => n.tag === 'h2')[0].textContent, 'Healthful Living');
        } finally { screen.unmount(); }
    }
});
test('office menu has only existing lines; real Hifdh pager reaches page 2, and letters lock each alphabet', async () => {
    const { screen, calls } = await setup('office'); try {
        assert.deepEqual(navLinks(screen).map((n) => n.textContent), ['Roster', 'Points', ...subjects.map((s) => s.name), 'Class Story', 'Messages', 'Lesson Plans', 'Grades', 'Files']);
        await pick(screen, "Qur'an");
        click(screen.all((n) => n.props['aria-label'] === 'Next page')[0]); await flush();
        assert.ok(calls.some((c) => c.method === 'hifz-page' && c.page === 2));
        for (const [name, alphabet] of [['Arabic', 'arabic'], ['ELA', 'english']]) {
            await pick(screen, name); assert.equal(screen.all((n) => n.props['aria-label'] === 'Alphabet').length, 0); assert.ok(calls.some((c) => c.url?.endsWith(`/letters?alphabet=${alphabet}`)));
        }
    } finally { screen.unmount(); }
});
test('phone focus is contained, Escape/backdrop return focus, choice focuses heading; listeners leave with the view', async () => {
    const keys = withDocumentKeys(); const before = keys.listeners.size;
    const { screen } = await setup('teacher', { width: 390 });
    try {
        const trigger = exactButton(screen, 'Class menu'); trigger.focus(); click(trigger); await flush();
        assert.ok(screen.all((n) => n.props.role === 'dialog').length);
        assert.ok(screen.all((n) => n.props.inert !== undefined).length);
        const focused = doc.activeElement; assert.notEqual(focused, trigger);
        const choices = screen.all((n) => n.tag === 'a' || n.textContent === 'Close class menu').filter((n) => n.props['data-class-choice'] !== undefined || n.textContent === 'Close class menu');
        choices.at(-1)!.focus(); pressKey('Tab'); assert.equal(doc.activeElement, choices[0]);
        pressKey('Escape'); await flush(); assert.equal(doc.activeElement, trigger);
        click(trigger); await flush(); click(screen.all((n) => n.props['data-class-backdrop'] !== undefined)[0]); await flush(); assert.equal(doc.activeElement, trigger);
        click(trigger); await flush(); await pick(screen, 'Healthful Living'); assert.equal(doc.activeElement.tag, 'h2');
    } finally { screen.unmount(); }
    assert.equal(keys.listeners.size, before);
});
test('leaving during subject validation cannot mount a late tool or replace the selected page', async () => {
    const answer = deferred(); const { screen, calls } = await setup('teacher', { detail: () => answer.promise });
    try {
        const arabic = navLinks(screen).find((n) => n.textContent === 'Arabic')!; click(arabic); await flush();
        await pick(screen, 'Points'); answer.resolve(ok(subjects[1])); await flush();
        assert.equal(screen.all((n) => n.tag === 'h2')[0].textContent, 'Points');
        assert.equal(calls.some((c) => c.url?.includes('/letters?')), false);
    } finally { screen.unmount(); }
});

test('real media preview URLs are released when leaving Class Story and on screen disposal', async () => {
    const beforeCreate = URL.createObjectURL; const beforeRevoke = URL.revokeObjectURL;
    const created: string[] = []; const revoked: string[] = [];
    URL.createObjectURL = () => { const url = `blob:practice-${created.length}`; created.push(url); return url; };
    URL.revokeObjectURL = (url: string) => { revoked.push(url); };
    const { screen } = await setup('teacher');
    try {
        await pick(screen, 'Class Story');
        const input = screen.all((n) => n.tag === 'input' && n.props.type === 'file')[0];
        const video = new File(['practice'], 'practice.webm', { type: 'video/webm' });
        (input as any).files = [video]; input.props.onChange({ target: input }); await flush();
        assert.equal(created.length, 1); assert.ok(screen.all((n) => n.tag === 'video' && n.props.src === created[0]).length);
        await pick(screen, 'Roster'); assert.deepEqual(revoked, created);
        await pick(screen, 'Class Story'); assert.equal(created.length, 2);
        screen.unmount(); assert.deepEqual(revoked, created);
    } finally { screen.unmount(); URL.createObjectURL = beforeCreate; URL.revokeObjectURL = beforeRevoke; }
});

test('Points Back restores the email week and a same-tab address change reads that week', async () => {
    const { screen, calls, router } = await setup('teacher', { query: { tab: 'points', week: '2026-09-27' } });
    try {
        await pick(screen, 'Arabic'); router.back(); await flush();
        assert.ok(calls.filter((c) => c.url?.includes('/awards/totals')).at(-1).url.endsWith('?week=2026-09-27'));
        await router.push({ query: { tab: 'points', week: '2026-09-20' } }); await flush();
        assert.ok(calls.filter((c) => c.url?.includes('/awards/totals')).at(-1).url.endsWith('?week=2026-09-20'));
    } finally { screen.unmount(); }
});
test('an Arabic tracker arriving after ELA opens cannot replace its English letters', async () => {
    const late = deferred();
    const { screen } = await setup('teacher', { read: (url: string) => url.endsWith('/members/9/letters?alphabet=arabic') ? late.promise : undefined });
    try {
        await pick(screen, 'Arabic'); click(screen.button('Practice student')); await flush();
        await pick(screen, 'ELA'); click(screen.button('Practice student')); await flush();
        late.resolve(ok({ alphabet: 'arabic', direction: 'rtl', letters: [{ id: 'alif', glyph: 'ا', transliteration: 'Alif', status: 'mastered', drills: [] }], stages: [], groups: [] })); await flush();
        assert.equal(screen.all((n) => n.tag === 'h2')[0].textContent, 'ELA');
        assert.equal(screen.all((n) => n.props.dir === 'rtl' && n.tag === 'div').length, 0, 'the active tool stays English');
        assert.doesNotMatch(screen.text(), /Alif/);
    } finally { screen.unmount(); }
});

test('every ON non-subject line refreshes to its real teacher and office panel', async () => {
    for (const realm of ['teacher', 'office'] as const) {
        const { screen, route } = await setup(realm);
        try {
            const lines = navLinks(screen).filter((n) => !String(n.props.href).includes('subject=')).map((n) => n.textContent);
            for (const name of lines) {
                await pick(screen, name);
                assert.equal(screen.all((n) => n.tag === 'h2')[0].textContent, name);
                assert.ok(route.query.tab);
            }
        } finally { screen.unmount(); }
    }
});
test('OFF false and absent have identical legacy tool requests and both alphabets remain available', async () => {
    for (const enabled of [false, undefined]) {
        const { screen, calls } = await setup('teacher', { flag: false, data: { class_subjects_enabled: enabled } });
        try {
            click(exactButton(screen, 'Letters')); await flush();
            assert.equal(screen.all((n) => n.props['aria-label'] === 'Alphabet').length, 1);
            assert.ok(calls.some((c) => c.url.endsWith('/letters?alphabet=arabic')));
            click(exactButton(screen, 'English')); await flush();
            assert.ok(calls.some((c) => c.url.endsWith('/letters?alphabet=english')));
            click(exactButton(screen, 'Hifdh')); await flush();
            assert.equal(calls.some((c) => c.url.includes('/subjects')), false);
        } finally { screen.unmount(); }
    }
});
test('office add pickers use school/guide names and explicit Nothing clears both bindings as JSON', async () => {
    const { screen, calls } = await setup('office');
    try {
        field(screen, 'school').value = 'Science'; field(screen, 'school').props.onChange({ target: field(screen, 'school') }); await flush(); assert.equal(field(screen, 'name').value, 'Science');
        field(screen, 'curriculum').value = 'English Language Arts'; field(screen, 'curriculum').props.onChange({ target: field(screen, 'curriculum') }); await flush(); assert.equal(field(screen, 'name').value, 'English Language Arts');
        submit(screen.all((n) => n.props['data-subject-form'] !== undefined)[0]); await flush();
        assert.equal(calls.find((c) => c.method === 'post').body.guide_subject, 'English Language Arts');
        type(field(screen, 'name'), 'Practice elective'); chooseOption(field(screen, 'guide'), null); chooseOption(field(screen, 'tool'), null);
        submit(screen.all((n) => n.props['data-subject-form'] !== undefined)[0]); await flush();
        assert.deepEqual(calls.filter((c) => c.method === 'post').at(-1).body, { name: 'Practice elective', guide_subject: null, tool: null });
    } finally { screen.unmount(); }
});
test('manager write failure keeps its draft and server words; second submit during a write sends once', async () => {
    const answer = deferred(); let requests = 0;
    const api = { get: async (url: string) => ok(url.endsWith('/subjects') ? subjects : [], { guide_subjects: [], tools: [] }),
        VueApp: { axios: { post: async () => { requests++; return answer.promise; } } } };
    const file = 'components/classes/ClassSubjectManager.vue';
    const manager = await mountSfc(file, { base: '/api/admin/masjids/1/groups/2' }, await realClassModules(file, {
        '@/core/services/ApiService': { default: api }, '@/core/services/TeacherApiService': { default: {} },
        'vue-router': { useRoute: () => ({ query: {} }), useRouter: () => ({}) },
    }));
    try {
        await flush();
        const disclosure = manager.all((n: Node) => n.tag === 'button' && /^Class subjects \(\d+\)$/.test(n.textContent))[0];
        if (disclosure) { click(disclosure); await flush(); }
        type(field(manager, 'name'), 'Practice elective');
        const form = manager.all((n) => n.props['data-subject-form'] !== undefined)[0]; submit(form); submit(form); await flush(); assert.equal(requests, 1);
        answer.reject(httpError(422, { message: 'That name would collide with saved work.' })); await flush();
        assert.equal(field(manager, 'name').value, 'Practice elective'); assert.match(manager.text(), /That name would collide with saved work\./);
    } finally { manager.unmount(); }
});

test('every manager mutation says the server refusal, including rename, reorder, hide, restore and grade merge', async () => {
    for (const action of ['rename', 'reorder', 'hide', 'restore', 'grades']) {
        const message = `Practice server refusal for ${action}.`;
        const { screen } = await setup('office', { subjects: [...subjects, { ...subjects[3], id: 100, name: 'Hidden elective', position: 4, hidden_at: '2026-10-08' }], refuse: () => ({ status: 'error', message }) });
        try {
            if (action === 'rename') { click(exactButton(screen, 'Rename Arabic')); await flush(); type(field(screen, 'name'), 'Practice rename'); submit(screen.all((n) => n.props['data-subject-form'] !== undefined)[0]); }
            if (action === 'reorder') click(exactButton(screen, 'Move Arabic up'));
            if (action === 'hide') { click(exactButton(screen, 'Remove Arabic')); await flush(); click(exactButton(screen, 'Hide subject')); }
            if (action === 'restore') click(exactButton(screen, 'Bring back Hidden elective'));
            if (action === 'grades') click(exactButton(screen, 'Add subjects for current grades'));
            await flush(); assert.ok(screen.text().includes(message));
            assert.deepEqual(navLinks(screen).filter((n) => String(n.props.href).includes('subject=')).map((n) => n.textContent), subjects.map((s) => s.name));
        } finally { screen.unmount(); }
    }
});
test('hiding and restoring Arabic preserves its existing tool and saved marks in the office child', async () => {
    const { screen, calls } = await setup('office');
    try {
        click(exactButton(screen, 'Remove Arabic')); await flush(); click(exactButton(screen, 'Hide subject')); await flush();
        assert.equal(navLinks(screen).some((n) => n.textContent === 'Arabic'), false);
        click(exactButton(screen, 'Bring back Arabic')); await flush(); await pick(screen, 'Arabic');
        click(screen.button('Practice student')); await flush(); assert.match(screen.text(), /1 of 2 mastered/);
        assert.ok(screen.all((n) => String(n.props.class).includes('letter-tile--mastered')).length);
        assert.ok(calls.some((c) => c.url?.endsWith('/members/9/letters?alphabet=arabic')));
    } finally { screen.unmount(); }
});


// Follow-up regressions: deferred real tool reads/writes, not mocked panels.
const secondStudent = { ...student, membership_id: 10, contact: { id: 4, first_name: 'Second practice student', last_name: '' } };
const arabicDrillTracker = () => ({ ...trackerFor('arabic'), letters: [{ id: 'alif', glyph: 'ا', transliteration: 'Alif', status: 'learning', drills: [{ id: 'alif.single', label: 'Arabic drill', text: 'ا', status: 'learning' }] }] });
const totalsFor = (start: string, end: string) => ok({ points_period: 'weekly', week: { start, end }, students: [], class: {} });

test('follow-up 1: late Arabic daily notes never enter another student’s editor', async () => {
    const late = deferred();
    const { screen } = await setup('teacher', { data: { students: [student, secondStudent] }, read: (url: string) => {
        if (url.endsWith('/members/9/arabic-notes')) return late.promise;
        if (url.endsWith('/letters?alphabet=arabic') && !url.includes('/members/')) return ok({ students: [student, secondStudent], total: 28 });
        if (url.endsWith('/members/10/arabic-notes')) return ok([{ id: 2, session_date: '2026-10-08', note: 'Second student’s own note' }]);
        return undefined;
    } });
    try {
        await pick(screen, 'Arabic'); click(screen.button('Practice student')); await flush();
        await pick(screen, 'ELA'); await pick(screen, 'Arabic'); click(screen.button('Second practice student')); await flush();
        late.resolve(ok([{ id: 1, session_date: '2026-10-08', note: 'Late first student note' }])); await flush();
        assert.doesNotMatch(screen.text(), /Late first student note/);
        assert.match(screen.text(), /Second student’s own note/);
    } finally { screen.unmount(); }
});
test('follow-up 2: a delayed Arabic mark cannot replace ELA’s English tracker or error', async () => {
    for (const refused of [false, true]) {
        const late = deferred();
        const { screen } = await setup('teacher', {
            read: (url: string) => url.endsWith('/members/9/letters?alphabet=arabic') ? ok(arabicDrillTracker()) : undefined,
            write: (_: string, url: string) => url.endsWith('/members/9/letters') ? late.promise : undefined,
        });
        try {
            await pick(screen, 'Arabic'); click(screen.button('Practice student')); await flush();
            click(screen.all((n) => String(n.props.class).includes('letter-tile'))[0]); await flush();
            click(screen.all((n) => n.tag === 'button' && n.textContent.includes('Arabic drill') && !n.textContent.startsWith('Note on'))[0]); await flush();
            await pick(screen, 'ELA'); click(screen.button('Practice student')); await flush();
            if (refused) late.reject(httpError(422, { message: 'Late Arabic refusal' })); else late.resolve(ok(arabicDrillTracker()));
            await flush(); assert.match(screen.text(), /Capitals/); assert.doesNotMatch(screen.text(), /Alif|Late Arabic refusal/);
        } finally { screen.unmount(); }
    }
});
test('follow-up 3: overlapping Points reads keep the week selected by the address', async () => {
    const late = deferred();
    const { screen, router, route } = await setup('teacher', { query: { tab: 'points', week: '2026-09-20' }, read: (url: string) => {
        if (url.endsWith('/awards/totals?week=2026-09-20')) return late.promise;
        if (url.endsWith('/awards/totals?week=2026-09-27')) return totalsFor('2026-09-27', '2026-10-03');
        return undefined;
    } });
    try {
        await router.push({ query: { tab: 'points', week: '2026-09-27' } }); await flush();
        late.resolve(totalsFor('2026-09-20', '2026-09-26')); await flush();
        assert.equal(route.query.week, '2026-09-27'); assert.match(screen.text(), /Sep 27 - Oct 3, 2026/); assert.doesNotMatch(screen.text(), /Sep 20 - Sep 26/);
    } finally { screen.unmount(); }
});
test('follow-up 4: English overview loading clears Arabic progress immediately', async () => {
    const late = deferred();
    const { screen } = await setup('teacher', { read: (url: string) => url.endsWith('/letters?alphabet=english') && !url.includes('/members/') ? late.promise : undefined });
    try {
        await pick(screen, 'Arabic'); assert.match(screen.text(), /4 \/ 26/);
        await pick(screen, 'ELA'); assert.equal(screen.all((n) => n.tag === 'h2')[0].textContent, 'ELA'); assert.doesNotMatch(screen.text(), /4 \/ 26/);
        late.resolve(ok({ students: [], stage: null, stages: [] })); await flush();
    } finally { screen.unmount(); }
});
test('follow-up 5: [] means no assigned subjects; null means all and the heading stays visible', async () => {
    for (const mine of [[], null]) {
        const { screen } = await setup('teacher', { mine });
        try {
            assert.equal(navLinks(screen).filter((n) => String(n.props.href).includes('subject=')).length, mine === null ? 4 : 0);
            assert.ok(screen.all((n) => n.props['data-class-section'] !== undefined && n.textContent === 'Subjects').length);
            if (mine !== null) assert.match(screen.text(), /No subjects assigned\./);
        } finally { screen.unmount(); }
    }
});
test('follow-up 7: office manager follows the members, starts closed and uses short named controls', async () => {
    const { screen } = await setup('office', { closed: true });
    try {
        const disclosure = exactButton(screen, 'Class subjects (4)');
        assert.equal(disclosure.props['aria-expanded'], 'false'); assert.equal(screen.all((n) => n.props['data-subject-form'] !== undefined).length, 0);
        assert.ok(screen.text().indexOf('Practice student') < screen.text().indexOf('Class subjects (4)'));
        click(disclosure); await flush(); assert.equal(disclosure.props['aria-expanded'], 'true');
        assert.equal(exactButton(screen, 'Rename Arabic').textContent, 'Rename');
        assert.equal(exactButton(screen, 'Move Arabic up').textContent, 'Up');
        assert.equal(exactButton(screen, 'Move Arabic down').textContent, 'Down');
        assert.equal(exactButton(screen, 'Remove Arabic').textContent, 'Remove');
    } finally { screen.unmount(); }
});
test('follow-up 8: reorder swaps visible neighbours, retains hidden slots and disables visible ends', async () => {
    const ordered = [ { ...subjects[0], position: 0 }, { ...subjects[3], id: 100, name: 'Hidden elective', position: 1, hidden_at: '2026-10-08' }, ...subjects.slice(1).map((s, i) => ({ ...s, position: i + 2 })) ];
    const { screen, calls } = await setup('office', { subjects: ordered });
    try {
        assert.equal(exactButton(screen, "Move Qur'an up").props.disabled, true);
        assert.equal(exactButton(screen, 'Move Healthful Living down').props.disabled, true);
        click(exactButton(screen, 'Move Arabic up')); await flush();
        assert.deepEqual(calls.find((c) => c.url?.endsWith('/reorder')).body.subject_ids, [102, 100, 101, 103, 104]);
        assert.deepEqual(navLinks(screen).filter((n) => String(n.props.href).includes('subject=')).map((n) => n.textContent), ['Arabic', "Qur'an", 'ELA', 'Healthful Living']);
        assert.equal(exactButton(screen, 'Move Arabic up').props.disabled, true);
    } finally { screen.unmount(); }
});
test('follow-up 6: selection scrolls inside the menu without scrolling the page', async () => {
    const { screen } = await setup('teacher');
    try {
        const menu = screen.all((n) => n.tag === 'aside' && String(n.props.class).includes('class-menu'))[0] as any;
        menu.getBoundingClientRect = () => ({ top: 80, bottom: 300 }); menu.scrollTop = 0;
        for (const link of navLinks(screen)) (link as any).getBoundingClientRect = () => ({ top: link.textContent === 'Files' ? 500 : 90, bottom: link.textContent === 'Files' ? 532 : 122 });
        await pick(screen, 'Files'); assert.equal(menu.scrollTop, 232); assert.equal(doc.activeElement.tag, 'h2');
    } finally { screen.unmount(); }
});
test('follow-up 6, 9, 10: bounded sticky compact menu, quiet heading and ON-only wide containers', () => {
    const navigation = readFileSync('resources/vue-app/components/classes/ClassNavigation.vue', 'utf8');
    assert.match(navigation, /position: sticky/); assert.match(navigation, /max-height: calc\(100dvh/); assert.match(navigation, /overflow-y: auto/);
    assert.match(navigation, /\.class-workspace-content > h2:focus[^}]*outline: none/);
    assert.match(navigation, /\.class-menu-line:focus-visible/);
    const layout = readFileSync('resources/vue-app/layouts/TeacherLayout.vue', 'utf8');
    assert.match(layout, /@media \(min-width: 1280px\)/); assert.match(layout, /:has\(\.class-workspace\)/);
    const office = readFileSync('resources/vue-app/views/dashboard/GroupDetailView.vue', 'utf8');
    assert.match(office, /class-subjects-enabled/); assert.match(office, /max-width: none/);
});

test('guard coverage: office student detail ignores an older tracker after returning to the roster', async () => {
    const late = deferred();
    const { screen } = await setup('office', { read: (url: string) => {
        if (url.endsWith('/letters?alphabet=arabic') && !url.includes('/members/')) return ok({ students: [student, secondStudent], total: 28 });
        if (url.endsWith('/members/9/letters?alphabet=arabic')) return late.promise;
        return undefined;
    } });
    try {
        await pick(screen, 'Arabic'); click(screen.button('Practice student')); await flush();
        // Return to the roster while A's read is pending, then choose B.
        click(screen.button('All students')); await flush();
        click(screen.button('Second practice student')); await flush();
        late.resolve(ok({ ...trackerFor('arabic'), totals: { mastered: 28, total: 28 } })); await flush();
        assert.match(screen.text(), /1 of 2 mastered/); assert.doesNotMatch(screen.text(), /28 of 28 mastered/);
    } finally { screen.unmount(); }
});
test('guard coverage: leaving and reopening Hifdh starts a new log load and drops the old answer', async () => {
    const late = deferred(); let reads = 0;
    const { screen } = await setup('teacher', { read: (url: string) => {
        if (url.endsWith('/members/9/hifz')) return ++reads === 1 ? late.promise : ok([{ ...row, note: 'Current recitation note' }]);
        return undefined;
    } });
    try {
        await pick(screen, "Qur'an");
        const select = screen.all((n) => n.tag === 'select' && n.children.some((c) => c.textContent.includes('Choose a student')))[0];
        chooseOption(select, 9); await flush(); await pick(screen, 'Healthful Living'); await pick(screen, "Qur'an");
        late.resolve(ok([{ ...row, note: 'Late recitation note' }])); await flush();
        assert.ok(reads >= 2); assert.match(screen.text(), /Current recitation note/); assert.doesNotMatch(screen.text(), /Late recitation note/);
    } finally { screen.unmount(); }
});

test('Points week buttons update the ON address and Back restores the previous week', async () => {
    const { screen, route, router } = await setup('teacher', { query: { tab: 'points', week: '2026-09-27' }, read: (url: string) => {
        if (!url.includes('/awards/totals')) return undefined;
        const start = url.includes('2026-09-20') ? '2026-09-20' : '2026-09-27';
        return ok({ points_period: 'weekly', week: { start, end: start === '2026-09-20' ? '2026-09-26' : '2026-10-03', previous: '2026-09-20', next: '2026-09-27', is_current: false }, students: [], class: {} });
    } });
    try {
        click(exactButton(screen, 'Previous week')); await flush(); assert.equal(route.query.week, '2026-09-20'); assert.match(screen.text(), /Sep 20 - Sep 26/);
        router.back(); await flush(); assert.equal(route.query.week, '2026-09-27'); assert.match(screen.text(), /Sep 27 - Oct 3/);
    } finally { screen.unmount(); }
});


test('office Hifdh discards a late record for A and leaves B’s form usable', async () => {
    const late = deferred();
    const { screen } = await setup('office', { hifzStore: { recordEntry: () => late.promise }, data: { memberships: [membership, { ...membership, id: 10, ...secondStudent }] } });
    try {
        await pick(screen, "Qur'an"); click(exactButton(screen, 'Record recitation')); await flush();
        const picker = screen.all((n) => n.tag === 'select' && n.children.some((c) => c.textContent.includes('Choose a student')))[0];
        chooseOption(picker, 9); await flush(); submit(screen.all((n) => n.tag === 'form')[0]); await flush();
        chooseOption(picker, 10); await flush(); late.resolve(row); await flush();
        assert.equal(Boolean(exactButton(screen, 'Record').props.disabled), false); assert.equal(picker.options.find((option) => option.selected)?.value, 10);
        assert.match(screen.text(), /Record recitation/);
    } finally { screen.unmount(); }
});
test('office Letters discards A’s mark and releases the mark button on B’s tracker', async () => {
    const late = deferred();
    const { screen } = await setup('office', { read: (url: string) => {
        if (url.endsWith('/letters?alphabet=arabic') && !url.includes('/members/')) return ok({ students: [student, secondStudent], total: 28 });
        if (url.includes('/members/') && url.endsWith('/letters?alphabet=arabic')) return ok(arabicDrillTracker());
        return undefined;
    }, write: (_: string, url: string) => url.endsWith('/members/9/letters') ? late.promise : undefined });
    try {
        await pick(screen, 'Arabic'); click(screen.button('Practice student')); await flush();
        click(screen.all((n) => String(n.props.class).includes('letter-tile'))[0]); await flush(); click(screen.button('Arabic drill')); await flush();
        click(screen.button('All students')); await flush(); click(screen.button('Second practice student')); await flush();
        click(screen.all((n) => String(n.props.class).includes('letter-tile'))[0]); await flush();
        late.resolve(ok({ ...arabicDrillTracker(), totals: { mastered: 28, total: 28 } })); await flush();
        assert.doesNotMatch(screen.text(), /28 of 28 mastered/); assert.equal(Boolean(screen.button('Arabic drill').props.disabled), false);
    } finally { screen.unmount(); }
});

const studentPicker = (screen: any) => screen.all((n: Node) => n.tag === 'select' && n.children.some(c => c.textContent.includes('Choose a student')))[0];
const visit = async (screen: any, on: boolean, label: string) => {
    if (on) await pick(screen, label); else { click(exactButton(screen, label)); await flush(10); }
};
for (const on of [true, false]) {
    test(`save redesign 1 ${on ? 'ON' : 'OFF'}: paging awards retains pending school skills`, async () => {
        const late = deferred();
        const { screen } = await setup('office', { flag: on, read: (url: string) => {
            if (url.includes('/behavior-skills')) return late.promise;
            if (url.includes('/awards?page=')) return ok({ data: [{ id: 1, skill_label: 'Existing award', points: 1 }], current_page: Number(url.split('=').at(-1)), per_page: 25, total: 26, last_page: 2 });
            return undefined;
        } });
        try {
            await visit(screen, on, 'Points'); click(exactButton(screen, 'Next page')); await flush();
            late.resolve(ok({ data: [{ id: 8, label: 'Pending vocabulary', is_active: true, polarity: 'positive', default_points: 1 }] })); await flush();
            click(exactButton(screen, 'Give points')); await flush();
            assert.match(screen.text(), /Pending vocabulary/);
        } finally { screen.unmount(); }
    });
    test(`save redesign 2 ${on ? 'ON' : 'OFF'}: Points re-entry settles selected history`, async () => {
        const late = deferred(); let reads = 0;
        const { screen } = await setup('teacher', { flag: on, read: (url: string) => {
            if (url.endsWith('/members/9/awards')) return ++reads === 1 ? late.promise : ok([{ id: 20, skill_label: 'Fresh history', points: 1 }]);
            return undefined;
        } });
        try {
            await visit(screen, on, 'Points'); chooseOption(studentPicker(screen), 9); await flush();
            await visit(screen, on, 'Roster'); await visit(screen, on, 'Points');
            late.resolve(ok([{ id: 20, skill_label: on ? 'Dropped history' : 'Fresh history', points: 1 }])); await flush();
            assert.match(screen.text(), /Fresh history/); assert.equal(reads, on ? 2 : 1);
        } finally { screen.unmount(); }
    });
    for (const action of ['period', 'skill']) test(`save redesign 3 ${on ? 'ON' : 'OFF'}: ${action} save survives student change`, async () => {
        const late = deferred(); let totals = 0; let storedPeriod = 'running';
        const { screen, data } = await setup('teacher', { flag: on, data: { students: [student, secondStudent], points_period: 'running' },
            read: (url: string) => { if (url.includes('/awards/totals')) { totals++; return ok({ points_period: storedPeriod, students: [], class: {} }); } return undefined; },
            write: (_: string, url: string) => url.endsWith(action === 'period' ? '/points-period' : '/behavior-skills') ? late.promise : undefined });
        try {
            await visit(screen, on, 'Points'); chooseOption(studentPicker(screen), 9); await flush();
            const before = totals;
            if (action === 'period') {
                const input: any = screen.all((n: Node) => n.props.id === 'points-weekly')[0]; input.checked = true; input.props.onChange({ target: input });
            } else {
                type(screen.all((n: Node) => n.tag === 'input' && n.props.placeholder === 'e.g. Helped without being asked')[0], 'Created school skill'); await flush();
                click(exactButton(screen, 'Add'));
            }
            await flush(); chooseOption(studentPicker(screen), 10); await flush();
            storedPeriod = 'weekly'; late.resolve(ok(action === 'period' ? { points_period: 'weekly' } : { id: 30, label: 'Created school skill', polarity: 'positive', default_points: 1 })); await flush();
            if (action === 'period') { assert.equal(data.points_period, 'weekly'); assert.ok(totals > before); }
            else { assert.match(screen.text(), /Created school skill \(\+1\)/); assert.equal(screen.all((n: Node) => n.tag === 'input' && n.props.placeholder === 'e.g. Helped without being asked')[0].value, ''); }
        } finally { screen.unmount(); }
    });
    for (const tool of ['Hifdh', 'Points']) test(`save redesign 4 ${on ? 'ON' : 'OFF'}: office ${tool} refreshes A after switching editor to B`, async () => {
        const late = deferred(); let lists = 0; let summaries = 0;
        const { screen } = await setup('office', { flag: on, data: { memberships: [membership, { ...membership, id: 10, ...secondStudent }] },
            hifzStore: { recordEntry: () => late.promise, fetchEntries: async () => { lists++; }, fetchProgress: async () => { summaries++; } },
            read: (url: string) => {
                if (url.includes('/awards?page=')) { lists++; return ok({ data: [], current_page: 1, total: 0, per_page: 25 }); }
                if (url.includes('/awards/summary')) summaries++;
                if (url.includes('/behavior-skills')) return ok({ data: [{ id: 8, label: 'Practice skill', is_active: true, polarity: 'positive', default_points: 1 }] });
                return undefined;
            }, write: (_: string, url: string) => url.endsWith('/awards') ? late.promise : undefined });
        try {
            await visit(screen, on, tool === 'Hifdh' && on ? "Qur'an" : tool);
            click(exactButton(screen, tool === 'Hifdh' ? 'Record recitation' : 'Give points')); await flush();
            const picker = studentPicker(screen); chooseOption(picker, 9); await flush();
            if (tool === 'Points') { const skills = screen.all((n: Node) => n.tag === 'select' && n.children.some(c => c.textContent.includes('Practice skill')))[0]; chooseOption(skills, 8); await flush(); }
            const before = [lists, summaries]; submit(screen.all((n: Node) => n.tag === 'form')[0]); await flush();
            chooseOption(picker, 10); await flush(); late.resolve(ok(row)); await flush(10);
            assert.ok(lists > before[0], 'class log refreshed'); assert.ok(summaries > before[1], 'positions/totals refreshed');
            if (on) assert.equal(studentPicker(screen).options.find((o: any) => o.selected)?.value, 10);
            else assert.equal(screen.all((n: Node) => n.tag === 'form').length, 0, 'legacy success closes modal');
        } finally { screen.unmount(); }
    });
}

test('save redesign menu: resting clearance at 900/768 and own scroll reveal Files', async () => {
    const { screen } = await setup('teacher');
    try {
        const menu: any = screen.all((n: Node) => n.tag === 'aside')[0];
        const workspace: any = screen.all((n: Node) => String(n.props.class).includes('class-workspace') && !String(n.props.class).includes('content'))[0];
        workspace.getBoundingClientRect = () => ({ top: 196, bottom: 954 });
        for (const height of [900, 768]) {
            (globalThis as any).window.innerHeight = height;
            // A viewport resize runs the component's registered handler.
            for (const fn of resizeHandlers) fn(); await flush();
            assert.equal(menu.props.style?.['--class-menu-rest-top'], '196px');
        }
    } finally { screen.unmount(); }
});

for (const on of [true, false]) test(`save sweep ${on ? 'ON' : 'OFF'}: pending award history is independent of the Points week`, async () => {
    const late = deferred(); let reads = 0;
    const { screen, router } = await setup('teacher', { flag: on, read: (url: string) => {
        if (url.endsWith('/members/9/awards')) { reads++; return late.promise; }
        return undefined;
    } });
    try {
        await visit(screen, on, 'Points'); chooseOption(studentPicker(screen), 9); await flush();
        await router.push({ query: { tab: 'points', week: '2026-09-20' } }); await flush();
        late.resolve(ok([{ id: 1, skill_label: 'History across weeks', points: 2 }])); await flush();
        assert.match(screen.text(), /History across weeks/); assert.equal(reads, 1);
    } finally { screen.unmount(); }
});

for (const on of [true, false]) test(`save sweep ${on ? 'ON' : 'OFF'}: school vocabulary can arrive while another tab is open`, async () => {
    const late = deferred(); let reads = 0;
    const { screen } = await setup('teacher', { flag: on, read: (url: string) => {
        if (url.endsWith('/behavior-skills')) { reads++; return late.promise; }
        return undefined;
    } });
    try {
        await visit(screen, on, 'Points'); await visit(screen, on, 'Roster');
        late.resolve(ok([{ id: 8, label: 'School vocabulary', polarity: 'positive', default_points: 1 }])); await flush();
        await visit(screen, on, 'Points'); chooseOption(studentPicker(screen), 9); await flush();
        assert.match(screen.text(), /School vocabulary/); assert.equal(reads, 1);
    } finally { screen.unmount(); }
});

for (const gone of [false, true]) test(`save sweep: teacher save refusal is surfaced after ${gone ? 'unmount' : 'leaving its tool'}`, async () => {
    const late = deferred();
    const { screen, alerts } = await setup('teacher', { read: (url: string) => url.endsWith('/members/9/letters?alphabet=arabic') ? ok(arabicDrillTracker()) : undefined,
        write: (_: string, url: string) => url.endsWith('/members/9/letters') ? late.promise : undefined });
    try {
        await pick(screen, 'Arabic'); click(screen.button('Practice student')); await flush();
        click(screen.all((n: Node) => String(n.props.class).includes('letter-tile'))[0]); await flush(); click(screen.all((n: Node) => n.tag === 'button' && n.textContent.includes('Arabic drill') && !n.textContent.startsWith('Note on'))[0]); await flush();
        if (gone) screen.unmount(); else await pick(screen, 'ELA');
        late.reject(httpError(422, { message: 'The saved mark was refused.' })); await flush();
        assert.ok(alerts.some((a: any) => a.text === 'The saved mark was refused.' && a.icon === 'error'));
        if (!gone) assert.doesNotMatch(screen.text(), /The saved mark was refused/);
    } finally { if (!gone) screen.unmount(); }
});

for (const on of [true, false]) test(`save sweep ${on ? 'ON' : 'OFF'}: office Hifdh legacy continuation after unmount applies progress`, async () => {
    const late = deferred(); const calls: any[] = [];
    const { screen } = await setup('office', { flag: on, hifzStore: {
        fetchEntries: () => late.promise,
        fetchProgress: async (_group: number, student: number, keep: () => boolean) => { calls.push({ student, accepted: keep() }); },
    } });
    await visit(screen, on, on ? "Qur'an" : 'Hifdh'); screen.unmount();
    const before = calls.length; late.resolve(undefined); await flush();
    assert.equal(calls.length, on ? before : before + 1);
    if (!on) assert.equal(calls.at(-1).accepted, true);
});

for (const on of [true, false]) test(`save sweep ${on ? 'ON' : 'OFF'}: an older save cannot release a newer editor's busy flag`, async () => {
    const first = deferred(); const second = deferred(); let writes = 0;
    const { screen } = await setup('office', { flag: on, data: { memberships: [membership, { ...membership, id: 10, ...secondStudent }] },
        hifzStore: { recordEntry: () => ++writes === 1 ? first.promise : second.promise } });
    try {
        await visit(screen, on, on ? "Qur'an" : 'Hifdh'); click(exactButton(screen, 'Record recitation')); await flush();
        const picker = studentPicker(screen); chooseOption(picker, 9); await flush(); submit(screen.all((n: Node) => n.tag === 'form')[0]); await flush();
        chooseOption(picker, 10); await flush();
        if (on) {
            submit(screen.all((n: Node) => n.tag === 'form')[0]); await flush(); assert.equal(writes, 2);
            first.resolve(ok(row)); await flush(); assert.equal(exactButton(screen, 'Record').disabled, true);
            second.resolve(ok({ ...row, membership_id: 10 })); await flush(); assert.equal(screen.all((n: Node) => n.tag === 'form').length, 0);
        } else {
            assert.equal(exactButton(screen, 'Record').disabled, true, 'OFF keeps its original busy flag across student switches');
            first.resolve(ok(row)); await flush(); assert.equal(writes, 1);
        }
    } finally { screen.unmount(); }
});

test('save sweep: office Hifdh remount before success reconciles the replacement class log', async () => {
    const late = deferred(); let saved = false; let reads = 0;
    const helper = await loadTs('composables/useToolResponseGuard.ts', { vue });
    const { screen, hifzStore } = await setup('office', { modules: { '@/composables/useToolResponseGuard': helper, './useToolResponseGuard': helper }, hifzStore: {
        recordEntry: () => late.promise,
        fetchEntries: async () => { reads++; hifzStore.entriesPaginated.data = [{ ...row, quality: saved ? 'excellent' : 'good', note: saved ? 'Reconciled saved recitation' : 'Before save' }]; },
    } });
    try {
        await pick(screen, "Qur'an"); click(exactButton(screen, 'Record recitation')); await flush();
        chooseOption(studentPicker(screen), 9); await flush(); submit(screen.all((n: Node) => n.tag === 'form')[0]); await flush();
        await pick(screen, 'Healthful Living'); await pick(screen, "Qur'an"); const before = reads;
        saved = true; late.resolve(ok(row)); await flush();
        assert.ok(reads > before); assert.equal(hifzStore.entriesPaginated.data[0].note, 'Reconciled saved recitation'); assert.match(screen.text(), /Excellent/);
    } finally { screen.unmount(); }
});

test('save sweep: a school skill save reconciles a replacement teacher picker', async () => {
    const late = deferred(); let saved = false;
    const helper = await loadTs('composables/useToolResponseGuard.ts', { vue });
    const options = { query: { tab: 'points' }, modules: { '@/composables/useToolResponseGuard': helper, './useToolResponseGuard': helper },
        read: (url: string) => url.endsWith('/behavior-skills') ? ok(saved ? [{ id: 80, label: 'Saved after remount', polarity: 'positive', default_points: 1 }] : []) : undefined,
        write: (_: string, url: string) => url.endsWith('/behavior-skills') ? late.promise : undefined };
    const first = await setup('teacher', options);
    chooseOption(studentPicker(first.screen), 9); await flush();
    type(first.screen.all((n: Node) => n.props.placeholder === 'e.g. Helped without being asked')[0], 'Saved after remount'); await flush();
    click(exactButton(first.screen, 'Add')); await flush(); first.screen.unmount();
    const next = await setup('teacher', options);
    try {
        chooseOption(studentPicker(next.screen), 9); await flush();
        saved = true; late.resolve(ok({ id: 80, label: 'Saved after remount', polarity: 'positive', default_points: 1 })); await flush();
        assert.match(next.screen.text(), /Saved after remount \(\+1\)/);
    } finally { next.screen.unmount(); }
});

for (const failure of ['save', 'refresh']) test(`review3 1 OFF: office skill ${failure} failure keeps main's Swal words`, async () => {
    let created = false;
    const { screen, alerts } = await setup('office', { flag: false,
        read: (url: string) => url.includes('/behavior-skills') && created ? Promise.reject(httpError(422, { message: 'Vocabulary refresh refused.' })) : undefined,
        write: (_: string, url: string) => {
            if (!url.endsWith('/behavior-skills')) return undefined;
            if (failure === 'save') throw httpError(422, { message: 'Skill creation refused.' });
            created = true; return ok({ id: 80 });
        } });
    try {
        await visit(screen, false, 'Points'); click(exactButton(screen, 'Manage skills')); await flush();
        type(screen.all((n: Node) => n.props.placeholder === 'Participation')[0], 'New vocabulary'); await flush();
        submit(screen.all((n: Node) => n.tag === 'form')[0]); await flush();
        assert.ok(alerts.some(a => a.icon === 'error' && a.title === 'Error!' && a.text === (failure === 'save' ? 'Skill creation refused.' : 'Vocabulary refresh refused.')));
        type(screen.all((n: Node) => n.props.placeholder === 'Participation')[0], 'Retry'); await flush();
        assert.equal(exactButton(screen, 'Add').disabled, false);
    } finally { screen.unmount(); }
});

const twoDrills = (mastered = 0) => ({ ...arabicDrillTracker(), totals: { mastered, total: 2 },
    letters: [{ ...arabicDrillTracker().letters[0], drills: [1, 2].map(i => ({ id: `alif.${i}`, label: `Drill ${i}`, text: 'ا', status: i <= mastered ? 'mastered' : 'learning' })) }] });
const drillButton = (screen: any, label: string) => screen.all((n: Node) => n.tag === 'button' && n.textContent.includes(label) && !n.textContent.startsWith('Note on'))[0];
for (const realm of ['teacher', 'office'] as const) for (const on of [true, false]) {
    test(`review3 2 ${on ? 'ON' : 'OFF'}: ${realm} older drill snapshot cannot undo the newest sent save`, async () => {
        const first = deferred(); const second = deferred(); let writes = 0; let overviews = 0; let stored = twoDrills(); let reads = 0;
        const { screen } = await setup(realm, { flag: on,
            read: (url: string) => {
                if (url.includes('/members/9/letters')) { reads++; return ok(structuredClone(stored)); }
                if (url.includes('/letters?')) overviews++;
                return undefined;
            }, write: (_: string, url: string) => {
                if (!url.endsWith('/members/9/letters')) return undefined;
                // These writes execute in send order; their frozen answers arrive in reverse.
                stored = twoDrills(++writes);
                return writes === 1 ? first.promise : second.promise;
            } });
        try {
            await visit(screen, on, on ? 'Arabic' : 'Letters'); click(screen.button('Practice student')); await flush();
            click(screen.all((n: Node) => String(n.props.class).includes('letter-tile'))[0]); await flush();
            click(drillButton(screen, 'Drill 1')); await flush(); click(drillButton(screen, 'Drill 2')); await flush();
            assert.equal(writes, 2); const before = overviews; const beforeReads = reads;
            second.resolve(ok(twoDrills(2))); await flush(); assert.match(screen.text(), /2 of 2 mastered/);
            first.resolve(ok(twoDrills(1))); await flush();
            assert.match(screen.text(), on ? /2 of 2 mastered/ : /1 of 2 mastered/);
            assert.equal(reads - beforeReads, on ? 1 : 0);
            if (on) assert.match(screen.text(), new RegExp(`${stored.totals.mastered} of ${stored.totals.total} mastered`));
            if (on) assert.ok(overviews >= before + 2, 'both successes still reconcile the class overview');
        } finally { screen.unmount(); }
    });
}

for (const on of [true, false]) for (const change of ['student', 'skill', 'skill-return', 'none']) {
    test(`review3 3 ${on ? 'ON' : 'OFF'}: late teacher skill creation after ${change} preserves the award selection`, async () => {
        const late = deferred();
        const existing = [8, 9].map(id => ({ id, label: `Existing skill ${id}`, polarity: 'positive', default_points: 1 }));
        const { screen, calls } = await setup('teacher', { flag: on, data: { students: [student, secondStudent] },
            read: (url: string) => url.endsWith('/behavior-skills') ? ok(existing) : undefined,
            write: (_: string, url: string) => url.endsWith('/behavior-skills') ? late.promise : undefined });
        const skillPicker = () => screen.all((n: Node) => n.tag === 'select' && n.children.some(c => c.textContent.includes('Existing skill')))[0];
        try {
            await visit(screen, on, 'Points'); chooseOption(studentPicker(screen), 9); await flush(); chooseOption(skillPicker(), 8); await flush();
            type(screen.all((n: Node) => n.props.placeholder === 'e.g. Helped without being asked')[0], 'Created skill'); await flush(); click(exactButton(screen, 'Add')); await flush();
            if (change === 'student') { await visit(screen, on, 'Roster'); await visit(screen, on, 'Points'); chooseOption(studentPicker(screen), 10); await flush(); }
            if (change !== 'none') { chooseOption(skillPicker(), 9); await flush(); }
            if (change === 'skill-return') { chooseOption(skillPicker(), 8); await flush(); }
            late.resolve(ok({ id: 80, label: 'Created skill', polarity: 'positive', default_points: 1 })); await flush();
            assert.match(screen.text(), /Created skill \(\+1\)/);
            click(exactButton(screen, 'Give')); await flush();
            const award = calls.find(c => c.method === 'post' && c.url.endsWith('/awards'));
            assert.equal(award.body.behavior_skill_id, on && change !== 'none' ? (change === 'skill-return' ? 8 : 9) : 80);
        } finally { screen.unmount(); }
    });
}

for (const action of ['masterAll', 'masterGroup', 'saveDrillNote']) for (const on of [true, false]) {
    test(`review3 2 sibling ${on ? 'ON' : 'OFF'}: ${action} shares the tracker sequence with a newer drill mark`, async () => {
        const first = deferred(); const second = deferred(); let writes = 0;
        const payload = (mastered = 0) => ({ ...twoDrills(mastered), groups: [{ id: 'practice', label: 'Practice', totals: { mastered: 0, total: 2 }, drills: [] }] });
        let stored = payload(); let reads = 0;
        const { screen } = await setup('teacher', { flag: on,
            read: (url: string) => { if (url.includes('/members/9/letters')) { reads++; return ok(structuredClone(stored)); } return undefined; },
            write: (_: string, url: string) => {
                if (!url.includes('/members/9/letters')) return undefined;
                stored = payload(++writes);
                return writes === 1 ? first.promise : second.promise;
            } });
        try {
            await visit(screen, on, on ? 'Arabic' : 'Letters'); click(screen.button('Practice student')); await flush();
            click(screen.all((n: Node) => String(n.props.class).includes('letter-tile'))[0]); await flush();
            if (action === 'masterAll') { click(exactButton(screen, 'Mark all mastered')); await flush(); click(screen.button('Just this stage')); }
            else if (action === 'masterGroup') { click(exactButton(screen, 'Mark all practice mastered')); await flush(); click(exactButton(screen, 'Yes, mark them mastered')); }
            else { click(exactButton(screen, 'Note on Drill 1')); await flush(); type(screen.all((n: Node) => n.tag === 'textarea')[0], 'Saved drill note'); await flush(); click(exactButton(screen, 'Save note')); }
            await flush(); click(drillButton(screen, 'Drill 2')); await flush(); assert.equal(writes, 2);
            const beforeReads = reads;
            second.resolve(ok(payload(2))); await flush(); first.resolve(ok(payload(1))); await flush();
            assert.match(screen.text(), on ? /2 of 2 mastered/ : /1 of 2 mastered/);
            assert.equal(reads - beforeReads, on ? 1 : 0);
            if (on) assert.match(screen.text(), new RegExp(`${stored.totals.mastered} of ${stored.totals.total} mastered`));
        } finally { screen.unmount(); }
    });
}

for (const realm of ['teacher', 'office'] as const) for (const on of [true, false]) test(`review3 2 stage ${on ? 'ON' : 'OFF'}: ${realm} applies only the newest sent stage snapshot`, async () => {
    const first = deferred(); const second = deferred(); let writes = 0;
    const stages = ['initial', 'one', 'two'].map(id => ({ id, label: `Stage ${id}` }));
    const overview = (id: string) => ({ students: [student], stage: stages.find(s => s.id === id), stages, total: 2 });
    const { screen, data } = await setup(realm, { flag: on, data: { arabic_stage: 'initial' },
        read: (url: string) => url.includes('/letters?') ? ok(overview(writes ? 'two' : 'initial')) : undefined,
        write: (_: string, url: string) => url.endsWith('/letters/stage') ? (++writes === 1 ? first.promise : second.promise) : undefined });
    try {
        await visit(screen, on, on ? 'Arabic' : 'Letters');
        const picker = screen.all((n: Node) => n.tag === 'select' && n.children.some(c => c.textContent === 'Stage one'))[0];
        // Two changes before Vue disables the native control can send both writes.
        picker.value = 'one'; chooseOption(picker, 'one'); picker.value = 'two'; chooseOption(picker, 'two'); await flush(); assert.equal(writes, 2);
        second.resolve(ok(overview('two'))); await flush(); first.resolve(ok(overview('one'))); await flush();
        if (realm === 'teacher') assert.equal(data.arabic_stage, on ? 'two' : 'one');
        else assert.equal(picker.props.value, on ? 'two' : 'one');
    } finally { screen.unmount(); }
});

for (const realm of ['teacher', 'office'] as const) test(`review3 2 repair: ${realm} reads after the last pending save rejects the newest snapshot`, async () => {
    const first = deferred(); const second = deferred(); let writes = 0; let reads = 0; let stored = 0;
    const { screen } = await setup(realm, {
        read: (url: string) => { if (url.includes('/members/9/letters')) { reads++; return ok(twoDrills(stored)); } return undefined; },
        write: (_: string, url: string) => url.endsWith('/members/9/letters') ? (++writes === 1 ? first.promise : second.promise) : undefined });
    try {
        await pick(screen, 'Arabic'); click(screen.button('Practice student')); await flush(); click(screen.all((n: Node) => String(n.props.class).includes('letter-tile'))[0]); await flush();
        click(drillButton(screen, 'Drill 1')); await flush(); click(drillButton(screen, 'Drill 2')); await flush();
        const before = reads; stored = 1; first.resolve(ok(twoDrills(1))); await flush();
        assert.equal(reads, before); assert.match(screen.text(), /0 of 2 mastered/);
        second.reject(httpError(422, { message: 'Newest mark refused.' })); await flush();
        assert.ok(reads > before); assert.match(screen.text(), /1 of 2 mastered/); assert.match(screen.text(), /Newest mark refused\./);
    } finally { screen.unmount(); }
});

for (const on of [true, false]) test(`review3 3 office ${on ? 'ON' : 'OFF'}: creating vocabulary preserves an existing award choice`, async () => {
    const late = deferred(); let created = false;
    const skills = [8, 9].map(id => ({ id, label: `Existing skill ${id}`, polarity: 'positive', default_points: 1, is_active: true }));
    const { screen, calls } = await setup('office', { flag: on,
        read: (url: string) => url.includes('/behavior-skills') ? ok({ data: created ? [...skills, { ...skills[0], id: 80, label: 'Created skill' }] : skills }) : undefined,
        write: (_: string, url: string) => url.endsWith('/behavior-skills') ? late.promise : undefined });
    try {
        await visit(screen, on, 'Points'); click(exactButton(screen, 'Manage skills')); await flush();
        type(screen.all((n: Node) => n.props.placeholder === 'Participation')[0], 'Created skill'); await flush(); submit(screen.all((n: Node) => n.tag === 'form')[0]); await flush();
        click(exactButton(screen, 'Close')); await flush(); click(exactButton(screen, 'Give points')); await flush(); chooseOption(studentPicker(screen), 9); await flush();
        const picker = screen.all((n: Node) => n.tag === 'select' && n.children.some(c => c.textContent.includes('Existing skill')))[0]; chooseOption(picker, 9); await flush();
        created = true; late.resolve(ok({ id: 80 })); await flush(); assert.match(screen.text(), /Created skill/);
        submit(screen.all((n: Node) => n.tag === 'form')[0]); await flush();
        assert.equal(calls.find(c => c.method === 'post' && c.url.endsWith('/awards')).body.get('behavior_skill_id'), '9');
    } finally { screen.unmount(); }
});

for (const on of [true, false]) for (const action of ['edit', 'note']) for (const returned of [true, false]) {
    test(`review3 2 Hifdh ${on ? 'ON' : 'OFF'}: ${action} reconciles ${returned ? 'returned row' : 'missing snapshot'} and closes its editor`, async () => {
        const late = deferred(); let stored = false;
        const { screen } = await setup('teacher', { flag: on,
            read: (url: string) => url.endsWith('/hifz') ? ok([{ ...row, note: stored ? 'Reconciled note' : row.note }]) : undefined,
            write: (_: string, url: string) => url.endsWith(action === 'edit' ? '/hifz/7/correct' : '/hifz/7') ? late.promise : undefined });
        try {
            await visit(screen, on, on ? "Qur'an" : 'Hifdh'); chooseOption(studentPicker(screen), 9); await flush();
            click(exactButton(screen, action === 'edit' ? 'Edit entry' : 'Edit note')); await flush();
            const text = screen.all((n: Node) => action === 'edit' ? n.tag === 'input' && n.props.placeholder === 'e.g. struggled with the waqf on ayah 12' : n.tag === 'textarea' && n.props['aria-label'] === 'Note on this recitation')[0];
            assert.ok(text, screen.text()); type(text, 'Reconciled note'); await flush(); click(exactButton(screen, action === 'edit' ? 'Save changes' : 'Save note')); await flush();
            stored = true; late.resolve(ok(returned ? { ...row, note: 'Reconciled note' } : null)); await flush();
            assert.match(screen.text(), /Reconciled note/); assert.equal(screen.all((n: Node) => n.tag === 'button' && n.textContent === (action === 'edit' ? 'Save changes' : 'Save note')).length, 0);
        } finally { screen.unmount(); }
    });
}

test('review3 2 Hifdh repair preserves the newer edit refusal after a departed edit succeeds', async () => {
    const first = deferred(); const second = deferred(); let writes = 0; let stored = false;
    const { screen } = await setup('teacher', {
        read: (url: string) => url.endsWith('/hifz') ? ok([{ ...row, note: stored ? 'Older edit saved' : row.note }]) : undefined,
        write: (_: string, url: string) => url.endsWith('/hifz/7/correct') ? (++writes === 1 ? first.promise : second.promise) : undefined });
    const start = async (note: string) => {
        click(exactButton(screen, 'Edit entry')); await flush();
        type(screen.all((n: Node) => n.tag === 'input' && n.props.placeholder === 'e.g. struggled with the waqf on ayah 12')[0], note); await flush();
        click(exactButton(screen, 'Save changes')); await flush();
    };
    try {
        await pick(screen, "Qur'an"); chooseOption(studentPicker(screen), 9); await flush(); await start('Older edit saved');
        await pick(screen, 'Roster'); await pick(screen, "Qur'an"); await start('Newer edit'); assert.equal(writes, 2);
        stored = true; first.resolve(ok({ ...row, note: 'Older edit saved' })); await flush();
        second.reject(httpError(422, { message: 'Newest edit refused.' })); await flush();
        assert.match(screen.text(), /Newest edit refused\./); assert.equal(exactButton(screen, 'Save changes').disabled, false);
    } finally { screen.unmount(); }
});

for (const on of [true, false]) test(`review3 2 period ${on ? 'ON' : 'OFF'}: older class setting snapshot waits for totals without overwriting the newer period`, async () => {
    const first = deferred(); const second = deferred(); const totals = deferred(); let writes = 0; let reads = 0;
    const { screen, data } = await setup('teacher', { flag: on, data: { points_period: 'running' },
        read: (url: string) => url.includes('/awards/totals') ? (++reads === 1 ? ok({ points_period: 'running' }) : totals.promise) : undefined,
        write: (_: string, url: string) => url.endsWith('/points-period') ? (++writes === 1 ? first.promise : second.promise) : undefined });
    try {
        await visit(screen, on, 'Points'); const input: any = screen.all((n: Node) => n.props.id === 'points-weekly')[0];
        input.checked = true; input.props.onChange({ target: input }); input.checked = false; input.props.onChange({ target: input }); await flush(); assert.equal(writes, 2);
        second.resolve(ok({ points_period: 'running' })); await flush(); first.resolve(ok({ points_period: 'weekly' })); await flush();
        assert.equal(data.points_period, on ? 'running' : 'weekly');
    } finally { totals.resolve(ok({ points_period: 'running' })); await flush(); screen.unmount(); }
});

const arrivalOrders = [[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]];
// Server execution and response delivery are separate: a response holds the snapshot
// at execution time, while a repair GET reads all writes the server has accepted.
const queuedSnapshots = (initial: any, apply: (stored: any, body: any) => any) => {
    let stored = structuredClone(initial);
    const pending: any[] = [];
    return {
        get stored() { return structuredClone(stored); },
        pending,
        write(body: any) { const answer = deferred(); pending.push({ body, answer }); return answer.promise; },
        execute(index: number) {
            assert.equal(pending[index].snapshot, undefined, 'each request executes once');
            stored = apply(structuredClone(stored), pending[index].body);
            pending[index].snapshot = structuredClone(stored);
        },
        respond(index: number) { assert.ok(pending[index].snapshot); pending[index].answer.resolve(ok(pending[index].snapshot)); },
    };
};
const markedTracker = (ids: string[], mastered: string[] = []) => ({ ...twoDrills(), totals: { mastered: mastered.length, total: ids.length },
    letters: [{ ...twoDrills().letters[0], drills: ids.map(id => ({ id, text: 'ا', label: `Drill ${id.split('.').at(-1)}`, status: mastered.includes(id) ? 'mastered' : 'learning' })) }] });
const openDrills = async (screen: any, on: boolean) => {
    await visit(screen, on, on ? 'Arabic' : 'Letters'); click(screen.button('Practice student')); await flush();
    click(screen.all((n: Node) => String(n.props.class).includes('letter-tile'))[0]); await flush();
};
const refreshFailure = 'The letters could not be refreshed. Reload the page to see the latest marks.';

for (const realm of ['teacher', 'office'] as const) for (const on of [true, false]) test(`review4 finding 1 ${on ? 'ON' : 'OFF'}: ${realm} reversed execution and arrival shows both stored marks`, async () => {
    const ids = ['alif.1', 'alif.2']; let reads = 0;
    const server = queuedSnapshots(markedTracker(ids), (stored, body) => markedTracker(ids,
        [...stored.letters[0].drills.filter((d: any) => d.status === 'mastered').map((d: any) => d.id), body.drill_id]));
    const { screen } = await setup(realm, { flag: on,
        read: (url: string) => { if (url.includes('/members/9/letters')) { reads++; return ok(server.stored); } return undefined; },
        write: (_: string, url: string, body: any) => url.endsWith('/members/9/letters') ? server.write(body) : undefined });
    try {
        await openDrills(screen, on); click(drillButton(screen, 'Drill 1')); await flush(); click(drillButton(screen, 'Drill 2')); await flush();
        assert.equal(server.pending.length, 2); const before = reads;
        server.execute(1); server.respond(1); await flush(); assert.match(screen.text(), /1 of 2 mastered/);
        server.execute(0); server.respond(0); await flush();
        assert.match(screen.text(), /2 of 2 mastered/); assert.equal(server.stored.totals.mastered, 2);
        assert.equal(reads - before, on ? 1 : 0); assert.equal(drillButton(screen, 'Drill 1').disabled, false); assert.equal(drillButton(screen, 'Drill 2').disabled, false);
    } finally { screen.unmount(); }
});

for (const realm of ['teacher', 'office'] as const) for (const on of [true, false]) for (const refused of [true, false]) test(`review4 finding 2 ${on ? 'ON' : 'OFF'}: ${realm} failed repair ${refused ? 'preserves refusal' : 'reports refresh failure'}`, async () => {
    const first = deferred(); const second = deferred(); let writes = 0; let reads = 0;
    const { screen, alerts } = await setup(realm, { flag: on,
        read: (url: string) => { if (url.includes('/members/9/letters')) { if (++reads > 1) throw httpError(503, { message: 'Repair unavailable.' }); return ok(twoDrills()); } return undefined; },
        write: (_: string, url: string) => url.endsWith('/members/9/letters') ? (++writes === 1 ? first.promise : second.promise) : undefined });
    try {
        await openDrills(screen, on); click(drillButton(screen, 'Drill 1')); await flush(); click(drillButton(screen, 'Drill 2')); await flush(); assert.equal(writes, 2);
        if (refused) second.reject(httpError(422, { message: 'Newest mark refused.' })); else second.resolve(ok(twoDrills(1)));
        await flush(); first.resolve(ok(twoDrills(2))); await flush();
        assert.equal(reads, on ? 2 : 1, 'OFF never attempts the failing repair');
        assert.match(screen.text(), on ? (refused ? /0 of 2 mastered/ : /1 of 2 mastered/) : /2 of 2 mastered/);
        assert.equal(screen.text().includes('Newest mark refused.'), refused);
        assert.equal(screen.text().includes(refreshFailure), on && !refused);
        assert.equal(drillButton(screen, 'Drill 1').disabled, false); assert.equal(drillButton(screen, 'Drill 2').disabled, false);
        assert.doesNotMatch(screen.text(), /Loading|Saving|Marking/); assert.equal(alerts.length, 0);
    } finally { screen.unmount(); }
});

for (const realm of ['teacher', 'office'] as const) for (const on of [true, false]) for (const execution of ['sent', 'arrival']) for (const order of arrivalOrders) test(`review4 matrix letters ${on ? 'ON' : 'OFF'}: ${realm} execution ${execution}, arrival ${order.join('')}`, async () => {
    const ids = ['alif.1', 'alif.2', 'alif.3']; let reads = 0;
    const server = queuedSnapshots(markedTracker(ids), (stored, body) => markedTracker(ids,
        [...stored.letters[0].drills.filter((d: any) => d.status === 'mastered').map((d: any) => d.id), body.drill_id]));
    const { screen } = await setup(realm, { flag: on,
        read: (url: string) => { if (url.includes('/members/9/letters')) { reads++; return ok(server.stored); } return undefined; },
        write: (_: string, url: string, body: any) => url.endsWith('/members/9/letters') ? server.write(body) : undefined });
    try {
        await openDrills(screen, on);
        for (let i = 1; i <= 3; i++) { click(drillButton(screen, `Drill ${i}`)); await flush(); }
        assert.equal(server.pending.length, 3); const before = reads;
        if (execution === 'sent') [0, 1, 2].forEach(i => server.execute(i));
        for (const [at, i] of order.entries()) {
            if (execution === 'arrival') server.execute(i);
            server.respond(i); await flush();
            assert.equal(reads - before, on && at === 2 ? 1 : 0, 'only the last pending save repairs the burst');
        }
        const expected = on ? server.stored : server.pending[order[2]].snapshot;
        assert.match(screen.text(), new RegExp(`${expected.totals.mastered} of 3 mastered`));
        for (const drill of expected.letters[0].drills) {
            const button = drillButton(screen, drill.label);
            const statusRow = realm === 'teacher' ? button.parent?.parent : button;
            assert.ok(String(statusRow?.props.class).includes(`drill--${drill.status}`));
            assert.equal(drillButton(screen, drill.label).disabled, false);
        }
    } finally { screen.unmount(); }
});

for (const realm of ['teacher', 'office'] as const) for (const on of [true, false]) for (const order of arrivalOrders) test(`review4 matrix stage ${on ? 'ON' : 'OFF'}: ${realm} arrival ${order.join('')}`, async () => {
    const stages = ['initial', 'one', 'two', 'three'].map(id => ({ id, label: `Stage ${id}` })); let reads = 0;
    const server = queuedSnapshots({ students: [student], stage: stages[0], stages, total: 2 }, (stored, body) => ({ ...stored, stage: stages.find(s => s.id === body.stage) }));
    const { screen } = await setup(realm, { flag: on, data: { arabic_stage: 'initial' },
        read: (url: string) => { if (url.includes('/letters?')) { reads++; return ok(server.stored); } return undefined; },
        write: (_: string, url: string, body: any) => url.endsWith('/letters/stage') ? server.write(body) : undefined });
    try {
        await visit(screen, on, on ? 'Arabic' : 'Letters');
        const picker = screen.all((n: Node) => n.tag === 'select' && n.children.some(c => c.textContent === 'Stage one'))[0];
        for (const stage of ['one', 'two', 'three']) { picker.value = stage; chooseOption(picker, stage); }
        await flush(); assert.equal(server.pending.length, 3); const before = reads;
        for (const [at, i] of order.entries()) {
            server.execute(i); server.respond(i); await flush();
            assert.equal(reads - before, (realm === 'teacher' ? at + 1 : 0) + (on && at === 2 ? 1 : 0), 'teacher reads after each stage save; either realm repairs at most once');
        }
        assert.equal(picker.props.value, server.stored.stage.id); assert.equal(picker.disabled, false);
        assert.match(screen.text(), new RegExp(server.stored.stage.label));
    } finally { screen.unmount(); }
});

for (const on of [true, false]) for (const order of arrivalOrders) test(`review4 matrix period ${on ? 'ON' : 'OFF'}: teacher arrival ${order.join('')}`, async () => {
    let reads = 0;
    const server = queuedSnapshots({ points_period: 'running' }, (_stored, body) => ({ points_period: body.points_period }));
    const { screen, data } = await setup('teacher', { flag: on, data: { points_period: 'running' },
        read: (url: string) => { if (url.includes('/awards/totals')) { reads++; return ok(server.stored); } return undefined; },
        write: (_: string, url: string, body: any) => url.endsWith('/points-period') ? server.write(body) : undefined });
    try {
        await visit(screen, on, 'Points'); const input: any = screen.all((n: Node) => n.props.id === 'points-weekly')[0];
        for (const checked of [true, false, true]) { input.checked = checked; input.props.onChange({ target: input }); }
        await flush(); assert.equal(server.pending.length, 3); const before = reads;
        for (const [at, i] of order.entries()) {
            server.execute(i); server.respond(i); await flush();
            assert.equal(reads - before, at + 1 + (on && at === 2 ? 1 : 0), 'one normal totals read per success and at most one repair');
        }
        assert.equal(data.points_period, server.stored.points_period); assert.equal(input.checked, server.stored.points_period === 'weekly'); assert.equal(input.disabled, false);
    } finally { screen.unmount(); }
});

for (const action of ['edit', 'note', 'mixed']) for (const order of arrivalOrders) test(`review4 matrix Hifdh: ${action} arrival ${order.join('')}`, async () => {
    let reads = 0;
    const server = queuedSnapshots(row, (stored, body) => ({ ...stored, note: body.note }));
    const { screen } = await setup('teacher', {
        read: (url: string) => { if (url.endsWith('/hifz')) { reads++; return ok([server.stored]); } return undefined; },
        write: (_: string, url: string, body: any) => /\/hifz\/7(?:\/correct)?$/.test(url) ? server.write(body) : undefined });
    try {
        await pick(screen, "Qur'an"); chooseOption(studentPicker(screen), 9); await flush();
        for (let i = 0; i < 3; i++) {
            if (i) { await pick(screen, 'Roster'); await pick(screen, "Qur'an"); }
            const editing = action === 'edit' || (action === 'mixed' && i !== 1);
            click(exactButton(screen, editing ? 'Edit entry' : 'Edit note')); await flush();
            const text = screen.all((n: Node) => editing ? n.tag === 'input' && n.props.placeholder === 'e.g. struggled with the waqf on ayah 12' : n.tag === 'textarea' && n.props['aria-label'] === 'Note on this recitation')[0];
            type(text, `Stored note ${i}`); await flush(); click(exactButton(screen, editing ? 'Save changes' : 'Save note')); await flush();
        }
        assert.equal(server.pending.length, 3); const before = reads;
        for (const [at, i] of order.entries()) {
            server.execute(i); server.respond(i); await flush();
            assert.equal(reads - before, at === 2 ? 1 : 0, 'departed editors share one final repair');
        }
        assert.match(screen.text(), new RegExp(server.stored.note)); assert.equal(exactButton(screen, 'Edit entry').disabled, false);
        assert.doesNotMatch(screen.text(), /Loading recitations|Saving/);
    } finally { screen.unmount(); }
});

for (const action of ['masterAll', 'masterGroup', 'saveDrillNote']) for (const order of arrivalOrders) test(`review4 matrix siblings: ${action} arrival ${order.join('')}`, async () => {
    const ids = ['alif.1', 'alif.2', 'alif.3']; let reads = 0;
    const initial = { ...markedTracker(ids), groups: [{ id: 'practice', label: 'Practice', totals: { mastered: 0, total: 3 }, drills: [] }] };
    const server = queuedSnapshots(initial, (stored, body) => {
        for (const drill of stored.letters[0].drills) {
            if (!body.drill_id || body.drill_id === drill.id) {
                drill.status = body.status ?? 'mastered';
                if ('note' in body) drill.note = body.note;
            }
        }
        stored.totals.mastered = stored.letters[0].drills.filter((d: any) => d.status === 'mastered').length;
        return stored;
    });
    const { screen } = await setup('teacher', {
        read: (url: string) => { if (url.includes('/members/9/letters')) { reads++; return ok(server.stored); } return undefined; },
        write: (_: string, url: string, body: any) => url.includes('/members/9/letters') ? server.write(body) : undefined });
    try {
        await openDrills(screen, true);
        if (action === 'masterAll') { click(exactButton(screen, 'Mark all mastered')); await flush(); click(screen.button('Just this stage')); }
        else if (action === 'masterGroup') { click(exactButton(screen, 'Mark all practice mastered')); await flush(); click(exactButton(screen, 'Yes, mark them mastered')); }
        else { click(exactButton(screen, 'Note on Drill 1')); await flush(); type(screen.all((n: Node) => n.tag === 'textarea')[0], 'Stored sibling note'); await flush(); click(exactButton(screen, 'Save note')); }
        await flush(); click(drillButton(screen, 'Drill 2')); await flush(); click(drillButton(screen, 'Drill 3')); await flush();
        assert.equal(server.pending.length, 3); const before = reads;
        for (const [at, i] of order.entries()) {
            server.execute(i); server.respond(i); await flush();
            assert.equal(reads - before, at === 2 ? 1 : 0, 'siblings share one final repair');
        }
        assert.match(screen.text(), new RegExp(`${server.stored.totals.mastered} of 3 mastered`));
        if (action === 'saveDrillNote') assert.match(screen.text(), /Stored sibling note/);
        for (const drill of server.stored.letters[0].drills) {
            const button = drillButton(screen, drill.label);
            assert.ok(String(button.parent?.parent?.props.class).includes(`drill--${drill.status}`)); assert.equal(button.disabled, false);
        }
    } finally { screen.unmount(); }
});

for (const realm of ['teacher', 'office'] as const) test(`review4 OFF: ${realm} ordinary tracker read failure keeps main's error display`, async () => {
    let reads = 0;
    const { screen, alerts } = await setup(realm, { flag: false,
        read: (url: string) => { if (url.includes('/members/9/letters')) { reads++; throw httpError(503, { message: 'Tracker unavailable.' }); } return undefined; } });
    try {
        await visit(screen, false, 'Letters'); click(screen.button('Practice student')); await flush();
        assert.equal(reads, 1); assert.equal(screen.all((n: Node) => String(n.props.class).includes('letter-tile')).length, 0);
        assert.equal(screen.text().includes('Tracker unavailable.'), realm === 'office');
        assert.equal(screen.text().includes('All students'), realm === 'teacher');
        assert.equal(screen.text().includes(refreshFailure), false); assert.doesNotMatch(screen.text(), /Loading/); assert.equal(alerts.length, 0);
    } finally { screen.unmount(); }
});

for (const flag of [true, false]) test(`walk: lesson plan subject editor and server errors ${flag ? 'ON' : 'OFF'}`, async () => {
    const message = 'You do not teach that subject in this class.';
    const { screen, calls } = await setup('teacher', { flag, mine: [102],
        read: (url: string) => url.includes('/lesson-plans?') ? ok({ plans: [], hidden_fields: [], meeting_weekdays: null })
            : url.includes('/curriculum') ? ok({ grades: [], subjects: [], weeks: [] }) : undefined,
        write: (_method: string, url: string) => url.includes('/lesson-plans') ? Promise.reject(httpError(403, { message })) : undefined,
    });
    try {
        if (flag) await pick(screen, 'Lesson Plans');
        else { click(exactButton(screen, 'More')); await flush(); click(exactButton(screen, 'Lesson Plans')); await flush(10); }
        const pickers = screen.all((n: Node) => n.props['data-plan-subject'] !== undefined);
        if (flag) {
            assert.equal(pickers.length, 1, 'ON has a subject picker');
            assert.deepEqual(pickers[0].children.filter((n: Node) => n.tag === 'option').map((n: Node) => n.textContent), ['No subject / general', 'Arabic']);
            assert.equal(screen.all((n: Node) => n.tag === 'input' && n.props.placeholder === 'e.g. Arabic').length, 0);
            chooseOption(pickers[0], 102); await flush();
        } else {
            assert.equal(pickers.length, 0);
            const subject = screen.all((n: Node) => n.tag === 'input' && n.props.placeholder === 'e.g. Arabic')[0];
            assert.ok(subject); type(subject, 'Arabic'); await flush();
        }
        const activities = screen.all((n: Node) => n.tag === 'textarea' && Number(n.props.rows) === 4)[0];
        assert.ok(activities); type(activities, 'Practice letters'); await flush();
        click(exactButton(screen, 'Save plan')); await flush(10);
        const save = calls.findLast((c: any) => c.method === 'post' && c.url.endsWith('/lesson-plans'));
        assert.ok(save);
        if (flag) { assert.equal(save.body.class_subject_id, 102); assert.equal('subject' in save.body, false); assert.ok(screen.text().includes(message)); }
        else { assert.equal(save.body.subject, 'Arabic'); assert.equal('class_subject_id' in save.body, false); assert.ok(screen.text().includes('That plan could not be saved.')); }
    } finally { screen.unmount(); }
});

for (const scenario of ['limited named', 'unrestricted named', 'hidden named', 'unlinked named', 'limited general', 'no subjects'] as const) {
    test(`walk: ON plan choices and unchanged saves for ${scenario}`, async () => {
        const today = new Date();
        const day = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
        const general = scenario === 'limited general' || scenario === 'no subjects';
        const linked = !general && scenario !== 'unlinked named';
        const plan = { id: 77, class_subject_id: linked ? 102 : null, subject: general ? null : 'Arabic', session_date: day, body: 'Saved activities', attachments: [] };
        const { screen, calls } = await setup('teacher', {
            mine: scenario === 'unrestricted named' || scenario === 'unlinked named' ? null : scenario === 'no subjects' ? [] : [102],
            data: scenario === 'hidden named' ? { class_subjects: subjects.filter((s: any) => s.id !== 102) } : {},
            read: (url: string) => url.includes('/lesson-plans?') ? ok({ plans: [plan], hidden_fields: [], meeting_weekdays: null })
                : url.includes('/curriculum') ? ok({ grades: [], subjects: [], weeks: [] }) : undefined,
            write: (_method: string, url: string) => url.includes('/lesson-plans') ? ok(plan) : undefined,
        });
        try {
            await pick(screen, 'Lesson Plans');
            const picker = screen.all((n: Node) => n.props['data-plan-subject'] !== undefined)[0];
            assert.ok(picker);
            const words = picker.children.filter((n: Node) => n.tag === 'option').map((n: Node) => n.textContent);
            const allowsGeneral = general || scenario === 'unrestricted named' || scenario === 'unlinked named';
            assert.equal(words.includes('No subject / general'), allowsGeneral);
            if (scenario === 'hidden named' || scenario === 'unlinked named') assert.ok(words.includes('Arabic (saved subject)'));
            if (scenario === 'no subjects') assert.deepEqual(words, ['No subject / general']);
            click(exactButton(screen, 'Save plan')); await flush(10);
            const save = calls.findLast((c: any) => c.method === 'put' && c.url.endsWith('/lesson-plans/77'));
            assert.ok(save);
            if (linked) { assert.equal(save.body.class_subject_id, 102); assert.equal('subject' in save.body, false); }
            else if (!general) { assert.equal('class_subject_id' in save.body, false); assert.equal('subject' in save.body, false); }
            else { assert.equal(save.body.class_subject_id, null); assert.equal(save.body.subject, null); }
        } finally { screen.unmount(); }
    });
}

for (const count of [0, 2]) test(`current-grade merge says what changed when count is ${count}`, async () => {
    const { screen } = await setup('office', { write: (method: string, url: string) => url.endsWith('/add-for-current-grades') ? ok(subjects, { subjects_added: count }) : undefined });
    try {
        click(exactButton(screen, 'Add subjects for current grades')); await flush(12);
        assert.ok(screen.text().includes(count === 0 ? 'This class already has the subjects for its current grades.' : 'Subjects for current grades added.'));
    } finally { screen.unmount(); }
});

test('office ON roster staff labels use current limits including hidden names; OFF has no added staff panel', async () => {
    const teachers = [
        { id: 7, name: 'All Teacher', class_subject_ids: null, class_subject_names: null },
        { id: 8, name: 'None Teacher', class_subject_ids: [], class_subject_names: [] },
        { id: 9, name: 'Limited Teacher', class_subject_ids: [104], class_subject_names: [{ id: 104, name: 'Healthful Living', position: 0, hidden_at: null }] },
        { id: 10, name: 'Hidden Teacher', class_subject_ids: [103], class_subject_names: [{ id: 103, name: 'Reading', position: 1, hidden_at: '2026-10-08' }] },
    ];
    for (const flag of [true, false]) {
        const { screen } = await setup('office', { flag, data: { teachers } });
        try {
            if (flag) for (const words of ['All Teacher', 'All subjects', 'None Teacher', 'No subjects', 'Healthful Living', 'Reading (hidden)']) assert.ok(screen.text().includes(words), words);
            else assert.ok(!screen.text().includes('Hidden Teacher'));
        } finally { screen.unmount(); }
    }
});

test('office Grades has an ON-only shrinkable layout hook for narrow phones', async () => {
    for (const flag of [true, false]) {
        const { screen } = await setup('office', { flag, width: 320, query: { tab: 'grades' } });
        try { assert.equal(screen.all((n: Node) => String(n.props.class ?? '').includes('office-subject-grades')).length, flag ? 1 : 0); }
        finally { screen.unmount(); }
    }
    const source = readFileSync(new URL('../views/dashboard/groups/GroupGradesTab.vue', import.meta.url), 'utf8');
    assert.match(source, /\.office-subject-grades[^}]*min-width: 0/);
    assert.match(source, /\.office-subject-grades[^}]*white-space: normal/);
});

for (const count of [0, 1]) test(`merge reload refusal retains the resolved success sentence for count ${count}`, async () => {
    let merged = false;
    const { screen } = await setup('office', {
        write: (_method: string, url: string) => { if (url.endsWith('/add-for-current-grades')) { merged = true; return ok(subjects, { subjects_added: count }); } },
        read: (url: string) => { if (merged && url.endsWith('/subjects')) throw httpError(503, { message: 'The catalog is temporarily unavailable.' }); },
    });
    try {
        click(exactButton(screen, 'Add subjects for current grades')); await flush(12);
        assert.ok(screen.text().includes(count === 0 ? 'This class already has the subjects for its current grades.' : 'Subjects for current grades added.'));
        assert.match(screen.text(), /The list could not reload\. The catalog is temporarily unavailable\./);
        assert.doesNotMatch(screen.text(), /response =>|subjects_added|function/);
    } finally { screen.unmount(); }
});


for (const linked of [true, false]) test(`review8: ON gradebook editor retains ${linked ? 'linked' : 'unlinked'} identity sharing a current name`, async () => {
    const work = { id: 77, title: 'Saved work', subject: 'Arabic', class_subject_id: linked ? 102 : null, scale: 'points', points_possible: 10, assigned_on: '2026-10-08' };
    const { screen, calls } = await setup('teacher', {
        read: (url: string) => url.endsWith('/assignments') ? { data: { status: 'success', data: [work], subjects: [{ name: 'Arabic', key: 'arabic', class_subject_id: 102 }] } } : undefined,
    });
    try {
        await pick(screen, 'Grades');
        click(exactButton(screen, 'Edit Saved work')); await flush();
        click(exactButton(screen, 'Save changes')); await flush(10);
        const save = calls.findLast((c: any) => c.method === 'put' && c.url.endsWith('/assignments/77'));
        assert.ok(save); assert.equal('subject' in save.body, false, 'unchanged displayed name must not become a new choice');
        if (linked) assert.equal(save.body.class_subject_id, 102);
        else assert.equal('class_subject_id' in save.body, false, 'unlinked work remains unlinked');
    } finally { screen.unmount(); }
});

for (const flag of [true, false]) test(`review8: mounted Save keeps distinct identities ${flag ? 'ON' : 'OFF'}`, async () => {
    const today = new Date();
    const day = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
    const plans = [
        { id: 77, session_date: day, subject: 'Arabic', class_subject_id: 102, body: 'Linked activities' },
        { id: 78, session_date: day, subject: 'Arabic', class_subject_id: null, body: 'Historical activities' },
    ];
    const { screen, calls } = await setup('teacher', { flag,
        read: (url: string) => url.includes('/lesson-plans?') ? ok({ plans, hidden_fields: [], meeting_weekdays: null }) : undefined,
    });
    try {
        if (flag) await pick(screen, 'Lesson Plans');
        else { click(exactButton(screen, 'More')); await flush(); click(exactButton(screen, 'Lesson Plans')); await flush(10); }
        const save = exactButton(screen, 'Save plan');
        assert.equal(Boolean(save.props.disabled), !flag);
        assert.equal(screen.text().includes('This day already has a Arabic plan.'), !flag);
        if (flag) { click(save); await flush(10); assert.ok(calls.some((c: any) => c.method === 'put' && c.url.endsWith('/lesson-plans/77'))); }
    } finally { screen.unmount(); }
});


for (const flag of [true, false]) test(`review8: mounted week copy separates current labels from saved identity ${flag ? 'ON' : 'OFF'}`, async () => {
    const today = new Date();
    const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    const sourceDay = iso(today);
    const target = new Date(today); target.setDate(today.getDate() - today.getDay() + (today.getDay() === 1 ? 2 : 1));
    const targetDay = iso(target);
    const plans = [
        { id: 77, session_date: sourceDay, subject: 'Literacy', class_subject_id: 102, grade_label: '1', body: 'Source activities' },
        { id: 78, session_date: targetDay, subject: 'Literacy', class_subject_id: null, body: 'Historical activities' },
    ];
    const { screen, calls } = await setup('teacher', { flag,
        read: (url: string) => url.includes('/lesson-plans?') ? ok({ plans, hidden_fields: [], meeting_weekdays: null })
            : url.includes('/curriculum') ? ok({ grades: ['1'], subjects: ['Literacy'], weeks: [] }) : undefined,
        write: (_method: string, url: string, body: any) => url.includes('/lesson-plans') ? ok({ id: 99, ...body }) : undefined,
    });
    try {
        if (flag) await pick(screen, 'Lesson Plans');
        else { click(exactButton(screen, 'More')); await flush(); click(exactButton(screen, 'Lesson Plans')); await flush(10); }
        const button = screen.all((n: Node) => n.tag === 'button' && n.textContent.includes('Copy this Literacy plan to the rest of this week'))[0];
        assert.ok(button, screen.text()); click(button); await flush(10);
        const copy = calls.find((c: any) => ['put', 'post'].includes(c.method) && c.url.includes('/lesson-plans') && c.body.session_date === targetDay);
        assert.ok(copy); assert.equal(copy.method, flag ? 'post' : 'put');
        assert.equal(copy.url.endsWith('/lesson-plans/78'), !flag);
        assert.equal(copy.body.body, flag ? 'Source activities' : 'Historical activities');
    } finally { screen.unmount(); }
});

for (const flag of [false, true]) for (const width of [1280, 390]) test(`calendar browser: lesson notices, saved closed dates and empty weeks subjects=${flag} width=${width}`, async () => {
    let empty = false;
    const { screen } = await setup('teacher', { flag, width, read: (url: string) => {
        if (!url.includes('/lesson-plans?')) return undefined;
        const start = url.match(/from=([^&]+)/)![1];
        const date = new Date(start + 'T00:00:00'); date.setDate(date.getDate()+1);
        const iso = `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
        return ok({ plans: empty ? [] : [{ id: 77, session_date: iso, subject: '', subject_key: '', body: 'Saved on a closed day', attachments: [] }], hidden_fields: [], meeting_weekdays: [], week_dates: empty ? [] : [iso], day_notices: { [iso]: 'No school on Monday, October 12, 2026 — Teacher planning day.', [start]: "This isn't one of the school's meeting days on the calendar." } });
    } });
    try {
        if (flag) { if (width < 768) { click(exactButton(screen, 'Class menu')); await flush(); } await pick(screen, 'Lesson Plans'); }
        else { click(exactButton(screen, 'More')); await flush(); click(exactButton(screen, 'Lesson Plans')); await flush(10); }
        click(screen.all((n: Node) => n.tag === 'button' && /: 1 plan$/.test(String(n.props['aria-label'])))[0]); await flush();
        assert.match(screen.text(), /No school on Monday, October 12, 2026 — Teacher planning day./);
        assert.ok(screen.button('Save plan'));
        click(exactButton(screen, 'Week')); await flush();
        assert.match(screen.text(), /Saved on a closed day/);
        empty = true; click(screen.all((n: Node) => n.tag === 'button' && n.children.some((c: Node) => String(c.props.class).includes('bi-chevron-right')))[0]); await flush(10);
        assert.match(screen.text(), /No school this week./);
    } finally { screen.unmount(); }
});

for (const flag of [false,true]) for (const calendar of [false,true]) test(`calendar browser: Reports school years and dated card label subjects=${flag} calendar=${calendar}`, async () => {
    const { screen } = await setup('teacher', { flag, read: (url: string) => {
        if (url.includes('/report-cards?')) return ok({ period: { type: 'report_card', school_year: '2026-2027', term: 2 }, students: [student], ...(calendar ? { school_years: ['2028-2029','2026-2027'] } : {}) });
        if (url.includes('/report-card?')) return ok({ id: 7, student, type: 'report_card', type_label: 'Report Card', school_year: '2024-2025', term: 2, period_label: calendar ? 'Autumn (Oct 20, 2024 – Jan 16, 2025), 2024-2025' : 'Quarter 2, 2024-2025', subjects: [], learning_behaviours: [], attendance: {} });
        return undefined;
    } });
    try {
        if (flag) await pick(screen, 'Reports'); else { click(exactButton(screen, 'More')); await flush(); click(exactButton(screen, 'Reports')); await flush(10); }
        const yearOptions = () => screen.all((n: Node) => n.tag === 'select' && n.children.some((c: Node) => c.textContent === '2026-2027'))[0].children.filter((n: Node) => n.tag === 'option').map((n: Node) => n.textContent);
        assert.deepEqual(yearOptions(), calendar ? ['2028-2029','2026-2027'] : ['2025-2026','2026-2027','2027-2028']);
        click(screen.button('Practice student')); await flush(10);
        assert.match(screen.text(), calendar ? /Autumn \(Oct 20, 2024 – Jan 16, 2025\), 2024-2025/ : /Quarter 2, 2024-2025/);
        click(screen.button('All students')); await flush(10);
        if (calendar) assert.deepEqual(yearOptions(), ['2028-2029','2026-2027','2024-2025']);
    } finally { screen.unmount(); }
});

test('calendar browser: the year the Reports list is using is always offered, even outside the school years', async () => {
    const { screen } = await setup('teacher', { flag: false, read: (url: string) => {
        if (url.includes('/report-cards?')) return ok({ period: { type: 'report_card', school_year: '2026-2027', term: 2 }, students: [student], school_years: ['2026-2026'] });
        return undefined;
    } });
    try {
        click(exactButton(screen, 'More')); await flush(); click(exactButton(screen, 'Reports')); await flush(10);
        const select = screen.all((n: Node) => n.tag === 'select' && n.children.some((c: Node) => c.textContent === '2026-2026'))[0];
        assert.deepEqual(select.children.filter((n: Node) => n.tag === 'option').map((n: Node) => n.textContent), ['2026-2027', '2026-2026']);
    } finally { screen.unmount(); }
});

// Steps 2/3 use the server's membership IDs and copied wording, never contact IDs or calendar arithmetic.
const workStudents = [{ id: 9, name: 'Practice student', grade_label: '1st', grade_key: '1' }, { id: 10, name: 'Practice learner', grade_label: '2nd', grade_key: '2' }];
const workEntry = (week_no: number, grade_label = '1st') => ({ source: 'guide', piece_id: null, title: `Focus ${week_no}`, detail: 'Assessment note', standard_code: 'TEST.1', week_no, grade_label, marks: [], mark_count: 0 });
function workFixture() {
    return { subject: subjects[3], students: structuredClone(workStudents), levels: [
        { level: 4, short_label: 'Exceeds', description: 'Practice exceeds description' }, { level: 3, short_label: 'Meets', description: 'Practice meets description' },
        { level: 2, short_label: 'Approaching', description: 'Practice approaching description' }, { level: 1, short_label: 'Needs Support', description: 'Practice support description' }],
        curriculum: [{ grade_key: '1', grade_label: '1st', students: [workStudents[0]], entries: [workEntry(2), workEntry(12)], opening_week_no: 12, selected_week_no: 12 },
            { grade_key: '2', grade_label: '2nd', students: [workStudents[1]], entries: [workEntry(7, '2nd')], opening_week_no: 7, selected_week_no: 7 }],
        lesson_plans: [{ source: 'plan', piece_id: null, lesson_plan_id: 31, title: '2026-10-09: Practice objective', detail: null, marks: [], mark_count: 0 },
            { source: 'plan', piece_id: 32, lesson_plan_id: null, title: '2026-10-08: Retained objective', detail: null, marks: [], mark_count: 0 }],
        own_pieces: [{ source: 'own', piece_id: 41, title: 'Practice piece', detail: 'Practice detail', marks: [], mark_count: 0 }],
        notes: [{ id: 51, group_membership_id: 9, student_name: 'Practice student', body: 'Practice note', author_name: 'Practice Teacher', created_at: '2026-10-09T14:00:00Z' },
            { id: 50, group_membership_id: null, student_name: 'Whole class', body: 'Practice update', author_name: 'Practice Teacher', created_at: '2026-10-08T14:00:00Z' }] };
}
async function workSetup(realm: 'teacher' | 'office' = 'teacher', opts: any = {}) {
    const work: any = opts.work ?? workFixture();
    const result = await setup(realm, { closed: true, query: { subject: '104' }, ...opts,
        data: { class_subject_work_enabled: true, ...opts.data },
        read: (url: string) => { const custom = opts.read?.(url); if (custom !== undefined) return custom; if (url.endsWith('/work')) return ok(structuredClone(work)); if (url.endsWith('/notes')) return ok(structuredClone(work.notes)); },
        write: (method: string, url: string, body: any) => {
            const custom = opts.write?.(method, url, body); if (custom !== undefined) return custom;
            if (url.endsWith('/marks')) {
                const pieces = [...work.curriculum.flatMap((b: any) => b.entries), ...work.lesson_plans, ...work.own_pieces];
                const piece = body.piece_id ? pieces.find((p: any) => p.piece_id === body.piece_id) : body.source === 'guide'
                    ? pieces.find((p: any) => p.guide_subject === body.guide_subject && p.week_no === body.week_no && p.grade_label === body.grade_label) : pieces.find((p: any) => p.lesson_plan_id === body.lesson_plan_id);
                piece.piece_id ??= 70;
                const merged = new Map(piece.marks.map((m: any) => [m.group_membership_id, m]));
                const versions = body.marks.map((m: any) => ({ group_membership_id: m.group_membership_id, updated_at: m.level != null || m.comment?.trim() ? '2026-10-09T16:00:00.000000Z' : null }));
                for (const m of body.marks) {
                    if (m.level != null || m.comment?.trim()) merged.set(m.group_membership_id, { ...m, updated_at: versions.find((v: any) => v.group_membership_id === m.group_membership_id).updated_at });
                    else merged.delete(m.group_membership_id);
                }
                piece.marks = [...merged.values()]; piece.mark_count = piece.marks.length;
                return ok({ piece_id: piece.piece_id, marks: versions });
            }
            if (/\/notes(?:\/\d+)?$/.test(url)) {
                const id = Number(url.split('/').pop());
                if (method === 'delete') work.notes = work.notes.filter((n: any) => n.id !== id);
                else if (method === 'put') work.notes.find((n: any) => n.id === id).body = body.body;
                else work.notes.unshift({ id: 60, ...body, student_name: workStudents.find(s => s.id === body.group_membership_id)?.name ?? 'Whole class', author_name: 'Practice Teacher', created_at: '2026-10-09T16:00:00Z' });
                return ok({ id: 60 });
            }
            if (/\/pieces(?:\/\d+)?$/.test(url)) {
                const id = Number(url.split('/').pop());
                if (method === 'delete') work.own_pieces = work.own_pieces.filter((p: any) => p.piece_id !== id);
                else if (method === 'put') Object.assign(work.own_pieces.find((p: any) => p.piece_id === id), body);
                else work.own_pieces.unshift({ piece_id: 61, source: 'own', ...body, marks: [], mark_count: 0 });
                return ok({ id: 61 });
            }
        } });
    return { ...result, work };
}
const workField = (s: any, label: string) => { const found = s.all((n: Node) => n.props['aria-label'] === label); assert.equal(found.length, 1, label); return found[0]; };
const area = (s: any, label: string) => { const found = s.all((n: Node) => n.props['data-work-area'] === label); assert.equal(found.length, 1, label); return found[0]; };
const within = (node: Node, predicate: (n: Node) => boolean): Node[] => node.children.flatMap(n => [...(predicate(n) ? [n] : []), ...within(n, predicate)]);
const areaButton = (s: any, a: string, words: string) => { const found = within(area(s, a), n => n.tag === 'button' && n.textContent === words); assert.equal(found.length, 1, `${a}: ${words}`); return found[0]; };

test('work OFF: class-subjects bootstrap and no-tool words/requests remain pinned in both views', async () => {
    for (const realm of ['teacher', 'office'] as const) {
        const r = await setup(realm, { closed: true });
        try {
            assert.deepEqual(r.calls.map(c => c.url), realm === 'teacher' ? ['/api/teacher/masjids/1/groups/2'] : ['/api/admin/masjids/1/groups/2', '/api/admin/masjids/1/groups/2/memberships', '/api/admin/masjids/1/groups/2/subjects', '/api/admin/masjids/1/school-subjects']);
            await pick(r.screen, 'Healthful Living');
            assert.match(r.screen.text(), /There is nothing here yet\./); assert.doesNotMatch(r.screen.text(), /From the curriculum|From lesson plans|Your own pieces|Notes and updates/);
            assert.equal(r.calls.filter(c => c.url?.endsWith('/work')).length, 0);
        } finally { r.screen.unmount(); }
    }
});
test('walk 12: student and whole-class notes create, edit and confirmed delete', async () => {
    const r = await workSetup(); const s = r.screen;
    try {
        assert.match(s.text(), /Practice student.*Practice note.*Practice Teacher/); assert.match(s.text(), /Whole class.*Practice update/);
        click(s.button('New note')); await flush(); chooseOption(workField(s, 'Note about'), 'student'); await flush();
        chooseOption(workField(s, 'Student for note'), 9); type(workField(s, 'Note text'), 'New student note'); submit(within(area(s, 'notes'), n => n.tag === 'form')[0]); await flush(10);
        assert.deepEqual(r.calls.find(c => c.method === 'post' && c.url.endsWith('/notes')).body, { group_membership_id: 9, body: 'New student note' });
        click(s.button('New note')); await flush(); type(workField(s, 'Note text'), 'New class update'); submit(within(area(s, 'notes'), n => n.tag === 'form')[0]); await flush(10);
        assert.match(s.text(), /New class update/);
        click(workField(s, 'Edit note: Practice note')); await flush(); type(workField(s, 'Note text'), 'Corrected note'); submit(within(area(s, 'notes'), n => n.tag === 'form')[0]); await flush(10); assert.match(s.text(), /Corrected note/);
        click(workField(s, 'Delete note: Corrected note')); await flush(); assert.match(s.text(), /Delete this note\?/); click(s.button('Delete note')); await flush(10); assert.doesNotMatch(s.text(), /Corrected note/);
        assert.doesNotMatch(s.text(), /Share with the family/);
    } finally { s.unmount(); }
});
test('walk 13: direct restricted subject shows the server refusal and never fetches work', async () => {
    const r = await workSetup('teacher', { mine: [102], detail: async () => { throw httpError(403, { message: 'Forbidden.' }); } });
    try { assert.match(r.screen.text(), /That subject is not available/); assert.equal(r.calls.filter(c => c.url.endsWith('/work')).length, 0); } finally { r.screen.unmount(); }
});
test('walk 14: office reads all four blocks, opens entries/pieces and has no writing controls or requests', async () => {
    const r = await workSetup('office'); const s = r.screen;
    try {
        for (const words of ['From the curriculum', 'From lesson plans', 'Your own pieces', 'Notes and updates', 'Practice note', 'Whole class']) assert.ok(s.text().includes(words), s.text());
        click(s.button('Oct 9, 2026 · Practice objective')); await flush(); click(s.button('Practice piece')); await flush();
        assert.equal(s.all(n => n.tag === 'textarea' || n.tag === 'input' || n.props['aria-pressed'] !== undefined).length, 0);
        assert.equal(s.all(n => n.tag === 'button' && /^(Save|Edit|Delete|Add a piece|New note)/.test(n.textContent)).length, 0);
        assert.equal(r.calls.filter(c => c.method !== 'get').length, 0);
    } finally { s.unmount(); }
});
test('walk 15: server-selected numbered entries, combined-grade rows and no calendar Week wording at 390/320', async () => {
    for (const width of [390, 320]) {
        const r = await workSetup('teacher', { width }); const s = r.screen;
        try {
            assert.match(s.text(), /1st.*12 · Focus 12/); assert.match(s.text(), /2nd.*7 · Focus 7/); assert.doesNotMatch(area(s, 'curriculum').textContent, /Week|week/);
            for (const [grade, name, other] of [['1', 'Practice student', 'Practice learner'], ['2', 'Practice learner', 'Practice student']]) {
                const block = workField(s, `Curriculum grade ${grade}`); assert.ok(block.textContent.includes(name)); assert.ok(!block.textContent.includes(other));
                const buttons = within(block, n => n.props['aria-pressed'] !== undefined); assert.equal(buttons.length, 4); assert.ok(buttons.every(n => String(n.props['aria-pressed']) === 'false'));
            }
            assert.match(s.text(), /What do 4, 3, 2 and 1 mean\?/); assert.match(s.text(), /Practice exceeds description/);
        } finally { s.unmount(); }
    }
    const work = workFixture(); work.curriculum = []; const r = await workSetup('teacher', { work });
    try { assert.equal(r.screen.all(n => n.props['data-work-area'] === 'curriculum').length, 0); } finally { r.screen.unmount(); }
});
test('walk 16: linked plans in server order open marking rows, including retained deleted plans', async () => {
    const r = await workSetup(); const s = r.screen;
    try {
        const a = area(s, 'plans'); assert.ok(a.textContent.indexOf('Oct 9, 2026') < a.textContent.indexOf('Oct 8, 2026'));
        click(s.button('Oct 9, 2026 · Practice objective')); await flush(); assert.match(a.textContent, /Practice student.*Practice learner/);
        assert.equal(areaButton(s, 'plans', 'Save').props.disabled, true); assert.equal(r.calls.filter(c => c.url.endsWith('/marks')).length, 0);
        type(within(a, n => n.props['aria-label'] === 'Comment for Practice student')[0], 'Plan comment'); await flush(); click(areaButton(s, 'plans', 'Save')); await flush(10);
        const saved = r.calls.find(c => c.url.endsWith('/marks')).body;
        assert.equal(saved.lesson_plan_id, 31); assert.deepEqual(saved.marks, [{ group_membership_id: 9, level: null, comment: 'Plan comment', updated_at: null }]);
        click(s.button('Oct 9, 2026 · Practice objective')); await flush(); click(s.button('Oct 8, 2026 · Retained objective')); await flush();
        type(within(a, n => n.props['aria-label'] === 'Comment for Practice learner')[0], 'Retained correction'); await flush(); click(areaButton(s, 'plans', 'Save')); await flush(10);
        const retained = r.calls.filter(c => c.url.endsWith('/marks'))[1].body;
        assert.equal(retained.piece_id, 32); assert.deepEqual(retained.marks, [{ group_membership_id: 10, level: null, comment: 'Retained correction', updated_at: null }]);
    } finally { s.unmount(); }
});
test('walk 17: own piece create/edit/delete count conflict requires another explicit confirmation', async () => {
    let conflict = true;
    const work = workFixture(); work.own_pieces[0].mark_count = 7;
    const r = await workSetup('teacher', { work, write: (m: string, u: string) => { if (m === 'delete' && u.endsWith('/pieces/41') && conflict) { conflict = false; throw httpError(409, { mark_count: 8, message: 'The number of marks changed.' }); } } }); const s = r.screen;
    try {
        click(s.button('Add a piece')); await flush(); type(workField(s, 'Piece title'), 'New piece'); type(workField(s, 'Piece detail'), 'New detail'); submit(within(area(s, 'own'), n => n.tag === 'form')[0]); await flush(10); assert.match(s.text(), /New piece.*New detail/);
        click(workField(s, 'Edit Practice piece')); await flush(); type(workField(s, 'Piece title'), 'Changed piece'); submit(within(area(s, 'own'), n => n.tag === 'form')[0]); await flush(10); assert.match(s.text(), /Changed piece/);
        click(workField(s, 'Delete Changed piece')); await flush(); assert.match(s.text(), /Delete this piece and its 7 marks\?/); click(s.button('Delete piece')); await flush(10);
        assert.match(s.text(), /Delete this piece and its 8 marks\?/); assert.equal(r.calls.filter(c => c.method === 'delete').length, 1);
        click(s.button('Delete piece')); await flush(10); assert.deepEqual(r.calls.filter(c => c.method === 'delete').map(c => c.body), [{ mark_count: 7 }, { mark_count: 8 }]); assert.doesNotMatch(s.text(), /Changed piece/);
    } finally { s.unmount(); }
});
test('walk 18: levels toggle/clear, comment save and reopening retains marks; unmarked remains empty', async () => {
    const r = await workSetup(); const s = r.screen;
    try {
        const block = workField(s, 'Curriculum grade 1'); const buttons = () => within(block, n => n.props['aria-pressed'] !== undefined);
        for (const button of buttons()) { click(button); await flush(); assert.equal(button.props['aria-pressed'], 'true'); click(button); await flush(); assert.equal(button.props['aria-pressed'], 'false'); }
        click(buttons()[1]); type(within(block, n => n.tag === 'textarea')[0], 'Typed comment'); await flush(); click(within(block, n => n.tag === 'button' && n.textContent === 'Save')[0]); await flush(10);
        const saved = r.calls.find(c => c.url.endsWith('/marks')).body; assert.deepEqual(saved.marks, [{ group_membership_id: 9, level: 3, comment: 'Typed comment', updated_at: null }]); assert.equal(saved.week_no, 12);
        await pick(s, 'Roster'); await pick(s, 'Healthful Living'); assert.equal(workField(s, 'Comment for Practice student').value, 'Typed comment');
        const row = s.all(n => n.props['aria-label'] === 'Marks for Practice student')[0]; assert.ok(row); assert.equal(within(row, n => n.props['aria-pressed'] === 'true').length, 1);
    } finally { s.unmount(); }
});
test('work drafts: changing entry or menu line asks before discarding, staying keeps text', async () => {
    const r = await workSetup(); const s = r.screen;
    try {
        type(workField(s, 'Comment for Practice student'), 'Keep this draft'); chooseOption(workField(s, 'Entry for 1st'), 2); await flush();
        assert.match(s.text(), /Discard unsaved changes\?/); click(s.button('Keep editing')); await flush(); assert.equal(workField(s, 'Comment for Practice student').value, 'Keep this draft');
        await pick(s, 'Roster'); assert.match(s.text(), /Discard unsaved changes\?/); click(s.button('Keep editing')); await flush(10); assert.equal(r.route.query.subject, '104');
        chooseOption(workField(s, 'Entry for 1st'), 2); await flush(); click(s.button('Discard changes')); await flush(); assert.match(workField(s, 'Curriculum grade 1').textContent, /Focus 2/); assert.equal(workField(s, 'Comment for Practice student').value, '');
    } finally { s.unmount(); }
});
test('work failed saves: 422 field words, 403/404 and network errors keep typed comments next to the entry', async () => {
    for (const failure of [httpError(422, { errors: { 'marks.0.comment': ['Practice field refusal.'] }, message: 'Validation failed.' }), httpError(403, { message: 'Forbidden.' }), httpError(404, { message: 'Not found.' }), new Error('Network Error')]) {
        const r = await workSetup('teacher', { write: (_m: string, u: string) => { if (u.endsWith('/marks')) throw failure; } }); const s = r.screen;
        try { type(workField(s, 'Comment for Practice student'), 'Still typed'); const b = workField(s, 'Curriculum grade 1'); await flush(); click(within(b, n => n.tag === 'button' && n.textContent === 'Save')[0]); await flush(10); assert.equal(workField(s, 'Comment for Practice student').value, 'Still typed'); assert.match(b.textContent, /Practice field refusal\.|Forbidden\.|Not found\.|Check your connection and try again\./); assert.doesNotMatch(b.textContent, /Network Error/); } finally { s.unmount(); }
    }
});
test('walk 19: copied guide wording stays visible with changed-wording date supplied by server', async () => {
    const work: any = workFixture(); Object.assign(work.curriculum[0].entries[1], { piece_id: 70, title: 'Copied focus', detail: 'Copied assessment', standard_code: 'OLD.1', wording_changed: true, marked_against_date: '2026-10-08', marks: [{ group_membership_id: 9, level: 3, comment: 'Copied comment' }] });
    const r = await workSetup('teacher', { work });
    try { assert.match(r.screen.text(), /Copied focus.*OLD.1.*Copied assessment/); assert.match(r.screen.text(), /Marked against the wording of 2026-10-08/); } finally { r.screen.unmount(); }
});

test('work guards: the dependency, initial read refusal, and a late page response fail closed', async () => {
    for (const failure of [httpError(403, { message: 'Forbidden subject.' }), httpError(404, { message: 'Subject not found.' }), new Error('Network Error')]) {
        const r = await workSetup('teacher', { read: (url: string) => { if (url.endsWith('/work')) throw failure; } });
        try { assert.match(r.screen.text(), /Forbidden subject\.|Subject not found\.|Check your connection/); assert.equal(r.screen.all(n => n.props['data-work-area']).length, 0); } finally { r.screen.unmount(); }
    }
    const off = await workSetup('teacher', { flag: false });
    try { assert.equal(off.calls.filter(c => c.url.endsWith('/work')).length, 0); } finally { off.screen.unmount(); }
    const pending = deferred<any>(); const r = await workSetup('teacher', { read: (url: string) => url.endsWith('/work') ? pending.promise : undefined });
    try { await pick(r.screen, 'Roster'); pending.resolve(ok(workFixture())); await flush(10); assert.doesNotMatch(r.screen.text(), /Practice note|From the curriculum/); } finally { r.screen.unmount(); }
});
test('work forms: failed note/piece saves keep text and show field errors; cancelling deletion makes no write', async () => {
    const r = await workSetup('teacher', { write: (_m: string, u: string) => {
        if (u.endsWith('/notes')) throw httpError(422, { errors: { body: ['Practice note refusal.'] } });
        if (u.endsWith('/pieces')) throw httpError(422, { errors: { title: ['Practice title refusal.'] } });
    } }); const s = r.screen;
    try {
        click(s.button('New note')); await flush(); type(workField(s, 'Note text'), 'Typed note'); submit(within(area(s, 'notes'), n => n.tag === 'form')[0]); await flush(10);
        assert.equal(workField(s, 'Note text').value, 'Typed note'); assert.match(area(s, 'notes').textContent, /Practice note refusal\./);
        click(s.button('Add a piece')); await flush(); type(workField(s, 'Piece title'), 'Typed title'); type(workField(s, 'Piece detail'), 'Typed detail'); submit(within(area(s, 'own'), n => n.tag === 'form')[0]); await flush(10);
        assert.equal(workField(s, 'Piece title').value, 'Typed title'); assert.equal(workField(s, 'Piece detail').value, 'Typed detail'); assert.match(area(s, 'own').textContent, /Practice title refusal\./);
        click(workField(s, 'Delete note: Practice note')); await flush(); click(within(s.all(n => n.props['aria-label'] === 'Confirm note deletion')[0], n => n.tag === 'button' && n.textContent === 'Cancel')[0]); await flush();
        assert.equal(r.calls.filter(c => c.method === 'delete').length, 0);
        await pick(s, 'Roster'); assert.match(s.text(), /Discard unsaved changes\?/); click(s.button('Keep editing')); await flush(10); assert.equal(workField(s, 'Note text').value, 'Typed note');
    } finally { s.unmount(); }
});
test('work saves: another grade draft survives a note or own-piece change and a mark save', async () => {
    const r = await workSetup(); const s = r.screen;
    try {
        type(workField(s, 'Comment for Practice learner'), 'Other grade draft');
        click(s.button('New note')); await flush(); type(workField(s, 'Note text'), 'Class update'); submit(within(area(s, 'notes'), n => n.tag === 'form')[0]); await flush(10);
        assert.equal(workField(s, 'Comment for Practice learner').value, 'Other grade draft');
        click(s.button('Add a piece')); await flush(); type(workField(s, 'Piece title'), 'New task'); submit(within(area(s, 'own'), n => n.tag === 'form')[0]); await flush(10);
        assert.equal(workField(s, 'Comment for Practice learner').value, 'Other grade draft');
        type(workField(s, 'Comment for Practice student'), 'Saved grade one'); const block = workField(s, 'Curriculum grade 1'); await flush(); click(within(block, n => n.tag === 'button' && n.textContent === 'Save')[0]); await flush(10);
        assert.equal(workField(s, 'Comment for Practice learner').value, 'Other grade draft');
    } finally { s.unmount(); }
});
test('work transport: DELETE confirmation count declares JSON despite inherited form encoding, legacy delete has no body', async () => {
    (globalThis as any).window.location = { href: 'https://practice.invalid/class' };
    const { default: axios } = await import('axios');
    const module = await loadTs('core/services/TeacherApiService.ts', {
        axios: { default: axios }, '@/core/constants/appConfigConstants': { API_CONFIG: {}, LOCAL_STORAGE_KEYS: {} },
        '@/core/tenancy/tenantRequests': {}, '@/core/tenancy/teacherSchoolGuard': {}, '@/core/tenancy/teacherSchoolGuardCore': {},
    });
    const oldHeader = axios.defaults.headers.common['Content-Type']; const requests: any[] = [];
    try {
        axios.defaults.headers.common['Content-Type'] = 'application/x-www-form-urlencoded';
        module.default.client = axios.create({ adapter: async (config: any) => { requests.push(config); return { status: 200, data: {}, headers: {}, statusText: 'OK', config }; } });
        await module.default.delete('/api/teacher/practice/pieces/41', { mark_count: 7 });
        assert.equal(requests[0].headers.get('Content-Type'), 'application/json'); assert.deepEqual(JSON.parse(requests[0].data), { mark_count: 7 });
        await module.default.delete('/api/teacher/practice/notes/51'); assert.equal(requests[1].data, undefined);
    } finally { axios.defaults.headers.common['Content-Type'] = oldHeader; }
});

test('work drafts: back and phone menu preserve drafts on cancel; prompt supports Tab and Escape', async () => {
    const r = await workSetup('teacher', { width: 390 }); const s = r.screen;
    try {
        const comment = workField(s, 'Comment for Practice student'); comment.focus(); type(comment, 'Phone draft');
        click(s.button('Class menu')); await flush(); await pick(s, 'Roster');
        const dialog = s.all(n => n.props.role === 'alertdialog')[0]; assert.ok(dialog);
        assert.equal(doc.activeElement, s.button('Keep editing'));
        let prevented = 0; dialog.props.onKeydown({ key: 'Tab', preventDefault() { prevented++; } });
        assert.equal(doc.activeElement, s.button('Discard changes')); assert.equal(prevented, 1);
        dialog.props.onKeydown({ key: 'Escape', preventDefault() {} }); await flush(10);
        assert.equal(workField(s, 'Comment for Practice student').value, 'Phone draft'); assert.equal(r.route.query.subject, '104');
        // An address change from browser history uses the same guard, not only menu clicks.
        await r.router.push({ query: { subject: '102' } }); await flush(10); assert.match(s.text(), /Discard unsaved changes\?/);
        click(s.button('Keep editing')); await flush(10); assert.equal(r.route.query.subject, '104'); assert.equal(workField(s, 'Comment for Practice student').value, 'Phone draft');
    } finally { s.unmount(); }
});
test('work clears: saving empty marks removes a row; an own piece without marks confirms in plain words', async () => {
    const work: any = workFixture(); work.curriculum[0].entries[1].piece_id = 70;
    work.curriculum[0].entries[1].marks = [{ group_membership_id: 9, level: 3, comment: 'Old comment' }];
    const r = await workSetup('teacher', { work }); const s = r.screen;
    try {
        const block = workField(s, 'Curriculum grade 1'); click(within(block, n => n.props['aria-pressed'] === 'true')[0]); type(workField(s, 'Comment for Practice student'), '');
        await flush(); click(within(block, n => n.tag === 'button' && n.textContent === 'Save')[0]); await flush(10);
        assert.deepEqual(r.calls.find(c => c.url.endsWith('/marks')).body.marks, [{ group_membership_id: 9, level: null, comment: null, updated_at: null }]); assert.equal(work.curriculum[0].entries[1].marks.length, 0);
        click(workField(s, 'Delete Practice piece')); await flush(); assert.match(s.text(), /Delete this piece\?/); assert.doesNotMatch(s.text(), /and its 0 marks/);
        click(s.button('Delete piece')); await flush(10); assert.deepEqual(r.calls.find(c => c.method === 'delete').body, { mark_count: 0 });
    } finally { s.unmount(); }
});
test('work refresh failures: a saved note/piece reports list failure locally without discarding another draft', async () => {
    let failNotes = false; let failPieces = false;
    const r = await workSetup('teacher', { read: (u: string) => {
        if (failNotes && u.endsWith('/notes')) throw new Error('Offline notes');
        if (failPieces && u.endsWith('/work')) throw new Error('Offline pieces');
    } }); const s = r.screen;
    try {
        type(workField(s, 'Comment for Practice learner'), 'Preserved draft');
        click(s.button('New note')); await flush(); type(workField(s, 'Note text'), 'Saved note'); failNotes = true; submit(within(area(s, 'notes'), n => n.tag === 'form')[0]); await flush(10);
        assert.match(area(s, 'notes').textContent, /note was changed.*notes could not be reloaded/); assert.equal(workField(s, 'Comment for Practice learner').value, 'Preserved draft');
        failNotes = false; click(areaButton(s, 'notes', 'Reload list')); await flush(10); assert.match(area(s, 'notes').textContent, /Saved note/);
        click(s.button('Add a piece')); await flush(); type(workField(s, 'Piece title'), 'Saved task'); failPieces = true; submit(within(area(s, 'own'), n => n.tag === 'form')[0]); await flush(10);
        assert.match(area(s, 'own').textContent, /piece was changed.*pieces could not be reloaded/); assert.equal(workField(s, 'Comment for Practice learner').value, 'Preserved draft');
    } finally { s.unmount(); }
});

test('Build C office checklist saves zero or several names in shown order with grade hints and failed drafts', async () => {
    const r = await setup('office', { width: 390, data: { class_subject_work_enabled: false }, read: (url: string) => url.endsWith('/subjects')
        ? ok(structuredClone(subjects), { guide_subjects: ['English Language Arts', 'Science'], guide_subject_grades: { 'English Language Arts': ['Grade 1', 'Grade 2'], Science: [] }, tools: [] }) : undefined });
    const s = r.screen;
    try {
        click(exactButton(s, 'Rename Arabic')); await flush();
        assert.match(s.text(), /Grade 1, Grade 2/); assert.match(s.text(), /no entries for this class's grades/);
        const checks = () => s.all(n => n.props['data-guide-subject'] !== undefined);
        assert.equal(checks().length, 2);
        for (const n of [...checks()].reverse()) { n.checked = true; n.props.onChange({ target: n }); } await flush();
        submit(s.all(n => n.props['data-subject-form'] !== undefined)[0]); await flush(10);
        // Ticked Science first, then English Language Arts: the saved order is the order chosen, so the first
        // followed subject (which older screens read) never moves when a later one is added.
        assert.deepEqual(r.calls.find(c => c.method === 'put').body.guide_subjects, ['Science', 'English Language Arts']);
        assert.equal(r.calls.find(c => c.method === 'put').body.guide_subject, 'Science');
        click(exactButton(s, 'Rename Arabic')); await flush();
        for (const n of checks()) { n.checked = false; n.props.onChange({ target: n }); } await flush();
        submit(s.all(n => n.props['data-subject-form'] !== undefined)[0]); await flush(10);
        assert.deepEqual(r.calls.filter(c => c.method === 'put').at(-1).body.guide_subjects, []);
        assert.equal(r.calls.filter(c => c.method === 'put').at(-1).body.guide_subject, null);
        assert.equal(r.calls.some(c => c.url.endsWith('/work')), false);
    } finally { s.unmount(); }
    const refused = await setup('office', { refuse: () => ({ status: 'failed', data: { guide_subjects: ["Choose a subject from this school's curriculum."] } }),
        read: (url: string) => url.endsWith('/subjects') ? ok(structuredClone(subjects), { guide_subjects: ['English Language Arts', 'Science'], guide_subject_grades: { 'English Language Arts': ['Grade 1', 'Grade 2'], Science: [] }, tools: [] }) : undefined });
    try {
        click(exactButton(refused.screen, 'Rename Arabic')); await flush();
        const n = refused.screen.all(n => n.props['data-guide-subject'] === 'Science')[0]; n.checked = true; n.props.onChange({ target: n }); await flush();
        submit(refused.screen.all(n => n.props['data-subject-form'] !== undefined)[0]); await flush(10);
        assert.match(refused.screen.text(), /Choose a subject from this school's curriculum/);
        assert.equal(n.checked, true);
    } finally { refused.screen.unmount(); }
});

test('Build C overlapping guide numbers select and save their own subject and show guide headings', async () => {
    const work: any = workFixture();
    work.curriculum = [{ ...work.curriculum[0], grade_label: 'Grade 1', opening_guide_subject: 'Joint studies', selected_guide_subject: 'Joint studies', opening_week_no: 12, selected_week_no: 12,
        entries: [{ ...workEntry(12), guide_subject: 'Science' }, { ...workEntry(12), guide_subject: 'Joint studies' }] }];
    const r = await workSetup('teacher', { work }); const s = r.screen;
    try {
        const select = workField(s, 'Entry for Grade 1');
        assert.deepEqual(select.children.filter(n => n.tag === 'option').map(n => n.textContent), ['12 · Focus 12 — Science', '12 · Focus 12 — Joint studies']);
        assert.match(workField(s, 'Curriculum grade 1').textContent, /Grade 1/);
        type(workField(s, 'Comment for Practice student'), 'Joint comment'); await flush(); click(areaButton(s, 'curriculum', 'Save')); await flush(10);
        assert.equal(r.calls.find(c => c.url.endsWith('/marks')).body.guide_subject, 'Joint studies');
        chooseOption(select, select.children.filter(n => n.tag === 'option')[0].props.value); await flush();
        assert.match(workField(s, 'Curriculum grade 1').textContent, /12 · Focus 12 — Science/);
        assert.equal(workField(s, 'Comment for Practice student').value, '');
    } finally { s.unmount(); }
});

test('Build C no-following office advice is displayed without a teacher curriculum block', async () => {
    const work: any = workFixture(); work.curriculum = []; work.curriculum_empty_message = 'This subject follows no curriculum. Choose one under Class subjects.';
    for (const realm of ['office', 'teacher'] as const) {
        const r = await workSetup(realm, { work });
        try { if (realm === 'office') assert.match(r.screen.text(), /This subject follows no curriculum\. Choose one under Class subjects\./); else assert.doesNotMatch(r.screen.text(), /This subject follows no curriculum/); }
        finally { r.screen.unmount(); }
    }
});

test('Build C a grade with one guide keeps focus-only heading and chooser words', async () => {
    const work: any = workFixture();
    work.curriculum = [{ ...work.curriculum[0], grade_label: 'Grade 1', opening_guide_subject: 'Science', selected_guide_subject: 'Science', entries: work.curriculum[0].entries.map((entry: any) => ({ ...entry, guide_subject: 'Science' })) }];
    const r = await workSetup('teacher', { work });
    try {
        const select = workField(r.screen, 'Entry for Grade 1');
        assert.deepEqual(select.children.filter(n => n.tag === 'option').map(n => n.textContent), ['2 · Focus 2', '12 · Focus 12']);
        const heading = r.screen.all(n => n.tag === 'p' && String(n.props.class).includes('fw-semibold'))[0];
        assert.equal(heading.textContent, 'Focus 12');
        assert.equal(r.screen.all(n => n.tag === 'h4' && n.textContent === 'Grade 1').length, 1);
    } finally { r.screen.unmount(); }
});


test('Build D saved marks reach the parent before a pending wording read for guide plan and own piece', async () => {
    for (const kind of ['guide', 'plan', 'own']) {
        const pending = deferred<any>(); let reads = 0;
        const r = await workSetup('teacher', { read: (u: string) => { if (u.endsWith('/work') && ++reads === 2) return pending.promise; } });
        const s = r.screen;
        try {
            if (kind === 'plan') { click(s.button('Oct 9, 2026 · Practice objective')); await flush(); }
            if (kind === 'own') { click(s.button('Practice piece')); await flush(); }
            const container = () => kind === 'guide' ? workField(s, 'Curriculum grade 1') : area(s, kind === 'plan' ? 'plans' : 'own');
            type(within(container(), n => n.props['aria-label'] === 'Comment for Practice student')[0], 'Saved before reload');
            await flush(); click(within(container(), n => n.tag === 'button' && n.textContent === 'Save')[0]); await flush(10);
            if (kind === 'guide') { chooseOption(workField(s, 'Entry for 1st'), 2); await flush(); chooseOption(workField(s, 'Entry for 1st'), 12); }
            else { click(s.button(kind === 'plan' ? 'Oct 9, 2026 · Practice objective' : 'Practice piece')); await flush(); click(s.button(kind === 'plan' ? 'Oct 9, 2026 · Practice objective' : 'Practice piece')); }
            await flush(10);
            assert.equal(within(container(), n => n.props['aria-label'] === 'Comment for Practice student')[0].value, 'Saved before reload');
            assert.equal(within(container(), n => n.tag === 'button' && n.textContent === 'Save')[0].props.disabled, true);
            type(within(container(), n => n.props['aria-label'] === 'Comment for Practice student')[0], 'Second save');
            await flush(); click(within(container(), n => n.tag === 'button' && n.textContent === 'Save')[0]); await flush(10);
            assert.equal(r.calls.filter(c => c.url.endsWith('/marks'))[1].body.piece_id, kind === 'own' ? 41 : 70);
            assert.equal(r.calls.filter(c => c.url.endsWith('/marks'))[1].body.marks[0].updated_at, '2026-10-09T16:00:00.000000Z');
            pending.resolve(ok(workFixture())); await flush(10);
            assert.equal(within(container(), n => n.props['aria-label'] === 'Comment for Practice student')[0].value, 'Second save');
        } finally { s.unmount(); pending.resolve(ok(workFixture())); }
    }
});

test('Build D Save is disabled until a row differs and sends only changed students with their loaded timestamp', async () => {
    const work: any = workFixture(); work.curriculum = [];
    work.own_pieces[0].marks = [{ group_membership_id: 9, level: 3, comment: 'Existing', updated_at: '2026-10-09T14:00:00.000000Z' }]; work.own_pieces[0].mark_count = 1;
    const r = await workSetup('teacher', { work }); const s = r.screen;
    try {
        click(s.button('Practice piece')); await flush();
        assert.equal(areaButton(s, 'own', 'Save').props.disabled, true);
        type(workField(s, 'Comment for Practice student'), 'Temporary'); type(workField(s, 'Comment for Practice student'), 'Existing'); await flush();
        assert.equal(areaButton(s, 'own', 'Save').props.disabled, true);
        type(workField(s, 'Comment for Practice learner'), 'Only changed row'); await flush(); click(areaButton(s, 'own', 'Save')); await flush(10);
        assert.deepEqual(r.calls.find(c => c.url.endsWith('/marks')).body.marks, [{ group_membership_id: 10, level: null, comment: 'Only changed row', updated_at: null }]);
        assert.equal(workField(s, 'Comment for Practice student').value, 'Existing');
        type(workField(s, 'Comment for Practice student'), 'Updated existing'); await flush(); click(areaButton(s, 'own', 'Save')); await flush(10);
        assert.equal(r.calls.filter(c => c.url.endsWith('/marks'))[1].body.marks[0].updated_at, '2026-10-09T14:00:00.000000Z');
    } finally { s.unmount(); }
});

test('Build D stale mark conflicts name only affected rows and preserve typing until Reload is chosen', async () => {
    let reload = false; const pending = deferred<any>();
    const work: any = workFixture(); work.curriculum = [];
    const r = await workSetup('teacher', { work, read: (u: string) => reload && u.endsWith('/work') ? pending.promise : undefined,
        write: (_m: string, u: string) => { if (u.endsWith('/marks')) throw httpError(409, { message: 'Marks changed.', students: [{ group_membership_id: 10, name: 'Practice learner' }] }); } });
    const s = r.screen;
    try {
        click(s.button('Practice piece')); await flush(); type(workField(s, 'Comment for Practice learner'), 'My correction'); await flush(); click(areaButton(s, 'own', 'Save')); await flush(10);
        const rows = within(area(s, 'own'), n => n.props.role === 'group');
        assert.doesNotMatch(rows[0].textContent, /Someone else changed/);
        assert.match(rows[1].textContent, /Someone else changed this mark\. Reload to see it\./);
        assert.equal(workField(s, 'Comment for Practice learner').value, 'My correction');
        reload = true; click(within(rows[1], n => n.tag === 'button' && n.textContent === 'Reload')[0]); await flush();
        assert.equal(workField(s, 'Comment for Practice learner').value, 'My correction');
        work.own_pieces[0].marks = [{ group_membership_id: 10, level: 4, comment: 'Other teacher', updated_at: '2026-10-09T15:00:00.000000Z' }];
        pending.resolve(ok(work)); await flush(10);
        assert.equal(workField(s, 'Comment for Practice learner').value, 'Other teacher'); assert.doesNotMatch(area(s, 'own').textContent, /Someone else changed/);
    } finally { s.unmount(); pending.resolve(ok(work)); }
});

test('Build D deleting an edited note or own piece keeps the correction through cancellation and drops it on successful delete', async () => {
    for (const kind of ['note', 'piece']) {
        const r = await workSetup(); const s = r.screen;
        try {
            click(workField(s, kind === 'note' ? 'Edit note: Practice note' : 'Edit Practice piece')); await flush();
            type(workField(s, kind === 'note' ? 'Note text' : 'Piece title'), 'Unsaved correction');
            click(workField(s, kind === 'note' ? 'Delete note: Practice note' : 'Delete Practice piece')); await flush();
            assert.match(s.text(), kind === 'note' ? /Delete this note and the correction you were typing\?/ : /correction you were typing/);
            const confirm = workField(s, kind === 'note' ? 'Confirm note deletion' : 'Confirm piece deletion');
            click(within(confirm, n => n.tag === 'button' && n.textContent === 'Cancel')[0]); await flush();
            assert.equal(workField(s, kind === 'note' ? 'Note text' : 'Piece title').value, 'Unsaved correction');
            click(workField(s, kind === 'note' ? 'Delete note: Practice note' : 'Delete Practice piece')); await flush(); click(s.button(kind === 'note' ? 'Delete note' : 'Delete piece')); await flush(10);
            assert.equal(s.all(n => n.props['aria-label'] === (kind === 'note' ? 'Note text' : 'Piece title')).length, 0);
            await pick(s, 'Roster'); assert.doesNotMatch(s.text(), /Discard unsaved/);
        } finally { s.unmount(); }
    }
});

test('Build D field validation maps sent indices to students and ties each field message to its control', async () => {
    const work: any = workFixture(); work.curriculum = [];
    const r = await workSetup('teacher', { work, write: (_m: string, u: string) => {
        if (u.endsWith('/marks')) throw httpError(422, { errors: { 'marks.0.comment': ['Comment refusal'], 'marks.0.level': ['Level refusal'], marks: ['General refusal'] } });
        if (u.endsWith('/notes')) throw httpError(422, { data: { body: ['Note refusal'] } });
        if (u.endsWith('/pieces')) throw httpError(422, { data: { title: ['Title refusal'], detail: ['Detail refusal'] } });
    } }); const s = r.screen;
    const linked = (control: Node, words: string) => {
        assert.equal(control.props['aria-invalid'], 'true');
        const id = control.props['aria-describedby']; assert.ok(id);
        const message = s.all(n => n.props.id === id); assert.equal(message.length, 1); assert.equal(message[0].textContent, words);
    };
    try {
        click(s.button('Practice piece')); await flush(); type(workField(s, 'Comment for Practice learner'), 'Mine'); await flush(); click(areaButton(s, 'own', 'Save')); await flush(10);
        linked(workField(s, 'Comment for Practice learner'), 'Comment refusal');
        linked(workField(s, '4 Exceeds for Practice learner'), 'Level refusal');
        assert.equal(workField(s, 'Comment for Practice student').props['aria-invalid'], undefined);
        const rows = within(area(s, 'own'), n => n.props.role === 'group'); assert.match(rows[1].textContent, /Comment refusal/); assert.doesNotMatch(rows[0].textContent, /refusal/);
        assert.ok(s.all(n => n.props.role === 'alert' && n.textContent === 'General refusal').length);
        click(s.button('New note')); await flush(); type(workField(s, 'Note text'), 'Mine'); submit(within(area(s, 'notes'), n => n.tag === 'form')[0]); await flush(10); linked(workField(s, 'Note text'), 'Note refusal');
        click(s.button('Add a piece')); await flush(); type(workField(s, 'Piece title'), 'Mine'); submit(within(area(s, 'own'), n => n.tag === 'form')[0]); await flush(10);
        linked(workField(s, 'Piece title'), 'Title refusal'); linked(workField(s, 'Piece detail'), 'Detail refusal');
    } finally { s.unmount(); }
});

test('Build D phone targets are scoped locally including help used in Grades and Reports with work OFF', () => {
    for (const file of ['SubjectMarkEditor', 'SubjectNotes', 'SubjectOwnPieces', 'SubjectWorkPage', 'SubjectCurriculumBlock']) {
        const source = readFileSync(new URL(`../views/teacher/subject/${file}.vue`, import.meta.url), 'utf8');
        const css = source.match(/<style scoped>([\s\S]*?)<\/style>/)?.[1] ?? '';
        assert.match(css, /@media\s*\(max-width:\s*767px\)/, file);
        assert.match(css, /button, input, select, textarea\s*\{[^}]*min-height:\s*44px;[^}]*min-width:\s*44px;/, file);
    }
    const help = readFileSync(new URL('../components/classes/PerformanceLevelHelp.vue', import.meta.url), 'utf8');
    assert.match(help, /<style scoped>[\s\S]*summary\s*\{[^}]*min-height:\s*44px/);
    const teacher = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');
    const grades = teacher.slice(teacher.indexOf("activeTab === 'grades'"), teacher.indexOf("activeTab === 'reports'"));
    const reports = teacher.slice(teacher.indexOf("activeTab === 'reports'"), teacher.indexOf('<script'));
    assert.match(grades, /<PerformanceLevelHelp v-if="openAssignment.scale === 'levels'" :levels="levelKey" \/>/);
    assert.match(reports, /<PerformanceLevelHelp :levels="levelKey" \/>/);
    assert.doesNotMatch(help, /class_subject_work|workEnabled/, 'summary target does not depend on the work switch');
});

test('walk fixes: a pressed level is drawn filled, plan dates and note times read as dates, and labels name the thing', () => {
    const read = (file: string) => readFileSync(new URL('../views/teacher/subject/' + file, import.meta.url), 'utf8');
    const editor = read('SubjectMarkEditor.vue');
    // Mounted tests cannot see paint: pin the rule that fills the chosen level and the phone gap between levels.
    assert.match(editor, /\.mark-levels button\[aria-pressed="true"\] \{[^}]*background-color:[^}]*color: #fff/);
    assert.match(editor, /\.mark-levels \{ display: flex; gap: \.5rem; \}/);
    assert.match(editor, /The guide has since changed\./);
    const helpers = read('subjectWork.ts');
    assert.doesNotMatch(helpers, /error\?\.message \?\? ''/);
    assert.doesNotMatch(read('SubjectNotes.vue'), /note \$\{note\.id\}/);
    assert.doesNotMatch(read('SubjectOwnPieces.vue'), /piece \$\{piece\.piece_id\}/);
});

test('walk fixes: a save that returns after the teacher moved to another entry updates the entry that was saved', async () => {
    const pending = deferred<any>();
    const r = await workSetup('teacher', { write: (method: string, url: string) => { if (method === 'put' && url.endsWith('/marks')) return pending.promise; } });
    const s = r.screen;
    const comment = () => within(workField(s, 'Curriculum grade 1'), n => n.props['aria-label'] === 'Comment for Practice student')[0];
    try {
        type(comment(), 'Saved late'); await flush();
        click(within(workField(s, 'Curriculum grade 1'), n => n.tag === 'button' && n.textContent === 'Save')[0]); await flush();
        // The request is still in flight. The teacher picks another entry and discards the prompt.
        chooseOption(workField(s, 'Entry for 1st'), 2); await flush();
        click(s.button('Discard changes')); await flush(10);
        assert.equal(comment().value, '', 'the other entry opens empty');
        pending.resolve(ok({ piece_id: 70, marks: [{ group_membership_id: 9, updated_at: '2026-10-09T16:00:00.000000Z' }] })); await flush(10);
        assert.equal(comment().value, '', 'the entry now on screen was not overwritten by the late save');
        chooseOption(workField(s, 'Entry for 1st'), 12); await flush(10);
        assert.equal(comment().value, 'Saved late', 'the saved entry shows what was saved');
        assert.equal(within(workField(s, 'Curriculum grade 1'), n => n.tag === 'button' && n.textContent === 'Save')[0].props.disabled, true);
    } finally { s.unmount(); pending.resolve(ok({ piece_id: 70, marks: [] })); }
});

test('final review: an entry reopened before its save returns shows what was saved and can be saved again', async () => {
    const pending = deferred<any>(); let puts = 0;
    const r = await workSetup('teacher', { write: (method: string, url: string) => { if (method === 'put' && url.endsWith('/marks') && ++puts === 1) return pending.promise; } });
    const s = r.screen;
    const block = () => workField(s, 'Curriculum grade 1');
    const comment = () => within(block(), n => n.props['aria-label'] === 'Comment for Practice student')[0];
    const saveButton = () => within(block(), n => n.tag === 'button' && n.textContent === 'Save')[0];
    try {
        type(comment(), 'Saved late'); await flush(); click(saveButton()); await flush();
        chooseOption(workField(s, 'Entry for 1st'), 2); await flush(); click(s.button('Discard changes')); await flush(10);
        // Back on the first entry while its save is still in flight: a fresh editor on the same piece.
        chooseOption(workField(s, 'Entry for 1st'), 12); await flush(10);
        pending.resolve(ok({ piece_id: 70, marks: [{ group_membership_id: 9, updated_at: '2026-10-09T16:00:00.000000Z' }] })); await flush(10);
        assert.equal(comment().value, 'Saved late', 'the reopened editor takes what the page was told');
        assert.equal(saveButton().props.disabled, true);
        type(comment(), 'Edited after'); await flush(); click(saveButton()); await flush(10);
        const second = r.calls.filter(c => c.url.endsWith('/marks'))[1].body;
        assert.equal(second.piece_id, 70);
        assert.equal(second.marks[0].updated_at, '2026-10-09T16:00:00.000000Z', 'the next save carries the saved version, not a stale one');
    } finally { s.unmount(); pending.resolve(ok({ piece_id: 70, marks: [] })); }
});

test('final review: source pins for the conflict reload, the unfollowed line and the manager payload', () => {
    const editor = readFileSync(new URL('../views/teacher/subject/SubjectMarkEditor.vue', import.meta.url), 'utf8');
    assert.match(editor, /readPiece\(shown\.value\.piece_id \?\? conflictPiece\.value\)/);
    assert.match(editor, /This subject no longer follows \{\{ shown\.guide_subject \}\}\. Its marks stay here\./);
    assert.match(editor, /shown\.moved_to \?\? 'another subject'/);
    const manager = readFileSync(new URL('../components/classes/ClassSubjectManager.vue', import.meta.url), 'utf8');
    // Renaming alone sends no curriculum choice; a changed tick keeps the saved order.
    assert.match(manager, /if \(guideTouched\.value\) \{\s*payload\.guide_subjects = form\.value\.guide_subjects\.filter/);
});
