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
    familySeesWeighted,
    fencedNote,
    firstFieldError,
    isCombinedGuideColumn,
    isUntyped,
    mayChangeWeights,
    NOT_AVERAGED,
    NOT_AVERAGED_WHY,
    percentText,
    pointsPercentText,
    subjectLine,
    untypedInWork,
    untypedListNote,
    untypedNote,
    weightNote,
    weightsClearCall,
    weightsFormFrom,
    weightsRequest,
    weightsSaveCall,
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
    assert.equal(weightNote({ type: 'test', weight: 30 }, weights, true), 'counts 30 on its own');
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

// ---------------------------------------------------------------- review F6: simple-scale work is never averaged

const weightsOn = { test: 40, quiz: 20, homework: 10, classwork: 10, other: 10 };

test('a simple-scale piece in a weighted class says it is not averaged, not "counts N"', () => {
    for (const work of [{ scale: 'simple', type: 'test' }, { scale: 'simple', type: null }, { scale: 'simple', type: 'test', weight: 30 }]) {
        assert.equal(weightNote(work, weightsOn, true), NOT_AVERAGED, JSON.stringify(work));
        assert.equal(effectiveWeight(work, weightsOn, true), null, JSON.stringify(work));
    }
    assert.equal(NOT_AVERAGED, 'not averaged');

    // Points and levels work count as they did.
    assert.equal(weightNote({ scale: 'points', type: 'test' }, weightsOn, true), 'counts 40');
    assert.equal(weightNote({ scale: 'levels', type: 'quiz' }, weightsOn, true), 'counts 20');
    assert.equal(weightNote({ scale: 'points', type: 'test', weight: 30 }, weightsOn, true), 'counts 30 on its own');
    // No scale on the payload (an older client's work) reads as it always did.
    assert.equal(weightNote({ type: 'homework' }, weightsOn, true), 'counts 10');
    // An unweighted class has no weight to note for anyone.
    assert.equal(weightNote({ scale: 'simple', type: 'test' }, {}, false), '');
    assert.equal(weightNote({ scale: 'points', type: 'test' }, {}, false), '');
});

test('a weighted class with only simple marks says why there is no weighted figure', () => {
    const summary = {
        points_counted: 0, points_earned: 0, points_possible: 0, levels: { counted: 0, mean: null },
        simple: { recorded: 3, counted: 3, missing: 0 },
        weighting: { enabled: true, percent: null, level_mean: null, points_pieces: 0, untyped_excluded: 0 },
    };

    assert.deepEqual(averageLines(summary), [{ label: 'Weighted average', value: '—', note: NOT_AVERAGED_WHY }]);
    assert.match(NOT_AVERAGED_WHY, /never averaged/);
    assert.match(NOT_AVERAGED_WHY, /no weighted figure/);

    // It follows the figure's own label when fenced, like every other headline line.
    assert.equal(averageLines(summary, true)[0].label, 'Weighted average (your subjects)');

    // Said only where it is the reason: a weighted figure that exists, no simple marks, or an unweighted class say nothing of it.
    const notes = (s: any) => averageLines(s).map((l) => l.note);
    assert.equal(notes({ ...summary, weighting: { ...summary.weighting, percent: 80, points_pieces: 2 }, points_counted: 2, points_earned: 8, points_possible: 10 }).includes(NOT_AVERAGED_WHY), false);
    assert.deepEqual(averageLines({ ...summary, simple: { recorded: 0 } }), []);
    assert.deepEqual(averageLines({ ...summary, weighting: { ...summary.weighting, enabled: false } }), []);
});

test('the work list and the blank weight box word simple-scale work the same way', () => {
    const source = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');

    // The untyped warning does not count work a type could not help (the one predicate is `isUntyped`, below).
    assert.match(source, /const untypedInList = computed\(\(\) => untypedInWork\(assignments\.value\)\);/);
    // The blank weight box on a simple-scale form says so instead of "Type sets it".
    assert.match(source, /if \(assignmentForm\.value\.scale === SIMPLE_SCALE\) return NOT_AVERAGED/);
});

// ---------------------------------------------------------------- review F7: one points percentage, one rounding

test('one child\'s plain points read the same string in the office block and in the figures card', () => {
    // 68 of 80 is the review's own example: "85%" in one place and "84.7%" in the other, for a child at 84.7 too.
    for (const [earned, possible] of [[68, 80], [61, 72], [1, 3], [2, 3], [0, 10], [10, 10], [7, 8], [0.5, 3], ['68', '80'], [33, 39]] as const) {
        const card = averageLines({ points_counted: 1, points_earned: earned, points_possible: possible, weighting: { enabled: false } })[0];
        const block = pointsPercentText(earned, possible);

        assert.equal(card.label, 'Points');
        assert.equal(card.note, block, `${earned}/${possible}: the card and the block say the same`);
        assert.equal(block, percentText((100 * Number(earned)) / Number(possible)), 'and it is the one rounding');
    }

    // Spelled out, so a change of rounding is a decision and not an accident.
    assert.equal(pointsPercentText(68, 80), '85%');
    assert.equal(pointsPercentText(61, 72), '84.7%');
    assert.equal(pointsPercentText(2, 3), '66.7%');
    assert.equal(pointsPercentText(1, 3), '33.3%');
});

test('no denominator is no percentage, never NaN', () => {
    for (const possible of [0, '0', null, undefined, '', 'x']) {
        assert.equal(pointsPercentText(5, possible as any), null, String(possible));
    }
    assert.equal(pointsPercentText(null, 10), '0%');
    assert.deepEqual(averageLines({ points_counted: 0, points_earned: 0, points_possible: 0, weighting: { enabled: false } }), []);
});

test('the office Grades tab takes its points percentage from the helper and rounds nothing itself', () => {
    const view = readFileSync(new URL('../views/dashboard/groups/GroupGradesTab.vue', import.meta.url), 'utf8');

    assert.match(view, /import \{[^}]*\bpointsPercentText\b[^}]*\} from '@\/core\/helpers\/gradebook'/);
    assert.match(view, /pointsPercentText\(student\.value\?\.summary\?\.points_earned, student\.value\?\.summary\?\.points_possible\)/);
    assert.match(view, /\(\{\{ pointsPct \}\}\)/, 'the helper\'s string already carries its % sign');
    assert.doesNotMatch(view, /Math\.round/, 'no second rounding in the tab');
    assert.doesNotMatch(view, /\}\}%\)/, 'and no % appended to a string that has one');
});

// ---------------------------------------------------------------- review F8: a family sees no weighted figure while older work is left out of it

test('a family sees weighted figures only while nothing is left out of them', () => {
    // The review's case: nine older untyped pieces and one new typed quiz, weights just set. "100% across 1 piece".
    const weighting = { enabled: true, percent: 100, points_pieces: 1, untyped_excluded: 9 };
    assert.equal(familySeesWeighted(weighting), false, '9 pieces of older work are left out of the 100%');
    assert.equal(familySeesWeighted({ ...weighting, untyped_excluded: 1 }), false, 'one is enough');

    // Typed (or nothing untyped to begin with): the figure is honest again and comes back by itself.
    assert.equal(familySeesWeighted({ ...weighting, untyped_excluded: 0 }), true);
    assert.equal(familySeesWeighted({ ...weighting, untyped_excluded: null }), true);
    assert.equal(familySeesWeighted({ enabled: true, percent: 80 }), true, 'an older payload with no count');

    // Not a weighted class: nothing to show, whatever the count.
    for (const w of [{ enabled: false, untyped_excluded: 0 }, { enabled: false }, null, undefined]) {
        assert.equal(familySeesWeighted(w as any), false, JSON.stringify(w));
    }
});

test('staff keep the weighted figure and the note that says what is left out', () => {
    const summary = {
        points_counted: 10, points_earned: 60, points_possible: 90,
        weighting: { enabled: true, percent: 100, points_pieces: 1, untyped_excluded: 9, level_mean: null },
    };
    const lines = averageLines(summary);

    // The teacher's and the office's screens still lead with the weighted figure, with its note ...
    assert.equal(lines[0].label, 'Weighted average');
    assert.equal(lines[0].value, '100%');
    assert.match(lines[0].note, /across 1 piece of work; 9 pieces of work have no type, left out/);
    // ... beside the plain total.
    assert.equal(lines[1].value, '60 of 90');
});

test('the family screen asks familySeesWeighted before every weighted figure and prints no untyped note', () => {
    const view = readFileSync(new URL('../views/family/FamilyClass.vue', import.meta.url), 'utf8');

    // Headline, weighted level and per-subject figures all go through the one answer.
    assert.match(view, /<template v-if="familySeesWeighted\(marksFor\(child\)\.summary\.weighting\) && marksFor\(child\)\.summary\.weighting\.percent !== null">/);
    assert.match(view, /<p v-if="familySeesWeighted\(marksFor\(child\)\.summary\.weighting\) && marksFor\(child\)\.summary\.weighting\.level_mean !== null/);
    assert.match(view, /subjectFigures\(b, familySeesWeighted\(marksFor\(child\)\.summary\.weighting\)\)/);
    assert.match(view, /if \(showWeighted && b\?\.weighted_percent !== null/);

    // No weighted figure is drawn from the payload any other way.
    const weightedReads = view.match(/summary\.weighting\.(percent|level_mean)\b/g) ?? [];
    assert.ok(weightedReads.length > 0);
    assert.doesNotMatch(view, /weighting\?\.enabled && marksFor/, 'the old gate, which ignored what was left out, is gone');

    // The block that used to explain what was left out is gone with the figure it explained.
    assert.doesNotMatch(view, /tCount\('marks_untyped'/);
});

// ---------------------------------------------------------------- optional fold: the override's own label

test('a weight typed on one piece says it counts on its own, not "(this work)"', () => {
    const label = weightNote({ scale: 'points', type: 'test', weight: 30 }, weightsOn, true);

    assert.equal(label, 'counts 30 on its own');
    assert.doesNotMatch(label, /this work/);
    // The type's own weight is still the plain "counts 40".
    assert.equal(weightNote({ scale: 'points', type: 'test' }, weightsOn, true), 'counts 40');
});

// ---------------------------------------------------------------- the office sets the class's weights

const adminBase = '/api/admin/masjids/7/groups/12';

test('the office saves weights with one PUT to the admin route, and clears them with another', () => {
    const typed = weightsRequest({ test: '40', quiz: '20', homework: '10', classwork: '10', other: '10' }, types);
    assert.equal(typed.ok, true);
    if (!typed.ok) return;

    assert.deepEqual(weightsSaveCall(adminBase, typed.weights), {
        method: 'put',
        url: '/api/admin/masjids/7/groups/12/grade-weights',
        payload: { weights: { test: 40, quiz: 20, homework: 10, classwork: 10, other: 10 } },
    });
    assert.deepEqual(weightsClearCall(adminBase), {
        method: 'put',
        url: '/api/admin/masjids/7/groups/12/grade-weights',
        payload: { clear: true },
    });
    // A clear carries no weights: the server refuses the two together.
    assert.equal('weights' in weightsClearCall(adminBase).payload, false);
});

test('the URL the office panel builds is the route the server mounts, with the same permission as its neighbours', () => {
    const routes = readFileSync(new URL('../../../routes/admin.php', import.meta.url), 'utf8');
    const line = routes.match(/Route::put\('([^']*grade-weights)', \[GroupGradeWeightsController::class, 'update'\]\)\s*->middleware\('([^']*)'\)/);
    assert.ok(line, 'routes/admin.php mounts PUT grade-weights on GroupGradeWeightsController');
    assert.equal(line![2], 'permission:manage contacts');

    // The route is `{masjid_id}/groups/{group_id}/grade-weights` under `api/admin/masjids`.
    const path = `/api/admin/masjids/${line![1].replace('{masjid_id}', '7').replace('{group_id}', '12')}`;
    assert.equal(weightsClearCall(adminBase).url, path);
});

test('the office Grades tab sends its weights through those calls, on its own admin base', () => {
    const view = readFileSync(new URL('../views/dashboard/groups/GroupGradesTab.vue', import.meta.url), 'utf8');

    // The base is the admin group route, the one every other call in the tab uses.
    assert.match(view, /const base = computed\(\(\) => `\/api\/admin\/masjids\/\$\{props\.masjidId\}\/groups\/\$\{props\.groupId\}`\);/);
    assert.match(view, /weightsSaveCall\(base\.value, request\.weights\)/);
    assert.match(view, /weightsClearCall\(base\.value\)/);
    assert.match(view, /ApiService\.put\(call\.url as any, call\.payload\)/);
    // What was typed is checked as the teacher's panel checks it, before any request.
    assert.match(view, /weightsRequest\(weightsForm\.value, workTypes\.value, weightMax\.value\)/);
    assert.match(view, /if \(!request\.ok\) \{ weightsError\.value = request\.message; return; \}/);
    // The types and the ceiling come off the payload, not a copy of the server's list.
    assert.match(view, /workTypes\.value = res\.data\?\.types \?\? workTypes\.value;/);
    assert.match(view, /weightMax\.value = res\.data\?\.weight_max \?\? weightMax\.value;/);
});

test('the office weights panel is not read-only: inputs, Save and Clear are always there, and success and failure are said', () => {
    const view = readFileSync(new URL('../views/dashboard/groups/GroupGradesTab.vue', import.meta.url), 'utf8');
    const panel = view.slice(view.indexOf('data-test="weights-panel"'), view.indexOf('No work has been set for this class yet.'));
    assert.ok(panel.length > 500, 'the panel is in the work list');

    // Editable inputs, one per type off the payload, bound to the form.
    assert.match(panel, /<input :id="`weight-\$\{t\.key\}`" v-model="weightsForm\[t\.key\]" type="number"/);
    assert.doesNotMatch(panel, /disabled="!/, 'nothing here is disabled for who the user is');
    assert.doesNotMatch(panel, /<input[^>]*\bdisabled\b/);
    // Save is always offered; Clear is offered once the class has weights, behind a confirmation.
    assert.match(panel, /<button class="btn btn-sm btn-success" :disabled="savingWeights" @click="saveWeights">/);
    assert.match(panel, /<button v-if="weightingEnabled && !confirmClearWeights" class="btn btn-sm btn-outline-danger"/);
    assert.match(panel, /<button class="btn btn-sm btn-danger" :disabled="savingWeights" @click="clearWeights">Clear them<\/button>/);
    // Success and errors, the way the neighbouring panels put them.
    assert.match(panel, /<p v-if="weightsSaved" class="text-success small mt-2 mb-0">/);
    assert.match(panel, /<p v-if="weightsError" class="text-danger small mt-2 mb-0" role="alert">\{\{ weightsError \}\}<\/p>/);
    assert.match(view, /weightsError\.value = firstFieldError\(e, failed\);/);

    // The teacher's limited-teacher machinery has no place here, and the old "read-only" line is gone.
    for (const teacherOnly of ['canChangeWeights', 'mayChangeWeights', 'weights-read-only', 'my_subjects']) {
        assert.doesNotMatch(view, new RegExp(teacherOnly), teacherOnly);
    }
    assert.doesNotMatch(view, /read-only here/);
    // The button that opens it reads "Set weights" until the class has some.
    assert.match(view, /\{\{ weightingEnabled \? 'Weights' : 'Set weights' \}\}/);
});

test('after a save the list is re-read without the loading swap, so the panel stays and the badges follow', () => {
    const view = readFileSync(new URL('../views/dashboard/groups/GroupGradesTab.vue', import.meta.url), 'utf8');

    assert.match(view, /const load = async \(quiet = false\) => \{\s+if \(!quiet\) loading\.value = true;/);
    assert.match(view, /await load\(true\);/);
    assert.match(view, /onMounted\(\(\) => load\(\)\);/, 'the first load is not quiet, and is not handed the mount hook\'s arguments');
});

/**
 * The office panel's save and clear, as the component has them, run against stubs (the suite has no
 * renderer). What matters is the request that leaves, and what the panel holds after each answer.
 */
async function runOfficePanel(answer: (call: { url: string; payload: unknown }) => unknown, typed: Record<string, string>) {
    const view = readFileSync(new URL('../views/dashboard/groups/GroupGradesTab.vue', import.meta.url), 'utf8');
    const start = view.indexOf('const sendWeights = async');
    const clearLine = view.indexOf('const clearWeights = ');
    assert.ok(start !== -1 && clearLine > start, 'the tab defines sendWeights, saveWeights and clearWeights together');
    // The script is TypeScript and this runner (Node 22.12) has no type stripper for a snippet: the three
    // annotations these functions use are removed by hand, and any other one is a syntax error, loudly.
    const block = view.slice(start, view.indexOf('\n', clearLine))
        .replace(/\b(\w+): (?:WeightsCall|string|any)\b/g, '$1')
        .replace(/ as any\b/g, '');

    const AsyncFunction = Object.getPrototypeOf(async () => {}).constructor;
    const held = {
        weights: { value: {} as Record<string, number> },
        weightingEnabled: { value: false },
        weightsForm: { value: typed },
        savingWeights: { value: false },
        weightsSaved: { value: false },
        weightsError: { value: '' },
        confirmClearWeights: { value: true },
    };
    const sent: { url: string; payload: unknown }[] = [];
    let reloads = 0;
    const build = new AsyncFunction(
        'held', 'base', 'workTypes', 'weightMax', 'ApiService', 'load',
        'weightsRequest', 'weightsFormFrom', 'weightsSaveCall', 'weightsClearCall', 'firstFieldError',
        `const { weights, weightingEnabled, weightsForm, savingWeights, weightsSaved, weightsError, confirmClearWeights } = held;\n${block}\nreturn { saveWeights, clearWeights };`
    );
    const { saveWeights, clearWeights } = await build(
        held, { value: adminBase }, { value: types }, { value: 100 },
        { put: async (url: string, payload: unknown) => { sent.push({ url, payload }); return { data: answer({ url, payload }) }; } },
        async (quiet: boolean) => { assert.equal(quiet, true, 'the re-read is the quiet one'); reloads++; },
        weightsRequest, weightsFormFrom, weightsSaveCall, weightsClearCall, firstFieldError
    );

    return { held, sent, saveWeights, clearWeights, reloads: () => reloads };
}

const typedFive = { test: '40', quiz: '20', homework: '10', classwork: '10', other: '10' };
const savedAnswer = () => ({ status: 'success', data: { weights: { test: 40, quiz: 20, homework: 10, classwork: 10, other: 10 }, weighting_enabled: true, cleared_overrides: 0 } });

test('the office panel saves: one PUT with the five weights, then it says Saved and holds what the server holds', async () => {
    const p = await runOfficePanel(savedAnswer, typedFive);
    await p.saveWeights();

    assert.deepEqual(p.sent, [{ url: '/api/admin/masjids/7/groups/12/grade-weights', payload: { weights: { test: 40, quiz: 20, homework: 10, classwork: 10, other: 10 } } }]);
    assert.equal(p.held.weightsSaved.value, true);
    assert.equal(p.held.weightsError.value, '');
    assert.equal(p.held.weightingEnabled.value, true);
    assert.deepEqual(p.held.weights.value, { test: 40, quiz: 20, homework: 10, classwork: 10, other: 10 });
    assert.equal(p.held.savingWeights.value, false);
    assert.equal(p.reloads(), 1, 'the list is re-read so the badges follow');
});

test('the office panel refuses a bad form before any request, in the words the teacher\'s panel uses', async () => {
    const p = await runOfficePanel(savedAnswer, { ...typedFive, quiz: '' });
    await p.saveWeights();

    assert.deepEqual(p.sent, []);
    assert.equal(p.held.weightsError.value, 'Set a weight for Quiz, or clear the weights.');
    assert.equal(p.held.weightsSaved.value, false);
});

test('the office panel clears: one PUT of clear, then the weights are gone and the confirmation closes', async () => {
    const p = await runOfficePanel(() => ({ status: 'success', data: { weights: {}, weighting_enabled: false, cleared_overrides: 2 } }), typedFive);
    p.held.weights.value = { test: 40 };
    p.held.weightingEnabled.value = true;
    await p.clearWeights();

    assert.deepEqual(p.sent, [{ url: '/api/admin/masjids/7/groups/12/grade-weights', payload: { clear: true } }]);
    assert.equal(p.held.weightingEnabled.value, false);
    assert.deepEqual(p.held.weights.value, {});
    assert.equal(p.held.confirmClearWeights.value, false);
    assert.equal(p.held.weightsSaved.value, true);
    assert.equal(p.reloads(), 1, 'clearing removes every piece\'s own weight, so the list is re-read');
});

test('a refused save or clear shows the server\'s words and never says Saved', async () => {
    const refuse = (status: number, message: string) => () => { throw { response: { status, data: { status: 'failed', message } } }; };

    const forbidden = await runOfficePanel(refuse(403, 'User does not have the right permissions.'), typedFive);
    await forbidden.saveWeights();
    assert.equal(forbidden.held.weightsError.value, 'User does not have the right permissions.');
    assert.equal(forbidden.held.weightsSaved.value, false);
    assert.equal(forbidden.held.savingWeights.value, false, 'the button frees up');
    assert.equal(forbidden.reloads(), 0);

    const invalid = await runOfficePanel(() => { throw { response: { status: 422, data: { status: 'failed', data: { weights: ['At least one type of work has to count for something.'] } } } }; }, typedFive);
    await invalid.saveWeights();
    assert.equal(invalid.held.weightsError.value, 'At least one type of work has to count for something.');

    // No answer at all: the panel's own sentence, per verb.
    const offline = await runOfficePanel(() => { throw new Error('Network Error'); }, typedFive);
    await offline.saveWeights();
    assert.equal(offline.held.weightsError.value, 'The weights could not be saved.');
    await offline.clearWeights();
    assert.equal(offline.held.weightsError.value, 'The weights could not be cleared.');
    assert.equal(offline.held.weightsSaved.value, false);
});

// ---------------------------------------------------------------- review G5: "not averaged" polish

test('work a type could not help is not "untyped": simple-scale work, and work with a weight of its own', () => {
    assert.equal(isUntyped({ scale: 'points', type: null, weight: null }), true);
    assert.equal(isUntyped({ scale: 'levels', type: '', weight: undefined }), true);
    assert.equal(isUntyped({ type: null, weight: null }), true, 'no scale on the payload reads as it always did');
    assert.equal(isUntyped({ scale: 'simple', type: null, weight: null }), false, 'never averaged, whatever it is given');
    assert.equal(isUntyped({ scale: 'points', type: 'test', weight: null }), false);
    assert.equal(isUntyped({ scale: 'points', type: null, weight: 30 }), false);
    assert.equal(isUntyped({ scale: 'points', type: null, weight: 0 }), false, 'a weight of 0 is still a weight of its own');
    assert.equal(isUntyped(null), false, 'no piece of work is not an untyped one');
    assert.equal(isUntyped(undefined), false);

    const list = [
        { scale: 'points', type: null, weight: null }, { scale: 'simple', type: null, weight: null },
        { scale: 'levels', type: 'quiz', weight: null }, { scale: 'points', type: null, weight: 20 }, { scale: 'levels', type: null, weight: null },
    ];
    assert.equal(untypedInWork(list), 2);
    assert.equal(untypedInWork([]), 0);
    assert.equal(untypedInWork(null), 0);
});

test('the note under the work list says how many, and what fixes it', () => {
    assert.equal(untypedListNote(0), '');
    assert.equal(untypedListNote(1), '1 piece of work has no type: it is left out of weighted averages until given a type.');
    assert.equal(untypedListNote(3), '3 pieces of work have no type: they are left out of weighted averages until given a type.');
});

test('the teacher\'s "no type" badge skips simple-scale work, through the same predicate as the note under the list', () => {
    const source = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');

    assert.match(source, /<span v-if="weightingEnabled && isUntyped\(a\)" class="badge bg-warning-subtle text-warning-emphasis fw-normal"/);
    assert.doesNotMatch(source, /!a\.type && a\.weight === null/, 'no second, older spelling of the predicate');
    assert.match(source, /\{\{ untypedListNote\(untypedInList\) \}\}/);
});

/** The `v-if` of the office list's badge wrapper, as an expression to run against a piece of work. */
function officeWrapperShows(a: Record<string, unknown>, weights: Record<string, number>, weightingEnabled: boolean): boolean {
    const view = readFileSync(new URL('../views/dashboard/groups/GroupGradesTab.vue', import.meta.url), 'utf8');
    const expr = view.match(/<div v-if="([^"]*)"\s+class="d-flex flex-wrap gap-1 mt-1">/);
    assert.ok(expr, 'the office list has its badge wrapper');

    return Boolean(new Function('a', 'weights', 'weightingEnabled', 'weightNote', 'isUntyped', `return (${expr![1]});`)(a, weights, weightingEnabled, weightNote, isUntyped));
}

test('the office list shows "not averaged" for a simple piece with no subject, type or standard, and its "no type" badge for the rest', () => {
    const weights = { test: 40, quiz: 20, homework: 10, classwork: 10, other: 10 };
    const bare = { scale: 'simple', type: null, weight: null, subject: null, type_label: null, standard_code: null };

    // The review's case: nothing else on the piece, so the wrapper used to be missing and the note with it.
    assert.equal(weightNote(bare, weights, true), 'not averaged');
    assert.equal(officeWrapperShows(bare, weights, true), true);
    // An unweighted class has no weight to note and simple work has no "no type" to warn about.
    assert.equal(officeWrapperShows(bare, {}, false), false);
    // Points work with no type in a weighted class: the "no type" badge, so the wrapper is there for it too.
    assert.equal(officeWrapperShows({ ...bare, scale: 'points' }, weights, true), true);
    // A typed piece, an override or a subject each keep the wrapper as they always did.
    assert.equal(officeWrapperShows({ ...bare, scale: 'points', type: 'test', type_label: 'Test' }, weights, true), true);
    assert.equal(officeWrapperShows({ ...bare, subject: 'Arabic' }, {}, false), true);
});

test('the office Grades tab says which work a weighted class leaves out, in the teacher\'s words', () => {
    const view = readFileSync(new URL('../views/dashboard/groups/GroupGradesTab.vue', import.meta.url), 'utf8');

    assert.match(view, /const untypedInList = computed\(\(\) => untypedInWork\(assignments\.value\)\);/);
    assert.match(view, /<p v-if="assignments\.length && weightingEnabled && untypedInList > 0"[^>]*data-test="untyped-note">\s*\{\{ untypedListNote\(untypedInList\) \}\}/);
    assert.match(view, /<span v-if="weightingEnabled && isUntyped\(a\)" class="badge bg-warning-subtle text-warning-emphasis fw-normal"/);
});

test('"never averaged" is the reason for no weighted figure only while no work is waiting for a type', () => {
    const summary = (untyped: number | null | undefined) => ({
        points_counted: 0, points_earned: 0, points_possible: 0, levels: { counted: 0, mean: null },
        simple: { recorded: 3, counted: 3, missing: 0 },
        weighting: { enabled: true, percent: null, level_mean: null, points_pieces: 0, untyped_excluded: untyped },
    });

    // Nothing untyped (0, null, or a payload that does not say): the simple marks are the whole reason.
    for (const none of [0, null, undefined]) {
        assert.deepEqual(averageLines(summary(none)), [{ label: 'Weighted average', value: '—', note: NOT_AVERAGED_WHY }], String(none));
    }

    // Work is left out for want of a type: THAT is the reason a teacher can act on, and "never averaged" is not said.
    assert.deepEqual(averageLines(summary(1)), [{
        label: 'Weighted average', value: '—', note: '1 piece of work has no type, left out of the weighted average until given a type',
    }]);
    assert.equal(averageLines(summary(4))[0].note, '4 pieces of work have no type, left out of the weighted average until given a type');
    assert.equal(averageLines(summary(4)).some((l) => l.note === NOT_AVERAGED_WHY), false);
    // The fenced label still follows the line.
    assert.equal(averageLines(summary(4), true)[0].label, 'Weighted average (your subjects)');
});
