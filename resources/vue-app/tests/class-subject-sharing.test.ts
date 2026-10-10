import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { check, click, flush, loadTs, mountSfc, submit, type } from './support/mountSfc.ts';
import { realClassModules } from './support/classSubjectModules.ts';

(globalThis as any).window = { addEventListener() {}, removeEventListener() {} };
const students = [{ id: 9, name: 'Practice student', grade_label: '1st', grade_key: '1' }, { id: 10, name: 'Practice sibling', grade_label: '1st', grade_key: '1' }];
const levels = [4,3,2,1].map(level => ({ level, short_label: ['','Needs Support','Approaching','Meets','Exceeds'][level], description: 'Practice description' }));
const piece = () => ({ source: 'own', piece_id: 41, title: 'Practice piece', detail: null, marks: [], mark_count: 0 });
const page = (sharing = true, shared = false) => ({ ...(sharing ? { sharing_enabled: true } : {}), levels, students, curriculum: [], lesson_plans: [], own_pieces: [shared ? { ...piece(), marks: [{ group_membership_id: 9, level: 3, comment: 'Shared comment', updated_at: '2026-10-09T16:00:00.000000Z', shared_with_family: true }], mark_count: 1 } : piece()], notes: [{ id: 51, group_membership_id: 9, body: 'Shared note', student_name: 'Practice student', author_name: 'Practice Teacher', created_at: '2026-10-09T16:00:00Z', ...(sharing ? { shared_with_family: shared } : {}) }] });
async function work(readonly = false, sharing = true, shared = false) {
    const data: any = page(sharing, shared); const writes: any[] = [];
    const api = { get: async (url: string) => ({ data: { data: url.endsWith('/notes') ? data.notes : data } }),
        put: async (url: string, body: any) => { writes.push({ url, body }); return { data: { data: { piece_id: 41, marks: body.marks?.map((m: any) => ({ group_membership_id: m.group_membership_id, updated_at: '2026-10-09T16:00:01.000000Z' })) } } }; },
        post: async (url: string, body: any) => { writes.push({ url, body }); return { data: { data: {} } }; }, delete: async () => ({}) };
    const helpers = await loadTs('views/teacher/subject/subjectWork.ts', {});
    const file = 'views/teacher/subject/SubjectWorkPage.vue';
    const modules = await realClassModules(file, { './subjectWork': helpers, 'vue-router': { onBeforeRouteLeave() {} } });
    const screen = await mountSfc(file, { base: '/practice', api, readonly }, modules); await flush(12);
    return { screen, writes };
}
const ticks = (screen: any) => screen.all((n: any) => n.tag === 'input' && n.props.type === 'checkbox');
test('teacher row share defaults off, explains the audience, and sends only changed rows including tick-only changes', async () => {
    const { screen, writes } = await work();
    try {
        click(screen.button('Practice piece')); await flush();
        assert.equal(ticks(screen).length, 2); assert.ok(ticks(screen).every((n: any) => !n.checked));
        assert.match(screen.text(), /Share with the family/); assert.match(screen.text(), /This student's family can read this\./);
        const comment = screen.all((n: any) => n.tag === 'textarea' && n.props['aria-label'] === 'Comment for Practice student')[0];
        type(comment, 'Practice comment'); check(ticks(screen)[0], true); await flush(); click(screen.button('Save')); await flush(12);
        assert.equal(writes[0].body.marks.length, 1); assert.equal(writes[0].body.marks[0].shared_with_family, true);
        check(ticks(screen)[0], false); await flush(); click(screen.button('Save')); await flush(12);
        assert.equal(writes[1].body.marks.length, 1); assert.equal(writes[1].body.marks[0].shared_with_family, false);
        assert.equal(writes[1].body.marks[0].updated_at, '2026-10-09T16:00:01.000000Z');
    } finally { screen.unmount(); }
});
test('new note share is off and words change between whole class and one student; edits keep sharing', async () => {
    const { screen, writes } = await work(false, true, true);
    try {
        click(screen.button('New note')); await flush(); assert.equal(ticks(screen).length, 1); assert.equal(!!ticks(screen)[0].checked, false);
        assert.match(screen.text(), /Every family in this class can read this\./);
        type(screen.all((n: any) => n.props['aria-label'] === 'Note text')[0], 'Practice update'); check(ticks(screen)[0], true); submit(screen.all((n: any) => n.tag === 'form')[0]); await flush(12);
        assert.equal(writes[0].body.shared_with_family, true);
        click(screen.all((n: any) => n.props['aria-label'] === 'Edit note: Shared note')[0]); await flush(); assert.equal(ticks(screen)[0].checked, true);
        assert.match(screen.text(), /This student's family can read this\./);
    } finally { screen.unmount(); }
});
test('office read-only marks and notes show Shared with the family and offer no ticks', async () => {
    const { screen } = await work(true, true, true);
    try { click(screen.button('Practice piece')); await flush(); assert.equal(ticks(screen).length, 0); assert.equal(screen.all((n: any) => n.tag === 'span' && n.textContent === 'Shared with the family').length, 2); }
    finally { screen.unmount(); }
});
test('sharing OFF changes no teacher controls or mark payload', async () => {
    const { screen, writes } = await work(false, false);
    try { click(screen.button('Practice piece')); await flush(); assert.equal(ticks(screen).length, 0); type(screen.all((n: any) => n.props['aria-label'] === 'Comment for Practice student')[0], 'Practice'); await flush(); click(screen.button('Save')); await flush(12); assert.ok(!('shared_with_family' in writes[0].body.marks[0])); }
    finally { screen.unmount(); }
});

async function language() {
    const modules: any = { vue };
    for (const name of ['ur', 'ps', 'fa-AF', 'es']) modules[`@/views/family/locales/${name}`] = await loadTs(`views/family/locales/${name}.ts`, {});
    return loadTs('views/family/familyI18n.ts', modules);
}
test('family Subjects area mounts shared title, words, comment, date and child notes in English and Arabic at 390 and 320', async () => {
    const lang = await language();
    for (const [code, width] of [['en',390], ['ar',320]] as const) {
        (globalThis as any).window.innerWidth = width; lang.useFamilyLang().setLang(code);
        const screen = await mountSfc('views/family/FamilySubjects.vue', { subjects: [{ id: 7, name: 'Science', marks: [{ id: 11, title: 'Practice piece', level: 3, level_label: 'Meets', comment: 'Practice comment', date: '2026-10-09T16:00:00Z' }], notes: [{ id: 12, body: 'Practice note', date: '2026-10-09T16:00:00Z' }] }], memberId: 9 }, { '@/views/family/familyI18n': lang });
        try { await flush(); assert.match(screen.text(), code === 'en' ? /Subjects/ : /المواد/); assert.match(screen.text(), code === 'en' ? /3 Meets/ : /3 يحقق/); assert.match(screen.text(), /Practice piece/); assert.match(screen.text(), /Practice comment/); assert.match(screen.text(), /Practice note/); assert.equal(screen.all((n: any) => n.tag === 'time').length, 2); }
        finally { screen.unmount(); }
    }
});

test('family class offers notes and mark comments to the real translation pipeline, never copied titles', async () => {
    const { modulesFor } = await import('./support/batch3Modules.ts');
    const lang = await language(); lang.useFamilyLang().setLang('en');
    const doc = (globalThis as any).document; doc.addEventListener ??= () => {}; doc.removeEventListener ??= () => {};
    const subjects = [{ id: 7, name: 'Science', marks: [{ id: 11, title: 'School guide title', level: 3, comment: 'Practice comment', date: '2026-10-09T16:00:00Z' }], notes: [{ id: 12, body: 'Practice note', date: '2026-10-09T16:00:00Z', translation_version: 'source-hash' }] }];
    const data = { id: 2, name: 'Practice Class', description: null, children: [{ membership_id: 9, contact: { first_name: 'Practice child', last_name: '' }, subjects }], may_receive_feed: false, may_receive_media: false, points_period: 'running' };
    const calls: any[] = [];
    const ok = (data: any, meta: any = {}) => ({ data: { data, meta } });
    const api = { get: async (url: string) => url.endsWith('/groups/2') ? ok(structuredClone(data), { translation_available: true }) : ok([]),
        post: async (url: string, body: any) => { calls.push({ url, body }); return { data: { data: { translations: Object.fromEntries(body.items.map((i: any) => [i.key, `Rendered ${i.text}`])) }, meta: { complete: true } } }; } };
    const overrides = { '@/core/services/FamilyApiService': { default: api, rowsOf: (data: any) => Array.isArray(data) ? data : [] },
        '@/core/services/StudentApiService': { default: {} }, '@/stores/familyStore': { useFamilyStore: () => ({ contactFor: () => ({}), handleAuthFailure: () => false }) },
        '@/views/family/familyI18n': lang, 'vue-router': { useRoute: () => ({ params: { masjidId: '1', groupId: '2' } }), useRouter: () => ({ push() {}, replace() {} }) } };
    const familyChild = await (await import('./support/mountSfc.ts')).compileSfc('views/family/FamilySubjects.vue', { '@/views/family/familyI18n': lang });
    const contentTranslation = await loadTs('views/family/useContentTranslation.ts', { vue, '@/core/services/FamilyApiService': overrides['@/core/services/FamilyApiService'], '@/views/family/familyI18n': lang });
    const modules = await modulesFor('views/family/FamilyClass.vue', { ...overrides, '@/views/family/useContentTranslation': contentTranslation, '@/views/family/FamilySubjects.vue': { default: familyChild } });
    const screen = await mountSfc('views/family/FamilyClass.vue', {}, modules);
    try {
        await flush(15); click(screen.button('Practice child')); await flush(12);
        assert.match(screen.text(), /Subjects/); assert.match(screen.text(), /School guide title/);
        const button = screen.all((n: any) => n.tag === 'button' && n.textContent.includes('Translate'))[0]; assert.ok(button); click(button); await flush(20);
        assert.equal(calls.length, 1); assert.ok(calls[0].body.items.find((i: any) => i.text === 'Practice note').key.endsWith('source-hash')); assert.deepEqual(calls[0].body.items.map((i: any) => i.text).sort(), ['Practice comment','Practice note']);
        assert.match(screen.text(), /Rendered Practice note/); assert.match(screen.text(), /Rendered Practice comment/); assert.match(screen.text(), /School guide title/);
    } finally { screen.unmount(); }
});

test('family tab re-entry revalidates shared items instead of retaining an unshared item in the page', async () => {
    const { modulesFor } = await import('./support/batch3Modules.ts'); const lang = await language(); lang.useFamilyLang().setLang('en');
    const doc = (globalThis as any).document; doc.addEventListener ??= () => {}; doc.removeEventListener ??= () => {};
    let shared = true; const reads: string[] = [];
    const makeClass = () => ({ id: 2, name: 'Practice Class', description: null, children: [{ membership_id: 9, contact: { first_name: 'Practice child' }, subjects: shared ? [{ id: 7, name: 'Science', marks: [], notes: [{ id: 12, body: 'Visible shared note', date: '2026-10-09', translation_version: 'source' }] }] : [] }], may_receive_feed: false, may_receive_media: false });
    const apiModule = { default: { get: async (url: string) => { reads.push(url); return { data: { data: url.endsWith('/groups/2') ? makeClass() : [] } }; } }, rowsOf: (data: any) => Array.isArray(data) ? data : [] };
    const txModule = { useContentTranslation: () => ({ loading: vue.ref(false), error: vue.ref(null), incomplete: vue.ref(false), showing: vue.ref(false), showingLang: vue.ref(null), hasTranslations: vue.ref(false), available: vue.ref(false), setAvailable() {}, translate() {}, tx: (_key: string, text: string) => text }) };
    const child = await (await import('./support/mountSfc.ts')).compileSfc('views/family/FamilySubjects.vue', { '@/views/family/familyI18n': lang });
    const modules = await modulesFor('views/family/FamilyClass.vue', { '@/core/services/FamilyApiService': apiModule, '@/core/services/StudentApiService': { default: {} }, '@/stores/familyStore': { useFamilyStore: () => ({ contactFor: () => ({}), handleAuthFailure: () => false }) }, '@/views/family/familyI18n': lang, '@/views/family/useContentTranslation': txModule, '@/views/family/FamilySubjects.vue': { default: child }, 'vue-router': { useRoute: () => ({ params: { masjidId: '1', groupId: '2' } }), useRouter: () => ({ push() {}, replace() {} }) } });
    const screen = await mountSfc('views/family/FamilyClass.vue', {}, modules);
    try {
        await flush(15); click(screen.button('Practice child')); await flush(15); assert.match(screen.text(), /Visible shared note/);
        click(screen.button('Class story')); await flush(); shared = false; click(screen.button('Practice child')); await flush(15);
        assert.doesNotMatch(screen.text(), /Visible shared note/); assert.ok(reads.filter(url => url.endsWith('/groups/2')).length >= 3);
    } finally { screen.unmount(); }
});

test('saving an empty mark clears its sharing choice so the next new mark starts unticked', async () => {
    const { screen, writes } = await work();
    try {
        click(screen.button('Practice piece')); await flush(); check(ticks(screen)[0], true); await flush(); click(screen.button('Save')); await flush(12);
        assert.equal(ticks(screen)[0].checked, false);
        type(screen.all((n: any) => n.props['aria-label'] === 'Comment for Practice student')[0], 'New private comment'); await flush(); click(screen.button('Save')); await flush(12);
        assert.equal(writes[1].body.marks[0].shared_with_family, false);
    } finally { screen.unmount(); }
});

test('review: a note edit sends the version it was opened with, and the tick only when the teacher changed it', () => {
    const notes = readFileSync(new URL('../views/teacher/subject/SubjectNotes.vue', import.meta.url), 'utf8');
    // Mounted tests cannot open two tabs. Pin the two rules that stop a stale tab from sharing a note again.
    assert.match(notes, /editingVersion\.value = note\?\.version;/);
    assert.match(notes, /\(opened\.shared_with_family === true\) !== \(f\.shared_with_family === true\) \? \{ shared_with_family: f\.shared_with_family === true \} : \{\}/);
    assert.match(notes, /\{ body: f\.body, \.\.\.tick, \.\.\.\(sharing\.value \? \{ version: editingVersion\.value \} : \{\}\) \}/);
});
