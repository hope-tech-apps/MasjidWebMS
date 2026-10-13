/**
 * One login, a role at each organisation: signing out of an area that has just gone.
 *
 * A login that is lunch staff at one organisation and a teacher at another has ONE
 * token, and it is the session of both areas. Each shell signs out by posting its
 * own area's `/logout`, and that route sits behind the area's gate. So when the
 * role behind the open shell has just been taken away (or the server's switch was
 * turned off) and the person's next click is Sign out, the gate answers 401, the
 * server deletes nothing, and the shell used to clear the browser and show the
 * sign-in page all the same: the token went on working in the other area for up
 * to eight hours while its owner believed they had left.
 *
 * What the shells do now, executed here: the area guard takes that refusal without
 * asking or reloading (a reload would cancel the request that follows), and the
 * shell hands it to the store, which ends the session through the area that still
 * admits the login, BEFORE anything on this browser is cleared. A login with one
 * kind of role signs out with the one request it always made.
 *
 * The server's two 401 bodies (the gate's, and a dead session's) are pinned on the
 * PHP side: OneLoginTwoRolesRealmTest::a_sign_out_the_area_refuses_...
 * Each test names the regression it fails against.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as vue from 'vue';
import * as pinia from 'pinia';
import axios from 'axios';
import * as areas from '../core/helpers/staffAreas.ts';
import * as schools from '../core/helpers/teacherSchools.ts';
import { createStaffAreaGuard } from '../core/tenancy/staffAreaGuardCore.ts';
import { createTeacherResponseHandlers } from '../core/tenancy/teacherSchoolGuardCore.ts';
import { click, compileSfc, flush, httpError, loadTs, mountSfc } from './support/mountSfc.ts';
import { inLunchVoice, inTeacherVoice, lunchOnly, teacherOnly } from './support/twoRoleLogin.ts';

const read = (file: string): string => readFileSync(new URL(`../${file}`, import.meta.url), 'utf8');

/** The two 401s a sign-out can meet, as the server sends them. */
const GATE = { status: 'failed', data: 'Unauthorized.' };
const DEAD_SESSION = { status: 'error', message: 'Unauthenticated.' };

const gateRefused = (url: string) => ({ ...httpError(401, GATE), config: { url } });
const deadSession = (url: string) => ({ ...httpError(401, DEAD_SESSION), config: { url } });

// ------------------------------------------------------------------ the rule

test('a sign-out the gate refused is finished through the other area; a dead session and everything else is not', () => {
    assert.equal(areas.areaLogoutEndpoint('teacher'), '/api/teacher/logout');
    assert.equal(areas.areaLogoutEndpoint('lunch'), '/api/lunch/logout');

    // Regression: no second request, so the token outlived the sign-out.
    assert.equal(areas.areaToSignOutThrough('teacher', gateRefused('/api/teacher/logout')), 'lunch');
    assert.equal(areas.areaToSignOutThrough('lunch', gateRefused('/api/lunch/logout')), 'teacher');

    // Any 401 that does not say the session is dead is tried: a missed one leaves a session alive.
    assert.equal(areas.areaToSignOutThrough('teacher', httpError(401, {})), 'lunch');
    assert.equal(areas.areaToSignOutThrough('teacher', httpError(401, { status: 'error', message: 'Request failed.' })), 'lunch');
    assert.equal(areas.areaToSignOutThrough('teacher', { response: { status: 401 } }), 'lunch');

    // A dead session has nothing left to end anywhere: a one-role login whose token expired makes no second request.
    assert.equal(areas.areaToSignOutThrough('teacher', deadSession('/api/teacher/logout')), null);
    assert.equal(areas.areaToSignOutThrough('lunch', deadSession('/api/lunch/logout')), null);

    // Not a refusal about who this is.
    for (const other of [httpError(403, GATE), httpError(419, {}), httpError(500, {}), { message: 'Network Error' }, null, undefined, 'x']) {
        assert.equal(areas.areaToSignOutThrough('teacher', other), null);
    }

    // No area, no other area. (An administrator signs out through the admin route, which has no gate of this kind.)
    for (const area of ['admin', '', null, undefined, 14]) {
        assert.equal(areas.areaToSignOutThrough(area, gateRefused('/api/admin/logout')), null);
    }
});

test('a sign-out is told from every other request by its route, on any host', () => {
    assert.equal(areas.isAreaSignOut('/api/teacher/logout'), true);
    assert.equal(areas.isAreaSignOut('/api/lunch/logout'), true);
    assert.equal(areas.isAreaSignOut('https://app.example.test/api/lunch/logout'), true);
    assert.equal(areas.isAreaSignOut('/api/lunch/logout?x=1'), true);

    for (const url of ['/api/teacher/user', '/api/admin/logout', '/api/teacher/masjids/14/logout', '/api/lunch/logout/extra', '/logout', '', null, undefined, 3]) {
        assert.equal(areas.isAreaSignOut(url), false, `${url}`);
    }
});

// ------------------------------------------------------------------ the guard

/** The real guard over recorded side effects. `verdict` is what the area would say if it were asked. */
function guardFor(who: any) {
    const log = { asks: 0, reloads: 0, stamps: 0, stillRefused: 0 };

    const guard = createStaffAreaGuard({
        signedIn: () => who,
        areaOfKind: areas.areaOfKind, hasStaffAreas: areas.hasStaffAreas,
        isAreaRequest: areas.isAreaRequest, isAreaSignOut: areas.isAreaSignOut,
        mayReloadAfterRefusal: schools.mayReloadAfterRefusal,
        reask: async () => { log.asks++; return 'gone'; },
        reloadStamp: { read: () => null, write: () => { log.stamps++; }, clear: () => {} },
        now: () => 5_000_000,
        reload: () => { log.reloads++; },
        stillRefused: () => { log.stillRefused++; },
        warn: () => {},
    });

    return { guard, log };
}

test('a refused sign-out is taken by the area guard with nothing asked and nothing reloaded', async () => {
    // Regression: the guard treated it like any refusal in its area, asked /user (also refused) and
    // reloaded the page, which cancels the other area's sign-out on its way out.
    for (const [who, url] of [[inTeacherVoice(), '/api/teacher/logout'], [inLunchVoice(), '/api/lunch/logout']] as const) {
        const { guard, log } = guardFor(who);

        assert.equal(await guard.handleRefusal(gateRefused(url)), true, `${url}: taken`);
        assert.deepEqual(log, { asks: 0, reloads: 0, stamps: 0, stillRefused: 0 });

        // Any OTHER refusal in the same area is still asked about, and reloads once.
        assert.equal(await guard.handleRefusal(gateRefused(url.replace('/logout', '/user'))), true);
        assert.deepEqual(log, { asks: 1, reloads: 1, stamps: 1, stillRefused: 0 });
    }
});

test('a login with one kind of role is still never the guard\'s, sign-out included', () => {
    for (const who of [teacherOnly(), lunchOnly(), null]) {
        const { guard, log } = guardFor(who);

        assert.equal(guard.handleRefusal(deadSession('/api/teacher/logout')), null);
        assert.equal(guard.handleRefusal(deadSession('/api/lunch/logout')), null);
        assert.equal(log.asks + log.reloads, 0);
    }

    // And the wiring hands the real rule to the real guard.
    const wiring = read('core/tenancy/teacherSchoolGuard.ts');
    assert.match(wiring, /import \{ areaOfKind, hasStaffAreas, isAreaRequest, isAreaSignOut \} from "@\/core\/helpers\/staffAreas";/);
    assert.match(wiring.slice(wiring.indexOf('const areaGuard = createStaffAreaGuard({')), /isAreaRequest,\s+isAreaSignOut,/);
});

test('the teacher client does not push the sign-in page for her refused sign-out, and still rejects it to the shell', async () => {
    // Regression: with the refusal not taken, the client pushed /auth/sign-in while she was still signed in;
    // the router sends a signed-in teacher back to /teacher, whose first requests are refused and reload the page.
    const { guard } = guardFor(inTeacherVoice());
    const pushed: string[] = [];

    // TeacherApiService's own wiring, with the router stood in (the lines are pinned in staff-area-guard.test.ts).
    const handlers = createTeacherResponseHandlers({
        isFromSupersededEpoch: () => false,
        dropSupersededResponse: async () => { throw new Error('not superseded'); },
        checkEcho: () => {},
        handleRefusal: (error: any) => guard.handleRefusal(error),
        onUnauthorized: async (error: unknown) => {
            if (await guard.handleRefusal(error)) return;
            pushed.push('/auth/sign-in');
        },
    });

    const refusal = gateRefused('/api/teacher/logout');
    await assert.rejects(handlers.onRejected(refusal), (thrown) => thrown === refusal);
    assert.deepEqual(pushed, []);

    // A one-role teacher whose token has expired is sent to sign in, as always.
    const plain = guardFor(teacherOnly()).guard;
    const plainHandlers = createTeacherResponseHandlers({
        isFromSupersededEpoch: () => false,
        dropSupersededResponse: async () => { throw new Error('not superseded'); },
        checkEcho: () => {},
        handleRefusal: (error: any) => plain.handleRefusal(error),
        onUnauthorized: async (error: unknown) => {
            if (await plain.handleRefusal(error)) return;
            pushed.push('/auth/sign-in');
        },
    });
    await assert.rejects(plainHandlers.onRejected(deadSession('/api/teacher/logout')));
    assert.deepEqual(pushed, ['/auth/sign-in']);
});

// ------------------------------------------------------------------ the store

/** The real auth store over a server that records what it is sent. `refuse` makes a route's POST throw. */
async function store(refuse: Record<string, any> = {}) {
    pinia.setActivePinia(pinia.createPinia());

    const data = new Map<string, string>();
    (globalThis as any).localStorage = {
        getItem: (key: string) => (data.has(key) ? data.get(key)! : null),
        setItem: (key: string, value: string) => { data.set(key, String(value)); },
        removeItem: (key: string) => { data.delete(key); },
    };
    (globalThis as any).window = { location: { pathname: '/teacher' } };

    const calls: string[] = [];
    const api = {
        setHeader() {},
        async get(url: string) { calls.push(`GET ${url}`); throw httpError(401, GATE); },
        async post(url: string, body: unknown) {
            calls.push(`POST ${url}`);
            assert.deepEqual(body, {}, 'a sign-out carries nothing');
            if (url in refuse) throw refuse[url];

            return { data: {} };
        },
    };

    const loaded = await loadTs('stores/authStore.ts', {
        vue, pinia, axios: {},
        '@/core/constants/appConfigConstants': { LOCAL_STORAGE_KEYS: { token: 'T', dashboard_masjid_id: 'D', staff_area: 'A' } },
        '@/core/services/ApiService': { default: api },
        '@/core/types/config/SystemRoutes': {}, '@/core/types/data/Admin': {},
        '@/core/types/config/AxiosCustom': {}, '@/core/types/config/BackendApiRoutes': {},
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: null }) },
        '@/core/plugins/SweetAlerts2': { MSwal: { fire() {} } },
        '@/assets/ts/swalMethods': await loadTs('assets/ts/swalMethods.ts', { '@/core/types/config/AxiosCustom': {}, axios }),
        '@/core/tenancy/tenantRequests': { bumpTenantEpoch() {}, forgetServerTenant() {}, serverTenantId: { value: null } },
        '@/core/tenancy/teacherSchoolGuard': { resetTeacherSchoolGuard() {}, provideSignedInStaff() {} },
        '@/core/types/data/Membership': await loadTs('core/types/data/Membership.ts', { '@/core/types/data/Masjid': {} }),
        '@/stores/plugins/tenantStoreReset': { resetTenantScopedStores: () => [] },
        '@/core/helpers/staffAreas': areas,
    });

    return { auth: loaded.useAuthStore(), calls };
}

test('the store ends the session through the other area, once, when the gate refused the sign-out', async () => {
    // Regression: no request, so the server kept the token.
    let s = await store();
    await s.auth.signOutThroughOtherArea('teacher', gateRefused('/api/teacher/logout'));
    assert.deepEqual(s.calls, ['POST /api/lunch/logout']);

    s = await store();
    await s.auth.signOutThroughOtherArea('lunch', gateRefused('/api/lunch/logout'));
    assert.deepEqual(s.calls, ['POST /api/teacher/logout']);
});

test('the store asks nothing for a dead session or a failure that is not a refusal: one-role logins sign out as they did', async () => {
    const s = await store();

    await s.auth.signOutThroughOtherArea('teacher', deadSession('/api/teacher/logout'));
    await s.auth.signOutThroughOtherArea('lunch', deadSession('/api/lunch/logout'));
    await s.auth.signOutThroughOtherArea('teacher', httpError(500, {}));
    await s.auth.signOutThroughOtherArea('lunch', { message: 'Network Error' });

    assert.deepEqual(s.calls, []);
});

test('the store never throws out of a sign-out, whatever the other area says', async () => {
    // The shell clears this browser in a `finally`, but a throw here would still skip nothing it
    // needs and must not surface as an unhandled error under the sign-in page.
    for (const failure of [gateRefused('/api/lunch/logout'), deadSession('/api/lunch/logout'), httpError(500, {}), new Error('Network Error')]) {
        const s = await store({ '/api/lunch/logout': failure });
        const warn = console.warn;
        console.warn = () => {};

        try {
            await s.auth.signOutThroughOtherArea('teacher', gateRefused('/api/teacher/logout'));
        } finally {
            console.warn = warn;
        }

        assert.deepEqual(s.calls, ['POST /api/lunch/logout'], 'tried once, not again');
    }
});

// ------------------------------------------------------------ the two shells

/** A real layout over the import map the other layout tests use, with its own sign-out answered as `own` says. */
async function shell(name: 'Teacher' | 'Lunch', user: any, own: { refusal?: any } = {}) {
    const log: string[] = [];
    const handed: any[] = [];
    const ownRoute = `/api/${name.toLowerCase()}/logout`;
    const picker = await compileSfc('components/teacher/TeacherSchoolPicker.vue', { '@/core/helpers/teacherSchools': schools, '@/core/helpers/staffAreas': areas });

    const post = async (url: string) => {
        log.push(`POST ${url}`);
        if (url === ownRoute && own.refusal) throw own.refusal;

        return {};
    };

    const auth: any = vue.reactive({
        user, dashboardMasjidId: user.masjid.id, isAuthenticated: true,
        askStaffArea: async () => null,
        saveDashboardMasjidId() {}, saveStaffArea() {},
        async signOutThroughOtherArea(area: string, refusal: unknown) {
            log.push(`signOutThroughOtherArea ${area}`);
            handed.push(refusal);
            // The store's request takes a turn of the event loop; clearing the browser must wait for it.
            await flush(2);
            log.push('the other area answered');
        },
        removeAuth() { log.push('removeAuth'); },
    });

    (globalThis as any).window = { location: { pathname: `/${name.toLowerCase()}`, assign() {} } };

    const mounted = await mountSfc(`layouts/${name}Layout.vue`, {}, {
        '@/core/services/ApiService': { default: { post } },
        '@/stores/authStore': { useAuthStore: () => auth },
        '@/core/pageTitle': { setOrgTitle() {} },
        'vue-router': { useRoute: () => vue.reactive({ path: `/${name.toLowerCase()}`, name: '' }), useRouter: () => ({ push(to: string) { log.push(`push ${to}`); } }) },
        '@/core/helpers/staffChrome': { useStaffChrome() {} },
        '@/core/services/TeacherApiService': { default: { get: async () => ({ data: { data: {} } }), post } },
        '@/components/teacher/TeacherSchoolPicker.vue': { default: picker },
        '@/components/dashboard/TenantMismatchNotice.vue': { default: { render: () => null } },
        '@/core/helpers/teacherSchools': schools,
        '@/core/tenancy/teacherSchoolGuard': { clearTeacherSchoolNotices() {}, provideSelectedSchool() {}, teacherSchoolMismatch: vue.ref(null), teacherSchoolRefused: vue.ref(false) },
        '@/core/tenancy/tenantRequests': { bumpTenantEpoch() {}, forgetServerTenant() {} },
        '@/stores/plugins/tenantStoreReset': { resetTenantScopedStores() {} },
    });
    await flush();

    return { ...mounted, log, handed, ownRoute };
}

test('both shells: a refused sign-out is handed to the store before the browser is cleared', async () => {
    // Regression: the catch was empty. The browser was cleared and the sign-in page shown with the
    // server's token untouched.
    for (const [name, user] of [['Teacher', inTeacherVoice()], ['Lunch', inLunchVoice()]] as const) {
        const refusal = gateRefused(`/api/${name.toLowerCase()}/logout`);
        const s = await shell(name, user, { refusal });

        click(s.button('Sign out'));
        await flush(12);

        assert.deepEqual(s.log, [
            `POST ${s.ownRoute}`,
            `signOutThroughOtherArea ${name.toLowerCase()}`,
            'the other area answered',
            'removeAuth',
            'push /auth/sign-in',
        ], `${name}: the order`);
        assert.equal(s.handed[0], refusal, `${name}: the store is handed the refusal itself, to tell a gate from a dead session`);
        s.unmount();
    }
});

test('both shells: a sign-out the server accepts is one request, and the store is not asked anything', async () => {
    for (const [name, user] of [['Teacher', teacherOnly()], ['Lunch', lunchOnly()], ['Teacher', inTeacherVoice()], ['Lunch', inLunchVoice()]] as const) {
        const s = await shell(name, user);

        click(s.button('Sign out'));
        await flush(12);

        assert.deepEqual(s.log, [`POST ${s.ownRoute}`, 'removeAuth', 'push /auth/sign-in'], name);
        s.unmount();
    }
});

test('both shells: the real store, handed a one-role login\'s dead session, makes no second request', async () => {
    // The shell and the store together, for the login that is nearly every login.
    for (const name of ['Teacher', 'Lunch'] as const) {
        const real = await store();
        const refusal = deadSession(`/api/${name.toLowerCase()}/logout`);

        await real.auth.signOutThroughOtherArea(name.toLowerCase() as areas.StaffArea, refusal);

        assert.deepEqual(real.calls, []);
    }

    // Neither layout imports anything new for this: the store they already had does it.
    for (const layout of ['layouts/TeacherLayout.vue', 'layouts/LunchLayout.vue']) {
        assert.doesNotMatch(read(layout), /staffAreas/, `${layout} still imports no area helper`);
    }
});
