/**
 * The teacher shell's school guard, executed (core/tenancy/teacherSchoolGuardCore.ts).
 *
 * The comments in teacherSchoolGuard.ts call this behaviour load-bearing, and until it
 * was lifted out of the browser wiring the suite only ever read its source text. Each
 * test here fails against the specific regression named in it.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    echoedSchoolId,
    isOutsideMembershipsRefusal,
    mayReloadAfterRefusal,
    schoolMismatch,
    TENANT_FORBIDDEN_MESSAGE,
} from '../core/helpers/teacherSchools.ts';
import {
    createTeacherResponseHandlers,
    createTeacherSchoolGuard,
} from '../core/tenancy/teacherSchoolGuardCore.ts';

const NOW = 5_000_000;

/** A guard over fake browser pieces, with every side effect recorded. */
function harness(over: { stored?: unknown; stamp?: unknown; refetch?: () => Promise<void> } = {}) {
    const log = { refetches: 0, reloads: 0, warnings: 0, stamps: [] as number[], stampCleared: 0 };
    let stamp: unknown = over.stamp ?? null;
    let clock = NOW;

    const mismatch = { value: null as { server: number; selected: number } | null };
    const refused = { value: false };

    const guard = createTeacherSchoolGuard({
        mismatch,
        refused,
        echoedSchoolId,
        schoolMismatch,
        isOutsideMembershipsRefusal,
        mayReloadAfterRefusal,
        readStoredSelection: () => over.stored ?? null,
        reloadStamp: {
            read: () => stamp,
            write: (ms) => { stamp = ms; log.stamps.push(ms); },
            clear: () => { stamp = null; log.stampCleared++; },
        },
        now: () => clock,
        refetchUser: over.refetch ?? (async () => { log.refetches++; }),
        reload: () => { log.reloads++; },
        warn: () => { log.warnings++; },
    });

    return { guard, log, mismatch, refused, advance: (ms: number) => { clock += ms; }, stampNow: () => stamp };
}

const schoolRefusal = { response: { status: 403, data: { message: TENANT_FORBIDDEN_MESSAGE } } };
const classRefusal = { response: { status: 403, data: { message: 'You do not lead this class.' } } };

// ------------------------------------------------------------- the refusal

test('a class-level 403 is left alone: no refetch, no reload (the loop the status check prevents)', async () => {
    const h = harness();

    assert.equal(h.guard.handleRefusal(classRefusal), null);
    assert.equal(h.guard.handleRefusal({ response: { status: 500, data: { message: TENANT_FORBIDDEN_MESSAGE } } }), null);
    assert.equal(h.guard.handleRefusal(undefined), null);
    assert.deepEqual([h.log.refetches, h.log.reloads, h.refused.value], [0, 0, false]);
});

test('a refused school refetches the school list, records the reload, and reloads once', async () => {
    const h = harness();

    await h.guard.handleRefusal(schoolRefusal);

    assert.deepEqual([h.log.refetches, h.log.reloads], [1, 1]);
    assert.deepEqual(h.log.stamps, [NOW], 'the reload is stamped, or the loop guard has nothing to read');
    assert.equal(h.refused.value, false);
});

test('a second refusal inside the window stops and says so, instead of reloading forever', async () => {
    const h = harness();

    await h.guard.handleRefusal(schoolRefusal);
    h.advance(2_000);
    await h.guard.handleRefusal(schoolRefusal);

    assert.equal(h.log.reloads, 1, 'still one reload');
    assert.equal(h.log.refetches, 1, 'and no second refetch');
    assert.equal(h.refused.value, true, 'the shell shows its notice');
});

test('a refusal long after the last reload reloads again', async () => {
    const h = harness();

    await h.guard.handleRefusal(schoolRefusal);
    h.advance(60_000);
    await h.guard.handleRefusal(schoolRefusal);

    assert.equal(h.log.reloads, 2);
    assert.equal(h.refused.value, false);
});

test('a screen full of refusals shares ONE refetch and ONE reload', async () => {
    let refetches = 0;
    const h = harness({ refetch: async () => { refetches++; await new Promise((r) => setTimeout(r, 5)); } });

    const first = h.guard.handleRefusal(schoolRefusal);
    const rest = Array.from({ length: 11 }, () => h.guard.handleRefusal(schoolRefusal));

    assert.ok(rest.every((p) => p === first), 'every concurrent refusal gets the same promise');
    await first;
    assert.deepEqual([refetches, h.log.reloads], [1, 1]);

    // and the slot is released afterwards
    h.advance(60_000);
    await h.guard.handleRefusal(schoolRefusal);
    assert.equal(h.log.reloads, 2);
});

test('a failed refetch warns, never throws, does not reload, and does not wedge the guard', async () => {
    let fail = true;
    const h = harness({
        refetch: async () => { if (fail) throw new Error('network'); },
    });

    await h.guard.handleRefusal(schoolRefusal);
    assert.deepEqual([h.log.warnings, h.log.reloads, h.refused.value], [1, 0, false]);

    fail = false;
    await h.guard.handleRefusal(schoolRefusal);
    assert.equal(h.log.reloads, 1, 'the next refusal can still recover');
});

test('with no readable stamp (a private window) the first refusal still recovers', async () => {
    const log = { reloads: 0 };
    const guard = createTeacherSchoolGuard({
        mismatch: { value: null }, refused: { value: false },
        echoedSchoolId, schoolMismatch, isOutsideMembershipsRefusal, mayReloadAfterRefusal,
        readStoredSelection: () => null,
        reloadStamp: { read: () => { throw new Error('blocked'); }, write: () => { throw new Error('blocked'); }, clear: () => {} },
        now: () => NOW, refetchUser: async () => {}, reload: () => { log.reloads++; }, warn: () => {},
    });

    await guard.handleRefusal(schoolRefusal);

    assert.equal(log.reloads, 1);
});

// ---------------------------------------------------------------- the echo

test('an echo of a school other than the one this tab selected raises the mismatch', () => {
    const h = harness();
    h.guard.provideSelectedSchool(() => 18);

    h.guard.checkEcho({ headers: { 'x-tenant-id': '14' } });

    assert.deepEqual(h.mismatch.value, { server: 14, selected: 18 });
});

test('an echo that agrees, is absent, or says "unbound" raises nothing', () => {
    const h = harness();
    h.guard.provideSelectedSchool(() => 18);

    h.guard.checkEcho({ headers: { 'x-tenant-id': '18' } });
    h.guard.checkEcho({ headers: {} });
    h.guard.checkEcho({ headers: { 'x-tenant-id': 'unbound' } });
    h.guard.checkEcho(undefined);

    assert.equal(h.mismatch.value, null);
});

test('this tab\'s own selection is compared, not the shared localStorage copy (two tabs)', () => {
    // A second tab opened BISS and wrote 18 to localStorage; THIS tab still asks for 14
    // and is correctly served 14. Comparing with the shared copy would call that a mismatch.
    const h = harness({ stored: 18 });
    h.guard.provideSelectedSchool(() => 14);

    h.guard.checkEcho({ headers: { 'x-tenant-id': '14' } });

    assert.equal(h.mismatch.value, null);
});

test('with no provider the stored selection is the fallback, and a garbage one is silence', () => {
    const stored = harness({ stored: '18' });
    stored.guard.checkEcho({ headers: { 'x-tenant-id': '14' } });
    assert.deepEqual(stored.mismatch.value, { server: 14, selected: 18 });

    const garbage = harness({ stored: 'nope' });
    garbage.guard.checkEcho({ headers: { 'x-tenant-id': '14' } });
    assert.equal(garbage.mismatch.value, null);
});

// -------------------------------------------- the interceptors' ordering

function interceptors(over: Partial<Parameters<typeof createTeacherResponseHandlers>[0]> = {}) {
    const calls = { dropped: [] as string[], echoed: 0, refusals: 0, unauthorised: 0 };
    const handlers = createTeacherResponseHandlers({
        isFromSupersededEpoch: (config: any) => config?.superseded === true,
        dropSupersededResponse: (label) => { calls.dropped.push(label); return new Promise<never>(() => {}); },
        checkEcho: () => { calls.echoed++; },
        handleRefusal: () => { calls.refusals++; return null; },
        onUnauthorized: async () => { calls.unauthorised++; },
        ...over,
    });

    return { handlers, calls };
}

test('a response from the school just left is dropped BEFORE its echo is read', () => {
    // It still names the OLD school; reading it as the server disagreeing would block the new one.
    const { handlers, calls } = interceptors();

    void handlers.onFulfilled({ config: { superseded: true, url: '/api/teacher/masjids/14/groups' }, headers: { 'x-tenant-id': '14' } });

    assert.equal(calls.echoed, 0, 'the echo of a superseded response is never read');
    assert.deepEqual(calls.dropped, ['/api/teacher/masjids/14/groups']);
});

test('a current response has its echo checked and is returned untouched', () => {
    const { handlers, calls } = interceptors();
    const res = { config: { url: '/x' }, data: 1 };

    assert.equal(handlers.onFulfilled(res), res);
    assert.equal(calls.echoed, 1);
    assert.deepEqual(calls.dropped, []);
});

test('a superseded failure (the switch\'s own abort) is dropped, not handled as a refusal', async () => {
    const { handlers, calls } = interceptors();

    void handlers.onRejected({ config: { superseded: true, url: '/x' }, response: { status: 403 } });
    await Promise.resolve();

    assert.deepEqual([calls.dropped.length, calls.refusals, calls.unauthorised], [1, 0, 0]);
});

test('a refused school is handled AND still rejected, so the caller\'s own error path runs', async () => {
    const { handlers, calls } = interceptors();
    const error = { config: {}, response: { status: 403, data: { message: TENANT_FORBIDDEN_MESSAGE } } };

    await assert.rejects(() => handlers.onRejected(error), (e) => e === error);

    assert.equal(calls.refusals, 1);
    assert.equal(calls.unauthorised, 0);
});

test('a 401 sends the teacher to sign in, and is still rejected', async () => {
    const { handlers, calls } = interceptors();
    const error = { config: {}, response: { status: 401 } };

    await assert.rejects(() => handlers.onRejected(error), (e) => e === error);

    assert.equal(calls.unauthorised, 1);
});

// ---------------------------------------- the notices survive a sign-out

test('a stale "refused" or "mismatch" notice is cleared by reset, and reset forgets the reload stamp', async () => {
    const h = harness({ stamp: String(NOW - 1_000) });
    h.mismatch.value = { server: 14, selected: 18 };
    h.refused.value = true;

    h.guard.reset();

    assert.equal(h.mismatch.value, null, 'the next sign-in is not blocked by the last one\'s notice');
    assert.equal(h.refused.value, false);
    assert.equal(h.stampNow(), null);

    // With the stamp gone, the next teacher's first legitimate refusal recovers instead of stopping.
    await h.guard.handleRefusal(schoolRefusal);
    assert.equal(h.log.reloads, 1);
    assert.equal(h.refused.value, false);
});

test('clearing the notices on mount keeps the reload stamp, or a refused school would reload forever', async () => {
    const h = harness();
    await h.guard.handleRefusal(schoolRefusal);            // reload #1, stamped
    h.mismatch.value = { server: 14, selected: 18 };
    h.refused.value = true;

    h.guard.clearNotices();                                 // what TeacherLayout does on mount

    assert.equal(h.mismatch.value, null);
    assert.equal(h.refused.value, false);
    assert.notEqual(h.stampNow(), null);
    h.advance(1_000);
    await h.guard.handleRefusal(schoolRefusal);
    assert.equal(h.log.reloads, 1, 'the second refusal inside the window still stops');
    assert.equal(h.refused.value, true);
});

test('sign-out and shell mount both clear the notices', () => {
    const store = readFileSync(new URL('../stores/authStore.ts', import.meta.url), 'utf8');
    const layout = readFileSync(new URL('../layouts/TeacherLayout.vue', import.meta.url), 'utf8');
    const wiring = readFileSync(new URL('../core/tenancy/teacherSchoolGuard.ts', import.meta.url), 'utf8');

    const removeAuth = store.slice(store.indexOf('function removeAuth()'), store.indexOf('function saveDashboardMasjidId'));
    assert.match(removeAuth, /resetTeacherSchoolGuard\(\)/, 'sign-out must reset the module-level notices');

    const mounted = layout.slice(layout.indexOf('onMounted(async () => {'));
    assert.match(mounted.slice(0, 400), /clearTeacherSchoolNotices\(\)/);

    assert.match(wiring, /export const resetTeacherSchoolGuard = guard\.reset/);
    assert.match(wiring, /sessionStorage\.removeItem\(RELOADED_AT_KEY\)/, 'the wiring clears the stamp it writes');
});

test('the teacher client builds its interceptors from the tested factory', () => {
    const service = readFileSync(new URL('../core/services/TeacherApiService.ts', import.meta.url), 'utf8');

    assert.match(service, /createTeacherResponseHandlers\(/);
    assert.match(service, /interceptors\.response\.use\(handlers\.onFulfilled, handlers\.onRejected\)/);
});
