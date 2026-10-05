/**
 * Moving a student to another class: what the roster screen works out for itself
 * (core/helpers/rosterMove.ts). The sentences about a move are the server's and are tested there;
 * the dialogs are driven in roster-move-mounted.test.ts.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    applyConsentAnswer, carriedConsentLabel, carriedConsentNote, classOptions, className, consentBannerCount,
    consentBannerText, day, focusIdFromQuery, focusQuery, moveBody, movedLabels, putBackForm,
} from '../core/helpers/rosterMove.ts';

const source = (path: string) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const student = (over: Record<string, any> = {}): any => ({
    id: 10, role: 'member', contact_id: 100, guardian_of_contact_id: null, left_on: null, provenance: 'confirmed',
    consent_granted_at: null, consent_scope: null, moved_from_group_id: null, moved_to_group_id: null, moved_on: null,
    moved_to: null, moved_from: null, moved_to_state: null, contact: { first_name: 'Maryam', last_name: 'Student' }, ...over,
});
const guardian = (id: number, first: string, over: Record<string, any> = {}): any => ({
    ...student(), id, role: 'guardian', contact_id: 200 + id, guardian_of_contact_id: 100,
    contact: { first_name: first, last_name: 'Guardian' }, ...over,
});
const second = { id: 2, name: '2nd Grade', deleted_at: null };
const movedOut = (state: any, over: Record<string, any> = {}): any => student({
    left_on: '2026-10-03T00:00:00.000000Z', moved_to_group_id: 2, moved_on: '2026-10-04T00:00:00.000000Z',
    moved_to: second, moved_to_state: state, ...over,
});

test('the classes on offer: only running classes, never this one, in the page\'s order', () => {
    const groups = [
        { id: 1, name: '1st Grade', kind: 'class', is_active: true, ends_on: null, position: 1 },
        { id: 5, name: 'Zayd\'s class', kind: 'class', is_active: true, ends_on: null, position: null },
        { id: 3, name: '3rd Grade', kind: 'class', is_active: true, ends_on: null, position: 3 },
        { id: 2, name: '2nd Grade', kind: 'class', is_active: true, ends_on: null, position: 2 },
        { id: 6, name: 'Arabic B', kind: 'class', is_active: true, ends_on: null, position: null },
        { id: 7, name: 'Evening circle', kind: 'halaqa', is_active: true, ends_on: null, position: 0 },
        { id: 8, name: 'Switched off', kind: 'class', is_active: false, ends_on: null, position: 0 },
        { id: 9, name: 'Last year', kind: 'class', is_active: true, ends_on: '2026-06-30T00:00:00.000000Z', position: 0 },
        { id: 11, name: 'Ends today', kind: 'class', is_active: true, ends_on: '2026-10-04', position: 9 },
    ];

    assert.deepEqual(classOptions(groups, 1, '2026-10-04').map((o) => o.name),
        ['2nd Grade', '3rd Grade', 'Ends today', 'Arabic B', 'Zayd\'s class']);
    // A class that had not ended on an earlier day is on offer for that day.
    assert.ok(classOptions(groups, 1, '2026-06-01').some((o) => o.name === 'Last year'));
    assert.deepEqual(classOptions([], 1, '2026-10-04'), []);
});

test('what a move sends: strings, the keys the server knows, and what the dialog showed', () => {
    const body = moveBody({ toGroupId: 2, movedOn: '2026-10-04', gradeLabel: ' 2nd ' },
        { path: 'left_and_started', first_day_in_new_class: '2026-10-05', joined_on: '2026-10-05' });

    assert.deepEqual(body, {
        to_group_id: '2', moved_on: '2026-10-04', grade_label: '2nd',
        expected_path: 'left_and_started', expected_first_day: '2026-10-05',
    });
    Object.values(body).forEach((v) => {
        assert.equal(typeof v, 'string');
        assert.ok(!['null', 'undefined', 'true', 'false'].includes(v));
    });

    // A return also says which joining day the office read. An empty grade is sent: it clears the grade.
    const back = moveBody({ toGroupId: 1, movedOn: '2026-10-04', gradeLabel: '' },
        { path: 'returned', first_day_in_new_class: '2026-10-04', joined_on: '2026-09-01' });
    assert.equal(back.expected_joined_on, '2026-09-01');
    assert.equal(back.grade_label, '');
    assert.deepEqual(Object.keys(back).sort(),
        ['expected_first_day', 'expected_joined_on', 'expected_path', 'grade_label', 'moved_on', 'to_group_id']);
});

test('what a move sends: what the dialog showed about consent and the rule for Manara Bucks, each only when the preview gave one', () => {
    const form = { toGroupId: 2, movedOn: '2026-10-04', gradeLabel: '2nd' };
    const shown = { path: 'left_and_started' as const, first_day_in_new_class: '2026-10-05', joined_on: '2026-10-05' };

    const both = moveBody(form, { ...shown, expected_consent: 'm1f0s0n1e0', expected_bucks_rule: 'move' });
    assert.equal(both.expected_consent, 'm1f0s0n1e0');
    assert.equal(both.expected_bucks_rule, 'move');
    assert.deepEqual(Object.keys(both).sort(),
        ['expected_bucks_rule', 'expected_consent', 'expected_first_day', 'expected_path', 'grade_label', 'moved_on', 'to_group_id']);

    // The rule is null until a move can carry a balance: the key is left out, never sent as a word or a blank.
    const consentOnly = moveBody(form, { ...shown, expected_consent: 'm0f0s0n0e0', expected_bucks_rule: null });
    assert.equal(consentOnly.expected_consent, 'm0f0s0n0e0');
    assert.equal('expected_bucks_rule' in consentOnly, false);

    // A server from before the release sends neither: the body is the one it knows.
    for (const none of [{}, { expected_consent: null, expected_bucks_rule: null }, { expected_consent: '', expected_bucks_rule: undefined }]) {
        assert.deepEqual(Object.keys(moveBody(form, { ...shown, ...none })).sort(),
            ['expected_first_day', 'expected_path', 'grade_label', 'moved_on', 'to_group_id']);
    }

    Object.values(both).forEach((v) => {
        assert.equal(typeof v, 'string');
        assert.ok(!['null', 'undefined', 'true', 'false'].includes(v));
    });
});

test('a link to a roster row: only a plain positive whole number is a row, and no row means no query', () => {
    assert.equal(focusIdFromQuery('77'), 77);
    for (const not of [undefined, null, '', '0', '-3', '7.5', '07', '7 ', 'abc', '1e3', ['77'], 77, '99999999999999999999']) {
        assert.equal(focusIdFromQuery(not), null, JSON.stringify(not));
    }

    assert.deepEqual(focusQuery(77), { focus: '77' });
    assert.deepEqual(focusQuery(null), {});
    assert.deepEqual(focusQuery(undefined), {});
    assert.equal(focusIdFromQuery(focusQuery(77).focus), 77);
});

test('a moved row is labelled by where the student is now', () => {
    assert.equal(day('2026-10-04T00:00:00.000000Z'), '4 Oct 2026');
    assert.equal(className(null), 'a class that was removed');
    assert.equal(className({ id: 2, name: '2nd Grade', deleted_at: '2026-10-04T10:00:00Z' }), 'a class that was removed');

    assert.deepEqual(movedLabels(student()), { badge: null, note: null });
    assert.deepEqual(movedLabels(student({ left_on: '2026-09-20' })), { badge: 'Left 20 Sep 2026', note: null });

    // Still in the class they were moved to.
    const current = { student_there: 'current', open_group: second, guardians_not_vouched: [] };
    assert.deepEqual(movedLabels(movedOut(current)), { badge: 'Moved to 2nd Grade 4 Oct 2026', note: null });

    // They have left that class too, or the entry there is gone: the ordinary badge, and a line.
    for (const there of ['left', 'none']) {
        assert.deepEqual(movedLabels(movedOut({ ...current, student_there: there })),
            { badge: 'Left 3 Oct 2026', note: 'Was moved to 2nd Grade on 4 Oct 2026. No longer there.' });
    }

    // Put back by hand while still in the other class: on two rosters, and the roster says why.
    assert.deepEqual(movedLabels(movedOut(current, { left_on: null })), { badge: 'Put back after a move to 2nd Grade', note: null });

    // A class deleted since.
    assert.equal(movedLabels(movedOut({ ...current, student_there: 'none' }, { moved_to: { ...second, deleted_at: '2026-10-04' } })).note,
        'Was moved to a class that was removed on 4 Oct 2026. No longer there.');

    // The row they hold now.
    assert.deepEqual(movedLabels(student({ moved_from_group_id: 1, moved_from: { id: 1, name: '1st Grade', deleted_at: null }, moved_on: '2026-10-04' })),
        { badge: null, note: 'Moved from 1st Grade 4 Oct 2026' });
});

test('a consent a move carried is labelled by what the entry holds now, and a class removed since has no name', () => {
    const first = { id: 1, name: '1st Grade', deleted_at: null };
    const carried = guardian(1, 'Huda', { consent_scope: 'media', consent_granted_at: '2026-09-05T00:00:00.000000Z',
        consent_carried_from_group_id: 1, consent_carried_from: first });
    const withdrawn = guardian(2, 'Nadia', { consent_carried_from_group_id: 1, consent_carried_from: first });

    assert.equal(carriedConsentLabel(carried), 'Carried from 1st Grade');
    assert.equal(carriedConsentLabel(withdrawn), 'Withdrawn here after it was carried from 1st Grade');

    // Recorded by the office for this class, or never asked: no mark, no line.
    assert.equal(carriedConsentLabel(guardian(3, 'Own', { consent_scope: 'feed', consent_granted_at: '2026-09-05' })), null);
    assert.equal(carriedConsentLabel(guardian(4, 'Never')), null);
    // A row from a server that does not send the mark at all.
    const { consent_carried_from_group_id: _a, consent_carried_from: _b, ...old } = carried;
    assert.equal(carriedConsentLabel(old), null);

    // The class was removed since: deleted, or no longer served with the row.
    assert.equal(carriedConsentLabel({ ...carried, consent_carried_from: { ...first, deleted_at: '2026-10-04T10:00:00Z' } }),
        'Carried from a class that was removed');
    assert.equal(carriedConsentLabel({ ...withdrawn, consent_carried_from: null }),
        'Withdrawn here after it was carried from a class that was removed');

    // The consent dialog's line: only while the carried consent still stands.
    assert.equal(carriedConsentNote(carried, 'Maryam Student'),
        'Carried from 1st Grade when Maryam Student was moved. Saving records it for this class.');
    assert.equal(carriedConsentNote(withdrawn, 'Maryam Student'), null);
    assert.equal(carriedConsentNote(guardian(3, 'Own', { consent_scope: 'feed', consent_granted_at: '2026-09-05' }), 'Maryam Student'), null);

    // CARRIED, AND NOW LESS THAN THE CLASS IT CAME FROM HOLDS (the server says so: it takes the
    // other class's row). The label says what the two classes hold, not who changed what, and
    // the dialog's line no longer promises that saving makes it this class's own.
    const less = { ...carried, consent_scope: 'feed', consent_less_than_carried_from: true };
    assert.equal(carriedConsentLabel(less), 'Carried from 1st Grade, which holds photograph consent');
    assert.equal(carriedConsentNote(less, 'Maryam Student'),
        'Carried from 1st Grade when Maryam Student was moved. 1st Grade holds photograph consent; here it is the class story only. '
        + 'Saving photographs records it for this class.');
    assert.equal(carriedConsentLabel({ ...less, consent_carried_from: null }), 'Carried from a class that was removed, which holds photograph consent');
    assert.match(carriedConsentNote({ ...less, consent_carried_from: null }, 'Maryam Student')!, /That class holds photograph consent/);
    // The flag means nothing without the mark, and nothing on a withdrawn entry.
    assert.equal(carriedConsentLabel({ ...less, consent_carried_from_group_id: null }), null);
    assert.equal(carriedConsentLabel({ ...withdrawn, consent_less_than_carried_from: true }), 'Withdrawn here after it was carried from 1st Grade');
});

test('the answer to a consent is written onto the row with its mark: a record clears it, a withdrawal keeps it', () => {
    const first = { id: 1, name: '1st Grade', deleted_at: null };
    const carried = () => guardian(1, 'Huda', { consent_scope: 'media', consent_granted_at: '2026-09-05T00:00:00.000000Z',
        consent_carried_from_group_id: 1, consent_carried_from: first });

    // Saved in the dialog, changed or not: this class's own record now.
    const recorded = carried();
    applyConsentAnswer(recorded, { consent_scope: 'media', consent_granted_at: '2026-09-05T00:00:00.000000Z', consent_carried_from_group_id: null });
    assert.deepEqual([recorded.consent_scope, recorded.consent_granted_at, recorded.consent_carried_from_group_id, recorded.consent_carried_from],
        ['media', '2026-09-05T00:00:00.000000Z', null, null]);
    assert.equal(carriedConsentLabel(recorded), null);

    // Withdrawn: both columns blank, the mark kept, and the cell says so at once.
    const withdrawn = carried();
    applyConsentAnswer(withdrawn, { consent_scope: null, consent_granted_at: null, consent_carried_from_group_id: 1 });
    assert.deepEqual([withdrawn.consent_scope, withdrawn.consent_granted_at, withdrawn.consent_carried_from_group_id], [null, null, 1]);
    assert.deepEqual(withdrawn.consent_carried_from, first);
    assert.equal(carriedConsentLabel(withdrawn), 'Withdrawn here after it was carried from 1st Grade');

    // REDUCED: the class story where the class it came from holds photographs. The mark is kept,
    // and the cell says what the two classes hold at once.
    const reduced = carried();
    applyConsentAnswer(reduced, { consent_scope: 'feed', consent_granted_at: '2026-10-04T00:00:00.000000Z',
        consent_carried_from_group_id: 1, consent_less_than_carried_from: true });
    assert.deepEqual([reduced.consent_scope, reduced.consent_carried_from_group_id, reduced.consent_less_than_carried_from], ['feed', 1, true]);
    assert.equal(carriedConsentLabel(reduced), 'Carried from 1st Grade, which holds photograph consent');
    // ...and recorded again for as much: this class's own, and the flag goes with the mark.
    applyConsentAnswer(reduced, { consent_scope: 'media', consent_granted_at: '2026-10-04T00:00:00.000000Z',
        consent_carried_from_group_id: null, consent_less_than_carried_from: false });
    assert.deepEqual([reduced.consent_carried_from_group_id, reduced.consent_less_than_carried_from], [null, false]);
    assert.equal(carriedConsentLabel(reduced), null);

    // An answer without the key (the column is not there yet), or no row at all: nothing is carried.
    const before = carried();
    applyConsentAnswer(before, { consent_scope: 'feed', consent_granted_at: '2026-10-04T00:00:00.000000Z' });
    assert.deepEqual([before.consent_scope, before.consent_carried_from_group_id, before.consent_carried_from], ['feed', null, null]);
    const empty = carried();
    applyConsentAnswer(empty, null);
    assert.deepEqual([empty.consent_scope, empty.consent_granted_at, empty.consent_carried_from_group_id], [null, null, null]);
});

test('the consent banner counts confirmed, current guardians of students who moved in and have no consent here', () => {
    const roster = [
        student({ moved_from_group_id: 1 }),
        guardian(1, 'Huda'),                                              // counted
        guardian(2, 'Stranger', { provenance: 'self_asserted' }),         // a claim cannot take consent
        guardian(3, 'Nadia', { consent_granted_at: '2026-10-04' }),       // recorded
        guardian(4, 'Former', { left_on: '2026-09-20' }),                 // not current
        student({ id: 11, contact_id: 101 }),                             // never moved
        guardian(5, 'Other', { guardian_of_contact_id: 101 }),
        // Withdrawn here after it was carried: the family said no, so it is not something to record.
        guardian(6, 'Samira', { consent_carried_from_group_id: 1, consent_carried_from: { id: 1, name: '1st Grade', deleted_at: null } }),
        // Carried and standing: has consent.
        guardian(7, 'Layla', { consent_granted_at: '2026-09-05', consent_scope: 'feed', consent_carried_from_group_id: 1 }),
    ];

    assert.equal(consentBannerCount(roster), 1);
    // Without the mark the withdrawn entry would have been the second one counted.
    assert.equal(consentBannerCount(roster.map((r) => ({ ...r, consent_carried_from_group_id: null }))), 2);
    assert.equal(consentBannerText(0), '');
    assert.match(consentBannerText(1), /^1 guardian of students who moved into this class has no consent recorded here\./);
    assert.match(consentBannerText(2), /^2 guardians of students who moved into this class have no consent/);
});

test('put back: a row that was never moved keeps the confirmation it always had', () => {
    const form = putBackForm(student({ left_on: '2026-09-20' }), []);

    assert.equal(form.form, 'unchanged');
    assert.equal(form.title, 'Put them back on the roster?');
    assert.equal(form.confirmLabel, 'Yes, put them back');
});

test('put back: blocked while a guardian here is no longer a confirmed guardian where the student is, with no way to confirm', () => {
    const sentence = 'Putting Maryam Student back would also give Gamal Guardian access to this class again. '
        + 'Gamal Guardian is not a confirmed guardian of Maryam Student in 2nd Grade.';
    const roster = [guardian(1, 'Gamal'), guardian(2, 'Huda')];

    for (const there of ['current', 'left']) {
        const form = putBackForm(movedOut({
            student_there: there, open_group: second,
            guardians_not_vouched: [{ membership_id: 1, reason: 'no_entry', sentence }],
        }), roster);

        assert.equal(form.form, 'blocked');
        assert.equal(form.title, 'Not yet: check the guardians first');
        assert.equal(form.confirmLabel, null, 'the blocked form offers a way to put the student back');
        assert.equal(form.lines[0], sentence);
        assert.deepEqual(form.openGroup, second);
        // "Add them" is advice only while the student is in that class: the server refuses it once they have left.
        assert.equal(/add or confirm them in 2nd Grade/.test(form.lines[1]), there === 'current');
        assert.equal(/confirming it there also clears this/.test(form.lines[1]), there === 'left');
        // The sentence that names the guardian is the one drawn as the reason; the advice is not.
        assert.deepEqual(form.stops, [0]);
    }
});

// What the server says "Put back" would bring back, each as it sends it (RosterMove::movedToStates).
const copyWithdrawn = 'Huda Guardian withdrew consent in 2nd Grade after it had been carried there from this class. '
    + 'Putting Maryam Student back would bring the consent recorded here (the class story and photographs, recorded 5 Sep 2026) into force again.';
const sourceWithdrawn = 'Huda Guardian\'s consent here (the class story and photographs, recorded 5 Sep 2026) was carried from 2nd Grade, '
    + 'and consent in 2nd Grade has since been withdrawn. Putting Maryam Student back would bring it into force again.';
const sourceNarrowed = 'Huda Guardian\'s consent here (the class story and photographs, recorded 5 Sep 2026) was carried from 2nd Grade, '
    + 'and consent in 2nd Grade is now for the class story only. Putting Maryam Student back would bring it into force again.';
const remedy = 'Withdraw it on this roster first (the Consent button on the guardian\'s row), then put Maryam Student back.';
const comesBack = 'Putting Maryam Student back brings Nadia Guardian\'s consent in this class into force again (the class story, recorded 5 Sep 2026).';
const bucks = 'Any Manara Bucks that moved with Maryam Student to 2nd Grade stay there. Putting Maryam Student back here does not bring them back.';

test('put back: blocked while it would bring back a consent the family withdrew or narrowed on the other side of a move', () => {
    const roster = [guardian(1, 'Huda', { left_on: '2026-10-03' }), guardian(2, 'Nadia', { left_on: '2026-10-03' })];

    for (const [there, block] of [['current', copyWithdrawn], ['left', sourceWithdrawn], ['none', sourceNarrowed]] as const) {
        const form = putBackForm(movedOut({
            student_there: there, open_group: there === 'none' ? null : second, guardians_not_vouched: [],
            consent_blocks: [block, remedy], consent_lines: [comesBack], bucks_line: bucks,
        }), roster);

        assert.equal(form.form, 'blocked');
        assert.equal(form.title, 'Not yet: check consent first');
        assert.equal(form.confirmLabel, null, 'the blocked form offers a way to put the student back');
        // The server's sentences, whole and in its order: why not, what to do, what else comes back, the Bucks rule.
        assert.deepEqual(form.lines, [block, remedy, comesBack, bucks]);
        assert.deepEqual(form.stops, [0], 'only the sentence that names the consent is drawn as the reason');
        // What it asks for is done on this roster: nothing sends the office to the other class.
        assert.equal(form.openGroup, null);
    }

    // Two guardians, one sentence each, then once what to do.
    const two = putBackForm(movedOut({ student_there: 'current', open_group: second, guardians_not_vouched: [],
        consent_blocks: [copyWithdrawn, sourceNarrowed, remedy], consent_lines: [], bucks_line: null }), roster);
    assert.deepEqual(two.lines, [copyWithdrawn, sourceNarrowed, remedy]);
    assert.deepEqual(two.stops, [0, 1]);
    assert.equal(two.confirmLabel, null);
});

test('put back: a row that simply left is held to the same consent rule, and names what comes back', () => {
    const roster = [guardian(1, 'Huda', { left_on: '2026-10-03' }), guardian(2, 'Nadia', { left_on: '2026-10-03' })];
    const left = (state: any) => student({ left_on: '2026-10-03', moved_to_state: state });
    const plain = 'Maryam Student will be back on the register and every class list, and their guardians will be back in the class with them.';

    // Nothing to say (the server sends no state for such a row): the confirmation it always had.
    const asBefore = putBackForm(left(null), roster);
    assert.deepEqual([asBefore.form, asBefore.lines, asBefore.confirmLabel], ['unchanged', [plain], 'Yes, put them back']);

    // A consent beside the row comes back with the student: named above the button.
    const named = putBackForm(left({ student_there: 'none', open_group: null, guardians_not_vouched: [],
        consent_blocks: [], consent_lines: [comesBack], bucks_line: null }), roster);
    assert.deepEqual([named.form, named.lines, named.confirmLabel], ['unchanged', [plain, comesBack], 'Yes, put them back']);

    // A carried consent beside it whose class of origin has withdrawn it since: NO button,
    // exactly as on a row that a move left. (It used to get the plain confirmation and its
    // button, with no word about consent.)
    const blocked = putBackForm(left({ student_there: 'none', open_group: null, guardians_not_vouched: [],
        consent_blocks: [sourceWithdrawn, remedy], consent_lines: [comesBack], bucks_line: null }), roster);
    assert.equal(blocked.form, 'blocked');
    assert.equal(blocked.title, 'Not yet: check consent first');
    assert.equal(blocked.confirmLabel, null, 'a row that simply left was offered "Put back" over a withdrawn consent');
    assert.deepEqual(blocked.lines, [sourceWithdrawn, remedy, comesBack]);
    assert.deepEqual(blocked.stops, [0]);
    assert.equal(blocked.openGroup, null);

    const two = putBackForm(left({ student_there: 'none', open_group: null, guardians_not_vouched: [],
        consent_blocks: [sourceWithdrawn, sourceNarrowed, remedy], consent_lines: [], bucks_line: null }), roster);
    assert.deepEqual(two.stops, [0, 1]);
});

test('put back: a guardian who is not vouched for and a consent that would come back are both said, each with what to do', () => {
    const sentence = 'Putting Maryam Student back would also give Gamal Guardian access to this class again. '
        + 'Gamal Guardian is not a confirmed guardian of Maryam Student in 2nd Grade.';
    const form = putBackForm(movedOut({
        student_there: 'current', open_group: second,
        guardians_not_vouched: [{ membership_id: 1, reason: 'no_entry', sentence }],
        consent_blocks: [copyWithdrawn, remedy], consent_lines: [], bucks_line: bucks,
    }), [guardian(1, 'Gamal'), guardian(2, 'Huda')]);

    assert.equal(form.form, 'blocked');
    assert.equal(form.title, 'Not yet: check the guardians first');
    assert.equal(form.confirmLabel, null);
    assert.equal(form.lines.length, 5);
    assert.equal(form.lines[0], sentence);
    assert.match(form.lines[1], /^Remove that entry on this roster first\./);
    assert.deepEqual(form.lines.slice(2), [copyWithdrawn, remedy, bucks]);
    assert.deepEqual(form.stops, [0, 2]);
    assert.deepEqual(form.openGroup, second);
});

test('put back: with nothing blocked, the consent that comes back and the rule for Manara Bucks are printed above the button', () => {
    const roster = [guardian(1, 'Nadia', { left_on: '2026-10-03' })];

    const both = putBackForm(movedOut({ student_there: 'current', open_group: second, guardians_not_vouched: [],
        consent_blocks: [], consent_lines: [comesBack], bucks_line: bucks }), roster);
    assert.equal(both.form, 'both_classes');
    assert.equal(both.confirmLabel, 'Put back here anyway');
    assert.match(both.lines[0], /leaves them in both classes, on two registers\./);
    assert.deepEqual(both.lines.slice(1), [comesBack, bucks]);
    assert.deepEqual(both.stops, []);

    const ordinary = putBackForm(movedOut({ student_there: 'left', open_group: null, guardians_not_vouched: [],
        consent_blocks: [], consent_lines: [comesBack], bucks_line: null }), roster);
    assert.equal(ordinary.form, 'ordinary');
    assert.equal(ordinary.confirmLabel, 'Yes, put them back');
    assert.deepEqual(ordinary.lines.slice(1), [comesBack]);

    // A server from before the release sends none of the three: the form is the one it always was.
    const old = putBackForm(movedOut({ student_there: 'current', open_group: second, guardians_not_vouched: [] }), roster);
    assert.equal(old.form, 'both_classes');
    assert.equal(old.lines.length, 1);
    assert.equal(old.confirmLabel, 'Put back here anyway');
});

test('put back: in both classes, or back for good, each says what it means and who comes back', () => {
    const roster = [guardian(1, 'Huda'), guardian(2, 'Stranger', { provenance: 'self_asserted' }), guardian(3, 'Other', { guardian_of_contact_id: 999 })];

    const both = putBackForm(movedOut({ student_there: 'current', open_group: second, guardians_not_vouched: [] }), roster);
    assert.equal(both.form, 'both_classes');
    assert.equal(both.confirmLabel, 'Put back here anyway');
    assert.match(both.lines[0], /leaves them in both classes, on two registers\./);
    assert.match(both.lines[0], /These guardians come back into this class with them: Huda Guardian, Stranger Guardian \(not confirmed\)\./);
    assert.match(both.lines[0], /open 2nd Grade and use Move there\./);
    assert.deepEqual(both.openGroup, second);

    for (const there of ['left', 'none']) {
        const ordinary = putBackForm(movedOut({ student_there: there, open_group: null, guardians_not_vouched: [] }), roster);
        assert.equal(ordinary.form, 'ordinary');
        assert.equal(ordinary.confirmLabel, 'Yes, put them back');
        assert.match(ordinary.lines[0], /was moved to 2nd Grade on 4 Oct 2026 and is no longer there\./);
        assert.match(ordinary.lines[0], /Huda Guardian, Stranger Guardian \(not confirmed\)\. Remove any who should no longer be a guardian/);
        // The two-classes warning would be false here, and "use Move there" would be refused.
        assert.doesNotMatch(ordinary.lines[0], /both classes|use Move there/);
        assert.equal(ordinary.openGroup, null);
    }

    // Nobody beside the student: the sentence about guardians is left out.
    assert.doesNotMatch(putBackForm(movedOut({ student_there: 'none', open_group: null, guardians_not_vouched: [] }), []).lines[0], /guardians/);
});

test('one place sends the undo, and one place builds the sentences about a move', () => {
    const tab = source('views/dashboard/groups/GroupRosterTab.vue');
    const dialog = source('views/dashboard/groups/PutBackDialog.vue');
    const modal = source('views/dashboard/groups/MoveStudentModal.vue');
    const store = source('stores/masjid/groupsStore.ts');

    // The roster tab opens the dialog for every row that has left and sends no undo itself.
    assert.doesNotMatch(tab, /ApiService\.delete\(\s*withdrawalUrl/);
    assert.doesNotMatch(tab, /\.putBack\(/);
    assert.match(tab, /const undoWithdrawal = \(membership: GroupMembership\) => \{\s*putBackFor\.value = membership;\s*\};/);
    assert.equal((dialog.match(/groupsStore\.putBack\(/g) ?? []).length, 1);
    assert.doesNotMatch(modal, /putBack\(/);

    // The dialog's re-read is the quiet one: it never empties the page's roster.
    assert.match(dialog, /groupsStore\.readRoster\(/);
    assert.doesNotMatch(dialog, /fetchMemberships/);
    assert.doesNotMatch(store.slice(store.indexOf('async function readRoster'), store.indexOf('// ---------------------------------------------- moving a student')), /\.value = /);

    // The class list is the dialog's own request, never the Classes page's state.
    const classes = store.slice(store.indexOf('async function fetchClassesForMove'), store.indexOf('/** What moving this student there would do'));
    assert.doesNotMatch(classes, /groupsPaginated/);
    assert.match(classes, /kind=class&active_only=1&per_page=100&page=\$\{page\}/);

    // No sentence about what a move does is written in the browser: the lines are the server's.
    // The whole-class dialog and its helper are held to the same rule, comments included.
    const classMove = ['views/dashboard/groups/MoveClassModal.vue', 'core/helpers/rosterClassMove.ts'].map(source);
    for (const file of [modal, source('core/helpers/rosterMove.ts'), ...classMove]) {
        assert.doesNotMatch(file, /is now in |start fresh|shown as moved|in force again|Record consent again/);
        assert.doesNotMatch(file, /carried as it is|Manara Bucks go with|is in force again/);
    }
    assert.match(modal, /v-for="\(line, i\) in preview\.lines"/);

    // THE FORM MAY SHRINK, SO THE BODY SCROLLS AND THE BUTTON STAYS IN REACH. The dialog is
    // `modal-dialog-scrollable` and its body and footer sit inside a <form>. Seen in a browser at
    // 1280 by 900 once a move carried consent: the preview grew past the window, the body did not
    // scroll and the box cut the Move button off. No test without a layout can see that, so the
    // rule is pinned where it is written, as it is for the whole-class dialog.
    assert.match(modal, /class="modal fade show d-block move-student"/);
    assert.match(modal, /modal-dialog modal-dialog-centered modal-dialog-scrollable/);
    assert.match(modal, /<form v-else @submit\.prevent="save">\s+<div class="modal-body">/);
    assert.match(modal, /<style scoped>[\s\S]*\.move-student form \{\s+display: flex;\s+flex-direction: column;\s+flex: 1 1 auto;\s+min-height: 0;\s+\}/);

    // Put back: the server's three fields are printed, never rebuilt. The helper reads them and the
    // dialog draws `decision.lines`; neither writes a sentence about consent or Manara Bucks.
    const helper = source('core/helpers/rosterMove.ts');
    assert.match(helper, /state\.consent_blocks \?\? \[\]/);
    assert.match(helper, /state\.consent_lines \?\? \[\]/);
    assert.match(helper, /state\.bucks_line \? \[state\.bucks_line\] : \[\]/);
    assert.match(dialog, /v-for="\(line, i\) in decision\.lines"/);
    assert.doesNotMatch(dialog, /consent_blocks|consent_lines|bucks_line/);
    assert.doesNotMatch(helper, /Manara Bucks (that|go|went|stay)|withdrew consent|would bring/);

    // The echo of what was shown is the helper's: the dialog hands it the preview as it came.
    assert.match(modal, /moveBody\(\{[\s\S]*?\}, preview\.value\)\)/);

    // The roster: the consent button says its word, the answer to a consent is written back with
    // its mark, and a message that carries the server's notes has no timer on it.
    assert.match(tab, /<i class="bi bi-file-earmark-check me-1" aria-hidden="true"><\/i>Consent\s*<\/button>/);
    assert.match(tab, /const applyConsent = \(membership: GroupMembership, data: any\) => applyConsentAnswer\(membership, data\);/);
    assert.equal((tab.match(/applyConsent\(membership, res\.data\?\.data\);\s*consentFor\.value = null;\s*consentSaved\('Consent (recorded|withdrawn)', res\.data\?\.notes\);/g) ?? []).length, 2);
    const saved = tab.slice(tab.indexOf('const consentSaved = '), tab.indexOf('const saveConsent = '));
    assert.equal((saved.match(/timer:/g) ?? []).length, 1, 'only the message with no notes closes by itself');
    assert.match(saved, /said\.map\(\(note\) => `<p class="text-start mb-2">\$\{escapeHtml\(note\)\}<\/p>`\)\.join\(''\)/);

    // The Remove message is the server's, not a fixed word, and the roster shows that one.
    assert.match(store, /if \(res\.data\?\.status !== 'success'\) return null;/);
    assert.match(store, /message: String\(res\.data\?\.message \?\? 'Removed from the roster\.'\),/);
    assert.match(tab, /const said = answer\?\.message \?\? null;/);
    assert.equal((tab.match(/text: said \?\? undefined/g) ?? []).length, 2);
});
