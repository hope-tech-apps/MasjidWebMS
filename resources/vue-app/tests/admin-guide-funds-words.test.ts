import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import * as FundTypes from '../core/types/data/masjid-related/Fund.ts';
import * as ApiErrors from '../core/services/ApiErrors.ts';
import * as classTeachers from '../core/helpers/classTeachers.ts';
import * as teacherForm from '../core/helpers/teacherForm.ts';
import * as lastOpened from '../core/helpers/lastOpened.ts';
import { click, flush, loadTs, mountSfc, Node, select, submit, type } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');
const OfferingTypes = await loadTs('core/types/data/masjid-related/Offering.ts', {
    '@/core/types/data/masjid-related/Contact': {}, '@/core/types/data/masjid-related/Form': {},
});
(globalThis as any).document.body = { style: {} };
Object.defineProperty(Node.prototype, 'style', { get() { return this.props.style ??= {}; } });

const Page = { props: ['buttonProps'], emits: ['headerButtonClick'], setup(props: any, { slots, emit }: any) {
    return () => vue.h('div', [vue.h('button', { onClick: () => emit('headerButtonClick') }, props.buttonProps.title), slots.default?.()]);
} };

async function screen(file: string, term = 'Classrooms', deletion = 'success') {
    const sent: any[] = [];
    const alerts: any[] = [];
    const fund = { id: 1, name: 'Sample fund', type: 'general', receiptable: true, is_active: true };
    const funds = { funds: [fund], fetchFunds: async () => {}, createFund: async (body: any) => { sent.push({ ...body }); },
        deleteFund: async () => { if (deletion !== 'success') throw { response: { data: { status: 'failed', data: deletion } } }; } };
    const groups = { fetchGroups: async () => {}, createGroup: async (body: any) => { sent.push({ ...body }); } };
    const teachers = { teachers: [], classOptions: [], classOptionsKnown: true, classOptionsFailed: false, fetchTeachers: async () => {}, fetchClassOptions: async () => {} };
    const modules = {
        vue: { ...vue, Teleport: vue.Fragment },
        '@/core/types/elements/Pagination': {},
        '@/core/types/data/masjid-related/Group': {},
        '@/core/types/data/masjid-related/Teacher': {},
        '@/core/types/data/masjid-related/Form': {},
        '@/components/PageDataContainer.vue': { default: Page },
        '@/views/dashboard/groups/SchoolSubjectsCard.vue': { default: { render: () => null } },
        '@/core/types/data/masjid-related/Fund': FundTypes,
        '@/core/types/data/masjid-related/Offering': OfferingTypes,
        '@/stores/masjid/fundsStore': { useFundsStore: () => funds },
        '@/stores/masjid/groupsStore': { useGroupsStore: () => groups },
        '@/stores/masjid/teachersStore': { useTeachersStore: () => teachers },
        '@/stores/masjid/offeringsStore': { useOfferingsStore: () => ({ fetchOfferings: async () => {}, offeringsPaginated: { data: [] } }) },
        '@/stores/masjid/formsStore': { useFormsStore: () => ({ formOptions: [], fetchFormOptions: async () => {} }) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ orgType: 'masjid', masjid: { id: 1 }, term: () => term }) },
        '@/stores/authStore': { useAuthStore: () => ({ user: { type: 'MasjidAdmin' } }) },
        '@/core/constants/appConfigConstants': { LOCAL_STORAGE_KEYS: {} },
        '@/core/services/ApiErrors': ApiErrors,
        '@/core/helpers/classTeachers': classTeachers,
        '@/core/helpers/teacherForm': teacherForm,
        '@/core/helpers/lastOpened': lastOpened,
        '@/core/access/orgAccess': { canEditForms: () => true, canUseWebPages: () => true },
        '@/composables/useOfferingDisplay': { useOfferingDisplay: () => ({ offeringKindLabel: (key: string) => key }) },
        '@/composables/useMinorUnits': { formatMinor: () => '' },
        sweetalert2: { default: { fire: async (options: any) => { alerts.push(options); return { isConfirmed: true }; } } },
    };
    const mounted = await mountSfc(`views/dashboard/${file}.vue`, {}, modules);
    await flush();
    return { mounted, sent, alerts };
}

test('fund Type options show words and submit every original lowercase API key', async () => {
    const { mounted, sent } = await screen('FundsView');
    try {
        for (const key of FundTypes.FUND_TYPES) {
            click(mounted.button('Add Fund')); await flush();
            const options = mounted.all((n) => n.tag === 'option' && ['zakat', 'sadaqah', 'fitra', 'waqf', 'general'].includes(n.props.value));
            assert.deepEqual(options.map((n) => [n.props.value, n.textContent]), [
                ['zakat', 'Zakat'], ['sadaqah', 'Sadaqah'], ['fitra', 'Fitra'], ['waqf', 'Waqf'], ['general', 'General'],
            ]);
            type(mounted.all((n) => n.tag === 'input' && n.type === 'text')[0], 'Sample fund');
            select(mounted.all((n) => n.tag === 'select')[0], key);
            submit(mounted.all((n) => n.tag === 'form')[0]); await flush();
        }
        assert.deepEqual(sent.map((body) => body.type), ['zakat', 'sadaqah', 'fitra', 'waqf', 'general']);
    } finally { mounted.unmount(); }
});

test('class Kind options show words and submit every original lowercase API key', async () => {
    const { mounted, sent } = await screen('GroupsView');
    try {
        for (const key of ['general', 'class', 'halaqa', 'team']) {
            click(mounted.button('Add')); await flush();
            const options = mounted.all((n) => n.tag === 'option' && ['general', 'class', 'halaqa', 'team'].includes(n.props.value));
            assert.deepEqual(options.map((n) => [n.props.value, n.textContent]), [
                ['general', 'General'], ['class', 'Class'], ['halaqa', 'Halaqa'], ['team', 'Team'],
            ]);
            const inputs = mounted.all((n) => n.tag === 'input' && n.type === 'text');
            type(inputs[1], 'Sample class'); type(inputs[2], 'sample-class');
            select(mounted.all((n) => n.tag === 'select' && n.children.some((o) => o.props.value === 'class'))[0], key);
            submit(mounted.all((n) => n.tag === 'form')[0]); await flush();
        }
        assert.deepEqual(sent.map((body) => body.kind), ['general', 'class', 'halaqa', 'team']);
    } finally { mounted.unmount(); }
});

for (const term of ['Halaqat', 'Classrooms', 'Teams', 'Classroom', 'Group', 'Groups']) {
    test(`Teachers and Programs sentences work with the term ${term}`, async () => {
        const teachers = await screen('TeachersView', term);
        try {
            click(teachers.mounted.button('Add Teacher')); await flush();
            assert.ok(teachers.mounted.text().includes(`Assign the ${term.toLowerCase()} they will lead (at least one).`));
        } finally { teachers.mounted.unmount(); }
        const programs = await screen('OfferingsView', term);
        try {
            assert.ok(programs.mounted.text().includes('an optional roster destination, and how many seats there are.'));
            click(programs.mounted.button('Add New')); await flush();
            assert.ok(programs.mounted.text().includes('Confirmed registrants are added to the selected roster. Leave it empty for an offering that has no roster.'));
        } finally { programs.mounted.unmount(); }
    });
}

test('fund deletion shows the server sentence and preserves the empty-fund success words', async () => {
    const sentence = 'This fund has gifts recorded in it, so it cannot be deleted. Switch it to inactive instead: it is then hidden from new donations and its history is kept.';
    for (const outcome of [sentence, 'success']) {
        const { mounted, alerts } = await screen('FundsView', 'Classrooms', outcome);
        try {
            click(mounted.all((n) => n.tag === 'button' && n.props.title === 'Delete')[0]); await flush();
            assert.equal(alerts[0].confirmButtonText, 'Yes, delete it!');
            assert.equal(alerts[1].text, outcome === 'success' ? 'Fund has been removed.' : sentence);
            assert.equal(alerts[1].title, outcome === 'success' ? 'Deleted!' : 'Error!');
        } finally { mounted.unmount(); }
    }
});
