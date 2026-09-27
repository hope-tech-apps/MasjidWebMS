/**
 * A day's lesson plans (core/helpers/lessonPlans.ts): one per subject, listed
 * in one order, a taken subject not offered twice, and the day view opening
 * the plan the teacher was on.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    pickPlan,
    planLabel,
    plansOn,
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
