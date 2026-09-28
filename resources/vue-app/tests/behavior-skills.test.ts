/**
 * The behaviour-skill picker (core/helpers/behaviorSkills.ts): positives on top,
 * the picker opening on a positive skill, and a teacher-added skill landing in
 * its place instead of at the bottom.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { defaultSkillId, inPickerOrder, polarityRank, withSkillInserted } from '../core/helpers/behaviorSkills.ts';

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
    const before = skills.map((s) => s.id);
    inPickerOrder(skills);
    assert.deepEqual(skills.map((s) => s.id), before);
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
