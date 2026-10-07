import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { click, flush, loadTs, mountSfc } from './support/mountSfc.ts';

const require = createRequire(import.meta.url);
const vue = require('vue');
const pinia = require('pinia');
const typesOnly = {};

async function mount(rows: Record<string, any>[], confirmed = false, refusal?: string) {
    pinia.setActivePinia(pinia.createPinia());
    const posts: string[] = [];
    const questions: any[] = [];
    const messages: any[] = [];
    let reads = 0;
    let release: (() => void) | undefined;
    const masjidStore = vue.reactive({ masjid: { id: 7 } });
    const api = {
        get: async () => {
            reads++;
            return { data: { status: 'success', data: { data: rows.map(r => ({ ...r })), total: rows.length, current_page: 1, per_page: 15 } } };
        },
        post: async (url: string) => {
            posts.push(url);
            await new Promise<void>(resolve => { release = resolve; });
            if (refusal) {
                rows[0] = { ...rows[0], status: 'sending', cancellable: false };
                throw { response: { data: { status: 'error', message: refusal } } };
            }
            rows[0] = { ...rows[0], status: 'cancelled', cancellable: false, cancelled_at: '2026-10-06T12:00:00Z' };
            return { data: { status: 'success', message: 'Broadcast cancelled. Nothing will be sent.', data: rows[0] } };
        },
    };
    const storeModule = await loadTs('stores/masjid/broadcastsStore.ts', {
        'pinia': pinia,
        'vue': vue,
        '@/core/types/data/masjid-related/Broadcast': typesOnly,
        '../masjidStore': { useMasjidStore: () => masjidStore },
        '@/core/services/ApiService': { default: api },
        'axios': typesOnly,
        '@/core/types/data/interfaces/PaginatedData': typesOnly,
    });
    const screen = await mountSfc('views/dashboard/broadcasts/BroadcastsView.vue', {}, {
        '@/components/PageDataContainer.vue': { default: { render(this: any) { return vue.h('main', this.$slots.default?.()); } } },
        '@/core/types/data/masjid-related/Broadcast': typesOnly,
        '@/core/types/elements/Pagination': typesOnly,
        '@/stores/masjid/broadcastsStore': storeModule,
        '@/core/plugins/SweetAlerts2': {
            QSwal: { fire: async (options: any) => { questions.push(options); return { isConfirmed: confirmed }; } },
            MSwal: { fire: async (options: any) => { messages.push(options); } },
        },
        'vue-router': { useRouter: () => ({ push: () => {} }) },
    });
    await flush();
    return { screen, posts, questions, messages, reads: () => reads, release: () => release?.() };
}

const row = (over: Record<string, any> = {}) => ({
    id: 11, title: 'Test notice', body: 'Test body', audience: 'everyone', status: 'scheduled',
    scheduled_at: '2099-10-06T13:00:00Z', created_at: '2026-10-06T12:00:00Z',
    cancellable: true, deliveries: [], ...over,
});
const buttons = (screen: any) => screen.all((n: any) => n.tag === 'button' && /Cancel/.test(n.textContent));

test('Cancel follows the server flag, including absent and contradictory states', async () => {
    const mounted = await mount([
        row(), row({ id: 12, cancellable: false }), row({ id: 13, cancellable: undefined }),
        row({ id: 14, status: 'sent', cancellable: true }),
    ]);
    assert.equal(buttons(mounted.screen).length, 2);
    mounted.screen.unmount();
});

test('declining the cancellation question sends no request', async () => {
    const mounted = await mount([row()]);
    click(buttons(mounted.screen)[0]);
    await flush();
    assert.deepEqual(mounted.posts, []);
    assert.deepEqual(mounted.questions, [{
        title: 'Cancel scheduled broadcast?',
        text: 'This broadcast will not be sent on any channel.',
        icon: 'warning', confirmButtonText: 'Yes, cancel broadcast', cancelButtonText: 'Keep scheduled',
    }]);
    assert.equal(buttons(mounted.screen).length, 1);
    mounted.screen.unmount();
});

test('an overdue schedule cancels once through the store and shows Cancelled after refresh', async () => {
    const mounted = await mount([row({ scheduled_at: '2000-01-01T00:00:00Z' })], true);
    click(buttons(mounted.screen)[0]);
    await flush();
    assert.deepEqual(mounted.posts, ['/api/admin/masjids/7/broadcasts/11/cancel']);
    assert.equal(click(buttons(mounted.screen)[0]), false);
    assert.match(mounted.screen.text(), /Cancelling…/);
    mounted.release();
    await flush();
    assert.equal(mounted.reads(), 2);
    assert.match(mounted.screen.text(), /Cancelled/);
    assert.equal(buttons(mounted.screen).length, 0);
    assert.equal(mounted.messages[0].text, 'Broadcast cancelled. Nothing will be sent.');
    mounted.screen.unmount();
});

test('a send that wins the race shows the server refusal and refreshes the Sending row', async () => {
    const sentence = 'This broadcast is sending and cannot be cancelled. Refresh to see the delivery status.';
    const mounted = await mount([row()], true, sentence);
    click(buttons(mounted.screen)[0]);
    await flush();
    mounted.release();
    await flush();
    assert.equal(mounted.messages[0].text, sentence);
    assert.equal(mounted.reads(), 2);
    assert.match(mounted.screen.text(), /Sending/);
    assert.equal(buttons(mounted.screen).length, 0);
    mounted.screen.unmount();
});

test('interrupted history shows each outcome and asks for channel checks before a new composition', async () => {
    const mounted = await mount([row({
        status: 'interrupted', cancellable: false,
        deliveries: [
            { id: 1, channel: 'announcement', status: 'sent', target_count: 1 },
            { id: 2, channel: 'email', status: 'interrupted', target_count: 0 },
            { id: 3, channel: 'sms', status: 'not_sent', target_count: 0 },
        ],
    })]);
    const words = mounted.screen.text();
    assert.match(words, /Interrupted/);
    assert.match(words, /Feed: sent \(1\)/);
    assert.match(words, /Email: interrupted — outcome unknown/);
    assert.match(words, /Text: not sent/);
    assert.doesNotMatch(words, /outcome unknown \(0\)|not sent \(0\)/);
    assert.match(words, /Sending stopped before this broadcast finished\. This broadcast will not be sent again automatically\. Check each channel before composing a replacement\./);
    assert.equal(buttons(mounted.screen).length, 0);
    mounted.screen.unmount();
});

test('a channel in progress says sending instead of pending', async () => {
    const mounted = await mount([row({ status: 'sending', cancellable: false,
        deliveries: [{ id: 1, channel: 'email', status: 'sending', target_count: 0 }],
    })]);
    assert.match(mounted.screen.text(), /Email: sending/);
    assert.doesNotMatch(mounted.screen.text(), /Email: pending|sending \(0\)/);
    mounted.screen.unmount();
});

test('recovery after every channel finished describes a lost summary with known outcomes', async () => {
    const mounted = await mount([row({ status: 'partial', cancellable: false,
        send_recovered_at: '2026-10-07T12:00:00Z', deliveries: [],
    })]);
    assert.match(mounted.screen.text(), /Partly sent/);
    assert.match(mounted.screen.text(), /Sending stopped before this broadcast finished\. This broadcast will not be sent again automatically\. Check each channel before composing a replacement\./);
    assert.doesNotMatch(mounted.screen.text(), /before all channel outcomes were recorded/);
    mounted.screen.unmount();
});
