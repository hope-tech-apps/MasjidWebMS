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
    assert.match(service, /checkTeacherSchoolEcho\(res\)/);
    assert.match(service, /handleTeacherSchoolRefusal\(error\)/);
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

test('the echo guard compares against this tab\'s own selection, not the shared localStorage copy', () => {
    const guard = readFileSync(new URL('../core/tenancy/teacherSchoolGuard.ts', import.meta.url), 'utf8');
    const layout = readFileSync(new URL('../layouts/TeacherLayout.vue', import.meta.url), 'utf8');

    assert.match(guard, /export function provideSelectedSchool/);
    assert.match(guard, /if \(selectionProvider\) \{/, 'the provider must be read before localStorage');
    assert.match(layout, /provideSelectedSchool\(\(\) => authStore\.dashboardMasjidId\)/);
});
