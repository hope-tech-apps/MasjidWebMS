import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import * as dates from '../core/types/data/masjid-related/SchoolCalendar.ts';
import { compileSfc, mountSfc, loadTs, flush, withDocumentKeys } from './support/mountSfc.ts';
const require = createRequire(import.meta.url);
const vue = require('vue');

async function language(code: string) {
    const deps: any = { vue };
    for (const file of ['ur','ps','fa-AF','es']) deps[`@/views/family/locales/${file}`] = await loadTs(`views/family/locales/${file}.ts`, {});
    const module = await loadTs('views/family/familyI18n.ts', deps);
    module.useFamilyLang().setLang(code);
    return module;
}

function payload(on: boolean, weekend = false) {
    const days = weekend ? ['2026-10-11','2026-10-18','2026-10-25'] : ['2026-10-12','2026-10-13','2026-10-14','2026-10-15','2026-10-16'];
    return {
        timezone: 'America/New_York', today: days[0],
        years: [{ id: 1, label: 'Test year', first_day: days[0], last_day: days.at(-1), meeting_weekday: weekend ? 0 : 1,
            meeting_days: days, closures: [{ id: 3, closed_on: days[1], reason: 'Staff day' }],
            ...(on ? { meeting_weekdays: weekend ? [0] : [1,2,3,4,5], term_system: 'semesters', terms: [{ id: 2, name: 'Autumn', starts_on: days[0], ends_on: days.at(-1), position: 1 }] } : {}),
        }],
        upcoming: days.map((date, i) => ({ date, closed: i === 1, reason: i === 1 ? 'Staff day' : null })),
    };
}

async function screen(realm: 'teacher' | 'family', on: boolean, code = 'en', weekend = false) {
    const list = await compileSfc('components/common/SchoolCalendarList.vue', { vue, '@/core/types/data/masjid-related/SchoolCalendar': dates });
    const i18n = await language(code);
    const picker = await compileSfc('views/family/FamilyLangPicker.vue', { vue, '@/views/family/familyI18n': i18n });
    const api = { get: async () => ({ data: { data: payload(on, weekend) } }) };
    const deps: any = {
        vue, '@/core/types/data/masjid-related/SchoolCalendar': dates,
        '@/components/common/SchoolCalendarList.vue': { default: list },
        '@/core/services/TeacherApiService': { default: api },
        '@/core/services/FamilyApiService': { default: api },
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
        '@/stores/familyStore': { useFamilyStore: () => ({ handleAuthFailure: () => false }) },
        '@/views/family/familyI18n': i18n, '@/views/family/FamilyLangPicker.vue': { default: picker },
        'vue-router': { useRoute: () => ({ params: { masjidId: '1' } }), useRouter: () => ({ replace() {} }) },
    };
    const mounted = await mountSfc(`views/${realm}/${realm === 'teacher' ? 'Teacher' : 'Family'}Calendar.vue`, {}, deps);
    await flush(); return mounted;
}

for (const realm of ['teacher','family'] as const) {
    test(`${realm}: five days, dated terms read-only and closure wording with real calendar child`, async () => {
        const s = await screen(realm, true);
        try {
            assert.match(s.text(), /Monday.*Tuesday.*Wednesday.*Thursday.*Friday/);
            assert.match(s.text(), /Terms/); assert.match(s.text(), /Autumn/);
            assert.match(s.text(), /No school/); assert.match(s.text(), /Staff day/);
            assert.equal(s.all(n => n.tag === 'li' && String(n.props.class).includes('list-group-item') && n.children.some((c: any) => String(c.props?.class).includes('day-date'))).length, 5);
            assert.equal(s.all(n => n.tag === 'input' || n.tag === 'textarea').length, 0);
            assert.doesNotMatch(s.text(), /Add term|Edit term|Remove term/);
        } finally { s.unmount(); }
    });
    test(`${realm}: weekend dates and existing singular heading survive ON and OFF`, async () => {
        for (const on of [false,true]) {
            const s = await screen(realm, on, 'en', true);
            try {
                assert.match(s.text(), realm === 'teacher' ? /Meets every Sunday/ : /Classes meet every Sunday/);
                assert.equal(s.all(n => n.tag === 'li' && String(n.props.class).includes('list-group-item') && n.children.some((c: any) => String(c.props?.class).includes('day-date'))).length, 3);
                assert.equal(s.text().includes('Autumn'), on);
            } finally { s.unmount(); }
        }
    });
}

test('family Arabic keeps RTL, localised weekday names and terms with school-authored name fenced', async () => {
    const s = await screen('family', true, 'ar');
    try {
        assert.ok(s.all(n => n.props.dir === 'rtl').length);
        assert.match(s.text(), /الاثنين/); assert.match(s.text(), /الثلاثاء/); assert.match(s.text(), /الجمعة/);
        assert.match(s.text(), /الفصول الدراسية/); assert.match(s.text(), /Autumn/); assert.match(s.text(), /لا دراسة/);
        assert.ok(s.all(n => n.props.dir === 'auto' && n.children.some((c: any) => c.text?.includes('Autumn'))).length);
    } finally { s.unmount(); }
});

test('reader parser preserves absent, NULL and [] fields without client resolution', () => {
    for (const config of [{}, { meeting_weekdays: null, term_system: null }, { meeting_weekdays: [], term_system: null }]) {
        const raw: any = payload(false); Object.assign(raw.years[0], config);
        const parsed: any = dates.readSchoolCalendarRead(raw)!.years[0];
        for (const key of ['meeting_weekdays','term_system']) {
            assert.equal(key in parsed, key in config);
            if (key in config) assert.deepEqual(parsed[key], (config as any)[key]);
        }
    }
});

async function container() {
    const pagination = await compileSfc('components/partials/Pagination.vue', { vue, '@/core/types/elements/Pagination': {} });
    return compileSfc('components/PageDataContainer.vue', { vue, '@/components/partials/Pagination.vue': { default: pagination }, '@/core/types/elements/Buttons': {}, '@/core/types/elements/Pagination': {} });
}

test('office calendar shows weekdays, terms and closure words on its initial screen', async () => {
    (globalThis as any).document.body = { style: {} }; withDocumentKeys();
    const page = await container();
    const configuration = await loadTs('core/types/data/masjid-related/SchoolCalendarConfiguration.ts', { '@/core/types/data/masjid-related/SchoolCalendar': dates });
    const errors = await loadTs('core/services/ApiErrors.ts', { axios: require('axios') });
    const s = await mountSfc('views/dashboard/SchoolCalendarView.vue', {}, {
        vue: { ...vue, Teleport: vue.Fragment }, axios: {}, sweetalert2: { default: { fire() {} } },
        '@/components/PageDataContainer.vue': { default: page }, '@/core/services/ApiErrors': errors,
        '@/core/services/ApiService': { default: { get: async () => ({ data: { data: payload(true) } }) } },
        '@/core/types/config/BackendApiRoutes': {}, '@/core/types/data/masjid-related/SchoolCalendar': dates,
        '@/core/types/data/masjid-related/SchoolCalendarConfiguration': configuration,
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1, capabilities: { school_calendar_terms: true } } }) },
    });
    await flush();
    try { assert.match(s.text(), /Meets every Monday, Tuesday, Wednesday, Thursday, Friday/); assert.match(s.text(), /Terms/); assert.match(s.text(), /Autumn/); assert.match(s.text(), /No school/); } finally { s.unmount(); }
});

test('office lesson week consumes the open weekday payload including an empty week', async () => {
    const helper = await loadTs('core/helpers/lessonPlans.ts', {});
    for (const weekdays of [[1,2,4,5], []]) {
        const s = await mountSfc('views/dashboard/groups/GroupLessonPlansTab.vue', { groupId: 1, masjidId: 1 }, {
            vue, '@/core/helpers/lessonPlans': helper, '@/core/types/data/masjid-related/SchoolCalendar': dates,
            '@/core/services/ApiService': { default: { get: async () => ({ data: { data: { plans: [], hidden_fields: [], meeting_weekdays: weekdays } } }) } },
        });
        await flush();
        try {
            assert.equal(s.all(n => n.tag === 'div' && String(n.props.class).includes('card-body')).length, weekdays.length);
            if (weekdays.length) { assert.match(s.text(), /Monday/); assert.match(s.text(), /Tuesday/); assert.doesNotMatch(s.text(), /Wednesday/); }
        } finally { s.unmount(); }
    }
});


test('office attendance mounts real children and draws each missing weekday register without changing totals', async () => {
    const literal = JSON.parse(readFileSync(new URL('../../../tests/fixtures/calendar-readers/board.json', import.meta.url), 'utf8')).data.data;
    const board = structuredClone(literal);
    board.days = [12,13,14,15,16].map((day, i) => ({ date: `2026-10-${day}`, weekday: ['Mon','Tue','Wed','Thu','Fri'][i], taken_by: [] }));
    board.today.school_day.meeting_day = true; board.meta.columns = 5;
    const page = await container();
    const avatar = await compileSfc('components/common/PersonAvatar.vue', { vue });
    const attendance = await loadTs('core/types/data/masjid-related/Attendance.ts', {});
    const errors = await loadTs('core/services/ApiErrors.ts', { axios: require('axios') });
    const store = vue.reactive({ log: null, member: null, masjidId: () => 1, fetchLog: async () => { store.log = board; }, clearMember() {} });
    const s = await mountSfc('views/dashboard/AttendanceLogView.vue', {}, {
        vue, '@/components/PageDataContainer.vue': { default: page }, '@/components/common/PersonAvatar.vue': { default: avatar },
        '@/core/types/data/masjid-related/Attendance': attendance, '@/core/services/ApiErrors': errors, '@/core/types/elements/Pagination': {},
        '@/stores/masjid/attendanceLogStore': { useAttendanceLogStore: () => store }, '@/stores/masjidStore': { useMasjidStore: () => vue.reactive({ term: () => 'Classrooms' }) },
    });
    await flush();
    try {
        assert.equal(s.all(n => n.tag === 'th' && n.props.class === 'att-day-col').length, 5);
        assert.equal(s.all(n => n.tag === 'td' && String(n.props.class).includes('att-no-register')).length, 5);
        assert.match(s.text(), /Test Student/);
        assert.doesNotMatch(s.text(), /The school does not meet today/);
        assert.equal(board.students[0].totals.registers, 0);
    } finally { s.unmount(); }
});

test('teacher lesson week uses the ON payload without changing class tabs or access rules', async () => {
    const { modulesFor } = await import('./support/batch3Modules.ts');
    const { click } = await import('./support/mountSfc.ts');
    (globalThis as any).document.body = { style: {} }; withDocumentKeys();
    (globalThis as any).window ??= { addEventListener() {}, removeEventListener() {} };
    const avatar = await compileSfc('components/common/PersonAvatar.vue', { vue });
    for (const weekdays of [[1,2,4,5], []]) {
        const route = { params: { groupId: '1' }, query: {} };
        const classData = { id: 1, name: 'Class A', students: [{ membership_id: 1, contact: { first_name: 'Test', last_name: 'Student' } }] };
        const api = { get: async (url: string) => ({ data: { status: 'success', data: url.includes('/lesson-plans?')
            ? { plans: [], hidden_fields: [], meeting_weekdays: weekdays }
            : url.endsWith('/groups/1') ? classData : url.endsWith('/curriculum') ? { grades: [], subjects: [] } : [] } }) };
        const modules = await modulesFor('views/teacher/TeacherClass.vue', {
            'vue-router': { useRoute: () => route, useRouter: () => ({ replace() {}, push() {} }) }, '@/core/services/TeacherApiService': { default: api, rowsOf: (data: any) => Array.isArray(data) ? data : data?.data ?? [] },
            '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
            '@/components/common/PersonAvatar.vue': { default: avatar },
        });
        const s = await mountSfc('views/teacher/TeacherClass.vue', {}, modules);
        await flush(12);
        try {
            click(s.button('More')); await flush(); click(s.button('Lesson Plans')); await flush(12); click(s.button('Week')); await flush();
            const dayButtons = s.all(n => n.tag === 'tr' && !!n.props.onClick);
            assert.equal(dayButtons.length, weekdays.length);
        } finally { s.unmount(); }
    }
});


test('OFF parser body remains the literal main body', () => {
    const fixture = JSON.parse(readFileSync(new URL('../../../tests/fixtures/calendar-readers/frontend-body.json', import.meta.url), 'utf8'));
    const source = readFileSync(new URL('../core/types/data/masjid-related/SchoolCalendar.ts', import.meta.url), 'utf8');
    const start = source.indexOf('{', source.indexOf('function readYear(')) + 1;
    const body = source.slice(start, source.indexOf('\n}\n', start));
    assert.ok(body.endsWith(fixture.body));
    assert.match(body.slice(0, body.length - fixture.body.length), /readConfiguredYear/);
});

test('intake field draws resolved open school days and the server plural validation message', async () => {
    const s = await mountSfc('views/dashboard/offerings/IntakeFieldInput.vue', {
        field: { name: 'days', label: 'School days', type: 'checkboxGroup', options: [
            { value: '2026-10-20', label: 'Tuesday, October 20, 2026' },
            { value: '2026-10-21', label: 'Wednesday, October 21, 2026' },
        ] }, modelValue: ['2026-10-20'], inputId: 'school-days', error: 'Pick exactly 2 days.',
    }, { vue, '@/core/types/data/masjid-related/Form': {} });
    try {
        assert.match(s.text(), /Tuesday, October 20, 2026/); assert.match(s.text(), /Wednesday, October 21, 2026/);
        assert.doesNotMatch(s.text(), /Monday|Sunday/); assert.match(s.text(), /Pick exactly 2 days\./);
        const choices = s.all(n => n.tag === 'input');
        assert.equal(choices.length, 2); assert.equal(choices[0].props.checked, true); assert.equal(!!choices[1].props.checked, false);
    } finally { s.unmount(); }
});


test('teacher register draws closure refusal and keeps make-up-day advice without tightening access', async () => {
    const { modulesFor } = await import('./support/batch3Modules.ts');
    const { click } = await import('./support/mountSfc.ts');
    (globalThis as any).document.body = { style: {} }; withDocumentKeys();
    (globalThis as any).window ??= { addEventListener() {}, removeEventListener() {} };
    const avatar = await compileSfc('components/common/PersonAvatar.vue', { vue });
    for (const [closed, meeting_day] of [[true, true], [false, true], [false, false]]) {
        const classData = { id: 1, name: 'Class A', students: [{ membership_id: 1, contact: { first_name: 'Test', last_name: 'Student' } }] };
        const api = { get: async (url: string) => ({ data: { data: url.includes('/attendance?')
            ? { taken: false, students: closed ? [] : classData.students, school_day: { has_calendar: true, in_year: true, closed, meeting_day, reason: closed ? 'Staff day' : null } }
            : url.endsWith('/groups/1') ? classData : [] } }) };
        const modules = await modulesFor('views/teacher/TeacherClass.vue', {
            'vue-router': { useRoute: () => ({ params: { groupId: '1' }, query: {} }), useRouter: () => ({ replace() {}, push() {} }) },
            '@/core/services/TeacherApiService': { default: api, rowsOf: (data: any) => Array.isArray(data) ? data : data?.data ?? [] },
            '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
            '@/components/common/PersonAvatar.vue': { default: avatar },
        });
        const s = await mountSfc('views/teacher/TeacherClass.vue', {}, modules); await flush(12);
        try {
            click(s.button('Attendance')); await flush(12);
            if (closed) {
                assert.match(s.text(), /No school today.*Staff day/);
                assert.match(s.text(), /there is no register to take/);
                assert.equal(s.all(n => n.tag === 'button' && n.textContent.includes('All present')).length, 0);
            } else {
                assert.ok(s.button('All present'));
                if (meeting_day) assert.doesNotMatch(s.text(), /isn't one of the school's meeting days/);
                else assert.match(s.text(), /You can still take a register if the class met/);
            }
        } finally { s.unmount(); }
    }
});
