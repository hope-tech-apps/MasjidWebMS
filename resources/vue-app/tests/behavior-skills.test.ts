/**
 * The behaviour-skill picker (core/helpers/behaviorSkills.ts): positives on top,
 * the picker opening on a positive skill, and a teacher-added skill landing in
 * its place instead of at the bottom.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { awardPointsLabel, defaultSkillId, inPickerOrder, pickerFrom, polarityRank, signedAwardPoints, withSkillInserted } from '../core/helpers/behaviorSkills.ts';

const skills = [
    { id: 1, label: 'Argues', polarity: 'negative' },
    { id: 2, label: 'Zealous', polarity: 'positive' },
    { id: 3, label: 'Kind', polarity: 'positive' },
    { id: 4, label: 'Late', polarity: 'negative' },
    { id: 5, label: 'Odd', polarity: 'sideways' },
];

test('positives, then negatives, then unrecognised, by label within each', () => {
    assert.deepEqual(inPickerOrder(skills).map((s) => s.label), ['Kind', 'Zealous', 'Argues', 'Late', 'Odd']);
});

test('ordering never mutates its argument', () => {
    // A FRESH list, in payload order: the shared fixture above may already have been sorted in place by an earlier test.
    const payload = [
        { id: 1, label: 'Argues', polarity: 'negative' },
        { id: 2, label: 'Zealous', polarity: 'positive' },
        { id: 3, label: 'Kind', polarity: 'positive' },
    ];
    const ordered = inPickerOrder(payload);
    assert.deepEqual(payload.map((s) => s.id), [1, 2, 3], 'the reactive array is left as it was');
    assert.notEqual(ordered, payload);
    assert.deepEqual(ordered.map((s) => s.id), [3, 2, 1]);
});

test('labels that differ only in case or accent tie, as the server collation ties them, so payload order holds', () => {
    // utf8mb4_unicode_ci (config/database.php) treats these as equal; a case- or accent-sensitive compare would reorder them.
    const asPaid = [
        { id: 1, label: 'Éclair', polarity: 'positive' },
        { id: 2, label: 'eclair', polarity: 'positive' },
    ];
    assert.deepEqual(inPickerOrder(asPaid).map((s) => s.id), [1, 2]);
    assert.deepEqual(inPickerOrder([...asPaid].reverse()).map((s) => s.id), [2, 1]);
    assert.deepEqual(inPickerOrder([{ id: 1, label: 'kind', polarity: 'positive' }, { id: 2, label: 'Kind', polarity: 'positive' }]).map((s) => s.id), [1, 2]);
    assert.deepEqual(inPickerOrder([{ id: 2, label: 'Kind', polarity: 'positive' }, { id: 1, label: 'kind', polarity: 'positive' }]).map((s) => s.id), [2, 1]);
});

test('polarityRank mirrors the server CASE', () => {
    assert.deepEqual([polarityRank('positive'), polarityRank('negative'), polarityRank('x'), polarityRank(null)], [0, 1, 2, 2]);
});

test('the picker opens on the first positive skill even when a negative is listed first', () => {
    assert.equal(defaultSkillId(skills), 3);
});

test('a school with only negative skills still opens on something', () => {
    assert.equal(defaultSkillId(skills.filter((s) => s.polarity === 'negative')), 1);
    assert.equal(defaultSkillId([]), '');
});

test('a new skill is inserted in picker order, not appended', () => {
    const next = withSkillInserted(inPickerOrder(skills), { id: 9, label: 'Helpful', polarity: 'positive' });
    assert.deepEqual(next.map((s) => s.label), ['Helpful', 'Kind', 'Zealous', 'Argues', 'Late', 'Odd']);
    const neg = withSkillInserted(inPickerOrder(skills), { id: 10, label: 'Bullies', polarity: 'negative' });
    assert.deepEqual(neg.map((s) => s.label), ['Kind', 'Zealous', 'Argues', 'Bullies', 'Late', 'Odd']);
});

test('labels sort without regard to case, so "apple" comes before "Banana" as the server orders them', () => {
    const mixed = [
        { id: 1, label: 'Banana', polarity: 'positive' },
        { id: 2, label: 'apple', polarity: 'positive' },
        { id: 3, label: 'Cherry', polarity: 'positive' },
    ];
    assert.deepEqual(inPickerOrder(mixed).map((s) => s.label), ['apple', 'Banana', 'Cherry']);
});

test('with no positive skill the picker opens on the FIRST NEGATIVE BY LABEL, not the first in the payload', () => {
    const negatives = [
        { id: 1, label: 'Zebra', polarity: 'negative' },
        { id: 2, label: 'Argues', polarity: 'negative' },
    ];
    assert.equal(defaultSkillId(negatives), 2);
});

test('inserting a skill that is already listed replaces it rather than duplicating it', () => {
    const list = inPickerOrder(skills);
    const next = withSkillInserted(list, { id: 3, label: 'Kinder', polarity: 'positive' });
    assert.equal(next.length, list.length);
    assert.deepEqual(next.filter((s) => s.id === 3).map((s) => s.label), ['Kinder']);
    // The id match is by string value, so a payload id typed differently is still the same skill.
    assert.equal(withSkillInserted(list, { id: '3', label: 'Kinder', polarity: 'positive' }).length, list.length);
});

test('a freshly loaded vocabulary is reordered and the picker opens on a positive, keeping a choice already made', () => {
    const before = skills.map((s) => s.id);
    const fresh = pickerFrom(skills, '');
    assert.deepEqual(fresh.skills.map((s) => s.label), ['Kind', 'Zealous', 'Argues', 'Late', 'Odd']);
    assert.equal(fresh.selectedId, 3, 'an empty choice opens on the first positive skill, not skills[0]');
    assert.deepEqual(skills.map((s) => s.id), before, 'the payload array is never sorted in place');

    assert.equal(pickerFrom(skills, 4).selectedId, 4, 'a skill the teacher already chose is kept');
    assert.equal(pickerFrom([], '').selectedId, '');
});

/** The teacher screen must load and grow its vocabulary through these, or the tests above pin nothing. */
const teacher = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');

test('the teacher screen orders what it loads and what a teacher adds through the helpers', () => {
    assert.equal((teacher.match(/const picker = pickerFrom\(s, awardSkillId\.value\);/g) ?? []).length, 2, 'both loaders');
    assert.equal((teacher.match(/skills\.value = picker\.skills;/g) ?? []).length, 2);
    assert.equal((teacher.match(/awardSkillId\.value = picker\.selectedId;/g) ?? []).length, 2);
    assert.match(teacher, /skills\.value = withSkillInserted\(skills\.value, created\);/);
    assert.doesNotMatch(teacher, /skills\.value = \[\.\.\.skills\.value, created\]|skills\.value\.push\(/, 'a new skill is never appended out of order');
});

// ---- the award LOG reads the same sign the totals do (B1)

test('a negative behaviour reads as a deduction in the log, whether it was stored as a magnitude or already signed', () => {
    assert.equal(signedAwardPoints({ polarity: 'negative', points: 1 }), -1, 'stored +1: the total goes DOWN, so the row says -1');
    assert.equal(signedAwardPoints({ polarity: 'negative', points: -2 }), -2, 'an older row stored signed is not flipped to +2');
    assert.equal(awardPointsLabel({ polarity: 'negative', points: 1 }), '-1');
    assert.equal(awardPointsLabel({ polarity: 'negative', points: -2 }), '-2');
});

test('every other polarity reads as stored: a positive gift adds, a positive skill docked with an override stays negative', () => {
    assert.equal(awardPointsLabel({ polarity: 'positive', points: 3 }), '+3');
    assert.equal(awardPointsLabel({ polarity: 'positive', points: -3 }), '-3', 'the server sums -3 here; the log must not say +3');
    assert.equal(awardPointsLabel({ polarity: 'sideways', points: 2 }), '+2', 'an unrecognised polarity degrades to positive');
    assert.equal(awardPointsLabel({ polarity: null, points: 0 }), '0');
    assert.equal(signedAwardPoints({ polarity: 'positive', points: '4' }), 4, 'a numeric string from a payload is a number');
});

test('the teacher, office and family award logs print the signed figure, not the stored one', () => {
    const teacherView = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');
    const familyView = readFileSync(new URL('../views/family/FamilyClass.vue', import.meta.url), 'utf8');
    const officeView = readFileSync(new URL('../views/dashboard/groups/GroupPointsTab.vue', import.meta.url), 'utf8');

    assert.match(teacherView, /\{\{ awardPointsLabel\(a\) \}\}/);
    assert.match(familyView, /\{\{ awardPointsLabel\(a\) \}\}/);
    assert.match(officeView, /\{\{ signedAwardPoints\(award\) \}\}/);
    for (const [name, view] of [['teacher', teacherView], ['family', familyView]] as const) {
        assert.doesNotMatch(view, /a\.points > 0 \? '\+' : ''/, `${name}: the stored value is never printed raw`);
    }
    assert.doesNotMatch(officeView, /\{\{ award\.points \}\}/, 'office: the stored value is never printed raw');
});
