/**
 * The whole-class dialog, MOUNTED: compiled from its .vue file and driven with a store that
 * answers what the server answers (tests/support/mountSfc.ts says how, with no DOM).
 *
 * A helper test can say which rows `ticksAfterCheck` keeps; only a mounted one can say the screen
 * then sends exactly those rows, once, and that a Move button the office cannot use really is off
 * and says why. That the BUTTON which opens this dialog is drawn only on a class with a current
 * student belongs to the test of the roster tab that mounts it.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as rosterMove from '../core/helpers/rosterMove.ts';
import * as rosterClassMove from '../core/helpers/rosterClassMove.ts';
import {
    blur, check, choose, click, deferred, flush, httpError, mountSfc, Node, pressKey, press, select, submit, type, withDocumentKeys,
} from './support/mountSfc.ts';

const { listeners: documentKeydown, onPage } = withDocumentKeys();
const doc = (globalThis as any).document;

/** What has focus, by what it is (the harness hands a ref a wrapped node, so not by identity). */
const focused = (): string => {
    const el = doc.activeElement;
    if (!el) return 'nothing';

    return el.props?.id ? `#${el.props.id}` : el.props?.['aria-label'] ? `[${el.props['aria-label']}]` : `${el.tag} ${el.textContent.trim()}`;
};

/** "Move the class" on the roster behind the dialog, holding focus. */
const openerButton = () => {
    const opener = new Node('el', 'button');
    onPage.add(opener);
    opener.focus();

    return opener;
};

const classes = [
    { id: 1, name: '1st Grade', kind: 'class', is_active: true, ends_on: null, position: 1 },
    { id: 2, name: '2nd Grade', kind: 'class', is_active: true, ends_on: null, position: 2 },
    { id: 3, name: '3rd Grade', kind: 'class', is_active: true, ends_on: null, position: 3 },
];

const student = (id: number, name: string, over: Record<string, any> = {}): any => ({
    membership_id: id, name: `${name} Student`, grade_label: '1st', grade_after: '1st', grade_note: null, can_move: true,
    refusal: null, open_group: null, path: 'left_and_started', first_day_in_new_class: '2026-10-04', joined_on: '2026-10-04',
    expected_consent: 'm0f0s0n0e0', came_from_target: false,
    summary: 'Moves to 2nd Grade from 4 Oct 2026, with no guardian on this roster.',
    lines: [`${name} Student has nothing recorded in 1st Grade.`], ...over,
});
const stuck = (id: number, name: string, over: Record<string, any> = {}): any => student(id, name, {
    can_move: false, path: null, first_day_in_new_class: null, joined_on: null, expected_consent: null, lines: [],
    refusal: `${name} Student is already in 2nd Grade. Nothing was moved.`, summary: `${name} Student is already in 2nd Grade.`, ...over,
});

const previewOf = (students: any[], over: Record<string, any> = {}): any => {
    const able = students.filter((s) => s.can_move).length;

    return {
        can_move: true, refusal: null, open_group: null,
        from_group: { id: 1, name: '1st Grade' }, to_group: { id: 2, name: '2nd Grade' },
        moved_on: '2026-10-04', school_today: '2026-10-04', expected_bucks_rule: null,
        lines: {
            who: [`${able} of ${students.length} students can move to 2nd Grade from 4 Oct 2026.`],
            consent: ['No guardian is on 1st Grade\'s roster for these students.'],
            records: ['Everything recorded for each student stays with 1st Grade.'],
            bucks: [],
            afterwards: ['2nd Grade has no teacher yet.', '1st Grade keeps its teachers.'],
        },
        students,
        not_listed: { left_or_moved: 0, leaders: 0 },
        counts: { students: students.length, can_move: able, cannot_move: students.length - able, report_cards_not_started: 0 },
        limits: { max_students: 60 },
        ...over,
    };
};

const answerOf = (students: any[], over: Record<string, any> = {}): any => ({
    run: '01JRUNOFTHISCLASS000000000', from_group: { id: 1, name: '1st Grade' }, to_group: { id: 2, name: '2nd Grade' },
    moved_on: '2026-10-04',
    moved: students.filter((s) => s.outcome === 'moved').length,
    not_moved: students.filter((s) => s.outcome === 'not_moved').length,
    not_reached: students.filter((s) => s.outcome === 'not_reached').length,
    stopped_by_fault: false, siblings_left_behind: 0, students, counts: {},
    lines: {
        done: ['The server said how many students are in 2nd Grade.'],
        consent: ['The server said what happened to consent.'],
        bucks: [],
        afterwards: ['2nd Grade has no teacher yet.', '1st Grade keeps its teachers.'],
    },
    ...over,
});
const movedRow = (s: any) => ({ membership_id: s.membership_id, name: s.name, outcome: 'moved', new_membership_id: s.membership_id + 100, lines: [`${s.name} arrived.`] });

const three = () => [student(11, 'Maryam'), student(12, 'Yusuf'), student(13, 'Layla')];
const rosterRows = (n = 3) => Array.from({ length: n }, (_, i) => ({ id: 11 + i, role: 'member', left_on: null, moved_from_group_id: null, moved_from: null }));

/** A store that records what the dialog asks of it. Each test decides the answers. */
function fakeStore(over: Record<string, any> = {}) {
    const calls: Array<{ what: string; args: any[] }> = [];
    const record = (what: string, answer: (...args: any[]) => any) => (...args: any[]) => { calls.push({ what, args }); return answer(...args); };
    const store: any = {
        fetchClassesForMove: record('classes', over.classes ?? (async () => classes)),
        previewClassMove: record('preview', over.preview ?? (async () => previewOf(three()))),
        moveClass: record('move', over.move ?? (async (_group: number, _fields: any) => answerOf(three().map(movedRow)))),
    };

    return {
        store, calls,
        count: (what: string) => calls.filter((c) => c.what === what).length,
        last: (what: string) => calls.filter((c) => c.what === what).at(-1)!.args,
    };
}

const modules = (store: any) => ({
    '@/stores/masjid/groupsStore': { useGroupsStore: () => store },
    '@/core/helpers/focusTrap': { trapTab: () => {} },
    '@/core/helpers/rosterMove': rosterMove,
    '@/core/helpers/rosterClassMove': rosterClassMove,
});

async function mountDialog(store: any, events: Record<string, any> = {}, roster: any[] = rosterRows()) {
    const screen = await mountSfc('views/dashboard/groups/MoveClassModal.vue',
        { groupId: 1, groupName: '1st Grade', roster, schoolToday: '2026-10-04', ...events }, modules(store));
    await flush();

    return screen;
}

const part = (screen: any, name: string) => screen.all((n: Node) => n.props['data-part'] === name);
/** The list items under a list, as text (a v-for leaves empty anchors beside them). */
const items = (list: Node): string[] => list.children.filter((c) => c.kind === 'el').map((c) => c.textContent);
/** Is this disclosure open? (The harness drops an attribute bound to `false`; a browser writes aria-expanded="false".) */
const expanded = (button: Node): boolean => button.props['aria-expanded'] === true;
const why = (screen: any): string => part(screen, 'why')[0].textContent;
const moveButton = (screen: any): Node => screen.all((n: Node) => n.tag === 'button' && n.props.type === 'submit')[0];
const form = (screen: any): Node => screen.all((n: Node) => n.tag === 'form')[0];
const byId = (screen: any, id: string): Node => screen.all((n: Node) => n.props.id === id)[0];
const box = (screen: any, id: number): Node => byId(screen, `move-class-student-${id}`);
const tickedOnScreen = (screen: any): number[] => screen
    .all((n: Node) => n.tag === 'input' && n.type === 'checkbox' && n.props.checked === true)
    .map((n: Node) => Number(String(n.props.id).replace('move-class-student-', '')));
/** The roster row ids a request named, in order. */
const named = (fields: Array<[string, string]>): number[] => fields
    .filter(([key]) => /^students\[\d+\]\[membership_id\]$/.test(key)).map(([, value]) => Number(value));

/** Choose the class, and what happens to grades: the dialog is ready to move. */
async function chooseClassAndGrades(screen: any, classId = 2, grades: 'keep' | 'up' | 'set' = 'keep') {
    select(byId(screen, 'move-class-to'), classId);
    await flush();
    choose(byId(screen, `move-class-grade-${grades}`));
    await flush();
}

// ------------------------------------------------------------------ the button, and why it is off

test('class move: Move is off, and says why, until a class and a grade choice are made and somebody is ticked', async () => {
    const first = deferred();
    let answer: any = () => first.promise;
    const { store, count } = fakeStore({ preview: () => answer() });
    const screen = await mountDialog(store);

    assert.match(screen.text(), /1st Grade has 3 current students\./);
    // Nothing is chosen for the office: no class, and none of the three grade choices.
    assert.equal(count('preview'), 0);
    assert.equal(moveButton(screen).disabled, true);
    assert.equal(why(screen), 'Choose a class first');
    assert.deepEqual(screen.all((n) => n.tag === 'input' && n.type === 'radio').map((n) => n.props.checked === true), [false, false, false]);

    // A class is chosen: the check starts by itself, and Move stays off while it runs.
    select(byId(screen, 'move-class-to'), 2);
    await flush();
    assert.equal(count('preview'), 1);
    assert.match(screen.text(), /Checking…/);
    assert.equal(why(screen), 'Checking…');
    submit(form(screen));
    await flush();
    assert.equal(count('move'), 0, 'a move was sent while the check was running');

    // The list arrives with everyone who can move ticked. Still off: no choice about grades.
    first.resolve(previewOf(three()));
    await flush();
    assert.deepEqual(tickedOnScreen(screen), [11, 12, 13]);
    assert.equal(moveButton(screen).disabled, true);
    assert.equal(why(screen), 'Choose what happens to grades');
    submit(form(screen));
    await flush();
    assert.equal(count('move'), 0, 'a move was sent with no choice about grades');

    // The grade choice asks again (the list shows each grade as it will be), and then Move is on.
    answer = async () => previewOf(three());
    choose(byId(screen, 'move-class-grade-keep'));
    await flush();
    assert.equal(count('preview'), 2);
    assert.equal(moveButton(screen).disabled, false);
    assert.equal(moveButton(screen).textContent, 'Move 3 students');
    assert.equal(why(screen), '');
    assert.match(screen.text(), /Nothing is saved until you press Move\./);

    // Nobody ticked.
    click(screen.button('None'));
    await flush();
    assert.equal(moveButton(screen).disabled, true);
    assert.equal(why(screen), 'Nobody on this list is ticked');
    check(box(screen, 12), true);
    await flush();
    assert.equal(moveButton(screen).textContent, 'Move 1 student');
    assert.equal(why(screen), '');

    // "Give everyone this grade" with no grade typed.
    choose(byId(screen, 'move-class-grade-set'));
    await flush();
    assert.equal(moveButton(screen).disabled, true);
    assert.equal(why(screen), 'Type the grade to give everyone');
    screen.unmount();
});

test('class move: a refusal about the class, and a check that cannot be made, are said in the dialog and never turn Move on', async () => {
    let reloads = 0;
    const refused = fakeStore({ preview: async () => previewOf([], {
        can_move: false, refusal: '2nd Grade is not running: it is switched off or has ended. Choose a class that is running.',
    }) });
    const first = await mountDialog(refused.store);
    await chooseClassAndGrades(first);

    assert.match(first.text(), /2nd Grade is not running: it is switched off or has ended\./);
    assert.equal(part(first, 'students').length, 0, 'a refusal about the class drew a list of students');
    assert.equal(moveButton(first).disabled, true);
    assert.equal(why(first), 'This class cannot be moved there yet');
    submit(form(first));
    await flush();
    assert.equal(refused.count('move'), 0);
    first.unmount();

    const gone = fakeStore({ preview: () => Promise.reject(httpError(404, {})) });
    const second = await mountDialog(gone.store, { onReload: () => { reloads += 1; } });
    await chooseClassAndGrades(second);
    assert.match(second.text(), /This roster has changed\. Reload it\./);
    assert.equal(why(second), 'The check did not finish');
    click(second.button('Reload the roster'));
    assert.equal(reloads, 1);
    second.unmount();

    const forbidden = fakeStore({ preview: () => Promise.reject(httpError(403, {})) });
    const third = await mountDialog(forbidden.store);
    await chooseClassAndGrades(third);
    assert.match(third.text(), /You do not have permission to move students\./);
    third.unmount();

    const offline = fakeStore({ preview: () => Promise.reject(new Error('Network Error')) });
    const fourth = await mountDialog(offline.store);
    await chooseClassAndGrades(fourth);
    assert.match(fourth.text(), /Could not check this move\. Try again\./);
    const asked = offline.count('preview');
    click(fourth.button('Try again'));
    await flush();
    assert.equal(offline.count('preview'), asked + 1);
    assert.equal(moveButton(fourth).disabled, true);
    fourth.unmount();
});

test('class move: the class list says when it is loading, when it failed and when there is nothing to choose', async () => {
    const list = deferred<any[]>();
    const loading = fakeStore({ classes: () => list.promise });
    const first = await mountDialog(loading.store);
    assert.match(first.text(), /Loading classes…/);
    list.reject(new Error('offline'));
    await flush();
    assert.match(first.text(), /Could not load the classes\./);
    click(first.button('Try again'));
    await flush();
    assert.equal(loading.count('classes'), 2);
    first.unmount();

    const none = fakeStore({ classes: async () => [classes[0]] });
    const second = await mountDialog(none.store);
    assert.match(second.text(), /This school has no other class that is running\. Add the class first, on the Classes page\./);
    assert.equal(none.count('preview'), 0);
    assert.equal(moveButton(second).disabled, true);
    assert.equal(why(second), 'Choose a class first');
    second.unmount();
});

test('class move: students who were moved here from a class that is switched off are told why it is not on the list', async () => {
    const roster = [
        { id: 11, role: 'member', left_on: null, moved_from_group_id: 9, moved_from: { id: 9, name: 'Kindergarten', deleted_at: null } },
        { id: 12, role: 'member', left_on: null, moved_from_group_id: 9, moved_from: { id: 9, name: 'Kindergarten', deleted_at: null } },
        { id: 13, role: 'member', left_on: null, moved_from_group_id: 2, moved_from: { id: 2, name: '2nd Grade', deleted_at: null } },
    ];
    const { store } = fakeStore();
    const screen = await mountDialog(store, {}, roster);

    assert.deepEqual(part(screen, 'not-offered').map((n: Node) => n.textContent), [
        '2 students here were moved from Kindergarten, which is not in this list: it is switched off, has ended or was removed. '
            + 'To move them back, switch Kindergarten on again in its Edit form first.',
    ]);
    screen.unmount();
});

// ------------------------------------------------------------------------------ the request

test('class move: from the tap to the answer everything is off, a second tap sends nothing, and the body is what was shown', async () => {
    const answer = deferred<any>();
    const list = [
        student(11, 'Maryam', { path: 'returned', joined_on: '2026-09-01', expected_consent: 'm1f0s0n0e0', grade_after: '2nd' }),
        student(12, 'Yusuf', { grade_label: null, grade_after: null }),
        student(13, 'Layla'),
    ];
    const { store, calls, count } = fakeStore({ preview: async () => previewOf(list), move: () => answer.promise });
    const screen = await mountDialog(store);
    await chooseClassAndGrades(screen);

    // The check was asked with the class, the day and the grade choice.
    assert.deepEqual(calls.filter((c) => c.what === 'preview').at(-1)!.args, [1, 2, '2026-10-04', 'keep', '']);

    // The office leaves Layla out.
    check(box(screen, 13), false);
    await flush();

    submit(form(screen));
    await flush();
    assert.equal(moveButton(screen).disabled, true);
    assert.equal(screen.button('Cancel').disabled, true);
    assert.equal(why(screen), 'Moving 2 students. Keep this page open.');
    // The ticks cannot be changed under a request that is on its way.
    assert.equal(check(box(screen, 13), true), false);

    // The double tap: the handler runs again although the button is off.
    submit(form(screen));
    press(moveButton(screen));
    await flush();
    assert.equal(count('move'), 1, 'a double tap sent two requests');

    const [groupId, fields] = calls.find((c) => c.what === 'move')!.args;
    assert.equal(groupId, 1);
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
        ['students[1][expected_joined_on]', '2026-10-04'],
        ['students[1][expected_consent]', 'm0f0s0n0e0'],
        // No grade: a blank, never the word "null".
        ['students[1][expected_grade]', ''],
    ]);
    screen.unmount();
});

test('class move: a student who cannot move has an untickable box and the reason as text, with the class to open', async () => {
    const opened: any[] = [];
    const list = [
        student(11, 'Maryam'),
        stuck(12, 'Yusuf'),
        stuck(13, 'Layla', {
            refusal: 'Nadia Guardian withdrew consent in 1st Grade after it had been carried there from 2nd Grade.\nNothing was moved. Open 2nd Grade and withdraw that consent on its roster first.',
            open_group: { id: 2, name: '2nd Grade', membership_id: 77 },
        }),
    ];
    const { store, last } = fakeStore({ preview: async () => previewOf(list) });
    const screen = await mountDialog(store, { 'onOpen-class': (...args: any[]) => opened.push(args) });
    await chooseClassAndGrades(screen);

    assert.deepEqual(tickedOnScreen(screen), [11]);
    assert.equal(box(screen, 12).disabled, true);
    assert.equal(check(box(screen, 12), true), false);

    // A tap the re-render missed runs the handler anyway: still not ticked.
    (box(screen, 12) as any).checked = true;
    box(screen, 12).props.onChange({ target: box(screen, 12) });
    await flush();
    assert.deepEqual(tickedOnScreen(screen), [11]);

    // The reason is ordinary text the box points at, never a tooltip.
    assert.equal(box(screen, 12).props['aria-describedby'], 'move-class-why-12');
    assert.equal(byId(screen, 'move-class-why-12').textContent, 'Yusuf Student is already in 2nd Grade. Nothing was moved.');
    assert.equal(box(screen, 11).props['aria-describedby'], undefined);
    assert.match(byId(screen, 'move-class-why-13').textContent, /Nadia Guardian withdrew consent in 1st Grade.*Nothing was moved\. Open 2nd Grade/);

    // Every box has a real label, which is the student's name.
    const label = screen.all((n) => n.tag === 'label' && n.props.for === 'move-class-student-12')[0];
    assert.equal(label.textContent, 'Yusuf Student');

    // "Open 2nd Grade" names the roster row there the remedy is about.
    click(screen.button('Open 2nd Grade'));
    assert.deepEqual(opened, [[2, 77]]);

    // Only the student who can move is sent.
    submit(form(screen));
    await flush();
    assert.deepEqual(named(last('move')[1]), [11]);
    screen.unmount();
});

test('class move: each row shows its summary, the grade where it changes, and the single move\'s sentences under Details', async () => {
    const list = [
        student(11, 'Maryam', { grade_after: '2nd', lines: ['Maryam Student has nothing recorded in 1st Grade.', 'A second sentence from the server.'] }),
        student(12, 'Yusuf', { grade_label: '12th', grade_after: '12th', grade_note: '12th cannot be moved up one, so it is kept.' }),
    ];
    const { store } = fakeStore({ preview: async () => previewOf(list) });
    const screen = await mountDialog(store);
    await chooseClassAndGrades(screen, 2, 'up');

    assert.deepEqual(part(screen, 'grade').map((n: Node) => n.textContent), ['Grade: 1st to 2nd']);
    assert.match(screen.text(), /12th cannot be moved up one, so it is kept\./);
    assert.match(screen.text(), /Moves to 2nd Grade from 4 Oct 2026, with no guardian on this roster\./);

    // Details is a button that says whether it is open, and it holds the server's sentences.
    assert.doesNotMatch(screen.text(), /A second sentence from the server\./);
    const details = screen.all((n) => n.tag === 'button' && n.textContent.trim() === 'Details');
    assert.equal(details.length, 2);
    assert.equal(expanded(details[0]), false);
    click(details[0]);
    await flush();
    assert.match(screen.text(), /A second sentence from the server\./);
    assert.equal(expanded(screen.all((n) => n.tag === 'button' && n.textContent.trim() === 'Details')[0]), true);
    screen.unmount();
});

test('class move: the three buttons that tick in bulk', async () => {
    const list = [
        student(11, 'Maryam', { came_from_target: true }),
        student(12, 'Yusuf'),
        stuck(13, 'Layla', { came_from_target: true }),
        student(14, 'Zayd', { came_from_target: true }),
    ];
    const { store, last } = fakeStore({ preview: async () => previewOf(list) });
    const screen = await mountDialog(store);
    await chooseClassAndGrades(screen);

    assert.deepEqual(tickedOnScreen(screen), [11, 12, 14]);

    click(screen.button('None'));
    await flush();
    assert.deepEqual(tickedOnScreen(screen), []);

    // For putting a class back: only those who came from the class chosen, and of them only who can go.
    click(screen.button('Only the 2 students who came from 2nd Grade'));
    await flush();
    assert.deepEqual(tickedOnScreen(screen), [11, 14]);

    click(screen.button('All who can move (3)'));
    await flush();
    assert.deepEqual(tickedOnScreen(screen), [11, 12, 14]);

    click(screen.button('Only the 2 students who came from 2nd Grade'));
    await flush();
    submit(form(screen));
    await flush();
    assert.deepEqual(named(last('move')[1]), [11, 14]);
    screen.unmount();

    // With nobody who came from there, the third button is not offered.
    const plain = fakeStore();
    const other = await mountDialog(plain.store);
    await chooseClassAndGrades(other);
    assert.equal(other.all((n) => n.tag === 'button' && n.textContent.includes('who came from')).length, 0);
    other.unmount();
});

test('class move: more students than one request takes are cut to the limit, and the dialog says so', async () => {
    const many = Array.from({ length: 61 }, (_, i) => student(100 + i, `Student${i}`));
    const { store, last } = fakeStore({ preview: async () => previewOf(many) });
    const screen = await mountDialog(store, {}, rosterRows(61));
    await chooseClassAndGrades(screen);

    assert.equal(tickedOnScreen(screen).length, 61);
    assert.equal(part(screen, 'too-many')[0].textContent, 'Move up to 60 at a time. The rest stay on this list for the next round.');
    assert.equal(moveButton(screen).textContent, 'Move 60 students');

    submit(form(screen));
    await flush();
    // The first sixty of the list, in its order.
    assert.deepEqual(named(last('move')[1]), many.slice(0, 60).map((s) => s.membership_id));

    // At the limit there is nothing to say.
    const exact = fakeStore({ preview: async () => previewOf(many.slice(0, 60)) });
    const other = await mountDialog(exact.store, {}, rosterRows(60));
    await chooseClassAndGrades(other);
    assert.equal(part(other, 'too-many').length, 0);
    other.unmount();
    screen.unmount();
});

// -------------------------------------------------------------------------------- the ticks

test('class move: the ticks are the office\'s. A re-check keeps them, unticks who can no longer move, and ticks nobody', async () => {
    // Layla cannot move at first. From the second check on she can, and Zayd no longer can.
    let list = [student(11, 'Maryam'), student(12, 'Yusuf'), stuck(13, 'Layla'), student(14, 'Zayd'), student(15, 'Idris')];
    const later = () => [student(11, 'Maryam'), student(12, 'Yusuf'), student(13, 'Layla'), stuck(14, 'Zayd'), student(15, 'Idris')];
    const { store, calls, count } = fakeStore({ preview: async () => previewOf(list) });
    const screen = await mountDialog(store);

    select(byId(screen, 'move-class-to'), 2);
    await flush();
    assert.deepEqual(tickedOnScreen(screen), [11, 12, 14, 15]);

    // The office unticks two.
    check(box(screen, 11), false);
    check(box(screen, 12), false);
    await flush();
    assert.deepEqual(tickedOnScreen(screen), [14, 15]);
    // The lines count everyone who can move, and the dialog says how many of them are not ticked.
    assert.equal(part(screen, 'counted')[0].textContent, 'The lines below count all 4 students who can move. 2 of them are not ticked.');

    const sameTicks = async (what: string, asked: number) => {
        await flush();
        assert.equal(count('preview'), asked, `${what}: not one check`);
        // Maryam and Yusuf are still unticked, Layla (who newly can move) arrived unticked, and
        // Zayd (who no longer can) lost his tick and says why.
        assert.deepEqual(tickedOnScreen(screen), [15], what);
        assert.equal(box(screen, 13).disabled, false, what);
        assert.equal(box(screen, 14).disabled, true, what);
        assert.equal(byId(screen, 'move-class-why-14').textContent, 'Zayd Student is already in 2nd Grade. Nothing was moved.');
    };

    // 1. The grade choice.
    list = later();
    choose(byId(screen, 'move-class-grade-up'));
    await sameTicks('the grade choice', 2);
    assert.equal(part(screen, 'tick-note').length, 0, 'a re-check said the list had been ticked again');

    // 2. The day.
    type(byId(screen, 'move-class-first-day'), '2026-10-03');
    await sameTicks('the day', 3);
    assert.deepEqual(calls.filter((c) => c.what === 'preview').at(-1)!.args, [1, 2, '2026-10-03', 'up', '']);

    // 3. The grade typed. TYPING ASKS NOTHING PER KEYSTROKE: Move goes off at once (the list is
    //    for the grade typed before), and one check is made when the field loses focus.
    choose(byId(screen, 'move-class-grade-set'));
    await sameTicks('give everyone this grade', 4);
    const field = byId(screen, 'move-class-grade-label');
    for (const typed of ['L', 'Le', 'Lev', 'Level 2']) {
        type(field, typed);
        await flush();
    }
    assert.equal(count('preview'), 4, 'a keystroke asked the server');
    assert.equal(moveButton(screen).disabled, true);
    assert.equal(why(screen), 'Checking…');
    blur(field);
    await sameTicks('the grade typed', 5);
    assert.deepEqual(calls.filter((c) => c.what === 'preview').at(-1)!.args, [1, 2, '2026-10-03', 'set', 'Level 2']);
    assert.equal(moveButton(screen).textContent, 'Move 1 student');

    // ...or after a pause of half a second, with the field still focused.
    type(field, 'Level 3');
    await flush();
    assert.equal(count('preview'), 5);
    await new Promise((r) => setTimeout(r, 560));
    await sameTicks('a pause in typing', 6);
    // Losing focus afterwards asks nothing more.
    blur(field);
    await flush();
    assert.equal(count('preview'), 6);

    // ANOTHER CLASS starts again from everyone who can move, and the dialog says so.
    select(byId(screen, 'move-class-to'), 3);
    await flush();
    assert.equal(count('preview'), 7);
    assert.deepEqual(tickedOnScreen(screen), [11, 12, 13, 15]);
    assert.equal(part(screen, 'tick-note')[0].textContent, 'The list was ticked again for 2nd Grade.');
    screen.unmount();
});

test('class move: a late answer to an older choice is dropped', async () => {
    const slow = deferred<any>();
    const answers = [() => slow.promise, async () => previewOf([student(21, 'Bilal')])];
    const { store, count } = fakeStore({ preview: () => answers.shift()!() });
    const screen = await mountDialog(store);

    select(byId(screen, 'move-class-to'), 2);
    await flush();
    select(byId(screen, 'move-class-to'), 3);
    await flush();
    assert.equal(count('preview'), 2);
    assert.deepEqual(tickedOnScreen(screen), [21]);

    // The answer about the class chosen first arrives last, and changes nothing.
    slow.resolve(previewOf(three()));
    await flush();
    assert.deepEqual(tickedOnScreen(screen), [21]);
    assert.doesNotMatch(screen.text(), /Maryam Student/);
    screen.unmount();
});

// ------------------------------------------------------------------- refused before anything is written

test('class move: "changed while you were looking" is shown, marks the students that changed, keeps the ticks and checks again', async () => {
    let closed = 0;
    const refusal = httpError(409, {
        status: 'error', message: 'This roster changed while you were looking. Nothing was moved. Look again.', open_group: null,
        data: { students: [student(12, 'Yusuf', { first_day_in_new_class: '2026-10-05' })] },
    });
    const moves = [() => Promise.reject(refusal), async () => answerOf(three().map(movedRow))];
    const { store, count, last } = fakeStore({ move: () => moves.shift()!() });
    const screen = await mountDialog(store, { onClose: () => { closed += 1; }, onMoved: () => { closed += 1; } });
    await chooseClassAndGrades(screen);

    check(box(screen, 11), false);
    await flush();
    const asked = count('preview');

    submit(form(screen));
    await flush();

    // The server's sentence, not a list of objects, and the dialog is still open.
    assert.match(screen.text(), /This roster changed while you were looking\. Nothing was moved\. Look again\./);
    assert.doesNotMatch(screen.text(), /\[object Object\]/);
    assert.equal(closed, 0);
    // It checked again by itself, the ticks are what they were, and the student that changed is marked.
    assert.equal(count('preview'), asked + 1);
    assert.deepEqual(tickedOnScreen(screen), [12, 13]);
    const marked = screen.all((n) => n.tag === 'span' && n.textContent === 'Changed');
    assert.equal(marked.length, 1);
    assert.equal(marked[0].parent!.children.some((c) => c.props?.id === 'move-class-student-12'), true);

    // What the list says now can be sent.
    assert.equal(moveButton(screen).disabled, false);
    submit(form(screen));
    await flush();
    assert.equal(count('move'), 2);
    assert.deepEqual(named(last('move')[1]), [12, 13]);
    screen.unmount();
});

test('class move: every other refusal before the run is the server\'s sentence, in the dialog, with the ticks kept', async () => {
    const refusals = [
        [409, { status: 'error', message: 'A move out of 1st Grade is still running (it may be yours). Wait two minutes, then reload this roster.', open_group: null }],
        [409, { status: 'error', message: 'Manara is being updated. Try this move again in a minute.', open_group: null }],
        [422, { status: 'error', message: '2nd Grade is not running: it is switched off or has ended. Choose a class that is running.', open_group: null }],
        [422, { status: 'failed', data: { students: ['Move up to 60 students at a time.'] } }],
    ] as Array<[number, any]>;

    for (const [status, body] of refusals) {
        const { store, count } = fakeStore({ move: () => Promise.reject(httpError(status, body)) });
        const screen = await mountDialog(store);
        await chooseClassAndGrades(screen);
        check(box(screen, 13), false);
        await flush();
        const asked = count('preview');

        submit(form(screen));
        await flush();

        assert.ok(screen.text().includes(body.message ?? body.data.students[0]), `the refusal was not shown: ${JSON.stringify(body)}`);
        assert.equal(count('preview'), asked + 1);
        assert.deepEqual(tickedOnScreen(screen), [11, 12]);
        assert.doesNotMatch(screen.text(), /The answer did not arrive/);

        // Another choice takes the refusal down: it was about the choices made then.
        choose(byId(screen, 'move-class-grade-up'));
        await flush();
        assert.equal(screen.all((n) => n.props.role === 'alert').length, 0);
        screen.unmount();
    }
});

// ------------------------------------------------------------------------------ the result

test('class move: the result names every student, says what to check, and stays until OK', async () => {
    let moved = 0;
    const opened: any[] = [];
    const students = [
        movedRow(student(11, 'Maryam')),
        { membership_id: 12, name: 'Yusuf Student', outcome: 'not_moved', retry: false, open_group: { id: 2, name: '2nd Grade' },
            reason: 'Gamal Guardian is a confirmed guardian of Yusuf Student in 2nd Grade but not a confirmed guardian here.\nNothing was moved. Then move Yusuf Student again.' },
        { membership_id: 13, name: 'Layla Student', outcome: 'not_moved', reason: 'A fault stopped this move. Nothing about this student was changed.', open_group: null, retry: false },
    ];
    const { store } = fakeStore({ move: async () => answerOf(students) });
    const screen = await mountDialog(store, { onMoved: () => { moved += 1; }, 'onOpen-class': (...args: any[]) => opened.push(args) });
    await chooseClassAndGrades(screen);

    // Before the move "Afterwards, check" is one closed disclosure that says how many lines it holds.
    const afterwards = screen.button('Afterwards, check (2)');
    assert.equal(expanded(afterwards), false);
    assert.doesNotMatch(screen.text(), /2nd Grade has no teacher yet\./);
    click(afterwards);
    await flush();
    assert.match(screen.text(), /2nd Grade has no teacher yet\./);
    assert.equal(expanded(screen.button('Afterwards, check (2)')), true);
    click(screen.button('Afterwards, check (2)'));
    await flush();

    submit(form(screen));
    await flush();

    // The server's lines, in their groups; "Afterwards, check" is open here, where it can be acted on.
    assert.deepEqual(items(part(screen, 'done')[0]), ['The server said how many students are in 2nd Grade.']);
    assert.deepEqual(items(part(screen, 'consent')[0]), ['The server said what happened to consent.']);
    assert.deepEqual(items(part(screen, 'afterwards')[0]), ['2nd Grade has no teacher yet.', '1st Grade keeps its teachers.']);
    assert.equal(part(screen, 'bucks').length, 0);

    // Every student, with a word for the state (never colour alone) and the reason as text.
    const rows = part(screen, 'outcomes')[0].children.filter((c) => c.kind === 'el');
    assert.equal(rows.length, 3);
    assert.match(rows[0].textContent, /^Maryam Student Moved/);
    assert.match(rows[1].textContent, /^Yusuf Student Not moved Gamal Guardian is a confirmed guardian of Yusuf Student in 2nd Grade but not a confirmed guardian here\. Nothing was moved\./);
    assert.match(rows[2].textContent, /^Layla Student Not moved A fault stopped this move\./);

    click(screen.button('Open 2nd Grade'));
    assert.deepEqual(opened, [[2, undefined]]);

    // A moved student's own sentences are under Details.
    assert.doesNotMatch(screen.text(), /Maryam Student arrived\./);
    click(screen.button('Details'));
    await flush();
    assert.match(screen.text(), /Maryam Student arrived\./);

    // Nobody was left unreached or busy: there is no "Move the rest".
    assert.equal(screen.all((n) => n.tag === 'button' && n.textContent.includes('Move the rest')).length, 0);

    assert.equal(moved, 0, 'the dialog closed itself before the office read what happened');
    click(screen.button('OK'));
    assert.equal(moved, 1);
    screen.unmount();
});

test('class move: a preview with report cards not started says so beside the closed disclosure', async () => {
    const preview = previewOf(three());
    preview.counts.report_cards_not_started = 2;
    const { store } = fakeStore({ preview: async () => preview });
    const screen = await mountDialog(store);
    await chooseClassAndGrades(screen);

    assert.match(part(screen, 'afterwards')[0].textContent, /^Afterwards, check \(2\) 2 report cards not started$/);
    screen.unmount();
});

test('class move: "Move the rest" checks afresh and ticks only the students who were not reached or were busy', async () => {
    let closed = 0;
    let moved = 0;
    const five = () => [student(11, 'Maryam'), student(12, 'Yusuf'), student(13, 'Layla'), student(14, 'Zayd'), student(15, 'Idris')];
    const firstAnswer = answerOf([
        movedRow(student(11, 'Maryam')),
        { membership_id: 12, name: 'Yusuf Student', outcome: 'not_moved', retry: true, open_group: null,
            reason: 'This roster changed while the move was being saved. Nothing was moved. Look again and retry.' },
        { membership_id: 13, name: 'Layla Student', outcome: 'not_moved', retry: false, open_group: null, reason: 'Layla Student is already in 2nd Grade. Nothing was moved.' },
        { membership_id: 14, name: 'Zayd Student', outcome: 'not_reached' },
    ]);
    const previews = [
        // The first list: Layla cannot move, so she is named with a stale tick only in the answer above.
        async () => previewOf(five()),
        async () => previewOf(five()),
        // The fresh check after the run: Maryam has gone, and Layla's reason has since been cleared.
        async () => previewOf([student(12, 'Yusuf'), student(13, 'Layla'), student(14, 'Zayd'), student(15, 'Idris')]),
    ];
    const moves = [async () => firstAnswer, async () => answerOf([movedRow(student(12, 'Yusuf')), movedRow(student(14, 'Zayd'))])];
    const { store, count, last } = fakeStore({ preview: () => previews.shift()!(), move: () => moves.shift()!() });
    const screen = await mountDialog(store, { onClose: () => { closed += 1; }, onMoved: () => { moved += 1; } });
    await chooseClassAndGrades(screen);

    // The office leaves Idris out.
    check(box(screen, 15), false);
    await flush();
    submit(form(screen));
    await flush();
    assert.deepEqual(named(last('move')[1]), [11, 12, 13, 14]);

    const rest = screen.button('Move the rest');
    click(rest);
    await flush();

    // A fresh check, and nothing sent.
    assert.equal(count('preview'), 3);
    assert.equal(count('move'), 1);
    // ONLY Yusuf (busy) and Zayd (not reached). Not Layla, refused for a reason that has since
    // been cleared; not Idris, whom the office had left out.
    assert.deepEqual(tickedOnScreen(screen), [12, 14]);
    assert.equal(part(screen, 'tick-note')[0].textContent, 'Only the 2 students who were not reached or were busy are ticked.');
    assert.equal(moveButton(screen).textContent, 'Move 2 students');

    submit(form(screen));
    await flush();
    assert.equal(count('move'), 2);
    assert.deepEqual(named(last('move')[1]), [12, 14]);

    click(screen.button('OK'));
    assert.deepEqual([closed, moved], [0, 1]);
    screen.unmount();
});

test('class move: after a run that moved somebody, leaving the dialog any way tells the page its roster is out of date', async () => {
    let closed = 0;
    let moved = 0;
    const answer = answerOf([movedRow(student(11, 'Maryam')), { membership_id: 12, name: 'Yusuf Student', outcome: 'not_reached' }]);
    const { store } = fakeStore({ move: async () => answer });
    const screen = await mountDialog(store, { onClose: () => { closed += 1; }, onMoved: () => { moved += 1; } });
    await chooseClassAndGrades(screen);
    submit(form(screen));
    await flush();

    // Back on the form by "Move the rest", the office changes its mind and cancels.
    click(screen.button('Move the rest'));
    await flush();
    click(screen.button('Cancel'));
    assert.deepEqual([closed, moved], [0, 1], 'the page was not told that students had been moved');
    screen.unmount();
});

test('class move: when the answer never arrives nothing is assumed, and the same request is not sent again', async () => {
    for (const failure of [new Error('Network Error'), httpError(504, '<html>Gateway Time-out</html>')]) {
        let reloads = 0;
        let moved = 0;
        const { store, count } = fakeStore({ move: () => Promise.reject(failure) });
        const screen = await mountDialog(store, { onReload: () => { reloads += 1; }, onMoved: () => { moved += 1; }, onClose: () => { moved -= 100; } });
        await chooseClassAndGrades(screen);
        const asked = count('preview');

        submit(form(screen));
        await flush();

        assert.match(screen.text(), /The answer did not arrive\. Some students may have been moved, and the move may still be running\. Wait a minute, then close this and reload the roster: anyone not marked 'Moved to 2nd Grade' was not moved and can be moved again\./);
        // There is no form and no Move button left to send it with, and no check was made either.
        assert.equal(screen.all((n) => n.tag === 'form').length, 0);
        assert.equal(screen.all((n) => n.tag === 'button' && /^Move/.test(n.textContent.trim())).length, 0);
        screen.all((n) => n.tag === 'button').forEach((b) => press(b));
        await flush();
        assert.equal(count('move'), 1, 'the same request was sent again');
        assert.equal(count('preview'), asked);

        // Every way out reloads the roster, which is the only thing that knows who was moved.
        assert.equal(reloads, 1);
        assert.equal(moved, 1);
        screen.unmount();
    }
});

// ---------------------------------------------------------------------- the keyboard, and focus

test('class move: the keyboard comes into the dialog, Escape closes it except while it saves, and focus goes to the result and back', async () => {
    const opener = openerButton();
    const answer = deferred<any>();
    let closed = 0;
    let moved = 0;
    const { store } = fakeStore({ move: () => answer.promise });
    const screen = await mountDialog(store, { onClose: () => { closed += 1; }, onMoved: () => { moved += 1; } });

    // Focus was on "Move the class" behind the backdrop; it is on the class picker now.
    assert.equal(focused(), '#move-class-to');
    assert.equal(documentKeydown.size, 1);

    await chooseClassAndGrades(screen);
    submit(form(screen));
    await flush();
    pressKey('Escape');
    assert.deepEqual([closed, moved], [0, 0], 'Escape left the dialog while the class was being moved');

    // The form is replaced by the result: focus is on its heading, so it is read from the top.
    answer.resolve(answerOf(three().map(movedRow)));
    await flush();
    assert.equal(focused(), '#move-class-result');
    assert.equal(screen.all((n) => n.props.id === 'move-class-result')[0].props.tabindex, '-1');

    // Escape is OK now.
    pressKey('Escape');
    assert.deepEqual([closed, moved], [0, 1]);

    screen.unmount();
    assert.equal(documentKeydown.size, 0, 'the dialog left its key listener on the document');
    assert.equal(doc.activeElement, opener, 'focus did not go back to the button that opened the dialog');
    onPage.clear();
});

test('class move: Escape before anything is sent is Cancel, and with no class picker focus is still inside', async () => {
    openerButton();
    let closed = 0;
    const { store } = fakeStore({ classes: () => Promise.reject(new Error('offline')) });
    const screen = await mountDialog(store, { onClose: () => { closed += 1; } });

    assert.equal(focused(), '[Close]');
    pressKey('Escape');
    assert.equal(closed, 1);
    screen.unmount();
    onPage.clear();
});
