/**
 * What a pacing-guide week writes into a lesson plan (core/helpers/
 * lessonPlanPrefill.ts): the Islamic integration lines once Qur'an and Islamic
 * Studies are separate subjects, and the school's Learning Outcome under the
 * rule that a teacher's own words are never touched.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { islamicIntegration, outcomeFill, weekOutsideGuide } from '../core/helpers/lessonPlanPrefill.ts';

// ---------- islamicIntegration

test('one combined Qur’an & Islamic Studies sibling gives its focus alone, as it always did', () => {
    const siblings = [
        { subject: 'Mathematics', focus: 'Count to 5' },
        { subject: 'Qur’an & Islamic Studies', focus: 'Wudu and its conditions' },
        { subject: 'Science', focus: 'Senses' },
    ];
    const { islamic, others } = islamicIntegration(siblings);
    assert.equal(islamic, 'Wudu and its conditions');
    assert.equal(others, 'Mathematics: Count to 5\nScience: Senses');

    // The pre-change expression, byte for byte.
    const old = siblings.find((s) => /Qur|Islamic/i.test(s.subject));
    assert.equal(islamic, old?.focus);
    assert.equal(others, siblings.filter((s) => s !== old).map((s) => `${s.subject}: ${s.focus}`).join('\n'));
});

test('Qur’an and Islamic Studies as two subjects give one line each, and Arabic stays with the others', () => {
    const { islamic, others } = islamicIntegration([
        { subject: 'Arabic Language', focus: 'Greetings' },
        { subject: 'Islamic Studies', focus: 'Pillars of Islam' },
        { subject: "Qur'an", focus: 'Memorization' },
        { subject: 'Science', focus: 'Senses' },
    ]);
    assert.equal(islamic, 'Islamic Studies: Pillars of Islam\nQur\'an: Memorization');
    assert.equal(others, 'Arabic Language: Greetings\nScience: Senses');
});

test('exactly one separated Islamic sibling gives its focus alone', () => {
    const { islamic, others } = islamicIntegration([
        { subject: "Qur'an", focus: 'Memorization' },
        { subject: 'Arabic Language', focus: 'Greetings' },
    ]);
    assert.equal(islamic, 'Memorization');
    assert.equal(others, 'Arabic Language: Greetings');
});

test('no Islamic sibling leaves the box empty and every subject in the others', () => {
    const { islamic, others } = islamicIntegration([{ subject: 'Science', focus: 'Senses' }]);
    assert.equal(islamic, '');
    assert.equal(others, 'Science: Senses');
    assert.deepEqual(islamicIntegration([]), { islamic: '', others: '' });
});

test('a separated week’s Objective follows its Focus Skill, so the surah reaches the box', () => {
    const { islamic, others } = islamicIntegration([
        { subject: "Qur'an", focus: 'Memorization', objective: 'Memorize Surah Al-Ikhlāṣ' },
        { subject: 'Arabic Language', focus: 'Greetings', objective: 'Say hello' },
        { subject: 'Science', focus: 'Senses', objective: null },
    ]);
    assert.equal(islamic, 'Memorization — Memorize Surah Al-Ikhlāṣ');
    assert.equal(others, 'Arabic Language: Greetings — Say hello\nScience: Senses');
});

// ---------- outcomeFill

test('an empty outcomes list is filled with the school’s Learning Outcome', () => {
    assert.deepEqual(outcomeFill([], 'Recite independently', undefined), ['Recite independently']);
    assert.deepEqual(outcomeFill(['', '  '], 'Recite independently', undefined), ['Recite independently']);
    assert.deepEqual(outcomeFill(undefined, 'Recite independently', undefined), ['Recite independently']);
});

test('what the teacher typed is left alone', () => {
    assert.equal(outcomeFill(['Say the surah by heart'], 'Recite independently', undefined), null);
    assert.equal(outcomeFill(['Say the surah by heart'], 'Recite independently', 'Attend & respond'), null);
    assert.equal(outcomeFill(['Attend & respond', 'and my own'], 'Recite independently', 'Attend & respond'), null);
});

test('an earlier guide fill is replaced by the next week’s', () => {
    assert.deepEqual(outcomeFill(['Attend & respond'], 'Recite independently', 'Attend & respond'), ['Recite independently']);
    assert.deepEqual(outcomeFill([' Attend & respond '], 'Recite independently', 'Attend & respond'), ['Recite independently']);
});

test('a week with no outcome, or the outcome already there, changes nothing', () => {
    assert.equal(outcomeFill([], null, undefined), null);
    assert.equal(outcomeFill([], undefined, undefined), null);
    assert.equal(outcomeFill([], '', undefined), null);
    assert.equal(outcomeFill(['Recite independently'], 'Recite independently', 'Recite independently'), null);
});

test('a week with no outcome empties the outcome the guide itself wrote, and only that', () => {
    assert.deepEqual(outcomeFill(['Recite independently'], null, 'Recite independently'), []);
    assert.deepEqual(outcomeFill([' Recite independently '], '', 'Recite independently'), []);
    assert.deepEqual(outcomeFill(['Recite independently'], undefined, 'Recite independently'), []);
    // the teacher's own words, or a list the guide did not write alone, stay
    assert.equal(outcomeFill(['Say the surah by heart'], null, 'Recite independently'), null);
    assert.equal(outcomeFill(['Recite independently', 'and my own'], null, 'Recite independently'), null);
    assert.equal(outcomeFill(['Recite independently'], null, undefined), null);
});

// ---------- weekOutsideGuide

test('a week the guide list does not carry keeps the number input', () => {
    const weeks = [{ week_no: 1 }, { week_no: 2 }, { week_no: 8 }];
    assert.equal(weekOutsideGuide(12, weeks), true);
    assert.equal(weekOutsideGuide('12', weeks), true);
    assert.equal(weekOutsideGuide(8, weeks), false);
    assert.equal(weekOutsideGuide(null, weeks), false);
    assert.equal(weekOutsideGuide(12, []), false, 'no list means the number input already shows');
});

// ---------- the components use them (a component cannot be mounted here, so read the source)

const teacherClass = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');
const picker = readFileSync(new URL('../components/teacher/StandardPicker.vue', import.meta.url), 'utf8');

test('a pick writes the school’s Objective, falling back to the Focus Skill, as a week prefill does', () => {
    assert.match(teacherClass, /autoFill\('objective', m\.objective \?\? m\.focus\)/);
    assert.match(teacherClass, /autoFillOutcome\(m\.learning_outcome\)/);
    assert.match(teacherClass, /autoFillOutcome\(cell\.learning_outcome\)/);
});

test('the week prefill writes the integration lines through the helper', () => {
    assert.match(teacherClass, /islamicIntegration\(siblings\)/);
    assert.doesNotMatch(teacherClass, /siblings\.find\(\(s\) => \/Qur\|Islamic\/i/);
});

test('the outcome fill respects a hidden section, as every other field does', () => {
    assert.match(teacherClass, /const autoFillOutcome[\s\S]{0,200}planHidden\.value\.has\('learning_outcomes'\)/);
});

test('both standards lists key a suggestion by its objective and show it', () => {
    for (const source of [teacherClass, picker]) {
        assert.match(source, /:key="`\$\{m\.grade_label\}\|\$\{m\.subject\}\|\$\{m\.standard_code\}\|\$\{m\.focus\}\|\$\{m\.objective \?\? ''\}`"/);
        assert.match(source, /<div v-if="m\.objective" class="text-muted small" dir="auto">\{\{ m\.objective \}\}<\/div>/);
    }
});

test('the week select shows the objective when the row has one', () => {
    assert.match(teacherClass, /\{\{ w\.week_no \}\} · \{\{ w\.focus \}\}\{\{ w\.objective \? ` · \$\{w\.objective\}` : '' \}\}/);
});

test('the week control offers a way to a week past the list', () => {
    assert.match(teacherClass, /<option :value="WEEK_OTHER">Another week…<\/option>/);
    assert.match(teacherClass, /weekOutsideGuide\(planForm\.value\.curriculum_week_no, curriculum\.value\.weeks\)/);
});
