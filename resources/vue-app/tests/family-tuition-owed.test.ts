/**
 * The family tuition page said one total and nothing about it, so a 200 registration plus a 500
 * book fee read as tuition after a discount; and a parent's browser machine-translated the page,
 * turning children's names into nonsense words. These pin the breakdown, the paid-ahead wording,
 * the translation fences, and the three new words in every language table.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { mountSfc, flush, loadTs } from './support/mountSfc.ts';
import { tuitionMoney, tuitionDecimal, tuitionMinor } from '../core/helpers/tuitionMoney.ts';
const require = createRequire(import.meta.url), vue = require('vue');
const amountHelpers = { tuitionMoney, tuitionDecimal, tuitionMinor };

const words: Record<string, string> = { tuition_due_by: 'due {x}', tuition_list_separator: ', ' };
// The test renderer joins sibling nodes with a space a browser would not draw; compare without spaces.
const flat = (text: string) => text.replace(/\s+/g, '');
const t = (key: string, x?: string) => { const s = words[key] ?? key; return x !== undefined ? s.replace('{x}', x) : s; };

async function tuitionPage(account: any) {
    const api = { get: async (url: string) => ({ data: { data: url.includes('?') ? { statement: { opening_minor: 0, closing_minor: account.balance_minor, lines: [] } } : { accounts: [account] } } }) };
    return mountSfc('views/family/FamilyTuition.vue', {}, { vue, 'vue-router': { useRoute: () => ({ params: { masjidId: '1' }, query: {} }) }, '@/core/services/FamilyApiService': { default: api }, './FamilyLangPicker.vue': { default: { render: () => null } }, './familyI18n': { useFamilyLang: () => ({ t, dir: vue.ref('ltr'), lang: vue.ref('en'), locale: vue.ref('en') }) }, '@/core/helpers/tuitionMoney': amountHelpers, '@/core/types/data/masjid-related/SchoolCalendar': { formatSchoolDay: (d: string) => d } });
}

const base = { id: 4, currency: 'usd', minimum_payment_minor: 0, next_due_on: null, default_from: '2026-08-24', school_today: '2026-10-15', card_available: true, children: [{ id: 7, name: 'PupilA Practice' }, { id: 8, name: 'PupilB Practice' }] };

test('the amount owed is listed by what it is made of, with a due date only for what is not due yet', async () => {
    const account = { ...base, balance_minor: 70000, owed_items: [
        { line_id: 1, kind: 'charge', description: 'Registration', child: 'PupilA', amount_minor: 20000, due_on: '2026-10-01' },
        { line_id: 2, kind: 'charge', description: 'Books', child: 'PupilB', amount_minor: 50000, due_on: '2026-10-20' },
    ] };
    const s = await tuitionPage(account);
    try {
        await flush();
        const list = s.all((n) => n.tag === 'li');
        assert.equal(list.length, 2);
        assert.match(s.text(), /tuition_balance: \$700\.00/);
        assert.match(s.text(), /tuition_owed_items:/);
        assert.equal(flat(list[0].textContent), 'Registration(PupilA):$200.00');
        assert.equal(flat(list[1].textContent), 'Books(PupilB):$500.00,due2026-10-20');
        assert.doesNotMatch(s.text(), /tuition_paid_ahead/);
    } finally { s.unmount(); }
});

test('an account in credit says paid ahead, never a negative amount, and lists nothing owed', async () => {
    const s = await tuitionPage({ ...base, balance_minor: -3000, owed_items: [] });
    try {
        await flush();
        const line = s.all((n) => n.tag === 'p' && n.textContent.includes('tuition_paid_ahead'));
        assert.equal(line.length, 1);
        assert.equal(line[0].textContent, 'tuition_paid_ahead: $30.00');
        assert.equal(s.all((n) => n.tag === 'p' && n.textContent.includes('tuition_balance')).length, 0);
        assert.doesNotMatch(s.text(), /tuition_owed_items/);
        assert.doesNotMatch(s.text(), /tuition_pay_full/);
    } finally { s.unmount(); }
});

test('a settled account shows $0.00 alone, and an older payload without a breakdown still draws', async () => {
    for (const account of [{ ...base, balance_minor: 0, owed_items: [] }, { ...base, balance_minor: 0 }, { ...base, balance_minor: 2500 }]) {
        const s = await tuitionPage(account);
        try {
            await flush();
            assert.match(s.text(), new RegExp(`tuition_balance: \\$${account.balance_minor === 0 ? '0\\.00' : '25\\.00'}`));
            assert.doesNotMatch(s.text(), /tuition_owed_items|tuition_paid_ahead|-\$/);
        } finally { s.unmount(); }
    }
});

test('children\'s names and money are fenced from browser machine translation', async () => {
    const s = await tuitionPage({ ...base, balance_minor: 70000, next_due_on: '2026-11-01', next_due_minor: 4000, owed_items: [{ line_id: 1, kind: 'charge', description: 'Books', child: 'PupilB', amount_minor: 70000, due_on: '2026-10-01' }] });
    try {
        await flush();
        const fenced = s.all((n) => n.props.translate === 'no' && String(n.props.class ?? '').split(/\s+/).includes('notranslate')).map((n) => n.textContent);
        for (const text of ['PupilA Practice', 'PupilB Practice', 'PupilB', '$700.00', '$40.00']) assert.ok(fenced.includes(text), `${text} is not fenced: ${JSON.stringify(fenced)}`);
        assert.equal(flat(s.all((n) => n.tag === 'h2')[0].textContent), 'PupilAPractice,PupilBPractice');
    } finally { s.unmount(); }
});

async function language() {
    const modules: Record<string, any> = { vue };
    const source = readFileSync(new URL('../views/family/familyI18n.ts', import.meta.url), 'utf8');
    for (const [, name] of source.matchAll(/from "@\/views\/family\/locales\/([^"]+)"/g)) {
        modules[`@/views/family/locales/${name}`] = await loadTs(`views/family/locales/${name}.ts`, {});
    }
    return loadTs('views/family/familyI18n.ts', modules);
}

for (const before of [null, 'yes']) {
    test(`the family layout keeps browsers from translating the page and restores translate=${before} on leaving`, async () => {
        const stored = new Map<string, string>();
        (globalThis as any).localStorage = { getItem: (k: string) => stored.get(k) ?? null, setItem: (k: string, v: string) => stored.set(k, v) };
        const module = await language();
        module.useFamilyLang().setLang('ar');
        const attributes = new Map<string, string>([['lang', 'en']]);
        if (before !== null) attributes.set('translate', before);
        const doc = (globalThis as any).document, oldHtml = doc.documentElement, oldWindow = (globalThis as any).window;
        doc.documentElement = { getAttribute: (k: string) => attributes.get(k) ?? null, setAttribute: (k: string, v: string) => attributes.set(k, v), removeAttribute: (k: string) => attributes.delete(k) };
        doc.addEventListener = () => {}; doc.removeEventListener = () => {};
        (globalThis as any).window = { addEventListener() {}, removeEventListener() {} };
        const screen = await mountSfc('layouts/FamilyLayout.vue', {}, {
            '@/views/family/familyI18n': module,
            '@/stores/familyStore': { useFamilyStore: () => ({ slots: {}, isSignedInTo: () => false, signedInMasjidIds: [], displayNameFor: () => '' }) },
            '@/core/services/FamilyApiService': { default: { get: async () => ({ data: { data: { name: 'Practice school' } } }) } },
            '@/views/family/urduFont.css': {},
            '@/core/helpers/familySessions': { FAMILY_SESSIONS_KEY: 'MANARA_FAMILY_SESSIONS', familyRouteRedirect: () => true },
            '@/views/family/calendarFlag': { loadCalendarFlagFor: async () => {} },
            '@/core/pageTitle': { setOrgTitle() {} },
            'vue-router': { useRoute: () => ({ params: { masjidId: '1' }, meta: {} }), useRouter: () => ({ replace() {} }) },
        });
        try {
            await flush();
            assert.equal(attributes.get('translate'), 'no');
            assert.equal(attributes.get('lang'), 'ar');
            module.useFamilyLang().setLang('en');
            await flush();
            assert.equal(attributes.get('translate'), 'no', 'English is one of the portal\'s own languages too');
            const school = screen.all((n) => String(n.props.class ?? '').includes('family-school-name'));
            assert.equal(school.length, 1);
            assert.equal(school[0].props.translate, 'no');
            assert.match(String(school[0].props.class), /\bnotranslate\b/);
        } finally {
            screen.unmount();
            assert.equal(attributes.get('translate') ?? null, before);
            assert.equal(attributes.get('lang'), 'en');
            doc.documentElement = oldHtml; (globalThis as any).window = oldWindow;
        }
    });
}

test('every language table carries the three new words, with the date slot kept', () => {
    const dir = new URL('../views/family/', import.meta.url);
    const i18n = readFileSync(new URL('familyI18n.ts', dir), 'utf8');
    const enStart = i18n.indexOf('\n    en: {'), arStart = i18n.indexOf('\n    ar: {');
    const tables: Record<string, string> = { en: i18n.slice(enStart, arStart), ar: i18n.slice(arStart) };
    for (const code of ['ur', 'ps', 'fa-AF', 'es', 'fr']) tables[code] = readFileSync(new URL(`locales/${code}.ts`, dir), 'utf8');
    const value = (body: string, key: string) => body.match(new RegExp(`^\\s+${key}: "([^"]*)",$`, 'm'))?.[1];
    for (const [code, body] of Object.entries(tables)) {
        for (const key of ['tuition_owed_items', 'tuition_paid_ahead', 'tuition_due_by']) {
            const v = value(body, key);
            assert.ok(v && v.trim() !== '', `${code}.${key} is missing or empty`);
            if (code !== 'en') assert.notEqual(v, value(tables.en, key), `${code}.${key} is still English`);
        }
        assert.match(value(body, 'tuition_due_by')!, /\{x\}/, `${code}.tuition_due_by lost its date slot`);
    }
    assert.equal(value(tables.ar, 'tuition_owed_items'), 'يتكوّن من');
    assert.equal(value(tables.ar, 'tuition_paid_ahead'), 'مدفوع مقدّمًا');
});
