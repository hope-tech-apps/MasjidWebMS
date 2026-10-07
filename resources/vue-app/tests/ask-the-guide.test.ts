import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { compileSfc, mountSfc, flush, click, type, loadTs, deferred, withDocumentKeys } from './support/mountSfc.ts';
import './support/guideDom.ts';
const vue = createRequire(import.meta.url)('vue');
withDocumentKeys();
const route = vue.reactive({ params: { book: 'admin', task: '' }, query: {}, fullPath: '/masjid/help/admin' });
const auth = vue.reactive({ user: { type: 'MasjidAdmin', id: 1 }, dashboardMasjidId: 1, isAuthenticated: true });
const books = [{ book: 'admin', title: 'Admin guide', version: 'd1-1234abcd' }, { book: 'school', title: 'School guide', version: 'd1-1234abcd' }];
const html = readFileSync(new URL('../../../tests/fixtures/guides/d1-1234abcd/admin/page.html', import.meta.url), 'utf8');
async function screen(available = true, error?: string) {
    route.params.book = 'admin'; route.params.task = ''; route.fullPath = '/masjid/help/admin'; auth.dashboardMasjidId = 1;
    const pending = deferred<any>(); const calls: any[] = [];
    const ask = await compileSfc('components/guides/GuideAsk.vue', {});
    const viewer = await compileSfc('components/guides/GuideViewer.vue', {});
    const content = await compileSfc('components/guides/GuideContent.vue', {
        '@/components/guides/GuideViewer.vue': { default: viewer },
        '@/components/guides/guideContent.css?inline': { default: readFileSync(new URL('../components/guides/guideContent.css', import.meta.url), 'utf8') },
        '@/core/guides/guideRuntime': await loadTs('core/guides/guideRuntime.ts', {}),
    });
    const api = { json: async (url: string) => ({ data: url.endsWith('/guides') ? books : { version: 'd1-1234abcd', title: 'Help', html, css: '.mg {color:black}', tasks: [] }, ask_available: available, ask_min_chars: 3, ask_max_chars: 500 }),
        picture: async () => new Blob(), ask: (url: string, question: string, signal: AbortSignal) => { calls.push({ url, question, signal }); return error ? Promise.reject(new Error(error)) : pending.promise; } };
    const s = await mountSfc('views/guides/GuideScreen.vue', { realm: 'admin' }, {
        'vue-router': { useRoute: () => route, useRouter: () => ({ push: (path: string) => { route.fullPath = path; route.params.book = path.split('/')[3]; route.params.task = path.split('/')[4] || ''; } }) },
        '@/stores/authStore': { useAuthStore: () => auth }, '@/core/services/GuideApiService': { default: api },
        '@/components/guides/GuideAsk.vue': { default: ask }, '@/components/guides/GuideContent.vue': { default: content },
        '@/core/guides/guidePaths': await loadTs('core/guides/guidePaths.ts', {}),
    });
    await flush(); return { s, pending, calls };
}
function submit(s: any) { const form = s.all((n: any) => n.tag === 'form')[0]; form.props.onSubmit({ preventDefault() {} }); }
test('Ask box is offered only when listing says available, in chrome outside shadow content', async () => {
    let { s } = await screen(false); assert.doesNotMatch(s.text(), /Ask the guide/); s.unmount();
    ({ s } = await screen()); assert.match(s.text(), /Ask the guide/);
    const field = s.all((n: any) => n.tag === 'textarea')[0]; assert.ok(field); assert.equal(field.props.maxlength, 500);
    assert.ok(s.all((n: any) => n.tag === 'label' && n.props.for === field.props.id).length);
    assert.equal(s.all((n: any) => n.props['data-book'] === 'admin')[0].contains(field), false); s.unmount();
});
test('Ask wait plain-text answer and permitted task link navigate and focus; repeat allowed', async () => {
    const { s, pending, calls } = await screen(); const field = s.all((n: any) => n.tag === 'textarea')[0];
    type(field, 'How do I sprout?'); submit(s); await flush();
    assert.equal(calls.length, 1); assert.equal(calls[0].url, '/api/admin/masjids/1/guides/ask'); assert.equal(calls[0].question, 'How do I sprout?');
    assert.equal(field.props.disabled, true); assert.equal(s.button('Ask').props.disabled, true);
    submit(s); assert.equal(calls.length, 1);
    assert.ok(s.all((n: any) => n.props.role === 'status' && n.textContent.includes('Asking the guide…')).length);
    pending.resolve({ answer: '1. <b>Open</b>\n2. Sprout task.', unknown: false, tasks: [{ book: 'school', id: 'sprout', title: 'Sprout task' }] }); await flush();
    assert.equal(s.all((n: any) => n.props.class === 'guide-answer')[0].children[0].text, '1. <b>Open</b>\n2. Sprout task.'); assert.equal(s.all((n: any) => n.tag === 'b').length, 0);
    assert.match(s.text(), /From the guide:/); assert.equal(s.button('Ask').disabled, false);
    const link = s.all((n: any) => n.tag === 'a' && n.props.href === '/masjid/help/school/sprout' && n.textContent === 'Sprout task')[0]; click(link); await flush();
    assert.equal(route.fullPath, '/masjid/help/school/sprout'); assert.equal(document.activeElement?.getAttribute('data-task'), 'sprout'); s.unmount();
});
test('Fallback appears verbatim without links and can be asked again', async () => {
    const { s, pending, calls } = await screen(); type(s.all((n: any) => n.tag === 'textarea')[0], 'Why?'); submit(s);
    const answer = "I don't know that one. Please reach out to your Manara support contact and ask.";
    pending.resolve({ answer, unknown: true, tasks: [] }); await flush(); assert.match(s.text(), new RegExp(answer.replace(/[.?]/g, '\\$&')));
    assert.doesNotMatch(s.text(), /From the guide:/); submit(s); await flush(); assert.equal(calls.length, 2); s.unmount();
});
for (const message of ['Too many questions just now. Try again in a minute.', "The guide's question box is resting for today. Try again tomorrow, or reach out to your Manara support contact.", 'That did not work. Try again, or reach out to your Manara support contact.']) {
    test('Screen shows refusal verbatim: ' + message, async () => {
        const { s } = await screen(true, message); type(s.all((n: any) => n.tag === 'textarea')[0], 'How?'); submit(s); await flush();
        assert.ok(s.text().includes(message)); assert.equal(s.button('Ask').disabled, false); s.unmount();
    });
}
test('Account change aborts the ask and discards its late answer and question', async () => {
    const { s, pending, calls } = await screen(); type(s.all((n: any) => n.tag === 'textarea')[0], 'Private question'); submit(s);
    auth.dashboardMasjidId = 2; await flush(); assert.equal(calls[0].signal.aborted, true);
    pending.resolve({ answer: 'Private answer', unknown: false, tasks: [] }); await flush();
    assert.doesNotMatch(s.text(), /Private answer/); assert.equal(s.all((n: any) => n.tag === 'textarea')[0].value, ''); s.unmount();
});
test('Ask transport sends JSON with bearer, no browser persistence, and preserves server errors', async () => {
    const writes: any[] = []; const calls: any[] = [];
    const oldFetch = globalThis.fetch; const oldStorage = globalThis.localStorage;
    globalThis.localStorage = { getItem: () => 'fixture-token', setItem: (...args: any[]) => writes.push(args) } as any;
    globalThis.fetch = (async (...args: any[]) => { calls.push(args); return { ok: true, json: async () => ({ answer: 'Hi', unknown: false, tasks: [] }) }; }) as any;
    try {
        const { default: api } = await loadTs('core/services/GuideApiService.ts', { '@/core/constants/appConfigConstants': { API_CONFIG: { base_url: 'https://example.invalid' }, LOCAL_STORAGE_KEYS: { token: 'token' } } });
        await api.ask('/api/admin/masjids/1/guides/ask', 'How?');
        assert.equal(calls[0][1].method, 'POST'); assert.equal(calls[0][1].headers['Content-Type'], 'application/json'); assert.equal(calls[0][1].headers.Authorization, 'Bearer fixture-token');
        assert.deepEqual(JSON.parse(calls[0][1].body), { question: 'How?' }); assert.deepEqual(writes, []);
        globalThis.fetch = (async () => ({ ok: false, json: async () => ({ message: 'Too many questions just now. Try again in a minute.' }) })) as any;
        await assert.rejects(api.ask('/ask', 'How?'), /Too many questions just now/);
    } finally { globalThis.fetch = oldFetch; globalThis.localStorage = oldStorage; }
});
