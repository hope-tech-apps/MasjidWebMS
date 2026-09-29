/**
 * The Grades tab's helpers (core/helpers/gradebook.ts): what the form sends,
 * what a weight means for one piece of work, how weights are typed, and how a
 * child's average is worded. The server decides every figure; these only shape
 * a request and word a payload.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    averageLines,
    blankWorkForm,
    effectiveWeight,
    fencedNote,
    firstFieldError,
    isCombinedGuideColumn,
    mayChangeWeights,
    percentText,
    subjectLine,
    untypedNote,
    weightNote,
    weightsFormFrom,
    weightsRequest,
    workFormFrom,
    workFormReady,
    workRequest,
} from '../core/helpers/gradebook.ts';

const types = [
    { key: 'test', label: 'Test' }, { key: 'quiz', label: 'Quiz' }, { key: 'homework', label: 'Homework' },
    { key: 'classwork', label: 'Classwork' }, { key: 'other', label: 'Other' },
];
const ctx = { subjects: [{ name: 'Mathematics' }], weightingEnabled: false, standardsEnabled: true };

test('a new form starts on the scale, the day and the teachers own subject, with nothing chosen', () => {
    const f = blankWorkForm({ scale: 'levels', today: '2026-10-05', subject: 'Arabic Language' });
    assert.equal(f.scale, 'levels');
    assert.equal(f.subject, 'Arabic Language');
    assert.equal(f.type, '');
    assert.equal(f.weight, '');
    assert.equal(f.standard, null);
    assert.equal(blankWorkForm({ scale: 'points', today: 'x' }).subject, '');
});

test('the form is ready with a title, and with a subject wherever the school lists any', () => {
    const f = blankWorkForm({ scale: 'points', today: '2026-10-05' });
    assert.equal(workFormReady(f, ctx), false, 'no title');
    f.title = 'Fractions quiz';
    assert.equal(workFormReady(f, ctx), false, 'the school has a list, so a subject is required');
    f.subject = 'Mathematics';
    assert.equal(workFormReady(f, ctx), true);
    assert.equal(workFormReady({ ...f, subject: '' }, { ...ctx, subjects: [] }), true, 'no list to check against: a subject is optional');
    assert.equal(workFormReady({ ...f, title: '   ' }, ctx), false);
});

test('an empty subject, type and standard are sent as null so an edit can clear them', () => {
    const f = blankWorkForm({ scale: 'points', today: '2026-10-05' });
    f.title = ' Quiz ';
    const body = workRequest(f, { ...ctx, subjects: [] });
    assert.equal(body.title, 'Quiz');
    assert.equal(body.subject, null);
    assert.equal(body.type, null);
    assert.equal(body.standard_code, null);
    assert.equal(body.curriculum_focus, null);
    assert.equal(body.curriculum_week_no, null);
    assert.equal(body.points_possible, 10);
});

test('only points work sends a maximum, since the server forces it on the other scales', () => {
    const f = blankWorkForm({ scale: 'levels', today: '2026-10-05' });
    f.title = 'Rubric';
    assert.equal('points_possible' in workRequest(f, ctx), false);
    assert.equal(workRequest({ ...f, scale: 'simple' }, ctx).points_possible, undefined);
    assert.equal(workRequest({ ...f, scale: 'points', points_possible: '25' }, ctx).points_possible, 25);
});

test('a weight is sent only for a weighted class, and empty means inherit', () => {
    const f = { ...blankWorkForm({ scale: 'points', today: 'x' }), title: 'Project', weight: '30' };
    assert.equal('weight' in workRequest(f, ctx), false, 'the server would refuse it, so it is not sent');
    assert.equal(workRequest(f, { ...ctx, weightingEnabled: true }).weight, 30);
    assert.equal(workRequest({ ...f, weight: '' }, { ...ctx, weightingEnabled: true }).weight, null);
    assert.equal(workRequest({ ...f, weight: 0 }, { ...ctx, weightingEnabled: true }).weight, 0, 'zero is a weight, not "inherit"');
});

test('the standard travels as the three snapshot fields and only where the school teaches from a guide', () => {
    const f = {
        ...blankWorkForm({ scale: 'points', today: 'x' }), title: 'Fractions',
        standard: { standard_code: 'NC.3.NF.1', curriculum_focus: 'Understand fractions', curriculum_week_no: 4 },
    };
    const body = workRequest(f, ctx);
    assert.equal(body.standard_code, 'NC.3.NF.1');
    assert.equal(body.curriculum_focus, 'Understand fractions');
    assert.equal(body.curriculum_week_no, 4);

    const off = workRequest(f, { ...ctx, standardsEnabled: false });
    assert.equal('standard_code' in off, false, 'BISS: not shown and not sent');
});

test('editing starts from the work as it is, blanks where it has nothing', () => {
    const f = workFormFrom({
        title: 'Old', scale: 'points', points_possible: 20, assigned_on: '2026-09-01',
        subject: null, type: null, weight: null, standard_code: null, curriculum_focus: null, curriculum_week_no: null,
    });
    assert.equal(f.subject, '');
    assert.equal(f.type, '');
    assert.equal(f.weight, '');
    assert.equal(f.standard, null);

    const g = workFormFrom({ title: 'W', standard_code: null, curriculum_focus: 'Wudu', curriculum_week_no: 2, weight: 0 });
    assert.deepEqual(g.standard, { standard_code: null, curriculum_focus: 'Wudu', curriculum_week_no: 2 }, 'an uncoded weekly focus is a standard');
    assert.equal(g.weight, 0);
});

test('a piece of work counts by its own weight, else its types, else nothing', () => {
    const weights = { test: 40, quiz: 20 };
    assert.equal(effectiveWeight({ type: 'test' }, weights, true), 40);
    assert.equal(effectiveWeight({ type: 'test', weight: 30 }, weights, true), 30);
    assert.equal(effectiveWeight({ type: 'test', weight: 0 }, weights, true), 0);
    assert.equal(effectiveWeight({ type: null }, weights, true), null);
    assert.equal(effectiveWeight({ type: 'homework' }, weights, true), null);
    assert.equal(effectiveWeight({ type: 'test', weight: 30 }, weights, false), null, 'an unweighted class weighs nothing');
    assert.equal(effectiveWeight({ type: 'constructor' }, weights, true), null, 'a type is never looked up on the prototype');

    assert.equal(weightNote({ type: 'test' }, weights, true), 'counts 40');
    assert.equal(weightNote({ type: 'test', weight: 30 }, weights, true), 'counts 30 (this work)');
    assert.equal(weightNote({ type: null }, weights, true), '');
});

test('typed weights must be every type, whole numbers, in range, and not all zero', () => {
    const good = { test: '40', quiz: '20', homework: '10', classwork: '10', other: '10' };
    assert.deepEqual(weightsRequest(good, types), { ok: true, weights: { test: 40, quiz: 20, homework: 10, classwork: 10, other: 10 } });

    assert.deepEqual(weightsRequest({ ...good, quiz: '' }, types), { ok: false, message: 'Set a weight for Quiz, or clear the weights.' });
    assert.equal(weightsRequest({ ...good, test: '101' }, types).ok, false);
    assert.equal(weightsRequest({ ...good, test: '-1' }, types).ok, false);
    assert.equal(weightsRequest({ ...good, test: '2.5' }, types).ok, false);
    assert.equal(weightsRequest({ ...good, test: 'lots' }, types).ok, false);
    assert.equal(weightsRequest({ ...good, test: '100' }, types).ok, true, 'the edge is fine');
    assert.deepEqual(
        weightsRequest({ test: '0', quiz: '0', homework: '0', classwork: '0', other: '0' }, types),
        { ok: false, message: 'At least one type of work has to count for something.' }
    );
});

test('the weights form starts from the classs weights and is blank for a type with none', () => {
    assert.deepEqual(weightsFormFrom({ test: 40 }, types), { test: '40', quiz: '', homework: '', classwork: '', other: '' });
    assert.deepEqual(weightsFormFrom({}, types), { test: '', quiz: '', homework: '', classwork: '', other: '' });
});

test('percentages lose a pointless decimal and a missing figure is a dash', () => {
    assert.equal(percentText(81.4), '81.4%');
    assert.equal(percentText(80), '80%');
    assert.equal(percentText(80.04), '80%');
    assert.equal(percentText(0), '0%');
    assert.equal(percentText(null), '—');
    assert.equal(percentText(undefined), '—');
});

test('the untyped note names how many pieces were left out', () => {
    assert.equal(untypedNote(0), '');
    assert.equal(untypedNote(1), '1 piece of work has no type');
    assert.equal(untypedNote(3), '3 pieces of work have no type');
});

test('the weighted average leads where there is one, with the plain figure beside it', () => {
    const lines = averageLines({
        points_counted: 3, points_earned: 100, points_possible: 130,
        weighting: { enabled: true, percent: 81.4, points_pieces: 3, untyped_excluded: 2, level_mean: null },
        levels: { counted: 0, mean: null },
    });
    assert.deepEqual(lines.map((l) => l.label), ['Weighted average', 'Total points']);
    assert.equal(lines[0].value, '81.4%');
    assert.match(lines[0].note, /2 pieces of work have no type, left out/);
    assert.equal(lines[1].value, '100 of 130');
    assert.equal(lines[1].note, '76.9%');
});

test('an unweighted class shows only the plain figures, and a levels mean is never a percentage', () => {
    const lines = averageLines({
        points_counted: 0, points_earned: 0, points_possible: 0,
        weighting: { enabled: false, percent: null, level_mean: null },
        levels: { counted: 2, mean: 3.5, mean_label: 'Exceeds' },
    });
    assert.deepEqual(lines, [{ label: 'Average level', value: '3.5', note: 'Exceeds' }]);
    assert.equal(lines.some((l) => l.value.includes('%')), false);
});

test('a class with weights and a weighted level says so', () => {
    const lines = averageLines({
        points_counted: 0, points_possible: 0,
        weighting: { enabled: true, percent: null, level_mean: 3.3, level_mean_label: 'Meets', points_pieces: 0, untyped_excluded: 0 },
        levels: { counted: 2, mean: 3, mean_label: 'Meets' },
    });
    assert.deepEqual(lines, [{ label: 'Weighted level', value: '3.3', note: 'Meets' }]);
});

test('a teacher limited to some subjects reads figures labelled as theirs, and the note says a parent sees more', () => {
    const summary = {
        points_counted: 1, points_earned: 9, points_possible: 10,
        weighting: { enabled: true, percent: 90, points_pieces: 1, untyped_excluded: 0, level_mean: 3.3, level_mean_label: 'Meets' },
        levels: { counted: 1, mean: 3, mean_label: 'Meets' },
    };
    // Unfenced, and the second argument left out, are the same thing.
    assert.deepEqual(averageLines(summary).map((l) => l.label), ['Weighted average', 'Total points', 'Weighted level']);
    assert.deepEqual(averageLines(summary, false).map((l) => l.label), ['Weighted average', 'Total points', 'Weighted level']);
    // Fenced: EVERY headline label says whose subjects it covers, so no figure reads as the whole child.
    assert.deepEqual(averageLines(summary, true).map((l) => l.label), [
        'Weighted average (your subjects)', 'Total points (your subjects)', 'Weighted level (your subjects)',
    ]);
    // The note appears only when fenced.
    assert.equal(fencedNote(false), '');
    assert.equal(fencedNote(undefined), '');
    assert.match(fencedNote(true), /only the subjects you teach/);
    assert.match(fencedNote(true), /A parent sees every subject/);
});

test('the Students view passes the fence to the figures and prints the note', () => {
    const source = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');
    assert.match(source, /averageLines\(studentGrades\.value\?\.summary, !!studentGrades\.value\?\.fenced\)/);
    assert.match(source, /v-if="studentFencedNote"/);
});

test('the weights panel says a type is one share of the average, not a per-piece multiplier', () => {
    const source = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');
    assert.match(source, /however many pieces of it there are/);
    assert.doesNotMatch(source, /counts as much as\s+four Homework/);
});

test('a child with no marks has no figures', () => {
    assert.deepEqual(averageLines({ points_counted: 0, points_possible: 0, weighting: { enabled: false }, levels: { counted: 0 } }), []);
    assert.deepEqual(averageLines(undefined), []);
});

test('a subject line joins what it has and nothing it does not', () => {
    assert.equal(subjectLine({ points_counted: 2, points_earned: 14, points_possible: 20, percent: 70, weighted_percent: 72.5, levels_counted: 0 }),
        '14 of 20 (70%) · weighted 72.5%');
    assert.equal(subjectLine({ points_counted: 0, levels_counted: 2, level_mean: 3.5, level_mean_label: 'Exceeds' }), 'level 3.5 Exceeds');
    assert.equal(subjectLine({ points_counted: 0, levels_counted: 0 }), '');
});

test('the first field the server refused is the message the teacher reads', () => {
    assert.equal(firstFieldError({ response: { data: { data: { subject: ['Choose the subject this work is for.'] } } } }, 'x'),
        'Choose the subject this work is for.');
    assert.equal(firstFieldError({ response: { data: { message: 'You do not teach Arabic Language in this class.' } } }, 'x'),
        'You do not teach Arabic Language in this class.');
    assert.equal(firstFieldError({}, 'That work could not be added.'), 'That work could not be added.');
});

test('the tab wires the helpers and never divides a levels mark itself', () => {
    const source = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');
    assert.match(source, /from '@\/core\/helpers\/gradebook'/);
});

test('the combined weekly column of the guide is recognised by any spelling and nothing else is', () => {
    for (const s of ["Qur\u2019an & Islamic Studies", "Qur'an & Islamic Studies", 'Quran and Islamic Studies', '  QURAN  &  ISLAMIC STUDIES ']) {
        assert.equal(isCombinedGuideColumn(s), true, s);
    }
    for (const s of ["Qur'an", 'Islamic Studies', 'Arabic Language', 'Mathematics', '', null, undefined]) {
        assert.equal(isCombinedGuideColumn(s as any), false, String(s));
    }
});

test('the lesson plan names the class when it asks for subjects, and labels the combined column', () => {
    const source = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');
    assert.match(source, /q\.set\('group_id', groupId\.value\)/);
    assert.match(source, /isCombinedGuideColumn\(s\) \? ' \(school pacing-guide column\)'/);
});

// ---------------------------------------------------------------- review F5: who changes the class's weights

test('only a teacher of every subject may change the weights: a limited list, of any length, may not', () => {
    // null and an empty list are "everything" (a full-time teacher, or every assignment before subjects existed).
    for (const all of [null, undefined, []]) assert.equal(mayChangeWeights(all), true, JSON.stringify(all));
    for (const limited of [['quran'], ['arabic'], ['quran', 'arabic'], ['quran', 'arabic', 'islamic_studies']]) {
        assert.equal(mayChangeWeights(limited), false, JSON.stringify(limited));
    }
    // Anything that is not a list is not a limit the server sent.
    assert.equal(mayChangeWeights('quran'), true);
});

test('the weights panel is read-only for a limited teacher and offers no save or clear', () => {
    const source = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');

    assert.match(source, /const canChangeWeights = computed\(\(\) => mayChangeWeights\(group\.value\?\.my_subjects\)\)/);
    // The inputs are disabled, and the Save and Clear buttons (and the clear confirmation) exist only when allowed.
    assert.match(source, /:disabled="!canChangeWeights"/);
    assert.match(source, /<div v-if="canChangeWeights" class="col-auto d-flex gap-2">/);
    assert.match(source, /<div v-if="canChangeWeights && confirmClearWeights"/);
    // And the screen says why.
    assert.match(source, /data-test="weights-read-only"/);
    assert.match(source, /only a\s+teacher of all the subjects in this class, or the office, can change them/);
    // The button no longer invites a limited teacher to "Set weights".
    assert.match(source, /weightingEnabled \|\| !canChangeWeights \? 'Weights' : 'Set weights'/);
});
