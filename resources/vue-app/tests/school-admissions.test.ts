import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { mountSfc, flush, click, loadTs, compileSfc } from './support/mountSfc.ts';

async function words() {
    const modules: Record<string, any> = { vue };
    for (const code of ['ur','ps','fa-AF','es','fr']) modules[`@/views/family/locales/${code}`] = await loadTs(`views/family/locales/${code}.ts`, {});
    return loadTs('views/family/familyI18n.ts', modules);
}
const child = { membership_id: 7, contact: { first_name: 'Sample', last_name: 'Student' } };
test('family card shares answer attribution, saves a changed answer and shows deposit only from the server', async () => {
    const language = await words(); language.useFamilyLang().setLang('en'); const calls: any[] = [];
    let data: any = { id: 2, year: 'Next year', open: true, answer: null, next_grade: '4th', answered_by: null };
    const api = { get: async () => ({ data: { data } }), put: async (url: string, body: any) => { calls.push([url, body]); data = { ...data, answer: body.answer, answered_by: 'Practice Guardian', answered_at: '2026-10-19T14:00:00Z', deposit: { paid: false, url: '/family/1/deposit/2/7' } }; return { data: { data } }; } };
    const s = await mountSfc('views/family/FamilyReenrolment.vue', { masjidId: '1', groupId: '3', children: [child], campaigns: [{ id: 2, year: 'Next year' }] }, { vue, '@/views/family/familyI18n': language, '@/core/services/FamilyApiService': { default: api } });
    try {
        await flush(); assert.match(s.text(), /Sample Student/); assert.ok(!s.text().includes('Deposit'));
        click(s.button('Returning next year')); await flush(); assert.equal(calls.length, 1); assert.equal(calls[0][1].answer, 'returning'); assert.match(s.text(), /Practice Guardian/); assert.match(s.text(), /Deposit/);
        for (const code of ['en','ar','ur','ps','fa-AF','es','fr']) {
            language.useFamilyLang().setLang(code); await flush();
            for (const key of ['reenrol_title','reenrol_returning','reenrol_not_returning','reenrol_office','reenrol_closed','reenrol_deposit','reenrol_paid','reenrol_unpaid','reenrol_error','reenrol_saved','reenrol_next_grade']) assert.notEqual(language.useFamilyLang().t(key), key, `${code}: ${key}`);
        }
    } finally { s.unmount(); }
});

test('status control sends the selected status, staff note and explicit mail suppression and reads history/outcome', async () => {
    const calls: any[] = [];
    const api = { get: async () => ({ data: { data: { status: 'new', history: [{ status: 'wait_listed', note: 'Practice note', changed_by: 'Office', mails: [{ status: 'sent' }] }] } } }), put: async (url: string, body: any) => { calls.push([url, body]); return { data: { data: { status: body.status, history: [] } } }; } };
    const s = await mountSfc('components/forms/ApplicationStatusControl.vue', { masjidId: '1', responseId: 8 }, { vue, '@/core/services/ApiService': { default: api } });
    try {
        await flush(); assert.match(s.text(), /Practice note/); assert.match(s.text(), /sent/);
        const select = s.all((n: any) => n.tag === 'select')[0]; select.props['onUpdate:modelValue']('accepted');
        const check = s.all((n: any) => n.tag === 'input' && n.props.type === 'checkbox')[0]; check.props['onUpdate:modelValue'](true);
        click(s.button('Save status')); await flush(); assert.equal(calls[0][1].status, 'accepted'); assert.equal(calls[0][1].without_email, true);
    } finally { s.unmount(); }
});

test('office re-enrolment list reads children and sends reminders only on the office button', async () => {
    const calls: string[] = [];
    const api = { get: async () => ({ data: { data: { campaigns: [{ id: 2, year: 'Next year' }], years: [], forms: [], children: [{ student_contact_id: 9, name: 'Sample Student', answer: null, next_grade: '4th', classes: [{ name: 'Practice Class' }], deposit: { paid: false } }], mails: [] } } }), post: async (url: string) => { calls.push(url); return { data: { data: { queued: 1 } } }; } };
    const s = await mountSfc('views/dashboard/SchoolReenrolmentView.vue', {}, { vue, '@/core/services/ApiService': { default: api }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) } });
    try { await flush(); assert.match(s.text(), /Sample Student/); assert.match(s.text(), /No answer yet/); assert.equal(calls.length, 0); click(s.button('Email unanswered families')); await flush(); assert.equal(calls.length, 1); } finally { s.unmount(); }
});

test('application list renders grade wait-list positions and adds only the selected accepted entry to a roster', async () => {
    const calls: any[] = [];
    const api = { get: async () => ({ data: { data: { children: [{ response_id: 8, entry_index: 0, first_name: 'Sample', last_name: 'Student', grade: '3rd', status: 'accepted', form_name: 'Practice form' }, { response_id: 9, entry_index: 0, first_name: 'Waiting', last_name: 'Student', grade: '3rd', status: 'wait_listed', waitlist_position: 1 }], classes: [{ id: 3, name: 'Practice Class' }], settings: { accepted_text: 'Welcome {children} at {school}.', wait_listed_text: 'Waiting.', declined_text: 'Declined.' } } } }), post: async (url: string, body: any) => { calls.push([url, body]); return { data: { data: { message: 'Added with confirmed guardians.' } } }; } };
    const s = await mountSfc('views/dashboard/SchoolApplicationsView.vue', {}, { vue, '@/core/services/ApiService': { default: api }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) }, '@/components/forms/ApplicationStatusControl.vue': { default: { render: () => null } }, '@/components/forms/ApplicationFormSetting.vue': { default: { render: () => null } } });
    try { await flush(); assert.match(s.text(), /Wait-listed · 1/); const select = s.all((n: any) => n.tag === 'select' && String(n.props['aria-label']).includes('Class for'))[0]; select.props['onUpdate:modelValue'](3); await flush(); click(s.button('Add to roster with guardians')); await flush(); assert.equal(calls.length, 1); assert.deepEqual(calls[0], ['/api/admin/masjids/1/applications/8/roster', { entry_index: 0, group_id: 3 }]); assert.match(s.text(), /Added with confirmed guardians/); } finally { s.unmount(); }
});

test('new grants stay hidden for office and platform operator when absent', async () => {
    const access = await loadTs('core/access/orgAccess.ts', { '@/core/constants/dashboardAsideMenuItems': { MASJID_DASHBOARD_ASIDE_MENU: [] }, '@/core/types/config/AsideMenuItem': {}, '@/core/types/data/Capability': {}, '@/core/types/data/Masjid': {}, '@/core/types/data/User': {}, '@/core/types/data/Vertical': {} });
    for (const type of ['SuperAdmin', 'MasjidAdmin']) for (const key of ['school_reenrolment', 'school_applications']) {
        const result = access.menuItemState({ requiresCapability: key, allowed_types: ['SuperAdmin', 'MasjidAdmin'] }, type, { capabilities: {} }, 'school');
        assert.equal(result, 'hidden');
    }
});

test('family deposit submits the stored form through public Forms with the signed child token and no inherited credentials', async () => {
    const language = await words(); language.useFamilyLang().setLang('en'); const calls: any[] = [];
    const previousFetch = globalThis.fetch; const previousLocation = (globalThis as any).location;
    (globalThis as any).location = { pathname: '/family/1/deposit/2/3/7', assign() {} };
    globalThis.fetch = (async (url: string, options: any) => { calls.push([url, options]); return { ok: true, json: async () => ({ data: url.includes('form-responses/') ? { form_id: 11, payment_status: 'unpaid', can_pay: false } : { uuid: 'practice-payment-handle' } }) }; }) as any;
    const page = { form_id: 11, name: 'Practice deposit', token: 'signed-practice-token', card: false, office: true, data: { parentEmail: 'guardian@example.invalid', children: [{ firstName: 'Sample', lastName: 'Student' }] }, quote: { currency: 'USD', total_minor: 2500 }, schema: { sections: [{ id: 'guardian', fields: [{ name: 'parentEmail', label: 'Guardian email', type: 'email' }] }, { id: 'children', repeatable: true, fields: [{ name: 'firstName', label: 'First name', type: 'text' }, { name: 'lastName', label: 'Last name', type: 'text' }] }] } };
    const api = { get: async () => ({ data: { data: page } }), post: async () => ({ data: { data: page.quote } }) };
    const field = await compileSfc('components/forms/FamilyDepositField.vue', { vue });
    const s = await mountSfc('views/family/FamilyReenrolmentDeposit.vue', {}, { vue, 'vue-router': { useRoute: () => ({ params: { masjidId: '1', groupId: '3', membershipId: '7', reenrolmentId: '2' }, query: {} }) }, '@/views/family/familyI18n': language, '@/core/services/FamilyApiService': { default: api }, '@/components/forms/FamilyDepositField.vue': { default: field } });
    try {
        await flush(); assert.match(s.text(), /\$25.00/);
        const inputs = s.all((n: any) => n.tag === 'input'); assert.ok(inputs.some((n: any) => n.props.value === 'Sample'));
        const form = s.all((n: any) => n.tag === 'form')[0]; await form.props.onSubmit({ preventDefault() {}, stopPropagation() {} }); await flush();
        assert.equal(calls[0][0], '/api/v1/forms/11/responses'); assert.equal(calls[0][1].credentials, 'omit'); assert.equal(calls[0][1].headers['masjid-id'], '1'); assert.equal(calls[0][1].headers.Authorization, undefined);
        assert.equal(calls[0][1].body.get('reenrolment_token'), 'signed-practice-token'); assert.equal(JSON.parse(calls[0][1].body.get('data')).children[0].firstName, 'Sample');
        assert.match(s.text(), /Response recorded/); assert.match(s.text(), /Not paid yet/);
    } finally { s.unmount(); globalThis.fetch = previousFetch; (globalThis as any).location = previousLocation; }
});

test('a payment return for another child is refused before any public payment read', async () => {
    const language = await words(); language.useFamilyLang().setLang('en'); const previousFetch = globalThis.fetch; let publicReads = 0;
    globalThis.fetch = (async () => { ++publicReads; throw new Error('Should not fetch'); }) as any;
    const page = { form_id: 11, name: 'Practice deposit', child_name: 'Sample Student', response_uuids: ['own-handle'], card: false, office: true, data: {}, quote: { currency: 'USD', total_minor: 2500 }, schema: { sections: [] } };
    const s = await mountSfc('views/family/FamilyReenrolmentDeposit.vue', {}, { vue, 'vue-router': { useRoute: () => ({ params: { masjidId: '1', groupId: '3', membershipId: '7', reenrolmentId: '2' }, query: { form_response: 'another-child-handle' } }) }, '@/views/family/familyI18n': language, '@/core/services/FamilyApiService': { default: { get: async () => ({ data: { data: page } }) } }, '@/components/forms/FamilyDepositField.vue': { default: { render: () => null } } });
    try { await flush(); assert.equal(publicReads, 0); assert.match(s.text(), /could not be saved or loaded/); assert.ok(!s.text().includes('Paid')); assert.equal(s.all((n: any) => n.tag === 'form').length, 0); } finally { s.unmount(); globalThis.fetch = previousFetch; }
});
