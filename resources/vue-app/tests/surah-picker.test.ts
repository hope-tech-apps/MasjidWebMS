import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chooseOption, click, compileSfc, deferred, flush, httpError, mountSfc, press, type } from './support/mountSfc.ts';
import { modulesFor } from './support/batch3Modules.ts';
import { foldSurahText, matchSurahs, surahLabel, surahOnLeave, type Surah } from '../core/helpers/surahSearch.ts';

/**
 * The Surah box on the teacher's Hifdh form: type the number or part of the name.
 * It was a plain list of 114 to scroll through for every recitation.
 */
const doc = (globalThis as any).document;
doc.addEventListener ??= () => {};
doc.removeEventListener ??= () => {};
doc.body = { style: {} };
(globalThis as any).window ??= { addEventListener() {}, removeEventListener() {} };

// A slice of the server's list, as GET .../quran-surahs answers it.
const SURAHS: Surah[] = [
    { number: 1, name: 'Al-Fatihah', ayahs: 7 }, { number: 2, name: 'Al-Baqarah', ayahs: 286 },
    { number: 3, name: "Ali 'Imran", ayahs: 200 }, { number: 10, name: 'Yunus', ayahs: 109 },
    { number: 12, name: 'Yusuf', ayahs: 111 }, { number: 18, name: 'Al-Kahf', ayahs: 110 },
    { number: 30, name: 'Ar-Rum', ayahs: 60 }, { number: 36, name: 'Ya-Sin', ayahs: 83 },
    { number: 78, name: 'An-Naba', ayahs: 40 }, { number: 100, name: 'Al-Adiyat', ayahs: 11 },
    { number: 110, name: 'An-Nasr', ayahs: 3 }, { number: 114, name: 'An-Nas', ayahs: 6 },
];
const numbers = (list: Surah[]) => list.map((s) => s.number);

test('nothing typed is the whole list, in mushaf order', () => {
    assert.deepEqual(numbers(matchSurahs(SURAHS, '')), numbers(SURAHS));
    assert.deepEqual(numbers(matchSurahs(SURAHS, '   ')), numbers(SURAHS));
});

test('digits are the number, read from its first digit, the exact number first', () => {
    assert.deepEqual(numbers(matchSurahs(SURAHS, '36')), [36]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, '1')), [1, 10, 12, 18, 100, 110, 114]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, '3')), [3, 30, 36]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, '036')), [36], 'leading zeros are ignored');
    assert.deepEqual(matchSurahs(SURAHS, '115'), []);
    assert.deepEqual(matchSurahs(SURAHS, '0'), [], 'no surah is numbered zero');
});

test('letters are part of the name: spelling marks, the article and doubled vowels do not matter', () => {
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'yas')), [36]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'Ya Sin')), [36]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'yaseen')), [36]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'fatiha')), [1]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'al-fat')), [1]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'imraan')), [3]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'kahf')), [18]);
    // A name that STARTS with the letters comes before one that only holds them.
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'nas')), [110, 114]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'yu')), [10, 12]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'a')).slice(0, 2), [1, 2]);
    assert.deepEqual(matchSurahs(SURAHS, 'zzz'), []);
    assert.equal(foldSurahText("Al-Ma'idah"), 'almaidah');
});

test('digits from an Arabic or Persian keyboard are the same numbers, and the word "surah" is not part of a name', () => {
    assert.deepEqual(numbers(matchSurahs(SURAHS, '٣٦')), [36]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, '۳۶')), [36]);
    assert.equal(surahOnLeave(SURAHS, '٣٦')?.number, 36);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'surah 36')), [36]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'Surat Ya-Sin')), [36]);
    assert.deepEqual(numbers(matchSurahs(SURAHS, 'sura')), numbers(SURAHS));
});

test('text with nothing in it to find a surah by matches none, and never puts the first surah under Enter', async () => {
    // Arabic letters, punctuation: the list's names are in Latin letters.
    assert.deepEqual(matchSurahs(SURAHS, 'يس'), []);
    assert.deepEqual(matchSurahs(SURAHS, '??'), []);
    assert.equal(surahOnLeave(SURAHS, 'يس'), null);

    const { screen, picked, box, options } = await picker(78);
    try {
        fire(box(), 'focus'); await flush();
        type(box(), 'يس'); await flush();
        assert.equal(options().length, 0);
        assert.ok(screen.text().includes('No surah matches “يس”.'), screen.text());
        fire(box(), 'keydown', { key: 'Enter' }); await flush();
        assert.deepEqual(picked, [], 'Enter takes nothing');

        // The word "surah" by itself lists every surah and highlights none.
        type(box(), 'surah'); await flush();
        assert.equal(options().length, SURAHS.length);
        fire(box(), 'keydown', { key: 'Enter' }); await flush();
        assert.deepEqual(picked, [], 'Enter takes nothing until the text narrows the list');

        // Arabic-keyboard digits ARE a number.
        type(box(), '٣٦'); await flush();
        assert.deepEqual(options(), ['36 · Ya-Sin (83)']);
        fire(box(), 'keydown', { key: 'Enter' }); await flush();
        assert.deepEqual(picked, [36]);
    } finally { screen.unmount(); }
});

test('a number and letters together must both hold', () => {
    assert.deepEqual(numbers(matchSurahs(SURAHS, '2 baq')), [2]);
    assert.deepEqual(matchSurahs(SURAHS, '2 yas'), []);
});

test('leaving the box takes an exact number or the only match, and never guesses', () => {
    assert.equal(surahOnLeave(SURAHS, '36')?.number, 36);
    assert.equal(surahOnLeave(SURAHS, '1')?.number, 1, 'the exact number, though 10 and 12 also start with it');
    assert.equal(surahOnLeave(SURAHS, 'yaseen')?.number, 36);
    assert.equal(surahOnLeave(SURAHS, 'yu'), null, 'Yunus or Yusuf: not certain');
    assert.equal(surahOnLeave(SURAHS, '115'), null);
    assert.equal(surahOnLeave(SURAHS, ''), null);
});

const fire = (el: any, name: string, extra: Record<string, any> = {}) => {
    const handler = el.props[`on${name[0].toUpperCase()}${name.slice(1)}`];
    const event = { target: el, currentTarget: el, preventDefault() {}, stopPropagation() {}, ...extra };
    (Array.isArray(handler) ? handler : handler ? [handler] : []).forEach((h: any) => h(event));
};
const file = 'components/teacher/SurahPicker.vue';

async function picker(modelValue: number | null) {
    const picked: (number | null)[] = [];
    const screen = await mountSfc(file, { surahs: SURAHS, modelValue, inputId: 'hifz-surah', 'onUpdate:modelValue': (v: number | null) => picked.push(v) },
        await modulesFor(file, {}));
    await flush();
    const box = () => screen.all((n: any) => n.tag === 'input')[0];
    const options = () => screen.all((n: any) => n.props.role === 'option').map((n: any) => n.textContent);
    return { screen, picked, box, options };
}

test('the box shows the chosen surah; focusing it lists every surah as the old drop-down did', async () => {
    const { screen, box, options } = await picker(78);
    try {
        assert.equal(box().props.value, surahLabel(SURAHS[8]));
        assert.equal(options().length, 0, 'closed until it is focused');
        fire(box(), 'focus'); await flush();
        assert.equal(options().length, SURAHS.length);
        assert.equal(options()[0], '1 · Al-Fatihah (7)');
    } finally { screen.unmount(); }
});

test('typing a number then Enter chooses that surah', async () => {
    const { screen, picked, box, options } = await picker(null);
    try {
        assert.equal(box().props.placeholder, 'Type a number or a name');
        fire(box(), 'focus'); await flush();
        type(box(), '36'); await flush();
        assert.deepEqual(options(), ['36 · Ya-Sin (83)']);
        fire(box(), 'keydown', { key: 'Enter' }); await flush();
        assert.deepEqual(picked, [36]);
        assert.equal(box().props.value, '36 · Ya-Sin (83)');
        assert.equal(options().length, 0, 'the list closes on a pick');
    } finally { screen.unmount(); }
});

test('typing part of a name narrows the list; a tap chooses; the arrows move the highlight', async () => {
    const { screen, picked, box, options } = await picker(null);
    try {
        fire(box(), 'focus'); await flush();
        type(box(), 'yu'); await flush();
        assert.deepEqual(options(), ['10 · Yunus (109)', '12 · Yusuf (111)']);
        fire(box(), 'keydown', { key: 'ArrowDown' }); await flush();
        fire(box(), 'keydown', { key: 'Enter' }); await flush();
        assert.deepEqual(picked, [12], 'the first match is highlighted, one step down is the second');

        fire(box(), 'focus'); await flush();
        type(box(), 'kahf'); await flush();
        const row = screen.all((n: any) => n.props.role === 'option')[0];
        fire(row, 'mousedown'); await flush();
        assert.deepEqual(picked, [12, 18]);
    } finally { screen.unmount(); }
});

test('leaving the box: an exact number is taken, an uncertain text leaves it holding no surah, Escape puts the surah back', async () => {
    const { screen, picked, box } = await picker(78);
    try {
        fire(box(), 'focus'); await flush();
        type(box(), '2'); await flush();
        fire(box(), 'blur'); await flush();
        assert.deepEqual(picked, [2]);

        // Text that names no ONE surah: the box holds none (never the surah of the
        // last recitation), keeps the text, and says so.
        fire(box(), 'focus'); await flush();
        type(box(), 'yu'); await flush();
        fire(box(), 'blur'); await flush();
        assert.deepEqual(picked, [2, null], 'Yunus or Yusuf is not chosen for her, and neither is the old surah');
        assert.equal(box().props.value, 'yu');
        assert.ok(screen.text().includes('“yu” is not one surah. Choose a surah from the list.'), screen.text());
        assert.ok(String(box().props.class).includes('is-invalid'));

        // Escape is the teacher saying "never mind": the surah the box holds comes back.
        fire(box(), 'focus'); await flush();
        type(box(), 'zzz'); await flush();
        assert.ok(screen.text().includes('No surah matches “zzz”.'), screen.text());
        fire(box(), 'keydown', { key: 'Escape' }); await flush();
        assert.equal(box().props.value, surahLabel(SURAHS[8]), 'the test parent still holds 78');
        assert.deepEqual(picked, [2, null]);

        // Focusing and leaving with nothing typed changes nothing.
        fire(box(), 'focus'); await flush();
        fire(box(), 'blur'); await flush();
        assert.deepEqual(picked, [2, null]);
    } finally { screen.unmount(); }
});

test('on the Hifdh form, the typed surah is the one recorded', async () => {
    const posts: any[] = [];
    const route = { params: { masjidId: '1', groupId: '2' }, query: {} };
    const router = { useRoute: () => route, useRouter: () => ({ replace: async () => {}, resolve: () => ({ href: '/' }) }) };
    const ok = (data: any, meta: any = {}) => ({ data: { status: 'success', data, meta } });
    const api = {
        get: async (url: string) => ok(url.endsWith('/quran-surahs') ? SURAHS
            : url.endsWith('/groups/2') ? { id: 2, name: 'Sample class', students: [{ membership_id: 9, contact: { first_name: 'Test student' } }] } : []),
        post: async (url: string, body: any) => { posts.push({ url, body }); return ok({}); },
    };
    const parent = 'views/teacher/TeacherClass.vue';
    const child = await compileSfc(file, await modulesFor(file, {}));
    const screen = await mountSfc(parent, {}, await modulesFor(parent, {
        'vue-router': router,
        '@/core/services/TeacherApiService': { default: api, rowsOf: (d: any) => Array.isArray(d) ? d : d?.data ?? [] },
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
        '@/components/teacher/SurahPicker.vue': { default: child },
    }));
    try {
        await flush(); click(screen.button('Hifdh')); await flush();
        chooseOption(screen.all((n: any) => n.tag === 'select' && n.children.some((o: any) => o.props.value === 9))[0], 9);
        await flush(12);
        const box = screen.all((n: any) => n.tag === 'input' && n.props.id === 'hifz-surah')[0];
        assert.ok(box, 'the Surah box is a text box now');
        fire(box, 'focus'); await flush();
        type(box, 'naba'); await flush();
        fire(box, 'keydown', { key: 'Enter' }); await flush();
        const whole = screen.all((n: any) => n.tag === 'input' && n.props.id === 'hifz-whole-surah')[0];
        whole.checked = true; fire(whole, 'change'); (whole.listeners?.change ?? []).forEach((h: any) => h({ target: whole }));
        await flush();
        click(screen.button('Record')); await flush(12);
        assert.equal(posts.length, 1, JSON.stringify(posts));
        assert.equal(posts[0].body.from_surah, 78);
        assert.equal(posts[0].body.to_surah, 78);
    } finally { screen.unmount(); }
});

test('with a recitation ready to record, Record is held while a note is saving, so its reload cannot close the editor under the save', async () => {
    const saving = deferred<any>();
    const posts: any[] = [];
    const route = { params: { masjidId: '1', groupId: '2' }, query: {} };
    const router = { useRoute: () => route, useRouter: () => ({ replace: async () => {}, resolve: () => ({ href: '/' }) }) };
    const ok = (data: any, meta: any = {}) => ({ data: { status: 'success', data, meta } });
    const line = { id: 1, membership_id: 9, kind: 'sabak', quality: 'good', whole_surah: false, note: 'Old note.',
        from: { surah: 78, surah_name: 'An-Naba', ayah: 1 }, to: { surah: 78, surah_name: 'An-Naba', ayah: 10 }, recited_at: '2026-10-01T15:00:00+00:00' };
    const api = {
        get: async (url: string) => ok(url.endsWith('/quran-surahs') ? SURAHS : url.endsWith('/hifz') ? [line]
            : url.endsWith('/groups/2') ? { id: 2, name: 'Sample class', students: [{ membership_id: 9, contact: { first_name: 'Test student' } }] } : []),
        post: async (_url: string, body: any) => { posts.push(body); return ok({ id: 90 }); },
        put: async () => saving.promise,
    };
    const parent = 'views/teacher/TeacherClass.vue';
    const child = await compileSfc(file, await modulesFor(file, {}));
    const screen = await mountSfc(parent, {}, await modulesFor(parent, {
        'vue-router': router,
        '@/core/services/TeacherApiService': { default: api, rowsOf: (d: any) => Array.isArray(d) ? d : d?.data ?? [] },
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
        '@/components/teacher/SurahPicker.vue': { default: child },
    }));
    const under = (node: any, test: (n: any) => boolean): any => {
        for (const c of node.children ?? []) { if (c.kind === 'el' && test(c)) return c; const d = under(c, test); if (d) return d; }
        return undefined;
    };
    const row = () => screen.all((n: any) => n.tag === 'li' && n.textContent.includes('Remove'))[0];
    const named = (label: string) => under(row(), (n) => n.tag === 'button' && n.textContent.trim() === label);
    try {
        await flush(); click(screen.button('Hifdh')); await flush();
        chooseOption(screen.all((n: any) => n.tag === 'select' && n.children.some((o: any) => o.props.value === 9))[0], 9);
        await flush(12);
        // A whole surah chosen: the form is ready, and Record is live.
        const box = screen.all((n: any) => n.tag === 'input' && n.props.id === 'hifz-surah')[0];
        fire(box, 'focus'); await flush(); type(box, '114'); await flush(); fire(box, 'keydown', { key: 'Enter' }); await flush();
        const whole = screen.all((n: any) => n.tag === 'input' && n.props.id === 'hifz-whole-surah')[0];
        whole.checked = true; fire(whole, 'change'); (whole.listeners?.change ?? []).forEach((h: any) => h({ target: whole }));
        await flush();
        assert.equal(screen.button('Record').disabled, false, 'ready to record');

        // A note save goes out and has not answered.
        click(named('Edit note')); await flush();
        const editor = screen.all((n: any) => n.tag === 'textarea')[0];
        type(editor, 'Draft that must survive.'); await flush();
        click(named('Save note')); await flush();

        assert.equal(screen.button('Record').disabled, true, 'held while the save is in flight');
        press(screen.button('Record')); await flush(12);
        assert.deepEqual(posts, [], 'a tap that got past the held button records nothing');

        saving.reject(httpError(500, { message: 'Server error.' })); await flush(12);
        const still = screen.all((n: any) => n.tag === 'textarea')[0];
        assert.ok(still, 'the editor is still open');
        assert.equal(still.value ?? still.props.value, 'Draft that must survive.');
        assert.equal(screen.button('Record').disabled, false, 'released when the save has answered');
    } finally { screen.unmount(); }
});
