import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chooseOption, click, deferred, flush, httpError, mountSfc, type } from './support/mountSfc.ts';
import { modulesFor } from './support/batch3Modules.ts';

/**
 * The teacher's Hifdh tab and the notes on a recitation.
 *
 * The form has taken a note since the tab shipped; the list under it showed the
 * portion, the quality and the day, and never the note. A Qur'an teacher wrote
 * about a child and could not find it again.
 */
const doc = (globalThis as any).document;
doc.addEventListener ??= () => {};
doc.removeEventListener ??= () => {};
doc.body = { style: {} };
(globalThis as any).window ??= { addEventListener() {}, removeEventListener() {} };
const route = { params: { masjidId: '1', groupId: '2' }, query: {} };
const router = { useRoute: () => route, useRouter: () => ({ replace: async () => {}, resolve: () => ({ href: '/' }) }) };
const ok = (data: any, meta: any = {}) => ({ data: { status: 'success', data, meta } });
const rowsOf = (data: any) => Array.isArray(data) ? data : data?.data ?? [];

const classData = { id: 2, name: 'Sample class', students: [
    { membership_id: 9, contact: { first_name: 'Test student' } },
    { membership_id: 10, contact: { first_name: 'Second student' } },
    { membership_id: 11, contact: { first_name: 'Third student' } },
] };
const row = (id: number, note: string | null) => ({
    id, membership_id: 9, kind: 'sabak', quality: 'good', whole_surah: false,
    from: { surah: 78, surah_name: 'An-Naba', ayah: 1 }, to: { surah: 78, surah_name: 'An-Naba', ayah: 10 },
    recited_at: '2026-10-01', note, heard_by: { id: 5, name: 'Test teacher' },
});

async function openHifdh(rows: any[], extra: Record<string, any> = {}) {
    const calls: { verb: string; url: string; body?: any }[] = [];
    const api = {
        get: async (url: string) => ok(url.endsWith('/hifz') ? rows : url.endsWith('/groups/2') ? classData : []),
        post: async (url: string, body: any) => { calls.push({ verb: 'post', url, body }); return ok({}); },
        ...extra,
    };
    const file = 'views/teacher/TeacherClass.vue';
    const screen = await mountSfc(file, {}, await modulesFor(file, {
        'vue-router': router,
        '@/core/services/TeacherApiService': { default: api, rowsOf },
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
    }));
    await flush(); click(screen.button('Hifdh')); await flush();
    const student = screen.all((n: any) => n.tag === 'select' && n.children.some((o: any) => o.props.value === 9))[0];
    chooseOption(student, 9);
    await flush(12);
    return { screen, calls };
}

const rowsOn = (screen: any) => screen.all((n: any) => n.tag === 'li' && n.textContent.includes('Remove'));

test('a recitation that has a note shows it under its line, as the teacher typed it', async () => {
    const { screen } = await openHifdh([
        row(1, 'struggled with the waqf on ayah 12\nrevise at home'),
        row(2, null),
    ]);
    try {
        const rows = rowsOn(screen);
        assert.equal(rows.length, 2);
        assert.ok(rows[0].textContent.includes('struggled with the waqf on ayah 12'), rows[0].textContent);
        assert.ok(rows[0].textContent.includes('revise at home'), rows[0].textContent);
        // Her words are not inside the line the screen capitalises, and a line
        // break she typed is kept.
        const note = screen.all((n: any) => n.kind === 'el' && String(n.props.class ?? '').includes('hifz-note'));
        assert.equal(note.length, 1, 'only the row that has a note draws one');
        assert.equal(String(note[0].props.class).includes('text-capitalize'), false);
        assert.match(JSON.stringify(note[0].props.style ?? ''), /pre-wrap/);
        // The line itself still reads as it did: the portion first.
        assert.ok(rows[0].textContent.startsWith('New memorization:'), rows[0].textContent);
        assert.ok(rows[1].textContent.startsWith('New memorization:'), rows[1].textContent);
    } finally { screen.unmount(); }
});

const editorOn = (screen: any) => screen.all((n: any) => n.tag === 'textarea')[0];
/** The button with exactly these words somewhere under `node`, or undefined. */
const buttonIn = (node: any, label: string): any => {
    for (const child of node.children ?? []) {
        if (child.kind === 'el' && child.tag === 'button' && child.textContent.trim() === label) return child;
        const deeper = buttonIn(child, label);
        if (deeper) return deeper;
    }
    return undefined;
};

test('Edit note opens the words already written, saves the note alone, and shows what the server stored', async () => {
    const puts: { url: string; body: any }[] = [];
    const { screen } = await openHifdh([row(1, 'First words.'), row(2, null)], {
        put: async (url: string, body: any) => { puts.push({ url, body }); return ok({ ...row(1, body.note.trim() || null) }); },
    });
    try {
        let rows = rowsOn(screen);
        assert.ok(buttonIn(rows[0], 'Edit note'), 'a line that has a note offers Edit note');
        assert.ok(buttonIn(rows[1], 'Add note'), 'a line that has none offers Add note');

        click(buttonIn(rows[0], 'Edit note')); await flush();
        const box = editorOn(screen);
        assert.equal(box.value ?? box.props.value, 'First words.');
        assert.ok(screen.text().includes("This student's family can read this note."));

        type(box, '  Second words.  '); await flush();
        click(buttonIn(rowsOn(screen)[0], 'Save note')); await flush(12);

        assert.equal(puts.length, 1);
        assert.match(puts[0].url, /\/groups\/2\/hifz\/1$/);
        assert.deepEqual(Object.keys(puts[0].body), ['note'], 'nothing but the note is sent');
        rows = rowsOn(screen);
        assert.ok(rows[0].textContent.includes('Second words.'), rows[0].textContent);
        assert.equal(rows[0].textContent.includes('First words.'), false);
        assert.equal(screen.all((n: any) => n.tag === 'textarea').length, 0, 'the editor closes on a save');
    } finally { screen.unmount(); }
});

test('Remove note sends an empty note, and the line goes back to Add note', async () => {
    const puts: any[] = [];
    const { screen } = await openHifdh([row(1, 'To be removed.')], {
        put: async (_url: string, body: any) => { puts.push(body); return ok({ ...row(1, null) }); },
    });
    try {
        click(buttonIn(rowsOn(screen)[0], 'Edit note')); await flush();
        click(buttonIn(rowsOn(screen)[0], 'Remove note')); await flush(12);
        assert.deepEqual(puts, [{ note: '' }]);
        const line = rowsOn(screen)[0];
        assert.equal(line.textContent.includes('To be removed.'), false);
        assert.ok(buttonIn(line, 'Add note'));
    } finally { screen.unmount(); }
});

test('a refused save keeps the editor open with the draft and says why under the box', async () => {
    const { screen } = await openHifdh([row(1, 'Kept.')], {
        put: async () => { throw httpError(422, { message: 'The note field must not be greater than 1000 characters.' }); },
    });
    try {
        click(buttonIn(rowsOn(screen)[0], 'Edit note')); await flush();
        type(editorOn(screen), 'A draft that must survive.'); await flush();
        click(buttonIn(rowsOn(screen)[0], 'Save note')); await flush(12);
        const box = editorOn(screen);
        assert.ok(box, 'the editor is still open');
        assert.equal(box.value ?? box.props.value, 'A draft that must survive.');
        assert.ok(screen.text().includes('must not be greater than 1000 characters'), screen.text());
    } finally { screen.unmount(); }
});

// ------------------------------------------------------------------ copy to students
const fire = (el: any, name: string) => {
    const handler = el.props[`on${name[0].toUpperCase()}${name.slice(1)}`];
    (Array.isArray(handler) ? handler : handler ? [handler] : []).forEach((h: any) => h({ target: el, preventDefault() {} }));
};
const tick = (screen: any, entryId: number, membershipId: number) =>
    fire(screen.all((n: any) => n.tag === 'input' && n.props.id === `hifz-copy-${entryId}-${membershipId}`)[0], 'change');
const copyPanel = (screen: any) => screen.all((n: any) => n.kind === 'el' && String(n.props.class ?? '').includes('hifz-copy'))[0];

test('Copy to students records the same line and note for each chosen classmate, and says who', async () => {
    const posts: any[] = [];
    const line = { ...row(1, 'Watch the madd on ayah 4.'), recited_at: '2026-10-01T15:00:00+00:00' };
    const { screen } = await openHifdh([line, row(2, null)], {
        post: async (url: string, body: any) => { posts.push({ url, body }); return ok({ id: 50 + posts.length }); },
    });
    try {
        const rows = rowsOn(screen);
        assert.ok(buttonIn(rows[0], 'Copy to students'), 'a line with a note offers the copy');
        assert.equal(buttonIn(rows[1], 'Copy to students'), undefined, 'a line with no note has nothing to copy');

        click(buttonIn(rows[0], 'Copy to students')); await flush();
        const panel = copyPanel(screen);
        assert.ok(panel.textContent.includes('Second student') && panel.textContent.includes('Third student'), panel.textContent);
        assert.equal(panel.textContent.includes('Test student'), false, 'the student whose log is open is not offered');
        assert.ok(panel.textContent.includes('the same portion, type, quality and day, with this note'), panel.textContent);
        assert.ok(panel.textContent.includes('This is new memorization, so it moves each of them forward.'), panel.textContent);
        assert.equal(buttonIn(panel, 'Copy').disabled, true, 'nothing to copy to until a student is chosen');

        tick(screen, 1, 10); tick(screen, 1, 11); await flush();
        click(buttonIn(copyPanel(screen), 'Copy to 2 students')); await flush(16);

        assert.deepEqual(posts.map((p) => p.url.replace(/^.*\/groups/, '/groups')), ['/groups/2/hifz', '/groups/2/hifz']);
        assert.deepEqual(posts.map((p) => p.body), [10, 11].map((membership_id) => ({
            membership_id, kind: 'sabak', from_surah: 78, from_ayah: 1, to_surah: 78, to_ayah: 10,
            quality: 'good', note: 'Watch the madd on ayah 4.', recited_at: '2026-10-01T15:00:00+00:00',
        })));
        assert.equal(copyPanel(screen), undefined, 'the panel closes when every copy went through');
        assert.ok(rowsOn(screen)[0].textContent.includes('Copied to Second student, Third student.'), rowsOn(screen)[0].textContent);
    } finally { screen.unmount(); }
});

test('a copy that fails for one student names her, keeps her chosen, and retries only her', async () => {
    const posts: any[] = [];
    let refuse = true;
    const { screen } = await openHifdh([{ ...row(1, 'Revise at home.'), kind: 'sabqi' }], {
        post: async (_url: string, body: any) => {
            posts.push(body.membership_id);
            if (body.membership_id === 11 && refuse) throw httpError(422, { message: 'That id names no participant of this group, so no recitation can be recorded for them.' });
            return ok({ id: 60 });
        },
    });
    try {
        click(buttonIn(rowsOn(screen)[0], 'Copy to students')); await flush();
        assert.equal(copyPanel(screen).textContent.includes('This is new memorization'), false, 'a revision line moves nobody');
        click(buttonIn(copyPanel(screen), 'Choose all')); await flush();
        click(buttonIn(copyPanel(screen), 'Copy to 2 students')); await flush(16);

        assert.deepEqual(posts, [10, 11]);
        const text = rowsOn(screen)[0].textContent;
        assert.ok(text.includes('Copied to Second student.'), text);
        assert.ok(text.includes('Not copied for Third student: That id names no participant of this group'), text);
        assert.ok(buttonIn(copyPanel(screen), 'Copy to 1 student'), 'only the student it failed for is still chosen');

        refuse = false;
        click(buttonIn(copyPanel(screen), 'Copy to 1 student')); await flush(16);
        assert.deepEqual(posts, [10, 11, 11], 'the retry reaches only her');
        assert.ok(rowsOn(screen)[0].textContent.includes('Copied to Third student.'), rowsOn(screen)[0].textContent);
        assert.equal(copyPanel(screen), undefined);
    } finally { screen.unmount(); }
});

test('opening the note editor closes the copy panel, and the other way round', async () => {
    const { screen } = await openHifdh([row(1, 'One panel at a time.')]);
    try {
        click(buttonIn(rowsOn(screen)[0], 'Copy to students')); await flush();
        assert.ok(copyPanel(screen));
        click(buttonIn(rowsOn(screen)[0], 'Edit note')); await flush();
        assert.equal(copyPanel(screen), undefined);
        assert.equal(screen.all((n: any) => n.tag === 'textarea').length, 1);
        click(buttonIn(rowsOn(screen)[0], 'Copy to students')); await flush();
        assert.equal(screen.all((n: any) => n.tag === 'textarea').length, 0);
        assert.ok(copyPanel(screen));
    } finally { screen.unmount(); }
});

// ------------------------------------------------------------------ the review's reproductions (2026-10-07)
const studentSelect = (screen: any) => screen.all((n: any) => n.tag === 'select' && n.children.some((o: any) => o.props.value === 9))[0];

test('a slower answer for the student chosen first never replaces the list of the student chosen last', async () => {
    const first = deferred<any>();
    const puts: string[] = [];
    let call = 0;
    const { screen } = await openHifdh([], {
        get: async (url: string) => {
            if (url.endsWith('/groups/2')) return ok(classData);
            if (url.endsWith('/members/10/hifz')) { call++; return first.promise; }
            if (url.endsWith('/members/9/hifz')) return ok([row(1, 'Nine\'s note.')]);
            return ok([]);
        },
        put: async (url: string) => { puts.push(url); return ok(row(1, 'x')); },
    });
    try {
        // Student 10 (slow), then back to 9 (fast): 9's lines are on screen.
        chooseOption(studentSelect(screen), 10); await flush();
        chooseOption(studentSelect(screen), 9); await flush(12);
        assert.ok(rowsOn(screen)[0].textContent.includes("Nine's note."));
        // 10's answer lands late, carrying another child's line.
        first.resolve(ok([{ ...row(99, 'Ten\'s note.') }])); await flush(12);
        assert.equal(call, 1);
        assert.equal(rowsOn(screen).length, 1);
        assert.ok(rowsOn(screen)[0].textContent.includes("Nine's note."), rowsOn(screen)[0].textContent);
        assert.equal(screen.text().includes("Ten's note."), false, 'the late list is dropped');
    } finally { screen.unmount(); }
});

test('while a note is saving nothing else on the tab can be opened, and a refusal lands under its own box with the draft kept', async () => {
    const saving = deferred<any>();
    const { screen } = await openHifdh([row(1, 'Line one.'), row(2, 'Line two.')], { put: async () => saving.promise });
    try {
        click(buttonIn(rowsOn(screen)[0], 'Edit note')); await flush();
        type(editorOn(screen), 'A draft on line one.'); await flush();
        click(buttonIn(rowsOn(screen)[0], 'Save note')); await flush();

        // In flight: the other line's editor, the copy panel and the student box are held.
        assert.equal(buttonIn(rowsOn(screen)[1], 'Edit note').disabled, true);
        assert.equal(buttonIn(rowsOn(screen)[1], 'Copy to students').disabled, true);
        assert.equal(studentSelect(screen).disabled, true);
        assert.equal(click(buttonIn(rowsOn(screen)[1], 'Edit note')), false);

        saving.reject(httpError(422, { message: 'The note field must not be greater than 1000 characters.' })); await flush(12);
        const first = rowsOn(screen)[0];
        assert.ok(first.textContent.includes('must not be greater than 1000 characters'), first.textContent);
        assert.equal(rowsOn(screen)[1].textContent.includes('must not be greater'), false, 'never under another line');
        const box = editorOn(screen);
        assert.equal(box.value ?? box.props.value, 'A draft on line one.');
        assert.equal(studentSelect(screen).disabled, false, 'released when the save has answered');
    } finally { screen.unmount(); }
});

test('while a line is being copied the student cannot be changed, and every chosen student gets the same words', async () => {
    const gate = deferred<any>();
    const bodies: any[] = [];
    const { screen } = await openHifdh([row(1, 'Original note.')], {
        post: async (_url: string, body: any) => { bodies.push(body); if (body.membership_id === 10) await gate.promise; return ok({ id: 70 }); },
        put: async () => ok(row(1, 'Changed note.')),
    });
    try {
        click(buttonIn(rowsOn(screen)[0], 'Copy to students')); await flush();
        click(buttonIn(copyPanel(screen), 'Choose all')); await flush();
        click(buttonIn(copyPanel(screen), 'Copy to 2 students')); await flush();

        // The first copy is waiting. Nothing can change the student, open the editor or start another copy.
        assert.equal(studentSelect(screen).disabled, true);
        assert.equal(buttonIn(rowsOn(screen)[0], 'Edit note').disabled, true);
        assert.equal(click(buttonIn(rowsOn(screen)[0], 'Edit note')), false);
        assert.equal(screen.all((n: any) => n.tag === 'textarea').length, 0);

        gate.resolve(null); await flush(16);
        assert.deepEqual(bodies.map((b) => [b.membership_id, b.note]), [[10, 'Original note.'], [11, 'Original note.']]);
        assert.equal(studentSelect(screen).disabled, false);
    } finally { screen.unmount(); }
});

test('a copy only ever goes to students ticked in the panel on screen', async () => {
    const bodies: any[] = [];
    const { screen } = await openHifdh([row(1, 'To copy.')], {
        post: async (_url: string, body: any) => { bodies.push(body.membership_id); return ok({ id: 80 }); },
    });
    try {
        click(buttonIn(rowsOn(screen)[0], 'Copy to students')); await flush();
        tick(screen, 1, 10); await flush();
        // Change to the ticked student's own log: the panel closes and its ticks go with it.
        chooseOption(studentSelect(screen), 10); await flush(12);
        assert.equal(copyPanel(screen), undefined);
        assert.deepEqual(bodies, [], 'nothing was copied by changing student');
    } finally { screen.unmount(); }
});

// ------------------------------------------------------------------ Edit entry: every part of a recorded line
const kindSelect = (screen: any) => screen.all((n: any) => n.tag === 'select' && n.children.some((o: any) => o.props.value === 'sabak'))[0];
const qualitySelect = (screen: any) => screen.all((n: any) => n.tag === 'select' && n.children.some((o: any) => o.props.value === 'excellent'))[0];
const dateBox = (screen: any) => screen.all((n: any) => n.tag === 'input' && n.props.type === 'date')[0];
const ayahBox = (screen: any, which: 'from' | 'to') => screen.all((n: any) => n.tag === 'input' && n.props.placeholder === which)[0];
const noteBox = (screen: any) => screen.all((n: any) => n.tag === 'input' && String(n.props.placeholder ?? '').startsWith('e.g. struggled'))[0];
const valueOf = (el: any) => el.value ?? el.props.value ?? '';
const editingBox = (screen: any) => screen.all((n: any) => n.kind === 'el' && String(n.props.class ?? '').includes('hifz-editing'))[0];
// A line heard at a real moment (not a chosen date), so "the day left alone keeps the exact time" can be seen.
const heard = (id: number, note: string | null, extra: Record<string, any> = {}) =>
    ({ ...row(id, note), recited_at: '2026-10-01T15:00:00+00:00', major_mistakes: 0, minor_mistakes: 0, ...extra });

async function editing(rows: any[], extra: Record<string, any> = {}) {
    const calls: { verb: string; url: string; body?: any }[] = [];
    const short = (url: string) => url.replace(/^.*\/groups\/2/, '');
    const opened = await openHifdh(rows, {
        post: async (url: string, body: any) => { calls.push({ verb: 'post', url: short(url), body }); return ok({ id: 500 }); },
        put: async (url: string, body: any) => { calls.push({ verb: 'put', url: short(url), body }); return ok({ ...rows[0], note: body.note || null }); },
        delete: async (url: string) => { calls.push({ verb: 'delete', url: short(url) }); return ok({}); },
        ...extra,
    });
    return { ...opened, calls };
}

test('Edit entry loads the whole line into the form, holds the other lines, and Cancel puts the form back', async () => {
    const { screen, calls } = await editing([heard(1, 'First note.', { kind: 'sabqi', quality: 'fair' }), heard(2, null)]);
    try {
        click(buttonIn(rowsOn(screen)[0], 'Edit entry')); await flush();

        assert.ok(editingBox(screen).textContent.includes('Changing this line'), editingBox(screen).textContent);
        assert.ok(editingBox(screen).textContent.includes('Recent revision: An-Naba 1 → An-Naba 10'), editingBox(screen).textContent);
        assert.ok(editingBox(screen).textContent.includes('Saving records the corrected line and removes the old one.'));
        // (The type and the quality are drop-downs, which this harness cannot read
        // back; the next test proves they were loaded by what Save then sends.)
        assert.equal(String(valueOf(ayahBox(screen, 'from'))), '1');
        assert.equal(String(valueOf(ayahBox(screen, 'to'))), '10');
        assert.equal(valueOf(dateBox(screen)), '2026-10-01');
        assert.equal(valueOf(noteBox(screen)), 'First note.');
        assert.ok(screen.button('Save changes'));

        // Save or Cancel first: the lines' own actions and the student box wait.
        for (const label of ['Edit note', 'Edit entry', 'Copy to students', 'Remove']) {
            assert.equal(buttonIn(rowsOn(screen)[0], label).disabled, true, label);
        }
        assert.equal(studentSelect(screen).disabled, true);
        assert.ok(rowsOn(screen)[0].textContent.includes('being changed above'));

        click(buttonIn(screen.root, 'Cancel')); await flush();
        assert.equal(editingBox(screen), undefined);
        assert.equal(valueOf(noteBox(screen)), '', 'the form is as it was');
        assert.equal(valueOf(dateBox(screen)) || '', '');
        assert.equal(buttonIn(rowsOn(screen)[0], 'Edit entry').disabled, false);
        assert.deepEqual(calls, [], 'looking and cancelling sends nothing');
    } finally { screen.unmount(); }
});

test('changing the day records the corrected line first and strikes the old one second', async () => {
    const { screen, calls } = await editing([heard(1, 'Keep this note.', { kind: 'sabqi', quality: 'fair' })]);
    try {
        click(buttonIn(rowsOn(screen)[0], 'Edit entry')); await flush();
        type(dateBox(screen), '2026-09-29'); await flush();
        click(screen.button('Save changes')); await flush(16);

        assert.deepEqual(calls.map((c) => `${c.verb} ${c.url}`), ['post /hifz', 'delete /hifz/1']);
        assert.deepEqual(calls[0].body, {
            // The type and quality are the LINE's, loaded into the form, not the form's defaults.
            membership_id: 9, kind: 'sabqi', from_surah: 78, to_surah: 78, from_ayah: 1, to_ayah: 10,
            quality: 'fair', major_mistakes: 0, minor_mistakes: 0, note: 'Keep this note.',
            // A chosen day goes as noon UTC of that day, never a bare date.
            recited_at: '2026-09-29T12:00:00Z',
        });
        assert.equal(editingBox(screen), undefined, 'edit mode is over');
    } finally { screen.unmount(); }
});

test('changing the ayahs or the quality but not the day keeps the exact time the line was heard', async () => {
    const { screen, calls } = await editing([heard(1, null)]);
    try {
        click(buttonIn(rowsOn(screen)[0], 'Edit entry')); await flush();
        type(ayahBox(screen, 'to'), '12'); await flush();
        chooseOption(qualitySelect(screen), 'excellent'); await flush();
        click(screen.button('Save changes')); await flush(16);

        assert.deepEqual(calls.map((c) => `${c.verb} ${c.url}`), ['post /hifz', 'delete /hifz/1']);
        assert.equal(calls[0].body.to_ayah, 12);
        assert.equal(calls[0].body.quality, 'excellent');
        assert.equal(calls[0].body.recited_at, '2026-10-01T15:00:00+00:00');
        assert.equal('note' in calls[0].body, false, 'a line with no note is recorded with none');
    } finally { screen.unmount(); }
});

test('when only the note is changed in the form, the note alone is rewritten and the line is not replaced', async () => {
    const { screen, calls } = await editing([heard(1, 'Old words.')]);
    try {
        click(buttonIn(rowsOn(screen)[0], 'Edit entry')); await flush();
        type(noteBox(screen), 'New words.'); await flush();
        click(screen.button('Save changes')); await flush(16);

        assert.deepEqual(calls, [{ verb: 'put', url: '/hifz/1', body: { note: 'New words.' } }]);
        assert.ok(rowsOn(screen)[0].textContent.includes('New words.'), rowsOn(screen)[0].textContent);
        assert.equal(editingBox(screen), undefined);
    } finally { screen.unmount(); }
});

test('saving a line that was not changed sends nothing', async () => {
    const { screen, calls } = await editing([{ ...heard(1, 'Same.'), whole_surah: true, from: { surah: 114, surah_name: 'An-Nas', ayah: 1 }, to: { surah: 114, surah_name: 'An-Nas', ayah: 6 } }]);
    try {
        click(buttonIn(rowsOn(screen)[0], 'Edit entry')); await flush();
        click(screen.button('Save changes')); await flush(16);
        assert.deepEqual(calls, []);
        assert.equal(editingBox(screen), undefined);
    } finally { screen.unmount(); }
});

test('a correction the server refuses changes nothing: the old line stands and the form keeps what was typed', async () => {
    const { screen, calls } = await editing([heard(1, null)], {
        post: async () => { throw httpError(422, { message: 'The to ayah is past the end of this surah.' }); },
    });
    try {
        click(buttonIn(rowsOn(screen)[0], 'Edit entry')); await flush();
        type(ayahBox(screen, 'to'), '40'); await flush();
        click(screen.button('Save changes')); await flush(16);

        assert.deepEqual(calls.filter((c) => c.verb === 'delete'), [], 'the old line is never struck when the new one was refused');
        assert.ok(screen.text().includes('The to ayah is past the end of this surah.'), screen.text());
        assert.ok(editingBox(screen), 'still in edit mode');
        assert.equal(String(valueOf(ayahBox(screen, 'to'))), '40');
    } finally { screen.unmount(); }
});

test('if the old line cannot be struck after the corrected one is recorded, the screen says both are in the list', async () => {
    const { screen, calls } = await editing([heard(1, null)], {
        delete: async () => { throw httpError(500, { message: 'Server error.' }); },
    });
    try {
        click(buttonIn(rowsOn(screen)[0], 'Edit entry')); await flush();
        chooseOption(kindSelect(screen), 'manzil'); await flush();
        click(screen.button('Save changes')); await flush(16);

        assert.deepEqual(calls.map((c) => c.verb), ['post']);
        assert.ok(screen.text().includes('The corrected line was recorded, but the old line could not be removed. Both are in the list below: remove the old one.'), screen.text());
        assert.equal(editingBox(screen), undefined);
    } finally { screen.unmount(); }
});

test('a line that runs across two surahs has no Edit entry, and a chosen day reads as that day in the list', async () => {
    const across = { ...heard(1, 'Across two.'), from: { surah: 113, surah_name: 'Al-Falaq', ayah: 1 }, to: { surah: 114, surah_name: 'An-Nas', ayah: 6 } };
    // Stored the old way (a bare date, midnight UTC) and the new way (noon UTC).
    const { screen } = await editing([across, { ...heard(2, null), recited_at: '2026-09-17T00:00:00+00:00' }, { ...heard(3, null), recited_at: '2026-10-05T12:00:00+00:00' }]);
    try {
        const rows = rowsOn(screen);
        assert.equal(buttonIn(rows[0], 'Edit entry'), undefined);
        assert.ok(buttonIn(rows[0], 'Edit note'), 'its note can still be changed');
        assert.ok(buttonIn(rows[1], 'Edit entry'));
        assert.ok(rows[1].textContent.includes('Sep 17, 2026'), rows[1].textContent);
        assert.ok(rows[2].textContent.includes('Oct 5, 2026'), rows[2].textContent);
    } finally { screen.unmount(); }
});
