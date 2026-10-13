/**
 * One login, a role at each organisation: the switch between its two areas.
 *
 * A login that is Friday-lunch staff at one organisation and a teacher at another
 * gets one control in the header of both shells (the teacher shell's school
 * picker, extended) that lists its other places and moves it there without
 * signing out. The real picker and both real layouts are mounted here; a login
 * with one kind of role must see exactly the header it saw before.
 *
 * There is no switch endpoint: moving is asking the other area's `/user` and then
 * starting its shell. So "a refused switch" is that question refused, and it is
 * shown in the server's own words while the person stays where they are.
 * Each test names the regression it fails against.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as vue from 'vue';
import * as areas from '../core/helpers/staffAreas.ts';
import * as schools from '../core/helpers/teacherSchools.ts';
import { click, compileSfc, flush, mountSfc, press } from './support/mountSfc.ts';
import {
    ALRAZI, HER_AREAS, SECOND_SCHOOL,
    inLunchVoice, inTeacherVoice, lunchOnly, membership, teacherOnly, twoSchoolTeacher,
} from './support/twoRoleLogin.ts';

const read = (file: string): string => readFileSync(new URL(`../${file}`, import.meta.url), 'utf8');

// ------------------------------------------------------------------ the switch

const pickerModules = { '@/core/helpers/teacherSchools': schools, '@/core/helpers/staffAreas': areas };
const mountPicker = (props: Record<string, any>) => mountSfc('components/teacher/TeacherSchoolPicker.vue', props, pickerModules);
const classes = (node: any): string[] => String(node.props.class ?? '').split(/\s+/).filter(Boolean);

test('the picker without areas is the control it was: nothing for one school, the same words for two', async () => {
    let s = await mountPicker({ choices: schools.schoolChoices(teacherOnly().memberships), currentId: 14, currentArea: 'teacher' });
    assert.equal(s.all(() => true).length, 0, 'a one-school teacher: no button, no menu, no gap');
    s.unmount();

    s = await mountPicker({ choices: schools.schoolChoices(twoSchoolTeacher().memberships), currentId: 14, currentArea: 'teacher' });
    const toggle = s.all((n) => n.tag === 'button' && n.props['data-bs-toggle'] === 'dropdown')[0];
    assert.equal(toggle.props['aria-label'], 'You are in Al-Razi School. Switch school.');
    assert.equal(toggle.props.title, 'Switch school');
    const word = s.all((n) => n.tag === 'span' && n.textContent === 'Switch')[0];
    assert.deepEqual(classes(word), ['d-none', 'd-md-inline'], 'hidden on a phone, as it always was');
    assert.deepEqual(s.all((n) => n.tag === 'h6').map((n) => n.textContent), ['Your schools']);
    assert.deepEqual(s.all((n) => classes(n).includes('dropdown-item')).map((n) => n.textContent), ['Al-Razi School (current school)', 'Second School']);
    s.unmount();

    // The lunch shell mounts it with no schools: for a lunch login that is nothing at all.
    s = await mountPicker({ choices: [], currentId: null, currentArea: 'lunch', areas: undefined });
    assert.equal(s.all(() => true).length, 0);
    s.unmount();
});

test('with areas, the teacher shell offers the lunch board under its role and hands the choice up', async () => {
    const chosen: any[] = [];
    const s = await mountPicker({
        choices: schools.schoolChoices(inTeacherVoice().memberships), currentId: 14, currentArea: 'teacher', areas: HER_AREAS,
        onChooseArea: (place: any) => chosen.push(place), onChoose: () => assert.fail('a place is not a school'),
    });

    // One school, so no school list: only the other place.
    assert.deepEqual(s.all((n) => n.tag === 'h6').map((n) => n.textContent), ['Other places you work']);
    const rows = s.all((n) => classes(n).includes('dropdown-item'));
    assert.deepEqual(rows.map((n) => n.textContent), ['Friday lunch · Burlington Masjid']);

    assert.equal(click(rows[0]), true);
    assert.deepEqual(chosen, [{ area: 'lunch', masjidId: 1, name: 'Burlington Masjid', label: 'Friday lunch · Burlington Masjid', home: '/lunch' }]);
    s.unmount();
});

test('with areas and two schools, both lists are there and neither swallows the other', async () => {
    const twoSchools = [...HER_AREAS, { area: 'teacher', masjid_id: 20, masjid: SECOND_SCHOOL }];
    const memberships = [membership(ALRAZI, 'teacher', true), membership(SECOND_SCHOOL, 'teacher', false)];
    const picked: number[] = [];
    const s = await mountPicker({ choices: schools.schoolChoices(memberships), currentId: 20, currentArea: 'teacher', areas: twoSchools, onChoose: (id: number) => picked.push(id) });

    assert.deepEqual(s.all((n) => n.tag === 'h6').map((n) => n.textContent), ['Your schools', 'Other places you work']);
    assert.deepEqual(s.all((n) => classes(n).includes('dropdown-item')).map((n) => n.textContent),
        ['Al-Razi School', 'Second School (current school)', 'Friday lunch · Burlington Masjid']);
    assert.equal(s.all((n) => n.tag === 'hr').length, 1, 'a rule between the two lists');

    click(s.all((n) => n.textContent === 'Al-Razi School' && n.tag === 'button')[0]);
    assert.deepEqual(picked, [14], 'a school is still chosen as a school');
    s.unmount();
});

test('in the lunch shell the control is named for the lunch board, never for "no school"', async () => {
    const s = await mountPicker({ choices: [], currentId: null, currentArea: 'lunch', areas: HER_AREAS });

    const toggle = s.all((n) => n.tag === 'button' && n.props['data-bs-toggle'] === 'dropdown')[0];
    assert.equal(toggle.props['aria-label'], 'You are in Friday lunch · Burlington Masjid. Switch between the places you work.');
    assert.doesNotMatch(toggle.props['aria-label'], /no school/);
    assert.deepEqual(s.all((n) => classes(n).includes('dropdown-item')).map((n) => n.textContent), ['Teacher · Al-Razi School']);
    s.unmount();
});

test('keyboard and screen reader: the switch is a named button that opens a menu of buttons', async () => {
    const s = await mountPicker({ choices: schools.schoolChoices(inTeacherVoice().memberships), currentId: 14, currentArea: 'teacher', areas: HER_AREAS });

    // The control: a real button (so it is in the tab order and Enter and Space press it),
    // wired to the dropdown that gives the arrow keys and Escape, saying whether it is open.
    const toggle = s.all((n) => n.props['data-bs-toggle'] === 'dropdown')[0];
    assert.equal(toggle.tag, 'button');
    assert.equal(toggle.props.type, 'button');
    assert.equal(toggle.props['aria-expanded'], 'false');
    assert.equal(toggle.props.tabindex, undefined, 'nothing takes it out of the tab order');

    // Its spoken name says where she is, and CONTAINS the word a sighted person reads on it
    // (speech-control users say what they see).
    const word = s.all((n) => n.tag === 'span' && n.textContent === 'Switch')[0];
    assert.deepEqual(classes(word), [], 'the word is shown on a phone too: the email says "use the switch at the top of the page"');
    assert.equal(toggle.props['aria-label'], 'You are in Teacher · Al-Razi School. Switch between the places you work.');
    assert.ok(toggle.props['aria-label'].includes(word.textContent));
    assert.equal(toggle.props.title, 'Switch between the places you work');

    // Pictures say nothing; the words carry it.
    for (const icon of s.all((n) => n.tag === 'i')) assert.equal(icon.props['aria-hidden'], 'true');

    // The menu: a list with a heading, and every choice a button whose name is its place.
    const menu = s.all((n) => n.tag === 'ul' && classes(n).includes('dropdown-menu'))[0];
    assert.ok(menu, 'the menu is the list the dropdown opens');
    const rows = s.all((n) => classes(n).includes('dropdown-item'));
    for (const row of rows) {
        assert.equal(row.tag, 'button', 'a link with no address is skipped by Tab; a button is not');
        assert.equal(row.props.type, 'button');
        assert.equal(row.props.tabindex, undefined);
        assert.equal(row.parent?.tag, 'li');
        assert.ok(row.textContent.trim().length > 0);
    }
    assert.equal(s.all((n) => n.tag === 'h6' && classes(n).includes('dropdown-header')).length, 1);
    s.unmount();
});

test('while a switch is under way the control and every choice are disabled, so a second press starts nothing', async () => {
    const chosen: any[] = [];
    const s = await mountPicker({ choices: [], currentId: null, currentArea: 'lunch', areas: HER_AREAS, switching: true, onChooseArea: (place: any) => chosen.push(place) });

    const toggle = s.all((n) => n.props['data-bs-toggle'] === 'dropdown')[0];
    assert.equal(toggle.disabled, true);
    assert.equal(s.all((n) => n.props.role === 'status')[0].props['aria-hidden'], 'true', 'the spinner is not read out as content');

    const row = s.all((n) => classes(n).includes('dropdown-item'))[0];
    assert.equal(click(row), false, 'a browser gives a disabled button no click');
    assert.deepEqual(chosen, []);
    s.unmount();
});

// ------------------------------------------------------------ the two shells

/**
 * A real layout with the real picker inside it, over the import map the two
 * existing layout tests use (in-app-guides, teacher-timetable): the layouts may
 * import nothing that is not in it.
 */
async function shell(name: 'Teacher' | 'Lunch', user: any, over: { refusal?: string | null; dashboardMasjidId?: number } = {}) {
    const log: string[] = [];
    const requests: string[] = [];
    const picker = await compileSfc('components/teacher/TeacherSchoolPicker.vue', pickerModules);

    const auth: any = vue.reactive({
        user, dashboardMasjidId: over.dashboardMasjidId ?? user.masjid.id, isAuthenticated: true,
        askStaffArea: async (area: string) => { log.push(`ask ${area}`); return over.refusal ?? null; },
        saveDashboardMasjidId(id: number) { log.push(`saveDashboardMasjidId ${id}`); },
        saveStaffArea(area: string) { log.push(`saveStaffArea ${area}`); },
        removeAuth() {},
    });

    (globalThis as any).window = { location: { pathname: `/${name.toLowerCase()}`, assign: (to: string) => { log.push(`assign ${to}`); } } };

    const mounted = await mountSfc(`layouts/${name}Layout.vue`, {}, {
        '@/core/services/ApiService': { default: { post: async () => ({}) } },
        '@/stores/authStore': { useAuthStore: () => auth },
        '@/core/pageTitle': { setOrgTitle() {} },
        'vue-router': { useRoute: () => vue.reactive({ path: `/${name.toLowerCase()}`, name: '' }), useRouter: () => ({ push() {} }) },
        '@/core/helpers/staffChrome': { useStaffChrome() {} },
        '@/core/services/TeacherApiService': { default: {
            get: async (url: string) => { requests.push(url); return { data: { data: url.endsWith('/school') ? { id: 14, name: 'Al-Razi School', logo_url: null } : {} } }; },
            post: async () => ({}),
        } },
        '@/components/teacher/TeacherSchoolPicker.vue': { default: picker },
        '@/components/dashboard/TenantMismatchNotice.vue': { default: { render: () => null } },
        '@/core/helpers/teacherSchools': schools,
        '@/core/tenancy/teacherSchoolGuard': { clearTeacherSchoolNotices() {}, provideSelectedSchool() {}, teacherSchoolMismatch: vue.ref(null), teacherSchoolRefused: vue.ref(false) },
        '@/core/tenancy/tenantRequests': { bumpTenantEpoch: () => { log.push('bumpTenantEpoch'); }, forgetServerTenant: () => { log.push('forgetServerTenant'); } },
        '@/stores/plugins/tenantStoreReset': { resetTenantScopedStores: () => { log.push('resetTenantScopedStores'); } },
    });
    await flush();

    const places = () => mounted.all((n) => classes(n).includes('dropdown-item'));
    const toggle = () => mounted.all((n) => n.props['data-bs-toggle'] === 'dropdown')[0];
    const alerts = () => mounted.all((n) => n.props.role === 'alert');

    return { ...mounted, log, requests, auth, places, toggle, alerts };
}

test('a one-role login sees the header it always saw in both shells: no switch at all', async () => {
    for (const [name, user] of [['Teacher', teacherOnly()], ['Lunch', lunchOnly()]] as const) {
        const s = await shell(name, user);

        assert.equal(s.toggle(), undefined, `${name}: no switch`);
        assert.equal(s.alerts().length, 0);
        assert.match(s.text(), /Help/);
        assert.match(s.text(), /Sign out/);
        // One row on a phone, as before: the two-row header is for a login with the switch in it.
        assert.doesNotMatch(String(s.all((n) => classes(n).includes('mn-realm'))[0].props.class), /works-elsewhere/, name);
        s.unmount();
    }

    // And the lunch header's own markup is untouched for them: the name is not shortened.
    const s = await shell('Lunch', lunchOnly());
    const brand = s.all((n) => classes(n).includes('navbar-brand'))[0];
    assert.equal(classes(brand).includes('lunch-brand-shrinks'), false);
    assert.equal(s.all((n) => classes(n).includes('mn-topbar-name'))[0].textContent, 'Burlington Masjid');
    s.unmount();
});

test('with the switch in it, a phone\'s header has two rows so the organisation\'s name is not squeezed to a letter', async () => {
    // Measured in a browser at 375px before this rule: the name was left 10px beside Switch, Help and Sign out.
    for (const [name, user, marker] of [['Teacher', inTeacherVoice(), 'teacher-works-elsewhere'], ['Lunch', inLunchVoice(), 'lunch-works-elsewhere']] as const) {
        const s = await shell(name, user);
        assert.ok(classes(s.all((n) => classes(n).includes('mn-realm'))[0]).includes(marker), name);
        s.unmount();

        const layout = read(`layouts/${name}Layout.vue`);
        const phone = layout.slice(layout.indexOf('@media (max-width: 575.98px) {'));
        assert.match(phone, new RegExp(`\\.${marker} \\.mn-topbar > \\.container-fluid[^{]*\\{ flex-wrap: wrap;`), `${name}: the phone rule`);
    }
});

test('the teacher shell asks the teacher prefix at the school this tab is in', async () => {
    const s = await shell('Teacher', inTeacherVoice());

    assert.deepEqual(s.requests, ['/api/teacher/user', '/api/teacher/masjids/14/school']);
    assert.ok(s.requests.every((url) => url.startsWith('/api/teacher/')), 'nothing in this shell is asked under another prefix');
    s.unmount();
});

const SWITCH_STEPS = (area: string, masjidId: number, home: string) => [
    `ask ${area}`, 'bumpTenantEpoch', 'resetTenantScopedStores', 'forgetServerTenant',
    `saveDashboardMasjidId ${masjidId}`, `saveStaffArea ${area}`, `assign ${home}`,
];

test('switching from the teacher shell: the lunch area is asked first, then the steps of every switch in order, then a full load of /lunch', async () => {
    const s = await shell('Teacher', inTeacherVoice());

    assert.deepEqual(s.places().map((n) => n.textContent), ['Friday lunch · Burlington Masjid']);
    click(s.places()[0]);
    await flush();

    assert.deepEqual(s.log, SWITCH_STEPS('lunch', 1, '/lunch'));
    assert.equal(s.alerts().length, 0);
    s.unmount();
});

test('switching from the lunch shell: the same steps, to the school', async () => {
    const s = await shell('Lunch', inLunchVoice());

    assert.equal(classes(s.all((n) => classes(n).includes('navbar-brand'))[0]).includes('lunch-brand-shrinks'), true, 'room for the switch on a phone');
    assert.deepEqual(s.places().map((n) => n.textContent), ['Teacher · Al-Razi School']);
    click(s.places()[0]);
    await flush();

    assert.deepEqual(s.log, SWITCH_STEPS('teacher', 14, '/teacher'));
    s.unmount();
});

test('a second press while the first is being asked starts nothing', async () => {
    const s = await shell('Lunch', inLunchVoice());

    press(s.places()[0]);          // the tap the re-render has not disabled yet
    press(s.places()[0]);
    await flush();

    assert.deepEqual(s.log, SWITCH_STEPS('teacher', 14, '/teacher'), 'asked once, moved once');
    s.unmount();
});

test('a refused switch is said in the server\'s words, and nothing of this shell is taken down', async () => {
    for (const [name, user, place, here, words] of [
        ['Teacher', inTeacherVoice(), 'Friday lunch · Burlington Masjid', 'Al-Razi School', 'Unauthorized.'],
        ['Lunch', inLunchVoice(), 'Teacher · Al-Razi School', 'Burlington Masjid', 'Request failed.'],
    ] as const) {
        const s = await shell(name, user, { refusal: words });

        click(s.places()[0]);
        await flush();

        assert.deepEqual(s.log, [`ask ${name === 'Teacher' ? 'lunch' : 'teacher'}`], `${name}: no epoch, no reset, no saved id, no load`);
        const alert = s.alerts()[0];
        assert.ok(alert, `${name}: the refusal is announced (role="alert")`);
        assert.equal(alert.textContent.replace(/\s+/g, ' ').trim(),
            `Manara could not open ${place}. The answer was: ${words} You are still in ${here}.`);
        assert.equal(s.toggle().disabled, false, `${name}: the switch works again`);

        // It can be dismissed with a named button, and it does not replace the screen.
        const dismiss = s.all((n) => n.tag === 'button' && n.props['aria-label'] === 'Dismiss this message')[0];
        click(dismiss);
        await flush();
        assert.equal(s.alerts().length, 0);
        s.unmount();
    }
});

test('a sentence the server did not end is closed before the next one', async () => {
    const s = await shell('Lunch', inLunchVoice(), { refusal: 'Network Error' });

    click(s.places()[0]);
    await flush();

    assert.match(s.alerts()[0].textContent, /The answer was: Network Error\. You are still in Burlington Masjid\./);
    s.unmount();
});

test('the order of the steps, read from both switchArea bodies', () => {
    for (const file of ['layouts/TeacherLayout.vue', 'layouts/LunchLayout.vue']) {
        const layout = read(file);
        const start = layout.indexOf('async function switchArea');
        assert.ok(start > 0, `${file} has switchArea`);
        const body = layout.slice(start, layout.indexOf('\n}\n', start));

        const order = ['authStore.askStaffArea(place.area)', 'bumpTenantEpoch()', 'resetTenantScopedStores()', 'forgetServerTenant()',
            'saveDashboardMasjidId(place.masjidId)', 'saveStaffArea(place.area)', 'window.location.assign(place.home)']
            .map((needle) => body.indexOf(needle));

        assert.ok(order.every((at) => at >= 0), `${file}: a step of the switch is missing`);
        assert.deepEqual(order, [...order].sort((a, b) => a - b), `${file}: the steps must run in this order`);
    }

    // In the teacher shell it sits after reconcile(), outside the slice the school-switch test reads.
    const teacher = read('layouts/TeacherLayout.vue');
    assert.ok(teacher.indexOf('async function switchArea') > teacher.indexOf('function reconcile()'));
});
