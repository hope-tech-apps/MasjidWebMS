import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mountSfc, compileSfc, loadTs, flush, click, press, type, select, submit, check, httpError, deferred } from './support/mountSfc.ts';
const require = createRequire(import.meta.url); const vue = require('vue');

// One PE teacher takes two classes at 2:00 PM: the office says so once and every schedule reads it as one lesson.
const period = { id: 21, name: 'P6', starts_at: '14:00', ends_at: '14:45', kind: 'teaching', position: 1 };
const coach = { id: 8, name: 'Practice Coach' };
const lesson = (id: number, group: number, name: string, extra: any = {}) => ({ id, group_id: group, group_name: name, weekday: 1, period_id: 21, kind: 'activity', class_subject_id: null, activity_name: 'PE', label: 'PE', room_id: null, room_name: null, starts_at: '14:00', ends_at: '14:45', teachers: [coach], effective_from: '2026-10-10', effective_until: null, ...extra });
const clash = (a: any, b: any, extra: any = {}) => ({ kind: 'teacher', id: 8, name: 'Practice Coach', weekday: 1, effective_from: '2026-10-10', effective_until: '2027-06-25', meetings: [a, b], ...extra });
const seniors = lesson(31, 4, 'Practice Seniors'); const thirds = lesson(32, 5, 'Practice Thirds');
const brief = ({ id, group_id, group_name }: any) => ({ id, group_id, group_name });

async function office(options: any = {}) {
    const year = { id: 7, label: 'Practice year', first_day: '2026-09-07', last_day: '2027-06-25' };
    const data: any = { today: '2026-10-10', years: [year], year, weekdays: [1, 3], sets: [{ id: 1, name: 'Regular', periods: [period] }], days: [{ weekday: 1, period_set_id: 1 }, { weekday: 3, period_set_id: 1 }], class_days: [], classes: [{ id: 3, name: 'Practice Juniors', room_id: null, teachers: [8], subjects: [] }], rooms: [], teachers: [coach], class_subjects_enabled: false };
    const week: any = { state: 'meetings', days: data.days.map((d: any) => ({ ...d, periods: [period] })), meetings: options.meetings ?? [], clashes: options.clashes ?? [] };
    const calls: any[] = []; const answers: any[] = [...(options.answers ?? [])];
    const write = (method: string) => async (url: string, body?: any) => { calls.push({ method, url, body }); const next = answers.shift(); if (next) throw next; return { data: { data: {} } }; };
    const api = { get: async (url: string) => { calls.push({ method: 'get', url }); if (url.includes('/joint') && options.pending) await options.pending.promise; return { data: { data: url.includes('/joint') ? options.joint : url.includes('/week') ? week : data } }; }, post: write('post'), put: write('put'), delete: write('delete'), deleteWithBody: write('delete') };
    const dates = await loadTs('core/types/data/masjid-related/SchoolCalendar.ts', {}); const times = await loadTs('core/helpers/scheduledSend.ts', {}); const committed = await loadTs('composables/useCommittedDate.ts', { vue });
    const s = await mountSfc('views/dashboard/SchoolTimetableView.vue', {}, { vue, '@/composables/useCommittedDate': committed, '@/core/types/data/masjid-related/SchoolCalendar': dates, '@/core/helpers/scheduledSend': times, './OfficeSchedules.vue': { default: vue.defineComponent({ render: () => null }) }, '@/core/services/ApiService': { default: api }, '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { capabilities: { school_timetable: true } } }) } });
    await flush(); click(s.button('Class week')); await flush();
    const writes = () => calls.filter(c => c.method !== 'get');
    const boxes = (ids: number[]) => s.all(n => n.tag === 'input' && n.props.type === 'checkbox' && ids.includes(n.props.value));
    return { ...s, calls, writes, boxes, hasButton: (label: string) => s.all(n => n.tag === 'button' && n.textContent.includes(label)).length > 0 };
}
const together = 'These classes take this lesson together';
// The screen reaches its dialog through a ref, so the element it focuses arrives wrapped by Vue: unwrap it to compare
// (with ===: a failed deep comparison of two mounted elements would print the whole screen).
const focused = () => vue.toRaw((globalThis as any).document.activeElement);
async function placePe(s: any) {
    click(s.all((n: any) => n.tag === 'button' && n.textContent === 'Place meeting')[0]); await flush();
    select(s.all((n: any) => n.props.id === 'meeting-kind')[0], 'activity'); await flush(); type(s.all((n: any) => n.props.id === 'activity-name')[0], 'PE'); await flush();
    submit(s.all((n: any) => n.tag === 'form')[0]); await flush();
}

test('Timetable joint lesson: a save that clashes with another class at the same time offers the joint choice beside Save anyway and saves it', async () => {
    const mine = lesson(0, 3, 'Practice Juniors');
    const s = await office({ answers: [httpError(409, { status: 'clashes', clashes: [clash(seniors, mine)], clash_fingerprint: 'a'.repeat(64) })] });
    try {
        await placePe(s);
        assert.match(s.text(), /Practice Coach is in two places: Practice Seniors \(PE\) and Practice Juniors \(PE\), Monday 2:00 - 2:45 PM\./);
        assert.ok(s.hasButton('Save anyway')); assert.ok(s.hasButton('Go back')); assert.doesNotMatch(s.text(), /These clashes remain/);
        const [box] = s.boxes([31]); assert.equal(box.checked, true, 'one class at that time: the choice is made and named'); assert.match(box.parent!.textContent, /Practice Seniors \(PE\)/);
        click(s.button(together)); await flush();
        const [first, second] = s.writes();
        assert.equal(second.method, 'post'); assert.equal(second.url, first.url);
        assert.deepEqual(second.body, { ...first.body, joint_with: [31], confirm_clashes: false });
        assert.ok(!('clash_fingerprint' in second.body), 'saying so is a new submission, never a confirmation of the reviewed clashes');
        assert.equal(s.all(n => n.props.role === 'dialog').length, 0, 'the lesson is saved and the dialog is gone');
        assert.ok(s.calls.filter(c => c.url.endsWith('/setup')).length >= 2, 'the year is read again');
    } finally { s.unmount(); }
});

test('Timetable joint lesson: what still clashes after the joint choice is shown and needs its own Save anyway', async () => {
    const mine = lesson(0, 3, 'Practice Juniors'); const later = lesson(33, 6, 'Practice Fourths', { label: 'Reading', starts_at: '14:30', ends_at: '15:15' });
    const s = await office({ answers: [
        httpError(409, { status: 'clashes', clashes: [clash(seniors, mine), clash(thirds, mine), clash(later, mine)], clash_fingerprint: 'a'.repeat(64) }),
        httpError(409, { status: 'clashes', clashes: [clash(later, { ...mine, joint: [brief(seniors), brief(thirds)] })], clash_fingerprint: 'b'.repeat(64) }),
    ] });
    try {
        await placePe(s);
        const boxes = s.boxes([31, 32, 33]); assert.deepEqual(boxes.map(b => b.props.value), [31, 32], 'the lesson at 2:30 is not the same lesson and is not offered');
        assert.ok(boxes.every(b => !b.checked), 'with more than one class at that time nothing is chosen for the office');
        assert.equal(s.button(together).disabled, true); assert.equal(click(s.button(together)), false); assert.equal(s.writes().length, 1);
        check(boxes[0], true); await flush(); check(boxes[1], true); await flush();
        click(s.button(together)); await flush();
        const [first, second] = s.writes();
        assert.deepEqual(second.body.joint_with, [31, 32], 'both classes at 2:00 were ticked, so both are named');
        // The server (scripted here) worked the clashes out again with those lessons joined and reports the one that is left.
        assert.match(s.text(), /The classes will take this lesson together\. These clashes remain\./);
        assert.match(s.text(), /Practice Fourths \(Reading\)/); assert.ok(!s.hasButton(together), 'nothing more at the same time is left to join');
        click(s.button('Save anyway')); await flush();
        const third = s.writes()[2];
        assert.deepEqual(third.body, { ...second.body, confirm_clashes: true, clash_fingerprint: 'b'.repeat(64) });
        assert.deepEqual(third.body.joint_with, [31, 32]); assert.deepEqual({ ...first.body, joint_with: [31, 32] }, second.body);
    } finally { s.unmount(); }
});

test('Timetable joint lesson: a real double booking at the same time is left out of the joint choice and stays a clash', async () => {
    // Seniors' PE is the lesson really shared (same coach). Thirds' Art is in the same hall at that time with another teacher: a real clash.
    const mine = lesson(0, 3, 'Practice Juniors', { room_name: 'Practice Hall' }); const art = lesson(32, 5, 'Practice Thirds', { label: 'Art', activity_name: 'Art', teachers: [{ id: 9, name: 'Practice Painter' }], room_name: 'Practice Hall' });
    const room = (a: any, b: any) => clash(a, b, { kind: 'room', id: 4, name: 'Practice Hall' });
    const s = await office({ answers: [
        httpError(409, { status: 'clashes', clashes: [clash(seniors, mine), room(art, mine)], clash_fingerprint: 'a'.repeat(64) }),
        httpError(409, { status: 'clashes', clashes: [room(art, { ...mine, joint: [brief(seniors)] })], clash_fingerprint: 'b'.repeat(64) }),
    ] });
    try {
        await placePe(s);
        assert.match(s.text(), /Tick only the classes that take this lesson together with Practice Juniors\. A clash with a class you leave unticked stays a clash\./);
        const [pe, hall] = s.boxes([31, 32]); assert.match(pe.parent!.textContent, /Practice Seniors \(PE\)/); assert.match(hall.parent!.textContent, /Practice Thirds \(Art\)/);
        assert.ok(!pe.checked && !hall.checked);
        check(pe, true); await flush(); click(s.button(together)); await flush();
        const [first, second] = s.writes();
        assert.deepEqual(second.body, { ...first.body, joint_with: [31], confirm_clashes: false }, 'only the ticked lesson is named');
        // The hall clash is what remains. It is still offered, never chosen for the office, and Save anyway confirms it as a clash.
        assert.match(s.text(), /These clashes remain\./); assert.match(s.text(), /Practice Hall is used by two classes: Practice Thirds \(Art\) and Practice Juniors \(PE\)/);
        const [left] = s.boxes([32]); assert.equal(s.boxes([31]).length, 0); assert.ok(!left.checked, 'a class the office has just left out is not ticked for it'); assert.equal(s.button(together).disabled, true);
        click(s.button('Save anyway')); await flush();
        assert.deepEqual(s.writes()[2].body, { ...second.body, confirm_clashes: true, clash_fingerprint: 'b'.repeat(64) });
        assert.deepEqual(s.writes()[2].body.joint_with, [31]);
    } finally { s.unmount(); }
});

test('Timetable joint lesson: the joint choice after a confirmed review drops that review and submits afresh', async () => {
    const mine = lesson(0, 3, 'Practice Juniors'); const other = lesson(34, 7, 'Practice Fifths', { label: 'Reading', starts_at: '14:30', ends_at: '15:15' });
    const s = await office({ answers: [
        httpError(409, { status: 'clashes', clashes: [clash(other, mine)], clash_fingerprint: 'a'.repeat(64) }),
        // While the office was reading, the seniors' lesson was placed at the same time: a fresh list, and now a joint choice.
        httpError(409, { status: 'clashes', clashes: [clash(other, mine), clash(seniors, mine)], clash_fingerprint: 'b'.repeat(64) }),
        httpError(409, { status: 'clashes', clashes: [clash(other, { ...mine, joint: [brief(seniors)] })], clash_fingerprint: 'c'.repeat(64) }),
    ] });
    try {
        await placePe(s); assert.ok(!s.hasButton(together));
        click(s.button('Save anyway')); await flush(); assert.match(s.text(), /The clashes have changed/);
        click(s.button(together)); await flush();
        const [first, confirmed, joined] = s.writes();
        assert.equal(confirmed.body.clash_fingerprint, 'a'.repeat(64));
        assert.deepEqual(joined.body, { ...first.body, joint_with: [31], confirm_clashes: false });
        assert.doesNotMatch(s.text(), /The clashes have changed/); assert.match(s.text(), /These clashes remain/);
        click(s.button('Save anyway')); await flush();
        assert.deepEqual(s.writes()[3].body, { ...joined.body, confirm_clashes: true, clash_fingerprint: 'c'.repeat(64) });
    } finally { s.unmount(); }
});

test('Timetable joint lesson: a clash with a lesson at another time, or while copying a day, offers only Save anyway', async () => {
    const mine = lesson(0, 3, 'Practice Juniors'); const overlapping = lesson(33, 6, 'Practice Fourths', { starts_at: '14:30', ends_at: '15:15' });
    const s = await office({ answers: [httpError(409, { status: 'clashes', clashes: [clash(overlapping, mine)], clash_fingerprint: 'a'.repeat(64) })] });
    try { await placePe(s); assert.ok(s.hasButton('Save anyway')); assert.ok(!s.hasButton(together)); } finally { s.unmount(); }
    const copy = await office({ meetings: [lesson(9, 3, 'Practice Juniors')], answers: [httpError(409, { status: 'clashes', clashes: [clash(seniors, mine)], clash_fingerprint: 'a'.repeat(64) })] });
    try {
        click(copy.button('Copy Monday to')); await flush(); check(copy.all(n => n.tag === 'input' && n.props.type === 'checkbox')[0], true); await flush(); submit(copy.all(n => n.tag === 'form')[0]); await flush();
        assert.equal(copy.writes()[0].url.endsWith('/copy-day'), true); assert.ok(copy.hasButton('Save anyway')); assert.ok(!copy.hasButton(together));
    } finally { copy.unmount(); }
});

test('Timetable joint lesson: the Clashes list joins two existing lessons at the same time and leaves other clashes alone', async () => {
    const juniors = lesson(9, 3, 'Practice Juniors'); const later = lesson(33, 6, 'Practice Fourths', { label: 'Reading', starts_at: '14:30', ends_at: '15:15' });
    const s = await office({ meetings: [juniors], clashes: [clash(juniors, seniors), clash(juniors, later)] });
    try {
        click(s.button('Clashes (2)')); await flush();
        assert.match(s.text(), /If two classes take a lesson together, say so and it is no longer a clash\./);
        assert.equal(s.all(n => n.tag === 'button' && n.textContent === together).length, 1, 'only the pair at the same time');
        assert.equal(s.button(together).props['aria-label'], 'These classes take this lesson together: Practice Juniors and Practice Seniors', 'read out of context, the button names its pair');
        click(s.button(together)); await flush();
        assert.deepEqual(s.writes(), [{ method: 'put', url: '/api/admin/masjids/1/timetable/years/7/meetings/9/joint', body: { meeting_ids: [31] } }]);
        assert.ok(s.calls.at(-1).url.includes('/week?'), 'the clashes are read again');
    } finally { s.unmount(); }
});

test('Timetable joint lesson: a clash list with nothing at the same time is the list it was', async () => {
    const juniors = lesson(9, 3, 'Practice Juniors'); const later = lesson(33, 6, 'Practice Fourths', { starts_at: '14:30', ends_at: '15:15' });
    const s = await office({ meetings: [juniors], clashes: [clash(juniors, later)] });
    try { click(s.button('Clashes (1)')); await flush(); assert.ok(!s.hasButton(together)); assert.doesNotMatch(s.text(), /take a lesson together/); } finally { s.unmount(); }
});

test('Timetable joint lesson: the class week names the other class and the lesson dialog takes the class out', async () => {
    const s = await office({ meetings: [lesson(9, 3, 'Practice Juniors', { joint: [{ ...brief(seniors), effective_from: '2026-10-10', effective_until: null }] })] });
    try {
        const cell = s.all(n => n.tag === 'article' && String(n.props.class).includes('tt-cell'))[0];
        assert.match(cell.textContent, /PE Practice Coach With Practice Seniors/);
        click(s.button('PE')); await flush();
        assert.match(s.text(), /Practice Juniors takes this lesson together with Practice Seniors\./);
        assert.match(s.text(), /This applies to the lesson as it is stored, and to later dated changes of it, not from the date below\./);
        assert.equal(s.calls.filter(c => c.url.includes('/joint')).length, 0, 'nothing is asked until the office asks');
        click(s.button('Take Practice Juniors out of the joint lesson')); await flush();
        assert.deepEqual(s.writes(), [{ method: 'delete', url: '/api/admin/masjids/1/timetable/years/7/meetings/9/joint', body: undefined }]);
        assert.equal(s.all(n => n.props.role === 'dialog').length, 0);
    } finally { s.unmount(); }
});

test('Timetable joint lesson: an existing lesson chooses the classes it is taken with, and an ordinary lesson asks for nothing', async () => {
    const candidates = [{ ...brief(seniors), label: 'PE', effective_from: '2026-10-10', effective_until: null }, { ...brief(thirds), label: 'Games', effective_from: '2026-10-10', effective_until: '2026-12-18' }];
    const s = await office({ meetings: [lesson(9, 3, 'Practice Juniors')], joint: { meeting: {}, joint: [], candidates } });
    try {
        assert.doesNotMatch(s.text(), /With /);
        click(s.button('PE')); await flush();
        assert.doesNotMatch(s.text(), /takes this lesson together with/); assert.ok(!s.hasButton('out of the joint lesson')); assert.ok(!s.hasButton(together));
        assert.doesNotMatch(s.text(), /as it is stored/, 'an ordinary lesson gains the one button and no more words');
        assert.equal(s.calls.filter(c => c.url.includes('/joint')).length, 0);
        click(s.button('Take this lesson together with another class')); await flush();
        assert.equal(s.calls.filter(c => c.url.endsWith('/meetings/9/joint')).length, 1);
        assert.ok(focused() === s.boxes([31])[0], 'the button that asked is gone: focus is on the first class to choose');
        assert.match(s.text(), /Practice Seniors \(PE\)/); assert.match(s.text(), /Practice Thirds \(Games\), from October 10, 2026 until December 18, 2026/);
        const join = s.button(together); assert.equal(join.disabled, true, 'nothing is chosen yet');
        const boxes = s.all(n => n.tag === 'input' && n.props.type === 'checkbox' && [31, 32].includes(n.props.value));
        assert.equal(boxes.length, 2); check(boxes[0], true); await flush();
        click(s.button(together)); await flush();
        assert.deepEqual(s.writes(), [{ method: 'put', url: '/api/admin/masjids/1/timetable/years/7/meetings/9/joint', body: { meeting_ids: [31] } }]);
        assert.equal(s.all(n => n.props.role === 'dialog').length, 0);
    } finally { s.unmount(); }
    const empty = await office({ meetings: [lesson(9, 3, 'Practice Juniors')], joint: { meeting: {}, joint: [], candidates: [] } });
    try {
        click(empty.button('PE')); await flush(); click(empty.button('Take this lesson together with another class')); await flush();
        assert.match(empty.text(), /No other class has a lesson at this time on Monday\./); assert.ok(!empty.hasButton(together));
        const said = empty.all(n => n.tag === 'p' && n.textContent.startsWith('No other class'))[0];
        assert.equal(said.props.role, 'status'); assert.ok(focused() === said, 'with nothing to choose, focus is on the sentence that says so');
    } finally { empty.unmount(); }
});

test('Timetable joint lesson: the lesson dialog shows a class joined from a later date and can take the lesson out', async () => {
    // As of 10 October the week row has no partner yet: the seniors' lesson it is joined to starts on 2 November.
    const later = { ...brief(seniors), label: 'PE', weekday: 1, starts_at: '14:00', ends_at: '14:45', effective_from: '2026-11-02', effective_until: null };
    const s = await office({ meetings: [lesson(9, 3, 'Practice Juniors')], joint: { meeting: {}, joint: [later], candidates: [] } });
    try {
        click(s.button('PE')); await flush();
        assert.doesNotMatch(s.text(), /takes this lesson together with/); assert.ok(!s.hasButton('out of the joint lesson'));
        click(s.button('Take this lesson together with another class')); await flush();
        assert.match(s.text(), /Practice Juniors takes this lesson together with Practice Seniors \(from November 2, 2026\)\./);
        assert.match(s.text(), /No further class has a lesson at this time on Monday\./); assert.doesNotMatch(s.text(), /No other class/);
        click(s.button('Take Practice Juniors out of the joint lesson')); await flush();
        assert.deepEqual(s.writes(), [{ method: 'delete', url: '/api/admin/masjids/1/timetable/years/7/meetings/9/joint', body: undefined }]);
    } finally { s.unmount(); }
    // A partner in effect on the date shown is named plainly, once, however many dated rows it has; one that has ended says until when.
    const rows = [{ ...later, effective_from: '2026-10-10', effective_until: '2026-11-01' }, later, { ...brief(thirds), label: 'PE', effective_from: '2026-09-07', effective_until: '2026-10-04' }];
    const dated = await office({ meetings: [lesson(9, 3, 'Practice Juniors', { joint: [{ ...brief(seniors), effective_from: '2026-10-10', effective_until: '2026-11-01' }] })], joint: { meeting: {}, joint: rows, candidates: [] } });
    try {
        click(dated.button('PE')); await flush(); click(dated.button('Take this lesson together with another class')); await flush();
        assert.match(dated.text(), /takes this lesson together with Practice Seniors, Practice Thirds \(until October 4, 2026\)\./);
    } finally { dated.unmount(); }
});

test('Timetable joint lesson: joining and taking out wait while the form has unsaved changes, and reading the list is not saving', async () => {
    const candidates = [{ ...brief(seniors), label: 'PE', effective_from: '2026-10-10', effective_until: null }]; const pending = deferred();
    const s = await office({ meetings: [lesson(9, 3, 'Practice Juniors', { joint: [{ ...brief(thirds), effective_from: '2026-10-10', effective_until: null }] })], joint: { meeting: {}, joint: [{ ...brief(thirds), effective_from: '2026-10-10', effective_until: null }], candidates }, pending });
    try {
        click(s.button('PE')); await flush();
        const out = () => s.button('Take Practice Juniors out of the joint lesson'); assert.equal(out().disabled, false);
        click(s.button('Take this lesson together with another class')); await flush();
        // While the list is on its way the form is not being saved, and says so nowhere.
        assert.match(s.text(), /Loading the other classes' lessons…/); assert.ok(s.hasButton('Save')); assert.ok(!s.hasButton('Saving…')); assert.equal(s.button('Save').disabled, false);
        pending.resolve(null); await flush();
        assert.doesNotMatch(s.text(), /Loading the other classes/);
        check(s.boxes([31])[0], true); await flush(); assert.equal(s.button(together).disabled, false);
        // A change to the lesson itself (here its name) would be thrown away by either button: both wait, and the screen says why.
        const name = s.all(n => n.props.id === 'activity-name')[0]; type(name, 'Games'); await flush();
        assert.equal(out().disabled, true); assert.equal(s.button(together).disabled, true); assert.match(s.text(), /Save or cancel your changes to this lesson first\./);
        assert.equal(click(out()), false); assert.equal(click(s.button(together)), false); assert.deepEqual(s.writes(), []);
        // A tap that lands before the buttons are redrawn as disabled does nothing either.
        press(out()); press(s.button(together)); await flush(); assert.deepEqual(s.writes(), []); assert.equal(s.all(n => n.props.role === 'dialog').length, 1);
        type(name, 'PE'); await flush();
        assert.equal(out().disabled, false); assert.equal(s.button(together).disabled, false); assert.doesNotMatch(s.text(), /Save or cancel your changes/);
        // "From which date" is part of the form too: these buttons never act from that date.
        const from = s.all(n => n.props.id === 'effective-from')[0]; type(from, '2026-11-02'); await flush();
        assert.equal(out().disabled, true); assert.equal(s.button(together).disabled, true);
        type(from, '2026-10-10'); await flush();
        click(s.button(together)); await flush();
        assert.deepEqual(s.writes(), [{ method: 'put', url: '/api/admin/masjids/1/timetable/years/7/meetings/9/joint', body: { meeting_ids: [31] } }]);
    } finally { s.unmount(); }
});

async function cards() {
    const dates = await loadTs('core/types/data/masjid-related/SchoolCalendar.ts', {}); const times = await loadTs('core/helpers/scheduledSend.ts', {});
    const link = vue.defineComponent({ props: ['to'], setup: (props: any, { slots }: any) => () => vue.h('a', { href: props.to }, slots.default?.()) });
    const meeting = await compileSfc('views/teacher/ScheduleMeeting.vue', { vue, '@/core/types/data/masjid-related/SchoolCalendar': dates, '@/core/helpers/scheduledSend': times, 'vue-router': { RouterLink: link } });
    const week = await compileSfc('views/teacher/ScheduleWeek.vue', { '@/core/types/data/masjid-related/SchoolCalendar': dates, '@/core/helpers/scheduledSend': times, './ScheduleMeeting.vue': { default: meeting } });
    return { dates, times, meeting, week };
}

test('Timetable joint lesson: My schedule shows one lesson naming both classes, each with its own link, and an ordinary lesson as before', async () => {
    const { dates, times, meeting, week } = await cards(); const calls: string[] = [];
    const slot = (extra: any = {}) => ({ id: 1, group_id: 3, group_name: 'Practice Juniors', class_subject_id: null, kind: 'activity', label: 'PE', starts_at: '14:00', ends_at: '14:45', room_name: null, co_teachers: [], effective_from: '2026-09-07', effective_until: null, href: '/teacher/classes/3', ...extra });
    const joint = slot({ joint: [{ id: 2, group_id: 4, group_name: 'Practice Seniors', href: '/teacher/classes/4' }, { id: 5, group_id: 6, group_name: 'Practice Visitors', href: null }] });
    const plain = slot({ id: 3, label: 'Library', starts_at: '12:45', ends_at: '13:30' });
    const page = { timezone: 'America/New_York', week_start: '2026-10-12', week_end: '2026-10-18', today: { date: '2026-10-12', state: 'meetings', reason: null, meetings: [plain, joint] }, days: [{ date: '2026-10-12', weekday: 1, reason: null, meetings: [plain, joint] }] };
    const api = { get: async (url: string) => { calls.push(url); return { data: { data: page } }; } };
    const s = await mountSfc('views/teacher/TeacherSchedule.vue', {}, { vue, '@/core/services/TeacherApiService': { default: api }, '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) }, '@/core/types/data/masjid-related/SchoolCalendar': dates, '@/core/helpers/scheduledSend': times, './ScheduleMeeting.vue': { default: meeting }, './ScheduleWeek.vue': { default: week } });
    await flush();
    try {
        const today = s.all(n => n.props['data-test'] === 'today-meeting'); assert.equal(today.length, 2, 'one card for the library lesson, one for PE with both classes');
        assert.match(today[1].textContent, /2:00 - 2:45 PM.*Practice Juniors With Practice Seniors , Practice Visitors PE/);
        const links = (card: any) => { const found: string[] = []; const walk = (n: any) => { if (n.tag === 'a') found.push(n.props.href); n.children.forEach(walk); }; walk(card); return found; };
        assert.deepEqual(links(today[1]), ['/teacher/classes/3', '/teacher/classes/4'], 'a class the teacher is not placed with is named, not linked');
        assert.doesNotMatch(today[0].textContent, /With/); assert.equal(s.all(n => n.props['data-test'] === 'joint').length, 2, 'today and the week, on the joint lesson only');
        assert.equal(calls.length, 1);
    } finally { s.unmount(); }
});

test('Timetable joint lesson: the whole-school grid shows a teacher one card for both classes and each class its own', async () => {
    const { dates, times, meeting, week } = await cards(); const committed = await loadTs('composables/useCommittedDate.ts', { vue });
    const helper = { id: 9, name: 'Practice Helper' }; const groups = [{ id: 3, name: 'Practice Juniors' }, { id: 4, name: 'Practice Seniors' }, { id: 5, name: 'Practice Thirds' }];
    const card = (id: number, group: number, teachers: any[], extra: any = {}) => ({ ...lesson(id, group, groups.find(g => g.id === group)!.name), teachers, co_teachers: teachers, href: null, column_key: 'period:21', ...extra });
    const a = card(1, 3, [coach], { joint: [{ id: 2, group_id: 4, group_name: 'Practice Seniors' }] }); const b = card(2, 4, [coach, helper], { joint: [{ id: 1, group_id: 3, group_name: 'Practice Juniors' }] }); const c = card(3, 5, [coach], { label: 'Reading' });
    const setup = { year: { id: 7, label: 'Practice year', first_day: '2026-09-07', last_day: '2027-06-25' }, today: '2026-10-12', weekdays: [1], teachers: [coach, helper], classes: groups, rooms: [] };
    const day = { date: '2026-10-12', weekday: 1, state: 'meetings', reason: null, periods: null, meetings: [a, b, c] };
    const page = { as_of: '2026-10-12', week_start: '2026-10-12', week_end: '2026-10-18', days: [day], lens: 'school', selection_id: null, weekday: 1, day, columns: [{ key: 'period:21', starts_at: '14:00', ends_at: '14:45', names: ['P6'], period_ids: [21] }], meetings: [a, b, c], clashes: [clash(a, c), clash(b, c)], placed_teacher_ids: [8, 9], teachers: [coach, helper], classes: groups, may_edit: true, as_of_state: 'meetings', as_of_reason: null };
    const api = { get: async () => ({ data: { data: page } }) };
    const s = await mountSfc('views/dashboard/OfficeSchedules.vue', { base: '/api/admin/masjids/1/timetable/years/7', setup }, { '@/composables/useCommittedDate': committed, '@/core/services/ApiService': { default: api }, '@/core/types/data/masjid-related/SchoolCalendar': dates, '@/core/helpers/scheduledSend': times, '../teacher/ScheduleMeeting.vue': { default: meeting }, '../teacher/ScheduleWeek.vue': { default: week } });
    await flush();
    try {
        const row = (id: number) => s.all(n => n.tag === 'tr' && n.props['data-row-id'] === id)[0];
        const shown = (r: any) => { const found: any[] = []; const walk = (n: any) => { if (n.props?.['data-meeting-id']) found.push(n); n.children.forEach(walk); }; walk(r); return found; };
        // The coach is placed with all three classes at 2:00: the joint lesson once, and the third class's lesson beside it.
        const coachCards = shown(row(8)); assert.deepEqual(coachCards.map(n => n.props['data-meeting-id']), [1, 3]);
        assert.match(coachCards[0].textContent, /Practice Juniors With Practice Seniors PE Practice Helper/, 'the other class and its extra teacher are on the one card');
        // The third class clashes with each class of the joint lesson, so the server sends two clashes: the joint card says it once.
        const badge = (n: any) => { let title: string | null = null; const walk = (x: any) => { if (String(x.props?.class ?? '').includes('badge')) title = x.props.title; x.children.forEach(walk); }; walk(n); return title; };
        assert.equal(badge(coachCards[0]), 'Practice Coach: Practice Thirds (Reading), 2:00 - 2:45 PM');
        assert.equal(badge(coachCards[1]), 'Practice Coach: Practice Juniors (PE), 2:00 - 2:45 PM; Practice Coach: Practice Seniors (PE), 2:00 - 2:45 PM');
        // The helper is placed with the seniors only: their card is the seniors' lesson, naming the juniors.
        const helperCards = shown(row(9)); assert.deepEqual(helperCards.map(n => n.props['data-meeting-id']), [2]);
        assert.match(helperCards[0].textContent, /Practice Seniors With Practice Juniors PE Practice Coach/); assert.equal(badge(helperCards[0]), null, 'a teacher clash belongs to the teacher it names');
        click(s.button('Classes')); await flush();
        assert.deepEqual(shown(row(3)).map(n => n.props['data-meeting-id']), [1]); assert.deepEqual(shown(row(4)).map(n => n.props['data-meeting-id']), [2]);
        assert.match(shown(row(3))[0].textContent, /With Practice Seniors/); assert.match(shown(row(4))[0].textContent, /With Practice Juniors/);
        assert.equal(badge(shown(row(3))[0]), 'Practice Coach: Practice Thirds (Reading), 2:00 - 2:45 PM'); assert.equal(badge(shown(row(4))[0]), 'Practice Coach: Practice Thirds (Reading), 2:00 - 2:45 PM');
        assert.doesNotMatch(shown(row(5))[0].textContent, /With/);
    } finally { s.unmount(); }
});
