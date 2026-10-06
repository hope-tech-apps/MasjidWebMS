import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as vue from 'vue';
import { reactive } from 'vue';
import { mountSfc, check, click, flush } from './support/mountSfc.ts';
import * as formTypes from '../core/types/data/masjid-related/Form.ts';
import * as feePricing from '../components/forms/formFeePricing.ts';

// Teleport's compiled array of children must stay patchable when the detail opens.
const teleport = vue.Fragment;
const stub = { template: '<div />' };
const form = (payment: Record<string, unknown> = { staffCodes: true }) => ({
    id: 7, name: 'Test registration', slug: 'test-registration', is_active: true, capacity: null,
    schema: { sections: [{ id: 'attendees', title: 'Attendees', repeatable: true, minEntries: 1, maxEntries: 5,
        fields: [{ name: 'name', label: 'Name', type: 'text', required: true }] }] },
    settings: { fee: { amount: 15, currency: 'USD', perEntryOfSection: 'attendees' }, payment },
});
async function builder(stored: any) {
    const writes: any[] = [];
    const store = reactive({ fieldTypes: formTypes.FORM_FIELD_TYPES, optionsSources: [],
        fetchForm: async () => stored, fetchFieldTypes: async () => [],
        fetchFormOptions: async () => [], updateForm: async (_id: number, body: any) => { writes.push(body); return body; },
        createForm: async (body: any) => { writes.push(body); return body; },
    });
    const screen = await mountSfc('components/forms/FormBuilder.vue', { formId: stored ? 7 : null }, {
        '@/core/types/data/masjid-related/Form': formTypes,
        '@/components/forms/formFeePricing': feePricing,
        '@/components/forms/FormFieldEditor.vue': { default: stub },
        '@/components/forms/FormStaffCodesModal.vue': { default: stub },
        '@/stores/masjid/formsStore': { useFormsStore: () => store },
        '@/stores/masjid/connectStore': { useConnectStore: () => ({}) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: {}, orgType: 'masjid', term: (key: string) => key }) },
        '@/core/access/orgAccess': { connectPlace: () => null, connectPlaceTitle: () => null },
        '@/core/types/data/Capability': { CAPABILITY_LABELS: { crm: 'CRM' } },
        '@/core/types/data/masjid-related/StripeConnect': { formsCardProblemIsLink: () => false, formsCardProblemText: () => '' },
        '@/core/helpers/serverMessage': { serverFieldErrors: () => ({}), serverMessage: () => '' },
        'vue-router': { useRouter: () => ({ resolve: () => ({ href: '/' }) }) },
        sweetalert2: { default: { fire: async () => ({}) } },
    });
    await flush();
    return { screen, writes, field: (id: string) => screen.all(n => n.props.id === id)[0] };
}

test('builder defaults off, saves opt-in, hydrates it and saves opt-out', async () => {
    const { screen, writes, field } = await builder(form());
    try {
        const toggle = field('formPaymentStaffPriceOverride');
        assert.ok(toggle, 'staff price switch exists when codes are on');
        assert.equal((toggle as any).checked, false);
        check(toggle, true); await flush();
        click(screen.button('Save')); await flush();
        assert.equal(writes[0].settings.payment.staffPriceOverride, true);
    } finally { screen.unmount(); }
    const loaded = await builder(form({ staffCodes: true, staffPriceOverride: '1' }));
    try {
        assert.equal((loaded.field('formPaymentStaffPriceOverride') as any).checked, true);
        check(loaded.field('formPaymentStaffPriceOverride'), false); await flush();
        click(loaded.screen.button('Save')); await flush();
        assert.equal(loaded.writes[0].settings.payment.staffPriceOverride, false);
    } finally { loaded.screen.unmount(); }
});

test('turning staff codes off hides and clears override, and old forms omit its unused key', async () => {
    const loaded = await builder(form({ staffCodes: true, staffPriceOverride: true }));
    try {
        check(loaded.field('formPaymentStaffCodes'), false); await flush();
        assert.equal(loaded.field('formPaymentStaffPriceOverride'), undefined);
        click(loaded.screen.button('Save')); await flush();
        assert.equal(loaded.writes[0].settings.payment.staffPriceOverride, false);
    } finally { loaded.screen.unmount(); }
    const old = await builder(form());
    try {
        click(old.screen.button('Save')); await flush();
        assert.equal('staffPriceOverride' in old.writes[0].settings.payment, false);
    } finally { old.screen.unmount(); }
});

test('new draft price override starts off and hidden until staff codes are on', async () => {
    const { screen, field } = await builder(null);
    try {
        assert.equal(field('formPaymentStaffPriceOverride'), undefined);
        check(field('formPaymentStaffCodes'), true); await flush();
        assert.ok(field('formPaymentStaffPriceOverride'));
        assert.equal((field('formPaymentStaffPriceOverride') as any).checked, false);
    } finally { screen.unmount(); }
});

test('staff codes count all entries but show only effective cash totals sent by the backend', async () => {
    const screen = await mountSfc('components/forms/FormStaffCodesPanel.vue', { formId: 7 }, {
        vue: { ...vue, Teleport: teleport },
        '@/core/types/data/masjid-related/Form': formTypes,
        '@/stores/masjid/formsStore': { useFormsStore: () => ({ fetchStaffCodes: async () => ({
            meta: { timezone: 'UTC', staff_codes_enabled: true }, codes: [{ id: 3, holder_name: 'Test staff',
                usable: true, use_count: 4, submissions: 2, cash_minor: 2000,
                cancelled_submissions: 1, cancelled_cash_minor: 500 }] }) }) },
        '@/core/helpers/serverMessage': { serverFieldErrors: () => ({}), serverMessage: () => '' },
        '@/core/helpers/focusTrap': { trapTab: () => {} },
        sweetalert2: { default: { fire: async () => ({}) } },
    });
    await flush();
    try {
        assert.ok(screen.all(n => n.tag === 'th' && n.textContent === 'Entries').length);
        assert.doesNotMatch(screen.text(), /\bUses\b/);
        assert.match(screen.text(), /\$20\.00/);
        assert.match(screen.text(), /\$5\.00 cancelled/);
        assert.match(screen.text(), /2 cash entries/);
        assert.ok(screen.all(n => n.tag === 'td' && n.textContent === '4').length);
    } finally { screen.unmount(); }
});

// These views already use source wiring assertions; exercise money/attribution separately below.
test('list and detail retain sort and breakdown hooks and wire the new price context', () => {
    const view = readFileSync(new URL('../views/dashboard/FormResponsesView.vue', import.meta.url), 'utf8');
    assert.ok(/sort: 'amount_due'/.test(view));
    for (const hook of ['list-price-breakdown', 'price-breakdown']) assert.ok(view.includes(`data-test="${hook}"`));
    assert.ok(/priceHeading\(selectedResponse\)/.test(view));
    for (const row of ['response', 'selectedResponse']) {
        assert.ok(view.includes(`listPriceText(${row})`));
        assert.ok(view.includes(`staffPriceText(${row})`));
        assert.ok(view.includes(`isComplimentary(${row})`));
    }
});

test('price snapshots distinguish reduced, unchanged, unpaid card, complimentary and legacy rows', async () => {
    const p = await import('../views/dashboard/formResponsePricing.ts');
    const row: any = { payment_state: 'paid', payment_method: 'cash', total_minor: 2000, currency: 'usd',
        price_set_by: 'Original staff', staff_payment_method: 'cash', staff_code: { holder_name: 'Renamed staff' },
        price_breakdown: { unit_minor: 1000, quantity: 2, currency: 'usd', label: 'Standard', list_unit_minor: 1500, staff_unit_minor: 1000 } };
    assert.equal(p.priceHeading(row), 'Registration price');
    assert.equal(p.listPriceText(row), 'List price · Standard: $15.00 × 2 = $30.00');
    assert.equal(p.staffPriceText(row), 'Price set by staff · Original staff');
    assert.equal(p.cashCollectorText(row), 'held by Renamed staff');
    assert.equal(p.cashCollectorText({ ...row, marked_paid_by: { name: 'Test collector' } }), 'taken at the table by Test collector');
    assert.equal(p.listPriceText({ ...row, price_breakdown: { ...row.price_breakdown, staff_unit_minor: 1500, unit_minor: 1500 } }), '');
    const pending = { ...row, payment_state: 'unpaid', payment_method: 'online', staff_payment_method: 'card' };
    assert.equal(p.priceHeading(pending), 'Amount due');
    assert.equal(p.staffPriceText(pending), 'Price set by staff · Original staff');
    assert.equal(p.cashCollectorText(pending), null);
    const free = { ...row, total_minor: 0, price_breakdown: { ...row.price_breakdown, unit_minor: 0, staff_unit_minor: 0 } };
    assert.equal(p.isComplimentary(free), true);
    assert.equal(p.isComplimentary({ ...free, payment_state: 'unpaid' }), false);
    const legacy = { ...row, price_set_by: undefined, staff_payment_method: undefined,
        price_breakdown: { unit_minor: 1500, quantity: 2, label: 'Standard', currency: 'usd' } };
    assert.equal(p.listPriceText(legacy), '');
    assert.equal(p.staffPriceText(legacy), '');
    assert.equal(p.isComplimentary({ ...legacy, total_minor: 0 }), false);
    assert.equal(p.priceHeading(legacy), 'Registration price', 'an older paid row is still a paid row');
    assert.equal(p.priceHeading({ ...legacy, payment_state: 'unpaid' }), 'Amount due');
    assert.equal(p.staffPriceText({ ...row, price_breakdown: null }), '');
    assert.equal(p.staffPriceText({ ...row, price_set_by: null, staff_code: null }), 'Price set by staff');
    assert.equal(p.listPriceText({ ...row, price_breakdown: { ...row.price_breakdown, list_unit_minor: null, staff_unit_minor: null } }), '');
});

async function responsesScreen(rows: any[]) {
    const pricing = await import('../views/dashboard/formResponsePricing.ts');
    const status = await import('../views/dashboard/formResponseStatus.ts');
    const calls: any[] = [];
    const store = reactive({
        formOptions: [{ id: 7, name: 'Test registration', slug: 'test-registration' }],
        responsesMeta: { form: { id: 7, name: 'Test registration' }, columns: [], payment: { enabled: true } },
        responsesPaginated: { data: rows, total: rows.length, current_page: 1, per_page: 25 },
        masjidId: () => 1, fetchFormOptions: async () => store.formOptions,
        fetchResponses: async (_id: number, filters: any) => { calls.push({ ...filters }); },
        fetchRoster: async () => {},
        fetchResponse: async (_id: number, id: number) => ({ ...rows.find(row => row.id === id), data: {} }),
    });
    (globalThis as any).HTMLElement = class {};
    (globalThis as any).document.body = { style: {} };
    const container = { setup: (_props: any, { slots }: any) => () => vue.h('div', [slots.headerButtons?.(), slots.default?.()]) };
    const screen = await mountSfc('views/dashboard/FormResponsesView.vue', {}, {
        vue: { ...vue, Teleport: teleport },
        '@/components/PageDataContainer.vue': { default: container },
        '@/components/forms/FormStaffCodesModal.vue': { default: stub },
        '@/core/types/elements/Pagination': {},
        '@/core/types/data/masjid-related/Form': formTypes,
        './formResponsePricing': pricing, './formResponseStatus': status,
        '@/stores/masjid/formResponsesStore': { useFormResponsesStore: () => store, pageUnreachable: () => null },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: {}, orgType: 'masjid' }) },
        '@/stores/authStore': { useAuthStore: () => ({}) },
        '@/core/access/orgAccess': { canEditForms: () => false, canUseWebPages: () => false },
        'vue-router': { useRoute: () => ({ query: {} }) },
        '@/core/constants/appConfigConstants': { LOCAL_STORAGE_KEYS: { token: 'test-token' } },
        '@/core/helpers/serverMessage': { serverMessage: () => '' },
        '@/core/helpers/focusTrap': { trapTab: () => {} },
        sweetalert2: { default: { fire: async () => ({}) } },
    });
    await flush();
    return { screen, calls, store };
}

const pricedRow = (over: any = {}) => ({
    id: 11, form_id: 7, respondent_name: 'Test registrant', entry_count: 2, amount_due: '20.00',
    status: 'confirmed', admin_notes: null, submitted_at: null, payment_state: 'paid', payment_status: 'paid',
    payment_method: 'cash', paid_via: 'cash', total_minor: 2000, amount_due_minor: 2000, currency: 'usd',
    staff_code: { holder_name: 'Original staff' }, price_set_by: 'Original staff', staff_payment_method: 'cash',
    price_breakdown: { unit_minor: 1000, quantity: 2, label: 'Standard', currency: 'usd', list_unit_minor: 1500, staff_unit_minor: 1000 },
    ...over,
});

test('mounted list and detail show the effective total, list context, setter, original card choice and actual cash collector', async () => {
    const { screen, calls } = await responsesScreen([pricedRow({
        staff_payment_method: 'card', marked_paid_by: { name: 'Test collector' },
    })]);
    try {
        assert.match(screen.text(), /Registration price/);
        assert.match(screen.text(), /Total paid: \$20\.00/);
        assert.match(screen.text(), /List price · Standard: \$15\.00 × 2 = \$30\.00/);
        assert.match(screen.text(), /Price set by staff · Original staff/);
        assert.match(screen.text(), /Staff payment choice: Card/);
        assert.match(screen.text(), /Paid in cash/);
        assert.match(screen.text(), /taken at the table by Test collector/);
        assert.doesNotMatch(screen.text(), /held by Original staff/);
        assert.ok(screen.all(n => n.props['data-test'] === 'list-price-breakdown').length);
        click(screen.button('Registration price')); await flush();
        assert.equal(calls.at(-1).sort, 'amount_due');
        click(screen.all(n => n.tag === 'button' && n.props.title === 'View Details')[0]); await flush();
        assert.ok(screen.all(n => n.tag === 'h6' && n.textContent === 'Registration price').length, screen.text());
        assert.ok(screen.all(n => n.props['data-test'] === 'price-breakdown').length);
        assert.ok(screen.all(n => n.tag === 'dd' && n.textContent === '$20.00' && String(n.props.class).includes('fw-semibold')).length);
        assert.match(screen.text(), /Staff payment choice Card/);
    } finally { screen.unmount(); }
});

test('mounted complimentary entry is explicit, pending card still owes, and old snapshots keep their display', async () => {
    const free = pricedRow({ total_minor: 0, amount_due: '0.00', amount_due_minor: 0,
        price_breakdown: { unit_minor: 0, quantity: 2, currency: 'usd', list_unit_minor: 1500, staff_unit_minor: 0 } });
    const pending = pricedRow({ id: 12, payment_state: 'unpaid', payment_status: 'unpaid', payment_method: 'online', paid_via: null,
        staff_payment_method: 'card' });
    const { screen } = await responsesScreen([free, pending]);
    try {
        assert.match(screen.text(), /Complimentary entry/);
        assert.match(screen.text(), /Total paid: \$0\.00/);
        const tableRows = screen.all(n => n.tag === 'tr');
        const pendingRow = tableRows.find(n => n.textContent.includes('#12'))!;
        assert.match(pendingRow.textContent, /Amount due/);
        assert.match(pendingRow.textContent, /Unpaid/);
        assert.doesNotMatch(pendingRow.textContent, /Total paid|Complimentary|held by/);
        const open = screen.all(n => n.props['aria-label'] === 'View details of registration #12')[0];
        click(open); await flush();
        assert.ok(screen.all(n => n.tag === 'h6' && n.textContent === 'Amount due').length, screen.text());
    } finally { screen.unmount(); }
    const legacy = await responsesScreen([pricedRow({ price_set_by: undefined, staff_payment_method: undefined,
        price_breakdown: { unit_minor: 1500, quantity: 2, label: 'Standard', currency: 'usd' } })]);
    try {
        assert.match(legacy.screen.text(), /Registration price/);
        assert.doesNotMatch(legacy.screen.text(), /List price|Price set by staff|Staff payment choice|Total paid:/);
        assert.equal(legacy.screen.all(n => n.props['data-test'] === 'list-price-breakdown').length, 0);
    } finally { legacy.screen.unmount(); }
});

test('mounted attendee roster shows the same saved price context without changing older rows', async () => {
    const { screen, store } = await responsesScreen([]);
    (store as any).rosterMeta = { form: { id: 7 }, columns: [], payment: { enabled: true } };
    (store as any).rosterPaginated = { data: [{
        response_id: 11, entry_index: 0, values: {}, status: 'confirmed', payment: 'Paid in cash',
        payment_status: 'paid', payment_method: 'cash', holder: 'Test collector', total_minor: 2000,
        price_set_by: 'Original staff', staff_payment_method: 'card',
        price_breakdown: pricedRow().price_breakdown,
    }], current_page: 1, total: 1, per_page: 25 };
    try {
        click(screen.button('Everyone attending')); await flush();
        assert.match(screen.text(), /Total paid: \$20\.00/);
        assert.match(screen.text(), /List price · Standard: \$15\.00 × 2 = \$30\.00/);
        assert.match(screen.text(), /Price set by staff · Original staff/);
        assert.match(screen.text(), /Cash collector: Test collector/);
        (store as any).rosterPaginated.data = [{ response_id: 12, entry_index: 0, values: {}, status: 'confirmed', payment: 'Paid in cash' }];
        await flush();
        assert.doesNotMatch(screen.text(), /Total paid:|List price|Price set by staff|Cash collector:/);
    } finally { screen.unmount(); }
});

test('builder refuses an invalid override without codes and surfaces the matching validation message', async () => {
    const { screen, writes, field } = await builder(form({ staffCodes: false, staffPriceOverride: true }));
    try {
        assert.match(screen.text(), /Enable staff codes before enabling staff price overrides/);
        assert.equal(screen.button('Save').disabled, true);
        click(screen.button('Save')); await flush();
        assert.equal(writes.length, 0);
        check(field('formPaymentStaffCodes'), true); await flush();
        assert.doesNotMatch(screen.text(), /Enable staff codes before enabling staff price overrides/);
        assert.equal(screen.button('Save').disabled, false);
    } finally { screen.unmount(); }
});

test('mounted staff card displays the actual paid total including coverage and never assigns cash to its creator', async () => {
    const { screen } = await responsesScreen([pricedRow({
        payment_method: 'online', paid_via: null, staff_payment_method: 'card', total_minor: 2090, fee_covered_minor: 90,
    })]);
    try {
        assert.match(screen.text(), /Total paid: \$20\.90/);
        assert.match(screen.text(), /Paid by card/);
        assert.doesNotMatch(screen.text(), /held by|taken at the table/);
        click(screen.all(n => n.props['aria-label'] === 'View details of registration #11')[0]); await flush();
        assert.match(screen.text(), /Card fee covered \$0\.90/);
        assert.ok(screen.all(n => n.tag === 'dd' && n.textContent === '$20.90').length);
    } finally { screen.unmount(); }
});
