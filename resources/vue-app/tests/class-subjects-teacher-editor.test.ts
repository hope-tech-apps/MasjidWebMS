import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { createPinia, setActivePinia } from 'pinia';
import { mountSfc, flush, click, check, type, submit, Node, httpError, withDocumentKeys, deferred } from './support/mountSfc.ts';
import * as teacherForm from '../core/helpers/teacherForm.ts';
import { realClassModules } from './support/classSubjectModules.ts';

withDocumentKeys();
(globalThis as any).document.body = { style: {} };
(globalThis as any).window = { innerWidth: 1280, addEventListener() {}, removeEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }) };
(Node.prototype as any).__v_skip = true;
Object.defineProperty(Node.prototype, 'style', { get() { return (this as any)._style ??= {}; } });
const ok = (data: any, meta: any = {}) => ({ data: { status: 'success', data, meta } });
const math = { id: 101, name: 'Mathematics', position: 0, hidden_at: null };
const science = { id: 102, name: 'Science', position: 1, hidden_at: null };
const hidden = { id: 103, name: 'Reading', position: 2, hidden_at: '2026-10-08' };
const classes = [{ id: 2, name: 'Grade 2' }, { id: 3, name: 'Grade 3' }];
const button = (s: any, text: string) => { const bs = s.all((n: Node) => n.tag === 'button' && (n.textContent === text || n.props['aria-label'] === text || n.props.title === text)); const b = bs.find((n: Node) => n.props.type === 'submit') ?? bs[0]; assert.ok(b, text + ': ' + s.text()); return b; };
const input = (s: any, id: string) => { const n = s.all((n: Node) => n.tag === 'input' && n.props.id === id)[0]; assert.ok(n, id + ': ' + s.text()); return n; };
async function setup(options: any = {}) {
    setActivePinia(createPinia());
    const calls: any[] = [];
    const detail = { id: 7, name: 'Practice Teacher', email: 'teacher@example.invalid', phone: '', class_ids: [2], class_subjects: { 2: ['arabic'] }, class_subject_ids: { 2: [101] }, ...options.detail };
    const teacher = { ...detail, invited: false, classes: options.teacherClasses ?? [{ ...classes[0], class_subject_ids: [101], class_subject_names: [math] }] };
    const masjid = { id: 1, capabilities: { class_subjects: options.on !== false } };
    const api = {
        get: async (url: string) => {
            calls.push({ method: 'get', url });
            if (options.get) { const r = options.get(url); if (r !== undefined) return r; }
            if (url.endsWith('/teachers')) return ok([teacher], { classes });
            if (url.endsWith('/teachers/7')) return ok(detail);
            if (url.endsWith('/groups/2/subjects')) return ok(options.catalog ?? [science, hidden, math]);
            if (url.endsWith('/groups/3/subjects')) return ok([{ id: 201, name: 'History', position: 0, hidden_at: null }]);
            throw new Error(url);
        },
        post: async (url: string, body: any) => { calls.push({ method: 'post', url, body: JSON.parse(JSON.stringify(body)) }); if (options.refusal) throw options.refusal; return ok(teacher); },
        put: async (url: string, body: any) => { calls.push({ method: 'put', url, body: JSON.parse(JSON.stringify(body)) }); if (options.refusal) throw options.refusal; return ok(teacher); },
    };
    const overrides = {
        '@/core/helpers/teacherForm': teacherForm,
        '@/core/services/ApiService': { default: api },
        '@/core/constants/appConfigConstants': { LOCAL_STORAGE_KEYS: {} },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid, orgType: 'school', term: (key: string) => key === 'groups' ? 'Classrooms' : key }) },
        '../masjidStore': { useMasjidStore: () => ({ masjid }) },
        sweetalert2: { default: { fire: async () => ({}), mixin: () => ({ fire: async () => ({}) }) } },
    };
    const file = 'views/dashboard/TeachersView.vue';
    const modules = await realClassModules(file, overrides);
    const s = await mountSfc(file, {}, modules); await flush(12);
    const store = modules['@/stores/masjid/teachersStore'].useTeachersStore();
    return { s, calls, store, async add() { click(button(s, 'Add Teacher')); await flush(); type(s.all((n: Node) => n.tag === 'input' && n.props.type === 'text')[0], 'Practice Teacher'); type(s.all((n: Node) => n.tag === 'input' && n.props.type === 'email')[0], 'new@example.invalid'); await flush(); }, async edit() { click(button(s, 'Edit teacher')); await flush(12); }, async save() { submit(s.all((n: Node) => n.tag === 'form')[0]); await flush(12); } };
}

test('OFF mounted real children keep three fixed subjects and exact create/update HTTP bodies', async () => {
    const { s, calls, add, edit, save } = await setup({ on: false });
    try {
        await add(); check(input(s, 'class_2'), true); await flush();
        for (const key of ['quran', 'arabic', 'islamic_studies']) assert.ok(input(s, `subj_2_${key}`));
        check(input(s, 'subj_2_quran'), true); await flush(); await save();
        assert.deepEqual(calls.find(c => c.method === 'post').body, { name: 'Practice Teacher', email: 'new@example.invalid', class_ids: [2], class_subjects: { 2: ['quran'] } });
        await edit(); await save();
        assert.deepEqual(calls.find(c => c.method === 'put').body, { name: 'Practice Teacher', class_ids: [2], class_subjects: { 2: ['arabic'] } });
        assert.equal(calls.filter(c => c.url.endsWith('/subjects')).length, 0);
    } finally { s.unmount(); }
});

test('ON ticking loads only that class in order and requires an explicit choice before JSON submission', async () => {
    const { s, calls, add, save } = await setup();
    try {
        await add(); assert.equal(calls.filter(c => c.url.endsWith('/subjects')).length, 0);
        check(input(s, 'class_2'), true); await flush(12);
        assert.equal(calls.filter(c => c.url.endsWith('/subjects')).length, 1);
        assert.match(s.text(), /Choose subjects for this class, or choose All subjects\./);
        assert.ok(button(s, 'Add Teacher').disabled); await save(); assert.equal(calls.filter(c => c.method === 'post').length, 0);
        assert.ok(s.text().indexOf('Mathematics') < s.text().lastIndexOf('Science'));
        assert.equal(s.all((n: Node) => n.props.id === 'subject_2_103').length, 0);
        check(input(s, 'subject_2_101'), true); await flush(); await save();
        assert.deepEqual(calls.find(c => c.method === 'post').body, { name: 'Practice Teacher', email: 'new@example.invalid', class_ids: [2], class_subject_ids: { 2: [101] } });
    } finally { s.unmount(); }
});

for (const [label, ids, choice, expected] of [
    ['edit limit', [101], 'subject_2_102', [101, 102]],
    ['all subjects', [101], 'subjects_all_2', null],
    ['no subjects', null, 'subjects_none_2', []],
    ['hidden retained', [103], 'subject_2_101', [101, 103]],
] as any[]) test(`ON ${label}: existing choice loads on expansion and changes round-trip`, async () => {
    const { s, calls, edit, save } = await setup({ detail: { class_subject_ids: { 2: ids } } });
    try {
        await edit(); assert.equal(calls.filter(c => c.url.endsWith('/subjects')).length, 0);
        click(button(s, 'Subjects for Grade 2')); await flush(12);
        if (label === 'hidden retained') { assert.match(s.text(), /Reading \(hidden\)/); assert.equal((input(s, 'subject_2_103') as any).checked, true); }
        check(input(s, choice), true); await flush(); await save();
        assert.deepEqual(calls.find(c => c.method === 'put').body, { name: 'Practice Teacher', class_ids: [2], class_subject_ids: { 2: expected } });
    } finally { s.unmount(); }
});

test('ON unchanged hidden limit is omitted, class untick removes its map and retick needs an explicit choice', async () => {
    const { s, calls, edit, save } = await setup({ detail: { class_ids: [2, 3], class_subject_ids: { 2: [103], 3: null } } });
    try {
        await edit(); check(input(s, 'class_3'), false); await flush(); await save();
        assert.deepEqual(calls.find(c => c.method === 'put').body, { name: 'Practice Teacher', class_ids: [2], class_subject_ids: {} });
        await edit(); check(input(s, 'class_2'), false); await flush(); check(input(s, 'class_2'), true); await flush(12);
        assert.ok(button(s, 'Save Changes').disabled);
    } finally { s.unmount(); }
});

test('ON two distinct catalogs, including one subject, cache on expand and remove unticked classes', async () => {
    const { s, calls, add, save } = await setup();
    try {
        await add(); check(input(s, 'class_2'), true); await flush(12); check(input(s, 'subject_2_101'), true); await flush();
        check(input(s, 'class_3'), true); await flush(12); assert.ok(input(s, 'subject_3_201')); assert.equal(s.all((n: Node) => n.props.id === 'subject_3_101').length, 0);
        check(input(s, 'subject_3_201'), true); await flush();
        click(button(s, 'Subjects for Grade 2')); await flush(); click(button(s, 'Subjects for Grade 2')); await flush(12);
        assert.equal(calls.filter(c => c.url.endsWith('/groups/2/subjects')).length, 1);
        check(input(s, 'class_2'), false); await flush(); await save();
        assert.deepEqual(calls.find(c => c.method === 'post').body.class_subject_ids, { 3: [201] });
    } finally { s.unmount(); }
});

test('ON shows every refusal verbatim against its class and preserves the editor', async () => {
    const { s, add, save } = await setup({ refusal: httpError(422, { status: 'failed', data: { 'class_subject_ids.2.0': ['Choose subjects belonging to the named class in this school.', 'The selected subject was refused.'] } }) });
    try {
        await add(); check(input(s, 'class_2'), true); await flush(12); check(input(s, 'subjects_all_2'), true); await flush(); await save();
        const error = s.all((n: Node) => n.props['data-class-subject-error'] === 2)[0]; assert.ok(error);
        assert.match(error.textContent, /Choose subjects belonging to the named class in this school\. The selected subject was refused\./);
        assert.ok(s.all((n: Node) => n.tag === 'form').length);
    } finally { s.unmount(); }
});

test('ON list names: null, empty, ordered list and hidden subjects', async () => {
    const { s } = await setup({ teacherClasses: [
        { id: 2, name: 'All class', class_subject_ids: null, class_subject_names: null },
        { id: 3, name: 'None class', class_subject_ids: [], class_subject_names: [] },
        { id: 4, name: 'Limited class', class_subject_ids: [102, 101], class_subject_names: [math, science] },
        { id: 5, name: 'Hidden class', class_subject_ids: [103], class_subject_names: [hidden] },
    ] });
    try { for (const words of ['All class', 'All subjects', 'None class', 'No subjects', 'Mathematics, Science', 'Reading (hidden)']) assert.ok(s.text().includes(words), words); }
    finally { s.unmount(); }
});

test('Classrooms real-child mounted labels follow ON limits and retain OFF legacy names', async () => {
    for (const on of [true, false]) {
        setActivePinia(createPinia());
        const rows = [{ id: 2, name: 'Grade 2', kind: 'class', is_active: true, participants_count: 0, teachers: [
            { id: 7, name: 'All Teacher', subjects: [{ value: 'arabic', label: 'Arabic' }], class_subject_ids: null, class_subject_names: null },
            { id: 8, name: 'None Teacher', subjects: null, class_subject_ids: [], class_subject_names: [] },
            { id: 9, name: 'Limited Teacher', subjects: null, class_subject_ids: [102, 101], class_subject_names: [science, math] },
            { id: 10, name: 'Hidden Teacher', subjects: null, class_subject_ids: [103], class_subject_names: [hidden] },
        ] }];
        const store = vue.reactive<any>({ groupsPaginated: { data: rows, current_page: 1, per_page: 15, total: 1 }, groupsMeta: { kinds: ['class'], roles: [], group_label: 'Classrooms' }, fetchGroups: async () => {} });
        const file = 'views/dashboard/GroupsView.vue';
        const overrides = {
            '@/stores/masjid/groupsStore': { useGroupsStore: () => store },
            '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1, capabilities: { class_subjects: on } }, orgType: 'school', term: () => 'Classrooms' }) },
            '@/stores/authStore': { useAuthStore: () => ({}) },
            '@/core/services/ApiService': { default: { get: async () => ok([]) } },
            '@/core/constants/appConfigConstants': { LOCAL_STORAGE_KEYS: {} },
            sweetalert2: { default: { fire: async () => ({}), mixin: () => ({ fire: async () => ({}) }) } },
        };
        const modules = await realClassModules(file, overrides);
    const s = await mountSfc(file, {}, modules); await flush(12);

        try {
            const cell = s.all((n: Node) => n.tag === 'td' && String(n.props.class ?? '').includes('class-teachers'))[0]; assert.ok(cell);
            if (on) for (const words of ['All subjects', 'No subjects', 'Mathematics, Science', 'Reading (hidden)']) assert.ok(cell.textContent.includes(words), words);
            else { assert.ok(cell.textContent.includes('Arabic')); assert.ok(!cell.textContent.includes('No subjects')); assert.ok(!cell.textContent.includes('Reading (hidden)')); }
        } finally { s.unmount(); }
    }
});

test('catalog error words and Retry work without choosing all; a reopened modal starts a new cache', async () => {
    let reads = 0;
    const { s, calls, add } = await setup({ get: (url: string) => {
        if (!url.endsWith('/groups/2/subjects')) return;
        reads++; if (reads === 1) throw httpError(403, { message: 'This class is not available.' });
        return ok([math]);
    } });
    try {
        await add(); check(input(s, 'class_2'), true); await flush(12);
        assert.match(s.text(), /This class is not available\./); assert.ok(button(s, 'Add Teacher').disabled);
        click(button(s, 'Retry')); await flush(12); assert.ok(input(s, 'subject_2_101'));
        click(button(s, 'Cancel')); await flush(); await add(); check(input(s, 'class_2'), true); await flush(12);
        assert.equal(calls.filter(c => c.url.endsWith('/subjects')).length, 3);
        assert.ok(button(s, 'Add Teacher').disabled);
    } finally { s.unmount(); }
});

test('a catalog finishing after untick can serve a retick, while a new modal ignores the old choice', async () => {
    const answer = deferred(); let first = true;
    const { s, calls, add } = await setup({ get: (url: string) => {
        if (url.endsWith('/groups/2/subjects') && first) { first = false; return answer.promise; }
    } });
    try {
        await add(); check(input(s, 'class_2'), true); await flush(); check(input(s, 'class_2'), false); await flush(); check(input(s, 'class_2'), true); await flush();
        answer.resolve(ok([math])); await flush(12); assert.ok(input(s, 'subject_2_101')); assert.equal(calls.filter(c => c.url.endsWith('/subjects')).length, 1);
        check(input(s, 'subject_2_101'), true); await flush(); click(button(s, 'Cancel')); await flush(); await add();
        check(input(s, 'class_2'), true); await flush(12); assert.ok(button(s, 'Add Teacher').disabled);
    } finally { s.unmount(); }
});

test('ON late teacher detail cannot replace the next modal and its explicit choices', async () => {
    const answer = deferred();
    const { s, add, calls, save } = await setup({ get: (url: string) => url.endsWith('/teachers/7') ? answer.promise : undefined });
    try {
        click(button(s, 'Edit teacher')); await flush(); click(button(s, 'Cancel')); await flush(); await add();
        check(input(s, 'class_2'), true); await flush(12); check(input(s, 'subjects_all_2'), true); await flush();
        answer.resolve(ok({ id: 7, name: 'Old Teacher', email: 'old@example.invalid', phone: '', class_ids: [3], class_subject_ids: { 3: [201] } })); await flush(12);
        assert.equal((input(s, 'class_2') as any).checked, true); assert.equal((input(s, 'class_3') as any).checked, false);
        await save(); assert.equal(calls.find(c => c.method === 'post').body.name, 'Practice Teacher');
    } finally { s.unmount(); }
});


test('real Teachers store chooses its wire contract by school capability, including mixed caller fields', async () => {
    for (const on of [true, false]) {
        const { s, calls, store } = await setup({ on });
        try {
            const body = { name: 'Practice Teacher', email: 'new@example.invalid', phone: '', class_ids: [2], class_subjects: { 2: ['arabic'] }, class_subject_ids: { 2: [101] } };
            await store.createTeacher(body); await store.updateTeacher(7, body);
            for (const call of calls.filter(c => ['post', 'put'].includes(c.method))) {
                assert.deepEqual(on ? call.body.class_subject_ids : call.body.class_subjects, on ? { 2: [101] } : { 2: ['arabic'] });
                assert.ok(!((on ? 'class_subjects' : 'class_subject_ids') in call.body));
            }
            if (on) { await store.createTeacher({ ...body, class_subject_ids: undefined }); assert.ok(!('class_subjects' in calls.at(-1).body)); }
        } finally { s.unmount(); }
    }
});
