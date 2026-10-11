import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { compileSfc, mountSfc, loadTs, click, type, flush, httpError } from './support/mountSfc.ts';
import * as dates from '../core/types/data/masjid-related/SchoolCalendar.ts';
import { formatLastOpened } from '../core/helpers/lastOpened.ts';
const require = createRequire(import.meta.url); const vue = require('vue');
async function screen(statuses?: (string | null)[]) {
    const errors = await loadTs('core/services/ApiErrors.ts', { axios: require('axios') });
    const committed = await loadTs('composables/useCommittedDate.ts', { vue });
    const badge = await compileSfc('components/classes/AllergyBadge.vue', { vue });
    const reads: string[] = []; const writes: any[] = [];
    let refusal: any = null; let readRefusal: any = null; let delayed: ((value: any) => void) | null = null; let heldDate = ''; let holdNext = false;
    const rows = [1,2].map(id => ({ membership_id: id, contact: { first_name: id === 1 ? 'Practice' : 'Sample', last_name: 'Student' }, grade_label: 'KG', status: id === 1 ? 'absent' : null, note: id === 1 ? 'Earlier note' : '', marked_by: id === 1 ? 'Practice Teacher' : null, marked_at: id === 1 ? '2026-10-19T13:05:00Z' : null, student_allergy: id === 1 ? { allergies: 'Practice allergy', unavailable: false } : null }));
    if (statuses) { rows.splice(0, rows.length, ...statuses.map((status, i) => ({ ...rows[0], membership_id: i + 1, status, note: `Note ${i + 1}` }))); }
    let revision = 'revision-one';
    const response = (date = '2026-10-19') => ({ headers: { 'x-attendance-revision': revision }, data: { data: { session_date: date, today: '2026-10-19', timezone: 'America/New_York', taken: true, refusal: null, students: structuredClone(rows) } } });
    const api = {
        get: async (url: string) => { reads.push(url); if (url.includes('/registers')) return { data: { data: { classes: [{ id: 4, name: 'Other Class', taken: false }, { id: 3, name: 'Practice Class', taken: true, marked_by: 'Practice Teacher', marked_at: '2026-10-19T13:05:00Z' }] } } }; if (holdNext) { holdNext = false; heldDate = url.split('date=')[1]; return new Promise(resolve => { delayed = resolve; }); } if (readRefusal) throw readRefusal; return response(url.split('date=')[1]); },
        put: async (url: string, body: any, contentType?: string, headers?: any) => { writes.push({ url, body, headers }); if (refusal) throw refusal; for (const mark of body.marks) Object.assign(rows[mark.membership_id - 1], { ...mark, marked_by: 'Office: Practice Office', marked_at: '2026-10-19T14:10:00Z' }); return response(body.session_date); },
    };
    const s = await mountSfc('views/dashboard/groups/GroupAttendanceTab.vue', { groupId: 3, masjidId: 1, date: '' }, { vue, '@/core/services/ApiService': { default: api }, '@/core/services/ApiErrors': errors, '@/core/types/data/masjid-related/SchoolCalendar': dates, '@/core/helpers/lastOpened': { formatLastOpened }, '@/components/classes/AllergyBadge.vue': { default: badge }, '@/composables/useCommittedDate': committed, 'vue-router': { useRouter: () => ({ push: (v: any) => routes.push(v) }) } });
    const routes: any[] = []; await flush();
    return { ...s, reads, writes, routes, failRead: (e: any) => { readRefusal = e; }, server: (id: number, change: any) => { Object.assign(rows[id - 1], change); revision = 'revision-two'; }, fail: (e: any) => { refusal = e; }, delay: () => { holdNext = true; }, resolve: () => { delayed?.(response(heldDate)); delayed = null; } };
}
test('office controls mark all, absent with a note, save and correct with attribution and allergy', async () => {
    const s = await screen(); try {
        assert.match(s.text(), /Practice Teacher/); assert.match(s.text(), /9:05 AM/); assert.match(s.text(), /Allergy/);
        click(s.button('Mark all present')); await flush();
        const row = s.all(n => n.props['data-student'] === 1)[0];
        click(row.children.flatMap(function walk(n: any): any[] { return [n, ...n.children.flatMap(walk)]; }).find(n => n.tag === 'button' && n.textContent === 'Absent'));
        type(s.all(n => n.props.id === 'attendance-note-1')[0], 'Office practice note'); await flush();
        click(s.button('Save register')); await flush();
        assert.deepEqual(s.writes[0].body, { session_date: '2026-10-19', marks: [{ membership_id: 1, status: 'absent', note: 'Office practice note' }, { membership_id: 2, status: 'present', note: '' }] });
        assert.match(s.text(), /Office: Practice Office/); assert.match(s.text(), /10:10 AM/);
        click(s.all(n => n.props['data-student'] === 1)[0].children.flatMap(function walk(n: any): any[] { return [n, ...n.children.flatMap(walk)]; }).find(n => n.tag === 'button' && n.textContent === 'Late'));
        click(s.button('Save register')); await flush(); assert.equal(s.writes[1].body.marks[0].status, 'late');
    } finally { s.unmount(); }
});
test('committed date loads after typing pause, Enter and blur; save cannot use an uncommitted date', async () => {
    const s = await screen(); try {
        const input = s.all(n => n.props.id === 'office-attendance-date')[0];
        const count = s.reads.length;
        type(input, '2026-10-0'); input.props.onChange(); await flush(); assert.equal(s.reads.length, count); assert.equal(s.button('Save register').disabled, true);
        type(input, '2026-10-16'); input.props.onChange(); await flush(); assert.equal(s.reads.length, count);
        await new Promise(r => setTimeout(r, 450)); await flush(); assert.match(s.reads.at(-2)!, /date=2026-10-16/);
        type(input, '2026-10-15'); input.props.onKeydown({ key: 'Enter', preventDefault() {} }); await flush(); assert.match(s.reads.at(-2)!, /date=2026-10-15/);
        type(input, '2026-10-14'); input.props.onBlur(); await flush(); assert.match(s.reads.at(-2)!, /date=2026-10-14/);
        click(s.button('Other Class')); await flush(); assert.deepEqual(s.routes[0], { name: 'masjid.groupDetail', params: { groupId: 4 }, query: { tab: 'attendance', date: '2026-10-14' } });
    } finally { s.unmount(); }
});
test('an older register response cannot replace a newer committed date or erase a date being typed', async () => {
    const s = await screen(); try {
        const input = s.all(n => n.props.id === 'office-attendance-date')[0];
        s.delay(); type(input, '2026-10-16'); input.props.onKeydown({ key: 'Enter', preventDefault() {} }); await flush();
        type(input, '2026-10-15'); input.props.onKeydown({ key: 'Enter', preventDefault() {} }); await flush();
        s.resolve(); await flush(); assert.equal(input.value, '2026-10-15');
        click(s.button('Mark all present')); click(s.button('Save register')); await flush(); assert.equal(s.writes[0].body.session_date, '2026-10-15');
        s.delay(); type(input, '2026-10-14'); input.props.onKeydown({ key: 'Enter', preventDefault() {} }); await flush();
        type(input, '2026-10-13'); await flush(); s.resolve(); await flush(); assert.equal(input.value, '2026-10-13');
        assert.equal(s.button('Save register').disabled, true);
    } finally { s.unmount(); }
});
test('a save refusal stays visible and correction retains the earlier marker until saved', async () => {
    const s = await screen(); try {
        click(s.button('Mark all present')); await flush(); assert.match(s.text(), /Practice Teacher/);
        s.fail(httpError(422, { status: 'failed', data: { marks: ['That register names a child who has left the class — reload it and mark the rest.'] } }));
        click(s.button('Save register')); await flush(); assert.match(s.text(), /reload it and mark the rest/); assert.doesNotMatch(s.text(), /Saved\./);
    } finally { s.unmount(); }
});
test('office register uses theme styles and fluid phone rows', () => {
    for (const file of ['views/dashboard/groups/GroupAttendanceTab.vue', 'views/dashboard/GroupDetailView.vue']) {
        const src = readFileSync(new URL('../'+file, import.meta.url), 'utf8');
        const styles = src.match(/<style[^>]*>([\s\S]*?)<\/style>/)?.[1] ?? '';
        assert.doesNotMatch(styles, /#[0-9a-f]{3,8}\b/i);
        assert.doesNotMatch(styles, /[;{]\s*min-width:\s*\d{3,}px/);
    }
});

test('office sends the read revision and reloads a conflict without restoring untouched stale marks', async () => {
    const s = await screen(); try {
        const row = s.all(n => n.props['data-student'] === 2)[0];
        click(row.children.flatMap(function walk(n: any): any[] { return [n, ...n.children.flatMap(walk)]; }).find(n => n.tag === 'button' && n.textContent === 'Excused'));
        type(s.all(n => n.props.id === 'attendance-note-2')[0], 'My unsaved note');
        s.server(1, { status: 'late', note: 'Teacher correction' });
        s.server(2, { status: 'present', note: 'Office correction' });
        s.fail(httpError(409, { message: 'This register was changed by Practice Teacher at noon. Reload it, then make your change.' }));
        click(s.button('Save register')); await flush();
        assert.equal(s.writes[0].headers?.['X-Attendance-Revision'], 'revision-one');
        assert.match(s.text(), /This register was changed by Practice Teacher/);
        assert.match(s.text(), /Your unsaved/);
        assert.equal(s.all(n => n.props.id === 'attendance-note-1')[0].value, 'Teacher correction');
        assert.equal(s.all(n => n.props.id === 'attendance-note-2')[0].value, 'My unsaved note');
        s.fail(null); click(s.button('Save register')); await flush();
        assert.equal(s.writes[1].headers?.['X-Attendance-Revision'], 'revision-two');
        assert.deepEqual(s.writes[1].body.marks, [{ membership_id: 1, status: 'late', note: 'Teacher correction' }, { membership_id: 2, status: 'excused', note: 'My unsaved note' }]);
    } finally { s.unmount(); }
});

test('office mark all protects three saved late or excused marks until confirmed, including after local edits', async () => {
    const s = await screen(['late', 'excused', 'late', null]); try {
        const row = s.all(n => n.props['data-student'] === 1)[0];
        click(row.children.flatMap(function walk(n: any): any[] { return [n, ...n.children.flatMap(walk)]; }).find(n => n.tag === 'button' && n.textContent === 'Absent'));
        click(s.button('Mark all present')); await flush();
        assert.match(s.text(), /3 students are marked late or excused. Mark them present too\?/);
        click(s.button('Keep late and excused')); await flush();
        click(s.button('Save register')); await flush();
        assert.deepEqual(s.writes[0].body.marks.map((m: any) => m.status), ['absent', 'excused', 'late']);
        click(s.button('Mark all present')); await flush();
        click(s.button('Mark them present too')); await flush();
        click(s.button('Save register')); await flush();
        assert.deepEqual(s.writes[1].body.marks.map((m: any) => m.status), ['present', 'present', 'present', 'present']);
        assert.deepEqual(s.writes[1].body.marks.map((m: any) => m.note), ['Note 1', 'Note 2', 'Note 3', 'Note 4']);
    } finally { s.unmount(); }
});

test('overview taken link opens a make-up register on the same date', async () => {
    const s = await screen(); try {
        const input = s.all(n => n.props.id === 'office-attendance-date')[0];
        type(input, '2026-10-18'); input.props.onBlur(); await flush();
        click(s.button('Practice Class')); await flush();
        assert.equal(s.routes[0].query.date, '2026-10-18');
        assert.equal(s.routes[0].query.tab, 'attendance');
    } finally { s.unmount(); }
});

test('office keeps unsaved choices visible if the conflict reload fails', async () => {
    const s = await screen(); try {
        type(s.all(n => n.props.id === 'attendance-note-1')[0], 'Unsaved while offline');
        s.fail(httpError(409, { message: 'This register was changed by Practice Teacher at noon. Reload it, then make your change.' }));
        s.failRead(httpError(503, { message: 'Try again later.' }));
        click(s.button('Save register')); await flush();
        assert.equal(s.all(n => n.props.id === 'attendance-note-1')[0]?.value, 'Unsaved while offline');
        assert.match(s.text(), /This register was changed/);
    } finally { s.unmount(); }
});
