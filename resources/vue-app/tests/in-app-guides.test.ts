import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { Node, deferred } from './support/mountSfc.ts';
import { delegatedClick } from './support/guideDom.ts';
import { compileSfc, mountSfc, flush, click, type, loadTs, withDocumentKeys, pressKey } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');
const auth = vue.reactive({ user: { type: 'MasjidAdmin', name: 'Pebble', masjid: { name: 'Pebble' } }, dashboardMasjidId: 1, isAuthenticated: true });
const route = vue.reactive({ params: { book: 'admin', task: '' }, fullPath: '/masjid/help/admin', query: {} as Record<string, string>, meta: {} });
const router = { push: (path: string) => { const url = new URL(path, 'https://example.invalid'); route.fullPath = path; route.params.book = decodeURIComponent(url.pathname.split('/')[3]); route.params.task = decodeURIComponent(url.pathname.split('/')[4] || ''); route.query = Object.fromEntries(url.searchParams); } };
const books = [{ book: 'admin', title: 'Admin guide', version: 'd1-1234abcd' }, { book: 'school', title: 'School guide', version: 'd1-1234abcd' }];
const html = readFileSync(new URL('../../../tests/fixtures/guides/d1-1234abcd/admin/page.html', import.meta.url), 'utf8');
const created: string[] = []; const revoked: string[] = [];
URL.createObjectURL = () => { const url = 'blob:pebble-' + created.length; created.push(url); return url; };
URL.revokeObjectURL = url => { revoked.push(url); };
withDocumentKeys();

const data = { version: 'd1-1234abcd', title: 'Admin guide', html, css: '.mg {color:black}', tasks: [{ id: 'sprout', title: 'Sprout task', section: 'Misleading manifest section' }, { id: 'ripple', title: 'Ripple task', section: 'Misleading manifest section' }] };

async function screen(off = false, unavailable = false, missingPicture = false, pageHtml?: string) {
    const viewer = await compileSfc('components/guides/GuideViewer.vue', {});
    const content = await compileSfc('components/guides/GuideContent.vue', {
        '@/components/guides/GuideViewer.vue': { default: viewer },
        '@/core/guides/guideRuntime': await loadTs('core/guides/guideRuntime.ts', {}),
    });
    route.params.book = 'admin'; route.params.task = ''; route.query = {}; route.fullPath = '/masjid/help/admin';
    const calls: string[] = [];
    const mounted = await mountSfc('views/guides/GuideScreen.vue', { realm: 'admin' }, {
        'vue-router': { useRoute: () => route, useRouter: () => router },
        '@/stores/authStore': { useAuthStore: () => auth },
        '@/core/services/GuideApiService': { default: { json: async (url: string) => { calls.push(url); return { data: url.endsWith('/guides') ? unavailable ? [] : off ? books.slice(0, 1) : books : { ...data, title: route.params.book + ' guide', html: pageHtml ?? readFileSync(new URL(`../../../tests/fixtures/guides/d1-1234abcd/${route.params.book}/page.html`, import.meta.url), 'utf8') } }; }, picture: async () => { if (missingPicture) throw new Error('missing'); return new Blob(); } } },
        '@/components/guides/GuideContent.vue': { default: content },
        '@/core/guides/guidePaths': await loadTs('core/guides/guidePaths.ts', {}),
    });
    await flush();
    return { mounted, calls };
}

test('mounted guide screen and real content/viewer children: school switch only when server offers it', async () => {
    let s = await screen();
    assert.match(s.mounted.text(), /School guide/); assert.match(s.mounted.text(), /Pebble chapter/);
    s.mounted.unmount();
    s = await screen(true); assert.equal(s.mounted.all(n => n.props['aria-label'] === 'Choose guide').length, 0); s.mounted.unmount();
});

test('mounted search uses task title and body, with a clear empty result', async () => {
    const { mounted } = await screen();
    const field = mounted.all(n => n.tag === 'input')[0];
    type(field, 'acorn'); await flush(); assert.match(mounted.text(), /Sprout task/); assert.doesNotMatch(mounted.all(n => n.props['aria-label'] === 'Guide contents')[0].textContent, /Ripple task/);
    assert.equal((mounted.all(n => n.tag === 'section' && n.props['data-task'] === 'ripple')[0] as any).hidden, true);
    type(field, 'nothing matches'); await flush(); assert.match(mounted.text(), /No results/);
    type(field, 'Ripple'); await flush(); assert.match(mounted.text(), /Ripple task/);
    mounted.unmount();
});

test('mounted screen: no installed release says unavailable', async () => {
    const { mounted } = await screen(false, true); assert.match(mounted.text(), /not available yet/); mounted.unmount();
});

test('next book or task navigation fetches a whole release again', async () => {
    const { mounted, calls } = await screen();
    router.push('/masjid/help/admin/ripple'); await flush(); assert.equal(calls.filter(p => p.endsWith('/admin')).length, 2); mounted.unmount();
});

test('mounted named viewer closes with its button and Escape, and returns focus', async () => {
    withDocumentKeys();
    let focused = 0; let closed = 0;
    const viewer = { src: 'blob:pebble', alt: 'Pebble picture', opener: { focus: () => focused++ } };
    const s = await mountSfc('components/guides/GuideViewer.vue', { picture: viewer, onClose: () => closed++ }, {});
    await flush(); assert.match(s.text(), /Close picture/); pressKey('Escape'); assert.equal(closed, 1);
    click(s.button('Close picture')); assert.equal(closed, 2); s.unmount(); assert.ok(focused > 0);
});

test('mounted real content: allowed cross-guide links navigate, refused links are plain text', async () => {
    const { mounted } = await screen();
    const allowed = mounted.all(n => n.tag === 'a' && n.textContent === 'School sprout')[0];
    assert.equal(allowed.props.href, '/masjid/help/school/sprout');
    assert.equal(mounted.all(n => n.tag === 'a' && n.textContent === 'Lunch sprout').length, 0);
    assert.ok(mounted.all(n => n.tag === 'span' && n.textContent === 'Lunch sprout').length);
    delegatedClick(allowed); await flush(); assert.equal(route.fullPath, '/masjid/help/school/sprout');
    mounted.unmount();
});

test('mounted real content: a refused picture leaves its task readable in a box of the same dimensions', async () => {
    const { mounted } = await screen(false, false, true);
    assert.match(mounted.text(), /Picture unavailable/); assert.match(mounted.text(), /Velvet acorn/);
    const box: any = mounted.all(n => n.tag === 'button' && n.props['data-picture'] === '')[0];
    assert.equal(box.style.width, '1px'); assert.equal(box.style.aspectRatio, '1 / 1');
    mounted.unmount();
});

test('mounted real content and viewer: picture opens, Escape and close return focus, URLs revoked on leave', async () => {
    const before = created.length;
    const { mounted } = await screen();
    const button = mounted.all(n => n.tag === 'button' && n.props['data-picture'] === '')[0];
    delegatedClick(button); await flush(); assert.ok(mounted.all(n => n.props.role === 'dialog').length);
    pressKey('Escape'); await flush(); assert.equal(mounted.all(n => n.props.role === 'dialog').length, 0); assert.equal(document.activeElement, button);
    delegatedClick(button); await flush(); click(mounted.button('Close picture')); await flush(); assert.equal(document.activeElement, button);
    mounted.unmount(); assert.ok(created.slice(before).every(url => revoked.includes(url)));
});

test('real lazy loader caps concurrent requests and aborts/revokes even when a picture finishes after disposal', async () => {
    const { attachGuide } = await loadTs('core/guides/guideRuntime.ts', {});
    const el: any = new Node('el', 'div');
    el.innerHTML = '<div class="mg">' + Array.from({ length: 9 }, (_, i) => `<img data-src="shots/pebble/${i}.jpg" width="10" height="20" alt="Pebble">`).join('') + '</div>';
    const pending: any[] = []; let observed = 0; let callback: any;
    (globalThis as any).IntersectionObserver = class { constructor(cb: any) { callback = cb; } observe() { observed++; } unobserve() {} disconnect() {} };
    const signals: AbortSignal[] = [];
    const runtime = attachGuide(el, { tasks: [], allowed: [], path: () => '', navigate() {}, contents() {}, view() {}, picture: (_path: string, signal: AbortSignal) => { signals.push(signal); const d = deferred(); pending.push(d); return d.promise; } });
    assert.equal(observed, 9); assert.equal(pending.length, 0);
    callback(el.querySelectorAll('img').map((target: any) => ({ target, isIntersecting: true })));
    assert.equal(pending.length, 4); pending[0].resolve(new Blob()); await flush(); assert.equal(pending.length, 5);
    const before = created.length; runtime.dispose(); assert.ok(signals.every(signal => signal.aborted));
    for (const d of pending) d.resolve(new Blob()); await flush(); assert.equal(created.length, before);
    delete (globalThis as any).IntersectionObserver;
});

test('guide paths stay in this shell and encode tasks', async () => {
    const { guidePath } = await loadTs('core/guides/guidePaths.ts', {});
    assert.equal(guidePath('admin', 'school', 'sprout'), '/masjid/help/school/sprout');
    assert.equal(guidePath('teacher', 'teacher'), '/teacher/help/teacher');
    assert.equal(guidePath('lunch', 'lunch'), '/lunch/help/lunch');
});

test('Help is the final organisation menu entry and a mounted topbar entry in both scoped shells', async () => {
    const { MASJID_DASHBOARD_ASIDE_MENU } = await loadTs('core/constants/dashboardAsideMenuItems.ts', { '@/core/types/config/AsideMenuItem': {} });
    assert.equal(MASJID_DASHBOARD_ASIDE_MENU.at(-1).title, 'Help');
    assert.equal(MASJID_DASHBOARD_ASIDE_MENU.at(-1).to, '/masjid/help/admin');
    for (const shell of ['Teacher', 'Lunch']) {
        const chrome = { useStaffChrome() {} };
        const modules: any = {
            '@/core/services/ApiService': { default: {} }, '@/stores/authStore': { useAuthStore: () => auth },
            '@/core/pageTitle': { setOrgTitle() {} }, 'vue-router': { useRoute: () => route, useRouter: () => router },
            '@/core/helpers/staffChrome': chrome,
            '@/core/services/TeacherApiService': { default: { get: async () => ({ data: { data: { name: 'Pebble', id: 1 } } }) } },
            '@/components/teacher/TeacherSchoolPicker.vue': { default: { render: () => null } },
            '@/components/dashboard/TenantMismatchNotice.vue': { default: { render: () => null } },
            '@/core/helpers/teacherSchools': { landingSchoolId: () => 1, schoolChoices: () => [], switchTarget: () => 1 },
            '@/core/tenancy/teacherSchoolGuard': { clearTeacherSchoolNotices() {}, provideSelectedSchool: () => () => {}, teacherSchoolMismatch: vue.ref(null), teacherSchoolRefused: vue.ref(false) },
            '@/core/tenancy/tenantRequests': { bumpTenantEpoch() {}, forgetServerTenant() {} },
            '@/stores/plugins/tenantStoreReset': { resetTenantScopedStores() {} },
        };
        const s = await mountSfc(`layouts/${shell}Layout.vue`, {}, modules); await flush();
        assert.match(s.text(), /Help/); s.unmount();
    }
});

test('guide transport reads the current bearer on every request and sends no cookies or public picture URLs', async () => {
    const calls: any[] = [];
    let token = 'fixture-one';
    const previousFetch = globalThis.fetch;
    const previousStorage = (globalThis as any).localStorage;
    (globalThis as any).localStorage = { getItem: () => token };
    globalThis.fetch = async (url: any, options: any) => { calls.push({ url, options }); return new Response('{}', { headers: { 'Content-Type': 'application/json' } }); };
    try {
        const { default: api } = await loadTs('core/services/GuideApiService.ts', { '@/core/constants/appConfigConstants': { API_CONFIG: { base_url: '' }, LOCAL_STORAGE_KEYS: { token: 'fixture' } } });
        await api.json('/api/admin/masjids/1/guides'); token = 'fixture-two'; await api.picture('/api/admin/masjids/1/guides/admin/d1-1234abcd/pictures/shots/pebble/pixel.jpg');
        assert.equal(calls[0].options.headers.Authorization, 'Bearer fixture-one'); assert.equal(calls[1].options.headers.Authorization, 'Bearer fixture-two');
        assert.ok(calls.every(call => call.options.credentials === 'omit' && call.options.cache === 'no-cache'));
    } finally { globalThis.fetch = previousFetch; (globalThis as any).localStorage = previousStorage; }
});

test('mounted real content: chapter/task/question deep links focus, and theme stays inside the guide root', async () => {
    const { mounted } = await screen();
    click(mounted.button('Dark guide')); await flush();
    const root: any = mounted.all(n => String(n.props.class).split(/\s+/).includes('mg'))[0];
    assert.equal(root.dataset.theme, 'dark');
    router.push('/masjid/help/admin/ripple'); await flush();
    let section: any = mounted.all(n => n.tag === 'section' && n.props['data-task'] === 'ripple')[0];
    assert.equal(document.activeElement, section); assert.equal(section.scrolled, true);
    const chapter = mounted.all(n => n.tag === 'a' && n.textContent === 'Pebble chapter')[0];
    click(chapter); await flush();
    section = mounted.all(n => n.tag === 'section' && n.props['data-chapter'] === 'pebble')[0];
    assert.equal(document.activeElement, section);
    const question = mounted.all(n => n.tag === 'a' && n.textContent === 'Why a pebble?')[0];
    click(question); await flush();
    const details = mounted.all(n => n.tag === 'details')[0];
    assert.equal(details.props.open, ''); assert.equal(document.activeElement, details);
    mounted.unmount();
});

test('mounted real content: a corrupt picture is quiet and its blob is revoked', async () => {
    const before = created.length;
    const { mounted } = await screen();
    const img: any = mounted.all(n => n.tag === 'img')[0];
    img.onerror(); await flush(); assert.match(mounted.text(), /Picture unavailable/); assert.match(mounted.text(), /Velvet acorn/);
    assert.ok(revoked.includes(created[before])); mounted.unmount();
});


test('mounted search includes task and question data-words, and clear restores both', async () => {
    const { mounted } = await screen();
    const field = mounted.all(n => n.tag === 'input')[0];
    type(field, 'starleaf'); await flush();
    const contents = mounted.all(n => n.props['aria-label'] === 'Guide contents')[0];
    assert.match(contents.textContent, /Sprout task/); assert.doesNotMatch(contents.textContent, /Ripple task|No results/);
    type(field, 'moonstone'); await flush();
    assert.match(contents.textContent, /Why a pebble/); assert.doesNotMatch(contents.textContent, /No results/);
    const faq: any = mounted.all(n => n.tag === 'details')[0]; assert.equal(faq.hidden, false);
    type(field, 'nothing matches'); await flush(); assert.equal(faq.hidden, true); assert.match(contents.textContent, /No results/);
    click(mounted.button('Clear search')); await flush(); assert.equal(faq.hidden, false); assert.match(contents.textContent, /Sprout task.*Ripple task/);
    mounted.unmount();
});

test('mounted contents nest tasks under their actual page chapter headings', async () => {
    const { mounted } = await screen();
    const contents = mounted.all(n => n.props['aria-label'] === 'Guide contents')[0];
    const chapters = contents.children.flatMap(n => n.tag === 'ol' ? n.children.filter(c => c.tag === 'li') : []);
    assert.equal(chapters.length, 2);
    assert.match(chapters[0].textContent, /Pebble chapter.*Sprout task/); assert.doesNotMatch(chapters[0].textContent, /Ripple/);
    assert.match(chapters[1].textContent, /Fern chapter.*Ripple task/); assert.doesNotMatch(contents.textContent, /Misleading manifest/);
    mounted.unmount();
});

test('whole-guide link opens its named book top, question link opens its named book FAQ', async () => {
    const { mounted } = await screen();
    let link = mounted.all(n => n.tag === 'a' && n.textContent === 'Whole school')[0];
    assert.equal(link.props.href, '/masjid/help/school'); delegatedClick(link); await flush();
    assert.equal(route.fullPath, '/masjid/help/school');
    let root: any = mounted.all(n => n.props['data-book'] === 'school')[0];
    assert.equal(root.scrolled, true); assert.equal(document.activeElement, root);
    router.push('/masjid/help/admin'); await flush();
    link = mounted.all(n => n.tag === 'a' && n.textContent === 'School question')[0];
    assert.equal(link.props.href, '/masjid/help/school?faq=faq-pebble'); delegatedClick(link); await flush();
    const faq: any = mounted.all(n => n.tag === 'details' && n.props.id === 'faq-pebble')[0];
    assert.equal(faq.props.open, ''); assert.equal(faq.scrolled, true); assert.equal(document.activeElement, faq);
    root = mounted.all(n => n.props['data-book'] === 'school')[0]; assert.ok(root.contains(faq));
    mounted.unmount();
});

test('disallowed cross-book links become words without dropping the following guide qualification', async () => {
    const { mounted } = await screen(true);
    assert.equal(mounted.all(n => n.tag === 'a' && n.textContent === 'School sprout').length, 0);
    assert.ok(mounted.all(n => n.tag === 'span' && n.textContent === 'School sprout').length);
    assert.match(mounted.text(), /School sprout \(in the School guide\)/);
    assert.equal(mounted.all(n => n.tag === 'a' && ['Whole school', 'School question'].includes(n.textContent)).length, 0);
    mounted.unmount();
});

test('shared task ids stay in the data-guide book and figures expose enlargement', async () => {
    const { mounted } = await screen();
    const button = mounted.all(n => n.tag === 'button' && n.props['data-picture'] === '')[0];
    assert.match(button.textContent, /Enlarge picture/); assert.match(button.props['aria-label'], /Enlarge picture/);
    const link = mounted.all(n => n.tag === 'a' && n.textContent === 'School sprout')[0];
    delegatedClick(link); await flush();
    const target = mounted.all(n => n.tag === 'section' && n.props['data-task'] === 'sprout')[0];
    assert.equal(route.fullPath, '/masjid/help/school/sprout'); assert.equal(document.activeElement, target);
    assert.ok(mounted.all(n => n.props['data-book'] === 'school')[0].contains(target));
    mounted.unmount();
});

test('mounted viewer zooms a wide picture, fits it again, and traps focus across named controls', async () => {
    const s = await mountSfc('components/guides/GuideViewer.vue', { picture: { src: 'blob:wide', alt: 'Wide pebble', opener: { focus() {} } } }, {});
    await flush();
    click(s.button('Zoom picture')); await flush();
    assert.ok(s.all(n => n.props['aria-pressed'] === 'true').length);
    assert.ok(s.all(n => n.tag === 'div' && String(n.props.class).includes('is-zoomed')).length);
    click(s.button('Fit picture')); await flush(); assert.ok(s.all(n => n.props['aria-pressed'] === 'false').length);
    s.button('Close picture').focus(); pressKey('Tab'); assert.equal(document.activeElement, s.button('Zoom picture'));
    pressKey('Tab'); assert.equal(document.activeElement, s.all(n => n.props.role === 'region')[0]);
    pressKey('Tab'); assert.equal(document.activeElement, s.button('Close picture')); s.unmount();
});


test('whole-guide navigation to the current address refetches and returns to its top', async () => {
    const { mounted, calls } = await screen();
    let link = mounted.all(n => n.tag === 'a' && n.textContent === 'Whole school')[0];
    delegatedClick(link); await flush();
    const root: any = mounted.all(n => n.props['data-book'] === 'school')[0]; root.scrolled = false;
    link = mounted.all(n => n.tag === 'a' && n.textContent === 'Whole school')[0];
    delegatedClick(link); await flush();
    assert.equal(calls.filter(url => url.endsWith('/school')).length, 2);
    assert.equal((mounted.all(n => n.props['data-book'] === 'school')[0] as any).scrolled, true);
    mounted.unmount();
});

test('real release when requested mounts all four passive pages with real children', { skip: !process.env.GUIDE_REAL_RELEASE }, async () => {
    const source = process.env.GUIDE_REAL_RELEASE!;
    const manifest = JSON.parse(readFileSync(`${source}/manifest.json`, 'utf8'));
    const viewer = await compileSfc('components/guides/GuideViewer.vue', {});
    const runtime = await loadTs('core/guides/guideRuntime.ts', {});
    const { guidePath } = await loadTs('core/guides/guidePaths.ts', {});
    for (const [book, meta] of Object.entries<any>(manifest.books)) {
        const realm = book === 'school' ? 'admin' : book;
        const pageHtml = readFileSync(`${source}/${meta.page}`, 'utf8');
        let items: any[] = [];
        const s = await mountSfc('components/guides/GuideContent.vue', {
            page: { ...meta, html: pageHtml, css: readFileSync(`${source}/${meta.style}`, 'utf8'), version: manifest.version },
            allowed: [{ book, title: meta.title, version: manifest.version }], realm, query: '', theme: 'light',
            path: (target: string, task?: string, faq?: string) => guidePath(realm, target, task, faq),
            fetchPicture: async () => { throw new Error('Synthetic unavailable picture response'); }, navigate() {},
            onContents: (value: any[]) => { items = value; },
        }, { '@/components/guides/GuideViewer.vue': { default: viewer }, '@/core/guides/guideRuntime': runtime });
        try {
            await flush();
            assert.equal(items.filter(item => item.kind === 'task').length, meta.tasks.length);
            assert.ok(items.filter(item => item.kind === 'task').every(item => item.chapter));
            const sections: any[] = s.all(n => n.tag === 'section' && n.props['data-task']);
            assert.ok(sections.every(section => items.find(item => item.kind === 'task' && item.id === section.dataset.task)?.text.includes(section.dataset.words)));
            const root: any = s.all(n => n.props['data-book'] === book)[0];
            assert.ok(root && root.scrolled);
            const pictures = s.all(n => n.tag === 'button' && n.props['data-picture'] === '');
            assert.equal(pictures.length, (pageHtml.match(/<img\b/g) || []).length);
            assert.ok(pictures.every(button => button.textContent === 'Picture unavailable'));
            const links: any[] = s.all(n => n.tag === 'a' && n.props['data-guide-link']);
            assert.ok(links.every(link => new URL(link.props.href, 'https://example.invalid').pathname.split('/')[3] === book));
        } finally { s.unmount(); }
    }
});


for (const query of ['moonlit-answer', 'Sprout task']) {
    test(`reviewed nested question search preserves visible ancestors and whole matching task: ${query}`, async () => {
        const nested = '<details data-faq id="faq-nested" data-words="moonlit-answer"><summary>Nested question</summary><p>Amber answer.</p></details>';
        const page = html.replace('<p>Velvet acorn.</p>', '<p>Velvet acorn.</p>' + nested);
        const { mounted } = await screen(false, false, false, page);
        try {
            const question: any = mounted.all(n => n.tag === 'details' && n.props.id === 'faq-nested')[0];
            question.setAttribute('open', '');
            type(mounted.all(n => n.tag === 'input')[0], query); await flush();
            assert.equal(question.hidden, false);
            assert.equal(question.props.open, ''); assert.match(question.textContent, /Amber answer/);
            const task: any = mounted.all(n => n.tag === 'section' && n.props['data-task'] === 'sprout')[0];
            const chapter: any = mounted.all(n => n.tag === 'section' && n.props['data-chapter'] === 'pebble')[0];
            assert.equal(task.hidden, false); assert.equal(chapter.hidden, false);
            assert.equal((mounted.all(n => n.tag === 'section' && n.props['data-task'] === 'ripple')[0] as any).hidden, true);
            click(mounted.button('Clear search')); await flush();
            assert.equal((mounted.all(n => n.tag === 'section' && n.props['data-task'] === 'ripple')[0] as any).hidden, false);
        } finally { mounted.unmount(); }
    });
}
