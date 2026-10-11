import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { compileSfc, mountSfc, loadTs, click, type, flush, httpError } from './support/mountSfc.ts';
import * as dates from '../core/types/data/masjid-related/SchoolCalendar.ts';
import { formatLastOpened } from '../core/helpers/lastOpened.ts';
const require = createRequire(import.meta.url); const vue = require('vue');
async function screen() {
    const errors = await loadTs('core/services/ApiErrors.ts', { axios: require('axios') });
    const committed = await loadTs('composables/useCommittedDate.ts', { vue });
    const badge = await compileSfc('components/classes/AllergyBadge.vue', { vue });
    const reads: string[] = []; const writes: any[] = [];
    let refusal: any = null; let delayed: ((value: any) => void) | null = null; let heldDate = ''; let holdNext = false;
    const rows = [1,2].map(id => ({ membership_id: id, contact: { first_name: id === 1 ? 'Practice' : 'Sample', last_name: 'Student' }, grade_label: 'KG', status: id === 1 ? 'absent' : null, note: id === 1 ? 'Earlier note' : '', marked_by: id === 1 ? 'Practice Teacher' : null, marked_at: id === 1 ? '2026-10-19T13:05:00Z' : null, student_allergy: id === 1 ? { allergies: 'Practice allergy', unavailable: false } : null }));
    const response = (date = '2026-10-19') => ({ data: { data: { session_date: date, today: '2026-10-19', timezone: 'America/New_York', taken: true, refusal: null, students: structuredClone(rows) } } });
    const api = {
        get: async (url: string) => { reads.push(url); if (url.includes('/registers')) return { data: { data: { classes: [{ id: 4, name: 'Other Class', taken: false }, { id: 3, name: 'Practice Class', taken: true, marked_by: 'Practice Teacher', marked_at: '2026-10-19T13:05:00Z' }] } } }; if (holdNext) { holdNext = false; heldDate = url.split('date=')[1]; return new Promise(resolve => { delayed = resolve; }); } return response(url.split('date=')[1]); },
        put: async (url: string, body: any) => { writes.push({ url, body }); if (refusal) throw refusal; for (const mark of body.marks) Object.assign(rows[mark.membership_id - 1], { ...mark, marked_by: 'Office: Practice Office', marked_at: '2026-10-19T14:10:00Z' }); return response(body.session_date); },
    };
    const s = await mountSfc('views/dashboard/groups/GroupAttendanceTab.vue', { groupId: 3, masjidId: 1, date: '' }, { vue, '@/core/services/ApiService': { default: api }, '@/core/services/ApiErrors': errors, '@/core/types/data/masjid-related/SchoolCalendar': dates, '@/core/helpers/lastOpened': { formatLastOpened }, '@/components/classes/AllergyBadge.vue': { default: badge }, '@/composables/useCommittedDate': committed, 'vue-router': { useRouter: () => ({ push: (v: any) => routes.push(v) }) } });
    const routes: any[] = []; await flush();
    return { ...s, reads, writes, routes, fail: (e: any) => { refusal = e; }, delay: () => { holdNext = true; }, resolve: () => { delayed?.(response(heldDate)); delayed = null; } };
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
