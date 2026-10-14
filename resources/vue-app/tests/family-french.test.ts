import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as vue from 'vue';
import { flush, loadTs, mountSfc } from './support/mountSfc.ts';

const stored = new Map<string, string>();
(globalThis as any).localStorage = {
    getItem: (key: string) => stored.get(key) ?? null,
    setItem: (key: string, value: string) => stored.set(key, value),
};
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { languages: ['fr-CA', 'en'] } });

async function language() {
    const modules: Record<string, any> = { vue };
    // Read the registry's imports so adding French makes every runtime test load its real table.
    const { readFileSync } = await import('node:fs');
    const source = readFileSync(new URL('../views/family/familyI18n.ts', import.meta.url), 'utf8');
    for (const [, name] of source.matchAll(/from "@\/views\/family\/locales\/([^"]+)"/g)) {
        modules[`@/views/family/locales/${name}`] = await loadTs(`views/family/locales/${name}.ts`, {});
    }
    return loadTs('views/family/familyI18n.ts', modules);
}

test('French preference is accepted, stored canonically, restored, and offered for translation', async () => {
    stored.clear();
    const module = await language();
    const lang = module.useFamilyLang();
    assert.equal(lang.translationTarget.value, 'fr', 'an English portal on a French browser offers French');
    lang.setLang('fr-CA');
    assert.equal(lang.lang.value, 'fr');
    assert.equal(stored.get('MANARA_FAMILY_LANG'), 'fr');
    assert.equal(lang.dir.value, 'ltr');
    assert.equal(lang.isRtl.value, false);
    assert.equal(lang.locale.value, 'fr');
    assert.equal(lang.translationTarget.value, 'fr');
    assert.equal(lang.t('home_greeting_named', 'Practice parent'), 'Assalamu alaikum, Practice parent');
    assert.equal(lang.tCount('msgs', 2), '2 messages');
    assert.equal(new Intl.DateTimeFormat(lang.locale.value, { month: 'long', timeZone: 'UTC' }).format(new Date('2026-10-13')), 'octobre');
    assert.equal(new Intl.NumberFormat(lang.locale.value).format(12.5), '12,5');
    lang.setLang('de');
    assert.equal(lang.lang.value, 'fr', 'unsupported preferences stay refused');
    const restored = (await language()).useFamilyLang();
    assert.equal(restored.lang.value, 'fr');
});

test('the real picker renders seven choices including Français and saves the selected French code', async () => {
    stored.clear();
    const module = await language();
    const screen = await mountSfc('views/family/FamilyLangPicker.vue', {}, { '@/views/family/familyI18n': module });
    try {
        const options = screen.all((node: any) => node.tag === 'option');
        assert.deepEqual(options.map((node: any) => node.props.value), ['en', 'ar', 'ur', 'ps', 'fa-AF', 'es', 'fr']);
        const french = options.find((node: any) => node.props.value === 'fr')!;
        assert.equal(french.props.lang, 'fr');
        assert.equal(french.props.dir, 'ltr');
        assert.match(screen.text(), /Français/);
        const select = screen.all((node: any) => node.tag === 'select')[0];
        select.props.onChange({ target: { value: 'fr' } });
        await flush();
        assert.equal(select.props.value, 'fr');
        assert.equal(stored.get('MANARA_FAMILY_LANG'), 'fr');
    } finally { screen.unmount(); }
});

test('a French reader requests posts, shared notes and messages in French and a switch drops old translations', async () => {
    const module = await language();
    const lang = module.useFamilyLang();
    lang.setLang('es');
    const sent: any[] = [];
    const api = { post: async (_url: string, body: any) => {
        sent.push(body);
        return { data: { data: { translations: Object.fromEntries(body.items.map((item: any) => [item.key, `${body.target}:${item.text}`])) } } };
    } };
    const { useContentTranslation } = await loadTs('views/family/useContentTranslation.ts', { vue, '@/views/family/familyI18n': module, '@/core/services/FamilyApiService': { default: api } });
    const scope = vue.effectScope();
    const tx = scope.run(() => useContentTranslation(vue.ref(1)))!;
    const items = ['post', 'note', 'message'].map(key => ({ key, text: `Practice ${key}` }));
    try {
        await tx.translate(items);
        assert.equal(tx.tx('post', 'Practice post'), 'es:Practice post');
        lang.setLang('fr');
        await vue.nextTick();
        assert.equal(tx.tx('post', 'Practice post'), 'Practice post');
        assert.equal(sent.length, 1, 'switching alone buys no translation');
        await tx.translate(items);
        assert.equal(sent[1].target, 'fr');
        assert.deepEqual(sent[1].items, items);
        assert.equal(tx.showingLang.value, 'fr');
        await tx.translate(items);
        assert.equal(sent.length, 2, 're-reading uses the client cache');
    } finally { scope.stop(); }
});

test('the family layout applies French html lang/dir and restores them on leaving the realm', async () => {
    const module = await language();
    const lang = module.useFamilyLang();
    lang.setLang('fr');
    const attributes = new Map<string, string>([['lang', 'en']]);
    const doc = (globalThis as any).document;
    const oldHtml = doc.documentElement;
    doc.documentElement = {
        getAttribute: (key: string) => attributes.get(key) ?? null,
        setAttribute: (key: string, value: string) => attributes.set(key, value),
        removeAttribute: (key: string) => attributes.delete(key),
    };
    doc.addEventListener = () => {};
    doc.removeEventListener = () => {};
    (globalThis as any).window = { addEventListener() {}, removeEventListener() {} };
    const modules = {
        '@/views/family/familyI18n': module,
        '@/stores/familyStore': { useFamilyStore: () => ({ slots: {}, isSignedInTo: () => false, signedInMasjidIds: [], displayNameFor: () => '' }) },
        '@/core/services/FamilyApiService': { default: { get: async () => ({ data: { data: { name: 'Practice school' } } }) } },
        '@/views/family/urduFont.css': {},
        '@/core/helpers/familySessions': { FAMILY_SESSIONS_KEY: 'MANARA_FAMILY_SESSIONS', familyRouteRedirect: () => true },
        '@/views/family/calendarFlag': { loadCalendarFlagFor: async () => {} },
        '@/core/pageTitle': { setOrgTitle() {} },
        'vue-router': { useRoute: () => ({ params: { masjidId: '1' }, meta: {} }), useRouter: () => ({ replace() {} }) },
    };
    const screen = await mountSfc('layouts/FamilyLayout.vue', {}, modules);
    try {
        await flush();
        assert.equal(attributes.get('lang'), 'fr');
        assert.equal(attributes.get('dir'), 'ltr');
        lang.setLang('ar');
        await flush();
        assert.equal(attributes.get('lang'), 'ar');
        assert.equal(attributes.get('dir'), 'rtl');
        lang.setLang('fr');
        await flush();
        assert.equal(attributes.get('dir'), 'ltr');
    } finally {
        screen.unmount();
        assert.equal(attributes.get('lang'), 'en');
        assert.equal(attributes.has('dir'), false);
        doc.documentElement = oldHtml;
    }
});

test('the family bar lets a long school name give way so the page is never wider than the phone', () => {
    const layout = readFileSync(new URL('../layouts/FamilyLayout.vue', import.meta.url), 'utf8');
    const style = layout.slice(layout.lastIndexOf('<style'));
    assert.match(style, /\.navbar > \.container-fluid \{[^}]*flex-wrap: nowrap;[^}]*min-width: 0;/);
    assert.match(style, /\.navbar-brand \{ min-width: 0; flex: 1 1 auto; \}/);
    assert.match(style, /\.family-school-name \{[^}]*overflow: hidden;[^}]*text-overflow: ellipsis;[^}]*white-space: nowrap;/);
    assert.match(style, /\.push-end \{ flex: 0 0 auto; \}/);
    assert.match(layout, /class="[^"]*family-school-name[^"]*"[^>]*:title="orgName \|\| undefined"/);
});
