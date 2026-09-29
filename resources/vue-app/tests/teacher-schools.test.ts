/**
 * The teacher shell's school picker (core/helpers/teacherSchools.ts): who sees it,
 * where a teacher lands, what a switch may target, and the two ways the server can
 * disagree with the tab (a different school bound, a school refused).
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    canPickSchool,
    echoedSchoolId,
    isOutsideMembershipsRefusal,
    landingSchoolId,
    mayReloadAfterRefusal,
    schoolChoices,
    schoolMismatch,
    signInSchoolId,
    switchTarget,
    TENANT_FORBIDDEN_MESSAGE,
} from '../core/helpers/teacherSchools.ts';

const alrazi = { masjid_id: 14, is_default: true, role: 'teacher', masjid: { id: 14, name: 'Al-Razi School' } };
const biss = { masjid_id: 18, is_default: false, role: 'teacher', masjid: { id: 18, name: 'BISS' } };

// ------------------------------------------------------------ who sees it

test('the picker renders only with two or more schools', () => {
    assert.equal(canPickSchool(schoolChoices([alrazi, biss])), true);
    assert.equal(canPickSchool(schoolChoices([alrazi])), false, 'a one-school teacher sees no change');
    assert.equal(canPickSchool(schoolChoices([])), false);
    assert.equal(canPickSchool(schoolChoices(undefined)), false, 'an older backend sends no memberships');
    assert.equal(canPickSchool(schoolChoices(null)), false);
});

test('choices are named, ordered by name, de-duplicated and never blank', () => {
    const choices = schoolChoices([
        biss,
        alrazi,
        { masjid_id: 14, is_default: false, masjid: { name: 'duplicate id' } },
        { masjid_id: 20, masjid: null },
        { masjid_id: 'nope' },
        { masjid_id: 0 },
        {},
    ]);

    assert.deepEqual(choices.map((c) => c.name), ['Al-Razi School', 'BISS', 'School #20']);
    assert.deepEqual(choices.map((c) => c.id), [14, 18, 20]);
    assert.deepEqual(choices.map((c) => c.isDefault), [true, false, false]);
});

test('a row with no usable id is dropped rather than offered', () => {
    assert.deepEqual(schoolChoices([{ masjid_id: null, masjid: { name: 'x' } }]), []);
});

// -------------------------------------------------------------- landing

test('a stored school the server still grants wins', () => {
    const choices = schoolChoices([alrazi, biss]);
    assert.equal(landingSchoolId('18', choices), 18);
    assert.equal(landingSchoolId(18, choices), 18);
});

test('a stored school that is not granted falls back to the default', () => {
    const choices = schoolChoices([alrazi, biss]);
    assert.equal(landingSchoolId('99', choices), 14, 'another account\'s id left in a shared browser');
    assert.equal(landingSchoolId(null, choices), 14);
    assert.equal(landingSchoolId('garbage', choices), 14);
});

test('with no default the lowest id is used, and with nothing there is nothing to open', () => {
    const noDefault = schoolChoices([{ ...biss, is_default: false }, { ...alrazi, is_default: false }]);
    assert.equal(landingSchoolId(null, noDefault), 14);
    assert.equal(landingSchoolId('5', []), null);
});

test('the default school wins over a LOWER id: default is not "lowest id"', () => {
    // Al-Razi (14) is the lowest id, BISS (18) is this teacher's default.
    const choices = schoolChoices([{ ...alrazi, is_default: false }, { ...biss, is_default: true }]);

    assert.equal(landingSchoolId(null, choices), 18);
    assert.equal(landingSchoolId('99', choices), 18, 'a stale stored id falls back to the DEFAULT, not to 14');
});

test('is_default arrives as 1 or true, and both count', () => {
    const numeric = schoolChoices([{ ...alrazi, is_default: 0 }, { ...biss, is_default: 1 }]);

    assert.deepEqual(numeric.map((c) => c.isDefault), [false, true]);
    assert.equal(landingSchoolId(null, numeric), 18);
});

test('with no default the lowest id is used whatever the names sort like', () => {
    // Sorted by name the list is [Alpha #30, Beta #20]; "lowest id" is 20, not the first row.
    const choices = schoolChoices([
        { masjid_id: 30, is_default: false, masjid: { name: 'Alpha' } },
        { masjid_id: 20, is_default: false, masjid: { name: 'Beta' } },
    ]);

    assert.deepEqual(choices.map((c) => c.id), [30, 20]);
    assert.equal(landingSchoolId(null, choices), 20);
});

test('two schools with the same name are ordered by id, so the menu is stable', () => {
    const choices = schoolChoices([
        { masjid_id: 30, masjid: { name: 'Same' } },
        { masjid_id: 20, masjid: { name: 'Same' } },
    ]);

    assert.deepEqual(choices.map((c) => c.id), [20, 30]);
});

// ------------------------------------------------------ landing at sign-in

test('signing in lands in the school this browser last used, when it is still granted', () => {
    const memberships = [alrazi, biss];

    assert.equal(signInSchoolId('18', memberships, 14), 18, 'an expired token must not throw them back to the default');
    assert.equal(signInSchoolId(18, memberships, 14), 18);
});

test('signing in falls back to the login\'s own school for a stale, foreign or absent stored id', () => {
    const memberships = [alrazi, biss];

    assert.equal(signInSchoolId(null, memberships, 14), 14);
    assert.equal(signInSchoolId('99', memberships, 14), 14, 'another person\'s id in a shared browser is never honoured');
    assert.equal(signInSchoolId('garbage', memberships, 14), 14);
});

test('sign-in with no memberships (an older backend) keeps the old rule: the login\'s own school', () => {
    assert.equal(signInSchoolId('18', undefined, 14), 14);
    assert.equal(signInSchoolId('18', [], 14), 14);
    assert.equal(signInSchoolId(null, undefined, undefined), null);
    assert.equal(signInSchoolId(null, [], 'x'), null);
});

test('the sign-in screen asks the helper for a teacher\'s school', () => {
    const view = readFileSync(new URL('../views/auth/SignIn.vue', import.meta.url), 'utf8');
    const teacher = view.slice(view.indexOf("type === 'Teacher'"), view.indexOf("type === 'LunchStaff'"));

    assert.match(teacher, /signInSchoolId\(lastUsed, authStore\.user\.memberships, authStore\.user\.masjid\?\.id\)/);
    assert.doesNotMatch(teacher, /saveDashboardMasjidId\(authStore\.user\.masjid\.id\)/, 'the unconditional default is gone');
});

// ------------------------------------------------------------- switching

test('a switch may only target another granted school', () => {
    const choices = schoolChoices([alrazi, biss]);
    assert.equal(switchTarget(14, 18, choices), 18);
    assert.equal(switchTarget('14', '18', choices), 18);
    assert.equal(switchTarget(14, 14, choices), null, 're-selecting the current school is a no-op');
    assert.equal(switchTarget(14, 99, choices), null, 'fail closed: the server would 403 it anyway');
    assert.equal(switchTarget(14, 'x', choices), null);
});

// --------------------------------------------- a school that was taken away

test('only the resolver refusal counts as "your schools changed"', () => {
    assert.equal(isOutsideMembershipsRefusal(403, TENANT_FORBIDDEN_MESSAGE), true);
    // A CLASS refusal is a 403 too, and reloading on it would loop.
    assert.equal(isOutsideMembershipsRefusal(403, 'You do not lead this class.'), false);
    assert.equal(isOutsideMembershipsRefusal(401, TENANT_FORBIDDEN_MESSAGE), false);
    assert.equal(isOutsideMembershipsRefusal(404, TENANT_FORBIDDEN_MESSAGE), false);
    assert.equal(isOutsideMembershipsRefusal(undefined, undefined), false);
});

test('the refusal text matches the backend constant byte for byte', () => {
    const php = readFileSync(new URL('../../../app/Http/Middleware/ResolveMasjidTenant.php', import.meta.url), 'utf8');
    assert.ok(
        php.includes(`FORBIDDEN_MESSAGE = '${TENANT_FORBIDDEN_MESSAGE}'`),
        'ResolveMasjidTenant::FORBIDDEN_MESSAGE changed: the teacher shell no longer recognises a removed school'
    );
});

test('a reload after a refusal is allowed once, then not again inside the window', () => {
    assert.equal(mayReloadAfterRefusal(null, 1_000_000), true);
    assert.equal(mayReloadAfterRefusal('not a number', 1_000_000), true);
    assert.equal(mayReloadAfterRefusal(String(1_000_000 - 2_000), 1_000_000), false, 'just reloaded: would loop');
    assert.equal(mayReloadAfterRefusal(String(1_000_000 - 20_000), 1_000_000), true, 'long enough ago');
});

// ------------------------------------------------------ the server's echo

test('the echoed school is read from X-Tenant-Id, however the headers arrive', () => {
    assert.equal(echoedSchoolId({ 'x-tenant-id': '18' }), 18);
    assert.equal(echoedSchoolId({ 'X-Tenant-Id': '18' }), 18);
    assert.equal(echoedSchoolId({ get: (name: string) => (name === 'x-tenant-id' ? '14' : null) }), 14);
});

test('no readable echo is silence, never a disagreement', () => {
    assert.equal(echoedSchoolId({}), null);
    assert.equal(echoedSchoolId(undefined), null);
    assert.equal(echoedSchoolId({ 'x-tenant-id': 'unbound' }), null);
    assert.equal(echoedSchoolId({ 'x-tenant-id': '' }), null);
    assert.equal(echoedSchoolId({ 'x-tenant-id': '-3' }), null);
});

test('a mismatch is raised only when both sides speak and differ', () => {
    assert.deepEqual(schoolMismatch(14, '18'), { server: 14, selected: 18 });
    assert.equal(schoolMismatch(18, '18'), null);
    assert.equal(schoolMismatch(null, '18'), null, 'no echo');
    assert.equal(schoolMismatch(14, null), null, 'nothing selected yet');
    assert.equal(schoolMismatch(14, 'x'), null);
});

// ------------------------------------------- the wiring these decisions need

test('the picker is gated on the helper, so a one-school teacher renders nothing', () => {
    const picker = readFileSync(new URL('../components/teacher/TeacherSchoolPicker.vue', import.meta.url), 'utf8');
    assert.match(picker, /v-if="canPickSchool\(choices\)"/);
});

test('the header comes from the tenant-bound school endpoint, not from the default membership', () => {
    const layout = readFileSync(new URL('../layouts/TeacherLayout.vue', import.meta.url), 'utf8');

    assert.match(layout, /\/api\/teacher\/masjids\/\$\{selectedId\.value\}\/school/);
    assert.doesNotMatch(layout, /school\.value = data\.masjid/, '/teacher/user names the DEFAULT school');
    assert.match(layout, /<TeacherSchoolPicker/);
    assert.match(layout, /<TenantMismatchNotice/);
});

test('the teacher client stamps the epoch and handles a refused school', () => {
    const service = readFileSync(new URL('../core/services/TeacherApiService.ts', import.meta.url), 'utf8');

    assert.match(service, /interceptors\.request\.use\(stampTenantEpoch\)/);
    assert.match(service, /isFromSupersededEpoch/);
    // The order those steps run in is executed, not read: teacher-school-guard.test.ts.
    assert.match(service, /checkEcho: checkTeacherSchoolEcho/);
    assert.match(service, /handleRefusal: handleTeacherSchoolRefusal/);
});

test('the switch opens a new epoch and empties the stores before it moves the selection', () => {
    const layout = readFileSync(new URL('../layouts/TeacherLayout.vue', import.meta.url), 'utf8');
    const start = layout.indexOf('function switchSchool');
    const body = layout.slice(start, layout.indexOf('/** "Continue in', start));

    const order = ['bumpTenantEpoch()', 'resetTenantScopedStores()', 'forgetServerTenant()', 'saveDashboardMasjidId(target)', "window.location.assign('/teacher')"]
        .map((needle) => body.indexOf(needle));

    assert.ok(order.every((at) => at >= 0), 'a step of the switch is missing');
    assert.deepEqual(order, [...order].sort((a, b) => a - b), 'the steps must run in this order');
});

test('the shell registers this tab\'s own selection with the guard (behaviour: teacher-school-guard.test.ts)', () => {
    const layout = readFileSync(new URL('../layouts/TeacherLayout.vue', import.meta.url), 'utf8');
    const wiring = readFileSync(new URL('../core/tenancy/teacherSchoolGuard.ts', import.meta.url), 'utf8');

    assert.match(layout, /provideSelectedSchool\(\(\) => authStore\.dashboardMasjidId\)/);
    assert.match(wiring, /export const provideSelectedSchool = guard\.provideSelectedSchool/);
});

test('the header prints no new name label for a single-school teacher', () => {
    const layout = readFileSync(new URL('../layouts/TeacherLayout.vue', import.meta.url), 'utf8');

    // `users` has no first_name/last_name, so this stays blank. Reading `data.name` put a new
    // label on every teacher's header, which was not asked for.
    assert.doesNotMatch(layout, /teacherName\.value = data\.name/);
    assert.match(layout, /teacherName\.value = \[data\.first_name, data\.last_name\]/);
});

test('the add form says the list shows the name on an existing login, without naming any address', () => {
    const view = readFileSync(new URL('../views/dashboard/TeachersView.vue', import.meta.url), 'utf8');

    assert.match(view, /If this person already has a Manara login, the name on that login is the one shown in your list\./);
    assert.match(view, /v-else-if="!isEditing" class="form-text"/, 'shown on every add, so it reveals nothing about one address');
});
