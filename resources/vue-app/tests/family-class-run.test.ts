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
import { readFileSync } from 'node:fs';

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
let nextTick: any;
let watch: any;

const sent: { url: string; auth: string | null }[] = [];
/** What the fake network answers for a URL; default 200 with an empty body. */
let respond: (url: string, n: number) => { status: number; data?: any } = () => ({ status: 200, data: { data: [] } });
/** Runs as each request is answered, so a test can move the route mid-run. */
let onRequest: (n: number) => void = () => {};

before(async () => {
    ({ default: FamilyApiService } = await import('@/core/services/FamilyApiService' as string));
    run = await import('@/views/family/familyClassRun' as string);
    ({ computed, ref, nextTick, watch } = await import('vue'));

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
        groupId: () => route.value.groupId,
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

/** What the screen's hand-over hands the module: sinks that record, over a moving route. */
function handOverSinks() {
    const got = { begun: [] as any[], opened: [] as string[], failed: 0 };

    return {
        got,
        sinks: {
            name: 'Aisha Khan',
            begin: (token: string, context: any) => { got.begun.push({ token, context }); },
            open: (p: string) => { got.opened.push(p); },
            failed: () => { got.failed++; },
        },
    };
}

const studentSession = (url: string) => (
    url.endsWith('/student-session') ? { status: 200, data: { data: { token: 'student-tok' } } } : { status: 200, data: {} }
);

test('hand over, no switch: the session is stored for this school and class and child mode opens there', async () => {
    const s = screen();
    const { got, sinks } = handOverSinks();
    respond = studentSession;

    await run.handOverFor(s.begin(), children[0], sinks);

    assert.deepEqual(sent.map((r) => r.url), ['/api/family/masjids/7/groups/3/members/101/student-session']);
    assert.equal(sent[0].auth, 'Bearer tok-7');
    assert.deepEqual(got.begun, [{
        token: 'student-tok',
        context: { masjidId: '7', groupId: '3', membershipId: '101', name: 'Aisha Khan' },
    }]);
    assert.deepEqual(got.opened, ['/family/7/student/3/101']);
    assert.equal(got.failed, 0);
});

test('hand over: a school switch while the POST is out stores nothing and leaves the parent at school B', async () => {
    const s = screen();
    const { got, sinks } = handOverSinks();
    respond = studentSession;
    // The parent picks school B as school A's answer lands.
    onRequest = (n) => { if (n === 1) s.route.value = { masjidId: '9', groupId: '5' }; };

    await run.handOverFor(s.begin(), children[0], sinks);

    assert.deepEqual(got.begun, [], 'school A\'s session is not stored under school B\'s id');
    assert.deepEqual(got.opened, [], 'the parent is not pushed off school B');
    assert.equal(got.failed, 0);
    assert.equal(toSchool9().length, 0, 'nothing was asked of school B');
    assert.equal(sent.length, 1);
    assert.equal(sent[0].url, '/api/family/masjids/7/groups/3/members/101/student-session');
});

test('hand over: a switch to a school whose class id is unset does not turn into "undefined" in the stored context', async () => {
    const s = screen();
    const { got, sinks } = handOverSinks();
    respond = studentSession;
    onRequest = (n) => { if (n === 1) s.route.value = { masjidId: '9', groupId: 'undefined' }; };

    await run.handOverFor(s.begin(), children[0], sinks);

    assert.equal(JSON.stringify(got).includes('undefined'), false);
    assert.equal(got.begun.length, 0);
    assert.equal(got.opened.length, 0);
});

test('hand over: the route moving to another class of the SAME school still stores the class the POST was made for', async () => {
    const s = screen();
    const { got, sinks } = handOverSinks();
    respond = studentSession;
    onRequest = (n) => { if (n === 1) s.route.value = { masjidId: '7', groupId: '8' }; };

    await run.handOverFor(s.begin(), children[0], sinks);

    assert.equal(got.begun.length, 1);
    assert.equal(got.begun[0].context.groupId, '3', 'not the class the route names after the await');
    assert.equal(got.begun[0].context.masjidId, '7');
    assert.deepEqual(got.opened, ['/family/7/student/3/101']);
});

test('hand over: an unmounted screen stores nothing even if the route still names the same school', async () => {
    const s = screen();
    const { got, sinks } = handOverSinks();
    respond = studentSession;
    onRequest = (n) => { if (n === 1) s.state.unmounted = true; };

    await run.handOverFor(s.begin(), children[0], sinks);

    assert.deepEqual(got.begun, []);
    assert.deepEqual(got.opened, []);
});

test('hand over: a 403 for a school left behind neither ends a session nor shows an error', async () => {
    const s = screen();
    const { got, sinks } = handOverSinks();
    respond = () => ({ status: 403 });
    onRequest = (n) => { if (n === 1) s.route.value = { masjidId: '9', groupId: '5' }; };

    await run.handOverFor(s.begin(), children[0], sinks);

    assert.equal(s.state.failed.length, 0, 'the screen\'s session handler was never asked about a stale 403');
    assert.equal(got.failed, 0);
    assert.deepEqual(got.begun, []);
});

test('hand over, a live 403 on the current school goes to the session handler and shows no second error', async () => {
    const s = screen();
    const { got, sinks } = handOverSinks();
    respond = () => ({ status: 403 });

    await run.handOverFor(s.begin(() => { s.state.failures++; return true; }), children[0], sinks);

    assert.equal(s.state.failures, 1);
    assert.equal(got.failed, 0);
    assert.deepEqual(got.begun, []);
    assert.deepEqual(got.opened, []);
});

test('hand over, a live failure the session handler does not take shows the hand-over error', async () => {
    const s = screen();
    const { got, sinks } = handOverSinks();
    respond = () => ({ status: 500 });

    await run.handOverFor(s.begin(), children[0], sinks);

    assert.equal(got.failed, 1);
    assert.deepEqual(got.begun, []);
    assert.deepEqual(got.opened, []);
});

test('hand over: an answer with no token is a failure, not a stored "undefined" session', async () => {
    const s = screen();
    const { got, sinks } = handOverSinks();
    respond = () => ({ status: 200, data: {} });

    await run.handOverFor(s.begin(), children[0], sinks);

    assert.equal(got.failed, 1);
    assert.deepEqual(got.begun, []);
    assert.deepEqual(got.opened, []);
});

test('a run remembers the class it began at, not only its base path', async () => {
    const s = screen();
    const started = s.begin();

    s.route.value = { masjidId: '9', groupId: '8' };

    assert.equal(started.school, '7');
    assert.equal(started.group, '3');
});

// ---------------------------------------------------------------- story receipts

test('story seen: the ids go to the class the run began at, signed with that school\'s token', async () => {
    const s = screen();

    const ok = await run.recordStoriesSeenFor(s.begin(), [11, 12]);

    assert.equal(ok, true);
    assert.deepEqual(sent.map((r) => r.url), ['/api/family/masjids/7/groups/3/posts/seen']);
    assert.equal(sent[0].auth, 'Bearer tok-7');
});

test('story seen: nothing to say sends nothing', async () => {
    const s = screen();

    assert.equal(await run.recordStoriesSeenFor(s.begin(), []), false);
    assert.equal(sent.length, 0);
});

test('story seen: a run that is already stale sends nothing', async () => {
    const s = screen();
    const started = s.begin();
    s.route.value = { masjidId: '9', groupId: '5' };

    assert.equal(await run.recordStoriesSeenFor(started, [11]), false);
    assert.equal(sent.length, 0, 'school B is asked for nothing');
});

test('story seen: a school switch while the POST is out reports nothing and asks school B for nothing', async () => {
    const s = screen();
    onRequest = (n) => { if (n === 1) s.route.value = { masjidId: '9', groupId: '5' }; };

    assert.equal(await run.recordStoriesSeenFor(s.begin(), [11]), false);
    assert.equal(sent.length, 1);
    assert.equal(sent[0].url, '/api/family/masjids/7/groups/3/posts/seen');
    assert.equal(toSchool9().length, 0);
});

test('story seen: a long list is sent in server-sized chunks, and a switch between chunks stops the rest', async () => {
    const s = screen();
    const ids = Array.from({ length: 120 }, (_, i) => i + 1);
    const bodies: number[] = [];
    respond = () => ({ status: 200, data: { data: { recorded: 0 } } });

    assert.equal(await run.recordStoriesSeenFor(s.begin(), ids), true);
    assert.equal(sent.length, 3, '50 + 50 + 20');

    sent.length = 0;
    onRequest = (n) => { if (n === 1) s.route.value = { masjidId: '9', groupId: '5' }; };
    assert.equal(await run.recordStoriesSeenFor(s.begin(), ids), false);
    assert.equal(sent.length, 1, 'the second chunk is never sent');
    void bodies;
});

test('story seen: an expired session goes to the screen\'s handler; any other failure is silent', async () => {
    const s = screen();

    respond = () => ({ status: 401, data: { message: 'Unauthenticated.' } });
    assert.equal(await run.recordStoriesSeenFor(s.begin(), [11]), false);
    assert.equal(s.state.failed.length, 1, 'the handler was asked about the 401');

    respond = () => ({ status: 500, data: {} });
    assert.equal(await run.recordStoriesSeenFor(s.begin(), [11]), false);
    assert.equal(s.state.failed.length, 2, 'the handler decides; the helper shows nothing itself');
});

// ------------------------------------------------- story reads: WHEN the screen may report
// These drive `watchStoriesSeen`, the decision FamilyClass.vue wires to its refs.
// The rule: a read is recorded only once the Story section has been DRAWN. The
// posts arrive mid-way through the screen's load chain, while it still shows a
// spinner, so "the posts are here" is not "the parent has seen them".

/** The class screen's state for the reporter, over the same movable route. */
function storyScreen(over: Record<string, any> = {}) {
    const s = screen();
    const state = {
        tab: ref('story'),
        posts: ref([] as { id: number }[]),
        enabled: ref(true),
        loading: ref(true),
        error: ref(null as any),
        mayReceive: true,
        visible: true,
        begun: [] as string[],
        ...over,
    };
    const report = run.watchStoriesSeen({
        tab: state.tab,
        posts: state.posts,
        enabled: state.enabled,
        loading: state.loading,
        error: state.error,
        mayReceiveFeed: () => state.mayReceive,
        visible: () => state.visible,
        begin: () => { state.begun.push('begin'); return s.begin(); },
    });

    return Object.assign(state, { report, route: s.route });
}

const storySettle = async () => {
    for (let i = 0; i < 6; i++) await nextTick();
    await new Promise((r) => setTimeout(r, 0));
};

test('story reads: nothing is sent while the screen is still loading, however early the posts arrive', async () => {
    const v = storyScreen();

    // What onMounted does mid-chain: the posts and the switch land, /threads and
    // the per-child records are still to come, the spinner is still on screen.
    v.posts.value = [{ id: 11 }, { id: 12 }];
    await storySettle();
    assert.equal(sent.length, 0, 'the spinner is not a story on screen');

    // The visibilitychange trigger must not slip past either.
    await v.report();
    assert.equal(sent.length, 0);

    v.loading.value = false;
    await storySettle();
    assert.equal(sent.length, 1);
    assert.equal(sent[0].url, '/api/family/masjids/7/groups/3/posts/seen');
});

test('story reads: a load that fails records nothing at all', async () => {
    const v = storyScreen();

    v.posts.value = [{ id: 11 }, { id: 12 }];
    // /threads returns 500: the screen sets its error alert, then `finally` ends loading.
    v.error.value = { key: 'class_load_error' };
    v.loading.value = false;
    await storySettle();
    await v.report();

    assert.equal(sent.length, 0, 'the parent saw an error alert, not the stories or the notice');
});

test('story reads: the request goes only after the render that draws the notice', async () => {
    const order: string[] = [];
    // `begin` is the first thing the report does once it has decided to send.
    const v = storyScreen({ begun: { push: () => order.push('report') } });
    // A post-flush watcher stands in for the DOM patch that draws the notice and the articles.
    watch(v.loading, () => order.push('rendered'), { flush: 'post' });
    v.posts.value = [{ id: 11 }];
    await storySettle();

    v.loading.value = false;
    await storySettle();

    assert.deepEqual(order, ['rendered', 'report']);
});

test('story reads: only the Story tab reports, and coming back to it reports', async () => {
    const v = storyScreen();
    v.loading.value = false;
    v.tab.value = 'grades';
    v.posts.value = [{ id: 11 }];
    await storySettle();
    assert.equal(sent.length, 0, 'a parent who only opened Grades has not seen the stories');

    v.tab.value = 'story';
    await storySettle();
    assert.equal(sent.length, 1);
});

test('story reads: the switch off, a class without the feed, and a hidden page each send nothing', async () => {
    const off = storyScreen({ enabled: ref(false) });
    off.loading.value = false;
    off.posts.value = [{ id: 11 }];
    await storySettle();
    assert.equal(sent.length, 0, 'switch off');

    const noFeed = storyScreen({ mayReceive: false });
    noFeed.loading.value = false;
    noFeed.posts.value = [{ id: 11 }];
    await storySettle();
    assert.equal(sent.length, 0, 'no consent, no feed');

    const hidden = storyScreen({ visible: false });
    hidden.loading.value = false;
    hidden.posts.value = [{ id: 11 }];
    await storySettle();
    assert.equal(sent.length, 0, 'a background tab');

    hidden.visible = true;
    await hidden.report();
    assert.equal(sent.length, 1, 'the moment it is looked at');
});

test('story reads: a story already reported is not reported again; a new one is', async () => {
    const v = storyScreen();
    v.loading.value = false;
    v.posts.value = [{ id: 11 }];
    await storySettle();
    await v.report();
    assert.equal(sent.length, 1);

    v.posts.value = [{ id: 11 }, { id: 12 }];
    await storySettle();
    assert.equal(sent.length, 2, 'only the new one');
});

test('story reads: two triggers in the same tick send once, and a failed send is tried again', async () => {
    const v = storyScreen();
    v.loading.value = false;
    v.posts.value = [{ id: 11 }];
    await Promise.all([v.report(), v.report()]);
    await storySettle();
    assert.equal(sent.length, 1);

    const w = storyScreen();
    respond = () => ({ status: 500, data: {} });
    w.loading.value = false;
    w.posts.value = [{ id: 21 }];
    await storySettle();
    const afterFailure = sent.length;
    respond = () => ({ status: 200, data: { data: { recorded: 1 } } });
    await w.report();
    assert.equal(sent.length, afterFailure + 1, 'given back on failure');
});

test('story reads: FamilyClass.vue wires the reporter to the load state and never posts from the load chain', () => {
    const src = readFileSync(path.join(appRoot, 'views/family/FamilyClass.vue'), 'utf8');

    assert.match(src, /watchStoriesSeen\(\{[\s\S]*?\n\}\);/, 'the screen goes through the reporter');
    const wiring = src.match(/watchStoriesSeen\(\{[\s\S]*?\n\}\);/)![0];
    for (const key of ['tab,', 'posts,', 'loading,', 'error,', 'enabled: storyReadsEnabled']) {
        assert.ok(wiring.includes(key), `the reporter is given ${key}`);
    }
    assert.ok(!/recordStoriesSeenFor/.test(src), 'no call to the recorder from the screen: the load chain cannot report');
    assert.match(src, /<p v-if="storyReadsEnabled"[^>]*data-test="story-seen-notice"/, 'the notice shares the switch that lets the screen record');
});
