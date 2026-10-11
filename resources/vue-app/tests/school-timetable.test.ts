import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mountSfc, flush, click, type, select, submit, httpError } from './support/mountSfc.ts';
const require = createRequire(import.meta.url); const vue = require('vue');
async function screen(on = true) {
    const periods = [{ id: 21, name: 'P1', starts_at: '08:00', ends_at: '09:00', kind: 'teaching', position: 1 }];
    const data: any = { today: '2026-10-10', years: [{ id: 7, label: 'Practice year' }], weekdays: [1,5], sets: [{ id: 1, name: 'Regular', periods }, { id: 2, name: 'Friday', periods: [{ ...periods[0], id: 22, starts_at: '08:30', ends_at: '09:15' }] }], days: [{ weekday: 1, period_set_id: 1 }, { weekday: 5, period_set_id: 2 }], classes: [{ id: 3, name: 'Practice Class', room_id: 4, teachers: [8], subjects: [{ id: 10, name: 'Science', teacher_ids: [8] }] }], rooms: [{ id: 4, name: 'Practice Hall', active: true }], teachers: [{ id: 8, name: 'Practice Teacher' }], class_subjects_enabled: true };
    const week = { days: data.days.map((d: any) => ({ ...d, periods: data.sets.find((s: any) => s.id === d.period_set_id).periods })), meetings: [{ id: 9, group_id: 3, weekday: 1, period_id: 21, kind: 'subject', class_subject_id: 10, label: 'Science', room_id: 4, room_name: 'Practice Hall', teachers: [data.teachers[0]], effective_from: '2026-10-10' }] };
    const clash = { kind: 'teacher', name: 'Practice Teacher', meetings: [{ group_name: 'Practice Other', label: 'Reading', starts_at: '08:00', ends_at: '09:00' }], effective_from: '2026-10-10', effective_until: '2027-06-25' };
    const calls: any[] = []; let conflict = false; let hold = false; let release: (() => void) | null = null;
    const api = { get: async (url: string) => { calls.push({ method: 'get', url }); if (url.includes('/week') && hold) await new Promise<void>(resolve => { release = resolve; }); return { data: { data: url.includes('/week') ? week : url.includes('/clashes') ? [clash] : data } }; }, post: async (url: string, body: any) => { calls.push({ method: 'post', url, body }); if (conflict && !body.confirm_clashes) throw httpError(409, { clashes: [clash] }); return { data: { data: {} } }; }, put: async (url: string, body: any) => { calls.push({ method: 'put', url, body }); return { data: { data: {} } }; }, deleteWithBody: async (url: string, body: any) => { calls.push({ method: 'delete', url, body }); return { data: { data: {} } }; }, delete: async (url: string, body: any) => { calls.push({ method: 'delete', url, body }); return { data: { data: {} } }; } };
    const s = await mountSfc('views/dashboard/SchoolTimetableView.vue', {}, { vue, '@/core/services/ApiService': { default: api }, '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { capabilities: { school_timetable: on } } }) } }); await flush();
    return { ...s, calls, holdWeek: () => { hold = true; }, releaseWeek: () => { hold = false; release?.(); }, conflict: () => { conflict = true; } };
}
test('Timetable OFF makes no requests and hides controls', async () => { const s = await screen(false); try { assert.equal(s.calls.length, 0); assert.doesNotMatch(s.text(), /Add period set|Place meeting/); } finally { s.unmount(); } });
test('Timetable setup mounted edits sets rooms weekdays and usual room', async () => { const s = await screen(); try { click(s.button('Setup')); await flush(); assert.match(s.text(), /This changes the times for the whole year/); click(s.button('Add period set')); await flush(); type(s.all(n => n.props.id === 'set-name')[0], 'Practice New'); click(s.all(n=>n.tag==='button' && n.textContent==='Add period')[0]); await flush(); submit(s.all(n=>n.tag==='form')[0]); await flush(); assert.equal(s.calls.find(c=>c.method==='post').body.name,'Practice New'); assert.match(s.text(), /Rooms|Usual room/); } finally { s.unmount(); } });
test('Timetable class week has each weekday own times as of control and filled cell', async () => { const s = await screen(); try { assert.match(s.text(), /08:00|08:30|09:15|Science|Practice Teacher|Practice Hall/); type(s.all(n=>n.props.id==='as-of')[0], '2026-11-01'); await flush(); assert.ok(s.calls.some(c=>c.url.includes('as_of=2026-11-01'))); click(s.button('Science')); await flush(); assert.match(s.text(), /From which date|Remove meeting/); } finally { s.unmount(); } });
test('Timetable placing warning lists parties and Save anyway resends same draft', async () => { const s = await screen(); try { s.conflict(); click(s.button('Place meeting')); await flush(); select(s.all(n=>n.props.id==='meeting-kind')[0], 'activity'); await flush(); type(s.all(n=>n.props.id==='activity-name')[0], 'Break duty'); await flush(); submit(s.all(n=>n.tag==='form')[0]); await flush(); assert.match(s.text(), /Practice Other|Reading|Go back/); click(s.button('Save anyway')); await flush(); const saves=s.calls.filter(c=>c.method==='post'); assert.deepEqual(saves[1].body,{...saves[0].body,confirm_clashes:true}); } finally { s.unmount(); } });
test('Timetable clashes panel and header count mount', async () => { const s=await screen(); try { click(s.button('Clashes (1)')); await flush(); assert.match(s.text(), /Practice Teacher|Practice Other|Reading|08:00/); } finally { s.unmount(); } });

test('Timetable 390 and 320 layouts contain the grid scroll and use logical RTL spacing', async () => {
    const { readFileSync } = await import('node:fs');
    const source=readFileSync(new URL('../views/dashboard/SchoolTimetableView.vue',import.meta.url),'utf8');
    assert.match(source,/\.timetable-page\s*\{[^}]*min-width: 0;[^}]*max-width: 100%/);
    assert.match(source,/\.tt-grid-scroll\s*\{[^}]*max-width: 100%;[^}]*overflow-x: auto/);
    assert.match(source,/margin-inline-end|padding-inline-start/); assert.match(source,/<bdi dir="ltr">/);
    assert.doesNotMatch(source,/margin-left|margin-right|padding-left|padding-right/);
    for(const width of [390,320]) { const s=await screen(); (globalThis as any).window ??= {}; (globalThis as any).window.innerWidth=width; try { assert.match(s.text(),/Monday|Friday|08:00|08:30/); } finally { s.unmount(); } }
});

test('Timetable as-of date clears earlier cells while the new dated read is pending', async () => {
    const s=await screen();
    try { assert.match(s.text(),/Science/); s.holdWeek(); type(s.all(n=>n.props.id==='as-of')[0],'2026-11-01'); await flush(); assert.doesNotMatch(s.text(),/Science/); assert.match(s.text(),/Loading class week/); s.releaseWeek(); await flush(); assert.match(s.text(),/Science/); }
    finally { s.releaseWeek(); s.unmount(); }
});
