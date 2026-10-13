/**
 * One login, a role at each organisation: the SPA's half.
 *
 * One person can be Friday-lunch staff at one organisation and a teacher at
 * another. The server answers every `/user` in ONE area's voice, and lists the
 * places the login works in `areas` only for such a login. These tests run the
 * code that decides, from that, which area a page load lands in, what the switch
 * offers and does, and what each area asks the server for. Each names the
 * regression it fails against.
 *
 * The real auth store, the real helper, the real router guard and the real lunch
 * store are executed; only the transport, the browser and unrelated modules are
 * stood in. The switch itself (the picker and both shells) is in
 * staff-area-switch.test.ts, and a role taken away under an open shell in
 * staff-area-guard.test.ts.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as vue from 'vue';
import * as pinia from 'pinia';
import axios from 'axios';
import * as areas from '../core/helpers/staffAreas.ts';
import { httpError, loadTs } from './support/mountSfc.ts';
import {
    ALRAZI, BURLINGTON, HER_AREAS, SECOND_SCHOOL,
    administrator, inLunchVoice, inTeacherVoice, lunchOnly, teacherOnly, twoSchoolTeacher,
} from './support/twoRoleLogin.ts';

const read = (file: string): string => readFileSync(new URL(`../${file}`, import.meta.url), 'utf8');

/** The bodies the server refuses with; the PHP side pins them in OneLoginTwoRolesRealmTest. */
const GATE_REFUSAL = { status: 'failed', data: 'Unauthorized.' };
const TENANT_REFUSAL_IN_PRODUCTION = { status: 'error', message: 'Request failed.' };

// ------------------------------------------------------------ the fake browser

const KEYS = { token: 'MASJID_APP_AUTH_TOKEN', dashboard_masjid_id: 'MASJID_APP_DASHBOARD_MASJID_ID', staff_area: 'MASJID_APP_STAFF_AREA' };

function fakeStorage(initial: Record<string, string> = {}) {
    const data = new Map<string, string>(Object.entries(initial));

    return {
        data,
        getItem: (key: string) => (data.has(key) ? data.get(key)! : null),
        setItem: (key: string, value: string) => { data.set(key, String(value)); },
        removeItem: (key: string) => { data.delete(key); },
    };
}

type Answers = Record<string, any>;

/**
 * The real auth store over a scripted server. `answers` maps a route to the user
 * it answers with, or to a status it refuses with; a route not named refuses 401
 * with the gate's body, as an area does for a login it does not admit.
 */
async function signedIn(over: { path?: string; stored?: Record<string, string>; answers?: Answers; login?: any } = {}) {
    pinia.setActivePinia(pinia.createPinia());

    const storage = fakeStorage(over.stored);
    (globalThis as any).localStorage = storage;
    (globalThis as any).window = { location: { pathname: over.path ?? '/' } };

    const answers: Answers = { ...(over.answers ?? {}) };
    const calls: string[] = [];
    let staffProvider: (() => any) | null = null;

    const api = {
        setHeader() {},
        async get(url: string) {
            calls.push(url);
            const answer = url in answers ? answers[url] : 401;
            if (typeof answer === 'number') {
                throw httpError(answer, answer === 401 ? GATE_REFUSAL : answer === 403 ? TENANT_REFUSAL_IN_PRODUCTION : { message: 'Server Error' });
            }

            return { data: { status: 'success', data: answer } };
        },
        async post(url: string) {
            calls.push(`POST ${url}`);

            return { data: { status: 'success', data: { user: over.login, token: 'practice-token' } } };
        },
    };

    const loaded = await loadTs('stores/authStore.ts', {
        vue, pinia, axios: {},
        '@/core/constants/appConfigConstants': { LOCAL_STORAGE_KEYS: KEYS },
        '@/core/services/ApiService': { default: api },
        '@/core/types/config/SystemRoutes': {}, '@/core/types/data/Admin': {},
        '@/core/types/config/AxiosCustom': {}, '@/core/types/config/BackendApiRoutes': {},
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: null }) },
        '@/core/plugins/SweetAlerts2': { MSwal: { fire() {} } },
        // The app's own reader of a server sentence, so "the server's words" below is not a stand-in's.
        '@/assets/ts/swalMethods': await loadTs('assets/ts/swalMethods.ts', { '@/core/types/config/AxiosCustom': {}, axios }),
        '@/core/tenancy/tenantRequests': { bumpTenantEpoch() {}, forgetServerTenant() {}, serverTenantId: { value: null } },
        '@/core/tenancy/teacherSchoolGuard': { resetTeacherSchoolGuard() {}, provideSignedInStaff: (provider: () => any) => { staffProvider = provider; } },
        '@/core/types/data/Membership': await loadTs('core/types/data/Membership.ts', { '@/core/types/data/Masjid': {} }),
        '@/stores/plugins/tenantStoreReset': { resetTenantScopedStores: () => [] },
        '@/core/helpers/staffAreas': areas,
    });

    const auth = loaded.useAuthStore();

    return { auth, calls, storage, answers, staffProvider: () => staffProvider };
}

const ADMIN = '/api/admin/user';
const TEACHER = '/api/teacher/user';
const LUNCH = '/api/lunch/user';

// ------------------------------------------------------------------ the helper

test('an area is read from a kind, an address and a /user route, and from nothing else', () => {
    assert.equal(areas.areaOfKind('Teacher'), 'teacher');
    assert.equal(areas.areaOfKind('LunchStaff'), 'lunch');
    for (const kind of ['MasjidAdmin', 'SuperAdmin', 'User', 'teacher', '', null, undefined]) {
        assert.equal(areas.areaOfKind(kind), null, `${kind} has no scoped area`);
    }

    assert.equal(areas.areaOfPath('/teacher'), 'teacher');
    assert.equal(areas.areaOfPath('/teacher/classes/3?tab=points#top'), 'teacher');
    assert.equal(areas.areaOfPath('/lunch/help/lunch'), 'lunch');
    // The admin screen that LISTS teachers is not the teacher area, and neither is a look-alike.
    for (const path of ['/masjid/teachers', '/teachers', '/lunchroom', '/auth/sign-in', '/', '', null, 14]) {
        assert.equal(areas.areaOfPath(path), null, `${path} names no area`);
    }

    assert.equal(areas.areaOfEndpoint(TEACHER), 'teacher');
    assert.equal(areas.areaOfEndpoint(LUNCH), 'lunch');
    assert.equal(areas.areaOfEndpoint(ADMIN), null);
    assert.equal(areas.areaUserEndpoint('lunch'), LUNCH);
    assert.equal(areas.areaHome('teacher'), '/teacher');
    assert.equal(areas.areaHome('lunch'), '/lunch');
});

test('a request belongs to an area by its API prefix only', () => {
    assert.equal(areas.isAreaRequest('/api/teacher/masjids/14/groups', 'teacher'), true);
    assert.equal(areas.isAreaRequest('https://school.example/api/teacher/user', 'teacher'), true);
    assert.equal(areas.isAreaRequest('/api/lunch/masjids/1/jummah-lunch/menus', 'lunch'), true);
    // Boot asks an ADMIN route for every kind of login, and a switch asks the OTHER area.
    assert.equal(areas.isAreaRequest('/api/admin/masjids/1/', 'lunch'), false);
    assert.equal(areas.isAreaRequest('/api/lunch/user', 'teacher'), false);
    assert.equal(areas.isAreaRequest('/api/teachers', 'teacher'), false);
    assert.equal(areas.isAreaRequest(undefined, 'teacher'), false);
});

test('the places come from areas[] as the server sent them, and a row that cannot be opened is dropped', () => {
    assert.deepEqual(areas.staffPlaces(HER_AREAS), [
        { area: 'lunch', masjidId: 1, name: 'Burlington Masjid', label: 'Friday lunch · Burlington Masjid', home: '/lunch' },
        { area: 'teacher', masjidId: 14, name: 'Al-Razi School', label: 'Teacher · Al-Razi School', home: '/teacher' },
    ]);

    // Absent is the server's answer for every login with one kind of role.
    for (const none of [undefined, null, [], 'lunch', {}]) {
        assert.deepEqual(areas.staffPlaces(none), []);
        assert.equal(areas.hasStaffAreas(none), false);
    }
    assert.equal(areas.hasStaffAreas(HER_AREAS), true);

    const untidy = [
        { area: 'admin', masjid_id: 1, masjid: BURLINGTON },      // not an area this app has a shell for
        { area: 'teacher', masjid_id: 'x' },                       // names no organisation
        { area: 'teacher', masjid_id: 14, masjid: { name: '  ' } },
        { area: 'teacher', masjid_id: 14, masjid: ALRAZI },        // the same place twice
        null,
    ];
    assert.deepEqual(areas.staffPlaces(untidy).map((place) => place.label), ['Teacher · Organisation #14']);
});

test('the switch offers the places of the OTHER area, and knows where it is', () => {
    assert.deepEqual(areas.otherPlaces(HER_AREAS, 'teacher').map((place) => place.label), ['Friday lunch · Burlington Masjid']);
    assert.deepEqual(areas.otherPlaces(HER_AREAS, 'lunch').map((place) => place.label), ['Teacher · Al-Razi School']);
    // A shell that does not say which area it is offers nothing rather than everything.
    assert.deepEqual(areas.otherPlaces(HER_AREAS, undefined), []);
    assert.deepEqual(areas.otherPlaces(undefined, 'teacher'), []);

    assert.equal(areas.currentPlace(HER_AREAS, 'lunch', null)?.label, 'Friday lunch · Burlington Masjid');
    assert.equal(areas.currentPlace(HER_AREAS, 'teacher', 14)?.label, 'Teacher · Al-Razi School');

    const twoSchools = [...HER_AREAS, { area: 'teacher', masjid_id: 20, masjid: SECOND_SCHOOL }];
    assert.equal(areas.currentPlace(twoSchools, 'teacher', 20)?.label, 'Teacher · Second School');
    assert.equal(areas.currentPlace(twoSchools, 'teacher', null), null, 'two schools and none selected: it does not guess');
    assert.deepEqual(areas.otherPlaces(twoSchools, 'lunch').map((place) => place.label), ['Teacher · Al-Razi School', 'Teacher · Second School']);
});

test('the order boot asks in is today\'s unless an area is stored', () => {
    const todays = [ADMIN, TEACHER, LUNCH];

    assert.deepEqual(areas.userEndpointOrder(todays, null), todays);
    assert.deepEqual(areas.userEndpointOrder(todays, 'lunch'), [LUNCH, ADMIN, TEACHER]);
    assert.deepEqual(areas.userEndpointOrder(todays, 'teacher'), [TEACHER, ADMIN, LUNCH]);

    // No stored area: nothing is put first, WHATEVER the address says. A one-role
    // teacher who types /lunch must not have the lunch route asked first.
    assert.equal(areas.firstAreaToAsk(null, '/lunch'), null);
    assert.equal(areas.firstAreaToAsk('garbage', '/teacher'), null);
    // Stored: the address wins, then the stored area.
    assert.equal(areas.firstAreaToAsk('teacher', '/lunch'), 'lunch');
    assert.equal(areas.firstAreaToAsk('teacher', '/auth/sign-in'), 'teacher');
    assert.equal(areas.firstAreaToAsk('lunch', '/'), 'lunch');
});

test('a second ask happens only for a two-area login whose page is in its other area', () => {
    // She reloads /lunch, the teacher route answered first: ask lunch.
    assert.equal(areas.areaToAskInstead(inTeacherVoice(), '/lunch', 'teacher', [ADMIN, TEACHER]), 'lunch');
    // The page is in the area that answered.
    assert.equal(areas.areaToAskInstead(inTeacherVoice(), '/teacher/classes/3', 'teacher', [ADMIN, TEACHER]), null);
    // The page names no area.
    assert.equal(areas.areaToAskInstead(inTeacherVoice(), '/auth/sign-in', 'teacher', [ADMIN, TEACHER]), null);
    // A login with one kind of role has no `areas`: never asked twice.
    assert.equal(areas.areaToAskInstead(teacherOnly(), '/lunch', 'teacher', [ADMIN, TEACHER]), null);
    // The area the page names is not one of hers.
    assert.equal(areas.areaToAskInstead({ areas: [HER_AREAS[1]] }, '/lunch', 'teacher', [ADMIN, TEACHER]), null);
    // That area already refused in this pass: asking again would only refuse again.
    assert.equal(areas.areaToAskInstead(inTeacherVoice(), '/lunch', 'teacher', [LUNCH, ADMIN, TEACHER]), null);
});

test('the router starts somewhere else only for a browser that was in a two-area session', () => {
    // The lunch role went: the page is /lunch, the teacher area answered.
    assert.equal(areas.movedAreaHome('lunch', '/lunch', 'Teacher'), '/teacher');
    assert.equal(areas.movedAreaHome('teacher', '/teacher/classes/3', 'LunchStaff'), '/lunch');
    // Nothing went.
    assert.equal(areas.movedAreaHome('lunch', '/lunch', 'LunchStaff'), null);
    // No stored area: a teacher who types /lunch sees the page she always saw.
    assert.equal(areas.movedAreaHome(null, '/lunch', 'Teacher'), null);
    // The page names no scoped area, or nobody answered, or an administrator did.
    assert.equal(areas.movedAreaHome('lunch', '/masjid/teachers', 'Teacher'), null);
    assert.equal(areas.movedAreaHome('lunch', '/lunch', undefined), null);
    assert.equal(areas.movedAreaHome('lunch', '/lunch', 'MasjidAdmin'), null);
});

// ------------------------------------------- a login with one role: as today

test('a one-role login is identified by the same requests in the same order as before', async () => {
    const cases: Array<[string, string, Answers, string[], string]> = [
        ['an administrator', '/masjid', { [ADMIN]: administrator() }, [ADMIN], 'MasjidAdmin'],
        ['a teacher', '/teacher', { [TEACHER]: teacherOnly() }, [ADMIN, TEACHER], 'Teacher'],
        ['a two-school teacher', '/teacher/classes/3', { [TEACHER]: twoSchoolTeacher() }, [ADMIN, TEACHER], 'Teacher'],
        ['lunch staff', '/lunch', { [LUNCH]: lunchOnly() }, [ADMIN, TEACHER, LUNCH], 'LunchStaff'],
        // A teacher who types the lunch address: the lunch route is not asked at all.
        ['a teacher at /lunch', '/lunch', { [TEACHER]: teacherOnly() }, [ADMIN, TEACHER], 'Teacher'],
        ['lunch staff at /teacher', '/teacher', { [LUNCH]: lunchOnly() }, [ADMIN, TEACHER, LUNCH], 'LunchStaff'],
    ];

    for (const [who, path, answers, expected, kind] of cases) {
        const { auth, calls, storage } = await signedIn({ path, answers });

        await auth.fetchAuthUser();

        assert.deepEqual(calls, expected, `${who}: the requests and their order`);
        assert.equal(auth.user.type, kind, who);
        assert.equal(storage.data.has(KEYS.staff_area), false, `${who}: no area is ever stored`);
        assert.equal('areas' in auth.user, false, `${who}: the payload carries no areas`);
    }
});

test('a one-role login lands where it landed: the guard and both shells route by the same kind', async () => {
    let guard: any;
    const auth: any = { isAuthenticated: true, user: null, dashboardMasjidId: null };
    (globalThis as any).document.documentElement = { scroll: () => {} };

    await loadTs('router/router.ts', {
        'vue-router': { createRouter: () => ({ beforeEach: (fn: any) => { guard = fn; } }), createWebHistory: () => ({}) },
        '@/router/routes/routes': { default: [] }, '@/stores/authStore': { useAuthStore: () => auth },
        '@/stores/config/dashboardAsideStore': { useDashboardAsideStore: () => ({}) },
        '@/core/constants/dashboardAsideMenuItems': { MASJID_DASHBOARD_ASIDE_MENU: [], SUPER_DASHBOARD_ASIDE_MENU: [] },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: null }) },
        '@/core/constants/appConfigConstants': { LOCAL_STORAGE_KEYS: {} },
        '@/core/access/orgAccess': { hasGrant: () => false, moduleIsOff: () => false }, '@/core/pageTitle': { setPageTitle: () => {} },
    });
    const teacherRoutes = (await loadTs('router/routes/teacherRoutes.ts', { 'vue-router': {}, '@/stores/authStore': { useAuthStore: () => auth }, '@/core/services/TeacherApiService': { default: {} } })).default[0];
    const lunchRoutes = (await loadTs('router/routes/lunchRoutes.ts', { 'vue-router': {}, '@/stores/authStore': { useAuthStore: () => auth } })).default[0];

    const go = (to: any) => { let destination: any = 'through'; guard(to, {}, (next: any) => { destination = next ?? 'through'; }); return destination; };
    const signIn = { fullPath: '/auth/sign-in', meta: {} };
    const teacherShell = { fullPath: '/teacher', meta: teacherRoutes.meta };
    const lunchShell = { fullPath: '/lunch', meta: lunchRoutes.meta };

    // Each of these users is what the real store holds after the test above, by kind.
    for (const [user, home, own, ownEnter, other, otherEnter] of [
        [teacherOnly(), '/teacher', teacherShell, teacherRoutes.beforeEnter, lunchShell, lunchRoutes.beforeEnter],
        [lunchOnly(), '/lunch', lunchShell, lunchRoutes.beforeEnter, teacherShell, teacherRoutes.beforeEnter],
    ] as any[]) {
        auth.user = user;
        assert.equal(go(signIn), home, `${user.type}: signed in, sent home`);
        assert.equal(go(own), 'through');
        assert.equal(ownEnter(), true);
        // The other area's address is the page it always was, and nothing moves them off it.
        assert.equal(go(other), '/auth/401');
        assert.equal(otherEnter(), '/auth/401');
        assert.equal(areas.movedAreaHome(null, other.fullPath, user.type), null);
    }

    // And a two-area login is routed by the voice that answered, with no edit to the guard.
    auth.user = inLunchVoice();
    assert.equal(go(signIn), '/lunch');
    assert.equal(go(lunchShell), 'through');
    assert.equal(go(teacherShell), '/auth/401', 'in the lunch voice the teacher shell is not hers until its own /user answers');
    auth.user = inTeacherVoice();
    assert.equal(go(signIn), '/teacher');
    assert.equal(teacherRoutes.beforeEnter(), true);
});

test('signing in as a one-role login stores no area, and removes one left by the last person', async () => {
    const { auth, storage, calls } = await signedIn({ login: teacherOnly(), stored: { [KEYS.staff_area]: 'lunch' } });

    await auth.login('teacher@example.test', 'practice');

    assert.deepEqual(calls, ['POST /api/admin/login']);
    assert.equal(auth.user.type, 'Teacher');
    assert.equal(storage.data.has(KEYS.staff_area), false, 'another person\'s area in a shared browser is not kept');
});

// --------------------------------------- a login with two roles: where it lands

test('signing in lands a two-role login in the area the server chose, and remembers it', async () => {
    const { auth, storage } = await signedIn({ login: inLunchVoice() });

    await auth.login('shamina@example.test', 'practice');

    assert.equal(auth.user.type, 'LunchStaff', 'SignIn.vue lands by this, unedited');
    assert.equal(auth.isAuthenticated, true);
    assert.equal(storage.data.get(KEYS.staff_area), 'lunch');
});

test('order 1, no stored area: today\'s order, and the first area to answer is the one she is in', async () => {
    const { auth, calls, storage } = await signedIn({ path: '/teacher', answers: { [TEACHER]: inTeacherVoice(), [LUNCH]: inLunchVoice() } });

    await auth.fetchAuthUser();

    assert.deepEqual(calls, [ADMIN, TEACHER]);
    assert.equal(auth.user.type, 'Teacher');
    assert.equal(storage.data.get(KEYS.staff_area), 'teacher', 'the answer carried areas, so the area is now remembered');
});

test('order 2, a stored area and a page that names none: the stored area is asked first', async () => {
    const { auth, calls } = await signedIn({ path: '/auth/sign-in', stored: { [KEYS.staff_area]: 'lunch' }, answers: { [TEACHER]: inTeacherVoice(), [LUNCH]: inLunchVoice() } });

    await auth.fetchAuthUser();

    assert.deepEqual(calls, [LUNCH], 'one request, and it is the lunch one although the teacher route would also answer');
    assert.equal(auth.user.type, 'LunchStaff');
});

test('order 3, a stored area and a page in the other area: the page wins', async () => {
    const { auth, calls, storage } = await signedIn({ path: '/teacher/classes/3', stored: { [KEYS.staff_area]: 'lunch' }, answers: { [TEACHER]: inTeacherVoice(), [LUNCH]: inLunchVoice() } });

    await auth.fetchAuthUser();

    assert.deepEqual(calls, [TEACHER]);
    assert.equal(auth.user.type, 'Teacher');
    assert.equal(storage.data.get(KEYS.staff_area), 'teacher', 'two tabs, one in each area, each stay where they are on reload');
});

test('order 4, no stored area and a page in the area that did not answer first: that area is asked and its answer kept', async () => {
    // A lunch login made a teacher while signed in reloads her lunch board. The teacher
    // route is second in today's order and now answers; kept, it lands her on "401".
    const { auth, calls, storage } = await signedIn({ path: '/lunch', answers: { [TEACHER]: inTeacherVoice(), [LUNCH]: inLunchVoice() } });

    await auth.fetchAuthUser();

    assert.deepEqual(calls, [ADMIN, TEACHER, LUNCH]);
    assert.equal(auth.user.type, 'LunchStaff');
    assert.equal(auth.user.masjid.id, 1);
    assert.equal(storage.data.get(KEYS.staff_area), 'lunch');
});

test('order 4, when the area the page names will not answer after all, the first answer stands', async () => {
    const { auth, calls } = await signedIn({ path: '/lunch', answers: { [TEACHER]: inTeacherVoice() } });

    await auth.fetchAuthUser();

    assert.deepEqual(calls, [ADMIN, TEACHER, LUNCH]);
    assert.equal(auth.user.type, 'Teacher');
});

test('a role taken away: the page\'s area refuses, the other answers without areas, and the stored area is dropped', async () => {
    // What the server does after her lunch role is removed: /api/lunch/user is 401,
    // /api/teacher/user answers and no longer carries areas.
    const { auth, calls, storage } = await signedIn({ path: '/lunch', stored: { [KEYS.staff_area]: 'lunch' }, answers: { [TEACHER]: teacherOnly() } });

    await auth.fetchAuthUser();

    assert.deepEqual(calls, [LUNCH, ADMIN, TEACHER]);
    assert.equal(auth.user.type, 'Teacher');
    assert.equal(storage.data.has(KEYS.staff_area), false);
    // main.ts read the key before this ran, and starts the router in the area that answered.
    assert.equal(areas.movedAreaHome('lunch', '/lunch', auth.user.type), '/teacher');
});

test('signing out forgets the area', async () => {
    const { auth, storage } = await signedIn({ login: inLunchVoice() });
    await auth.login('shamina@example.test', 'practice');
    assert.equal(storage.data.get(KEYS.staff_area), 'lunch');

    auth.removeAuth();

    assert.equal(storage.data.has(KEYS.staff_area), false);
    assert.equal(storage.data.has(KEYS.token), false);
});

test('boot: the stored area is read before the session is re-identified, and the move is a navigation the router has finished before it is installed', () => {
    const boot = read('main.ts');

    const readsKey = boot.indexOf('localStorage.getItem(LOCAL_STORAGE_KEYS.staff_area)');
    const fetches = boot.indexOf('authStore.fetchAuthUser()');
    const decides = boot.indexOf('movedAreaHome(STAFF_AREA, window.location.pathname, authStore.user?.type)');
    const navigates = boot.indexOf('await router.replace(movedTo)');
    // The statement, not the comment above it that names it.
    const installs = boot.indexOf('app.use(router);');

    for (const [step, at] of Object.entries({ readsKey, fetches, decides, navigates, installs })) assert.ok(at > 0, `${step} is in main.ts`);
    assert.deepEqual([readsKey, fetches, decides, navigates, installs], [readsKey, fetches, decides, navigates, installs].sort((a, b) => a - b));

    // The address bar is not rewritten: the router read the address when router.ts was imported.
    assert.doesNotMatch(boot, /history\.replaceState/);
    // The key's name is the one the store reads.
    assert.match(read('core/constants/appConfigConstants.ts'), /staff_area: 'MASJID_APP_STAFF_AREA'/);
});

// ----------------------------------------- each area calls its own API prefix

test('each voice selects its own organisation, so a stored id from the other area is never used', async () => {
    // The shared key holds the SCHOOL (she was last in the teacher shell). In the lunch
    // voice memberships is the masjid only, so the school's id is dropped on load.
    let s = await signedIn({ path: '/lunch', stored: { [KEYS.staff_area]: 'lunch', [KEYS.dashboard_masjid_id]: '14' }, answers: { [LUNCH]: inLunchVoice() } });
    await s.auth.fetchAuthUser();
    assert.equal(Number(s.auth.dashboardMasjidId), 1);
    assert.equal(s.storage.data.get(KEYS.dashboard_masjid_id), '1');

    // And the mirror: the masjid's id left behind, the teacher voice loading.
    s = await signedIn({ path: '/teacher', stored: { [KEYS.staff_area]: 'teacher', [KEYS.dashboard_masjid_id]: '1' }, answers: { [TEACHER]: inTeacherVoice() } });
    await s.auth.fetchAuthUser();
    assert.equal(Number(s.auth.dashboardMasjidId), 14);
});

test('the lunch board asks the lunch prefix at her lunch organisation, and the office asks the admin prefix', async () => {
    const requests: string[] = [];
    const api = { get: async (url: string) => { requests.push(url); return { data: { data: [] } }; } };
    const office = { masjid: { id: 14 } };

    async function board(user: any) {
        pinia.setActivePinia(pinia.createPinia());
        const { useJummahLunchStore } = await loadTs('stores/masjid/jummahLunchStore.ts', {
            vue, pinia, axios: {}, '../masjidStore': { useMasjidStore: () => office },
            '@/stores/authStore': { useAuthStore: () => ({ user }) }, '@/core/services/ApiService': { default: api },
        });

        return useJummahLunchStore();
    }

    // Lunch voice. masjidStore holds the SCHOOL here on purpose: the board must not read it.
    let store = await board(inLunchVoice());
    await store.fetchMenus();
    assert.equal(store.isLunchStaff(), true);
    assert.equal(store.organisationId(), 1);
    assert.deepEqual(requests, ['/api/lunch/masjids/1/jummah-lunch/menus']);

    // The same store for an administrator: the admin prefix, the organisation the office is in.
    requests.length = 0;
    store = await board(administrator());
    await store.fetchMenus();
    assert.deepEqual(requests, ['/api/admin/masjids/14/jummah-lunch/menus']);

    // The store's source is not edited by this feature: it reads the voice the server answered in.
    assert.doesNotMatch(read('stores/masjid/jummahLunchStore.ts'), /areas|staffAreas/);
});

test('the guides of each shell are asked under that shell\'s prefix, at that area\'s organisation', () => {
    const screen = read('views/guides/GuideScreen.vue');

    assert.match(screen, /const organisationId = computed\(\(\) => props\.realm === 'lunch' \? auth\.user\?\.masjid\?\.id : auth\.dashboardMasjidId\);/);
    assert.match(screen, /const base = `\/api\/\$\{props\.realm\}\/masjids\/\$\{id\}\/guides`;/);
    assert.match(read('router/routes/lunchRoutes.ts'), /props: \{ realm: 'lunch' \}/);
    assert.match(read('router/routes/teacherRoutes.ts'), /props: \{ realm: 'teacher' \}/);
});

// ----------------------------------------------- asking an area before moving

test('the area a switch is moving to is asked on its own /user, and a refusal comes back in the server\'s words', async () => {
    // It answers: nothing to say.
    let s = await signedIn({ path: '/teacher', answers: { [TEACHER]: inTeacherVoice(), [LUNCH]: inLunchVoice() } });
    await s.auth.fetchAuthUser();
    s.calls.length = 0;
    assert.equal(await s.auth.askStaffArea('lunch'), null);
    assert.deepEqual(s.calls, [LUNCH]);
    assert.equal(s.auth.user.type, 'Teacher', 'asking does not move her');

    // The gate refuses (her lunch role was removed since the page loaded): the gate's own word.
    s = await signedIn({ path: '/teacher', answers: { [TEACHER]: inTeacherVoice() } });
    await s.auth.fetchAuthUser();
    s.answers[TEACHER] = teacherOnly();           // what the teacher route says once the lunch role is gone
    s.calls.length = 0;
    assert.equal(await s.auth.askStaffArea('lunch'), 'Unauthorized.');
    // ...and this area is asked again, so the place that has gone leaves the switch.
    assert.deepEqual(s.calls, [LUNCH, TEACHER]);
    assert.equal('areas' in s.auth.user, false);
    assert.equal(s.storage.data.has(KEYS.staff_area), false);

    // A 403 outside debug says "Request failed." and that, not a guess, is what is shown.
    s = await signedIn({ path: '/lunch', stored: { [KEYS.staff_area]: 'lunch' }, answers: { [LUNCH]: inLunchVoice(), [TEACHER]: 403 } });
    await s.auth.fetchAuthUser();
    assert.equal(await s.auth.askStaffArea('teacher'), 'Request failed.');
});
