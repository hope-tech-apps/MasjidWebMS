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
    assert.ok(screen.text().includes('Finish the form there'));
    screen.unmount();
});

test('enabled Connect offers both doors and opens the OAuth URL immediately', async () => {
    const { screen, calls, opened } = await panel({ ...empty, oauth: { enabled: true, history: [] } });
    assert.ok(screen.text().includes('Create a new account'));
    click(screen.button('Link an existing Stripe account'));
    await flush();
    assert.deepEqual(calls, ['existing']);
    assert.match(opened[0].location.href, /oauth\/authorize/);
    assert.ok(screen.text().includes('Sign in to Stripe in the new tab and allow access, then come back here and press Refresh status.'));
    assert.ok(!screen.text().includes('Finish the form there'));
    click(screen.button('Refresh status'));
    await flush();
    assert.ok(!screen.text().includes('Sign in to Stripe in the new tab'));
    click(screen.button('Create a new account'));
    await flush();
    assert.ok(screen.text().includes('Finish the form there'));
    assert.ok(!screen.text().includes('Sign in to Stripe in the new tab'));
    screen.unmount();
});

test('unlink needs office confirmation and history shows suffix actor and time', async () => {
    const status = { ...empty, stripe_account_id: 'acct_test_existing', charges_enabled: true,
        oauth: { enabled: true, history: [{ action: 'link', account_last4: '0001', actor: 'Example Staff', at: 'Oct 15, 2026, 1:34 AM' }] } };
    const canceled = await panel(status, false);
    click(canceled.screen.button('Unlink Stripe account'));
    await flush();
    assert.deepEqual(canceled.calls, []);
    canceled.screen.unmount();
    const { screen, calls } = await panel(status);
    assert.ok(screen.text().includes('Example Staff linked account ending 0001 on Oct 15, 2026, 1:34 AM'));
    assert.ok(!screen.text().includes('acct_test_existing'));
    click(screen.button('Unlink Stripe account'));
    await flush();
    assert.deepEqual(calls, ['unlink']);
    assert.ok(screen.text().includes('Stripe account unlinked.'));
    assert.ok(screen.text().includes('Link an existing Stripe account'));
    screen.unmount();
});

test('history distinguishes Stripe disconnects from office actions and former staff', async () => {
    const at = 'Oct 15, 2026, 1:34 AM';
    const { screen } = await panel({ ...empty, oauth: { enabled: true, history: [
        { action: 'deauthorized', account_last4: '0001', actor: null, at },
        { action: 'unlink', account_last4: '0002', actor: 'Example Office', at },
        { action: 'link', account_last4: '0003', actor: null, at },
        { action: 'unlink', account_last4: '0004', actor: null, at },
    ] } });
    assert.ok(screen.text().includes(`Stripe disconnected account ending 0001 on ${at}`));
    assert.ok(screen.text().includes(`Example Office unlinked account ending 0002 on ${at}`));
    assert.ok(screen.text().includes(`a former staff member linked account ending 0003 on ${at}`));
    assert.ok(screen.text().includes(`a former staff member unlinked account ending 0004 on ${at}`));
    assert.ok(!screen.text().includes('by Stripe or removed staff'));
    assert.ok(!screen.text().includes('Invalid Date'));
    screen.unmount();
});
