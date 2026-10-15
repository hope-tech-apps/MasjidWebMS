import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { mountSfc as mountBase, flush, click, loadTs, compileSfc } from './support/mountSfc.ts';

const serverErrors = await loadTs('core/helpers/serverMessage.ts', {});
const mountSfc = (path: string, props: any, modules: any) => mountBase(path, props, { '@/core/helpers/serverMessage': serverErrors, ...modules });

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
        assert.equal((check as any).checked, false);
        select.props['onUpdate:modelValue']('wait_listed'); click(s.button('Save status')); await flush();
        assert.equal(calls[1][1].without_email, false);
    } finally { s.unmount(); }
});

test('office re-enrolment list reads children and sends reminders only on the office button', async () => {
    const calls: string[] = []; const previousConfirm = globalThis.confirm; globalThis.confirm = () => true;
    const api = { get: async () => ({ data: { data: { campaigns: [{ id: 2, year: 'Next year' }], reminder_count: 1, years: [], forms: [], children: [{ student_contact_id: 9, name: 'Sample Student', answer: null, next_grade: '4th', classes: [{ name: 'Practice Class' }], deposit: { paid: false } }], mails: [] } } }), post: async (url: string) => { calls.push(url); return { data: { data: { queued: 1 } } }; } };
    const s = await mountSfc('views/dashboard/SchoolReenrolmentView.vue', {}, { vue, '@/core/services/ApiService': { default: api }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) } });
    try { await flush(); assert.match(s.text(), /Sample Student/); assert.match(s.text(), /No answer yet/); assert.equal(calls.length, 0); click(s.button('Email unanswered families')); await flush(); assert.equal(calls.length, 1); } finally { s.unmount(); globalThis.confirm = previousConfirm; }
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
        assert.equal(calls[0][1].body.get('reenrolment_token'), 'signed-practice-token'); assert.equal(calls[0][1].body.get('data[children][0][firstName]'), 'Sample'); assert.equal(calls[0][1].body.get('data'), null, 'a JSON string in data is refused by the server');
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


test('mail suppression resets when a different application row opens and after a failed save', async () => {
    const props = vue.reactive({ masjidId: '1', responseId: 8 }); const calls: any[] = [];
    const api = { get: async () => ({ data: { data: { status: 'new', history: [] } } }), put: async (_: string, body: any) => { calls.push(body); throw { response: { data: { data: 'Save refused.' } } }; } };
    const control = await compileSfc('components/forms/ApplicationStatusControl.vue', { vue, '@/core/services/ApiService': { default: api }, '@/core/helpers/serverMessage': serverErrors });
    // A real reactive parent changes props on the same control instance.
    const s = await mountSfc('components/forms/ApplicationStatusControl.vue', props, { vue, '@/core/services/ApiService': { default: api } });
    try {
        await flush(); const check = () => s.all((n: any) => n.tag === 'input' && n.props.type === 'checkbox')[0];
        check().props['onUpdate:modelValue'](true); click(s.button('Save status')); await flush();
        assert.equal((check() as any).checked, false);
    } finally { s.unmount(); }
    // The parent harness uses the existing mounted view to open two response IDs.
    const viewApi = { get: async (url: string) => ({ data: { data: url.includes('/applications/') ? { status: 'new', history: [] } : { children: [8,9].map(response_id => ({ response_id, entry_index: 0, first_name: 'Sample', last_name: 'Student', status: 'new' })), classes: [], settings: {} } } }) };
    const view = await mountSfc('views/dashboard/SchoolApplicationsView.vue', {}, { vue, '@/core/services/ApiService': { default: viewApi }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) }, '@/components/forms/ApplicationStatusControl.vue': { default: control }, '@/components/forms/ApplicationFormSetting.vue': { default: { render: () => null } } });
    try {
        await flush(); click(view.all((n: any) => n.tag === 'button' && n.textContent === 'Status and email history')[0]); await flush();
        view.all((n: any) => n.tag === 'input' && n.props.type === 'checkbox')[0].props['onUpdate:modelValue'](true);
        click(view.all((n: any) => n.tag === 'button' && n.textContent === 'Status and email history')[1]); await flush();
        assert.equal((view.all((n: any) => n.tag === 'input' && n.props.type === 'checkbox')[0] as any).checked, false);
    } finally { view.unmount(); }
});

for (const [paid, canPay] of [[true, false], [false, true], [false, false]]) test(`existing ${paid ? 'paid' : 'unpaid'} deposit opens status without a card-return query (pay allowed: ${canPay})`, async () => {
    const language = await words(); language.useFamilyLang().setLang('en'); const previousFetch = globalThis.fetch; const reads: string[] = [];
    globalThis.fetch = (async (url: string) => { reads.push(url); return { ok: true, json: async () => ({ data: { form_id: 11, payment_status: paid ? 'paid' : 'unpaid', can_pay: canPay } }) }; }) as any;
    const page = { form_id: 11, name: 'Practice deposit', response_uuids: ['existing-handle'], card: true, data: {}, quote: { currency: 'USD', total_minor: 2500 }, schema: { sections: [] } };
    const s = await mountSfc('views/family/FamilyReenrolmentDeposit.vue', {}, { vue, 'vue-router': { useRoute: () => ({ params: { masjidId: '1', groupId: '3', membershipId: '7', reenrolmentId: '2' }, query: {} }) }, '@/views/family/familyI18n': language, '@/core/services/FamilyApiService': { default: { get: async () => ({ data: { data: page } }) } }, '@/components/forms/FamilyDepositField.vue': { default: { render: () => null } } });
    try { await flush(); assert.equal(s.all((n: any) => n.tag === 'form').length, 0); assert.deepEqual(reads, ['/api/v1/form-responses/existing-handle']); assert.match(s.text(), paid ? /Paid/ : /Not paid yet/); assert.equal(s.all((n: any) => n.tag === 'button' && n.textContent === 'Pay now').length, canPay ? 1 : 0); }
    finally { s.unmount(); globalThis.fetch = previousFetch; }
});

test('office list shows dashes for unanswered and not-returning deposits and permits late paper answers', async () => {
    const calls: any[] = []; const children = [null, 'not_returning', 'returning'].map((answer, i) => ({ student_contact_id: i + 1, name: `Sample ${i}`, answer, next_grade: '', classes: [], deposit: { paid: false } }));
    const api = { get: async () => ({ data: { data: { campaigns: [{ id: 2 }], open: false, years: [], forms: [], children, mails: [] } } }), put: async (url: string, body: any) => { calls.push(body); return {}; } };
    const s = await mountSfc('views/dashboard/SchoolReenrolmentView.vue', {}, { vue, '@/core/services/ApiService': { default: api }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) } });
    try {
        await flush(); const cells = s.all((n: any) => n.tag === 'td'); assert.deepEqual([cells[3].textContent, cells[8].textContent, cells[13].textContent], ['—', '—', 'Not paid']);
        assert.equal(s.all((n: any) => n.tag === 'input' && n.props.placeholder === "Choose next year's grade").length, 3);
        click(s.all((n: any) => n.tag === 'button' && n.textContent === 'Returning')[0]); await flush(); assert.equal(calls[0].answer, 'returning');
    } finally { s.unmount(); }
});

test('reminder confirmation includes the family count and cancellation sends nothing; last delivery is visible', async () => {
    const confirmations: string[] = []; let sends = 0; const previousConfirm = globalThis.confirm;
    globalThis.confirm = (message: string) => { confirmations.push(message); return confirmations.length > 1; };
    const api = { get: async () => ({ data: { data: { campaigns: [{ id: 2 }], open: true, reminder_count: 3, last_reminder: { at: 'Oct 14, 2026, 5:17 PM', count: 2 }, years: [], forms: [], children: [], mails: [] } } }), post: async () => { ++sends; return { data: { data: { queued: 3 } } }; } };
    const s = await mountSfc('views/dashboard/SchoolReenrolmentView.vue', {}, { vue, '@/core/services/ApiService': { default: api }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) } });
    try { await flush(); assert.match(s.text(), /Last reminder.*Oct 14, 2026, 5:17 PM.*2/); click(s.button('Email unanswered families')); await flush(); assert.equal(sends, 0); assert.match(confirmations[0], /3/); click(s.button('Email unanswered families')); await flush(); assert.equal(sends, 1); }
    finally { s.unmount(); globalThis.confirm = previousConfirm; }
});

for (const body of [{ data: 'This child is already on a roster. Use the existing class move instead.' }, { message: 'This child is already on a roster. Use the existing class move instead.' }]) test(`roster refusal shows the server ${Object.keys(body)[0]} string`, async () => {
    const api = { get: async () => ({ data: { data: { children: [{ response_id: 8, entry_index: 0, first_name: 'Sample', status: 'accepted' }], classes: [{ id: 3, name: 'Practice Class' }], settings: {} } } }), post: async () => { throw { response: { data: body } }; } };
    const s = await mountSfc('views/dashboard/SchoolApplicationsView.vue', {}, { vue, '@/core/services/ApiService': { default: api }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) }, '@/components/forms/ApplicationStatusControl.vue': { default: { render: () => null } }, '@/components/forms/ApplicationFormSetting.vue': { default: { render: () => null } } });
    try { await flush(); s.all((n: any) => n.tag === 'select' && String(n.props['aria-label']).includes('Class for'))[0].props['onUpdate:modelValue'](3); await flush(); click(s.button('Add to roster with guardians')); await flush(); assert.match(s.text(), /This child is already on a roster/); }
    finally { s.unmount(); }
});

test('added application child replaces its action with the class and retains it on reload', async () => {
    let added = false;
    const api = { get: async () => ({ data: { data: { children: [{ response_id: 8, entry_index: 0, first_name: 'Sample', status: 'accepted', roster_class: added ? 'Practice Class' : null }], classes: [{ id: 3, name: 'Practice Class' }], settings: {} } } }), post: async () => { added = true; return { data: { data: { message: 'Roster saved.', roster_class: 'Practice Class' } } }; } };
    const s = await mountSfc('views/dashboard/SchoolApplicationsView.vue', {}, { vue, '@/core/services/ApiService': { default: api }, '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) }, '@/components/forms/ApplicationStatusControl.vue': { default: { render: () => null } }, '@/components/forms/ApplicationFormSetting.vue': { default: { render: () => null } } });
    try { await flush(); s.all((n: any) => n.tag === 'select' && String(n.props['aria-label']).includes('Class for'))[0].props['onUpdate:modelValue'](3); await flush(); click(s.button('Add to roster with guardians')); await flush(); assert.match(s.text(), /Added to Practice Class/); assert.equal(s.all((n: any) => n.tag === 'button' && n.textContent === 'Add to roster with guardians').length, 0); click(s.button('Refresh')); await flush(); assert.match(s.text(), /Added to Practice Class/); }
    finally { s.unmount(); }
});

for (const body of [{ data: 'A deposit response already exists for this child.' }, { message: 'Keep this deposit for the selected child.' }, { message: 'The card checkout could not be started. Please try again.', data: { uuid: 'existing-unpaid-handle' } }]) test(`deposit submission displays the server ${Object.keys(body)[0]} sentence`, async () => {
    const language = await words(); language.useFamilyLang().setLang('en'); const previousFetch = globalThis.fetch; const previousLocation = (globalThis as any).location;
    (globalThis as any).location = { pathname: '/family/1/deposit/2/3/7' };
    globalThis.fetch = (async () => ({ ok: false, json: async () => body })) as any;
    const page = { form_id: 11, token: 'practice', office: true, data: {}, quote: { currency: 'USD', total_minor: 2500 }, schema: { sections: [] } };
    const s = await mountSfc('views/family/FamilyReenrolmentDeposit.vue', {}, { vue, 'vue-router': { useRoute: () => ({ params: { masjidId: '1', groupId: '3', membershipId: '7', reenrolmentId: '2' }, query: {} }) }, '@/views/family/familyI18n': language, '@/core/services/FamilyApiService': { default: { get: async () => ({ data: { data: page } }), post: async () => ({ data: { data: { currency: 'USD', total_minor: 2500 } } }) } }, '@/components/forms/FamilyDepositField.vue': { default: { render: () => null } } });
    try { await flush(); await s.all((n: any) => n.tag === 'form')[0].props.onSubmit({ preventDefault() {}, stopPropagation() {} }); await flush(); assert.ok(s.text().includes(Object.values(body)[0])); }
    finally { s.unmount(); globalThis.fetch = previousFetch; (globalThis as any).location = previousLocation; }
});

test('a saved checkout refusal reads the existing status and honors can_pay false', async () => {
    const language = await words(); language.useFamilyLang().setLang('en'); const previousFetch = globalThis.fetch; const previousLocation = (globalThis as any).location;
    (globalThis as any).location = { pathname: '/family/1/deposit/2/3/7' };
    const reads: string[] = [];
    globalThis.fetch = (async (url: string) => { reads.push(url); return url.includes('form-responses/') ? { ok: true, json: async () => ({ data: { form_id: 11, payment_status: 'unpaid', can_pay: false } }) } : { ok: false, json: async () => ({ message: 'Payment confirmation is still pending.', data: { uuid: 'pending-handle', can_pay: false, confirming: true } }) }; }) as any;
    const page = { form_id: 11, token: 'practice', card: true, data: {}, quote: { currency: 'USD', total_minor: 2500 }, schema: { sections: [] } };
    const s = await mountSfc('views/family/FamilyReenrolmentDeposit.vue', {}, { vue, 'vue-router': { useRoute: () => ({ params: { masjidId: '1', groupId: '3', membershipId: '7', reenrolmentId: '2' }, query: {} }) }, '@/views/family/familyI18n': language, '@/core/services/FamilyApiService': { default: { get: async () => ({ data: { data: page } }), post: async () => ({ data: { data: page.quote } }) } }, '@/components/forms/FamilyDepositField.vue': { default: { render: () => null } } });
    try {
        await flush(); await s.all((n: any) => n.tag === 'form')[0].props.onSubmit({ preventDefault() {}, stopPropagation() {} }); await flush();
        assert.match(s.text(), /Payment confirmation is still pending/); assert.equal(s.all((n: any) => n.tag === 'form').length, 0);
        assert.equal(s.all((n: any) => n.tag === 'button' && n.textContent === 'Pay now').length, 0); assert.deepEqual(reads, ['/api/v1/forms/11/responses', '/api/v1/form-responses/pending-handle']);
    } finally { s.unmount(); globalThis.fetch = previousFetch; (globalThis as any).location = previousLocation; }
});
