import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as dates from '../core/types/data/masjid-related/SchoolCalendar.ts';
import { compileSfc, mountSfc, loadTs, click, type, check, select, flush, httpError, withDocumentKeys, submit } from './support/mountSfc.ts';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const vue = require('vue');
const baseYear = { id: 7, label: 'Test year', first_day: '2026-10-12', last_day: '2026-10-23', meeting_weekday: 1, meeting_days: ['2026-10-12', '2026-10-13'], closures: [] };

async function screen(on: boolean, configurationOverride: Record<string, unknown> = {}, loadRefusal?: unknown, noYears = false) {
    (globalThis as any).document.body = { style: {} };
    withDocumentKeys();
    const configuration = await loadTs('core/types/data/masjid-related/SchoolCalendarConfiguration.ts', { '@/core/types/data/masjid-related/SchoolCalendar': dates });
    const pagination = await compileSfc('components/partials/Pagination.vue', { '@/core/types/elements/Pagination': {}, 'vue': vue });
    const container = await compileSfc('components/PageDataContainer.vue', { '@/components/partials/Pagination.vue': { default: pagination }, '@/core/types/elements/Buttons': {}, '@/core/types/elements/Pagination': {}, 'vue': vue });
    const year: any = { ...baseYear, ...(on ? { meeting_weekdays: [1,2,3,4,5], term_system: null, terms: [] } : {}), ...configurationOverride };
    const payload = () => ({ data: { data: { timezone: 'America/New_York', today: '2026-10-08', years: noYears ? [] : [year] } } });
    const errors = await loadTs('core/services/ApiErrors.ts', { axios: require('axios') });
    const writes: any[] = []; let refusal: unknown = null;
    const api = {
        get: async () => { if (loadRefusal) throw loadRefusal; return payload(); },
        put: async (url: string, body: any) => { writes.push({ method: 'put', url, body }); if (refusal) throw refusal; if (url.includes('/terms/')) year.terms = [{ id: 9, ...body }]; else Object.assign(year, body); return payload(); },
        post: async (url: string, body: any) => { writes.push({ method: 'post', url, body }); year.terms.push({ id: 9, ...body }); return payload(); },
        delete: async (url: string) => { writes.push({ method: 'delete', url }); year.terms = []; return payload(); },
    };
    const mounted = await mountSfc('views/dashboard/SchoolCalendarView.vue', {}, {
        'vue': { ...vue, Teleport: vue.Fragment }, 'axios': {}, 'sweetalert2': { default: { fire() {} } }, '@/components/PageDataContainer.vue': { default: container },
        '@/core/services/ApiService': { default: api }, '@/core/services/ApiErrors': errors, '@/core/types/config/BackendApiRoutes': {},
        '@/core/types/data/masjid-related/SchoolCalendar': dates, '@/core/types/data/masjid-related/SchoolCalendarConfiguration': configuration,
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1, capabilities: on ? { school_calendar_terms: true } : {} } }) },
    });
    await flush();
    return { ...mounted, writes, refuse: (error = httpError(422, { data: { meeting_weekdays: ['Keep the weekday with attendance.'] } })) => { refusal = error; } };
}

test('OFF office modal keeps legacy words and sends precisely the legacy year body', async () => {
    const s = await screen(false);
    try {
        click(s.button('Edit year')); await flush();
        assert.match(s.text(), /Meets every Monday/);
        assert.doesNotMatch(s.text(), /Days school meets|Term system|Add term/);
        submit(s.all(n => n.tag === 'form')[0]); await flush();
        assert.deepEqual(s.writes[0].body, { label: 'Test year', first_day: '2026-10-12', last_day: '2026-10-23' });
    } finally { s.unmount(); }
});

test('ON office modal sends weekdays, nullable term choice and shows server refusal beside the control', async () => {
    const s = await screen(true);
    try {
        click(s.button('Edit year')); await flush();
        assert.match(s.text(), /Days school meets/);
        check(s.all(n => n.tag === 'input').find(n => n.props.id === 'meetingWeekday2')!, false); await flush();
        select(s.all(n => n.tag === 'select').find(n => n.props.id === 'schoolTermSystem')!, 'semesters'); await flush();
        s.refuse(); submit(s.all(n => n.tag === 'form')[0]); await flush();
        assert.deepEqual(s.writes[0].body.meeting_weekdays, [1,3,4,5]);
        assert.equal(s.writes[0].body.term_system, 'semesters');
        assert.match(s.text(), /Keep the weekday with attendance/);
        assert.match(s.text(), /Edit school year/);
    } finally { s.unmount(); }
});

test('dated terms add, edit and remove locally until one year save; Cancel discards the draft', async () => {
    const s = await screen(true, { terms: [{ id: 9, name: 'Filed term', starts_on: '2026-10-12', ends_on: '2026-10-16', position: 1, report_card_count: 3 }] });
    try {
        click(s.button('Edit year')); await flush();
        assert.match(s.text(), /Term number/); assert.match(s.text(), /Term numbers stay the same when a term is removed/);
        click(s.button('Remove term')); await flush();
        assert.match(s.text(), /3 report cards are filed under this term. They stay, and will no longer be filed under a term./);
        assert.equal(s.writes.length, 0);
        for (const [id,value] of [['schoolTermName','Autumn'],['schoolTermStart','2026-10-12'],['schoolTermEnd','2026-10-16'],['schoolTermPosition','2']]) {
            type(s.all(n => n.tag === 'input').find(n => n.props.id === id)!, value);
        }
        await flush(); click(s.button('Add term')); await flush();
        assert.equal(s.writes.length, 0);
        click(s.button('Edit term')); await flush();
        type(s.all(n => n.tag === 'input').find(n => n.props.id === 'schoolTermName')!, 'Autumn revised'); await flush();
        click(s.button('Save term')); await flush();
        assert.equal(s.writes.length, 0);
        assert.match(s.text(), /Autumn revised/);
        click(s.button('Cancel')); await flush();
        click(s.button('Edit year')); await flush();
        assert.match(s.text(), /Filed term/); assert.doesNotMatch(s.text(), /Autumn revised/);
        click(s.button('Remove term')); await flush();
        submit(s.all(n => n.tag === 'form')[0]); await flush();
        assert.equal(s.writes.length, 1); assert.equal(s.writes[0].url, '/api/admin/masjids/1/school-calendar/years/7');
        assert.deepEqual(s.writes[0].body.terms, []);
    } finally { s.unmount(); }
});

test('an edited term is kept in the draft and sent with the year save', async () => {
    const s = await screen(true, { terms: [{ id: 9, name: 'Filed term', starts_on: '2026-10-12', ends_on: '2026-10-16', position: 1, report_card_count: 0 }] });
    try {
        click(s.button('Edit year')); await flush();
        click(s.button('Edit term')); await flush();
        type(s.all(n => n.tag === 'input').find(n => n.props.id === 'schoolTermName')!, 'Renamed term'); await flush();
        click(s.button('Save term')); await flush();
        assert.equal(s.writes.length, 0);
        assert.match(s.text(), /Renamed term/);
        submit(s.all(n => n.tag === 'form')[0]); await flush();
        assert.equal(s.writes.length, 1);
        const sent = s.writes[0].body.terms;
        assert.equal(sent.length, 1);
        assert.equal(sent[0].id, 9); assert.equal(sent[0].name, 'Renamed term');
        assert.equal(sent[0].starts_on, '2026-10-12'); assert.equal(sent[0].ends_on, '2026-10-16'); assert.equal(Number(sent[0].position), 1);
    } finally { s.unmount(); }
});

test('new ON years inherit the last year meeting days and can save new terms together', async () => {
    const s = await screen(true, { meeting_weekdays: [1,3] });
    try {
        click(s.button('Add school year')); await flush();
        for (let day=0;day<7;day++) assert.equal(Boolean((s.all(n => n.props.id === `meetingWeekday${day}`)[0] as any).checked), [1,3].includes(day));
        for (const [id,value] of [['schoolYearLabel','Next'],['schoolYearFirst','2027-10-11'],['schoolYearLast','2027-10-13'],['schoolTermName','First'],['schoolTermStart','2027-10-11'],['schoolTermEnd','2027-10-13']]) {
            type(s.all(n => n.props.id === id)[0], value);
        }
        click(s.button('Add term')); await flush();
        submit(s.all(n => n.tag === 'form')[0]); await flush();
        assert.deepEqual(s.writes[0].body.terms, [{ name: 'First', starts_on: '2027-10-11', ends_on: '2027-10-13', position: 1 }]);
    } finally { s.unmount(); }
});

test('ON office groups school days into collapsed month counts and retains closure reasons', async () => {
    const s = await screen(true, { meeting_days: ['2026-10-12','2026-10-13'], closures: [{ id: 8, closed_on: '2026-10-13', reason: 'Teacher planning day' }] });
    try {
        assert.match(s.text(), /October 2026 · 1 school day, 1 with no school/);
        assert.equal(s.all(n => n.props['data-calendar-day']).length, 0);
        click(s.button('October 2026')); await flush();
        assert.equal(s.all(n => n.props['data-calendar-day']).length, 2);
        assert.match(s.text(), /Teacher planning day/);
    } finally { s.unmount(); }
});

test('office parser preserves absent, null and empty weekdays and term-system fields', async () => {
    const parser = await loadTs('core/types/data/masjid-related/SchoolCalendarConfiguration.ts', { '@/core/types/data/masjid-related/SchoolCalendar': dates });
    for (const config of [{}, { meeting_weekdays: null, term_system: null }, { meeting_weekdays: [], term_system: null }]) {
        const result = parser.readConfiguredCalendar({ years: [{ ...baseYear, ...config }] }).years[0];
        for (const key of ['meeting_weekdays','term_system']) {
            assert.equal(key in result, key in config);
            if (key in config) assert.deepEqual(result[key], config[key as keyof typeof config]);
        }
    }
});

test('year deletion explains term cascade and retained report cards only when ON', async () => {
    for (const on of [false, true]) {
        const s = await screen(on);
        try {
            click(s.button('Delete year')); await flush();
            const words = 'Its dated terms will also be deleted. Report cards are kept with their original year text and quarter number.';
            assert.equal(s.text().includes(words), on);
        } finally { s.unmount(); }
    }
});


test('ON scrollable modal constrains its form while OFF keeps the original form', async () => {
    const on = await screen(true);
    try {
        click(on.button('Edit year')); await flush();
        const form = on.all(n => n.tag === 'form')[0];
        assert.ok(String(form.props.class).includes('calendar-year-form'));
        assert.ok(on.all(n => String(n.props.class).includes('modal-body')).length);
        assert.ok(on.button('Save changes'));
    } finally { on.unmount(); }
    const off = await screen(false);
    try {
        click(off.button('Edit year')); await flush();
        assert.ok(!String(off.all(n => n.tag === 'form')[0].props.class).includes('calendar-year-form'));
    } finally { off.unmount(); }
});


test('ON NULL year ticks its legacy weekday and saving persists an explicit list', async () => {
    const s = await screen(true, { meeting_weekdays: null, last_day: '2026-10-26' });
    try {
        assert.match(s.text(), /Meets every Monday/);
        click(s.button('Edit year')); await flush();
        for (let day = 0; day < 7; day++) {
            const input = s.all(n => n.tag === 'input').find(n => n.props.id === `meetingWeekday${day}`)!;
            assert.equal(Boolean((input as any).checked), day === 1);
        }
        submit(s.all(n => n.tag === 'form')[0]); await flush();
        assert.deepEqual(s.writes[0].body.meeting_weekdays, [1]);
    } finally { s.unmount(); }
});


test('ON modal and office load show the server calendar busy sentence and retain retry controls', async () => {
    const message = 'The school calendar is being edited. Try again in a few moments.';
    const error = httpError(422, { status: 'failed', data: { capability: [message] } });
    const modal = await screen(true);
    try {
        click(modal.button('Edit year')); await flush();
        modal.refuse(error); submit(modal.all(n => n.tag === 'form')[0]); await flush();
        assert.ok(modal.text().includes(message));
        assert.ok(modal.button('Save changes'));
        assert.ok(modal.text().includes('Edit school year'));
    } finally { modal.unmount(); }
    const office = await screen(true, {}, error);
    try {
        assert.ok(office.text().includes(message));
        assert.ok(office.button('Retry'));
        assert.doesNotMatch(office.text(), /No school year yet/);
    } finally { office.unmount(); }
});

test('capability toggle shows the busy refusal in plain words and leaves its switch available', async () => {
    const axios = require('axios');
    const messages = await loadTs('assets/ts/swalMethods.ts', { axios, '@/core/types/config/AxiosCustom': {} });
    const message = 'The school calendar is being edited. Try again in a few moments.';
    const error = new axios.AxiosError('Request failed with status code 422');
    error.response = { status: 422, data: { status: 'failed', data: { capability: [message] } } };
    const alerts: any[] = []; const writes: any[] = [];
    const entry = { key: 'school_calendar_terms', label: 'Meeting weekdays and dated terms', description: 'Calendar', kind: 'grant', writer: 'capability', enabled: false, default_for_org_type: false, overridden: false, in_use: null };
    const s = await mountSfc('components/super/OrganisationSwitchesPanel.vue', { masjid: { id: 1, name: 'Test school', org_type: 'school', capabilities: {} } }, {
        vue, axios, sweetalert2: {},
        '@/assets/ts/swalMethods': messages,
        '@/core/constants/dashboardAsideMenuItems': { MASJID_DASHBOARD_ASIDE_MENU: [] },
        '@/core/access/orgAccess': { detailsScreenTitle: () => 'School Details', menuItemTitle: () => '' },
        '@/core/plugins/SweetAlerts2': { QSwal: { fire: async () => ({ isConfirmed: true }) }, MSwal: { fire: (options: any) => alerts.push(options) } },
        '@/core/services/ApiService': { default: {
            get: async () => ({ data: { status: 'success', data: { org: { id: 1, name: 'Test school', org_type: 'school' }, groups: [{ key: 'school', label: 'School', entries: [entry] }], history: [] } } }),
            patch: async (url: string, body: any) => { writes.push({ url, body }); throw error; },
        } },
        '@/core/types/config/AsideMenuItem': {}, '@/core/types/config/AxiosCustom': {}, '@/core/types/data/Masjid': {},
        '@/core/types/data/Capability': { CAPABILITY_LABELS: {} },
        '@/core/types/data/Vertical': { DEFAULT_ORG_TYPE: 'masjid', MASJID_TERMINOLOGY: {} },
    });
    try {
        await flush();
        click(s.all(n => n.tag === 'input')[0]); await flush();
        assert.equal(writes[0].url, '/api/admin/masjids/1/capabilities/school_calendar_terms');
        assert.equal(writes[0].body.get('enabled'), '1');
        assert.equal(alerts[0].title, 'Not changed');
        assert.equal(alerts[0].text, message);
        assert.equal(entry.enabled, false);
        assert.equal(Boolean(s.all(n => n.tag === 'input')[0].props.disabled), false);
    } finally { s.unmount(); }
});

test('first ON year starts with no meeting days and Save asks for at least one', async () => {
    const s = await screen(true, {}, undefined, true);
    try {
        click(s.button('Add school year')); await flush();
        for (let day=0;day<7;day++) assert.equal(Boolean((s.all(n => n.props.id === `meetingWeekday${day}`)[0] as any).checked), false);
        for (const [id,value] of [['schoolYearLabel','First'],['schoolYearFirst','2026-10-12'],['schoolYearLast','2026-10-16']]) type(s.all(n => n.props.id === id)[0], value);
        await flush(); submit(s.all(n => n.tag === 'form')[0]); await flush();
        assert.match(s.text(), /Choose at least one meeting day./); assert.equal(s.writes.length, 0);
    } finally { s.unmount(); }
});

test('no screen the calendar touches wraps content in a bare <template>, which a browser never displays', () => {
    // A mounted test still finds nodes inside a <template> with no directive; a browser renders an inert element.
    for (const file of ['views/dashboard/SchoolCalendarView.vue', 'views/teacher/TeacherClass.vue', 'views/teacher/TeacherCalendar.vue', 'views/family/FamilyCalendar.vue']) {
        const source = readFileSync(new URL('../' + file, import.meta.url), 'utf8');
        const body = source.slice(source.indexOf('<template>') + '<template>'.length, source.lastIndexOf('</template>'));
        assert.equal((body.match(/<template\s*>/g) ?? []).length, 0, file);
    }
});
