import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { chooseOption, click, compileSfc, deferred, flush, mountSfc, select, submit, type } from './support/mountSfc.ts';
import { modulesFor, page } from './support/batch3Modules.ts';

const doc = (globalThis as any).document;
doc.addEventListener ??= () => {};
doc.removeEventListener ??= () => {};
doc.body = { style: {} };
(globalThis as any).window ??= { addEventListener() {}, removeEventListener() {} };
const route = { params: { masjidId: '1', groupId: '2', offeringId: '3', registrationId: '4' }, query: {} };
const router = { useRoute: () => route, useRouter: () => ({ replace: async () => {}, resolve: () => ({ href: '/' }) }) };
const ok = (data: any, meta: any = {}) => ({ data: { status: 'success', data, meta } });
const rowsOf = (data: any) => Array.isArray(data) ? data : data?.data ?? [];
const hifzRows = ['sabak', 'sabqi', 'manzil', 'sabak'].map((kind, i) => ({
    id: i + 1, membership_id: 9, kind, quality: ['excellent', 'good', 'fair', 'repeat'][i],
    whole_surah: i === 0, from: { surah: 1, surah_name: 'Al-Fatihah', ayah: 1 },
    to: { surah: 1, surah_name: 'Al-Fatihah', ayah: 7 }, recited_at: '2026-10-01',
}));
const kindWords = ['New memorization', 'Recent revision', 'Older revision'];
const qualityWords = ['Excellent', 'Good', 'Fair', 'Needs work'];

async function mount(file: string, props: any, overrides: any) {
    return mountSfc(file, props, await modulesFor(file, { 'vue-router': router, ...overrides }));
}

for (const realm of ['teacher', 'admin', 'family']) {
    test(`1 ${realm}: existing Hifdh rows use the form's words for every kind and quality`, async () => {
        const classData = { id: 2, name: 'Sample class', students: [{ membership_id: 9, contact: { first_name: 'Test student' } }], memberships: [{ id: 9, role: 'member', contact: { first_name: 'Test student' } }],
            children: [{ membership_id: 9, contact: { first_name: 'Test student' } }], in_class_now: true, may_receive_feed: false };
        const api = { get: async (url: string) => ok(url.endsWith('/hifz') ? hifzRows : url.endsWith('/groups/2') ? classData : []), post: async () => ok({}) };
        let screen: any;
        if (realm === 'admin') {
            const store = { entriesPaginated: { data: hifzRows }, progressByMembership: {}, fetchEntries: async () => {}, fetchProgress: async () => {} };
            screen = await mount('views/dashboard/groups/GroupHifzTab.vue', { groupId: 2, memberships: classData.memberships }, {
                '@/stores/masjid/hifzStore': { useHifzStore: () => store }, sweetalert2: { default: {} },
            });
        } else if (realm === 'teacher') {
            screen = await mount('views/teacher/TeacherClass.vue', {}, {
                '@/core/services/TeacherApiService': { default: api, rowsOf },
                '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
            });
            await flush(); click(screen.button('Hifdh')); await flush();
            const student = screen.all((n: any) => n.tag === 'select' && n.children.some((o: any) => o.props.value === 9))[0];
            chooseOption(student, 9);
        } else {
            const lang = { lang: vue.ref('en'), locale: vue.ref('en'), isRtl: vue.ref(false), dir: vue.ref('ltr'), t: (k: string) => k, count: (k: string) => k };
            screen = await mount('views/family/FamilyClass.vue', {}, {
                '@/core/services/FamilyApiService': { default: api, rowsOf },
                '@/stores/familyStore': { useFamilyStore: () => ({ contactFor: () => ({}), handleAuthFailure: () => false }) },
                '@/views/family/familyI18n': { useFamilyLang: () => lang },
                '@/views/family/useContentTranslation': { useContentTranslation: () => ({
                    loading: vue.ref(false), error: vue.ref(null), incomplete: vue.ref(false), showOriginal: vue.ref(false),
                    hasTranslations: vue.ref(false), showing: vue.ref(false), showingLang: vue.ref(null), available: vue.ref(false),
                    setAvailable() {}, translate: async () => {}, tx: (_k: string, text: any) => text,
                }) },
                '@/core/services/StudentApiService': { default: {} },
            });
            await flush(12); click(screen.button('Test student'));
        }
        try {
            await flush(12);
            for (const label of [...kindWords, ...qualityWords]) assert.ok(screen.text().includes(label), `${realm}: ${label}: ${screen.text()}`);
            if (realm === 'teacher') {
                const rows = screen.all((n: any) => n.tag === 'li' && n.textContent.includes('Remove'));
                assert.equal(rows.length, 4);
                for (const [i, row] of rows.entries()) {
                    assert.ok(row.textContent.startsWith(`${kindWords[i % 3]}:`), row.textContent);
                    assert.ok(row.textContent.includes(`· ${qualityWords[i]} ·`), row.textContent);
                }
                assert.match(rows[0].textContent, /New memorization: all of Al-Fatihah/);
            }
            if (realm === 'admin') {
                click(screen.button('Record')); await flush();
                assert.deepEqual(screen.all((n: any) => n.tag === 'option' && ['sabak', 'sabqi', 'manzil'].includes(n.props.value)).map((n: any) => [n.props.value, n.textContent]),
                    ['sabak', 'sabqi', 'manzil'].map((key, i) => [key, kindWords[i]]));
                assert.deepEqual(screen.all((n: any) => n.tag === 'option' && ['excellent', 'good', 'fair', 'repeat'].includes(n.props.value)).map((n: any) => [n.props.value, n.textContent]),
                    ['excellent', 'good', 'fair', 'repeat'].map((key, i) => [key, qualityWords[i]]));
            }
            assert.deepEqual(hifzRows.map(r => r.kind), ['sabak', 'sabqi', 'manzil', 'sabak']);
        } finally { screen.unmount(); }
    });
}

async function programScreen(status: string) {
    const counts: any = { pending: 0, confirmed: 0, waitlisted: 0, cancelled: 0 };
    const program = { id: 3, name: 'Sample program', slug: 'sample', kind: 'program', registration_count: 0,
        registration_state: 'open', intake_form_id: 5, intake_form: { name: 'Intake', schema: { sections: [] } } };
    let reads = 0;
    const listReads = { n: 0 };
    const calls: any[] = [];
    const store: any = vue.reactive({ offeringsMeta: { registrations_by_status: { ...counts } }, feePlans: [{ id: 6, label: 'Free', kind: 'free', is_active: true }],
        fetchOffering: async () => {
            reads++;
            store.offeringsMeta = { registrations_by_status: { ...counts }, seats: { capacity: 10, taken: counts.confirmed + counts.pending, remaining: 10 - counts.confirmed - counts.pending } };
            return program;
        },
        fetchRegistrations: async () => { listReads.n++; store.registrationsPaginated = { data: [], current_page: 1, total: 0, per_page: 25 }; },
        fetchFeePlans: async () => {}, searchContacts: async () => [],
        createRegistration: async (_id: number, body: any) => { calls.push(body); counts[status]++; return { id: 8, status, payment_status: 'not_required' }; },
    });
    const overrides: any = {
        '@/stores/masjid/offeringsStore': { useOfferingsStore: () => store },
        '@/stores/masjidStore': { useMasjidStore: () => ({ term: () => 'Programs' }) },
        '@/components/PageDataContainer.vue': { default: page },
        sweetalert2: { default: { fire: async () => ({}) } },
    };
    overrides['./ContactPicker.vue'] = { default: await compileSfc('views/dashboard/offerings/ContactPicker.vue', await modulesFor('views/dashboard/offerings/ContactPicker.vue', overrides)) };
    overrides['./ManualRegistrationModal.vue'] = { default: await compileSfc('views/dashboard/offerings/ManualRegistrationModal.vue', await modulesFor('views/dashboard/offerings/ManualRegistrationModal.vue', overrides)) };
    overrides['./offerings/OfferingRegistrationsTab.vue'] = { default: await compileSfc('views/dashboard/offerings/OfferingRegistrationsTab.vue', await modulesFor('views/dashboard/offerings/OfferingRegistrationsTab.vue', overrides)) };
    const screen = await mount('views/dashboard/OfferingDetailView.vue', {}, overrides);
    await flush(); click(screen.button('Registrations')); await flush(); click(screen.all(n => n.tag === 'button' && n.textContent === 'Add a registration')[0]); await flush();
    const payer = screen.all(n => n.tag === 'input' && n.props.placeholder?.startsWith('Search this organization'))[0];
    assert.ok(payer, screen.text()); type(payer, 'Test registrant'); await flush();
    select(screen.all(n => n.tag === 'select' && n.children.some(o => o.props.value === 6))[0], 6); await flush();
    submit(screen.all(n => n.tag === 'form')[0]); await flush(12);
    return { screen, calls, reads, listReads };
}
test('3 manual registration refusal calls the entry a program', async () => {
    const f = 'views/dashboard/offerings/ManualRegistrationModal.vue';
    const store = { feePlans: [], fetchOffering: async () => ({ intake_form: { schema: { sections: [] } } }), fetchFeePlans: async () => {} };
    const m = await mount(f, { offeringId: 3 }, { '@/stores/masjid/offeringsStore': { useOfferingsStore: () => store },
        '@/stores/masjidStore': { useMasjidStore: () => ({ term: () => 'Programs' }) } });
    try { await flush(); assert.match(m.text(), /This program has no active fee plan/); assert.doesNotMatch(m.text(), /\boffering\b/i); }
    finally { m.unmount(); }
});
for (const status of ['pending', 'confirmed', 'waitlisted']) {
    test(`2 program header refreshes immediately after a real modal adds ${status}`, async () => {
        const f = await programScreen(status);
        try {
            assert.equal(f.calls.length, 1);
            const label = status.charAt(0).toUpperCase() + status.slice(1);
            assert.ok(f.screen.all(n => n.textContent === `${label} 1`).length, f.screen.text());
            assert.equal(f.calls[0].fee_plan_id, 6);
            assert.ok(f.screen.all(n => n.tag === 'button' && n.textContent === 'Add a registration').length, 'roster remains available');
            // The roster is read on mount and once after the add. A third read means the
            // header refresh remounted it, which drops the admin's search and filters.
            assert.equal(f.listReads.n, 2, 'the roster is not remounted by the header refresh');
        } finally { f.screen.unmount(); }
    });
}

test('3 programs list uses program wording and renders a real row with its original fields', async () => {
    const store: any = vue.reactive({ offeringsPaginated: { data: [], current_page: 1, total: 0, per_page: 25 }, fetchOfferings: async () => {} });
    const screen = await mount('views/dashboard/OfferingsView.vue', {}, {
        '@/components/PageDataContainer.vue': { default: page },
        '@/stores/masjid/offeringsStore': { useOfferingsStore: () => store },
        '@/stores/masjid/formsStore': { useFormsStore: () => ({ formOptions: [], fetchFormOptions: async () => {} }) },
        '@/stores/masjid/groupsStore': { useGroupsStore: () => ({ groups: [], fetchGroups: async () => {} }) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: {}, orgType: 'masjid', term: () => 'Programs' }) },
        '@/stores/authStore': { useAuthStore: () => ({ user: { type: 'MasjidAdmin' } }) },
        sweetalert2: { default: {} },
    });
    try {
        await flush(); assert.match(screen.text(), /A program is one thing people can register for/);
        store.offeringsPaginated.data = [{ id: 3, name: 'Sample program', slug: 'sample-program', kind: 'program', registration_count: 0,
            registrations_count: 0, capacity: null, registration_state: 'closed', registration_state_reason: 'inactive', fee_plans: [] }];
        await flush(); assert.match(screen.text(), /Sample program/);
        assert.ok(screen.all(n => n.props.title === 'Every sign-up ever attached to this program, cancelled and waitlisted included.').length);
        assert.ok(screen.all(n => n.props.title === 'Switched off. It is not published anywhere and no registration is accepted.' && n.textContent === 'Switched off').length, 'state tooltip renders');
    } finally { screen.unmount(); }
});

test('3 registration details say program in back links and promotion confirmation', async () => {
    const alerts: any[] = [];
    const screen = await mount('views/dashboard/OfferingRegistrationDetailView.vue', {}, {
        '@/components/PageDataContainer.vue': { default: page },
        '@/stores/masjid/offeringsStore': { useOfferingsStore: () => ({ fetchRegistration: async () => ({ id: 4, status: 'waitlisted', payment_status: 'not_required', registrants: [], adjustments: [], payments: [] }) }) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 }, term: () => 'Programs' }) },
        sweetalert2: { default: { fire: async (o: any) => { alerts.push(o); return { isConfirmed: false }; } } },
    });
    try {
        await flush(); assert.match(screen.text(), /Back to the program/); assert.doesNotMatch(screen.text(), /\boffering\b/i);
        click(screen.button('Give this sign-up a seat')); await flush(); assert.match(alerts[0].text, /If the program is full/);
    } finally { screen.unmount(); }
});

async function lunchAdmin() {
    const updates: string[] = [];
    const menu = { id: 2, title: 'Sample lunch', status: 'open', kind: 'dated', items: [], allow_pay_at_pickup: true };
    const store: any = vue.reactive({ menus: [menu], currentMenu: menu, orders: [{ id: 3, order_number: 1, status: 'pending', payment_status: 'unpaid', payment_method: 'pickup', items: [] }],
        isLunchStaff: () => false, organisationId: () => 1, fetchMenus: async () => {}, fetchMenu: async () => {}, fetchOrders: async () => {},
        updateOrderStatus: async (_m: number, _o: number, value: string) => { updates.push(value); return {}; },
        fetchServices: async () => {}, fetchStaff: async () => {}, staff: [], fetchServiceOptions: async () => {}, serviceOptions: [],
    });
    const file = 'views/dashboard/JummahLunchView.vue';
    const screen = await mount(file, {}, { '@/stores/masjid/jummahLunchStore': {
        useJummahLunchStore: () => store, MENU_KIND_CATALOGUE: 'catalogue', MENU_KIND_DATED: 'dated', PAID_VIA_OPTIONS: [],
    }, sweetalert2: { default: { fire: async () => ({}) } } });
    await flush(); click(screen.all(n => n.tag === 'a' && n.textContent.startsWith('Received'))[0]); await flush();
    return { screen, updates };
}
test('4 admin lunch labels every order status while sending the same key', async () => {
    const f = await lunchAdmin();
    try {
        const selectStatus = f.screen.all(n => n.tag === 'select' && n.children.some(o => o.props.value === 'picked_up'))[0];
        assert.deepEqual(selectStatus.children.filter(n => n.tag === 'option').map(n => [n.props.value, n.textContent]), [
            ['pending', 'Pending'], ['confirmed', 'Confirmed'], ['ready', 'Ready'], ['picked_up', 'Picked up'], ['cancelled', 'Cancelled'],
        ]);
        for (const key of ['confirmed', 'ready', 'picked_up', 'cancelled']) { selectStatus.value = key; selectStatus.props.onChange({ target: selectStatus }); await flush(); }
        assert.deepEqual(f.updates, ['confirmed', 'ready', 'picked_up', 'cancelled']);
    } finally { f.screen.unmount(); }
});

test('5 public lunch quantity controls name the dish in English and Arabic, including fallback', async () => {
    const store = vue.reactive({ menu: { title: 'Sample lunch', allow_pay_at_pickup: true, items: [
        { id: 1, name: 'Rice', name_ar: 'أرز', price_minor: 500 }, { id: 2, name: 'Salad', price_minor: 500 },
    ] }, loading: false, fetchMenu: async () => {} });
    const screen = await mount('views/lunch/LunchOrderPage.vue', {}, { '@/stores/publicLunchStore': { usePublicLunchStore: () => store } });
    try {
        await flush();
        const labels = () => screen.all(n => n.tag === 'button' && ['−', '+'].includes(n.textContent)).map(n => n.props['aria-label']);
        assert.deepEqual(labels(), ['One fewer Rice', 'One more Rice', 'One fewer Salad', 'One more Salad']);
        click(screen.button('العربية')); await flush();
        assert.deepEqual(labels(), ['إنقاص أرز', 'زيادة أرز', 'إنقاص Salad', 'زيادة Salad']);
        click(screen.all(n => n.props['aria-label'] === 'زيادة أرز')[0]); await flush();
        assert.equal(screen.all(n => n.props['aria-label'] === 'إنقاص أرز')[0].disabled, false);
    } finally { screen.unmount(); }
});

for (const mode of ['existing', 'new']) for (const answer of [false, true]) {
    test(`6 ${mode} merge ${answer ? 'confirmed' : 'cancelled'} names kept/removed records before writing`, async () => {
        const alerts: any[] = []; const writes: any[] = []; const pending = deferred();
        const source = { id: 3, first_name: 'Source record', last_name: '', email: 'source@example.invalid', cards: [], donations: [] };
        const target = { id: 4, first_name: 'Kept record', last_name: '', email: 'kept@example.invalid' };
        const store = { contactsPaginated: { data: [source], current_page: 1, total: 1, per_page: 25 }, tags: [],
            fetchContacts: async () => {}, fetchTags: async () => {}, fetchContact: async () => source };
        const screen = await mount('views/dashboard/ContactsView.vue', {}, {
            '@/components/PageDataContainer.vue': { default: page },
            '@/stores/masjid/contactsStore': { useContactsStore: () => store },
            '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 }, term: () => 'Members' }) },
            'vue-router': { ...router, useRoute: () => ({ ...route, query: { contact: '3' } }) },
            '@/core/services/ApiService': { default: { get: async (url: string) => ok(url.includes('search=') ? { data: [target] } : {}),
                post: async (url: string, payload: any) => { writes.push({ url, body: payload.toString() }); return ok({}); } } },
            sweetalert2: { default: { fire: async (options: any) => { alerts.push(options); return pending.promise; } } },
        });
        try {
            await flush(); click(screen.button('Merge into another member')); await flush();
            if (mode === 'existing') {
                type(screen.all(n => n.props.placeholder === 'Name or email…')[0], 'Kept');
                await new Promise(r => setTimeout(r, 320)); await flush(); click(screen.button('Kept record')); await flush();
            } else {
                click(screen.button('New member')); await flush();
                const label = screen.all(n => n.tag === 'label' && n.textContent === 'First name *').at(-1)!;
                type(label.parent!.children.find(n => n.tag === 'input')!, 'Kept record'); await flush();
            }
            click(screen.all(n => n.tag === 'button' && n.textContent === 'Merge')[0]); await flush();
            assert.equal(writes.length, 0, 'no mutation before confirmation resolves');
            assert.equal(alerts[0].title, 'Merge these records?');
            assert.match(alerts[0].text, /Keep "Kept record/); assert.match(alerts[0].text, /remove "Source record/);
            assert.match(alerts[0].text, /source@example\.invalid/); assert.match(alerts[0].text, /cannot be undone/);
            assert.equal(alerts[0].showCancelButton, true); assert.equal(alerts[0].confirmButtonColor, '#d33');
            pending.resolve({ isConfirmed: answer }); await flush(12);
            assert.equal(writes.length, answer ? 1 : 0);
            if (answer) assert.equal(writes[0].body, mode === 'existing' ? 'target_contact_id=4' : 'first_name=Kept+record');
        } finally { pending.resolve({ isConfirmed: false }); screen.unmount(); }
    });
}

for (const org of ['masjid', 'school', 'community']) {
    test(`7 ${org} thank-you example and step removal are display-only`, async () => {
        const saved: any[] = [];
        const stored = { id: 7, name: 'Sample form', slug: 'sample-form', is_active: true, schema: { sections: [{ id: 'attendees', fields: [{ name: 'name', label: 'Name', type: 'text', required: true }] }] },
            settings: { successTitle: 'Existing heading', successBody: 'Existing body', successNextSteps: ['Step one', 'Step two'] } };
        const screen = await mount('components/forms/FormBuilder.vue', { formId: 7 }, {
            '@/stores/masjid/formsStore': { useFormsStore: () => ({ fieldTypes: [], optionsSources: [], fetchForm: async () => stored,
                fetchFieldTypes: async () => [], fetchFormOptions: async () => [], updateForm: async (_id: number, body: any) => { saved.push(body); return body; } }) },
            '@/stores/masjid/connectStore': { useConnectStore: () => ({}) },
            '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: {}, orgType: org, term: (key: string) => key }) },
            '@/core/access/orgAccess': { connectPlace: () => null, connectPlaceTitle: () => null },
            sweetalert2: { default: { fire: async () => ({}) } },
        });
        try {
            await flush();
            const heading = screen.all(n => n.tag === 'input' && n.value === 'Existing heading')[0];
            assert.equal(heading.props.placeholder, org === 'masjid' ? 'Jazak Allahu Khairan — your registration is in!' : 'Thank you — your registration is in!');
            const removes = screen.all(n => n.props['aria-label']?.startsWith('Remove next step'));
            assert.deepEqual(removes.map(n => n.props['aria-label']), ['Remove next step 1', 'Remove next step 2']);
            click(removes[0]); await flush(); click(screen.button('Save')); await flush();
            assert.equal(saved[0].settings.successTitle, 'Existing heading'); assert.equal(saved[0].settings.successBody, 'Existing body');
            assert.deepEqual(saved[0].settings.successNextSteps, ['Step two']);
        } finally { screen.unmount(); }
    });
}
