/**
 * The office's Subjects screen (core/helpers/schoolSubjects.ts): the same key the
 * server's unique index holds, the grade summary, and the request body.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    GRADE_LEVELS, cleanSubjectName, clashWith, firstError, gradesSummary, subjectFormFrom, subjectKeyOf, subjectPayload, workNote,
} from '../core/helpers/schoolSubjects.ts';

test('every apostrophe a person can type folds to the same key as the server folds it to', () => {
    // The spellings tests/Unit/SubjectKeyTest.php pins on the PHP side.
    for (const s of ["Qur'an", 'Qur’an', 'Qur‘an', 'Qurʼan', 'Qurʻan', "QUR'AN", '  Quran  ']) {
        assert.equal(subjectKeyOf(s), 'quran', JSON.stringify(s));
    }
    assert.equal(subjectKeyOf("Islamic \t  Studies"), 'islamic studies');
    assert.equal(subjectKeyOf(null), '');
    assert.equal(subjectKeyOf('   '), '');
    assert.equal(cleanSubjectName("  Islamic   Studies "), 'Islamic Studies');
});

test('the grade levels match the server list in order', () => {
    const php = readFileSync(new URL('../../../app/Support/GradeLevel.php', import.meta.url), 'utf8');
    const listed = [...php.slice(php.indexOf('public const LEVELS'), php.indexOf('];', php.indexOf('public const LEVELS'))).matchAll(/'([^']+)'/g)].map((m) => m[1]);
    assert.deepEqual(GRADE_LEVELS, listed);
});

test('a name that is already on the list under another spelling is a clash, except the row being edited', () => {
    const rows = [
        { id: 1, name: "Qur'an", grade_labels: null, position: 0 },
        { id: 2, name: 'Science', grade_labels: null, position: 1 },
    ];
    assert.equal(clashWith('Qur’an', rows)?.id, 1);
    assert.equal(clashWith('science ', rows)?.id, 2);
    assert.equal(clashWith('Science', rows, 2), undefined, 'saving a subject under its own name is not a clash');
    assert.equal(clashWith('Art', rows), undefined);
    assert.equal(clashWith('   ', rows), undefined, 'a blank name is refused by "required", not by "already there"');
});

test('grades read as every grade, or as levels in teaching order with runs joined', () => {
    assert.equal(gradesSummary(null), 'Every grade');
    assert.equal(gradesSummary([]), 'Every grade');
    assert.equal(gradesSummary(['3rd']), '3rd');
    assert.equal(gradesSummary(['4th', '3rd']), '3rd, 4th', 'two in a row are listed, not ranged');
    assert.equal(gradesSummary(['5th', '3rd', '4th']), '3rd–5th');
    assert.equal(gradesSummary(['KG', '1st', '2nd', '5th']), 'KG–2nd, 5th');
    assert.equal(gradesSummary(['9th', '10th', '11th']), '9th–11th');
    assert.equal(gradesSummary(['Pre-K', 'Level A']), 'Pre-K, Level A', 'a level the screen does not offer is kept, not dropped');
});

test('the request carries an empty list for every grade, levels in order, and a sane position', () => {
    assert.deepEqual(subjectPayload({ name: '  Art ', grades: [], position: '' }), { name: 'Art', grade_labels: [], position: 0 });
    assert.deepEqual(subjectPayload({ name: 'Tajweed', grades: ['4th', '3rd', 'Bogus'], position: '2' }),
        { name: 'Tajweed', grade_labels: ['3rd', '4th'], position: 2 }, 'only offered levels are sent');
    assert.equal(subjectPayload({ name: 'x', grades: [], position: -5 }).position, 0);
    assert.equal(subjectPayload({ name: 'x', grades: [], position: 3.9 }).position, 3);
});

test('editing starts from the row, and a new form is blank', () => {
    assert.deepEqual(subjectFormFrom(null), { name: '', grades: [], position: 0 });
    const f = subjectFormFrom({ id: 1, name: 'Art', grade_labels: ['3rd'], position: 4 });
    assert.deepEqual(f, { name: 'Art', grades: ['3rd'], position: 4 });
    f.grades.push('4th');
    assert.deepEqual(subjectFormFrom({ id: 1, name: 'Art', grade_labels: ['3rd'], position: 4 }).grades, ['3rd'], 'the form is a copy');
});

test('the office is told, before renaming, that work keeps the name it was set under', () => {
    assert.equal(workNote(0), '');
    assert.equal(workNote(undefined), '');
    assert.equal(workNote(1), '1 piece of work is filed under this name and will keep it.');
    assert.equal(workNote(4), '4 pieces of work are filed under this name and will keep it.');
});

test('the first field the server refused is the message shown', () => {
    assert.equal(firstError({ response: { data: { data: { name_key: ['That subject is already on the list.'] } } } }, 'x'), 'That subject is already on the list.');
    assert.equal(firstError({}, 'It could not be saved.'), 'It could not be saved.');
});
