/**
 * A combined-grade class's day: one plan per subject PER GRADE
 * (core/helpers/lessonPlans.ts). The same subject planned for each grade the
 * class teaches is two plans, so the picker must not hide a subject another
 * grade already has, the form must not call it a clash, and copying a grade's
 * plan across the week must land on that grade's plan, never the other's.
 * A plan with no grade is a slot of its own only in a class taught as several
 * grades, as on the server.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import * as dates from '../core/types/data/masjid-related/SchoolCalendar.ts';
import { copyRequest, gradeKey, planLabel, severalGrades, subjectClash, takenSubjectKeys } from '../core/helpers/lessonPlans.ts';
import { flush, loadTs, mountSfc } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');

const base = '/api/teacher/masjids/1/groups/2';

const monday = [
    { id: 3, session_date: '2026-10-19', subject: 'Mathematics', grade_label: 'Pre-Kindergarten', class_subject_id: 5 },
    { id: 7, session_date: '2026-10-19', subject: 'Arabic', grade_label: null, class_subject_id: 6 },
];

test('another grade\'s plan for a subject does not take that subject from this grade', () => {
    assert.ok(!takenSubjectKeys(monday, '2026-10-19', null, false, 'Kindergarten').has('mathematics'),
        'the Kindergarten form must still offer Mathematics');
    assert.ok(takenSubjectKeys(monday, '2026-10-19', null, false, 'Pre-Kindergarten').has('mathematics'),
        'the Pre-K form must not offer it twice');

    assert.ok(!takenSubjectKeys(monday, '2026-10-19', null, true, 'Kindergarten').has('id:5'),
        'with class subjects on, the one Mathematics subject serves both grades');
    assert.ok(takenSubjectKeys(monday, '2026-10-19', null, true, 'Pre-Kindergarten').has('id:5'));
});

test('the same subject for another grade is no clash; for the same grade it is', () => {
    assert.equal(subjectClash(monday, '2026-10-19', 'Mathematics', null, false, null, 'Kindergarten'), null);
    assert.equal(subjectClash(monday, '2026-10-19', 'Mathematics', null, false, null, 'Pre-Kindergarten')?.id, 3);

    assert.equal(subjectClash(monday, '2026-10-19', 'Mathematics', null, true, 5, 'Kindergarten'), null);
    assert.equal(subjectClash(monday, '2026-10-19', 'Mathematics', null, true, 5, 'Pre-Kindergarten')?.id, 3);
});

test('in a class taught as several grades, a plan with no grade and a graded plan of its subject are two plans', () => {
    assert.equal(subjectClash(monday, '2026-10-19', 'Arabic', null, false, null, 'Kindergarten', true), null);
    assert.equal(subjectClash(monday, '2026-10-19', 'Arabic', null, false, null, null, true)?.id, 7);
    assert.ok(!takenSubjectKeys(monday, '2026-10-19', null, false, null, true).has('mathematics'),
        'a form with no grade is not blocked by a graded plan');
});

/**
 * Anywhere else (one grade on the roster, or none) a plan with no grade, which
 * every plan saved before grades were recorded is, IS the graded plan of its
 * subject, either way round, as on the server (LessonPlanController::ofGrade).
 * Two different grades still stay apart.
 */
test('in a class taught as one grade, a plan with no grade is that grade\'s plan of its subject', () => {
    assert.equal(subjectClash(monday, '2026-10-19', 'Arabic', null, false, null, 'Kindergarten')?.id, 7);
    assert.equal(subjectClash(monday, '2026-10-19', 'Arabic', null, true, 6, 'Kindergarten')?.id, 7);
    assert.equal(subjectClash(monday, '2026-10-19', 'Mathematics', null, false, null, null)?.id, 3, 'a form with no grade meets the graded plan');
    assert.ok(takenSubjectKeys(monday, '2026-10-19', null, false, 'Kindergarten').has('arabic'), 'Arabic is not offered again');
    assert.ok(takenSubjectKeys(monday, '2026-10-19', null, true, 'Kindergarten').has('id:6'));
    assert.ok(!takenSubjectKeys(monday, '2026-10-19', 7, false, 'Kindergarten').has('arabic'), 'the plan being edited takes nothing');
    assert.equal(subjectClash(monday, '2026-10-19', 'Mathematics', null, false, null, 'Kindergarten'), null, 'another grade is still another plan');

    // Copying a graded plan across the week rewrites the older ungraded plan of its subject, never adds a second.
    const week = [
        { id: 77, session_date: '2026-10-19', subject: 'Literacy', grade_label: '1', body: 'Source activities.' },
        { id: 78, session_date: '2026-10-20', subject: 'Literacy', grade_label: null, body: 'Historical activities.' },
    ];
    const copy = copyRequest(base, week, week[0], '2026-10-20');
    assert.equal(copy.method, 'put');
    assert.equal(copy.url, `${base}/lesson-plans/78`);
    assert.equal(copyRequest(base, week, week[0], '2026-10-20', false, true).method, 'post', 'in a class taught as several grades it is its own slot');
});

test('copying a grade\'s plan across the week lands on that grade\'s plan, never another grade\'s', () => {
    const plans = [
        { id: 3, session_date: '2026-10-19', subject: 'Mathematics', grade_label: 'Kindergarten', body: 'Count to twenty.' },
        { id: 4, session_date: '2026-10-20', subject: 'Mathematics', grade_label: 'Pre-Kindergarten', body: 'Pre-K Tuesday.' },
        { id: 5, session_date: '2026-10-21', subject: 'Mathematics', grade_label: 'Pre-Kindergarten', body: 'Pre-K Wednesday.' },
        { id: 6, session_date: '2026-10-21', subject: 'Mathematics', grade_label: 'Kindergarten', body: 'Kindergarten Wednesday.' },
    ];
    const source = plans[0];

    const tuesday = copyRequest(base, plans, source, '2026-10-20');
    assert.equal(tuesday.method, 'post', 'Tuesday has no Kindergarten Mathematics plan: a new one, not the Pre-K plan');
    assert.equal(tuesday.url, `${base}/lesson-plans`);
    assert.equal(tuesday.payload.body, 'Count to twenty.');
    assert.equal((tuesday.payload as any).grade_label, 'Kindergarten');

    const wednesday = copyRequest(base, plans, source, '2026-10-21');
    assert.equal(wednesday.method, 'put');
    assert.equal(wednesday.url, `${base}/lesson-plans/6`, 'Wednesday\'s Kindergarten plan, not its Pre-K one');
    assert.equal(wednesday.payload.body, 'Kindergarten Wednesday.');
});

test('two grades\' plans for one subject read differently, each naming its grade', () => {
    const preK = planLabel({ id: 3, session_date: '2026-10-19', subject: 'Mathematics', grade_label: 'Pre-Kindergarten' } as any);
    const kg = planLabel({ id: 4, session_date: '2026-10-19', subject: 'Mathematics', grade_label: 'Kindergarten' } as any);

    assert.notEqual(preK, kg);
    assert.match(preK, /Mathematics/);
    assert.match(preK, /Pre-Kindergarten/);
    assert.match(kg, /Mathematics/);
    assert.match(kg, /Kindergarten/);
});

test('one grade, however it is spelled, is one key, as on the server', () => {
    for (const label of ['Pre-K', 'PreK', 'Pre-Kindergarten', ' pre-kindergarten ', 'PK']) assert.equal(gradeKey(label), 'prek', label);
    for (const label of ['K', 'KG', 'Kindergarten']) assert.equal(gradeKey(label), 'kg', label);
    assert.equal(gradeKey('Grade 1'), '1');
    assert.equal(gradeKey('1st'), '1');
    assert.equal(gradeKey('first grade'), '1');
    assert.notEqual(gradeKey('Grade 1A'), gradeKey('Grade 1B'));
    assert.equal(gradeKey(null), '');
    assert.equal(gradeKey('   '), '');
    // A label with no Latin letter or digit is still a grade, never "no grade".
    assert.equal(gradeKey('الروضة'), 'الروضة');
    assert.notEqual(gradeKey('الروضة'), gradeKey('التمهيدي'));
    // An inherited property name is not a grade word.
    assert.equal(gradeKey('constructor'), 'constructor');

    // The same subject for "Pre-K" and "Pre-Kindergarten" is the same plan.
    assert.equal(subjectClash(monday, '2026-10-19', 'Mathematics', null, false, null, 'Pre-K')?.id, 3);
});

test('a class is taught as several grades when its roster or plans carry two', () => {
    assert.equal(severalGrades(['Pre-K', 'Pre-Kindergarten', null, '']), false, 'two spellings of one grade');
    assert.equal(severalGrades(['Pre-K', 'KG']), true);
    assert.equal(severalGrades([]), false);
    assert.equal(planLabel(monday[0], false), 'Mathematics', 'a one-grade class leaves the grade off');
    assert.equal(planLabel({ id: 9, session_date: '2026-10-19', subject: null, grade_label: 'Kindergarten' }), 'General · Kindergarten');
});

test('the office reads two grades\' plans of a subject as two labelled blocks', async () => {
    const helper = await loadTs('core/helpers/lessonPlans.ts', {});
    const s = await mountSfc('views/dashboard/groups/GroupLessonPlansTab.vue', { groupId: 1, masjidId: 1 }, {
        vue, '@/core/helpers/lessonPlans': helper, '@/core/types/data/masjid-related/SchoolCalendar': dates,
        '@/core/services/ApiService': { default: { get: async (url: string) => {
            const day = url.match(/from=([^&]+)/)![1];
            const plan = (id: number, grade: string, body: string) => ({ id, session_date: day, subject: 'Mathematics', grade_label: grade, body, teaching_methods: [], learning_outcomes: [], attachments: [] });
            return { data: { data: { plans: [plan(3, 'Pre-Kindergarten', 'Count to five.'), plan(4, 'Kindergarten', 'Count to twenty.')], hidden_fields: [], meeting_weekdays: [0, 1, 2, 3, 4, 5, 6] } } };
        } } },
    });
    await flush();
    try {
        const badges = s.all((n: any) => n.tag === 'span' && String(n.props.class).includes('badge') && String(n.textContent).startsWith('Mathematics'))
            .map((n: any) => n.textContent);
        assert.deepEqual(badges, ['Mathematics · Pre-Kindergarten', 'Mathematics · Kindergarten']);
        assert.match(s.text(), /Count to five\./);
        assert.match(s.text(), /Count to twenty\./);
    } finally { s.unmount(); }
});
