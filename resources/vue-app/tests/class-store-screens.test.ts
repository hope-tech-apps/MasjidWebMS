/**
 * The class store's three screens are wired the way the rules say (T-003.4): shown only where the
 * server says the store is on, one request id per click, no sorting or totalling of Bucks
 * anywhere, and every ledger kind has a word in every language the portal speaks.
 *
 * These read the sources (there is no DOM here), so each check names the line of reasoning it
 * protects; the behaviour itself is pinned by ClassStoreApiTest and ClassStorePrivacyTest on the
 * server and by class-store.test.ts for the helpers.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { LEDGER_KINDS } from '../core/helpers/classStore.ts';

const root = new URL('../', import.meta.url);
const read = (rel: string) => readFileSync(new URL(rel, root), 'utf8');
/** A source with its comments removed, so a rule can name a word without tripping its own check. */
const code = (src: string) => src
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '');

const teacherTab = read('views/teacher/TeacherClass.vue');
const teacherStore = read('views/teacher/TeacherClassStore.vue');
const familyClass = read('views/family/FamilyClass.vue');
const familyBucks = read('views/family/FamilyBucks.vue');
const officeView = read('views/dashboard/ClassStoreView.vue');

test('the teacher sees the Class Store tab only when the class payload says the store is on', () => {
    assert.match(teacherTab, /shownMoreTabs = computed\(\(\) => moreTabs\.filter\(\(t\) => t\.key !== 'store' \|\| group\.value\?\.class_store === true\)\)/);
    assert.match(teacherTab, /v-for="t in shownMoreTabs"/, 'the menu draws the filtered list, not the whole one');
    assert.match(teacherTab, /activeTab === 'store' && group\.class_store === true/, 'and the section itself is guarded, so a stale tab key opens nothing');
});

test('a parent sees the Bucks section only when the class payload says the store is on, one child at a time', () => {
    assert.match(familyClass, /<FamilyBucks v-if="group\.class_store === true" :base="base" :member-id="child\.membership_id" \/>/);
    // Inside the per-child card: never above the children loop, where it could be one figure for the class.
    const card = familyClass.indexOf('v-for="child in group.children"');
    const bucks = familyClass.indexOf('<FamilyBucks');
    assert.ok(card > 0 && bucks > card, 'the Bucks section is drawn inside each child\'s own card');
});

test('a prize is given and paid out with one request id per click, so a double tap is a replay', () => {
    const src = code(teacherStore);
    assert.match(src, /redeem`, \{ prize_id: p\.id, request_id: newRequestId\(\) \}/);
    assert.match(src, /cash-out`, \{ amount: Number\(cashAmount\.value\), request_id: newRequestId\(\) \}/);
    assert.match(src, /:disabled="!p\.available \|\| busy"/, 'and the button is off while a write is in flight');
});

test('a balance the screen could not read is never shown as zero', () => {
    assert.match(code(teacherStore), /balance === null \? 'Balance unavailable' : bucksLabel\(balance\)/);
    assert.match(code(teacherStore), /balance\.value = null/, 'a failed history read blanks the figure');
    assert.match(familyBucks, /v-if="failed" class="text-danger small">\{\{ t\('bucks_failed'\) \}\}/);
    assert.match(code(familyBucks), /v-else-if="loaded"/, 'the balance is drawn only after a successful read');
});

test('no screen sorts, ranks or totals Bucks', () => {
    for (const [name, src] of [['TeacherClassStore', teacherStore], ['FamilyBucks', familyBucks], ['ClassStoreView', officeView]] as const) {
        const body = code(src);
        assert.doesNotMatch(body, /\.sort\(/, `${name} must not sort`);
        assert.doesNotMatch(body, /\brank\b|leaderboard|topStudent|\.reduce\(/i, `${name} must not rank or sum children`);
    }
});

test('the office screen reads class totals and carries no child anywhere', () => {
    const body = code(officeView);
    assert.match(body, /prize-reconciliation\?weeks=/);
    // Copy may say "student"; what must never appear is a field that names one.
    assert.doesNotMatch(body, /membership|\.students\b|\bcontact\b|first_name|last_name|avatar/i, 'no child appears on the office screen');
});

test('the office sends a blank stock as the empty string, because a null is dropped from a form body', () => {
    assert.match(code(officeView), /stock: body\.stock === null \? '' : body\.stock/);
    assert.match(code(officeView), /is_active: body\.is_active \? '1' : '0'/);
});

test('the office menu item, route and capability are the same key', () => {
    assert.match(read('core/constants/dashboardAsideMenuItems.ts'), /to: '\/masjid\/class-store',\s+allowed_types: \['SuperAdmin', 'MasjidAdmin'\],\s+requiresCapability: 'class_store'/);
    assert.match(read('router/routes/dashboardLayoutRoutes.ts'), /path: 'class-store',[\s\S]*?requiresCapability: 'class_store'/);
    assert.match(read('core/types/data/Capability.ts'), /\| 'class_store'/);
});

test('every ledger kind has a word in English and in Arabic, and the other four tables carry the same keys', () => {
    const i18n = read('views/family/familyI18n.ts');
    const en = i18n.slice(i18n.indexOf('\n    en: {'), i18n.indexOf('\n    ar: {'));
    const ar = i18n.slice(i18n.indexOf('\n    ar: {'));
    const keys = [...LEDGER_KINDS.map((k) => `bucks_kind_${k}`), 'bucks_section', 'bucks_balance', 'bucks_none', 'bucks_undone', 'bucks_week_of', 'bucks_explain', 'bucks_rate_one', 'bucks_rate_many', 'bucks_failed', 'bucks_more'];

    for (const [lang, table] of [['en', en], ['ar', ar], ['ur', read('views/family/locales/ur.ts')], ['ps', read('views/family/locales/ps.ts')], ['fa-AF', read('views/family/locales/fa-AF.ts')], ['es', read('views/family/locales/es.ts')]] as const) {
        for (const key of keys) {
            assert.match(table, new RegExp(`^\\s+${key}: ".+",$`, 'm'), `${lang} is missing ${key}`);
        }
    }
});

test('the prize title is drawn as the school wrote it, never through the translation layer', () => {
    assert.doesNotMatch(code(familyBucks), /useContentTranslation|txAward|translate/i);
    assert.match(familyBucks, /\$\{kind\}: \$\{e\.prize_title\}/);
});
