import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as vue from 'vue';
import { flush, loadTs, mountSfc, compileSfc, click } from './support/mountSfc.ts';
import { modulesFor } from './support/batch3Modules.ts';
import { formatSchoolDay } from '../core/types/data/masjid-related/SchoolCalendar.ts';

async function language() {
    const modules: Record<string, any> = { vue };
    for (const name of ['ur', 'ps', 'fa-AF', 'es', 'fr']) modules[`@/views/family/locales/${name}`] = await loadTs(`views/family/locales/${name}.ts`, {});
    return loadTs('views/family/familyI18n.ts', modules);
}
const child = { membership_id: 7, contact: { first_name: 'Sample', last_name: 'Student' } };
const data = { period: { kind: 'term', name: 'Quarter 1', from: '2026-08-24', to: '2026-10-23' }, totals: { present: 41, late: 2, absent: 1, excused: 1 }, days: [{ date: '2026-10-19', class: { id: 3, name: 'First Class' }, status: 'absent' }, { date: '2026-10-16', class: { id: 4, name: 'Second Class' }, status: 'present' }] };
async function screen(payload: any = data, fail = false) {
    const words = await language(); const calls: string[] = [];
    const api = { get: async (url: string) => { calls.push(url); if (fail) throw new Error('Practice failure'); return { data: { data: payload } }; } };
    const s = await mountSfc('views/family/FamilyAttendance.vue', { masjidId: '1', groupId: '3', children: [child] }, { vue, '@/views/family/familyI18n': words, '@/core/services/FamilyApiService': { default: api }, '@/core/types/data/masjid-related/SchoolCalendar': { formatSchoolDay } });
    await flush(); return { ...s, calls, lang: words.useFamilyLang() };
}

test('Attendance is beside Marks and is available under the same child condition', () => {
    const source = readFileSync(new URL('../views/family/FamilyClass.vue', import.meta.url), 'utf8');
    assert.match(source, /v-if="group.children.length"[\s\S]{0,200}tab === 'attendance'/);
    assert.match(source, /tab === 'attendance'[\s\S]*<FamilyAttendance/);
});
test('the family screen renders totals, term dates and both classes through the child endpoint', async () => {
    const s = await screen(); try {
        assert.deepEqual(s.calls, ['/api/family/masjids/1/groups/3/members/7/attendance']);
        for (const text of ['Sample Student', 'Quarter 1', 'Present 41', 'Late 2', 'Absent 1', 'Excused 1', 'First Class', 'Second Class']) assert.ok(s.text().includes(text), text);
        assert.ok(s.text().indexOf('October 19') < s.text().indexOf('October 16'));
        assert.ok(!s.text().includes('note')); assert.ok(!s.text().includes('marked by'));
    } finally { s.unmount(); }
});
test('empty attendance says no attendance recorded and a failed read offers retry without fake totals', async () => {
    const s = await screen({ ...data, days: [], period: { ...data.period, kind: 'year' } });
    try { assert.match(s.text(), /No attendance has been recorded yet/); assert.match(s.text(), /This school year/); } finally { s.unmount(); }
    const bad = await screen(data, true); try {
        assert.match(bad.text(), /could not be loaded/); assert.ok(!bad.text().includes('Present 41'));
        click(bad.button('Try again')); await flush(); assert.equal(bad.calls.length, 2);
    } finally { bad.unmount(); }
});
test('the seven languages carry attendance words and format calendar dates in that language with RTL roots', async () => {
    const s = await screen(); try {
        for (const code of ['en', 'ar', 'es', 'ur', 'ps', 'fa-AF', 'fr']) {
            s.lang.setLang(code); await flush();
            for (const key of ['tab_attendance', 'attendance_mark_present', 'attendance_mark_late', 'attendance_mark_absent', 'attendance_mark_excused', 'attendance_year', 'attendance_empty', 'attendance_error']) assert.notEqual(s.lang.t(key), key, `${code}: ${key}`);
            const root = s.all((n: any) => n.tag === 'section' && n.props.class === 'family-attendance')[0];
            assert.equal(root.props.dir, ['ar', 'ur', 'ps', 'fa-AF'].includes(code) ? 'rtl' : 'ltr');
        }
        assert.match(s.text(), /octobre/);
    } finally { s.unmount(); }
});
test('attendance layout wraps rows and totals at phone widths using logical alignment', () => {
    const source = readFileSync(new URL('../views/family/FamilyAttendance.vue', import.meta.url), 'utf8');
    assert.match(source, /flex-wrap/); assert.match(source, /min-width:\s*0/); assert.match(source, /overflow-wrap:\s*anywhere/); assert.match(source, /text-align:\s*start/);
});

test('one tap in the real class shell opens the real attendance section without fetching it on Story', async () => {
    const words = await language(); words.useFamilyLang().setLang('en');
    const calls: string[] = [];
    const group = { id: 3, name: 'Practice Class', children: [child], in_class_now: true, may_receive_feed: false };
    const api = { get: async (url: string) => { calls.push(url); return { data: { data: url.endsWith('/groups/3') ? group : url.endsWith('/attendance') ? data : [] } }; } };
    const overrides: Record<string, any> = {
        vue, 'vue-router': { useRoute: () => ({ params: { masjidId: '1', groupId: '3' }, query: {} }), useRouter: () => ({ replace: async () => {}, resolve: () => ({ href: '/' }) }) },
        '@/core/services/FamilyApiService': { default: api, rowsOf: (node: any) => Array.isArray(node) ? node : node?.data ?? [] },
        '@/views/family/familyI18n': words,
        '@/stores/familyStore': { useFamilyStore: () => ({ contactFor: () => ({}), handleAuthFailure: () => false }) },
        '@/core/services/StudentApiService': { default: {} },
        '@/views/family/useContentTranslation': { useContentTranslation: () => ({ loading: vue.ref(false), error: vue.ref(null), incomplete: vue.ref(false), showOriginal: vue.ref(false), hasTranslations: vue.ref(false), showing: vue.ref(false), showingLang: vue.ref(null), available: vue.ref(false), setAvailable() {}, translate: async () => {}, tx: (_key: string, text: any) => text }) },
    };
    overrides['@/views/family/FamilyAttendance.vue'] = { default: await compileSfc('views/family/FamilyAttendance.vue', await modulesFor('views/family/FamilyAttendance.vue', overrides)) };
    const doc = (globalThis as any).document; doc.addEventListener ??= () => {}; doc.removeEventListener ??= () => {};
    const s = await mountSfc('views/family/FamilyClass.vue', {}, await modulesFor('views/family/FamilyClass.vue', overrides));
    try {
        await flush(16); assert.equal(calls.filter(url => url.endsWith('/attendance')).length, 0);
        click(s.button('Attendance')); await flush(12);
        assert.ok(s.text().includes('Present 41')); assert.ok(s.text().includes('Second Class'));
        assert.deepEqual(calls.filter(url => url.endsWith('/attendance')), ['/api/family/masjids/1/groups/3/members/7/attendance']);
    } finally { s.unmount(); }
});
