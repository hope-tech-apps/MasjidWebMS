import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { readFileSync } from 'node:fs';
import { compileSfc, mountSfc, loadTs, click, flush, httpError } from './support/mountSfc.ts';
import { modulesFor } from './support/batch3Modules.ts';

async function register(teacher = false, readonly = false, classOnly = false) {
    const reads: string[] = []; const writes: any[] = []; let fail: any = null;
    const response = { headers: { 'x-attendance-revision': 'period-revision' }, data: { data: { session_date: '2026-10-19', today: '2026-10-19', timezone: 'America/New_York', taken: true, read_only: readonly,
        meeting: { id: 11, period_name: 'First period', label: 'Practice subject' }, students: [{ membership_id: 7, contact: { first_name: 'Sample', last_name: 'Student' }, status: 'late', note: 'Staff note', marked_by: 'Practice Teacher', marked_at: '2026-10-19T13:00:00Z' }] } } };
    const api = { get: async (url: string) => { reads.push(url); if (url.includes('/registers?')) return { data: { data: { classes: [] } } }; if (!url.includes('/period-attendance/') || url.includes('/period-attendance/classes/')) return { data: { data: { session_date: '2026-10-19', today: '2026-10-19', period_mode: true, students: [], registers: [{ id: 11, label: 'First period · Practice subject', taken: true, read_only: readonly }] } } }; return structuredClone(response); },
        put: async (...args: any[]) => { writes.push(args); if (fail) throw fail; return structuredClone(response); } };
    const modules = await modulesFor('views/dashboard/groups/GroupAttendanceTab.vue', { '@/core/services/ApiService': { default: api }, 'vue-router': { useRouter: () => ({ push() {} }) } });
    const s = await mountSfc('views/dashboard/groups/GroupAttendanceTab.vue', { masjidId: 1, groupId: 3, date: '2026-10-19', classPeriodsOnly: classOnly, realm: teacher ? 'teacher' : 'office', transport: api }, modules); await flush(14);
    return { ...s, reads, writes, fail: (error: any) => { fail = error; } };
}
for (const teacher of [false, true]) test(`${teacher ? 'teacher class' : 'office'} period picker loads and corrects the shared register with its revision`, async () => {
    const s = await register(teacher); try {
        assert.match(s.text(), /First period/); click(s.button('First period · Practice subject')); await flush(14);
        assert.match(s.text(), /Marked by Practice Teacher/); assert.match(s.text(), /Practice subject/);
        assert.ok(s.reads.some(url => url.includes(`/api/${teacher ? 'teacher' : 'admin'}/masjids/1/period-attendance/11?date=2026-10-19`)));
        click(s.button('Absent')); click(s.button('Save register')); await flush(14);
        assert.match(s.writes[0][0], /period-attendance\/11$/); assert.equal(s.writes[0][1].marks[0].note, 'Staff note');
        assert.equal(s.writes[0][3]['X-Attendance-Revision'], 'period-revision');
        if (teacher) assert.ok(!s.reads.some(url => url.includes('/api/admin/')));
        s.fail(httpError(409, { message: 'This register was changed by Practice Teacher. Reload it.' }));
        click(s.button('Present')); click(s.button('Save register')); await flush(14);
        assert.match(s.text(), /This register was changed by/); assert.match(s.text(), /Your unsaved/);
    } finally { s.unmount(); }
});
test('a meeting teacher opens class Attendance through the attendance-only class endpoint', async () => {
    const s = await register(true, false, true); try {
        assert.match(s.reads[0], /teacher\/masjids\/1\/period-attendance\/classes\/3/);
        click(s.button('First period · Practice subject')); await flush(14);
        assert.match(s.text(), /Sample Student/); assert.ok(!s.reads.some(url => url.includes('/api/admin/')));
    } finally { s.unmount(); }
});
test('retained period registers say read-only and disable every mark and save', async () => {
    const s = await register(false, true); try {
        click(s.button('First period · Practice subject')); await flush(14);
        assert.match(s.text(), /Read-only/); assert.equal(s.button('Absent').disabled, true); assert.equal(s.button('Save register').disabled, true);
    } finally { s.unmount(); }
});
test('My schedule offers a period register and a separate register for every joint class', async () => {
    const modules = await modulesFor('views/teacher/ScheduleMeeting.vue', { 'vue-router': { RouterLink: { props: ['to'], setup: (p: any, { slots }: any) => () => vue.h('a', { href: p.to }, slots.default?.()) } } });
    const s = await mountSfc('views/teacher/ScheduleMeeting.vue', { meeting: { id: 11, group_name: 'Practice Class', kind: 'activity', label: 'Practice lesson', starts_at: '08:00', ends_at: '08:45', co_teachers: [], effective_from: '2026-08-24', period_register: '/teacher/period-registers/11?date=2026-10-19', joint: [{ id: 12, group_name: 'Other Class', period_register: '/teacher/period-registers/12?date=2026-10-19' }] }, weekStart: '2026-10-19', weekEnd: '2026-10-25' }, modules);
    try { assert.ok(s.all(n => n.tag === 'a').some(n => n.props.href === '/teacher/period-registers/11?date=2026-10-19')); assert.ok(s.all(n => n.tag === 'a').some(n => n.props.href === '/teacher/period-registers/12?date=2026-10-19')); } finally { s.unmount(); }
});
test('parent period lines render under the day mark and all seven languages have the label', async () => {
    const modules: any = { vue }; for (const name of ['ur', 'ps', 'fa-AF', 'es', 'fr']) modules[`@/views/family/locales/${name}`] = await loadTs(`views/family/locales/${name}.ts`, {});
    const words = await loadTs('views/family/familyI18n.ts', modules); words.useFamilyLang().setLang('en');
    const data = { period: { kind: 'year', from: '2026-08-24', to: '2027-06-30' }, totals: { present: 1, late: 0, absent: 0, excused: 0 }, days: [{ date: '2026-10-19', class: { id: 3, name: 'Practice Class' }, status: 'present', periods: [{ meeting_id: 11, period: 'First period', subject: 'Practice subject', starts_at: '08:00', ends_at: '08:45', status: 'absent' }, { meeting_id: 12, period: 'Second period', subject: 'Other subject', starts_at: '09:00', ends_at: '09:45', status: 'present' }] }] };
    const overrides = { '@/views/family/familyI18n': words, '@/core/services/FamilyApiService': { default: { get: async () => ({ data: { data } }) } } };
    const s = await mountSfc('views/family/FamilyAttendance.vue', { masjidId: '1', groupId: '3', children: [{ membership_id: 7, contact: { first_name: 'Sample' } }] }, await modulesFor('views/family/FamilyAttendance.vue', overrides)); await flush();
    try { for (const text of ['First period', 'Practice subject', 'Second period', 'Other subject']) assert.match(s.text(), new RegExp(text));
        for (const code of ['en','ar','ur','ps','fa-AF','es','fr']) { words.useFamilyLang().setLang(code); await flush(); assert.notEqual(words.useFamilyLang().t('attendance_periods'), 'attendance_periods'); }
        assert.ok(!s.text().includes('Staff note'));
    } finally { s.unmount(); }
});
