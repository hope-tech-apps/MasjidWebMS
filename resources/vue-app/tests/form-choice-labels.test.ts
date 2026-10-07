import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { mountSfc, click, flush } from './support/mountSfc.ts';
import * as formTypes from '../core/types/data/masjid-related/Form.ts';
import * as pricing from '../views/dashboard/formResponsePricing.ts';
import * as status from '../views/dashboard/formResponseStatus.ts';

const options = [
    { value: 'settingUp', label: 'Setting up' },
    { value: 'chicken', label: 'Chicken' },
    { value: 'rice,beans', label: 'Rice and beans' },
];

async function responsesScreen(type: string, repeatable: boolean, answers: any[], withOptions = true) {
    const column = { key: repeatable ? 'attendees.meal' : 'meal', field: 'meal', label: 'Meal',
        section: repeatable ? 'Attendees' : null, repeatable, type,
        ...(withOptions ? { options } : {}) };
    const rows = answers.map((answer, index) => ({ id: index + 11, form_id: 7,
        respondent_name: `Test registrant ${index}`, status: 'confirmed', entry_count: 1,
        data: repeatable ? { attendees: [{ meal: answer }] } : { meal: answer } }));
    const rosterRows = answers.map((answer, index) => ({ response_id: index + 11, entry_index: 0,
        values: { meal: Array.isArray(answer) ? answer.join(', ') : answer },
        ...(type === 'checkboxGroup' ? { choice_values: { meal: answer } } : {}),
        status: 'confirmed', registered_by: `Test registrant ${index}` }));
    const store = vue.reactive({
        formOptions: [{ id: 7, name: 'Test registration', slug: 'test-registration' }],
        responsesMeta: { form: { id: 7, name: 'Test registration' }, columns: [column] },
        responsesPaginated: { data: rows, total: rows.length, current_page: 1, per_page: 25 },
        rosterMeta: { form: { id: 7 }, columns: [{ ...column, key: 'meal' }] },
        rosterPaginated: { data: rosterRows, total: rosterRows.length, current_page: 1, per_page: 25 },
        masjidId: () => 1, fetchFormOptions: async () => store.formOptions,
        fetchResponses: async () => {}, fetchRoster: async () => {},
        fetchResponse: async (_formId: number, id: number) => rows.find(row => row.id === id),
    });
    (globalThis as any).HTMLElement = class {};
    (globalThis as any).document.body = { style: {} };
    const container = { setup: (_props: any, { slots }: any) => () => vue.h('div', [slots.headerButtons?.(), slots.default?.()]) };
    const screen = await mountSfc('views/dashboard/FormResponsesView.vue', {}, {
        vue: { ...vue, Teleport: vue.Fragment },
        '@/components/PageDataContainer.vue': { default: container },
        '@/components/forms/FormStaffCodesModal.vue': { default: { template: '<div />' } },
        '@/core/types/elements/Pagination': {}, '@/core/types/data/masjid-related/Form': formTypes,
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
    return { screen, store };
}

for (const repeatable of [false, true]) {
    for (const type of ['select', 'radio', 'checkboxGroup']) {
        for (const surface of ['detail', 'roster']) {
            test(`${repeatable ? 'repeating' : 'flat'} ${type}: ${surface} labels current choices and preserves removed values`, async () => {
                const answers = type === 'checkboxGroup'
                    ? [['settingUp', 'chicken', 'rice,beans', 'removedOption'], ['SETTINGUP']]
                    : ['settingUp', 'chicken', 'removedOption', 'SETTINGUP'];
                const expected = type === 'checkboxGroup'
                    ? ['Setting up, Chicken, Rice and beans, removedOption', 'SETTINGUP']
                    : ['Setting up', 'Chicken', 'removedOption', 'SETTINGUP'];
                const { screen, store } = await responsesScreen(type, repeatable, answers);
                const originalValues = JSON.stringify(store.rosterPaginated.data.map(row => row.values));
                try {
                    if (surface === 'detail') {
                        for (let index = 0; index < answers.length; index++) {
                            click(screen.all(n => n.props['aria-label'] === `View details of registration #${index + 11}`)[0]);
                            await flush();
                            const cells = screen.all(n => n.tag === (repeatable ? 'td' : 'dd'));
                            assert.ok(cells.some(n => n.textContent === expected[index]), screen.text());
                            click(screen.button('Close')); await flush();
                        }
                    } else {
                        click(screen.button('Everyone attending')); await flush();
                        const rosterCells = screen.all(n => n.tag === 'td');
                        for (const text of expected) assert.ok(rosterCells.some(n => n.textContent === text), screen.text());
                    }
                    assert.equal(JSON.stringify(store.rosterPaginated.data.map(row => row.values)), originalValues);
                } finally { screen.unmount(); }
            });
        }
    }
}

test('legacy metadata preserves stored choices and non-choice detail formatting', async () => {
    const { screen } = await responsesScreen('text', false, [false, null, ['one', 'two'], { note: 'old' }], false);
    try {
        for (const [index, expected] of ['No', '—', 'one, two', '{"note":"old"}'].entries()) {
            click(screen.all(n => n.props['aria-label'] === `View details of registration #${index + 11}`)[0]);
            await flush();
            assert.ok(screen.all(n => n.tag === 'dd').some(n => n.textContent === expected), screen.text());
            click(screen.button('Close')); await flush();
        }
    } finally { screen.unmount(); }
});

test('an older roster payload without choice_values remains readable', async () => {
    const { screen, store } = await responsesScreen('checkboxGroup', false, [['chicken', 'removedOption']]);
    delete (store.rosterPaginated.data[0] as any).choice_values;
    try {
        click(screen.button('Everyone attending')); await flush();
        assert.ok(screen.all(n => n.tag === 'td').some(n => n.textContent === 'chicken, removedOption'), screen.text());
    } finally { screen.unmount(); }
});
