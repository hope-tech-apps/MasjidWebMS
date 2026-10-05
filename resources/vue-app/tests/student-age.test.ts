/**
 * Ages on rosters, the teacher's student sheet, and the office's date-of-birth form.
 *
 * Three layers, each for what only it can show:
 *  - the WORDS (core/helpers/studentAge.ts), as plain functions;
 *  - the two components, MOUNTED from their .vue files (tests/support/mountSfc.ts), with the API
 *    answering what the server answers: a refusal has to be SAID, a double tap has to send once;
 *  - TeacherClass.vue as source text, because it is far too large to mount: the Roster row is one
 *    button, and nothing on it or in the sheet can print a guardian.
 *
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import * as ApiErrors from '../core/services/ApiErrors.ts';
import * as studentAge from '../core/helpers/studentAge.ts';
import { click, deferred, flush, httpError, mountSfc, press, submit, type } from './support/mountSfc.ts';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = (rel: string) => readFileSync(path.join(appRoot, rel), 'utf8');

const {
    AGE_GIVEN_MARK, AGE_GIVEN_TITLE, AGE_NOT_ON_FILE, BIRTH_DATE_MIN, EMERGENCY_LINE, ageCell, ageLabel, ageWasGiven,
    birthDateMax, birthDateProblem, birthDateWords, missingBirthDatesLine, sheetAgeLine, studentSheetModel,
    studentsMissingAnAge, studentsWithAGivenAge,
} = studentAge;

// ------------------------------------------------------------------ the words

test('a row says "Age 7", and nothing at all when the age is unknown', () => {
    assert.equal(ageLabel(7), 'Age 7');
    assert.equal(ageLabel(0), 'Age 0');
    for (const unknown of [null, undefined, '', '7', 7.5, -1, NaN, {}]) {
        assert.equal(ageLabel(unknown), '', `ageLabel(${JSON.stringify(unknown)})`);
    }
});

test('a table cell says the number, or a dash', () => {
    assert.equal(ageCell(11), '11');
    assert.equal(ageCell(null), '—');
    assert.equal(ageCell(undefined), '—');
});

test('the sheet says the age, or who can add it', () => {
    assert.equal(sheetAgeLine(7), 'Age 7');
    assert.equal(sheetAgeLine(null), 'Age not on file. The school office can add a date of birth.');
    assert.equal(AGE_NOT_ON_FILE, 'Age not on file. The school office can add a date of birth.');
    assert.equal(EMERGENCY_LINE, "In an emergency, contact the school office. The office holds each family's phone numbers.");
});

test('the office is told how many current students have no date of birth', () => {
    const rows = [
        { role: 'member', left_on: null, age: 7 },
        { role: 'member', left_on: null, age: null },
        { role: 'member', left_on: null },                    // the key absent reads as no age
        { role: 'member', left_on: '2026-09-30', age: null }, // has left: not asked about
        { role: 'guardian', left_on: null, age: null },
        { role: 'leader', left_on: null, age: null },
    ];

    assert.equal(studentsMissingAnAge(rows), 2);
    assert.equal(
        missingBirthDatesLine(rows),
        '2 students have no date of birth on file, so no age is shown for them. Tap a name to add it.',
    );
    assert.equal(
        missingBirthDatesLine(rows.slice(0, 2)),
        '1 student has no date of birth on file, so no age is shown for them. Tap their name to add it.',
    );
    assert.equal(missingBirthDatesLine([{ role: 'member', left_on: null, age: 9 }]), '');
    assert.equal(missingBirthDatesLine([]), '');
    assert.equal(missingBirthDatesLine(null), '');
    // A group that is not a class shows no age for anybody, so it asks for no dates.
    assert.equal(missingBirthDatesLine(rows, false), '');
});

test('an age the family gave is said to be that, and only when there is an age and the server says so', () => {
    assert.equal(ageLabel(6, true), 'Age 6, as the family gave it at registration');
    assert.equal(ageLabel(6, false), 'Age 6');
    assert.equal(ageLabel(6), 'Age 6');
    // Only the server's own `true` counts: anything else reads as an exact age.
    for (const notTrue of [null, undefined, 1, 'true', {}]) assert.equal(ageLabel(6, notTrue), 'Age 6');
    assert.equal(ageLabel(null, true), '');

    assert.equal(ageWasGiven({ age: 6, age_given: true }), true);
    assert.equal(ageWasGiven({ age: 6, age_given: false }), false);
    assert.equal(ageWasGiven({ age: 6 }), false);
    assert.equal(ageWasGiven({ age: null, age_given: true }), false, 'no age, nothing to mark');
    assert.equal(ageWasGiven(null), false);

    assert.equal(AGE_GIVEN_MARK, 'given');
    assert.match(AGE_GIVEN_TITLE, /family gave at registration/);
    // The cell keeps the bare number: the mark is drawn beside it, not inside it.
    assert.equal(ageCell(6), '6');
});

test('the line above the roster says which ages families gave, beside how many have none', () => {
    const given = { role: 'member', left_on: null, age: 6, age_given: true };
    const exact = { role: 'member', left_on: null, age: 9, age_given: false };
    const none = { role: 'member', left_on: null, age: null, age_given: false };
    const left = { role: 'member', left_on: '2026-09-30', age: 7, age_given: true };

    assert.equal(studentsWithAGivenAge([given, given, exact, none, left]), 2, 'a student who left is not counted');
    assert.equal(
        missingBirthDatesLine([given, exact]),
        '1 age marked "given" is the one the family gave at registration. Tap the name to add a date of birth for an exact age.',
    );
    assert.equal(
        missingBirthDatesLine([given, given, exact]),
        '2 ages marked "given" are the ones families gave at registration. Tap a name to add a date of birth for an exact age.',
    );
    // Both things are true of one class: said one after the other.
    assert.equal(
        missingBirthDatesLine([given, none]),
        '1 student has no date of birth on file, so no age is shown for them. Tap their name to add it. '
            + '1 age marked "given" is the one the family gave at registration. Tap the name to add a date of birth for an exact age.',
    );
    // Every age exact: nothing to say. Not a class: nothing at all.
    assert.equal(missingBirthDatesLine([exact]), '');
    assert.equal(missingBirthDatesLine([given, none], false), '');
});

test('the sheet model is the avatar, the name, the grade and the age, and no other key', () => {
    const model = studentSheetModel({
        membership_id: 12,
        grade_label: '2nd',
        age: 7,
        contact: { id: 3, first_name: 'Idris', last_name: 'Marlowe', avatar: { character: 'fox' } },
        // What a later payload change might add. None of it may reach the sheet.
        guardians: [{ name: 'Zubaydah Quillfeather', phone: '+15559998888' }],
        date_of_birth: '2017-03-09',
        email: 'someone@example.test',
    } as any);

    assert.deepEqual(Object.keys(model).sort(), ['age', 'avatar', 'grade', 'name']);
    assert.deepEqual(model, { avatar: { character: 'fox' }, name: 'Idris Marlowe', grade: '2nd', age: 7 });
    assert.doesNotMatch(JSON.stringify(model), /Quillfeather|5559998888|2017-03-09|example\.test/);

    assert.deepEqual(studentSheetModel(null), { avatar: null, name: 'Student', grade: '', age: null });
    assert.equal(studentSheetModel({ membership_id: 1, age: '7' } as any).age, null, 'only a whole number is an age');
});

test("the date field's latest day is the school's today, as the server says it", () => {
    assert.equal(birthDateMax('2026-10-06'), '2026-10-06');
    // Before the server has answered: the browser's own day, by its own calendar.
    assert.equal(birthDateMax(null, new Date(2026, 9, 6, 23, 30)), '2026-10-06');
    assert.equal(birthDateMax('not a day', new Date(2026, 0, 5, 9, 0)), '2026-01-05');
    assert.equal(BIRTH_DATE_MIN, '1900-01-02');
});

test('a day that the server would refuse is refused before it is sent', () => {
    assert.equal(birthDateProblem('2017-03-09', '2026-10-06'), '');
    assert.equal(birthDateProblem('2026-10-06', '2026-10-06'), '');
    assert.equal(birthDateProblem('2026-10-07', '2026-10-06'), 'The date of birth cannot be in the future.');
    assert.equal(birthDateProblem('1900-01-01', '2026-10-06'), 'The date of birth cannot be before 1900.');
    assert.equal(birthDateProblem('', '2026-10-06'), 'Enter the date of birth.');
    assert.equal(birthDateProblem('09/03/2017', '2026-10-06'), 'Enter the date of birth.');
});

test('a date is written in words without the browser moving the day', () => {
    assert.equal(birthDateWords('2017-03-09'), 'March 9, 2017');
    assert.equal(birthDateWords('2016-01-01'), 'January 1, 2016');
    assert.equal(birthDateWords(null), '');
});

// ------------------------------------------------------------------ the office's form, mounted

const ok = (data: any, message?: string) => ({ data: { status: 'success', ...(message ? { message } : {}), data } });
const answer = (over: Record<string, any> = {}) => ({ date_of_birth: null, age: null, unreadable: false, school_today: '2026-10-06', ...over });

const ROW_URL = '/api/admin/masjids/4/groups/9/members/12/birth-date';
const CONTACT_URL = '/api/admin/masjids/4/contacts/3/birth-date';

function officeApi(over: { get?: (url: string) => Promise<any>; put?: (url: string, body: any) => Promise<any>; delete?: (url: string) => Promise<any> } = {}) {
    const calls: Array<{ verb: string; url: string; body?: any }> = [];
    const api = {
        get: (url: string) => { calls.push({ verb: 'get', url }); return over.get ? over.get(url) : Promise.resolve(ok(answer())); },
        put: (url: string, body: any) => { calls.push({ verb: 'put', url, body }); return over.put ? over.put(url, body) : Promise.resolve(ok(answer({ date_of_birth: body.date_of_birth, age: 9 }), 'Date of birth saved.')); },
        delete: (url: string) => { calls.push({ verb: 'delete', url }); return over.delete ? over.delete(url) : Promise.resolve(ok({ date_of_birth: null, age: null, unreadable: false }, 'Date of birth removed.')); },
    };

    return { api, calls };
}

async function mountForm(api: any, props: Record<string, any> = {}) {
    const changed: any[] = [];
    const screen = await mountSfc('views/dashboard/groups/StudentBirthDateForm.vue', {
        masjidId: 4, groupId: 9, membershipId: 12, contactId: 3,
        // A function prop, not a listener: it has to run after the form is gone too.
        afterChange: (payload: any) => changed.push(payload),
        ...props,
    }, {
        '@/core/services/ApiService': { default: api },
        '@/core/services/ApiErrors': ApiErrors,
        '@/core/helpers/studentAge': studentAge,
    });
    await flush();

    return { screen, changed };
}

const dateInput = (screen: any) => screen.all((n: any) => n.tag === 'input')[0];

test('form: with no date on file it offers to add one, and reads the student by roster row', async () => {
    const { api, calls } = officeApi();
    const { screen } = await mountForm(api);

    assert.deepEqual(calls, [{ verb: 'get', url: ROW_URL }]);
    assert.match(screen.text(), /Date of birth/);
    assert.match(screen.text(), /Not on file/);
    assert.match(screen.text(), /Used only to show the student's age on class lists\. Only the office sees the date: here, and in the school records export\./);
    assert.ok(screen.button('Add date of birth'));
    assert.equal(screen.all((n: any) => n.tag === 'button' && n.textContent === 'Remove').length, 0, 'nothing to remove');
});

test('form: a date on file is shown in words, with Change and Remove', async () => {
    const { api } = officeApi({ get: async () => ok(answer({ date_of_birth: '2017-03-09', age: 9 })) });
    const { screen } = await mountForm(api);

    assert.match(screen.text(), /March 9, 2017/);
    assert.ok(screen.button('Change'));
    assert.ok(screen.button('Remove'));
});

test('form: nothing is drawn for a login that may not read the date, a row that is not a student, or a row that is gone', async () => {
    for (const status of [403, 422, 404]) {
        const { api } = officeApi({ get: () => Promise.reject(httpError(status, { status: 'error', message: 'No.' })) });
        const { screen } = await mountForm(api);

        assert.equal(screen.text(), '', `a ${status} draws nothing`);
    }
});

test('form: any other failure to load is said, with a way to try again', async () => {
    let attempt = 0;
    const { api, calls } = officeApi({
        get: () => (++attempt === 1
            ? Promise.reject(httpError(503, { status: 'error', message: 'Dates of birth are being switched on. Try again in a minute.' }))
            : Promise.resolve(ok(answer({ date_of_birth: '2017-03-09', age: 9 })))),
    });
    const { screen } = await mountForm(api);

    assert.match(screen.text(), /Dates of birth are being switched on\. Try again in a minute\./);

    click(screen.button('Try again'));
    await flush();

    assert.equal(calls.filter((c) => c.verb === 'get').length, 2);
    assert.match(screen.text(), /March 9, 2017/);
});

test("form: the date field's latest day is the school's today from the server, and its earliest is 1900", async () => {
    const { api } = officeApi({ get: async () => ok(answer({ school_today: '2026-10-05' })) });
    const { screen } = await mountForm(api);

    click(screen.button('Add date of birth'));
    await flush();

    const input = dateInput(screen);
    assert.equal(input.props.type, 'date');
    assert.equal(input.props.max, '2026-10-05');
    assert.equal(input.props.min, '1900-01-02');
});

test('form: saving sends one PUT with the day, says it was saved, and tells the roster the new age', async () => {
    const { api, calls } = officeApi();
    const { screen, changed } = await mountForm(api);

    click(screen.button('Add date of birth'));
    await flush();
    type(dateInput(screen), '2017-03-09');
    await flush();
    submit(screen.all((n: any) => n.tag === 'form')[0]);
    await flush();

    assert.deepEqual(calls.filter((c) => c.verb === 'put'), [{ verb: 'put', url: ROW_URL, body: { date_of_birth: '2017-03-09' } }]);
    assert.match(screen.text(), /March 9, 2017/);
    assert.match(screen.text(), /Date of birth saved\./);
    assert.deepEqual(changed, [{ membershipId: 12, age: 9, given: false, held: true }]);
    assert.equal(screen.all((n: any) => n.tag === 'form').length, 0, 'the form closed');
});

test('form: a second tap while the first save is on its way sends nothing more', async () => {
    const pending = deferred();
    const { api, calls } = officeApi({ put: () => pending.promise });
    const { screen, changed } = await mountForm(api);

    click(screen.button('Add date of birth'));
    await flush();
    type(dateInput(screen), '2017-03-09');
    await flush();

    // Twice before the screen has had a chance to disable anything, and once after.
    const form = screen.all((n: any) => n.tag === 'form')[0];
    submit(form);
    submit(form);
    await flush();
    submit(form);
    await flush();

    assert.equal(calls.filter((c) => c.verb === 'put').length, 1);
    assert.ok(screen.button('Saving').disabled);

    pending.resolve(ok(answer({ date_of_birth: '2017-03-09', age: 9 }), 'Date of birth saved.'));
    await flush();

    assert.deepEqual(changed, [{ membershipId: 12, age: 9, given: false, held: true }]);
});

test('form: a date saved after the panel was closed still reaches the roster row it was saved for', async () => {
    // A slow connection: Save, then the panel is closed (or Escape) straight away. That unmounts
    // the form, and an emit from an unmounted component is dropped, so the server had the date and
    // the row kept its dash. The roster is told through a function, which still runs.
    const pending = deferred();
    const { api } = officeApi({ put: () => pending.promise });
    const { screen, changed } = await mountForm(api);

    click(screen.button('Add date of birth'));
    await flush();
    type(dateInput(screen), '2017-03-09');
    await flush();
    submit(screen.all((n: any) => n.tag === 'form')[0]);
    await flush();

    screen.unmount();
    pending.resolve(ok(answer({ date_of_birth: '2017-03-09', age: 9 }), 'Date of birth saved.'));
    await flush();

    assert.deepEqual(changed, [{ membershipId: 12, age: 9, given: false, held: true }]);
});

test('form: a date removed after the panel was closed still reaches the roster row', async () => {
    const pending = deferred();
    const { api } = officeApi({ get: async () => ok(answer({ date_of_birth: '2017-03-09', age: 9 })), delete: () => pending.promise });
    const { screen, changed } = await mountForm(api);

    click(screen.button('Remove'));
    await flush();
    click(screen.button('Yes, remove it'));
    await flush();

    screen.unmount();
    pending.resolve(ok({ date_of_birth: null, age: null, unreadable: false }, 'Date of birth removed.'));
    await flush();

    assert.deepEqual(changed, [{ membershipId: 12, age: null, given: false, held: false }]);
});

test("form: after the date is removed the row re-reads itself, and shows the family's age again when there is one", async () => {
    // The clear answers the same for every contact (it must not say what somebody who is not a
    // student holds), so what the row shows now is read by ROSTER ROW.
    let cleared = false;
    const { api, calls } = officeApi({
        get: async () => (cleared
            ? ok(answer({ date_of_birth: null, age: 6, age_given: true }))
            : ok(answer({ date_of_birth: '2017-03-09', age: 9 }))),
        delete: async () => { cleared = true; return ok({ date_of_birth: null, age: null, age_given: false, unreadable: false }, 'Date of birth removed.'); },
    });
    const { screen, changed } = await mountForm(api);

    click(screen.button('Remove'));
    await flush();
    click(screen.button('Yes, remove it'));
    await flush();
    await flush();

    // Told at once that the date is gone, then what the roster shows instead: not a dash.
    assert.deepEqual(changed, [
        { membershipId: 12, age: null, given: false, held: false },
        { membershipId: 12, age: 6, given: true, held: false },
    ]);
    // By roster row, never by contact.
    assert.deepEqual(calls.filter((c) => c.verb === 'get').map((c) => c.url), [ROW_URL, ROW_URL]);
    screen.unmount();
});

test('form: after the date is removed a student with no age from their family keeps the dash, and a failed re-read is silent', async () => {
    let cleared = false;
    const { api } = officeApi({
        get: async () => {
            if (cleared) throw httpError(500, {});

            return ok(answer({ date_of_birth: '2017-03-09', age: 9 }));
        },
        delete: async () => { cleared = true; return ok({ date_of_birth: null, age: null, age_given: false, unreadable: false }, 'Date of birth removed.'); },
    });
    const { screen, changed } = await mountForm(api);

    click(screen.button('Remove'));
    await flush();
    click(screen.button('Yes, remove it'));
    await flush();
    await flush();

    assert.deepEqual(changed, [{ membershipId: 12, age: null, given: false, held: false }]);
    assert.match(screen.text(), /Date of birth removed\./);
    assert.doesNotMatch(screen.text(), /could not/);
    screen.unmount();
});

test('form: the keyboard follows each step, because each step takes away the button just pressed', async () => {
    // What has focus, by what it says (the harness hands a ref a wrapped node, so not by identity).
    const focused = (): string => {
        const el = (globalThis as any).document.activeElement;

        return el?.tag === 'input' ? `input ${el.props.type}` : `${el?.tag} ${el?.textContent.trim()}`;
    };
    const { api } = officeApi();
    const { screen } = await mountForm(api);

    // Add: the field. Cancel: back on the button.
    click(screen.button('Add date of birth'));
    await flush();
    assert.equal(focused(), 'input date', 'Add left focus on a button that is gone');
    click(screen.button('Cancel'));
    await flush();
    assert.equal(focused(), 'button Add date of birth');

    // Save: the button again, now "Change".
    click(screen.button('Add date of birth'));
    await flush();
    type(dateInput(screen), '2017-03-09');
    await flush();
    submit(screen.all((n: any) => n.tag === 'form')[0]);
    await flush();
    assert.equal(focused(), 'button Change');

    // Remove asks, with focus on the answer that keeps the date; keeping it goes back to Change.
    click(screen.button('Remove'));
    await flush();
    assert.equal(focused(), 'button Keep it');
    click(screen.button('Keep it'));
    await flush();
    assert.equal(focused(), 'button Change');

    // Removed: "Add date of birth" is what is left to press.
    click(screen.button('Remove'));
    await flush();
    click(screen.button('Yes, remove it'));
    await flush();
    assert.equal(focused(), 'button Add date of birth');
    screen.unmount();
});

test('form: every control is a full button for a finger, "Try again" included', async () => {
    const form = source('views/dashboard/groups/StudentBirthDateForm.vue');

    // The panel is full screen on a phone; the same 44px rule its footer and the teacher's sheet have.
    assert.match(form, /@media \(max-width: 575\.98px\), \(pointer: coarse\) \{\s+\.student-birth-date \.btn,\s+\.student-birth-date \.form-control \{\s+min-height: 44px;/);
    assert.doesNotMatch(form, /btn-link/, 'a bare text link is no target for a thumb');

    const { api } = officeApi({ get: () => Promise.reject(httpError(500, {})) });
    const { screen } = await mountForm(api);
    assert.match(String(screen.button('Try again').props.class), /\bbtn-outline-secondary\b/);
    screen.unmount();
});

test('form: a day in the future is refused in the form and no request is sent', async () => {
    const { api, calls } = officeApi();
    const { screen, changed } = await mountForm(api);

    click(screen.button('Add date of birth'));
    await flush();
    type(dateInput(screen), '2026-10-07');
    await flush();
    submit(screen.all((n: any) => n.tag === 'form')[0]);
    await flush();

    assert.equal(calls.filter((c) => c.verb === 'put').length, 0);
    assert.match(screen.text(), /The date of birth cannot be in the future\./);
    assert.deepEqual(changed, []);
});

test("form: the server's refusal is shown in its own words and the form stays open with what was typed", async () => {
    const { api } = officeApi({
        put: () => Promise.reject(httpError(422, { status: 'failed', data: { date_of_birth: ['The date of birth cannot be in the future.'] } })),
    });
    const { screen, changed } = await mountForm(api);

    click(screen.button('Add date of birth'));
    await flush();
    type(dateInput(screen), '2026-10-06');
    await flush();
    submit(screen.all((n: any) => n.tag === 'form')[0]);
    await flush();

    assert.match(screen.text(), /The date of birth cannot be in the future\./);
    assert.equal(dateInput(screen).value, '2026-10-06');
    assert.ok(!screen.button('Save').disabled, 'it can be tried again');
    assert.deepEqual(changed, []);
});

test('form: Remove asks first, then clears by CONTACT with one request', async () => {
    const { api, calls } = officeApi({ get: async () => ok(answer({ date_of_birth: '2017-03-09', age: 9 })) });
    const { screen, changed } = await mountForm(api);

    click(screen.button('Remove'));
    await flush();

    assert.equal(calls.filter((c) => c.verb === 'delete').length, 0, 'asking is not removing');
    assert.match(screen.text(), /Remove the date of birth\? The class lists will stop showing an age\./);

    // "Keep it" backs out.
    click(screen.button('Keep it'));
    await flush();
    assert.equal(calls.filter((c) => c.verb === 'delete').length, 0);
    assert.match(screen.text(), /March 9, 2017/);

    click(screen.button('Remove'));
    await flush();
    const yes = screen.button('Yes, remove it');
    click(yes);
    press(yes);
    await flush();

    assert.deepEqual(calls.filter((c) => c.verb === 'delete'), [{ verb: 'delete', url: CONTACT_URL }]);
    assert.match(screen.text(), /Not on file/);
    assert.match(screen.text(), /Date of birth removed\./);
    assert.deepEqual(changed, [{ membershipId: 12, age: null, given: false, held: false }]);
});

test('form: a refused Remove is said, and the date is still shown', async () => {
    const { api } = officeApi({
        get: async () => ok(answer({ date_of_birth: '2017-03-09', age: 9 })),
        delete: () => Promise.reject(httpError(403, { message: 'This action is unauthorized.' })),
    });
    const { screen, changed } = await mountForm(api);

    click(screen.button('Remove'));
    await flush();
    click(screen.button('Yes, remove it'));
    await flush();

    assert.match(screen.text(), /This action is unauthorized\./);
    assert.match(screen.text(), /March 9, 2017/);
    assert.deepEqual(changed, []);
});

test('form: a stored date that cannot be read is said, and can be typed again or removed', async () => {
    const { api } = officeApi({ get: async () => ok(answer({ unreadable: true })) });
    const { screen } = await mountForm(api);

    assert.match(screen.text(), /The stored date cannot be read\. Enter it again\./);
    assert.doesNotMatch(screen.text(), /Not on file/);
    assert.ok(screen.button('Add date of birth'));
    assert.ok(screen.button('Remove'));
});

// The panel keeps one form mounted and changes its ids as the office moves from student to student.
// mountSfc mounts a component as the root, whose props cannot change afterwards, so the guard against
// a late answer landing under the next child's name is pinned as source text.
test('form: it re-reads when the student changes, and a late answer for the previous student is dropped', () => {
    const sfc = source('views/dashboard/groups/StudentBirthDateForm.vue');

    assert.match(sfc, /watch\(\(\) => \[props\.masjidId, props\.groupId, props\.membershipId, props\.contactId\], load\)/);
    assert.match(sfc, /const mine = \+\+asked;/);
    assert.equal((sfc.match(/if \(mine !== asked\) return;/g) ?? []).length, 6, 'the load, the save and the remove each check on both paths');
    // It keeps nothing in a store and reads no roster payload: four ids in, one event out.
    assert.doesNotMatch(sfc, /useGroupsStore|useMasjidStore|pinia/);
});

test('member directory: a merge that could not carry a date of birth says so', () => {
    const view = source('views/dashboard/ContactsView.vue');

    assert.match(view, /const birthDateNote: string = res\.data\?\.birth_date\?\.message \?\? '';/);
    // Every branch of the outcome dialog carries it: the sentence is the only time the office is told.
    assert.equal((view.match(/withBirthDate\(/g) ?? []).length, 4, 'the four dialogs that had no other warning to carry it');
    assert.match(view, /\[familyLoginOutcome\.message, rosterOutcome\?\.message, birthDateNote\]/);
});

// ------------------------------------------------------------------ the teacher's sheet, mounted

const pickerStub = {
    props: ['catalogueEndpoint', 'familyEndpoint'],
    emits: ['saved'],
    render(this: any) { return `PICKER ${this.catalogueEndpoint} ${this.familyEndpoint}`; },
};

// The sheet listens for keys on the document. mountSfc's document has no events, so this one does:
// the listeners the sheet adds are kept here, and a test presses a key by calling them.
const documentKeydown = new Set<(e: any) => void>();
const pressKey = (key: string) => [...documentKeydown].forEach((fn) => fn({ key, preventDefault() {}, shiftKey: false }));

async function mountSheet(student: any, props: Record<string, any> = {}) {
    const doc = (globalThis as any).document;
    doc.addEventListener = (name: string, fn: (e: any) => void) => { if (name === 'keydown') documentKeydown.add(fn); };
    doc.removeEventListener = (name: string, fn: (e: any) => void) => { if (name === 'keydown') documentKeydown.delete(fn); };

    const events: any[] = [];
    const screen = await mountSfc('views/teacher/TeacherStudentSheet.vue', {
        student, masjidId: 4, base: '/api/teacher/masjids/4/groups/9', isClass: true,
        onClose: () => events.push(['close']),
        onAvatarSaved: (s: any) => events.push(['avatar-saved', s]),
        ...props,
    }, {
        '@/components/common/PersonAvatar.vue': { default: { render: () => null } },
        '@/components/common/AvatarPicker.vue': { default: pickerStub },
        '@/core/helpers/focusTrap': { trapTab: () => {} },
        '@/core/helpers/studentAge': studentAge,
    });
    await flush();

    return { screen, events };
}

const idris = () => ({
    membership_id: 12, grade_label: '2nd', age: 7,
    contact: { id: 3, first_name: 'Idris', last_name: 'Marlowe', avatar: null },
});

test('sheet: it shows the name, the grade, the age and where to turn in an emergency', async () => {
    const { screen } = await mountSheet(idris());

    assert.match(screen.text(), /Idris Marlowe/);
    assert.match(screen.text(), /2nd/);
    assert.match(screen.text(), /Age 7/);
    assert.match(screen.text(), /In an emergency, contact the school office\. The office holds each family's phone numbers\./);
    assert.ok(screen.button('Change avatar'));
});

test('sheet: whatever the payload carries about a parent or a date, none of it is drawn', async () => {
    const { screen } = await mountSheet({
        ...idris(),
        guardians: [{ name: 'Zubaydah Quillfeather', first_name: 'Zubaydah', phone: '+15559998888', email: 'guardian.secret@example.test' }],
        contact: { ...idris().contact, guardians: [{ name: 'Zubaydah Quillfeather' }], email: 'idris.child@example.test', phone: '+15551230001' },
        date_of_birth: '2017-03-09',
    });

    assert.doesNotMatch(screen.text(), /Quillfeather|Zubaydah|5559998888|5551230001|example\.test|2017-03-09|Guardian|Parent:/);
});

test('sheet: with no age it says who can add one; in a group that is not a class it says nothing about age', async () => {
    const noAge = await mountSheet({ ...idris(), age: null });
    assert.match(noAge.screen.text(), /Age not on file\. The school office can add a date of birth\./);

    const circle = await mountSheet({ ...idris(), age: null, grade_label: null }, { isClass: false });
    assert.doesNotMatch(circle.screen.text(), /Age/);
    assert.match(circle.screen.text(), /Idris Marlowe/);
});

test('sheet: the avatar is changed inside the sheet, through the teacher realm, and the sheet stays open', async () => {
    const { screen, events } = await mountSheet(idris());

    click(screen.button('Change avatar'));
    await flush();

    assert.match(screen.text(), /PICKER \/api\/teacher\/masjids\/4\/avatars \/api\/teacher\/masjids\/4\/groups\/9\/members\/12\/avatar/);
    assert.ok(screen.button('Back to Idris Marlowe'));

    click(screen.button('Back to Idris Marlowe'));
    await flush();
    assert.match(screen.text(), /Age 7/);
    assert.deepEqual(events, [], 'going back closes nothing');
});

test('sheet: the close button and Escape close it', async () => {
    const { screen, events } = await mountSheet(idris());

    click(screen.all((n: any) => n.tag === 'button' && n.props['aria-label'] === 'Close')[0]);
    assert.deepEqual(events, [['close']]);

    const dialog = screen.all((n: any) => n.props.role === 'dialog')[0];
    assert.equal(dialog.props['aria-modal'], 'true');
    assert.ok(dialog.props['aria-labelledby']);

    // Escape is heard on the document: after an avatar is saved the button that had focus is gone,
    // focus is on the page, and a listener on the dialog would never hear the key.
    pressKey('Escape');
    assert.deepEqual(events, [['close'], ['close']]);
    pressKey('a');
    assert.equal(events.length, 2, 'no other key closes it');

    // ...and the sheet stops listening when it goes.
    screen.unmount();
    pressKey('Escape');
    assert.equal(events.length, 2);
});

// ------------------------------------------------------------------ TeacherClass.vue, as source

const teacherClass = source('views/teacher/TeacherClass.vue');
const section = (from: string, to: string) => {
    const start = teacherClass.indexOf(from);
    const end = teacherClass.indexOf(to, start + 1);
    assert.ok(start >= 0 && end > start, `TeacherClass.vue has no section from "${from}" to "${to}"`);

    return teacherClass.slice(start, end);
};

test('teacher roster: the whole row is one button with a chevron, and it opens the sheet', () => {
    const roster = section('ROSTER (read only)', 'ATTENDANCE -->');

    assert.match(roster, /<button v-for="s in students" :key="s\.membership_id" type="button"\s+class="list-group-item list-group-item-action [^"]*tc-roster-row"\s+@click="sheetFor = s">/);
    assert.match(roster, /<i class="bi bi-chevron-right text-muted"><\/i>\s*<\/button>/);
    // One target per row: no second button inside it (the Avatar button moved into the sheet).
    assert.equal((roster.match(/<button/g) ?? []).length, 1);
    assert.match(roster, /<span v-if="ageLabel\(s\.age\)" class="text-muted small">\{\{ ageLabel\(s\.age\) \}\}<\/span>/);
    assert.match(teacherClass, /\.tc-roster-row \{ min-height: 56px; \}/);
    assert.match(teacherClass, /<TeacherStudentSheet v-if="sheetFor" :student="sheetFor" :masjid-id="masjidId" :base="base"\s+:is-class="group\?\.kind === 'class'"\s+@close="sheetFor = null" @avatar-saved="onAvatarSaved" \/>/);
});

test('teacher roster: nothing on the row or in the sheet can print a guardian', () => {
    const roster = section('ROSTER (read only)', 'ATTENDANCE -->');

    // The dormant hook that would have printed guardians the day a payload carried them is gone.
    assert.doesNotMatch(teacherClass, /guardianNames/);
    assert.doesNotMatch(teacherClass, /Guardians: \{\{/);
    assert.doesNotMatch(roster, /\.guardians|s\.guardian|\.email|\.phone|date_of_birth/);

    const sheet = source('views/teacher/TeacherStudentSheet.vue');
    const template = sheet.slice(sheet.indexOf('<template>'), sheet.lastIndexOf('</template>'));
    assert.doesNotMatch(template, /guardian|\.email|\.phone|date_of_birth/i);
    // The sheet draws from the four-key model, never from the student object's other fields.
    assert.match(sheet, /const model = computed\(\(\) => studentSheetModel\(props\.student\)\);/);
});

test('teacher attendance: the register row is not a button and opens no sheet', () => {
    const attendance = section('ATTENDANCE -->', 'LETTERS');

    assert.doesNotMatch(attendance, /sheetFor|TeacherStudentSheet|ageLabel/);
});
