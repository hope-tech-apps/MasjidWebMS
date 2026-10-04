/**
 * The office's "Student details" panel (core/helpers/studentDetails.ts, StudentDetailsPanel.vue,
 * and its wiring in GroupRosterTab.vue and ContactsView.vue).
 *
 * The rule under test: ONLY A GUARDIAN ENTRY THE SCHOOL STANDS BEHIND, AND THAT IS STILL IN THE
 * CLASS, IS OFFERED AS SOMEONE TO CALL. A claim a public registration form wrote is shown as text
 * and never as a link, an entry that left is a name and a day, and a student who has left the class
 * shows no link at all.
 *
 * Three layers: the pure helper (which rows land in which list, which values become links, the
 * Member Directory link); the panel, MOUNTED, so "no link" is said about what is drawn and not
 * about a list; and the roster, MOUNTED, so the student's name is proved to open it.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import * as ApiErrors from '../core/services/ApiErrors.ts';
import * as helper from '../core/helpers/studentDetails.ts';
import * as rosterMove from '../core/helpers/rosterMove.ts';
import * as studentAge from '../core/helpers/studentAge.ts';
import {
    CONTACT_QUERY_KEY,
    NO_ADDRESS,
    RECORD_NOT_OPENED,
    contactIdFromQuery,
    dialHref,
    fullRecordQuery,
    isStudentRow,
    mailHref,
    openLinkedRecord,
    personName,
    storedDayLabel,
    studentDetails,
    studentRowFor,
    withoutContactQuery,
    type RosterRow,
} from '../core/helpers/studentDetails.ts';
import { Node, click, deferred, flush, mountSfc } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');
const read = (rel: string): string => readFileSync(new URL(rel, import.meta.url), 'utf8');

// ------------------------------------------------------------------ a roster, as the API serves it

const CLASS = 3;
let nextId = 100;

type Person = { id: number; first_name: string; last_name: string; email: string | null; phone: string | null };

const person = (id: number, first: string, last: string, email: string | null = null, phone: string | null = null): Person =>
    ({ id, first_name: first, last_name: last, email, phone });

/** One roster row with the keys the listing serves (OfficeStudentDetailsPayloadTest pins them). */
function row(over: Partial<RosterRow> & { contact: Person; guardian_of?: Person | null }): any {
    return {
        id: nextId++,
        masjid_id: 1,
        group_id: CLASS,
        contact_id: over.contact.id,
        role: 'member',
        grade_label: null,
        guardian_of_contact_id: null,
        joined_at: '2026-09-01T00:00:00.000000Z',
        left_on: null,
        consent_granted_at: null,
        consent_scope: null,
        provenance: 'confirmed',
        guardian_of: null,
        ...over,
    };
}

const maryam = person(1, 'Maryam', 'Testwood');
const yahya = person(2, 'Yahya', 'Testwood');

const guardianOf = (ward: Person, who: Person, over: Partial<RosterRow> = {}) =>
    row({ contact: who, role: 'guardian', guardian_of_contact_id: ward.id, guardian_of: ward, ...over });

/** Maryam's class: her, her brother, and every kind of guardian entry a roster can hold. */
function roster() {
    const student = row({ contact: maryam, grade_label: '3rd' });
    const sibling = row({ contact: yahya });

    const mother = guardianOf(maryam, person(10, 'Salma', 'Testwood', 'salma@household.test', '+1 (555) 010-0001'), {
        consent_scope: 'media',
        consent_granted_at: '2026-09-05T00:00:00.000000Z',
    });
    const father = guardianOf(maryam, person(11, 'Idris', 'Testwood'));
    const claimant = guardianOf(maryam, person(12, 'Karim', 'Formfield', 'karim@elsewhere.test', '+15550109999'), {
        provenance: 'self_asserted',
    });
    const departed = guardianOf(maryam, person(13, 'Harun', 'Testwood', 'harun@household.test', '+15550100002'), {
        left_on: '2026-09-20T00:00:00.000000Z',
    });
    // The same adult's entry over the BROTHER, and an aunt who is only the brother's guardian.
    const motherOfSibling = guardianOf(yahya, mother.contact);
    const aunt = guardianOf(yahya, person(14, 'Zaynab', 'Otherchild', 'zaynab@household.test', '+15550100003'));

    return {
        student, sibling, mother, father, claimant, departed, motherOfSibling, aunt,
        rows: [student, sibling, mother, father, claimant, departed, motherOfSibling, aunt],
    };
}

// =================================================================== the helper: three lists

test('each guardian entry that names the student lands in exactly one list', () => {
    const r = roster();
    const details = studentDetails(r.rows, r.student);

    assert.equal(details.studentHasLeft, false);
    assert.deepEqual(details.confirmed.map((g) => g.id), [r.mother.id, r.father.id]);
    assert.deepEqual(details.unconfirmed.map((g) => g.id), [r.claimant.id]);
    assert.deepEqual(details.departed.map((g) => g.id), [r.departed.id]);
    assert.deepEqual(details.former, []);
});

test('a guardian of a sibling in the same class is not listed', () => {
    const r = roster();
    const details = studentDetails(r.rows, r.student);
    const listed = [...details.confirmed, ...details.unconfirmed, ...details.departed].map((g) => g.id);

    assert.ok(!listed.includes(r.aunt.id), 'the brother\'s aunt is not Maryam\'s guardian');
    assert.ok(!listed.includes(r.motherOfSibling.id), 'the mother is listed once, by her entry over Maryam');

    // And the other way round: the brother's panel holds his two and none of hers.
    assert.deepEqual(studentDetails(r.rows, r.sibling).confirmed.map((g) => g.id), [r.motherOfSibling.id, r.aunt.id]);
});

test('an entry in another class is not read, even when it names the same student', () => {
    const r = roster();
    const elsewhere = guardianOf(maryam, person(15, 'Other', 'Classroom', 'other@household.test', '+15550100004'), { group_id: 99 });

    assert.deepEqual(
        studentDetails([...r.rows, elsewhere], r.student).confirmed.map((g) => g.id),
        [r.mother.id, r.father.id],
    );
});

test('only the confirmed, current list carries links, and only from a value that is there', () => {
    const r = roster();
    const details = studentDetails(r.rows, r.student);
    const [mother, father] = details.confirmed;

    assert.equal(mother.phoneHref, 'tel:+15550100001');
    assert.equal(mother.phone, '+1 (555) 010-0001', 'the number is shown as the office typed it');
    assert.equal(mother.emailHref, 'mailto:salma@household.test');
    assert.equal(mother.consent, 'Photos & notes');
    assert.equal(mother.consentDay, '2026-09-05');

    // Confirmed, with nothing on file: no link is invented.
    assert.deepEqual(
        { phone: father.phone, phoneHref: father.phoneHref, email: father.email, emailHref: father.emailHref },
        { phone: null, phoneHref: null, email: null, emailHref: null },
    );
    assert.equal(father.consent, 'Not given');
    assert.equal(father.consentDay, null);

    // The other two lists hold no link, and no field a link could be drawn from by mistake.
    assert.deepEqual(details.unconfirmed, [{ id: r.claimant.id, name: 'Karim Formfield', address: 'karim@elsewhere.test' }]);
    assert.deepEqual(details.departed, [{ id: r.departed.id, name: 'Harun Testwood', leftOn: '2026-09-20' }]);
    assert.doesNotMatch(JSON.stringify([details.unconfirmed, details.departed]), /tel:|mailto:|Href/);
});

test('an unconfirmed entry shows its address as text, and says so when it has none', () => {
    const student = row({ contact: maryam });
    const phoneOnly = guardianOf(maryam, person(20, 'Phone', 'Only', null, '+15550107777'), { provenance: 'self_asserted' });
    const nothing = guardianOf(maryam, person(21, 'No', 'Address'), { provenance: 'self_asserted' });

    assert.deepEqual(
        studentDetails([student, phoneOnly, nothing], student).unconfirmed.map((g) => g.address),
        ['+15550107777', NO_ADDRESS],
    );
});

test('a provenance this build does not know is unconfirmed, never someone to call', () => {
    const student = row({ contact: maryam });
    const odd = guardianOf(maryam, person(22, 'Newer', 'Build', 'newer@household.test', '+15550108888'), { provenance: 'verified_by_post' });
    const details = studentDetails([student, odd], student);

    assert.deepEqual(details.confirmed, []);
    assert.deepEqual(details.unconfirmed.map((g) => g.id), [odd.id]);
});

test('a student who has left shows names only: every list that could hold a link is empty', () => {
    const r = roster();
    r.student.left_on = '2026-09-28T00:00:00.000000Z';
    const details = studentDetails(r.rows, r.student);

    assert.equal(details.studentHasLeft, true);
    assert.deepEqual([details.confirmed, details.unconfirmed, details.departed], [[], [], []]);
    assert.deepEqual(details.former, [
        { id: r.mother.id, name: 'Salma Testwood', confirmed: true },
        { id: r.father.id, name: 'Idris Testwood', confirmed: true },
        { id: r.claimant.id, name: 'Karim Formfield', confirmed: false },
        { id: r.departed.id, name: 'Harun Testwood', confirmed: true },
    ]);
    assert.doesNotMatch(JSON.stringify(details), /tel:|mailto:|@|\+1555/, 'no address of any kind is carried for a student who left');
});

test('a tel: link is built from digits, and a value with none gets no link', () => {
    assert.equal(dialHref('+1 (555) 010-0001'), 'tel:+15550100001');
    assert.equal(dialHref('555.010.0001'), 'tel:5550100001');
    assert.equal(dialHref('  '), null);
    assert.equal(dialHref(null), null);
    assert.equal(dialHref(undefined), null);
    assert.equal(dialHref('ask at the desk'), null);
});

test('a mailto: link is one plain address, never a second recipient or a header', () => {
    assert.equal(mailHref(' salma@household.test '), 'mailto:salma@household.test');
    assert.equal(mailHref(''), null);
    assert.equal(mailHref(null), null);
    assert.equal(mailHref('salma@household.test,other@elsewhere.test'), null);
    assert.equal(mailHref('salma@household.test?bcc=other@elsewhere.test'), null);
    assert.equal(mailHref('salma@household.test&subject=x'), null);
    assert.equal(mailHref('salma at household'), null);
    assert.equal(mailHref('javascript:alert(1)'), null);
});

test('only a student row opens the panel, found from a guardian entry by the child it names', () => {
    const r = roster();
    const leader = row({ contact: person(30, 'Teacher', 'Row'), role: 'leader' });

    assert.equal(isStudentRow(r.student), true);
    assert.equal(isStudentRow(leader), false);
    assert.equal(isStudentRow(r.mother), false);
    assert.equal(isStudentRow(null), false);

    assert.equal(studentRowFor(r.rows, r.mother.guardian_of_contact_id), r.student);
    assert.equal(studentRowFor(r.rows, r.aunt.guardian_of_contact_id), r.sibling);
    // A guardian entry whose child has no student row on this roster opens nothing.
    assert.equal(studentRowFor(r.rows, 999), null);
    assert.equal(studentRowFor(r.rows, null), null);
    // The mother is on the roster as a guardian, not as a student: her own id finds no panel.
    assert.equal(studentRowFor(r.rows, r.mother.contact_id), null);
});

test('a stored day is read off the string, so it is the same day in every timezone', () => {
    assert.equal(storedDayLabel('2026-09-05T00:00:00.000000Z', 'en-US'), 'Sep 5, 2026');
    assert.equal(storedDayLabel('2026-09-05', 'en-US'), 'Sep 5, 2026');
    assert.equal(storedDayLabel(null), '—');
    assert.equal(personName(null), '—');
    assert.equal(personName({ first_name: ' Maryam ', last_name: null }), 'Maryam');
});

// =================================================================== the Member Directory link

test('the deep link takes only a plain positive whole number', () => {
    assert.equal(contactIdFromQuery('7'), 7);
    assert.equal(contactIdFromQuery('1024'), 1024);

    for (const bad of ['0', '-7', '07', '7.5', '1e3', '7abc', ' 7', '7 ', '', '0x10', '99999999999999999999', 'null']) {
        assert.equal(contactIdFromQuery(bad), null, `"${bad}" is not an id`);
    }
    // vue-router hands over an array for ?contact=1&contact=2, null for a bare ?contact, undefined for none.
    assert.equal(contactIdFromQuery(['1', '2']), null);
    assert.equal(contactIdFromQuery(null), null);
    assert.equal(contactIdFromQuery(undefined), null);
    assert.equal(contactIdFromQuery(7), null);
});

test('the link is ?contact={id}, and dropping it keeps the rest of the address', () => {
    assert.equal(CONTACT_QUERY_KEY, 'contact');
    assert.deepEqual(fullRecordQuery(7), { contact: '7' });
    assert.deepEqual(withoutContactQuery({ contact: '7', tag: '3' }), { tag: '3' });
    assert.deepEqual(withoutContactQuery({ tag: '3' }), { tag: '3' });
});

/** A screen that writes down what it was asked to do, in order. */
function linkedScreen(fetch: (id: number) => Promise<any>, stillHere?: () => boolean) {
    const log: string[] = [];
    const steps = {
        fetch: (id: number) => { log.push(`fetch ${id}`); return fetch(id); },
        dropLink: () => { log.push('drop'); },
        open: (record: any) => { log.push(`open ${record.first_name}`); },
        refuse: () => { log.push('refuse'); },
        ...(stillHere ? { stillHere } : {}),
    };

    return { log, steps };
}

test('a linked record is fetched FIRST: nothing opens until the server has answered', async () => {
    const answer = deferred<any>();
    const { log, steps } = linkedScreen(() => answer.promise);

    const done = openLinkedRecord(7, steps);
    await flush(2);
    assert.deepEqual(log, ['fetch 7'], 'no dialog while the request is out');

    answer.resolve({ id: 7, first_name: 'Maryam' });
    assert.equal(await done, true);
    // What opens is what the server returned, and the id has left the address.
    assert.deepEqual(log, ['fetch 7', 'drop', 'open Maryam']);
});

test('a 404, an empty answer or a network failure opens nothing and says one sentence', async () => {
    const refusals: Array<() => Promise<any>> = [
        () => Promise.reject(Object.assign(new Error('Request failed with status code 404'), { response: { status: 404 } })),
        () => Promise.reject(new Error('Network Error')),
        () => Promise.resolve(null),
        () => Promise.resolve(undefined),
    ];

    for (const fetch of refusals) {
        const { log, steps } = linkedScreen(fetch);

        assert.equal(await openLinkedRecord(7, steps), false);
        // The id still leaves the address, so a refresh does not repeat the refusal.
        assert.deepEqual(log, ['fetch 7', 'drop', 'refuse']);
    }

    assert.equal(RECORD_NOT_OPENED, 'That record could not be opened.');
});

test('an answer that arrives after the office left the screen does nothing at all', async () => {
    let here = true;
    const answer = deferred<any>();
    const { log, steps } = linkedScreen(() => answer.promise, () => here);

    const done = openLinkedRecord(7, steps);
    here = false;
    answer.resolve({ id: 7, first_name: 'Maryam' });

    assert.equal(await done, false);
    assert.deepEqual(log, ['fetch 7'], 'the next page\'s address is not rewritten and no dialog opens over it');
});

test('the Member Directory opens a linked record through the fetch-first path, never through viewContact', () => {
    const view = read('../views/dashboard/ContactsView.vue');
    const opener = /const openContactById = [\s\S]*?\n}\);\n/.exec(view)?.[0] ?? '';

    assert.match(opener, /openLinkedRecord<Contact>\(id, \{/);
    assert.match(opener, /fetch: \(contactId\) => contactsStore\.fetchContact\(contactId\)/);
    assert.match(opener, /router\.replace\(\{ query: withoutContactQuery\(route\.query\) \}\)/);
    assert.match(opener, /title: RECORD_NOT_OPENED/);
    assert.doesNotMatch(opener, /viewContact/);
    // The dialog is switched on in `open`, which runs only with a record the server returned.
    assert.match(opener, /open: async \(full\) => \{\s*selectedContact\.value = full;[\s\S]*showViewModal\.value = true;/);
    // The id comes from the address through the one parser, and is read once.
    assert.match(view, /let linkedContactId = contactIdFromQuery\(route\.query\[CONTACT_QUERY_KEY\]\);/);
    assert.match(view, /const id = linkedContactId;\s*linkedContactId = null;\s*openContactById\(id\);/);
});

// =================================================================== the panel, mounted

// The suite has no DOM. The panel asks two things of one: what had focus (to give it back), and
// whether that element is still in the page.
(globalThis as any).HTMLElement ??= Node;
(globalThis as any).document.contains ??= () => true;
(globalThis as any).document.body ??= { style: {} };

// <Teleport> needs a real target to mount into. Here its children are drawn where they stand: a
// Fragment, which is patched child by child as a Teleport is. (A component in its place would not
// do: the compiler hands a Teleport its children as a plain array, and a compiled parent never
// re-renders a component for a change in an array of children, so a dialog inside one would
// never open.)
const vueInPlace = { ...vue, Teleport: vue.Fragment };

const avatarStub = { render: () => null };

async function mountPanel(student: any, memberships: any[], listeners: Record<string, any> = {}) {
    const screen = await mountSfc('views/dashboard/groups/StudentDetailsPanel.vue', { student, memberships, ...listeners }, {
        vue: vueInPlace,
        '@/components/common/PersonAvatar.vue': { default: avatarStub },
        '@/core/types/data/masjid-related/Group': {},
        '@/core/helpers/focusTrap': { trapTab: () => {} },
        '@/core/helpers/studentDetails': helper,
    });
    await flush();

    return screen;
}

const links = (screen: any): string[] => screen.all((n: Node) => n.tag === 'a').map((n: Node) => String(n.props.href));

test('panel: tap-to-call and email links are drawn for confirmed, current guardians and for nobody else', async () => {
    const r = roster();
    const screen = await mountPanel(r.student, r.rows);
    const text = screen.text();

    assert.match(text, /Student details/);
    assert.match(text, /Maryam Testwood/);

    // Every anchor on the panel. The mother's two, and nothing else: not the claimant's, not
    // the departed uncle's, not the brother's aunt's.
    assert.deepEqual(links(screen), ['tel:+15550100001', 'mailto:salma@household.test']);

    // The father is confirmed and has nothing on file: said, not left blank.
    assert.match(text, /Idris Testwood no phone on file no email on file Consent: Not given/);
    assert.match(text, /Consent: Photos & notes/);

    // The form claim: named, its address as text, and the warning beside it.
    assert.match(text, /Not confirmed \(from a registration form\) Karim Formfield karim@elsewhere\.test/);
    assert.match(text, /Not confirmed\. Anyone can post the registration form\. Check who posted this before confirming it on the roster\./);
    assert.doesNotMatch(text, /\+15550109999/, 'the claimant\'s number is not on the panel in any form');

    // The entry that left: a name and a day.
    assert.match(text, /No longer in this class Harun Testwood Left /);
    assert.doesNotMatch(text, /harun@household\.test|\+15550100002/);

    // Somebody else's guardian is not on this panel.
    assert.doesNotMatch(text, /Zaynab/);

    assert.match(text, /Allergies, medical notes and emergency contacts are not kept on a student's record\./);
    screen.unmount();
});

test('panel and roster: the day a student joined is the stored day, in every timezone', async () => {
    // Served as midnight UTC. Read as an instant, a reader west of UTC would be shown 3 October.
    const student = row({ contact: maryam, joined_at: '2026-10-04T00:00:00.000000Z' });
    const expected = storedDayLabel('2026-10-04');

    const before = process.env.TZ;
    process.env.TZ = 'America/New_York';
    try {
        const panel = await mountPanel(student, [student]);
        assert.ok(panel.text().includes(`Joined ${expected}`), panel.text());
        panel.unmount();

        const { screen } = await mountRoster(vue.reactive([student]));
        const joined = screen.all((n: Node) => n.tag === 'tr')[1].children.filter((c: Node) => c.tag === 'td')[3];
        assert.equal(joined.textContent, expected);
        screen.unmount();
    } finally {
        if (before === undefined) delete process.env.TZ; else process.env.TZ = before;
    }

    // A row with no day says so, as before.
    const blank = await mountPanel(row({ contact: maryam, joined_at: null }), []);
    assert.match(blank.text(), /Joined —/);
    blank.unmount();
});

test('panel: a student with no confirmed guardian says so', async () => {
    const student = row({ contact: maryam });
    const claimant = guardianOf(maryam, person(12, 'Karim', 'Formfield', 'karim@elsewhere.test'), { provenance: 'self_asserted' });
    const screen = await mountPanel(student, [student, claimant]);

    assert.match(screen.text(), /No confirmed guardian is on this class's roster for this student\./);
    assert.deepEqual(links(screen), []);
    screen.unmount();
});

test('panel: a student who has left shows no link at all, and cannot be marked as leaving again', async () => {
    const r = roster();
    r.student.left_on = '2026-09-28T00:00:00.000000Z';
    const screen = await mountPanel(r.student, r.rows);
    const text = screen.text();

    assert.deepEqual(links(screen), []);
    assert.match(text, /This student has left this class\. For their guardians, open the class they are in now\./);
    assert.match(text, /Salma Testwood/);
    assert.match(text, /Karim Formfield \(not confirmed\)/);
    assert.doesNotMatch(text, /salma@household\.test|karim@elsewhere\.test|\+1/);
    assert.ok(text.includes(`Left ${storedDayLabel('2026-09-28')}`), 'the badge says the day they left');
    assert.equal(screen.all((n: Node) => n.tag === 'button' && n.textContent.includes('Left the class')).length, 0);
    screen.unmount();
});

test('panel: shut draws nothing; Escape and the close button ask to close; the buttons say which student', async () => {
    const shut = await mountPanel(null, []);
    assert.equal(shut.text(), '');
    shut.unmount();

    const r = roster();
    const asked: any[] = [];
    const screen = await mountPanel(r.student, r.rows, {
        onClose: () => asked.push('close'),
        onWithdraw: (m: any) => asked.push(['withdraw', m.id]),
        'onSave-grade': (m: any, value: string) => asked.push(['grade', m.id, value]),
    });

    const dialog = screen.all((n: Node) => n.props.role === 'dialog')[0];
    assert.equal(dialog.props['aria-modal'], 'true');
    dialog.props.onKeydown({ key: 'Escape', stopPropagation() {} });
    click(screen.all((n: Node) => n.props['aria-label'] === 'Close student details')[0]);
    assert.deepEqual(asked, ['close', 'close']);

    click(screen.button('Left the class'));
    assert.deepEqual(asked[2], ['withdraw', r.student.id]);

    // The grade is the roster row's own field, saved by the roster's own writer.
    const grade = screen.all((n: Node) => n.tag === 'input')[0];
    assert.equal(grade.value, '3rd');
    grade.props.onChange({ target: { value: '4th' } });
    assert.deepEqual(asked[3], ['grade', r.student.id, '4th']);

    // "Open full record": the Member Directory, with this person's id and nothing else.
    const full = screen.all((n: Node) => n.textContent.includes('Open full record') && 'to' in n.props)[0];
    assert.deepEqual(full.props.to, { name: 'masjid.contacts', query: { contact: '1' } });
    screen.unmount();
});

test('panel: while the grade saves its field is read-only, never disabled, so the keyboard stays in the panel', async () => {
    const r = roster();
    const screen = await mountPanel(r.student, r.rows, { savingGrade: true });
    const grade = screen.all((n: Node) => n.tag === 'input')[0];

    // A disabled field loses focus to the page behind the dialog, and Escape stops closing it.
    assert.equal(grade.disabled, false);
    assert.equal(grade.props.readonly, true);
    assert.equal(grade.props['aria-busy'], 'true');
    screen.unmount();

    const idle = await mountPanel(r.student, r.rows);
    const field = idle.all((n: Node) => n.tag === 'input')[0];
    assert.notEqual(field.props.readonly, true);
    assert.equal(field.props['aria-busy'], undefined);
    idle.unmount();
});

test('panel: the mount points for Move and for the date of birth are named slots, each handed the student', () => {
    const panel = read('../views/dashboard/groups/StudentDetailsPanel.vue');

    for (const name of ['moved-badges', 'moved-from', 'age', 'birth-date', 'move']) {
        assert.match(panel, new RegExp(`<slot name="${name}" :student="student">`), `slot #${name}`);
    }
    // The panel itself asks the server nothing: everything on it is on the roster already.
    assert.doesNotMatch(panel, /ApiService|axios|fetch\(/);
});

// =================================================================== the roster, mounted

/** What the roster's server answer says about the group: a class, or not. */
const CLASS_META = { teaches_students: true, move_note: null, school_today: '2026-10-04', group_name: 'Third Grade' };

async function mountRoster(memberships: any[], options: { meta?: any; swal?: (o: any) => Promise<any>; api?: any; store?: any } = {}) {
    const panel: { student: any; memberships: any[]; emit: any } = { student: null, memberships: [], emit: null };
    // The panel, standing in: it records what it was given and draws the five mount points the
    // real one has, each handed the student, so what the roster puts in them is on the screen.
    const panelStub = {
        props: ['student', 'memberships', 'savingGrade'],
        emits: ['close', 'save-grade', 'withdraw'],
        setup(props: any, { emit, slots }: any) {
            panel.emit = emit;
            return () => {
                panel.student = props.student;
                panel.memberships = props.memberships;
                if (!props.student) return null;

                return vue.h('section', { 'data-panel': 'open' }, ['moved-badges', 'moved-from', 'age', 'birth-date', 'move']
                    .map((name) => vue.h('div', { 'data-slot': name }, slots[name]?.({ student: props.student }))));
            };
        },
    };
    // The move dialog and the date form, standing in: each records the props it was mounted with.
    const moveDialog: { membership: any } = { membership: null };
    const moveStub = {
        props: ['groupId', 'groupName', 'membership', 'schoolToday'],
        setup(props: any) {
            vue.onUnmounted(() => { moveDialog.membership = null; });
            return () => { moveDialog.membership = props.membership; return vue.h('div', { 'data-move-dialog': props.membership.id }); };
        },
    };
    const birthForm: { props: any; emit: any } = { props: null, emit: null };
    const birthStub = {
        props: ['masjidId', 'groupId', 'membershipId', 'contactId'],
        emits: ['changed'],
        setup(props: any, { emit }: any) {
            birthForm.emit = emit;
            return () => { birthForm.props = { ...props }; return vue.h('div', { 'data-birth-form': props.membershipId }); };
        },
    };
    const store = { groupsMeta: null, pendingClaims: 0, contestedClaims: 0, rosterMeta: options.meta ?? null, ...(options.store ?? {}) };
    const api = { put: async () => ({ data: { status: 'success' } }), get: async () => ({ data: { data: [] } }), ...(options.api ?? {}) };

    const screen = await mountSfc('views/dashboard/groups/GroupRosterTab.vue', { groupId: CLASS, memberships, loading: false, loadError: '' }, {
        vue: vueInPlace,
        axios: {},
        'vue-router': { useRouter: () => ({ resolve: () => ({ href: '' }) }) },
        '@/core/services/ApiService': { default: api },
        '@/components/common/PersonAvatar.vue': { default: avatarStub },
        '@/components/common/AvatarPicker.vue': { default: avatarStub },
        './StudentDetailsPanel.vue': { default: panelStub },
        './MoveStudentModal.vue': { default: moveStub },
        './PutBackDialog.vue': { default: avatarStub },
        './StudentBirthDateForm.vue': { default: birthStub },
        '@/core/types/config/BackendApiRoutes': {},
        '@/core/types/data/masjid-related/Contact': {},
        '@/core/types/data/masjid-related/Group': {},
        '@/stores/masjid/groupsStore': { useGroupsStore: () => store },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 }, term: (key: string) => key }) },
        '@/core/services/ApiErrors': ApiErrors,
        '@/core/helpers/studentDetails': helper,
        '@/core/helpers/rosterMove': rosterMove,
        '@/core/helpers/studentAge': studentAge,
        sweetalert2: { default: { fire: options.swal ?? (async () => ({})) } },
    });
    await flush();

    const nameButtons = () => screen.all((n: Node) => n.tag === 'button' && n.props.title === 'Open student details');
    const slot = (name: string): Node | null => screen.all((n: Node) => n.props['data-slot'] === name)[0] ?? null;

    return { screen, panel, nameButtons, slot, moveDialog, birthForm };
}

test('roster: a student\'s name is a button that opens their details; a teacher row\'s name is not', async () => {
    const r = roster();
    const leader = row({ contact: person(30, 'Teacher', 'Row'), role: 'leader' });
    const { screen, panel, nameButtons } = await mountRoster(vue.reactive([...r.rows, leader]));

    assert.equal(panel.student, null, 'shut until a name is tapped');

    // Two students in the participants table, and the child's name on each of the six guardian rows.
    const buttons = nameButtons();
    assert.deepEqual(
        buttons.map((b: Node) => b.textContent),
        ['Maryam Testwood', 'Yahya Testwood', 'Maryam Testwood', 'Maryam Testwood', 'Maryam Testwood', 'Maryam Testwood', 'Yahya Testwood', 'Yahya Testwood'],
    );
    assert.ok(buttons.every((b: Node) => b.props.type === 'button'));
    assert.equal(buttons.filter((b: Node) => b.textContent.includes('Teacher Row')).length, 0);
    assert.match(screen.text(), /Teacher Row/, 'the teacher row is still listed, as text');

    click(buttons[0]);
    await flush();
    assert.equal(panel.student?.id, r.student.id);
    assert.equal(panel.memberships.length, r.rows.length + 1, 'the panel reads the whole roster, guardians included');

    panel.emit('close');
    await flush();
    assert.equal(panel.student, null);

    // The child's name on the aunt's guardian row opens the BROTHER's details.
    click(buttons[7]);
    await flush();
    assert.equal(panel.student?.id, r.sibling.id);
    screen.unmount();
});

test('roster: the panel follows a reload, and shuts when the student is no longer on the roster', async () => {
    const r = roster();
    const memberships = vue.reactive([...r.rows]);
    const { screen, panel, nameButtons } = await mountRoster(memberships);

    click(nameButtons()[0]);
    await flush();
    assert.equal(panel.student?.grade_label, '3rd');

    // A reload replaces every row object. The panel shows the NEW row, not the one it was opened on.
    const reloaded = roster();
    reloaded.rows.forEach((fresh, i) => { fresh.id = r.rows[i].id; });
    reloaded.student.grade_label = '4th';
    memberships.splice(0, memberships.length, ...reloaded.rows);
    await flush();
    assert.equal(panel.student?.grade_label, '4th');

    // Removed by a colleague meanwhile: the reload has no such row, and the panel shuts.
    memberships.splice(0, 1);
    await flush();
    assert.equal(panel.student, null);
    screen.unmount();
});

test('roster: "Left the class" from the panel shuts it and opens the roster\'s own dialog for that student', async () => {
    const r = roster();
    const { screen, panel, nameButtons } = await mountRoster(vue.reactive([...r.rows]));

    click(nameButtons()[0]);
    await flush();
    const dateFields = () => screen.all((n: Node) => n.tag === 'input' && n.props.id === 'left-on').length;
    assert.equal(dateFields(), 0, 'the leaving dialog is shut while the panel is read');

    panel.emit('withdraw', panel.student);
    await flush();

    assert.equal(panel.student, null, 'one dialog at a time');
    // The withdrawal dialog's own date field is now drawn, and the dialog names Maryam.
    assert.equal(dateFields(), 1);
    assert.match(screen.text(), /Maryam Testwood has left this class\./);
    screen.unmount();
});

// =================================================================== the roster: Move, ages and the date of birth
//
// The three features meet on this one screen. What is proved here is the wiring: which group gets
// an Age column, what the panel's mount points are filled with, and that the two dialogs are never
// up together.

const cells = (screen: any, tag: string): string[] => screen.all((n: Node) => n.tag === tag).map((n: Node) => n.textContent);

test('roster: a class shows an Age column, a number or a dash, and says how many dates are missing', async () => {
    const r = roster();
    r.student.age = 8;
    r.sibling.age = null;
    const leader = row({ contact: person(30, 'Teacher', 'Row'), role: 'leader' });
    const gone = row({ contact: person(31, 'Left', 'Already'), left_on: '2026-09-28T00:00:00.000000Z' });
    const { screen } = await mountRoster(vue.reactive([...r.rows, leader, gone]), { meta: CLASS_META });

    // Between Grade and Joined, as the design places it.
    assert.deepEqual(cells(screen, 'th').slice(0, 6), ['Member', 'Role', 'Grade', 'Age', 'Joined', 'Actions']);

    const firstRow = screen.all((n: Node) => n.tag === 'tr')[1];
    assert.equal(firstRow.children.filter((c: Node) => c.tag === 'td')[3].textContent, '8');
    const secondRow = screen.all((n: Node) => n.tag === 'tr')[2];
    assert.equal(secondRow.children.filter((c: Node) => c.tag === 'td')[3].textContent, '—');

    // One CURRENT student has no age: the brother. The teacher row and the student who left are not counted.
    assert.match(screen.text(), /1 student has no date of birth on file, so no age is shown for them\. Tap their name to add it\./);
    screen.unmount();
});

test('roster: a group that is not a class has no Age column and no line about dates', async () => {
    const r = roster();
    const { screen } = await mountRoster(vue.reactive([...r.rows]), {
        meta: { ...CLASS_META, teaches_students: false },
    });

    assert.deepEqual(cells(screen, 'th').slice(0, 5), ['Member', 'Role', 'Grade', 'Joined', 'Actions']);
    assert.doesNotMatch(screen.text(), /date of birth/);
    screen.unmount();

    // The same when the server sent no meta at all (an older answer): nothing is guessed.
    const bare = await mountRoster(vue.reactive([...roster().rows]));
    assert.deepEqual(cells(bare.screen, 'th').slice(0, 5), ['Member', 'Role', 'Grade', 'Joined', 'Actions']);
    bare.screen.unmount();
});

test('roster: in a class the panel carries the age, the date-of-birth form for that student, and Move', async () => {
    const r = roster();
    r.student.age = 8;
    const memberships = vue.reactive([...r.rows]);
    const { screen, panel, nameButtons, slot, moveDialog, birthForm } = await mountRoster(memberships, { meta: CLASS_META });

    click(nameButtons()[0]);
    await flush();

    assert.equal(slot('age')?.textContent, 'Age 8');
    // The form is named the student's roster row AND their contact: it clears by contact.
    assert.deepEqual(birthForm.props, { masjidId: 1, groupId: CLASS, membershipId: r.student.id, contactId: maryam.id });

    // A date was saved: the answer's age lands on the row, with no re-read of the roster.
    birthForm.emit('changed', { age: 9, held: true });
    await flush();
    assert.equal(memberships[0].age, 9);
    assert.equal(slot('age')?.textContent, 'Age 9');

    // A date was removed: the age goes, and the line above the table counts the student again.
    birthForm.emit('changed', { age: null, held: false });
    await flush();
    assert.equal(memberships[0].age, null);
    assert.equal(slot('age')?.textContent, '');
    assert.match(screen.text(), /2 students have no date of birth on file/);

    // Move, from the panel: the panel shuts and the move dialog opens for that student. One dialog at a time.
    assert.equal(moveDialog.membership, null);
    const move = slot('move')!.children.filter((c: Node) => c.tag === 'button');
    assert.equal(move.length, 1);
    assert.equal(move[0].textContent, 'Move');
    click(move[0]);
    await flush();
    assert.equal(panel.student, null);
    assert.equal(moveDialog.membership?.id, r.student.id);
    screen.unmount();
});

test('roster: the panel offers no Move, no age and no date form outside a class, and no Move for a student who left', async () => {
    const r = roster();
    r.student.age = 8;
    const outside = await mountRoster(vue.reactive([...r.rows]), { meta: { ...CLASS_META, teaches_students: false } });
    click(outside.nameButtons()[0]);
    await flush();
    assert.equal(outside.slot('move')?.textContent, '');
    assert.equal(outside.slot('age')?.textContent, '');
    assert.equal(outside.birthForm.props, null, 'the date form is not mounted');
    outside.screen.unmount();

    const left = roster();
    left.student.left_on = '2026-09-28T00:00:00.000000Z';
    const inClass = await mountRoster(vue.reactive([...left.rows]), { meta: CLASS_META });
    click(inClass.nameButtons()[0]);
    await flush();
    assert.equal(inClass.slot('move')?.textContent, '', 'a student who has left is not moved');
    assert.ok(inClass.birthForm.props, 'their date of birth can still be read and removed');
    inClass.screen.unmount();
});

test('roster: the panel shows the moved badge by the roster row\'s own rule, and "Moved from" under the class', async () => {
    // Moved away and still in the other class: "Moved to …" stands in for "Left …".
    const away = roster();
    Object.assign(away.student, {
        left_on: '2026-10-01T00:00:00.000000Z', moved_on: '2026-10-02', moved_to_group_id: 9, moved_from_group_id: null,
        moved_to: { id: 9, name: 'Fourth Grade', deleted_at: null },
        moved_to_state: { student_there: 'current', guardians_not_vouched: [], open_group: { id: 9, name: 'Fourth Grade' } },
    });
    const a = await mountRoster(vue.reactive([...away.rows]), { meta: CLASS_META });
    click(a.nameButtons()[0]);
    await flush();
    assert.equal(a.slot('moved-badges')?.textContent, 'Moved to Fourth Grade 2 Oct 2026');
    assert.equal(a.slot('moved-from')?.textContent, '');
    a.screen.unmount();

    // Moved away and no longer there: the ordinary "Left …", and the line that says why.
    away.student.moved_to_state.student_there = 'left';
    const b = await mountRoster(vue.reactive([...away.rows]), { meta: CLASS_META });
    click(b.nameButtons()[0]);
    await flush();
    assert.equal(b.slot('moved-badges')?.textContent, 'Left 1 Oct 2026 Was moved to Fourth Grade on 2 Oct 2026. No longer there.');
    b.screen.unmount();

    // Moved IN: no badge, and the line under "In this class".
    const arrived = roster();
    Object.assign(arrived.student, {
        moved_on: '2026-10-02', moved_from_group_id: 8, moved_to_group_id: null,
        moved_from: { id: 8, name: 'Second Grade', deleted_at: null },
    });
    const c = await mountRoster(vue.reactive([...arrived.rows]), { meta: CLASS_META });
    click(c.nameButtons()[0]);
    await flush();
    assert.equal(c.slot('moved-badges')?.textContent, '');
    assert.equal(c.slot('moved-from')?.textContent, 'Moved from Second Grade 2 Oct 2026');
    c.screen.unmount();

    // An ordinary student who left: the plain badge, as before the move existed.
    const plain = roster();
    plain.student.left_on = '2026-09-28T00:00:00.000000Z';
    const d = await mountRoster(vue.reactive([...plain.rows]), { meta: CLASS_META });
    click(d.nameButtons()[0]);
    await flush();
    assert.equal(d.slot('moved-badges')?.textContent, 'Left 28 Sep 2026');
    d.screen.unmount();
});

test('roster: Remove says a date of birth is still on the record, and clears it only when the office chooses to', async () => {
    const sentence = 'Removed from the roster. Their date of birth is still on their record.';

    for (const chose of ['keep', 'clear'] as const) {
        const r = roster();
        const asked: any[] = [];
        const deleted: string[] = [];
        const { screen } = await mountRoster(vue.reactive([r.sibling]), {
            meta: CLASS_META,
            store: {
                removeMembershipAnswer: async (_group: number, id: number) => {
                    asked.push(['remove', id]);
                    return { message: sentence, birthDateContactId: yahya.id };
                },
            },
            api: { delete: async (url: string) => { deleted.push(url); return { data: { status: 'success', message: 'Date of birth removed.' } }; } },
            swal: async (o: any) => {
                asked.push(o.title + (o.denyButtonText ? ` [${o.denyButtonText}]` : ''));
                if (o.title === 'Remove from roster?') return { isConfirmed: true };
                if (o.denyButtonText) {
                    assert.equal(o.text, sentence, 'the server\'s sentence is what the office reads');
                    return chose === 'clear' ? { isDenied: true } : { isConfirmed: true };
                }
                return {};
            },
        });

        click(screen.all((n: Node) => n.tag === 'button' && n.props.title === 'Remove')[0]);
        await flush();
        await flush();

        assert.deepEqual(asked.slice(0, 3), ['Remove from roster?', ['remove', r.sibling.id], 'Removed [Remove the date of birth]']);
        assert.deepEqual(deleted, chose === 'clear' ? [`/api/admin/masjids/1/contacts/${yahya.id}/birth-date`] : []);
        if (chose === 'clear') assert.equal(asked[3], 'Date of birth removed.');
        screen.unmount();
    }
});

test('roster: Remove with no date left behind offers nothing to clear', async () => {
    const r = roster();
    const asked: any[] = [];
    const { screen } = await mountRoster(vue.reactive([r.sibling]), {
        meta: CLASS_META,
        store: { removeMembershipAnswer: async () => ({ message: 'Removed from the roster.', birthDateContactId: null }) },
        api: { delete: async () => { throw new Error('no DELETE is sent'); } },
        swal: async (o: any) => { asked.push(o); return o.title === 'Remove from roster?' ? { isConfirmed: true } : {}; },
    });

    click(screen.all((n: Node) => n.tag === 'button' && n.props.title === 'Remove')[0]);
    await flush();
    await flush();

    assert.equal(asked.length, 2);
    assert.equal(asked[1].showDenyButton, undefined);
    assert.equal(asked[1].title, 'Removed');
    screen.unmount();
});

test('panel: a key pressed after focus fell out of the panel still closes it, and Tab comes back in', () => {
    const panel = read('../views/dashboard/groups/StudentDetailsPanel.vue');

    // Listened for on the document only while the panel is open, and taken off when it shuts or goes.
    assert.match(panel, /document\.addEventListener\?\.\('keydown', onStrayKeydown\);/);
    assert.equal(panel.match(/document\.removeEventListener\?\.\('keydown', onStrayKeydown\)/g)?.length, 2);
    // It leaves a key pressed inside the panel, or under a message box, alone.
    assert.match(panel, /if \(!props\.student \|\| document\.querySelector\?\.\('\.swal2-container'\)\) return;/);
    assert.match(panel, /if \(active instanceof HTMLElement && root\.value\?\.contains\(active\)\) return;/);
});
