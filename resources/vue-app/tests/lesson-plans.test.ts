/**
 * A day's lesson plans (core/helpers/lessonPlans.ts): one per subject, listed
 * in one order, a taken subject not offered twice, and the day view opening
 * the plan the teacher was on.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    canSavePlan,
    copyRequest,
    formTicket,
    jumpTarget,
    pickPlan,
    planDeleteUrl,
    planLabel,
    plansOn,
    planSaveRequest,
    subjectClash,
    subjectKey,
    takenSubjectKeys,
} from '../core/helpers/lessonPlans.ts';

const week = [
    { id: 7, session_date: '2026-09-14', subject: 'Science' },
    { id: 3, session_date: '2026-09-14T00:00:00.000000Z', subject: 'Math' },
    { id: 9, session_date: '2026-09-14', subject: null },
    { id: 4, session_date: '2026-09-15', subject: 'Math' },
];

test('a subject key ignores case and spacing, and blank is no subject', () => {
    assert.equal(subjectKey('  Math \t Facts '), 'math facts');
    assert.equal(subjectKey(null), '');
    assert.equal(subjectKey('   '), '');
});

test('a day lists every plan it holds, general first, then subjects alphabetically', () => {
    assert.deepEqual(plansOn(week, '2026-09-14').map((p) => p.id), [9, 3, 7]);
    assert.deepEqual(plansOn(week, '2026-09-15').map((p) => p.id), [4]);
    assert.deepEqual(plansOn(week, '2026-09-16'), []);
});

test('the same subject on the same day is a clash, whatever its case', () => {
    assert.equal(subjectClash(week, '2026-09-14', ' math ', null)?.id, 3);
    assert.equal(subjectClash(week, '2026-09-14', '', null)?.id, 9, 'a second general plan clashes too');
    assert.equal(subjectClash(week, '2026-09-14', 'Arabic', null), null);
    assert.equal(subjectClash(week, '2026-09-15', 'Science', null), null, 'another day is another day');
});

test('a plan keeping its own subject is not a clash with itself', () => {
    assert.equal(subjectClash(week, '2026-09-14', 'Math', 3), null);
    assert.equal(subjectClash(week, '2026-09-14', 'Science', 3)?.id, 7);
});

test('a subject already planned that day is taken, except by the plan being edited', () => {
    assert.deepEqual([...takenSubjectKeys(week, '2026-09-14', null)].sort(), ['', 'math', 'science']);
    assert.deepEqual([...takenSubjectKeys(week, '2026-09-14', 3)].sort(), ['', 'science']);
});

test('the day view stays on the plan asked for, else opens the first, else a new one', () => {
    assert.equal(pickPlan(week, '2026-09-14', 7), 7);
    assert.equal(pickPlan(week, '2026-09-14', 4), 9, 'a plan from another day is not this day\'s');
    assert.equal(pickPlan(week, '2026-09-14', null), 9);
    assert.equal(pickPlan(week, '2026-09-16', 7), null);
});

test('a plan with no subject calls itself General', () => {
    assert.equal(planLabel({ id: 1, session_date: '2026-09-14', subject: '  ' }), 'General');
    assert.equal(planLabel({ id: 1, session_date: '2026-09-14', subject: 'Math' }), 'Math');
});

test('a subject key is lower-cased one character at a time, as the server keys it', () => {
    // The server's simple case mapping: 'İ' is 'i', never 'i' plus a combining
    // dot, and a word-final capital sigma is 'σ' like any other.
    assert.equal(subjectKey('İslamic'), 'islamic');
    assert.equal(subjectKey('İslamic').length, 'İslamic'.length);
    assert.equal(subjectKey('ΟΔΟΣ'), 'οδοσ');
});

const base = '/api/teacher/masjids/14/groups/3';

test('the open plan is rewritten by its id, and a new plan is created', () => {
    assert.deepEqual(planSaveRequest(base, 7), { method: 'put', url: `${base}/lesson-plans/7` });
    assert.deepEqual(planSaveRequest(base, null), { method: 'post', url: `${base}/lesson-plans` });
});

test('removing a plan names it by id, so the day keeps its other subjects', () => {
    assert.equal(planDeleteUrl(base, 7), `${base}/lesson-plans/7`);
});

test('save waits for activities, refuses a subject the day already has, and does not double-send', () => {
    assert.equal(canSavePlan(false, 'Count to ten.', null), true);
    assert.equal(canSavePlan(false, '   ', null), false);
    assert.equal(canSavePlan(false, 'Count to ten.', week[1]), false);
    assert.equal(canSavePlan(true, 'Count to ten.', null), false);
});

test('copying a subject across the week rewrites that subject\'s plan by id and keeps its activities', () => {
    const plans = [
        { id: 3, session_date: '2026-09-14', subject: 'Math', body: 'Count to ten.', objective: 'Counting' },
        // Art sorts first on Tuesday: the day's FIRST plan is not its Math plan.
        { id: 8, session_date: '2026-09-15', subject: 'Art', body: 'Leaf rubbings.' },
        { id: 4, session_date: '2026-09-15', subject: 'math', body: 'Tuesday\'s own activities.' },
        { id: 5, session_date: '2026-09-15', subject: 'Science', body: 'Sink or float.' },
        { id: 6, session_date: '2026-09-16', subject: 'Math', body: '' },
    ];
    const source = plans[0];

    const tuesday = copyRequest(base, plans, source, '2026-09-15');
    assert.equal(tuesday.method, 'put');
    assert.equal(tuesday.url, `${base}/lesson-plans/4`, 'Tuesday\'s Math plan, not its Art or Science plan');
    assert.equal(tuesday.payload.body, 'Tuesday\'s own activities.');
    assert.equal(tuesday.payload.session_date, '2026-09-15');
    assert.equal((tuesday.payload as any).objective, 'Counting', 'the week\'s shared part is copied');

    const wednesday = copyRequest(base, plans, source, '2026-09-16');
    assert.equal(wednesday.payload.body, 'Count to ten.', 'a day with no activities of its own gets the source\'s');
});

test('copying onto a day without that subject creates a plan beside the others, never the by-day PUT', () => {
    const plans = [
        { id: 3, session_date: '2026-09-14', subject: 'Math', body: 'Count to ten.' },
        { id: 5, session_date: '2026-09-15', subject: 'Science', body: 'Sink or float.' },
    ];

    const tuesday = copyRequest(base, plans, plans[0], '2026-09-15');
    assert.equal(tuesday.method, 'post');
    assert.equal(tuesday.url, `${base}/lesson-plans`);
    assert.equal(tuesday.payload.body, 'Count to ten.');
    assert.equal(tuesday.payload.subject, 'Math');
});

test('an answer for a form that has since been replaced is recognised as stale', () => {
    const forms = formTicket();
    const ticket = forms.current();
    assert.equal(forms.isCurrent(ticket), true);

    forms.replace();
    assert.equal(forms.isCurrent(ticket), false);
    assert.equal(forms.isCurrent(forms.current()), true);
});

/**
 * The day view's wiring. The decisions above are only right if the component
 * asks them, at the moments that matter; these read its source, because the
 * suite has no component renderer and the moments are a handful of lines.
 */
const view = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');

/** One `const name = ... => { ... };` block of the component's script. */
const fn = (name: string): string => {
    const start = view.indexOf(`const ${name} = `);
    assert.ok(start !== -1, `TeacherClass.vue defines ${name}`);
    const end = view.indexOf('\n};\n', start);

    return view.slice(start, end);
};

test('the day view writes, removes and copies through the helpers', () => {
    assert.match(fn('savePlan'), /planSaveRequest\(base\.value, planId\.value\)/);
    assert.match(fn('deletePlan'), /TeacherApiService\.delete\(planDeleteUrl\(base\.value, planId\.value\)\)/);
    assert.match(fn('copyAcrossWeek'), /copyRequest\(base\.value, list, source, iso\)/);
    assert.doesNotMatch(fn('copyAcrossWeek'), /put\(`\$\{base\.value\}\/lesson-plans`/, 'never the by-day PUT');
    assert.match(view, /:disabled="!canSavePlan\(planSaving, planForm\.body, planClash\)"/);
});

test('changing day or reloading the week keeps the open plan when it is still there', () => {
    assert.match(view, /watch\(planDate, \(iso\) => selectPlan\(pickPlan\(plans\.value, iso, planId\.value\)\)\);/);
    assert.match(fn('loadLessonPlans'), /planId\.value = pickPlan\(plans\.value, planDate\.value, planId\.value\);\s+syncPlanForm\(\);/);
});

test('a guide fill or a save that answers after the teacher opened another plan does not land in it', () => {
    assert.match(fn('syncPlanForm'), /planForms\.replace\(\);/);

    const prefill = fn('prefillFromGuide');
    const asked = prefill.indexOf('await TeacherApiService.get(');
    const checked = prefill.indexOf('if (!planForms.isCurrent(ticket)) return;');
    assert.ok(prefill.indexOf('const ticket = planForms.current();') < asked, 'the ticket is taken before the request');
    assert.ok(asked < checked && checked < prefill.indexOf('autoFill('), 'and checked before anything is written');

    const save = fn('savePlan');
    assert.ok(save.indexOf('const ticket = planForms.current();') < save.indexOf('await TeacherApiService.'));
    assert.match(save, /if \(planForms\.isCurrent\(ticket\)\) planId\.value = savedId;/);
});

test('a jump back into the open day stays on the plan being written', () => {
    // Nothing asked for, or the open plan asked for: stay, keeping the draft.
    assert.equal(jumpTarget(week, '2026-09-14', null, 7), undefined);
    assert.equal(jumpTarget(week, '2026-09-14', 7, 7), undefined);
    assert.equal(jumpTarget(week, '2026-09-14', null, null), undefined, 'a new plan being written stays too');
    // Another plan of that day: open it.
    assert.equal(jumpTarget(week, '2026-09-14', 3, 7), 3);
    // A plan not on that day falls back to the day's first, as pickPlan does.
    assert.equal(jumpTarget(week, '2026-09-14', 4, 7), 9);

    assert.match(fn('jumpToDay'), /const next = jumpTarget\(plans\.value, iso, id, planId\.value\);\s+if \(next !== undefined\) selectPlan\(next\);/);
    assert.match(view, /@click="p\.id !== planId && selectPlan\(p\.id\)"/, 'the open plan\'s chip does nothing');
});

test('a save, copy or removal that answers late does not reload over the plan the teacher moved to', () => {
    const load = fn('loadLessonPlans');
    // The keep-or-resync question is asked AFTER the week's answer lands, and an
    // older week's answer landing after a newer one's is dropped.
    const landed = load.indexOf('await TeacherApiService.get(');
    assert.ok(landed < load.indexOf('if (seq !== plansSeq) return false;'));
    assert.ok(load.indexOf('if (seq !== plansSeq) return false;') < load.indexOf("const again = typeof resync === 'function' ? resync() : resync;"));
    assert.match(load, /if \(!again && \(planId\.value === null \|\| plans\.value\.some\(\(p\) => p\.id === planId\.value\)\)\) return false;/);

    const save = fn('savePlan');
    assert.match(save, /await loadLessonPlans\(\s*\(\) => planForms\.isCurrent\(ticket\) \|\| planId\.value === savedId\)/);
    assert.match(save, /planSaved\.value = resynced && planId\.value === savedId;/, '"Saved" only beside the saved plan');

    const copy = fn('copyAcrossWeek');
    assert.match(copy, /const sourceDay = planDate\.value;/);
    assert.match(copy, /copyRequest\(base\.value, list, source, iso\)/, 'the list as it was when the copy began');
    assert.match(copy, /await loadLessonPlans\(\(\) => planId\.value !== null && written\.has\(planId\.value\)\);/);

    const remove = fn('deletePlan');
    assert.ok(remove.indexOf('const ticket = planForms.current();') < remove.indexOf('await TeacherApiService.delete('));
    assert.match(remove, /else \{\s+await loadLessonPlans\(false\);/);

    assert.match(view, /watch\(weekStart, \(\) => loadLessonPlans\(\)\);/, 'a watcher passes its value, never a resync flag');
    assert.match(view, /if \(tab === 'lessons'\) \{ loadLessonPlans\(!lessonsLoaded\);/, 'coming back to the tab keeps the draft');
});

test('the open day moves with the week, so the day view reads the week that is loaded', () => {
    const shift = fn('shiftWeek');
    assert.ok(shift.indexOf('planDate.value = ') !== -1 && shift.indexOf('planDate.value = ') < shift.indexOf('weekStart.value = '));
});

test('a plan switch loads the subject and week lists once', () => {
    const sync = fn('syncPlanForm');
    assert.equal((sync.match(/loadCurriculum\(/g) ?? []).length, 1);
    assert.doesNotMatch(view, /syncCurriculum/);
});
