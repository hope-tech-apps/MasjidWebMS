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
    const api: any = { get, post: (u: string, b: any) => write('post', u, b), put: (u: string, b: any) => write('put', u, b), delete: (u: string) => write('delete', u, undefined), blobUrl: async () => 'blob:practice' };
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
        sweetalert2: { default: { fire: async (notice: any) => { alerts.push(notice); return { isConfirmed: false }; }, mixin: () => ({ fire: async () => ({ isConfirmed: false }) }) } },
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
