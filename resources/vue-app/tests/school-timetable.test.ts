import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mountSfc, flush, click, type, select, submit, httpError } from './support/mountSfc.ts';
const require = createRequire(import.meta.url); const vue = require('vue');
async function screen(on = true, zeroRooms = false) {
    const periods = [{ id: 21, name: 'P1', starts_at: '08:00', ends_at: '09:00', kind: 'teaching', position: 1 }];
    const data: any = { today: '2026-10-10', years: [{ id: 7, label: 'Practice year' }], weekdays: [1,5], sets: [{ id: 1, name: 'Regular', periods }, { id: 2, name: 'Friday', periods: [{ ...periods[0], id: 22, starts_at: '08:30', ends_at: '09:15' }] }], days: [{ weekday: 1, period_set_id: 1 }, { weekday: 5, period_set_id: 2 }], classes: [{ id: 3, name: 'Practice Class', room_id: 4, teachers: [8], subjects: [{ id: 10, name: 'Science', teacher_ids: [8] }] }], rooms: [{ id: 4, name: 'Practice Hall', capacity: null, active: true }], teachers: [{ id: 8, name: 'Practice Teacher' }], class_subjects_enabled: true };
    const week = { days: data.days.map((d: any) => ({ ...d, periods: data.sets.find((s: any) => s.id === d.period_set_id).periods })), meetings: [{ id: 9, group_id: 3, weekday: 1, period_id: 21, kind: 'subject', class_subject_id: 10, label: 'Science', room_id: 4, room_name: 'Practice Hall', teachers: [data.teachers[0]], effective_from: '2026-10-10' }] };
    const clash = { kind: 'teacher', name: 'Practice Teacher', meetings: [{ group_name: 'Practice Other', label: 'Reading', starts_at: '08:00', ends_at: '09:00' }], effective_from: '2026-10-10', effective_until: '2027-06-25' };
    if (zeroRooms) { data.rooms = []; data.classes[0].room_id = null; week.meetings[0].room_id = null; week.meetings[0].room_name = null; }
    const calls: any[] = []; let conflict = false; let confirmationRound = 0; let hold = false; let release: (() => void) | null = null;
    const api = { get: async (url: string) => { calls.push({ method: 'get', url }); if (url.includes('/week') && hold) await new Promise<void>(resolve => { release = resolve; }); return { data: { data: url.includes('/week') ? week : url.includes('/clashes') ? [clash] : data } }; }, post: async (url: string, body: any) => { calls.push({ method: 'post', url, body }); if (conflict && (!body.confirm_clashes || confirmationRound++ === 0)) throw httpError(409, { clashes: body.confirm_clashes ? [{ ...clash, name: 'Practice New Clash' }] : [clash], clash_fingerprint: body.confirm_clashes ? 'b'.repeat(64) : 'a'.repeat(64) }); return { data: { data: {} } }; }, put: async (url: string, body: any) => { calls.push({ method: 'put', url, body }); return { data: { data: {} } }; }, deleteWithBody: async (url: string, body: any) => { calls.push({ method: 'delete', url, body }); return { data: { data: {} } }; }, delete: async (url: string, body: any) => { calls.push({ method: 'delete', url, body }); return { data: { data: {} } }; } };
    const s = await mountSfc('views/dashboard/SchoolTimetableView.vue', {}, { vue, '@/core/services/ApiService': { default: api }, '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { capabilities: { school_timetable: on } } }) } }); await flush();
    return { ...s, calls, holdWeek: () => { hold = true; }, releaseWeek: () => { hold = false; release?.(); }, conflict: () => { conflict = true; } };
}
test('Timetable OFF makes no requests and hides controls', async () => { const s = await screen(false); try { assert.equal(s.calls.length, 0); assert.doesNotMatch(s.text(), /Add period set|Place meeting/); } finally { s.unmount(); } });
test('Timetable setup mounted edits sets locations weekdays and usual location', async () => {
    const s = await screen();
    try {
        click(s.button('Setup')); await flush(); assert.match(s.text(), /This changes the times for the whole year/);
        click(s.button('Add period set')); await flush(); type(s.all(n => n.props.id === 'set-name')[0], 'Practice New');
        click(s.all(n=>n.tag==='button' && n.textContent==='Add period')[0]); await flush(); type(s.all(n=>n.props.id==='period-name-0')[0],'P1'); await flush(); submit(s.all(n=>n.tag==='form')[0]); await flush();
        assert.equal(s.calls.find(c=>c.method==='post').body.name,'Practice New');
        click(s.button('Edit Practice Hall')); await flush(); type(s.all(n=>n.props.id==='room-name')[0], 'Practice Library'); await flush(); submit(s.all(n=>n.tag==='form')[0]); await flush();
        assert.deepEqual(s.calls.find(c=>c.url.endsWith('/rooms/4')).body,{name:'Practice Library',capacity:null,active:true});
        const day=s.all(n=>n.props.id==='school-day-1')[0]; day.value='2'; day.props.onChange({target:day}); await flush();
        assert.deepEqual(s.calls.find(c=>c.url.endsWith('/days')).body,{group_id:null,days:[{weekday:1,period_set_id:2}]});
        const usual=s.all(n=>n.props.id==='usual-room-3')[0]; usual.value=''; usual.props.onChange({target:usual}); await flush();
        assert.deepEqual(s.calls.find(c=>c.url.endsWith('/classes/3/room')).body,{room_id:null});
        assert.match(s.text(), /Locations|Location/); assert.doesNotMatch(s.text(), /Rooms|Usual room/);
    } finally { s.unmount(); }
});
test('Timetable class week has each weekday own times as of control and filled cell', async () => {
    const s = await screen();
    try {
        const columns=s.all(n=>n.tag==='section' && n.props.class==='tt-day'); assert.equal(columns.length,2);
        assert.match(columns[0].textContent,/Monday/); assert.match(columns[0].textContent,/08:00/); assert.match(columns[0].textContent,/09:00/); assert.doesNotMatch(columns[0].textContent,/08:30/);
        assert.match(columns[1].textContent,/Friday/); assert.match(columns[1].textContent,/08:30/); assert.match(columns[1].textContent,/09:15/); assert.doesNotMatch(columns[1].textContent,/08:00/);
        assert.match(s.text(),/Science/); assert.match(s.text(),/Practice Teacher/); assert.match(s.text(),/Practice Hall/);
        type(s.all(n=>n.props.id==='as-of')[0], '2026-11-01'); await flush(); assert.ok(s.calls.some(c=>c.url.includes('as_of=2026-11-01')));
        click(s.button('Science')); await flush(); assert.match(s.text(), /From which date/); assert.match(s.text(),/Remove meeting/);
    } finally { s.unmount(); }
});
test('Timetable Save anyway echoes reviewed fingerprint and a fresh 409 replaces the list', async () => {
    const s = await screen();
    try {
        s.conflict(); click(s.button('Place meeting')); await flush(); select(s.all(n=>n.props.id==='meeting-kind')[0], 'activity'); await flush(); type(s.all(n=>n.props.id==='activity-name')[0], 'Break duty'); await flush(); submit(s.all(n=>n.tag==='form')[0]); await flush();
        assert.match(s.text(), /Practice Other/); assert.match(s.text(),/Reading/); assert.match(s.text(),/Go back/);
        click(s.button('Save anyway')); await flush(); let saves=s.calls.filter(c=>c.method==='post');
        assert.deepEqual(saves[1].body,{...saves[0].body,confirm_clashes:true,clash_fingerprint:'a'.repeat(64)});
        assert.match(s.text(),/Practice New Clash/); click(s.button('Save anyway')); await flush(); saves=s.calls.filter(c=>c.method==='post');
        assert.deepEqual(saves[2].body,{...saves[0].body,confirm_clashes:true,clash_fingerprint:'b'.repeat(64)});
    } finally { s.unmount(); }
});
test('Timetable clashes panel and header count mount', async () => { const s=await screen(); try { click(s.button('Clashes (1)')); await flush(); assert.match(s.text(), /Practice Teacher|Practice Other|Reading|08:00/); } finally { s.unmount(); } });

test('Timetable as-of date clears earlier cells while the new dated read is pending', async () => {
    const s=await screen();
    try { assert.match(s.text(),/Science/); s.holdWeek(); type(s.all(n=>n.props.id==='as-of')[0],'2026-11-01'); await flush(); assert.doesNotMatch(s.text(),/Science/); assert.match(s.text(),/Loading class week/); s.releaseWeek(); await flush(); assert.match(s.text(),/Science/); }
    finally { s.releaseWeek(); s.unmount(); }
});

test('Timetable zero locations names the class and has no empty location labels or required setup', async () => {
    const s=await screen(true,true);
    try {
        assert.match(s.text(), /Practice Class/); assert.doesNotMatch(s.text(), /No room|Room:|Location:|[—]/);
        assert.equal(s.all(n=>n.tag==='p' && n.textContent==='').length,0);
        click(s.button('Setup')); await flush(); assert.match(s.text(),/Locations are optional/); assert.doesNotMatch(s.text(),/Rooms|Usual room/);
        click(s.button('Class week')); await flush(); click(s.button('Place meeting')); await flush(); assert.match(s.text(),/Location \(optional\)/); assert.doesNotMatch(s.text(),/Room/);
    } finally { s.unmount(); }
});
