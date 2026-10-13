import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { click, flush, mountSfc } from './support/mountSfc.ts';

const require = createRequire(import.meta.url);
const vue = require('vue');

async function broadcastScreen(wording: string | undefined, nextWording?: string) {
    let reads = 0;
    const store = vue.reactive({ broadcastsPaginated: { data: [{ id: 1, title: 'Example', body: 'Body', audience: 'everyone',
        status: 'sent', created_at: '2026-10-13T12:00:00Z', deliveries: [{ id: 1, channel: 'push', status: 'sent',
            target_count: 74, push_outcome_text: wording }] }], total: 1, current_page: 1, per_page: 15 },
        fetchBroadcastsPaginated: async () => {
            reads++;
            if (reads > 1 && nextWording) store.broadcastsPaginated.data[0].deliveries[0].push_outcome_text = nextWording;
        } });
    const screen = await mountSfc('views/dashboard/broadcasts/BroadcastsView.vue', {}, {
        '@/components/PageDataContainer.vue': { default: { render(this: any) { return vue.h('main', this.$slots.default?.()); } } },
        '@/core/types/data/masjid-related/Broadcast': {}, '@/core/types/elements/Pagination': {},
        '@/stores/masjid/broadcastsStore': { useBroadcastsStore: () => store },
        '@/core/plugins/SweetAlerts2': { MSwal: {}, QSwal: {} }, 'vue-router': { useRouter: () => ({ push() {} }) },
    });
    await flush();
    return screen;
}

for (const wording of ['Sent to 74 phones. Checking delivery…',
    'Sent to 74 phones. Delivered to 8. 40 could not be delivered (iPhone).',
    'Sent to 74 phones. Delivery could not be checked.',
    'Sent to 74 phones. Delivered to 74.']) {
    test(`broadcast list renders the stored outcome: ${wording}`, async () => {
        const screen = await broadcastScreen(wording);
        assert.ok(screen.text().includes(wording));
        assert.ok(!screen.text().includes('Push: Sent (74)'));
        screen.unmount();
    });
}

test('legacy push payloads show an unknown outcome instead of a sent target badge', async () => {
    const screen = await broadcastScreen(undefined);
    assert.ok(screen.text().includes('Delivery could not be confirmed for 74 phones.'));
    assert.ok(!screen.text().includes('Sent to'));
    assert.ok(!screen.text().includes('Push: sent (74)'));
    screen.unmount();
});

test('checking delivery reloads the stored outcome on the same broadcast screen', async () => {
    const next = 'Sent to 74 phones. Delivered to 8. 40 could not be delivered (iPhone).';
    const screen = await broadcastScreen('Sent to 74 phones. Checking delivery…', next);
    click(screen.button('Check delivery'));
    await flush();
    assert.ok(screen.text().includes(next));
    assert.ok(!screen.text().includes('Checking delivery…'));
    screen.unmount();
});

test('notification history refresh reads its tenant and discards a late response after an organisation switch', async () => {
    const masjid = vue.reactive({ masjid: { id: 1 } });
    const requests: string[] = [];
    let release: ((value: any) => void) | undefined;
    let delay = false;
    const field = { render() { return vue.h('span'); } };
    const slots = { render(this: any) { return vue.h('div', this.$slots.default?.()); } };
    const rule: any = { required: () => rule, nullable: () => rule, label: () => rule, shape: () => rule };
    const screen = await mountSfc('views/dashboard/NotificationFormView.vue', {}, {
        '@/components/form/LoadingButton.vue': { default: slots }, '@/components/form/ColumnInputContainer.vue': { default: slots },
        '@/core/services/ApiService': { default: { get: async (url: string) => {
            requests.push(url);
            if (delay) return new Promise(resolve => { release = resolve; });
            return { data: { data: [{ id: 1, title: 'Example notice', push_outcome_text: 'Delivery could not be checked.' }] } };
        } } },
        '@/stores/masjidStore': { useMasjidStore: () => masjid }, 'axios': {},
        'vee-validate': { Form: slots, Field: field }, 'yup': { number: () => rule, object: () => rule, string: () => rule },
        '@/core/plugins/SweetAlerts2': { MSwal: {}, QSwal: {} }, 'sweetalert2': {},
        '@/stores/super/masjidsStore': { useMasjidsStore: () => ({ masjids: [] }) },
        '@/stores/authStore': { useAuthStore: () => ({ user: { type: 'MasjidAdmin' } }) },
        '@/assets/ts/swalMethods': { getMessageFromObj: () => '' }, '@/core/types/config/AxiosCustom': {},
    });
    await flush();
    assert.ok(screen.text().includes('Delivery could not be checked.'));
    delay = true;
    click(screen.button('Check delivery'));
    await flush();
    const oldRelease = release!;
    delay = false;
    masjid.masjid.id = 2;
    await flush();
    oldRelease({ data: { data: [{ id: 1, title: 'OLD TENANT EXAMPLE', push_outcome_text: 'Wrong outcome' }] } });
    await flush();
    assert.ok(!screen.text().includes('OLD TENANT EXAMPLE'));
    assert.deepEqual(requests, ['/api/admin/masjids/1/notifications', '/api/admin/masjids/1/notifications', '/api/admin/masjids/2/notifications']);
    screen.unmount();
});
