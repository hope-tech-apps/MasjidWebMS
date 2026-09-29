/**
 * The class screen's per-child loops when the parent switches schools mid-run.
 *
 * FamilyLayout unmounts the class screen on a school switch, but the loops keep
 * going, and they used to read `base` off the shared route after every await:
 * the next child's request went to the NEW school under the new school's token,
 * and a 403 there ("that is not your child") ended the new school's session.
 * These tests drive the real loops with a route that moves mid-run.
 *
 * Run: npm run test:spa
 */
import { test, before, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { register } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

register(
    'data:text/javascript,' + encodeURIComponent(`
        export async function resolve(specifier, context, next) {
            if (specifier.startsWith('@/')) {
                return next(${JSON.stringify(pathToFileURL(appRoot).href)} + '/' + specifier.slice(2) + '.ts', context);
            }
            return next(specifier, context);
        }
    `),
);

const data = new Map<string, string>();
(globalThis as any).localStorage = {
    getItem: (k: string) => (data.has(k) ? data.get(k)! : null),
    setItem: (k: string, v: string) => void data.set(k, String(v)),
    removeItem: (k: string) => void data.delete(k),
};

let FamilyApiService: any;
let run: any;
let computed: any;
let ref: any;

const sent: { url: string; auth: string | null }[] = [];
/** What the fake network answers for a URL; default 200 with an empty body. */
let respond: (url: string, n: number) => { status: number; data?: any } = () => ({ status: 200, data: { data: [] } });
/** Runs as each request is answered, so a test can move the route mid-run. */
let onRequest: (n: number) => void = () => {};

before(async () => {
    ({ default: FamilyApiService } = await import('@/core/services/FamilyApiService' as string));
    run = await import('@/views/family/familyClassRun' as string);
    ({ computed, ref } = await import('vue'));

    FamilyApiService.init('https://manara.example.test');
    FamilyApiService.client.defaults.adapter = async (config: any) => {
        sent.push({
            url: config.url,
            auth: config.headers.get?.('Authorization') ?? config.headers.Authorization ?? null,
        });
        onRequest(sent.length);
        const { status, data: body } = respond(config.url, sent.length);
        const response = { data: body ?? {}, status, statusText: '', headers: {}, config, request: {} };
        if (status >= 400) {
            const err: any = new Error(`Request failed with status code ${status}`);
            err.config = config;
            err.response = response;
            throw err;
        }
        return response;
    };
});

beforeEach(() => {
    data.clear();
    sent.length = 0;
    respond = () => ({ status: 200, data: { data: [] } });
    onRequest = () => {};
    data.set('MANARA_FAMILY_SESSIONS', JSON.stringify({
        '7': { token: 'tok-7', contact: { id: 70, masjid_id: 7 } },
        '9': { token: 'tok-9', contact: { id: 90, masjid_id: 9 } },
    }));
});

/** The screen's own derivation, over a route we can move. */
function screen() {
    const route = ref({ masjidId: '7', groupId: '3' });
    const masjidId = computed(() => route.value.masjidId);
    const base = computed(() => `/api/family/masjids/${masjidId.value}/groups/${route.value.groupId}`);
    const state = { unmounted: false, failures: 0, failed: [] as any[] };
    const begin = (fail = (e: any) => { state.failed.push(e); return false; }) => run.beginClassRun({
        masjidId: () => masjidId.value,
        base: () => base.value,
        unmounted: () => state.unmounted,
        fail,
    });

    return { route, state, begin };
}

const children = [{ membership_id: 101 }, { membership_id: 102 }, { membership_id: 103 }];

const recordSinks = () => {
    const got: Record<string, any> = { records: {}, letters: {}, notes: {} };

    return {
        got,
        sinks: {
            alphabets: ['arabic', 'english'],
            hasWork: () => true,
            setRecords: (id: number, v: any) => { got.records[id] = v; },
            setLetters: (id: number, v: any) => { got.letters[id] = v; },
            setArabicNotes: (id: number, v: any) => { got.notes[id] = v; },
        },
    };
};

const toSchool9 = () => sent.filter((r) => r.url.includes('/masjids/9/'));

test('records loop: a school switch after child 1 sends nothing to school B', async () => {
    const s = screen();
    const { got, sinks } = recordSinks();

    // Child 1's awards+hifz are requests 1 and 2; the parent switches as they land.
    onRequest = (n) => { if (n === 2) s.route.value = { masjidId: '9', groupId: 'undefined' }; };

    await run.loadChildRecordsFor(s.begin(), children, sinks);

    assert.equal(toSchool9().length, 0, 'not one request went to school B');
    assert.equal(sent.every((r) => r.url.startsWith('/api/family/masjids/7/groups/3/')), true);
    assert.equal(sent.every((r) => r.auth === 'Bearer tok-7'), true);
    assert.equal(sent.some((r) => r.url.includes('/members/102/') || r.url.includes('/members/103/')), false, 'children 2 and 3 were never asked about');
    assert.deepEqual(Object.keys(got.records), [], 'nothing is written for the screen that is gone');
});

test('records loop: a school switch during the letters step stops before the notes request', async () => {
    const s = screen();
    const { sinks } = recordSinks();

    // awards, hifz, then the two alphabets (3, 4); switch as the last one lands.
    onRequest = (n) => { if (n === 4) s.route.value = { masjidId: '9', groupId: '5' }; };

    await run.loadChildRecordsFor(s.begin(), children, sinks);

    assert.equal(toSchool9().length, 0);
    assert.equal(sent.some((r) => r.url.includes('arabic-notes')), false);
    assert.equal(sent.length, 4);
});

test('records loop: a 403 for a school left behind neither ends a session nor redirects', async () => {
    const s = screen();
    const { sinks } = recordSinks();

    // The parent has moved on by the time school A's answer arrives.
    onRequest = (n) => { if (n === 1) s.route.value = { masjidId: '9', groupId: '5' }; };
    respond = () => ({ status: 403 });

    await run.loadChildRecordsFor(s.begin(), children, sinks);

    assert.equal(s.state.failed.length, 0, 'the screen\'s session handler was never asked about a stale 403');
});

test('records loop: an unmounted screen stops even if the route still names the same school', async () => {
    const s = screen();
    const { sinks } = recordSinks();

    onRequest = (n) => { if (n === 2) s.state.unmounted = true; };

    await run.loadChildRecordsFor(s.begin(), children, sinks);

    assert.equal(sent.length, 2);
});

test('records loop, no switch: every child is loaded from the class it started at', async () => {
    const s = screen();
    const { got, sinks } = recordSinks();

    await run.loadChildRecordsFor(s.begin(), children, sinks);

    assert.deepEqual(Object.keys(got.records).sort(), ['101', '102', '103']);
    assert.equal(sent.every((r) => r.auth === 'Bearer tok-7'), true);
    // awards, hifz, two alphabets, notes = 5 per child.
    assert.equal(sent.length, 15);
});

test('records loop, a live 403 on the current school still goes to the session handler', async () => {
    const s = screen();
    const { sinks } = recordSinks();
    respond = () => ({ status: 403 });

    await run.loadChildRecordsFor(s.begin(() => { s.state.failures++; return true; }), children, sinks);

    assert.equal(s.state.failures, 1);
    assert.equal(sent.length, 2, 'stops after the redirect the handler asked for');
});

test('report cards: a switch mid-run asks school B for nothing and does not mark the tab loaded', async () => {
    const s = screen();
    const cards: Record<string, any> = {};
    onRequest = (n) => { if (n === 1) s.route.value = { masjidId: '9', groupId: '5' }; };

    const finished = await run.loadReportCardsFor(s.begin(), children, {
        setCards: (id: number, rows: any[]) => { cards[id] = rows; },
        markPartial: () => { throw new Error('a stale 200 is not a partial failure'); },
    });

    assert.equal(finished, false);
    assert.equal(toSchool9().length, 0);
    assert.equal(sent.length, 1);
    assert.deepEqual(cards, {});
});

test('report cards, no switch: all children are read and the run finishes', async () => {
    const s = screen();
    const cards: Record<string, any> = {};

    const finished = await run.loadReportCardsFor(s.begin(), children, {
        setCards: (id: number, rows: any[]) => { cards[id] = rows; },
        markPartial: () => {},
    });

    assert.equal(finished, true);
    assert.deepEqual(Object.keys(cards).sort(), ['101', '102', '103']);
    assert.equal(sent.every((r) => r.url.startsWith('/api/family/masjids/7/groups/3/members/')), true);
});

test('marks: a switch mid-run asks school B for nothing', async () => {
    const s = screen();
    const marks: Record<string, any> = {};
    onRequest = (n) => { if (n === 1) s.route.value = { masjidId: '9', groupId: '5' }; };

    const finished = await run.loadGradesFor(s.begin(), children, {
        setMarks: (id: number, m: any) => { marks[id] = m; },
        setLevels: () => {},
        empty: {},
        markError: () => { throw new Error('a stale answer is not an error'); },
    });

    assert.equal(finished, false);
    assert.equal(toSchool9().length, 0);
    assert.equal(sent.length, 1);
});

test('a run pins the class it began at even while the route moves within the same school', async () => {
    const s = screen();
    const started = s.begin();

    s.route.value = { masjidId: '7', groupId: '8' };

    assert.equal(started.base, '/api/family/masjids/7/groups/3');
    assert.equal(started.stale(), false);

    s.route.value = { masjidId: '9', groupId: '8' };
    assert.equal(started.stale(), true);
});
