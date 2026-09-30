/**
 * The class store's three screens, MOUNTED (T-003.4, W6 review B4, B8, B12, B13): each one is
 * compiled from its .vue file and driven through a refusal and its busy guard, with the API
 * answering exactly what the server answers (tests/support/mountSfc.ts says how, with no DOM).
 *
 * A source-regex test can say a line exists; only a mounted one can say a teacher who is refused
 * a prize is TOLD so, after the screen has reloaded around the refusal.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import * as ApiErrors from '../core/services/ApiErrors.ts';
import * as classStore from '../core/helpers/classStore.ts';
import { click, deferred, flush, httpError, mountSfc, press, submit, type } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');

const ok = (data: any, meta?: any) => ({ data: { status: 'success', data, ...(meta ? { meta } : {}) } });
const page = (rows: any[], current: number, last: number) => ({ data: rows, current_page: current, last_page: last });
const avatarStub = { render: () => null };

// ------------------------------------------------------------------ the teacher's store

const students = [
    { membership_id: 11, contact: { first_name: 'Amira', last_name: 'Y' }, balance: 3 },
    { membership_id: 12, contact: { first_name: 'Yusuf', last_name: 'K' }, balance: 0 },
];
const sticker = { id: 5, scope: 'class', title: 'Sticker', description: null, cost_bucks: 2, stock: 4, in_stock: true, is_active: true, editable: true };
const line = (id: number, amount = 1) => ({ id, kind: 'earned', amount, week_start: '2026-10-04', occurred_at: '2026-10-12T10:00:00Z', reversible: false, is_reversed: false });

/** A teacher API that answers the store's reads, and lets each test decide the writes. */
function teacherApi(over: { post?: (url: string, body: any) => Promise<any>; put?: (url: string, body: any) => Promise<any>; history?: (url: string) => any } = {}) {
    const calls: Array<{ verb: string; url: string; body?: any }> = [];
    const api = {
        get: async (url: string) => {
            calls.push({ verb: 'get', url });
            if (url.endsWith('/bucks')) return ok({ students, settings: { points_per_buck: 1, paper_bucks_enabled: false } });
            if (url.endsWith('/prizes')) return ok([sticker]);
            if (url.includes('/members/')) return over.history ? over.history(url) : ok(page([line(1)], 1, 1), { balance: 3 });
            throw new Error(`unexpected GET ${url}`);
        },
        post: (url: string, body: any) => { calls.push({ verb: 'post', url, body }); return over.post ? over.post(url, body) : Promise.resolve(ok({})); },
        put: (url: string, body: any) => { calls.push({ verb: 'put', url, body }); return over.put ? over.put(url, body) : Promise.resolve(ok({})); },
    };

    return { api, calls };
}

async function mountTeacher(api: any) {
    const screen = await mountSfc('views/teacher/TeacherClassStore.vue', { base: '/api/teacher/masjids/1/groups/2' }, {
        '@/core/services/TeacherApiService': { default: api, rowsOf: (n: any) => (Array.isArray(n) ? n : Array.isArray(n?.data) ? n.data : []) },
        '@/core/services/ApiErrors': ApiErrors,
        '@/components/common/PersonAvatar.vue': { default: avatarStub },
        '@/core/helpers/classStore': classStore,
    });
    await flush();

    return screen;
}

test('teacher: a refused prize says why, and still says it after the screen has reloaded', async () => {
    const { api, calls } = teacherApi({
        post: () => Promise.reject(httpError(422, { status: 'error', reason: 'out_of_stock', message: 'That prize is out of stock.' })),
    });
    const screen = await mountTeacher(api);

    click(screen.button('Amira'));
    await flush();
    click(screen.button('Give'));
    await flush();

    // The reload after the write ran (the roster was read again) and the refusal is still on screen.
    assert.ok(calls.filter((c) => c.verb === 'get' && c.url.endsWith('/bucks')).length >= 2, 'the screen reloaded after the write');
    assert.match(screen.text(), /That prize is out of stock\./);

    // Picking a student yourself does clear it: the message belonged to the last write.
    click(screen.button('Yusuf'));
    await flush();
    assert.doesNotMatch(screen.text(), /out of stock\./);
    screen.unmount();
});

test('teacher: while a prize is being given the buttons are off, and a second tap sends nothing', async () => {
    const answer = deferred();
    const { api, calls } = teacherApi({ post: () => answer.promise });
    const screen = await mountTeacher(api);

    click(screen.button('Amira'));
    await flush();
    const give = screen.button('Give');
    assert.equal(click(give), true);
    await flush();

    assert.equal(screen.button('Give').disabled, true, 'the button is off while the write is in flight');
    assert.equal(click(screen.button('Give')), false);
    press(screen.button('Give')); // a tap the re-render missed: the handler's own guard
    await flush();
    assert.equal(calls.filter((c) => c.verb === 'post').length, 1, 'one write, however many taps');

    answer.resolve(ok({ entry: {}, balance: 1, replayed: false }));
    await flush();
    assert.equal(screen.button('Give').disabled, false);
    screen.unmount();
});

test('teacher: a retry after a lost response sends the SAME request id; after a success the next gift is new', async () => {
    let n = 0;
    const { api, calls } = teacherApi({
        post: () => (++n === 1 ? Promise.reject(new Error('Network Error')) : Promise.resolve(ok({ entry: {}, balance: 1, replayed: n === 2 }))),
    });
    const screen = await mountTeacher(api);

    click(screen.button('Amira'));
    await flush();
    for (let i = 0; i < 3; i++) {
        click(screen.button('Give'));
        await flush();
    }

    const ids = calls.filter((c) => c.verb === 'post').map((c) => c.body.request_id);
    assert.equal(ids.length, 3);
    assert.equal(ids[0], ids[1], 'the retry after no answer is a replay');
    assert.notEqual(ids[1], ids[2], 'a new gift after a success is a new write');
    assert.match(ids[0], /^[A-Za-z0-9_-]{8,36}$/);
    screen.unmount();
});

test('teacher: the students are buttons, so a keyboard reaches every one of them', async () => {
    const { api } = teacherApi();
    const screen = await mountTeacher(api);

    const rows = screen.all((n) => n.tag === 'button' && (n.textContent.includes('Amira') || n.textContent.includes('Yusuf')));
    assert.equal(rows.length, 2);
    rows.forEach((b) => assert.equal(b.props.type, 'button'));
    click(rows[0]);
    await flush();
    assert.equal(screen.button('Amira').props['aria-pressed'], 'true');
    screen.unmount();
});

test('teacher: "Show earlier" pages the history 25 at a time, once per tap, and never shows a line twice', async () => {
    const more = deferred();
    const { api, calls } = teacherApi({
        history: (url) => (url.includes('?page=2&')
            ? more.promise
            : ok(page(Array.from({ length: 25 }, (_, i) => line(100 - i)), 1, 2), { balance: 3 })),
    });
    const screen = await mountTeacher(api);

    click(screen.button('Amira'));
    await flush();
    assert.equal(screen.all((n) => n.tag === 'li' && /Earned/.test(n.textContent)).length, 25);

    click(screen.button('Show earlier'));
    press(screen.button('Show earlier'));
    await flush();
    assert.equal(calls.filter((c) => c.url.includes('?page=2&')).length, 1, 'one request for the next page');

    // The next page starts with a line already shown (one was written meanwhile): it is not drawn twice.
    more.resolve(ok(page([line(76), line(75), line(74)], 2, 2), { balance: 3 }));
    await flush();
    assert.equal(screen.all((n) => n.tag === 'li' && /Earned/.test(n.textContent)).length, 27);
    assert.throws(() => screen.button('Show earlier'), /0 buttons/, 'the last page hides the button');
    screen.unmount();
});

test('teacher: an edit sends the stock only when it changed, with the count the form was opened at', async () => {
    const { api, calls } = teacherApi({
        put: (_url, body) => (body.stock === 9
            ? Promise.reject(httpError(409, { status: 'error', reason: 'stock_changed', message: 'The number left changed to 3 while this was being edited.' }))
            : Promise.resolve(ok(sticker))),
    });
    const screen = await mountTeacher(api);
    const form = screen.all((n) => n.tag === 'form')[0];
    const field = (id: string) => screen.all((n) => n.props.id === id)[0];

    click(screen.button('Edit'));
    await flush();
    type(field('cs-cost'), '3');
    submit(form);
    await flush();
    const first = calls.filter((c) => c.verb === 'put')[0].body;
    assert.equal('stock' in first, false, 'an edit that leaves the count alone does not send it');
    assert.equal('expected_stock' in first, false);

    click(screen.button('Edit'));
    await flush();
    type(field('cs-stock'), '9');
    submit(form);
    await flush();
    const second = calls.filter((c) => c.verb === 'put')[1].body;
    assert.equal(second.stock, 9);
    assert.equal(second.expected_stock, 4, 'the count the form opened at');
    assert.match(screen.text(), /The number left changed to 3/, 'the 409 is said, not swallowed');
    screen.unmount();
});

// ------------------------------------------------------------------ the family's view

function familyModules(api: any) {
    return {
        '@/core/services/FamilyApiService': { default: api, rowsOf: (n: any) => (Array.isArray(n) ? n : Array.isArray(n?.data) ? n.data : []) },
        '@/views/family/familyI18n': { useFamilyLang: () => ({ t: (k: string, x?: string) => (x ? `${k}(${x})` : k), locale: vue.ref('en') }) },
    };
}

test('family: a balance that could not be read is said out loud and never drawn as 0', async () => {
    const api = { get: () => Promise.reject(httpError(403, { status: 'error', message: 'Forbidden' })) };
    const screen = await mountSfc('views/family/FamilyBucks.vue', { base: '/api/family/masjids/1/groups/2', memberId: 11 }, familyModules(api));
    await flush();

    assert.match(screen.text(), /bucks_failed/);
    assert.doesNotMatch(screen.text(), /bucks_balance/, 'no balance line at all');
    assert.doesNotMatch(screen.text(), /\b0\b/);
    screen.unmount();
});

test('family: "show earlier" is one request per tap while it is busy, and a failure is said', async () => {
    const more = deferred();
    const urls: string[] = [];
    const api = {
        get: (url: string) => {
            urls.push(url);
            return url.includes('?page=2&') ? more.promise : Promise.resolve(ok(page([line(9, 4)], 1, 2), { balance: 4, points_per_buck: 1 }));
        },
    };
    const screen = await mountSfc('views/family/FamilyBucks.vue', { base: '/api/family/masjids/1/groups/2', memberId: 11 }, familyModules(api));
    await flush();
    assert.match(screen.text(), /bucks_balance 4/);

    click(screen.button('bucks_more'));
    await flush();
    assert.equal(screen.button('bucks_more').disabled, true);
    press(screen.button('bucks_more'));
    await flush();
    assert.equal(urls.filter((u) => u.includes('?page=2&')).length, 1);

    more.reject(new Error('Network Error'));
    await flush();
    assert.match(screen.text(), /bucks_failed/);
    screen.unmount();
});

// ------------------------------------------------------------------ the office's view

const certificate = { id: 8, scope: 'school', title: 'Certificate', description: null, cost_bucks: 5, stock: 4, in_stock: true, is_active: true, editable: false };
const recon = {
    settings: { points_per_buck: 1, paper_bucks_enabled: false, bucks_from: '2026-10-04' },
    window: { weeks: 1, requested_weeks: 8, from: '2026-10-04', to: '2026-10-10' },
    min_class_size: 5,
    suppressed_classes: 1,
    classes: [
        { group_id: 1, name: 'Grade 5', suppressed: false, minted: 50, redeemed: 10, reversed: 0, cashed_out: 0, expired: 0, outstanding: 40, children_holding: 6, negative_balances: 0, window_minted: 50, window_expected: 50, window_difference: 0, weeks_converted: 1 },
        { group_id: 2, name: 'Grade 3', suppressed: true },
    ],
    totals: { minted: 50, redeemed: 10, reversed: 0, cashed_out: 0, expired: 0, outstanding: 40, children_holding: 6, negative_balances: 0, window_minted: 50, window_expected: 50, window_difference: 0 },
};

async function mountOffice(put: (url: string, body: any) => Promise<any>) {
    const calls: Array<{ verb: string; url: string; body?: any }> = [];
    const api = {
        get: async (url: string) => { calls.push({ verb: 'get', url }); return url.includes('reconciliation') ? ok(recon) : ok([certificate]); },
        post: async (url: string, body: any) => { calls.push({ verb: 'post', url, body }); return ok({}); },
        put: (url: string, body: any) => { calls.push({ verb: 'put', url, body }); return put(url, body); },
    };
    const screen = await mountSfc('views/dashboard/ClassStoreView.vue', {}, {
        '@/components/PageDataContainer.vue': { default: { setup: (_p: any, { slots }: any) => () => slots.default?.() } },
        '@/core/services/ApiService': { default: api },
        '@/core/services/ApiErrors': ApiErrors,
        '@/core/types/config/BackendApiRoutes': {},
        '@/core/helpers/classStore': classStore,
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 7 }) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 7 } }) },
    });
    await flush();

    return { screen, calls };
}

test('office: a save refused because the count moved says so, and Save is off while it is in flight', async () => {
    const answer = deferred();
    const { screen, calls } = await mountOffice(() => answer.promise);
    const form = screen.all((n) => n.tag === 'form')[0];

    click(screen.button('Edit'));
    await flush();
    type(screen.all((n) => n.props.id === 'cs-o-stock')[0], '10');
    submit(form);
    await flush();

    assert.equal(screen.button('Save').disabled, true, 'Save is off while the write is in flight');
    submit(form); // a second submit (Enter pressed again) is refused by the handler's own guard
    await flush();
    const puts = calls.filter((c) => c.verb === 'put');
    assert.equal(puts.length, 1);
    // Form-encoded: the count it opened at goes with the new one.
    assert.equal(puts[0].body.stock, 10);
    assert.equal(puts[0].body.expected_stock, 4);

    answer.reject(httpError(409, { status: 'error', reason: 'stock_changed', message: 'The number left changed to 3 while this was being edited.' }));
    await flush();
    assert.match(screen.text(), /The number left changed to 3/);
    assert.equal(screen.button('Save').disabled, false);
    screen.unmount();
});

test('office: a class too small to show is named with no figure, and the totals say they cover the classes shown', async () => {
    const { screen } = await mountOffice(async () => ok({}));

    const rows = screen.all((n) => n.tag === 'tr' && n.textContent.startsWith('Grade 3'));
    assert.equal(rows.length, 1);
    assert.match(rows[0].textContent, /Fewer than 5 students: not shown/);
    assert.doesNotMatch(rows[0].textContent, /\d{2}/, 'no figure on the small class');
    assert.match(screen.text(), /Classes shown 50/);
    screen.unmount();
});
