/**
 * Moving a whole class: what the dialog works out for itself (core/helpers/rosterClassMove.ts).
 * The sentences about a move are the server's and are tested there; the dialog is driven in
 * roster-class-move-mounted.test.ts.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    classMoveFields, everyoneWhoCanMove, gradeChange, moveButtonLabel, movedFromClassesNotOffered, refusalOf, someWereLeft,
    theRest, ticksAfterCheck, toSend, whoCameFromTarget, whyNotYet,
} from '../core/helpers/rosterClassMove.ts';

const source = (path: string) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const student = (id: number, over: Record<string, any> = {}): any => ({
    membership_id: id, name: `Student ${id}`, grade_label: '1st', grade_after: '1st', grade_note: null, can_move: true,
    refusal: null, open_group: null, path: 'left_and_started', first_day_in_new_class: '2026-10-04', joined_on: '2026-10-04',
    expected_consent: 'm0f0s0n0e0', came_from_target: false, summary: '', lines: [], ...over,
});
const stuck = (id: number, over: Record<string, any> = {}): any => student(id, {
    can_move: false, refusal: 'The server said why.', path: null, first_day_in_new_class: null, joined_on: null,
    expected_consent: null, ...over,
});

test('the ticks: the first check ticks everyone who can move, and a re-check never ticks anybody', () => {
    const first = [student(1), student(2), stuck(3), student(4)];

    assert.deepEqual(ticksAfterCheck(null, first), [1, 2, 4]);

    // The office unticked 2. On the next check 4 can no longer move and 3 newly can.
    const next = [student(1), student(2), student(3), stuck(4)];
    assert.deepEqual(ticksAfterCheck([1, 4], next), [1], 'a row that newly can move arrived ticked, or one that cannot kept its tick');

    // Nobody ticked stays nobody ticked: an empty selection is the office's too.
    assert.deepEqual(ticksAfterCheck([], next), []);
    // A row that left the list is not sent.
    assert.deepEqual(ticksAfterCheck([1, 9], next), [1]);
});

test('the bulk buttons: everyone who can move, and only those who came from the class chosen', () => {
    const list = [student(1, { came_from_target: true }), student(2), stuck(3, { came_from_target: true }), student(4, { came_from_target: true })];

    assert.deepEqual(everyoneWhoCanMove(list), [1, 2, 4]);
    // One who came from there and cannot go back is not ticked.
    assert.deepEqual(whoCameFromTarget(list), [1, 4]);
});

test('"Move the rest" is only the students who were not reached or were busy, and only those who can still move', () => {
    const answer = { students: [
        { membership_id: 1, name: 'A', outcome: 'moved' as const },
        { membership_id: 2, name: 'B', outcome: 'not_moved' as const, reason: 'busy', retry: true },
        { membership_id: 3, name: 'C', outcome: 'not_moved' as const, reason: 'a decision about the rosters', retry: false },
        { membership_id: 4, name: 'D', outcome: 'not_reached' as const },
        { membership_id: 5, name: 'E', outcome: 'not_reached' as const },
    ] };
    // 6 was left unticked by the office and never named. 3's reason has since been cleared. 5 can no longer move.
    const fresh = [student(2), student(3), student(4), stuck(5), student(6)];

    assert.deepEqual(theRest(answer, fresh), [2, 4]);
    assert.equal(someWereLeft(answer), true);
    assert.equal(someWereLeft({ students: [answer.students[0], answer.students[2]] }), false);
});

test('what one request carries: the ticked rows that can move, in the order of the list, no more than the server takes', () => {
    const list = [student(1), student(2), stuck(3), student(4), student(5)];

    assert.deepEqual(toSend([5, 1, 3, 4], list, 60).map((s) => s.membership_id), [1, 4, 5]);
    assert.deepEqual(toSend([5, 1, 4], list, 2).map((s) => s.membership_id), [1, 4]);
    assert.deepEqual(toSend([], list, 60), []);
});

test('what a class move sends: the pairs a form carries, strings only, each ticked row with what it was shown', () => {
    const rows = [
        student(11, { path: 'returned', joined_on: '2026-09-01', expected_consent: 'm1f0s0n0e0', grade_after: '2nd' }),
        student(12, { joined_on: null, grade_label: null, grade_after: null }),
    ];

    const fields = classMoveFields({ toGroupId: 2, movedOn: '2026-10-04', gradeMode: 'keep', gradeLabel: 'ignored unless set' },
        { expected_bucks_rule: null }, rows);

    assert.deepEqual(fields, [
        ['to_group_id', '2'],
        ['moved_on', '2026-10-04'],
        ['grade_mode', 'keep'],
        ['expected_bucks_rule', ''],
        ['students[0][membership_id]', '11'],
        ['students[0][expected_path]', 'returned'],
        ['students[0][expected_first_day]', '2026-10-04'],
        ['students[0][expected_joined_on]', '2026-09-01'],
        ['students[0][expected_consent]', 'm1f0s0n0e0'],
        ['students[0][expected_grade]', '2nd'],
        ['students[1][membership_id]', '12'],
        ['students[1][expected_path]', 'left_and_started'],
        ['students[1][expected_first_day]', '2026-10-04'],
        // A blank is '', never the word "null": the body is form-encoded.
        ['students[1][expected_joined_on]', ''],
        ['students[1][expected_consent]', 'm0f0s0n0e0'],
        ['students[1][expected_grade]', ''],
    ]);
    fields.forEach(([key, value]) => {
        assert.equal(typeof value, 'string', key);
        assert.notEqual(value, 'null', key);
        assert.notEqual(value, 'undefined', key);
    });

    // The one grade travels only with "give everyone this grade", trimmed; the rule for Manara Bucks as it was shown.
    const set = classMoveFields({ toGroupId: 2, movedOn: '2026-10-04', gradeMode: 'set', gradeLabel: ' Level 2 ' },
        { expected_bucks_rule: 'move' }, []);
    assert.deepEqual(set, [
        ['to_group_id', '2'], ['moved_on', '2026-10-04'], ['grade_mode', 'set'], ['grade_label', 'Level 2'], ['expected_bucks_rule', 'move'],
    ]);
});

test('the grade is shown wherever the one a student will hold differs from the one they hold', () => {
    assert.equal(gradeChange({ grade_label: '1st', grade_after: '1st' }), null);
    assert.equal(gradeChange({ grade_label: ' 1st ', grade_after: '1st' }), null);
    assert.equal(gradeChange({ grade_label: null, grade_after: null }), null);
    assert.equal(gradeChange({ grade_label: '1st', grade_after: '2nd' }), '1st to 2nd');
    assert.equal(gradeChange({ grade_label: null, grade_after: 'Level 2' }), 'No grade to Level 2');
    assert.equal(gradeChange({ grade_label: '2nd', grade_after: null }), '2nd to no grade');
});

test('why the Move button is off: one reason at a time, the first thing to do', () => {
    const ready = { saving: false, toGroupId: 2, movedOn: '2026-10-04', check: 'ready' as const, canMove: true, gradeMode: 'keep' as const, gradeLabel: '', ticked: 3 };

    assert.equal(whyNotYet(ready), '');
    assert.equal(whyNotYet({ ...ready, toGroupId: null }), 'Choose a class first');
    assert.equal(whyNotYet({ ...ready, movedOn: '' }), 'Choose the first day in the new class');
    assert.equal(whyNotYet({ ...ready, check: 'checking' }), 'Checking…');
    assert.equal(whyNotYet({ ...ready, check: 'failed' }), 'The check did not finish');
    assert.equal(whyNotYet({ ...ready, canMove: false }), 'This class cannot be moved there yet');
    assert.equal(whyNotYet({ ...ready, gradeMode: null }), 'Choose what happens to grades');
    assert.equal(whyNotYet({ ...ready, gradeMode: 'set', gradeLabel: '  ' }), 'Type the grade to give everyone');
    assert.equal(whyNotYet({ ...ready, gradeMode: 'set', gradeLabel: 'Level 2' }), '');
    assert.equal(whyNotYet({ ...ready, ticked: 0 }), 'Nobody on this list is ticked');
    assert.equal(whyNotYet({ ...ready, saving: true }), 'Moving…');
    // No class comes before no grade choice.
    assert.equal(whyNotYet({ ...ready, toGroupId: null, gradeMode: null, ticked: 0 }), 'Choose a class first');

    assert.equal(moveButtonLabel(1), 'Move 1 student');
    assert.equal(moveButtonLabel(12), 'Move 12 students');
});

test('students who were moved here from a class that is not on the list: why it is not offered', () => {
    const row = (from: number | null, over: Record<string, any> = {}): any => ({
        role: 'member', left_on: null, moved_from_group_id: from,
        moved_from: from === null ? null : { id: from, name: from === 9 ? 'Kindergarten' : '1st Grade', deleted_at: null }, ...over,
    });
    const roster = [
        row(9), row(9), row(1), row(null),
        // Not a current student of this class: not counted.
        row(9, { left_on: '2026-10-01' }), row(9, { role: 'guardian' }),
    ];

    assert.deepEqual(movedFromClassesNotOffered(roster, [{ id: 1 }, { id: 3 }]), [
        '2 students here were moved from Kindergarten, which is not in this list: it is switched off, has ended or was '
            + 'removed. To move them back, switch Kindergarten on again in its Edit form first.',
    ]);
    assert.deepEqual(movedFromClassesNotOffered(roster, [{ id: 1 }, { id: 9 }]), []);

    // A class that was removed has no name to give and cannot be switched on again.
    assert.deepEqual(
        movedFromClassesNotOffered([row(9, { moved_from: { id: 9, name: 'Kindergarten', deleted_at: '2026-10-02' } })], []),
        ['1 student here was moved from a class that was removed, so that student cannot be moved back to it.'],
    );
});

test('a refused request: the sentence, the class to open and the students that changed; no answer is not a refusal', () => {
    const http = (status: number, data: any) => ({ response: { status, data } });

    // "Changed while you were looking" carries the students that differ: read as ids, never as a validation bag.
    assert.deepEqual(refusalOf(http(409, {
        status: 'error', message: 'This roster changed while you were looking. Nothing was moved. Look again.', open_group: null,
        data: { students: [{ membership_id: 11, name: 'Maryam Student' }, { membership_id: '12' }] },
    })), {
        arrived: true, message: 'This roster changed while you were looking. Nothing was moved. Look again.', openGroup: null, changed: [11, 12],
    });

    assert.deepEqual(refusalOf(http(422, { status: 'error', message: '2nd Grade is not running.', open_group: { id: 2, name: '2nd Grade' } })),
        { arrived: true, message: '2nd Grade is not running.', openGroup: { id: 2, name: '2nd Grade' }, changed: [] });

    // The legacy validation envelope.
    assert.equal(refusalOf(http(422, { status: 'failed', data: { students: ['Move up to 60 students at a time.'], grade_mode: ['Choose what happens to grades.'] } })).message,
        'Move up to 60 students at a time. Choose what happens to grades.');

    // A timeout, a dropped connection, a gateway's or the server's own fault: nothing can be assumed.
    for (const lost of [new Error('Network Error'), { response: undefined }, http(504, '<html>'), http(500, { message: 'Server Error' })]) {
        assert.equal(refusalOf(lost).arrived, false);
    }
});

test('the wiring: the server\'s lines are printed as they come, the body is appended as it was built, and the dialog fits a phone', () => {
    const modal = source('views/dashboard/groups/MoveClassModal.vue');
    const helper = source('core/helpers/rosterClassMove.ts');
    const store = source('stores/masjid/groupsStore.ts');

    // NO SENTENCE ABOUT WHAT A MOVE DOES IS WRITTEN IN THE BROWSER, in the dialog or in its
    // helper: the single move's phrases, and the ones a carried consent and Manara Bucks added.
    for (const file of [modal, helper]) {
        assert.doesNotMatch(file, /is now in |start fresh|shown as moved|in force again|Record consent again/);
        assert.doesNotMatch(file, /carried as it is|Manara Bucks go with|is in force again|are now in |guardian places/);
    }
    for (const group of ['who', 'afterwards']) {
        assert.match(modal, new RegExp(`v-for="\\(line, i\\) in preview\\.lines\\.${group}"`));
    }
    assert.match(modal, /v-for="\(line, i\) in preview\.lines\[group\.key\]"/);
    assert.match(modal, /v-for="\(line, i\) in student\.lines"/);
    for (const group of ['done', 'consent', 'bucks', 'afterwards']) {
        assert.match(modal, new RegExp(`v-for="\\(line, i\\) in result\\.lines\\.${group}"`));
    }

    // Full screen below the `sm` breakpoint, with its own scroll.
    assert.match(modal, /class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"/);

    // THE BODY IS IN THE ORDER IT IS ACTED ON: the choices, then the list of students, then what
    // follows them, then the closed "Afterwards, check". The Move button is the form's last word.
    const form = modal.slice(modal.indexOf('<form v-else'), modal.indexOf('</form>'));
    const at = (needle: string) => {
        const i = form.indexOf(needle);
        assert.notEqual(i, -1, `${needle} is not in the form`);
        return i;
    };
    const order = ['id="move-class-to"', 'id="move-class-first-day"', 'name="move-class-grades"', 'data-part="students"',
        'data-part="follows"', 'data-part="afterwards"', 'Nothing is saved until you press Move.', 'data-part="move"'].map(at);
    assert.deepEqual(order, [...order].sort((a, b) => a - b));
    // A REFUSED RUN IS SAID FIRST, above the choices and the list, never after them.
    assert.ok(at('data-part="refused"') < at('id="move-class-to"'), 'the refusal of a run is drawn below the list again');

    // ONLY THE MOVE BUTTON SENDS. A browser submits a form on Enter from any of its fields, and
    // this one holds a checkbox per student: so the form has no submit handler and no submit
    // button, and Move is a plain button with its own click.
    assert.doesNotMatch(modal, /type="submit"/);
    assert.doesNotMatch(modal, /@submit\.prevent="/);
    assert.match(modal, /<button type="button" class="btn btn-primary" data-part="move" :disabled="!canMove" @click="save">/);

    // THE BACKDROP: its own handler, which leaves a result, the lost-answer notice and a list
    // the office has changed a tick in alone.
    assert.match(modal, /@click\.self="backdrop"/);
    assert.match(modal, /if \(saving\.value \|\| result\.value \|\| lost\.value \|\| touched\.value\) return;/);

    // Every control is a full touch target, by one rule for the whole dialog.
    assert.match(modal, /class="modal fade show d-block move-class"/);
    assert.match(modal, /\.move-class \.btn,\s+\.move-class \.form-check,[^}]*min-height: 44px;/);
    // A tick's LABEL is the target: it takes the row's height and the width that is left.
    // (Measured in a browser before this rule: a 16 px box and a label 24 px high in a 44 px row.)
    assert.match(modal, /\.move-class \.form-check-label \{\s+display: flex;\s+align-items: center;\s+flex: 1 1 auto;\s+min-height: 44px;\s+\}/);

    // THE FORM MAY SHRINK, SO THE BODY SCROLLS AND THE FOOTER STAYS IN REACH. Seen in a browser
    // at 360 px without this rule: the body did not scroll and the box cut off everything below
    // the fold, the Move button with it. No test without a layout can see that, so the rule is
    // pinned where it is written.
    assert.match(modal, /\.move-class form \{\s+display: flex;\s+flex-direction: column;\s+flex: 1 1 auto;\s+min-height: 0;\s+\}/);
    assert.match(modal, /<form v-else @submit\.prevent>\s+<div class="modal-body">/);

    // Nothing the office must read lives in a tooltip, and every action has a word.
    assert.doesNotMatch(modal, /\stitle="/);
    assert.doesNotMatch(modal, /v-tooltip|data-bs-toggle="tooltip"/);

    // No grade choice is made for the office.
    assert.match(modal, /gradeMode: null,/);
    // Typing in the grade field waits half a second, or for the field to lose focus.
    assert.match(modal, /gradeText = setTimeout\(choiceChanged, 500\);/);
    assert.match(modal, /@blur="gradeTextDone"/);

    // The store appends the pairs as they come and adds nothing; the two verbs are one URI.
    const move = store.slice(store.indexOf('async function moveClass'), store.indexOf('/** Put a student who left back'));
    assert.match(move, /fields\.forEach\(\(\[key, value\]\) => body\.append\(key, value\)\);/);
    assert.equal((move.match(/body\.append\(/g) ?? []).length, 1);
    assert.match(move, /\/groups\/\$\{groupId\}\/class-move` as BackendApiRoute,\s+body/);

    const check = store.slice(store.indexOf('async function previewClassMove'), store.indexOf('async function moveClass'));
    assert.match(check, /\/groups\/\$\{groupId\}\/class-move\?\$\{query\.toString\(\)\}/);
    assert.match(check, /if \(gradeMode\) query\.set\('grade_mode', gradeMode\);/);
    assert.doesNotMatch(check, /ApiService\.post/);

    // The dialog asks only its own two verbs and the shared class list; it never touches the page's roster.
    assert.doesNotMatch(modal, /fetchMemberships|moveMembership|putBack\(/);
    assert.equal((modal.match(/groupsStore\.moveClass\(/g) ?? []).length, 1);
});
