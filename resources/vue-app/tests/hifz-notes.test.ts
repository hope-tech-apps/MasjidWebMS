import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chooseOption, click, flush, httpError, mountSfc, type } from './support/mountSfc.ts';
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
