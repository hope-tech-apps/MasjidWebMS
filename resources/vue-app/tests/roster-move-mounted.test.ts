/**
 * The two dialogs of a move, MOUNTED: each is compiled from its .vue file and driven with a store
 * that answers what the server answers (tests/support/mountSfc.ts says how, with no DOM).
 *
 * A helper test can say `putBackForm` returns `blocked`; only a mounted one can say the screen
 * then sends no undo, and that a Move button the office cannot use really is off.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as ApiErrors from '../core/services/ApiErrors.ts';
import * as rosterMove from '../core/helpers/rosterMove.ts';
import { click, deferred, flush, httpError, mountSfc, Node, press, submit, type } from './support/mountSfc.ts';

// BOTH DIALOGS LISTEN FOR KEYS ON THE DOCUMENT and hand focus back to whatever opened them.
// mountSfc's document has no events and no tree to ask "is this still on the page", so this file
// gives it both: the keydown listeners a dialog adds are kept here and a test presses a key by
// calling them; `onPage` is what `document.contains` answers from.
(globalThis as any).HTMLElement ??= Node;
const documentKeydown = new Set<(e: any) => void>();
const onPage = new Set<any>();
const doc = (globalThis as any).document;
doc.addEventListener = (name: string, fn: (e: any) => void) => { if (name === 'keydown') documentKeydown.add(fn); };
doc.removeEventListener = (name: string, fn: (e: any) => void) => { if (name === 'keydown') documentKeydown.delete(fn); };
doc.contains = (node: any) => onPage.has(node);
const pressKey = (key: string) => [...documentKeydown].forEach((fn) => fn({ key, shiftKey: false, preventDefault() {} }));

/** What has focus, by what it is (the harness hands a ref a wrapped node, so not by identity). */
const focused = (): string => {
    const el = doc.activeElement;
    if (!el) return 'nothing';

    return el.props?.id ? `#${el.props.id}` : el.props?.['aria-label'] ? `[${el.props['aria-label']}]` : `${el.tag} ${el.textContent.trim()}`;
};

/** A button on the page behind the dialog, holding focus: what the office pressed to open it. */
const openerButton = () => {
    const opener = new Node('el', 'button');
    onPage.add(opener);
    opener.focus();

    return opener;
};

const child = { id: 10, role: 'member', contact_id: 100, guardian_of_contact_id: null, left_on: null, grade_label: '1st',
    provenance: 'confirmed', contact: { first_name: 'Maryam', last_name: 'Student' } };
const classes = [
    { id: 1, name: '1st Grade', kind: 'class', is_active: true, ends_on: null, position: 1 },
    { id: 2, name: '2nd Grade', kind: 'class', is_active: true, ends_on: null, position: 2 },
];
// `expected_consent` is what the server decided about consent, as a string of counts; the rule for
// Manara Bucks is null until a move can carry a balance.
const canMove = { can_move: true, refusal: null, open_group: null, path: 'left_and_started', first_day_in_new_class: '2026-10-04',
    joined_on: '2026-10-04', grade_label: '1st', expected_consent: 'm1f0s0n0e0', expected_bucks_rule: null,
    lines: ['Maryam Student has nothing recorded in 1st Grade.', 'They start fresh in 2nd Grade.'] };
const refused = { can_move: false, refusal: 'Gamal Guardian is a confirmed guardian of Maryam Student in 2nd Grade but not a confirmed guardian here.\nNothing was moved. Then move Maryam Student again.',
    open_group: { id: 2, name: '2nd Grade' }, path: null, grade_label: '1st', lines: [] };

/** A store that records what the dialog asks of it. Each test decides the answers. */
function fakeStore(over: Record<string, any> = {}) {
    const calls: Array<{ what: string; args: any[] }> = [];
    const record = (what: string, answer: (...args: any[]) => any) => (...args: any[]) => { calls.push({ what, args }); return answer(...args); };
    const store: any = {
        lastMoveChoice: { toGroupId: 2, movedOn: '2026-10-04' },
        fetchClassesForMove: record('classes', over.classes ?? (async () => classes)),
        previewMove: record('preview', over.preview ?? (async () => canMove)),
        moveMembership: record('move', over.move ?? (async () => ['Maryam Student is now in 2nd Grade.'])),
        readRoster: record('readRoster', over.readRoster ?? (async () => ({ rows: [], meta: {} }))),
        putBack: record('putBack', over.putBack ?? (async () => undefined)),
        fetchMemberships: record('fetchMemberships', async () => undefined),
    };

    return { store, calls, count: (what: string) => calls.filter((c) => c.what === what).length };
}

const modules = (store: any) => ({
    '@/stores/masjid/groupsStore': { useGroupsStore: () => store },
    '@/core/services/ApiErrors': ApiErrors,
    '@/core/helpers/focusTrap': { trapTab: () => {} },
    '@/core/helpers/rosterMove': rosterMove,
});

async function mountMove(store: any, events: Record<string, any> = {}) {
    const screen = await mountSfc('views/dashboard/groups/MoveStudentModal.vue',
        { groupId: 1, groupName: '1st Grade', membership: child, schoolToday: '2026-10-04', ...events }, modules(store));
    await flush();

    return screen;
}

// ------------------------------------------------------------------ the Move dialog

test('move: the button is off while the check runs, and on once the server says the move can happen', async () => {
    const answer = deferred();
    const { store, count } = fakeStore({ preview: () => answer.promise });
    const screen = await mountMove(store);

    // The class chosen for the last student is chosen again, and the check starts by itself.
    assert.equal(count('preview'), 1);
    assert.match(screen.text(), /Checking…/);
    assert.equal(screen.button('Move to 2nd Grade').disabled, true);

    // A tap that lands anyway sends nothing.
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    assert.equal(count('move'), 0);

    answer.resolve(canMove);
    await flush();
    assert.match(screen.text(), /They start fresh in 2nd Grade\./);
    assert.equal(screen.button('Move to 2nd Grade').disabled, false);
    screen.unmount();
});

test('move: a refusal is drawn where the office is reading, with the class to open, and the button stays off', async () => {
    const opened: number[] = [];
    const { store, count } = fakeStore({ preview: async () => refused });
    const screen = await mountMove(store, { 'onOpen-class': (id: number) => opened.push(id) });

    assert.match(screen.text(), /Gamal Guardian is a confirmed guardian of Maryam Student in 2nd Grade but not a confirmed guardian here\./);
    assert.match(screen.text(), /Nothing was moved\./);
    assert.equal(screen.button('Move to 2nd Grade').disabled, true);

    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    assert.equal(count('move'), 0, 'a refused move was sent');

    click(screen.button('Open 2nd Grade'));
    assert.deepEqual(opened, [2]);
    screen.unmount();
});

test('move: from the tap to the answer the buttons are off, a second tap sends nothing, and the body is what was shown', async () => {
    const answer = deferred<string[]>();
    let moved = 0;
    const { store, calls, count } = fakeStore({ move: () => answer.promise });
    const screen = await mountMove(store, { onMoved: () => { moved += 1; } });
    const form = screen.all((n) => n.tag === 'form')[0];

    type(screen.all((n) => n.tag === 'input' && n.props.id === 'move-grade')[0], '2nd');
    submit(form);
    await flush();

    assert.equal(screen.button('Move to 2nd Grade').disabled, true);
    assert.equal(screen.button('Cancel').disabled, true);

    // The double tap: the handler runs again although the button is off.
    submit(form);
    press(screen.button('Move to 2nd Grade'));
    await flush();
    assert.equal(count('move'), 1, 'a double tap sent two moves');

    const [groupId, membershipId, body] = calls.find((c) => c.what === 'move')!.args;
    assert.deepEqual([groupId, membershipId], [1, 10]);
    // What the dialog showed about consent goes back with the tap; a rule the preview did not give is not sent.
    assert.deepEqual(body, {
        to_group_id: '2', moved_on: '2026-10-04', grade_label: '2nd',
        expected_path: 'left_and_started', expected_first_day: '2026-10-04', expected_consent: 'm1f0s0n0e0',
    });

    // The answer is the server's lines, and it stays until OK.
    answer.resolve(['Maryam Student is now in 2nd Grade.', 'Consent was carried as it is for 1 guardian, for the class story and photographs.']);
    await flush();
    assert.match(screen.text(), /Maryam Student is now in 2nd Grade\./);
    assert.match(screen.text(), /Consent was carried as it is for 1 guardian, for the class story and photographs\./);
    assert.equal(moved, 0, 'the dialog closed itself before the office read what happened');

    click(screen.button('OK'));
    assert.equal(moved, 1);
    assert.deepEqual(store.lastMoveChoice, { toGroupId: 2, movedOn: '2026-10-04' });
    screen.unmount();
});

test('move: the rule for Manara Bucks the preview showed is echoed with the tap, beside what it showed about consent', async () => {
    const { store, calls } = fakeStore({ preview: async () => ({ ...canMove, expected_consent: 'm0f1s0n0e0', expected_bucks_rule: 'from_ended' }) });
    const screen = await mountMove(store);

    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();

    const body = calls.find((c) => c.what === 'move')!.args[2];
    assert.equal(body.expected_consent, 'm0f1s0n0e0');
    assert.equal(body.expected_bucks_rule, 'from_ended');
    Object.values(body).forEach((v) => assert.equal(typeof v, 'string'));
    screen.unmount();
});

test('move: a refusal over a consent opens the other class on the row its remedy is about, before the tap and after it', async () => {
    const consent = 'Huda Guardian withdrew consent in 1st Grade after it had been carried there from 2nd Grade. The consent recorded in 2nd Grade '
        + '(the class story and photographs, recorded 5 Sep 2026) would come back into force.\nNothing was moved. Open 2nd Grade and withdraw '
        + 'that consent on its roster first (the Consent button on the guardian\'s row), then move Maryam Student again. If the family still '
        + 'agrees for 2nd Grade, record it there after the move.';
    const opened: any[][] = [];
    const onOpen = { 'onOpen-class': (...args: any[]) => opened.push(args) };

    // Before the tap: the preview's own refusal, printed as it came, with the row.
    const before = fakeStore({ preview: async () => ({ ...refused, refusal: consent, open_group: { id: 2, name: '2nd Grade', membership_id: 77 } }) });
    const first = await mountMove(before.store, onOpen);
    assert.match(first.text(), /Huda Guardian withdrew consent in 1st Grade after it had been carried there from 2nd Grade\./);
    assert.match(first.text(), /\(the Consent button on the guardian's row\), then move Maryam Student again\./);
    assert.equal(first.button('Move to 2nd Grade').disabled, true);
    click(first.button('Open 2nd Grade'));
    assert.deepEqual(opened, [[2, 77]]);
    first.unmount();

    // After it: the consent was withdrawn between the read and the tap, and the save says so.
    const after = fakeStore({
        move: () => Promise.reject(httpError(409, { status: 'error', message: consent, open_group: { id: 2, name: '2nd Grade', membership_id: 78 } })),
    });
    const second = await mountMove(after.store, onOpen);
    submit(second.all((n) => n.tag === 'form')[0]);
    await flush();
    assert.match(second.text(), /would come back into force\./);
    click(second.button('Open 2nd Grade'));
    assert.deepEqual(opened.at(-1), [2, 78]);
    second.unmount();

    // A refusal that names no row (a guardian to confirm, as before): the class alone.
    const plain = fakeStore({ preview: async () => refused });
    const third = await mountMove(plain.store, onOpen);
    click(third.button('Open 2nd Grade'));
    assert.deepEqual(opened.at(-1), [2, null]);
    third.unmount();
});

test('move: while the server is being updated its one sentence is shown, and nothing can be sent', async () => {
    const updating = 'Manara is being updated. Try this move again in a minute.';
    const { store, count } = fakeStore({ preview: async () => ({ ...refused, refusal: updating, open_group: null }) });
    const screen = await mountMove(store);

    assert.match(screen.text(), /Manara is being updated\. Try this move again in a minute\./);
    assert.equal(screen.button('Move to 2nd Grade').disabled, true);
    assert.equal(screen.all((n) => n.tag === 'button' && n.textContent.startsWith('Open ')).length, 0);
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    assert.equal(count('move'), 0);
    screen.unmount();
});

test('move: a 409 from the save is shown in the dialog, which stays open and checks again by itself', async () => {
    let closed = 0;
    const { store, count } = fakeStore({
        move: () => Promise.reject(httpError(409, { status: 'error', message: 'This changed while you were looking. Nothing was moved. Read what will happen below, then move again.' })),
    });
    const screen = await mountMove(store, { onClose: () => { closed += 1; }, onMoved: () => { closed += 1; } });

    assert.equal(count('preview'), 1);
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();

    assert.match(screen.text(), /This changed while you were looking\. Nothing was moved\./);
    assert.equal(count('preview'), 2, 'the check did not run again after the refusal');
    assert.equal(closed, 0, 'the dialog closed on a refusal');
    // THE REFUSAL IS THE FIRST THING IN THE BODY, above the choices and the lines, and it has
    // the keyboard: the body scrolls now, and under a long "What will happen" a refusal drawn
    // last could be below the fold.
    const alert = screen.all((n) => n.props['data-part'] === 'refused')[0];
    assert.equal(alert.parent!.children.filter((c) => c.kind === 'el')[0], alert, 'the refusal is not the first thing in the body');
    assert.equal(String(alert.parent!.props.class).includes('modal-body'), true);
    assert.equal(doc.activeElement.props['data-part'], 'refused', 'the refusal did not take the keyboard');
    // What the server would do now is on screen, and the office can try again.
    assert.match(screen.text(), /They start fresh in 2nd Grade\./);
    assert.equal(screen.button('Move to 2nd Grade').disabled, false);
    screen.unmount();
});

test('move: the class list says when it is loading, when it failed and when there is nothing to choose', async () => {
    const list = deferred<any[]>();
    const loading = fakeStore({ classes: () => list.promise });
    const first = await mountMove(loading.store);
    assert.match(first.text(), /Loading classes…/);
    list.reject(new Error('offline'));
    await flush();
    assert.match(first.text(), /Could not load the classes\./);
    assert.equal(loading.count('classes'), 1);
    click(first.button('Try again'));
    await flush();
    assert.equal(loading.count('classes'), 2);
    first.unmount();

    const none = fakeStore({ classes: async () => [classes[0]] });
    const second = await mountMove(none.store);
    assert.match(second.text(), /This school has no other class that is running\. Add the class first, on the Classes page\./);
    assert.equal(none.count('preview'), 0);
    assert.equal(second.all((n) => n.tag === 'button' && n.textContent.trim() === 'Move')[0].disabled, true);
    second.unmount();
});

test('move: a check that cannot be made says which kind, and never turns the button on', async () => {
    let reloads = 0;
    const gone = fakeStore({ preview: () => Promise.reject(httpError(404, {})) });
    const first = await mountMove(gone.store, { onReload: () => { reloads += 1; } });
    assert.match(first.text(), /This roster has changed\. Reload it\./);
    assert.equal(first.button('Move to 2nd Grade').disabled, true);
    click(first.button('Reload the roster'));
    assert.equal(reloads, 1);
    first.unmount();

    const forbidden = fakeStore({ preview: () => Promise.reject(httpError(403, {})) });
    const second = await mountMove(forbidden.store);
    assert.match(second.text(), /You do not have permission to move students\./);
    second.unmount();

    const offline = fakeStore({ preview: () => Promise.reject(new Error('Network Error')) });
    const third = await mountMove(offline.store);
    assert.match(third.text(), /Could not check this move\. Try again\./);
    click(third.button('Try again'));
    await flush();
    assert.equal(offline.count('preview'), 2);
    assert.equal(third.button('Move to 2nd Grade').disabled, true);
    third.unmount();
});

test('move: the keyboard comes into the dialog, Escape closes it from wherever focus is, and the opener gets focus back', async () => {
    const opener = openerButton();
    let closed = 0;
    const { store } = fakeStore();
    const screen = await mountMove(store, { onClose: () => { closed += 1; } });

    // Focus was on a row button behind the backdrop; it is on the class picker now.
    assert.equal(focused(), '#move-to-class');

    // Escape is heard on the document: it works although no key ever reached the dialog's own element.
    assert.equal(documentKeydown.size, 1);
    pressKey('Escape');
    assert.equal(closed, 1);

    screen.unmount();
    assert.equal(documentKeydown.size, 0, 'the dialog left its key listener on the document');
    assert.equal(doc.activeElement, opener, 'focus did not go back to the button that opened the dialog');
    onPage.clear();
});

test('move: with no class picker to focus (the list failed, or there is no other class) focus is still inside, on Close', async () => {
    for (const classesAnswer of [() => Promise.reject(new Error('offline')), async () => [classes[0]]]) {
        openerButton();
        let closed = 0;
        const { store } = fakeStore({ classes: classesAnswer });
        const screen = await mountMove(store, { onClose: () => { closed += 1; } });

        assert.equal(focused(), '[Close]');
        pressKey('Escape');
        assert.equal(closed, 1);
        screen.unmount();
        onPage.clear();
    }
});

test('move: Escape does nothing while the move saves; afterwards OK has the focus and Escape is OK', async () => {
    const answer = deferred<string[]>();
    let closed = 0;
    let moved = 0;
    const { store } = fakeStore({ move: () => answer.promise });
    const screen = await mountMove(store, { onClose: () => { closed += 1; }, onMoved: () => { moved += 1; } });

    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    pressKey('Escape');
    assert.deepEqual([closed, moved], [0, 0], 'Escape left the dialog while the move was being saved');

    // The form, and the Move button that had focus, are replaced by the result.
    answer.resolve(['Maryam Student is now in 2nd Grade.']);
    await flush();
    assert.equal(focused(), 'button OK');

    pressKey('Escape');
    assert.deepEqual([closed, moved], [0, 1]);
    screen.unmount();
});

test('move: with the first day cleared the box says a day is needed, and nothing is asked of the server', async () => {
    const { store, count } = fakeStore();
    const screen = await mountMove(store);
    assert.equal(count('preview'), 1);

    // The date picker's Clear, or Backspace: the field is empty.
    type(screen.all((n) => n.tag === 'input' && n.props.id === 'move-first-day')[0], '');
    await flush();

    assert.match(screen.text(), /What will happen Choose the first day in the new class\./);
    assert.doesNotMatch(screen.text(), /They start fresh in 2nd Grade\./);
    assert.equal(screen.button('Move to 2nd Grade').disabled, true);
    assert.equal(count('preview'), 1);
    screen.unmount();
});

test('move: a refusal from the save is taken down when the office makes another choice', async () => {
    const { store, count } = fakeStore({
        move: () => Promise.reject(httpError(409, { status: 'error',
            message: 'Maryam Student is already in 2nd Grade. Nothing was moved.', open_group: { id: 2, name: '2nd Grade' } })),
    });
    const screen = await mountMove(store);

    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    assert.match(screen.text(), /Maryam Student is already in 2nd Grade\. Nothing was moved\./);
    assert.ok(screen.button('Open 2nd Grade'));

    // Another day (the same watcher hears another class): the lines below are for the new choice,
    // and the refusal about the old one is gone, with its button.
    type(screen.all((n) => n.tag === 'input' && n.props.id === 'move-first-day')[0], '2026-10-03');
    await flush();

    assert.equal(count('preview'), 3);
    assert.match(screen.text(), /They start fresh in 2nd Grade\./);
    assert.doesNotMatch(screen.text(), /already in 2nd Grade/);
    assert.equal(screen.all((n) => n.tag === 'button' && n.textContent.includes('Open 2nd Grade')).length, 0);
    assert.equal(screen.button('Move to 2nd Grade').disabled, false);
    screen.unmount();
});

// ------------------------------------------------------------------ Put back

const gamal = { id: 21, role: 'guardian', contact_id: 201, guardian_of_contact_id: 100, left_on: '2026-10-03', provenance: 'confirmed',
    contact: { first_name: 'Gamal', last_name: 'Guardian' } };
const movedRow = (notVouched: any[], there = 'current', consent: Record<string, any> = {}) => ({
    ...child, left_on: '2026-10-03', moved_to_group_id: 2, moved_on: '2026-10-04', moved_to: { id: 2, name: '2nd Grade', deleted_at: null },
    moved_to_state: { student_there: there, open_group: { id: 2, name: '2nd Grade' }, guardians_not_vouched: notVouched,
        consent_blocks: [], consent_lines: [], bucks_line: null, ...consent },
});
const blockedBy = [{ membership_id: 21, reason: 'no_entry',
    sentence: 'Putting Maryam Student back would also give Gamal Guardian access to this class again. Gamal Guardian is not a confirmed guardian of Maryam Student in 2nd Grade.' }];

async function mountPutBack(store: any, membership: any, events: Record<string, any> = {}) {
    const screen = await mountSfc('views/dashboard/groups/PutBackDialog.vue', { groupId: 1, membership, ...events }, modules(store));
    await flush();

    return screen;
}

const putBackButtons = (screen: any) => screen.all((n: any) => n.tag === 'button' && /put (them )?back/i.test(n.textContent));

test('put back: blocked. The guardian is named, there is nothing to tap that puts the student back, and no undo is sent', async () => {
    const opened: number[] = [];
    // The page drew the row before the guardian was removed in the other class: the dialog reads again.
    const { store, count } = fakeStore({ readRoster: async () => ({ rows: [movedRow(blockedBy), gamal], meta: {} }) });
    const screen = await mountPutBack(store, movedRow([]), { 'onOpen-class': (id: number) => opened.push(id) });

    assert.equal(count('readRoster'), 1);
    assert.equal(count('fetchMemberships'), 0, 'the re-read emptied the page\'s roster');
    assert.match(screen.text(), /Not yet: check the guardians first/);
    assert.match(screen.text(), /would also give Gamal Guardian access to this class again/);
    assert.match(screen.text(), /Remove that entry on this roster first\./);
    assert.deepEqual(putBackButtons(screen), [], 'the blocked form offers a put-back button');

    // Every button the form does have is pressed, off or not: none of them sends the undo.
    screen.all((n) => n.tag === 'button').forEach((b) => press(b));
    await flush();
    assert.equal(count('putBack'), 0, 'the blocked form sent the undo');
    assert.deepEqual(opened, [2]);
    screen.unmount();
});

// The server's sentences for a consent that "Put back" would bring back (RosterMove::movedToStates):
// the family withdrew the copy where it had been carried, or withdrew or narrowed the source.
const putBackRemedy = 'Withdraw it on this roster first (the Consent button on the guardian\'s row), then put Maryam Student back.';
const consentBlockKinds: Record<string, string> = {
    'the copy was withdrawn': 'Gamal Guardian withdrew consent in 2nd Grade after it had been carried there from this class. Putting Maryam Student '
        + 'back would bring the consent recorded here (the class story and photographs, recorded 5 Sep 2026) into force again.',
    'the source was withdrawn': 'Gamal Guardian\'s consent here (the class story and photographs, recorded 5 Sep 2026) was carried from 2nd Grade, '
        + 'and consent in 2nd Grade has since been withdrawn. Putting Maryam Student back would bring it into force again.',
    'the source was narrowed': 'Gamal Guardian\'s consent here (the class story and photographs, recorded 5 Sep 2026) was carried from 2nd Grade, '
        + 'and consent in 2nd Grade is now for the class story only. Putting Maryam Student back would bring it into force again.',
};
const bucksLine = 'Any Manara Bucks that moved with Maryam Student to 2nd Grade stay there. Putting Maryam Student back here does not bring them '
    + 'back. Bucks Maryam Student earns here from now on are kept here. To bring Maryam Student and their Bucks back together, open 2nd Grade and use Move.';
const inForceAgain = 'Putting Maryam Student back brings Gamal Guardian\'s consent in this class into force again (the class story, recorded 5 Sep 2026).';

for (const [kind, sentence] of Object.entries(consentBlockKinds)) {
    test(`put back: blocked by a consent that would come back (${kind}). No button puts the student back, and no undo is sent`, async () => {
        const opened: number[] = [];
        // The page drew the row before the family withdrew: the dialog reads again and is told.
        const { store, count } = fakeStore({ readRoster: async () => ({
            rows: [movedRow([], 'current', { consent_blocks: [sentence, putBackRemedy], bucks_line: bucksLine }), gamal], meta: {},
        }) });
        const screen = await mountPutBack(store, movedRow([]), { 'onOpen-class': (id: number) => opened.push(id) });

        assert.match(screen.text(), /Not yet: check consent first/);
        assert.ok(screen.text().includes(sentence), 'the server\'s sentence is printed whole');
        assert.ok(screen.text().includes(putBackRemedy));
        // The rule for Manara Bucks is said in this form too.
        assert.ok(screen.text().includes(bucksLine));
        assert.deepEqual(putBackButtons(screen).map((b: any) => b.textContent), [], 'the blocked form offers a put-back button');
        // The remedy is on this roster: nothing sends the office to the other class.
        assert.equal(screen.all((n) => n.tag === 'button' && n.textContent.startsWith('Open ')).length, 0);

        // The sentence that says why not is the red one and carries the sign; what to do, and the Bucks rule, are plain.
        const paragraphs = screen.all((n) => n.tag === 'p');
        assert.deepEqual(paragraphs.map((p) => String(p.props.class ?? '').includes('text-danger')), [true, false, false]);
        assert.deepEqual(paragraphs.map((p) => p.children.some((c) => c.tag === 'i')), [true, false, false]);

        // Every button the form does have is pressed, off or not: none of them sends the undo.
        screen.all((n) => n.tag === 'button').forEach((b) => press(b));
        await flush();
        assert.equal(count('putBack'), 0, 'the blocked form sent the undo');
        assert.deepEqual(opened, []);
        screen.unmount();
    });
}

test('put back: the consent that comes back and the rule for Manara Bucks are printed above the button, and the button still works', async () => {
    let done = 0;
    const { store, count } = fakeStore({ readRoster: async () => ({
        rows: [movedRow([], 'current', { consent_lines: [inForceAgain], bucks_line: bucksLine }), gamal], meta: {},
    }) });
    const screen = await mountPutBack(store, movedRow([]), { onDone: () => { done += 1; } });

    assert.match(screen.text(), /Put them back in this class too\?/);
    const said = screen.all((n) => n.tag === 'p').map((p) => p.textContent);
    assert.equal(said.length, 3);
    assert.match(said[0], /leaves them in both classes, on two registers\./);
    assert.deepEqual(said.slice(1), [inForceAgain, bucksLine]);
    // Nothing here is a reason not to: no line is drawn as one.
    assert.equal(screen.all((n) => n.tag === 'p' && String(n.props.class ?? '').includes('text-danger')).length, 0);

    click(screen.button('Put back here anyway'));
    await flush();
    assert.deepEqual([count('putBack'), done], [1, 1]);
    screen.unmount();

    // A student who is no longer in the other class: the ordinary form, with the same lines.
    const gone = fakeStore({ readRoster: async () => ({
        rows: [movedRow([], 'left', { open_group: null, consent_lines: [inForceAgain] }), gamal], meta: {},
    }) });
    const ordinary = await mountPutBack(gone.store, movedRow([]));
    assert.ok(ordinary.text().includes(inForceAgain));
    assert.ok(ordinary.button('Yes, put them back'));
    ordinary.unmount();
});

test('put back: once nobody is blocked the two-classes form is drawn, and only its own button sends the undo, once', async () => {
    const answer = deferred();
    let done = 0;
    const { store, count } = fakeStore({
        readRoster: async () => ({ rows: [movedRow([]), gamal], meta: {} }),
        putBack: () => answer.promise,
    });
    const screen = await mountPutBack(store, movedRow(blockedBy), { onDone: () => { done += 1; } });

    assert.match(screen.text(), /Put them back in this class too\?/);
    assert.match(screen.text(), /leaves them in both classes, on two registers\./);
    assert.match(screen.text(), /These guardians come back into this class with them: Gamal Guardian\./);

    click(screen.button('Cancel'));
    click(screen.button('Open 2nd Grade'));
    await flush();
    assert.equal(count('putBack'), 0);

    const anyway = screen.button('Put back here anyway');
    click(anyway);
    press(anyway);
    await flush();
    assert.equal(count('putBack'), 1, 'a double tap sent two undos');
    assert.equal(anyway.disabled, true);

    answer.resolve(undefined);
    await flush();
    assert.equal(done, 1);
    screen.unmount();
});

test('put back: a check that failed, or a row that is gone, offers no way to put the student back', async () => {
    const failed = fakeStore({ readRoster: () => Promise.reject(new Error('Network Error')) });
    const first = await mountPutBack(failed.store, movedRow([]));
    assert.match(first.text(), /Could not check this\. Try again\./);
    assert.deepEqual(putBackButtons(first), []);
    click(first.button('Try again'));
    await flush();
    assert.equal(failed.count('readRoster'), 2);
    first.unmount();

    const gone = fakeStore({ readRoster: async () => ({ rows: [gamal], meta: {} }) });
    const second = await mountPutBack(gone.store, movedRow([]));
    assert.match(second.text(), /This roster has changed\. Reload it\./);
    assert.deepEqual(putBackButtons(second), []);
    second.all((n) => n.tag === 'button').forEach((b) => press(b));
    await flush();
    assert.equal(gone.count('putBack'), 0);
    second.unmount();
});

test('put back: a row that simply left keeps the confirmation it always had', async () => {
    const left = { ...child, left_on: '2026-09-20' };
    const { store, count } = fakeStore({ readRoster: async () => ({ rows: [left], meta: {} }) });
    const screen = await mountPutBack(store, left);

    assert.match(screen.text(), /Put them back on the roster\?/);
    assert.match(screen.text(), /will be back on the register and every class list/);
    click(screen.button('Yes, put them back'));
    await flush();
    assert.equal(count('putBack'), 1);
    screen.unmount();
});

test('put back: a row that simply left is not offered "Put back" over a carried consent withdrawn since, and otherwise says what comes back', async () => {
    const left = { ...child, left_on: '2026-09-20' };
    const state = (over: Record<string, any>) => ({ student_there: 'none', open_group: null, guardians_not_vouched: [],
        consent_blocks: [], consent_lines: [], bucks_line: null, ...over });
    const block = consentBlockKinds['the source was withdrawn'];

    // The page drew the row before the family withdrew in the other class: the dialog reads
    // again and is told. No "moved to" on this row: the student left the class, and was not moved.
    const blocked = fakeStore({ readRoster: async () => ({
        rows: [{ ...left, moved_to_state: state({ consent_blocks: [block, putBackRemedy] }) }, gamal], meta: {},
    }) });
    const first = await mountPutBack(blocked.store, left);
    assert.match(first.text(), /Not yet: check consent first/);
    assert.ok(first.text().includes(block), 'the server\'s sentence is printed whole');
    assert.ok(first.text().includes(putBackRemedy));
    assert.deepEqual(putBackButtons(first).map((b: any) => b.textContent), [], 'the blocked form offers a put-back button');
    first.all((n) => n.tag === 'button').forEach((b) => press(b));
    await flush();
    assert.equal(blocked.count('putBack'), 0, 'the blocked form sent the undo');
    first.unmount();

    // Nothing withdrawn: the consent that comes back is named above the button, which works.
    const named = fakeStore({ readRoster: async () => ({
        rows: [{ ...left, moved_to_state: state({ consent_lines: [inForceAgain] }) }, gamal], meta: {},
    }) });
    const second = await mountPutBack(named.store, left);
    assert.match(second.text(), /Put them back on the roster\?/);
    assert.ok(second.text().includes(inForceAgain));
    click(second.button('Yes, put them back'));
    await flush();
    assert.equal(named.count('putBack'), 1);
    second.unmount();
});

test('put back: the keyboard comes into the dialog, Escape is Cancel and waits for a save, and the opener gets focus back', async () => {
    const opener = openerButton();
    const answer = deferred();
    let closed = 0;
    const left = { ...child, left_on: '2026-09-20' };
    const { store, count } = fakeStore({ readRoster: async () => ({ rows: [left], meta: {} }), putBack: () => answer.promise });
    const screen = await mountPutBack(store, left, { onClose: () => { closed += 1; } });

    // The green button in the row had focus, behind the backdrop. Now Close has it.
    assert.equal(focused(), '[Close]');

    click(screen.button('Yes, put them back'));
    await flush();
    pressKey('Escape');
    assert.equal(closed, 0, 'Escape closed the dialog while the undo was being saved');

    answer.reject(httpError(500, {}));
    await flush();
    pressKey('Escape');
    assert.equal(closed, 1);
    assert.equal(count('putBack'), 1);

    screen.unmount();
    assert.equal(documentKeydown.size, 0, 'the dialog left its key listener on the document');
    assert.equal(doc.activeElement, opener);
    onPage.clear();
});
