import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { click, flush, mountSfc } from './support/mountSfc.ts';

const require = createRequire(import.meta.url);
const vue = require('vue');

async function mount(response: any, reject = false, summary: any = null) {
    const alerts: any[] = [];
    const posts: string[] = [];
    const screen = await mountSfc('views/dashboard/AnnualStatementsView.vue', {}, {
        '@/components/PageDataContainer.vue': { default: { render(this: any) { return vue.h('main', this.$slots.default?.()); } } },
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 7 }) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 7 } }) },
        '@/core/services/ApiService': { default: {
            get: async () => ({ data: { status: 'success', data: summary ?? {
                donors: [{ contact_id: 11, name: 'Example Donor', email: 'donor@example.test', total_eligible: 25000, gift_count: 1, currency: 'usd' }],
                total_eligible: 25000,
            } } }),
            post: async (url: string) => {
                posts.push(url);
                if (reject) throw new Error('Request refused');
                return { data: response };
            },
        } },
        'sweetalert2': { default: { fire: async (options: any) => {
            alerts.push(options);
            return { isConfirmed: true };
        } } },
    });
    await flush();
    return { screen, alerts, posts };
}

test('Email reports queued delivery to the selected donor', async () => {
    const { screen, alerts, posts } = await mount({ status: 'success' });
    try {
        click(screen.all((n: any) => n.tag === 'button' && n.textContent === 'Email')[0]);
        await flush();
        assert.match(posts[0], /\/masjids\/7\/annual-statements\/11\/send\?year=\d{4}$/);
        assert.deepEqual(alerts, [{ icon: 'success', title: 'Queued', text: 'Statement queued for delivery to Example Donor.' }]);
    } finally { screen.unmount(); }
});

for (const reject of [false, true]) {
    test(`Email shows failure when the request ${reject ? 'rejects' : 'returns an error'}`, async () => {
        const { screen, alerts } = await mount({ status: 'error' }, reject);
        try {
            click(screen.all((n: any) => n.tag === 'button' && n.textContent === 'Email')[0]);
            await flush();
            assert.deepEqual(alerts, [{ icon: 'error', title: 'Error!', text: 'Failed to queue statement.' }]);
        } finally { screen.unmount(); }
    });
}

for (const failed of [0, 1, 2]) {
    test(`Email all reports queued, skipped and failed with ${failed} failure(s)`, async () => {
        const queued = 2 - failed;
        const { screen, alerts, posts } = await mount({ status: 'success', data: { queued, skipped: 1, failed } });
        try {
            click(screen.all((n: any) => n.tag === 'button' && n.textContent === 'Email all statements')[0]);
            await flush();
            assert.match(posts[0], /\/masjids\/7\/annual-statements\/send-all\?year=\d{4}$/);
            assert.deepEqual(alerts[1], {
                icon: failed ? 'warning' : 'success', title: failed ? 'Some statements failed' : 'Done',
                text: `${queued} statement(s) queued, 1 skipped (no email), ${failed} failed.`,
            });
        } finally { screen.unmount(); }
    });
}

for (const reject of [false, true]) {
    test(`Email all reports failure when the request ${reject ? 'rejects' : 'returns an error'}`, async () => {
        const { screen, alerts } = await mount({ status: 'error' }, reject);
        try {
            click(screen.all((n: any) => n.tag === 'button' && n.textContent === 'Email all statements')[0]);
            await flush();
            assert.deepEqual(alerts[1], { icon: 'error', title: 'Error!', text: 'Failed to queue statements.' });
        } finally { screen.unmount(); }
    });
}

for (const legacy of [false, true]) {
    test(`Mixed summary displays both currencies and counts distinct donors (${legacy ? 'cached API' : 'current API'})`, async () => {
        const donors = [
            { contact_id: 11, name: 'Example Donor', email: 'donor@example.test', total_eligible: 30000, gift_count: 2, currency: 'USD' },
            { contact_id: 11, name: 'Example Donor', email: 'donor@example.test', total_eligible: 10000, gift_count: 1, currency: 'CAD' },
        ];
        const { screen } = await mount({}, false, { donors, total_eligible: legacy ? 40000 : null, totals_by_currency: { USD: 30000, CAD: 10000 } });
        try {
            const text = screen.all((n: any) => n.tag === 'main')[0].textContent;
            assert.match(text, /1 donor ·/);
            assert.match(text, /Total eligible: USD 300\.00; CAD 100\.00/);
            assert.doesNotMatch(text, /400\.00/);
            assert.equal(screen.all((n: any) => n.tag === 'tbody')[0].children.filter((n: any) => n.tag === 'tr').length, 2);
        } finally { screen.unmount(); }
    });
}
