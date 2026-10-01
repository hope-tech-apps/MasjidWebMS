/**
 * The shop is wired the way the neighbouring capability-gated screens are (shop slice B3): one sidebar
 * entry shown only where the organisation has the `shop` grant, every route guarded by it, every write a
 * JSON body, and nothing left half-done in the shop's files.
 *
 * These read the sources (there is no DOM for the router or the sidebar here), so each check names what it
 * protects; the behaviour of the screens is pinned by shop-screens.test.ts and the helpers by shop.test.ts.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { currencySymbol } from '../core/helpers/shop.ts';

const root = new URL('../', import.meta.url);
const read = (rel: string) => readFileSync(new URL(rel, root), 'utf8');

const menu = read('core/constants/dashboardAsideMenuItems.ts');
const routes = read('router/routes/shopManagementRoutes.ts');
const layout = read('router/routes/dashboardLayoutRoutes.ts');
const store = read('stores/masjid/shopStore.ts');

/** The one menu item whose route is /masjid/shop, from its opening brace to the next item's. */
const shopItem = (() => {
    const at = menu.indexOf("to: '/masjid/shop'");
    const start = menu.lastIndexOf('\n    {', at);
    const end = menu.indexOf('\n    },', at);

    return menu.slice(start, end);
})();

test('the Shop sidebar entry is shown only for an organisation that has the shop grant, to administrators', () => {
    assert.match(shopItem, /title: "Shop"/);
    assert.match(shopItem, /allowed_types: \['SuperAdmin', 'MasjidAdmin'\]/, 'the SPA\'s way of saying the person may view donations');
    assert.match(shopItem, /requiresCapability: 'shop'/);
    // A grant, not a module: a module's check would show the entry the moment the payload is silent.
    assert.doesNotMatch(shopItem.replace(/\/\/.*$/gm, ''), /requiresModule|requiresCrm|requiresOrgTypes/);
    assert.equal((menu.match(/to: '\/masjid\/shop'/g) ?? []).length, 1, 'one entry, and it is not duplicated');
});

test('the sidebar entry has its icon and its route type, and the route table spreads the shop routes in', () => {
    assert.match(read('components/dashboard/DashboardAside.vue'), /'\/masjid\/shop': 'bi-bag'/);

    const types = read('core/types/config/SystemRoutes.ts');
    for (const path of ["'/masjid/shop'", "'/masjid/shop/products'", "'/masjid/shop/products/new'", '`/masjid/shop/products/${number}/edit`', "'/masjid/shop/pickup'"]) {
        assert.ok(types.includes(path), path);
    }

    assert.match(layout, /import shopManagementRoutes from "@\/router\/routes\/shopManagementRoutes"/);
    assert.match(layout, /\.\.\.shopManagementRoutes,/);
});

test('every shop screen is behind the shop grant, for administrators, and the parent lands on Products', () => {
    assert.match(routes, /redirect: '\/masjid\/shop\/products'/);

    const children = routes.split('name: ').slice(1);
    assert.equal(children.length, 4, 'Products, New product, Edit product and Pickup list');
    for (const child of children) {
        assert.match(child, /requiresCapability: 'shop'/);
        assert.match(child, /allowedUsers: \['SuperAdmin', 'MasjidAdmin'\]/);
        assert.match(child, /auth: true/);
    }

    // No member directory needed: the shop's server routes sit outside `crm`.
    assert.doesNotMatch(routes.replace(/\/\*[\s\S]*?\*\//g, ''), /requiresCrm/);
    // The id is digits only, so `new` can never be read as one.
    assert.match(routes, /products\/:productId\(\\\\d\+\)\/edit/);
});

test('every write is JSON (a form body would turn each price and count into a string), and the CSV is a bearer fetch', () => {
    const code = store.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

    assert.doesNotMatch(code, /ApiService\.put\(/, 'ApiService.put is form-encoded by default');
    assert.doesNotMatch(code, /ApiService\.patch\(/);
    // Create, update and reorder carry their numbers explicitly as JSON; collect and resolve go through
    // ApiService.post, which sends a plain object as JSON.
    assert.equal((code.match(/ApiService\.VueApp\.axios\.(post|put)\(.*JSON_BODY\)/g) ?? []).length, 3);
    assert.match(code, /const JSON_BODY = \{ headers: \{ 'Content-Type': 'application\/json' \} \}/);
    assert.match(code, /fetch\(request\.url, request\.init\)/);
});

test('the shop adds no new capability, permission or terminology, and no screen leaves a TODO behind', () => {
    const dir = 'views/dashboard/shop/';
    const files = [
        ...readdirSync(new URL(dir, root)).map((name) => dir + name),
        'core/helpers/shop.ts', 'core/types/data/masjid-related/Shop.ts', 'stores/masjid/shopStore.ts', 'router/routes/shopManagementRoutes.ts',
    ];

    assert.ok(files.length >= 9);
    for (const file of files) assert.doesNotMatch(read(file), /TODO|FIXME|XXX/, file);

    assert.match(read('core/types/data/Capability.ts'), /\| 'shop';/, 'the `shop` key already existed');
});

test('a price input shows its currency\'s symbol, the code when Intl cannot, and a dollar for no code', () => {
    assert.equal(currencySymbol('usd'), '$');
    assert.equal(currencySymbol('EUR'), '€');
    assert.equal(currencySymbol(''), '$');
    assert.equal(currencySymbol(null), '$');
    assert.equal(currencySymbol('not-a-code'), 'NOT-A-CODE');
});
