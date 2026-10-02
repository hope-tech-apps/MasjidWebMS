/**
 * LUNCH STAFF: what their realm serves, the board must let them do.
 *
 * `routes/lunch.php` re-lists the admin lunch routes one by one, and the board's store
 * (`stores/masjid/jummahLunchStore.ts`) addresses whichever realm the signed-in person is in
 * through `base()`. Two things have gone wrong in that pairing, both silently:
 *
 *  - 2026-09-24: the order editor's route was in admin.php only, so the button was hidden
 *    from volunteers;
 *  - 2026-10-02: the route was served and the button shown, but the STORE still refused a
 *    LunchStaff itself ("Only a masjid administrator can change what is on an order"),
 *    before any request. The server suite was green throughout: it never runs the store.
 *
 * So the pairing is pinned from the two source files: a call the lunch realm serves may not
 * be refused in the store for a LunchStaff, and a call it does not serve must be on the
 * short list below, each one a decision somebody made.
 *
 * `.vue` and Pinia files cannot be loaded by `node --test`, so this reads source text.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const repoRoot = path.resolve(appRoot, '..', '..');
const store = readFileSync(path.join(appRoot, 'stores/masjid/jummahLunchStore.ts'), 'utf8');
const view = readFileSync(path.join(appRoot, 'views/dashboard/JummahLunchView.vue'), 'utf8');
const lunchRoutes = readFileSync(path.join(repoRoot, 'routes/lunch.php'), 'utf8');

/** "PATCH /menus/{}/orders/{}/items": one shape for a route and for a store call. */
const shape = (verb: string, p: string): string =>
    `${verb.toUpperCase()} ${p.replace(/\$\{[^}]+\}|\{[^}]+\}/g, '{}').replace(/\/$/, '') || '/'}`;

/** Comments out: a commented-out route is not served, and a guard named in a comment is not a guard. */
const withoutComments = (code: string): string => code.replace(/\/\*[\s\S]*?\*\/|^\s*(\/\/|#).*$/gm, '');

/** Every route the lunch realm serves under .../jummah-lunch. */
const served = new Set(
    [...withoutComments(lunchRoutes).matchAll(/Route::(get|post|put|patch|delete)\('(\/[^']*)',\s*'\w+'\)/g)]
        .map((m) => shape(m[1], m[2])),
);

/** Each store function with the realm-relative calls it makes and whether it turns a LunchStaff away itself. */
type Call = { fn: string; shape: string; refusesLunchStaff: boolean };
const calls: Call[] = [];
const functions = store.split(/\n {4}async function /).slice(1);
for (const body of functions) {
    const fn = body.slice(0, body.indexOf('('));
    // Up to the function's own closing brace (four spaces of indent), so a neighbour's guard is not counted.
    const own = withoutComments(body.slice(0, body.search(/\n {4}\}\n/) + 1));
    // DENY BY DEFAULT. Not one spelling of the refusal: a function that so much as names who
    // the caller is, or the admin-only masjid store, is treated as turning a LunchStaff away.
    // (`if (isLunchStaff()) return`, a brace-less throw, `authStore.user?.type === ...` and
    // `if (!masjidStore.masjid?.id) throw` all passed an earlier, narrower version of this.)
    const refusesLunchStaff = /isLunchStaff|adminOnly|LunchStaff|user\??\.type|masjidStore/.test(own);
    for (const m of own.matchAll(/ApiService\.(get|post|put|patch|delete)\(\s*`\$\{base\(\)\}([^`]*)`/g)) {
        calls.push({ fn, shape: shape(m[1], m[2]), refusesLunchStaff });
    }
}

/**
 * What the lunch realm deliberately does NOT serve. Deleting a menu takes its customers'
 * orders with it, and the staff routes create logins. A new entry here is a decision: decide
 * whether volunteers get the route, and add it to routes/lunch.php if they do.
 */
const ADMIN_ONLY = new Set([
    'DELETE /menus/{}',
    'GET /staff',
    'POST /staff',
    'PUT /staff/{}',
    'POST /staff/{}/invite',
    'DELETE /staff/{}',
]);

test('the two files were read the way this test thinks', () => {
    assert.ok(served.size >= 12, `routes/lunch.php: ${served.size} routes parsed`);
    assert.ok(calls.length >= 18, `the store: ${calls.length} realm calls parsed`);
    assert.ok(served.has('PATCH /menus/{}/orders/{}/items'), 'the lunch realm serves the order editor');
    assert.ok(calls.some((c) => c.fn === 'updateOrderItems' && c.shape === 'PATCH /menus/{}/orders/{}/items'));
});

test('a LunchStaff can save an edit to what is on an order: the store sends it to their own realm', () => {
    const edit = calls.find((c) => c.fn === 'updateOrderItems');

    assert.ok(edit);
    assert.equal(edit.refusesLunchStaff, false, 'the store must not refuse a LunchStaff before the request');
    assert.doesNotMatch(store, /Only a masjid administrator can change what is on an order/);
    // The prefix is the caller's own realm, never a hard-coded admin one.
    assert.match(store, /return isLunchStaff\(\)\s*\?\s*`\/api\/lunch\/masjids\/\$\{authStore\.user\?\.masjid\?\.id\}\/jummah-lunch`/);
    // And the board offers the button to them: only a cancelled or refunded order hides it.
    assert.match(view, /<button v-if="canEditItems\(o\)"[^>]*@click="openEditItems\(o\)">Edit items<\/button>/);
    assert.match(view, /saved = await store\.updateOrderItems\(currentMenu\.value\.id, o\.id, items\);/);
});

test('the two gates every call goes through let a LunchStaff past', () => {
    // Every served function opens with ensureMasjid() or notReady(). A refusal moved into
    // either would stop every volunteer call at once and no per-function check would see it.
    assert.match(store, /function ensureMasjid\(\): void \{\s*if \(isLunchStaff\(\)\) \{\s*return;\s*\}/);
    assert.match(store, /function notReady\(\): boolean \{\s*return !isLunchStaff\(\) && !masjidStore\.masjid\?\.id;\s*\}/);
});

test('the board\'s own handlers do not turn a LunchStaff away either', () => {
    for (const name of ['canEditItems', 'openEditItems', 'saveEditItems']) {
        const at = view.indexOf(`function ${name}(`);
        assert.ok(at > 0, `${name} is still in the board`);
        const body = withoutComments(view.slice(at, view.indexOf('\n}\n', at)));
        assert.doesNotMatch(body, /isLunchStaff|LunchStaff|\.type\b/, `${name} must not ask who the caller is`);
    }
});

test('the board names the organisation from the store, which knows a volunteer\'s', () => {
    // masjidStore is never loaded in a lunch volunteer's shell: read from it, the public order
    // address on an open menu was "/jummah-lunch/" with no number.
    assert.match(store, /function organisationId\(\): number \| string \| undefined \{\s*return isLunchStaff\(\) \? authStore\.user\?\.masjid\?\.id : masjidStore\.masjid\?\.id;\s*\}/);
    assert.match(view, /const masjidId = computed\(\(\) => store\.organisationId\(\)\);/);
    assert.doesNotMatch(withoutComments(view.slice(view.indexOf('<script'))), /masjidStore/);
});

test('nothing the lunch realm serves is refused in the store for a LunchStaff', () => {
    const blocked = calls.filter((c) => served.has(c.shape) && c.refusesLunchStaff);

    assert.deepEqual(blocked, [], 'served by routes/lunch.php but refused client-side');
});

test('what the lunch realm does not serve is a known, decided list', () => {
    const unserved = [...new Set(calls.filter((c) => !served.has(c.shape)).map((c) => c.shape))].sort();

    assert.deepEqual(unserved, [...ADMIN_ONLY].sort());
    // The staff calls stop in the store; the menu delete is hidden by the board, since the store shares it.
    for (const c of calls.filter((x) => x.shape.includes('/staff') && x.fn === 'fetchStaff')) {
        assert.equal(c.refusesLunchStaff, true);
    }
    assert.match(view, /<button v-if="!isLunchStaff" class="btn btn-sm btn-outline-danger" @click="removeMenu\(m\)">Delete<\/button>/);
});

test('every route the lunch realm serves is one the board can reach', () => {
    const reachable = new Set(calls.map((c) => c.shape));
    const unreached = [...served].filter((s) => !reachable.has(s)).sort();

    // One order on its own is served for other clients; the board reads the list. The realm's
    // /user and /logout are closure-free controller-array routes, outside this file's pattern.
    assert.deepEqual(unreached, ['GET /menus/{}/orders/{}']);
});
