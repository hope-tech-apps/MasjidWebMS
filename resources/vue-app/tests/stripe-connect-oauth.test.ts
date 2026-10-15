import { test } from 'node:test';
import assert from 'node:assert/strict';
import { reactive } from 'vue';
import { click, flush, mountSfc } from './support/mountSfc.ts';
import * as stripeTypes from '../core/types/data/masjid-related/StripeConnect.ts';

async function panel(status: any, confirmed = true) {
    const calls: string[] = [];
    const opened: any[] = [];
    const store = reactive({
        connectStatus: status,
        fetchStatus: async () => {},
        startOnboarding: async () => { calls.push('create'); return 'https://connect.stripe.com/setup/test'; },
        startExistingAccount: async () => { calls.push('existing'); return 'https://connect.stripe.com/oauth/authorize?test'; },
        unlinkAccount: async () => {
            calls.push('unlink');
            store.connectStatus = { ...store.connectStatus, stripe_account_id: null };
        },
    });
    (globalThis as any).window = { open: () => {
        const tab = { closed: false, location: { href: '' }, close() { this.closed = true; } };
        opened.push(tab);
        return tab;
    } };
    const screen = await mountSfc('components/StripeConnectPanel.vue', {}, {
        'sweetalert2': { default: { fire: async () => ({ isConfirmed: confirmed }) } },
        '@/stores/masjid/connectStore': { useConnectStore: () => store, isForbidden: () => false, envelopeMessage: (e: any) => e.message },
        '@/stores/masjidStore': { useMasjidStore: () => ({ orgType: 'school', masjid: {} }) },
        '@/core/access/orgAccess': { moduleIsOff: () => false, moduleSwitchedOn: () => false },
        '@/core/types/data/masjid-related/StripeConnect': stripeTypes,
    });
    await flush();
    return { screen, calls, opened };
}

const empty = { stripe_account_id: null, charges_enabled: false, payouts_enabled: false };

test('unconfigured Connect keeps its existing door and hides OAuth and unlink', async () => {
    const { screen, calls } = await panel(empty);
    assert.ok(screen.text().includes('Connect with Stripe'));
    assert.ok(!screen.text().includes('Link an existing'));
    assert.ok(!screen.text().includes('Unlink Stripe'));
    click(screen.button('Connect with Stripe'));
    await flush();
    assert.deepEqual(calls, ['create']);
    screen.unmount();
});

test('enabled Connect offers both doors and opens the OAuth URL immediately', async () => {
    const { screen, calls, opened } = await panel({ ...empty, oauth: { enabled: true, history: [] } });
    assert.ok(screen.text().includes('Create a new account'));
    click(screen.button('Link an existing Stripe account'));
    await flush();
    assert.deepEqual(calls, ['existing']);
    assert.match(opened[0].location.href, /oauth\/authorize/);
    screen.unmount();
});

test('unlink needs office confirmation and history shows suffix actor and time', async () => {
    const status = { ...empty, stripe_account_id: 'acct_test_existing', charges_enabled: true,
        oauth: { enabled: true, history: [{ action: 'link', account_last4: 'ting', actor: 'Example Staff', at: '2026-10-15T12:00:00Z' }] } };
    const canceled = await panel(status, false);
    click(canceled.screen.button('Unlink Stripe account'));
    await flush();
    assert.deepEqual(canceled.calls, []);
    canceled.screen.unmount();
    const { screen, calls } = await panel(status);
    assert.ok(screen.text().includes('Linked account ending ting by Example Staff'));
    assert.ok(!screen.text().includes('acct_test_existing'));
    click(screen.button('Unlink Stripe account'));
    await flush();
    assert.deepEqual(calls, ['unlink']);
    assert.ok(screen.text().includes('Stripe account unlinked.'));
    assert.ok(screen.text().includes('Link an existing Stripe account'));
    screen.unmount();
});
