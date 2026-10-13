/**
 * A role taken away while its shell is open (core/tenancy/staffAreaGuardCore.ts).
 *
 * One login can be lunch staff at one organisation and a teacher at another, on
 * one token. Removing one role does not end the token, so the next request in
 * that area's shell is refused while the other area keeps answering. These tests
 * run what the shell does then, and what it must NOT do for a login with one
 * role or for a refusal that is only about a class. Each names its regression.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as vue from 'vue';
import * as pinia from 'pinia';
import { areaOfKind, hasStaffAreas, isAreaRequest } from '../core/helpers/staffAreas.ts';
import * as areas from '../core/helpers/staffAreas.ts';
import { mayReloadAfterRefusal } from '../core/helpers/teacherSchools.ts';
import { createStaffAreaGuard } from '../core/tenancy/staffAreaGuardCore.ts';
import { createTeacherResponseHandlers } from '../core/tenancy/teacherSchoolGuardCore.ts';
import { flush, httpError, loadTs } from './support/mountSfc.ts';

const read = (file: string): string => readFileSync(new URL(`../${file}`, import.meta.url), 'utf8');

const NOW = 5_000_000;

const HER_AREAS = [
    { area: 'lunch', masjid_id: 1, masjid: { id: 1, name: 'Burlington Masjid' } },
    { area: 'teacher', masjid_id: 14, masjid: { id: 14, name: 'Al-Razi School' } },
];

const twoRoleTeacher = { type: 'Teacher', areas: HER_AREAS };
const twoRoleLunch = { type: 'LunchStaff', areas: HER_AREAS };
const oneRoleTeacher = { type: 'Teacher' };

/** The guard over fake browser pieces, with every side effect recorded. */
function harness(over: { who?: any; verdict?: 'here' | 'moved' | 'gone' | Error; stamp?: unknown } = {}) {
    const log = { asks: 0, reloads: 0, stillRefused: 0, warnings: 0, stamps: [] as number[] };
    let stamp: unknown = over.stamp ?? null;
    let clock = NOW;
    let verdict = over.verdict ?? 'gone';
    let who = 'who' in over ? over.who : twoRoleTeacher;

    const guard = createStaffAreaGuard({
        signedIn: () => who,
        areaOfKind, hasStaffAreas, isAreaRequest, mayReloadAfterRefusal,
        reask: async () => {
            log.asks++;
            if (verdict instanceof Error) throw verdict;

            return verdict;
        },
        reloadStamp: { read: () => stamp, write: (ms) => { stamp = ms; log.stamps.push(ms); }, clear: () => { stamp = null; } },
        now: () => clock,
        reload: () => { log.reloads++; },
        stillRefused: () => { log.stillRefused++; },
        warn: () => { log.warnings++; },
    });

    return {
        guard, log,
        advance: (ms: number) => { clock += ms; },
        says: (next: typeof verdict) => { verdict = next; },
        becomes: (next: any) => { who = next; },
    };
}

const refused = (status: number, url: string, data: any = {}) => ({ response: { status, data }, config: { url } });

/** What the server sends; the PHP side pins these bodies in OneLoginTwoRolesRealmTest. */
const gate401 = (url: string) => refused(401, url, { status: 'failed', data: 'Unauthorized.' });
const tenant403 = (url: string) => refused(403, url, { status: 'error', message: 'Request failed.' });
const class403InDebug = (url: string) => refused(403, url, { status: 'error', message: 'You do not lead this class.' });

// ------------------------------------------------------ whose refusal it is

test('a login with one kind of role is never this guard\'s: null at once, nothing asked', () => {
    for (const who of [oneRoleTeacher, { type: 'LunchStaff' }, { type: 'MasjidAdmin' }, { type: 'Teacher', areas: [] }, null, undefined]) {
        const h = harness({ who });

        assert.equal(h.guard.handleRefusal(gate401('/api/teacher/masjids/14/groups')), null);
        assert.equal(h.guard.handleRefusal(tenant403('/api/teacher/masjids/14/groups')), null);
        assert.equal(h.log.asks, 0);
        assert.equal(h.log.reloads, 0);
    }
});

test('a two-role login refused OUTSIDE the area its tab is in is left alone', () => {
    const h = harness({ who: twoRoleLunch });

    // Boot asks an admin route for every kind of login, and it is refused for all of them.
    assert.equal(h.guard.handleRefusal(gate401('/api/admin/masjids/1/')), null);
    // A switch asks the other area before moving; its refusal is shown, not acted on here.
    assert.equal(h.guard.handleRefusal(gate401('/api/teacher/user')), null);
    // Not a refusal about who she is.
    assert.equal(h.guard.handleRefusal(refused(422, '/api/lunch/masjids/1/jummah-lunch/menus')), null);
    assert.equal(h.guard.handleRefusal(refused(500, '/api/lunch/masjids/1/jummah-lunch/menus')), null);
    assert.equal(h.guard.handleRefusal({ message: 'Network Error', config: { url: '/api/lunch/masjids/1/jummah-lunch/menus' } }), null);
    assert.equal(h.log.asks, 0);
});

// ------------------------------------------------------------ what it does

test('the area refuses when asked again: one reload, and the guard took it', async () => {
    // Her teacher role was removed: every teacher request is 401 at the gate, /user included.
    const h = harness({ who: twoRoleTeacher, verdict: 'gone' });

    assert.equal(await h.guard.handleRefusal(gate401('/api/teacher/masjids/14/groups')), true);

    assert.equal(h.log.asks, 1);
    assert.equal(h.log.reloads, 1);
    assert.deepEqual(h.log.stamps, [NOW], 'the reload is stamped, so a second one can be refused');
    assert.equal(h.log.stillRefused, 0);
});

test('the area answers but no longer names the organisation the tab is in: one reload', async () => {
    const h = harness({ who: twoRoleTeacher, verdict: 'moved' });

    assert.equal(await h.guard.handleRefusal(tenant403('/api/teacher/masjids/14/groups')), true);
    assert.equal(h.log.reloads, 1);
});

test('the area still has her: a refusal about a class reloads nothing and is not taken', async () => {
    // Outside debug a class refusal and an organisation refusal are the same body. Reloading on
    // every 403 would reload a working shell for "You do not lead this class."
    for (const error of [tenant403('/api/teacher/masjids/14/groups/9'), class403InDebug('/api/teacher/masjids/14/groups/9')]) {
        const h = harness({ who: twoRoleTeacher, verdict: 'here' });

        assert.equal(await h.guard.handleRefusal(error), false);
        assert.equal(h.log.asks, 1);
        assert.equal(h.log.reloads, 0);
        assert.equal(h.log.stillRefused, 0);
        assert.deepEqual(h.log.stamps, []);
    }
});

test('the sentence is never read: the same refusal with any words gets the same treatment', async () => {
    for (const message of ['Request failed.', 'You are not authorized to access this masjid.', 'You do not lead this class.', '']) {
        const h = harness({ who: twoRoleLunch, verdict: 'gone' });

        assert.equal(await h.guard.handleRefusal(refused(403, '/api/lunch/masjids/1/jummah-lunch/menus', { message })), true, message);
        assert.equal(h.log.reloads, 1, message);
    }
    assert.doesNotMatch(read('core/tenancy/staffAreaGuardCore.ts').replace(/\/\*[\s\S]*?\*\/|\/\/.*$/gm, ''), /\.message|\.data\b/, 'the core reads the status and the address only');
});

test('a dozen refusals at once ask once and reload once', async () => {
    const h = harness({ who: twoRoleLunch, verdict: 'gone' });

    const all = Array.from({ length: 12 }, (_, i) => h.guard.handleRefusal(tenant403(`/api/lunch/masjids/1/jummah-lunch/menus/${i}`)));

    assert.deepEqual(await Promise.all(all), Array(12).fill(true));
    assert.equal(h.log.asks, 1);
    assert.equal(h.log.reloads, 1);
});

test('a second refusal inside the window does not reload again: the shell is told instead', async () => {
    const h = harness({ who: twoRoleTeacher, verdict: 'gone' });

    await h.guard.handleRefusal(gate401('/api/teacher/user'));
    h.advance(2_000);
    assert.equal(await h.guard.handleRefusal(gate401('/api/teacher/user')), true, 'still taken: sign-in must not be pushed either');

    assert.equal(h.log.reloads, 1);
    assert.equal(h.log.stillRefused, 1);

    // The slot is free again after a decision that awaited nothing more: a later refusal is still handled.
    h.advance(20_000);
    assert.equal(await h.guard.handleRefusal(gate401('/api/teacher/user')), true);
    assert.equal(h.log.reloads, 2);
});

test('when the area cannot be asked (offline, a 500) nothing reloads and the refusal is not taken', async () => {
    const h = harness({ who: twoRoleTeacher, verdict: new Error('Network Error') });

    assert.equal(await h.guard.handleRefusal(gate401('/api/teacher/masjids/14/groups')), false);
    assert.equal(h.log.reloads, 0);
    assert.equal(h.log.warnings, 1);

    // And it can be asked again afterwards.
    h.says('gone');
    assert.equal(await h.guard.handleRefusal(gate401('/api/teacher/masjids/14/groups')), true);
    assert.equal(h.log.reloads, 1);
});

// ------------------------------------- the teacher client and the sign-in push

/** The teacher client's own interceptor factory, wired the way TeacherApiService wires it. */
function teacherClient(guard: ReturnType<typeof createStaffAreaGuard>) {
    const calls = { pushedSignIn: 0 };
    const handlers = createTeacherResponseHandlers({
        isFromSupersededEpoch: () => false,
        dropSupersededResponse: () => new Promise<never>(() => {}),
        checkEcho: () => {},
        handleRefusal: (error) => guard.handleRefusal(error),
        onUnauthorized: async (error) => {
            if (await guard.handleRefusal(error)) return;
            calls.pushedSignIn++;
        },
    });

    return { handlers, calls };
}

test('a two-role login whose teacher role went is NOT sent to sign-in: it is reloaded', async () => {
    const h = harness({ who: twoRoleTeacher, verdict: 'gone' });
    const { handlers, calls } = teacherClient(h.guard);
    const error = gate401('/api/teacher/masjids/14/groups');

    await assert.rejects(handlers.onRejected(error), (thrown) => thrown === error, 'the caller still gets its refusal');

    assert.equal(calls.pushedSignIn, 0, 'the router would send a signed-in login straight back to this shell');
    assert.equal(h.log.asks, 1, 'the refusal and the 401 step share one question');
    assert.equal(h.log.reloads, 1);
});

test('a one-role teacher with a dead token is sent to sign-in exactly as before', async () => {
    const h = harness({ who: oneRoleTeacher });
    const { handlers, calls } = teacherClient(h.guard);

    await assert.rejects(handlers.onRejected(refused(401, '/api/teacher/masjids/14/groups', { status: 'error', message: 'Unauthenticated.' })));

    assert.equal(calls.pushedSignIn, 1);
    assert.equal(h.log.asks, 0);
    assert.equal(h.log.reloads, 0);
});

test('the wiring: both shells\' clients reach the one guard, and nothing reads the refusal\'s sentence for it', () => {
    const service = read('core/services/TeacherApiService.ts');
    const wiring = read('core/tenancy/teacherSchoolGuard.ts');
    const core = read('core/tenancy/teacherSchoolGuardCore.ts');
    const boot = read('main.ts');

    // The teacher client: a 401 asks the area guard before it pushes sign-in.
    const unauthorised = service.slice(service.indexOf('onUnauthorized: async (error: unknown) => {'));
    assert.ok(unauthorised.indexOf('if (await handleStaffAreaRefusal(error)) return;') > 0);
    assert.ok(unauthorised.indexOf('if (await handleStaffAreaRefusal(error)) return;') < unauthorised.indexOf('router.push("/auth/sign-in")'));
    assert.match(core, /await deps\.onUnauthorized\(error\);/, 'the client is handed the refusal it is deciding about');

    // A 403 reaches it through the refusal handler, AHEAD of the sentence-matching school guard.
    assert.match(wiring, /return areaGuard\.handleRefusal\(error\) \?\? guard\.handleRefusal\(error\);/);
    // One stamp for both guards, and the "still refused" notice the teacher shell already shows.
    assert.equal(wiring.match(/reloadStamp,/g)?.length, 2, 'both guards are given the same stamp');
    assert.match(wiring, /stillRefused: \(\) => \{ teacherSchoolRefused\.value = true; \}/);
    assert.match(wiring, /return useAuthStore\(\)\.reaskStaffArea\(\);/);

    // The lunch board rides the admin client, which main.ts puts the same guard on.
    assert.match(boot, /watchStaffAreaRefusals\(ApiService\.VueApp\.axios\);/);
    assert.ok(boot.indexOf('watchStaffAreaRefusals(') > boot.indexOf('ApiService.init(app, API_CONFIG.base_url)'));
});

test('the watcher only watches: the refusal still reaches the caller', async () => {
    let rejected: ((error: any) => any) | null = null;
    const seen: any[] = [];
    const wiring = await loadTs('core/tenancy/teacherSchoolGuard.ts', {
        vue, axios: {},
        '@/core/constants/appConfigConstants': { LOCAL_STORAGE_KEYS: {} },
        '@/core/helpers/teacherSchools': await import('../core/helpers/teacherSchools.ts'),
        '@/core/helpers/staffAreas': areas,
        '@/core/tenancy/staffAreaGuardCore': { createStaffAreaGuard: () => ({ handleRefusal: (error: any) => { seen.push(error); return null; } }) },
        '@/core/tenancy/teacherSchoolGuardCore': await import('../core/tenancy/teacherSchoolGuardCore.ts'),
    });

    wiring.watchStaffAreaRefusals({ interceptors: { response: { use: (_ok: undefined, failed: (error: any) => any) => { rejected = failed; } } } });

    const error = gate401('/api/lunch/masjids/1/jummah-lunch/menus');
    await assert.rejects(rejected!(error), (thrown) => thrown === error);
    assert.deepEqual(seen, [error]);
});

// --------------------------------------------- asking the area again (the store)

const KEYS = { token: 'MASJID_APP_AUTH_TOKEN', dashboard_masjid_id: 'MASJID_APP_DASHBOARD_MASJID_ID', staff_area: 'MASJID_APP_STAFF_AREA' };
const BURLINGTON = { id: 1, name: 'Burlington Masjid', org_type: 'masjid' };
const ALRAZI = { id: 14, name: 'Al-Razi School', org_type: 'school' };
const SECOND_SCHOOL = { id: 20, name: 'Second School', org_type: 'school' };
const row = (masjid: typeof ALRAZI, role: string, isDefault = false) => ({ masjid_id: masjid.id, role, is_default: isDefault, masjid });

/** The real auth store, already holding `user` in one area's voice, over a scripted `/user`. */
async function storeHolding(user: any, selected: number, stored: Record<string, string>, answer: () => any) {
    pinia.setActivePinia(pinia.createPinia());
    const data = new Map<string, string>(Object.entries(stored));
    (globalThis as any).localStorage = { getItem: (k: string) => data.get(k) ?? null, setItem: (k: string, v: string) => { data.set(k, String(v)); }, removeItem: (k: string) => { data.delete(k); } };
    (globalThis as any).window = { location: { pathname: '/' } };
    const calls: string[] = [];

    const { useAuthStore } = await loadTs('stores/authStore.ts', {
        vue, pinia, axios: {},
        '@/core/constants/appConfigConstants': { LOCAL_STORAGE_KEYS: KEYS },
        '@/core/services/ApiService': { default: { setHeader() {}, get: async (url: string) => {
            calls.push(url);
            const reply = answer();
            if (typeof reply === 'number') throw httpError(reply, {});

            return { data: { status: 'success', data: reply } };
        } } },
        '@/core/types/config/SystemRoutes': {}, '@/core/types/data/Admin': {}, '@/core/types/config/AxiosCustom': {}, '@/core/types/config/BackendApiRoutes': {},
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: null }) }, '@/core/plugins/SweetAlerts2': { MSwal: { fire() {} } },
        '@/assets/ts/swalMethods': { getMessageFromObj: () => '' },
        '@/core/tenancy/tenantRequests': { bumpTenantEpoch() {}, forgetServerTenant() {}, serverTenantId: { value: null } },
        '@/core/tenancy/teacherSchoolGuard': { resetTeacherSchoolGuard() {}, provideSignedInStaff() {} },
        '@/core/types/data/Membership': await loadTs('core/types/data/Membership.ts', { '@/core/types/data/Masjid': {} }),
        '@/stores/plugins/tenantStoreReset': { resetTenantScopedStores: () => [] },
        '@/core/helpers/staffAreas': areas,
    });

    const auth = useAuthStore();
    auth.user = user;
    auth.dashboardMasjidId = selected;

    return { auth, calls, data };
}

test('asked again, the area that still has her answers "here", and a place that has gone leaves the switch', async () => {
    const before = { type: 'Teacher', memberships: [row(ALRAZI, 'teacher', true)], areas: HER_AREAS, masjid: ALRAZI };
    // Her LUNCH role was removed meanwhile: the teacher voice no longer carries areas.
    const after = { type: 'Teacher', memberships: [row(ALRAZI, 'teacher', true)], masjid: ALRAZI };
    const s = await storeHolding(before, 14, { [KEYS.staff_area]: 'teacher', [KEYS.dashboard_masjid_id]: '14' }, () => after);

    assert.equal(await s.auth.reaskStaffArea(), 'here');

    assert.deepEqual(s.calls, ['/api/teacher/user'], 'this area alone is asked, not the whole chain');
    assert.equal('areas' in s.auth.user, false);
    assert.equal(s.data.has(KEYS.staff_area), false);
    assert.equal(s.auth.dashboardMasjidId, 14);
});

test('asked again, "here" does not follow another tab\'s school', async () => {
    // Two tabs: this one in school 14, the other moved the shared key to school 20. A class-level
    // refusal here must not flip this tab to the other tab's school.
    const user = { type: 'Teacher', memberships: [row(ALRAZI, 'teacher', true), row(SECOND_SCHOOL, 'teacher')], areas: HER_AREAS, masjid: ALRAZI };
    const s = await storeHolding(user, 14, { [KEYS.staff_area]: 'teacher', [KEYS.dashboard_masjid_id]: '20' }, () => user);

    assert.equal(await s.auth.reaskStaffArea(), 'here');
    assert.equal(s.auth.dashboardMasjidId, 14);
});

test('asked again, a teacher removed from the school the tab is in is "moved" to one the server grants', async () => {
    const before = { type: 'Teacher', memberships: [row(ALRAZI, 'teacher', true), row(SECOND_SCHOOL, 'teacher')], areas: HER_AREAS, masjid: ALRAZI };
    const after = { type: 'Teacher', memberships: [row(SECOND_SCHOOL, 'teacher', true)], areas: [HER_AREAS[0], { area: 'teacher', masjid_id: 20, masjid: SECOND_SCHOOL }], masjid: SECOND_SCHOOL };
    const s = await storeHolding(before, 14, { [KEYS.staff_area]: 'teacher', [KEYS.dashboard_masjid_id]: '14' }, () => after);

    assert.equal(await s.auth.reaskStaffArea(), 'moved');
    assert.equal(Number(s.auth.dashboardMasjidId), 20);
    assert.equal(s.data.get(KEYS.dashboard_masjid_id), '20');
});

test('asked again, an area that refuses is "gone" and nothing is changed, so the reload can find its way', async () => {
    for (const status of [401, 403, 404]) {
        const user = { type: 'LunchStaff', memberships: [row(BURLINGTON, 'lunch-staff', true)], areas: HER_AREAS, masjid: BURLINGTON };
        const s = await storeHolding(user, 1, { [KEYS.staff_area]: 'lunch', [KEYS.dashboard_masjid_id]: '1' }, () => status);

        assert.equal(await s.auth.reaskStaffArea(), 'gone');

        assert.deepEqual(s.calls, ['/api/lunch/user']);
        assert.equal(s.auth.user.type, 'LunchStaff', 'the store is left as it was');
        // Kept on purpose: boot reads it, asks /lunch first, sees the refusal and starts in the area that answers.
        assert.equal(s.data.get(KEYS.staff_area), 'lunch');
    }
});

test('asked again, an error that is not about her is thrown, not read as "gone"', async () => {
    const user = { type: 'LunchStaff', memberships: [row(BURLINGTON, 'lunch-staff', true)], areas: HER_AREAS, masjid: BURLINGTON };
    const s = await storeHolding(user, 1, { [KEYS.staff_area]: 'lunch' }, () => 500);

    await assert.rejects(s.auth.reaskStaffArea());
    assert.equal(s.auth.user.type, 'LunchStaff');
});

test('the store hands the guard the signed-in login, and an administrator is not asked anything', async () => {
    const store = read('stores/authStore.ts');
    assert.match(store, /provideSignedInStaff\(\(\) => user\.value\);/);

    const admin = { type: 'MasjidAdmin', memberships: [row(BURLINGTON, 'masjid-admin', true)], masjid: BURLINGTON };
    const s = await storeHolding(admin, 1, {}, () => assert.fail('no request'));
    assert.equal(await s.auth.reaskStaffArea(), 'here');
    assert.deepEqual(s.calls, []);
    await flush();
});
