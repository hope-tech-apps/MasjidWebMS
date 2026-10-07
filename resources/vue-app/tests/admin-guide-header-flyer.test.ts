import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { click, compileSfc, flush, mountSfc } from './support/mountSfc.ts';
import * as paymentMethods from '../views/dashboard/paymentMethods.ts';

const vue = createRequire(import.meta.url)('vue');
const json = (name: string) => JSON.parse(readFileSync(new URL(`../../flyer-templates/${name}.json`, import.meta.url), 'utf8'));

async function header() {
    return await compileSfc('components/PageDataContainer.vue', {
        '@/components/partials/Pagination.vue': { default: { render: () => null } },
        '@/core/types/elements/Buttons': {}, '@/core/types/elements/Pagination': {},
    });
}

test('Payment Methods has no Add New button from the real shared header', async () => {
    const screen = await mountSfc('views/dashboard/PaymentMethodsView.vue', {}, {
        '@/components/PageDataContainer.vue': { default: await header() },
        '@/core/services/ApiErrors': { apiErrorText: () => '' },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1, name: 'Sample organisation' } }) },
        '@/stores/masjid/paymentMethodsStore': { usePaymentMethodsStore: () => ({ fetchMethods: async () => {}, payload: { methods: [], catalogue: [] } }) },
        '@/views/dashboard/paymentMethods': paymentMethods,
        sweetalert2: { default: {} },
    });
    await flush();
    assert.equal(screen.all((n) => n.tag === 'button' && n.textContent === 'Add New').length, 0);
    assert.ok(screen.button('Save'));
    screen.unmount();
});

test('Pages still opens its create modal from Add New in the real shared header', async () => {
    const screen = await mountSfc('views/dashboard/pages/PagesView.vue', {}, {
        '@/components/PageDataContainer.vue': { default: await header() },
        '@/components/modals/PageFormModal.vue': { default: { render: () => vue.h('div', 'New page form') } },
        '@/core/types/data/masjid-related/Page': {}, '@/core/types/elements/Pagination': {},
        '@/stores/masjid/pagesStore': { usePagesStore: () => ({ pagesPaginated: { data: [] }, fetchMasjidPagesPaginated: async () => {} }) },
        'vue-router': { useRouter: () => ({ push() {} }) }, sweetalert2: { default: {} },
        vuedraggable: { default: { render: () => null } },
    });
    await flush();
    click(screen.button('Add New')); await flush();
    assert.match(screen.text(), /New page form/);
    screen.unmount();
});

for (const organization of ['Masjid', 'School', 'Organization']) {
    test(`Flyer Studio names the logo for ${organization}`, async () => {
        for (const name of ['event-photo', 'event-banner', 'event-bulletin', 'event-invitation', 'janazah']) {
            const manifest = json(name);
            const screen = await mountSfc('components/flyer/FlyerSlotForm.vue', {
                slots: manifest.slots, content: {}, images: {},
            }, {
                '@/core/types/data/masjid-related/Flyer': { FLYER_CUTOUT_PENDING: [] },
                '@/stores/masjidStore': { useMasjidStore: () => ({ term: () => organization }) },
            });
            await flush();
            assert.equal(screen.all((n) => n.tag === 'label' && n.props.for === 'slot-logo')[0].textContent, `${organization} logo`);
            screen.unmount();
        }
    });
}

test("a custom flyer logo label remains the author's wording", async () => {
    const screen = await mountSfc('components/flyer/FlyerSlotForm.vue', {
        slots: [{ name: 'logo', label: 'Sponsor logo', type: 'image', required: false }], content: {}, images: {},
    }, {
        '@/core/types/data/masjid-related/Flyer': { FLYER_CUTOUT_PENDING: [] },
        '@/stores/masjidStore': { useMasjidStore: () => ({ term: () => 'School' }) },
    });
    await flush();
    assert.equal(screen.all((n) => n.tag === 'label' && n.props.for === 'slot-logo')[0].textContent, 'Sponsor logo');
    screen.unmount();
});

