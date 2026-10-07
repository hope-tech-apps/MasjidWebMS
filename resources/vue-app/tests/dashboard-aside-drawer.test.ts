/**
 * The sidebar drawer on a phone, MOUNTED: components/dashboard/DashboardAside.vue is compiled from
 * its .vue file and driven with the real sidebar rules (core/access/orgAccess.ts) and the real menu
 * (core/constants/dashboardAsideMenuItems.ts).
 *
 * On a phone the open drawer covers the screen, and a tap on an entry has to close it. The entries
 * used to get that once, in onMounted, by looking them up in the page, so an entry drawn LATER never
 * closed the drawer. Entries are drawn later in three ways, each walked below: an in-tab organisation
 * switch (the stores are emptied, then filled with the new organisation), a first paint before the
 * organisation has arrived, and a SuperAdmin's sidebar going from the platform's menu to an
 * organisation's.
 *
 * What this cannot say: <router-link> is a stand-in element here (the test supplies no vue-router
 * component), so these tests prove the template puts the handler on every link. That RouterLink
 * hands a listener on to its <a>, beside its own, is vue-router's (the switched-off links have
 * always relied on it), and was driven in a browser at phone width when this was fixed.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import type { TestContext } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { click, flush, loadTs, mountSfc } from './support/mountSfc.ts';
import type { Mounted, Node } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');

// ------------------------------------------------------------------------------------------ the page

/**
 * The layout the sidebar sits in (DashboardLayout's #dashboard_layout). On a phone the drawer is
 * OPEN while it carries `aside-hidden` (resources/css/custom/dashboard.css inverts the class under
 * 920px), and closing it means taking the class off.
 */
const layout = {
    classes: new Set<string>(),
    classList: {
        add: (name: string) => { layout.classes.add(name); },
        remove: (name: string) => { layout.classes.delete(name); },
        contains: (name: string) => layout.classes.has(name),
    },
};
const drawerIsOpen = (): boolean => layout.classes.has('aside-hidden');
const openDrawer = (): void => { layout.classes.add('aside-hidden'); };

// The sidebar asks the document for the layout; everything else is looked up among what is mounted.
const documentStub = (globalThis as any).document;
const mountedLookup = documentStub.getElementById.bind(documentStub);
documentStub.getElementById = (id: string) => (id === 'dashboard_layout' ? layout : mountedLookup(id));

// ------------------------------------------------------------------------------------------ fixtures

const typesOnly = {};
const menu = await loadTs('core/constants/dashboardAsideMenuItems.ts', { '@/core/types/config/AsideMenuItem': typesOnly });
const orgAccess = await loadTs('core/access/orgAccess.ts', {
    '@/core/constants/dashboardAsideMenuItems': menu,
    '@/core/types/config/AsideMenuItem': typesOnly,
    '@/core/types/data/Capability': typesOnly,
    '@/core/types/data/Masjid': typesOnly,
    '@/core/types/data/User': typesOnly,
    '@/core/types/data/Vertical': typesOnly,
});

/** A school that holds the grants and CRM: most of its entries exist only once the payload is in. */
const school = () => ({
    id: 14, name: 'Al-Noor Academy', org_type: 'school', vertical: { org_type: 'school' }, crm_enabled: true, assistant_enabled: true,
    capabilities: { tv_display: true, shop: true, class_store: true, school_calendar: true, web_pages: true, jummah_lunch: true, form_editing: true },
    modules_off: [], modules_on: [],
});

/** A masjid with nothing extra switched on: the grant entries go to a SuperAdmin's "Switched off" list. */
const plainMasjid = () => ({
    id: 7, name: 'Al-Noor Centre', org_type: 'masjid', vertical: { org_type: 'masjid' }, crm_enabled: false, assistant_enabled: false,
    capabilities: { tv_display: false, shop: false }, modules_off: [], modules_on: [],
});

async function mountAside(t: TestContext, start: { userType: string; masjid: any; items?: any[]; dashboardType?: string }) {
    const masjidStore: any = vue.reactive({
        masjid: start.masjid,
        organizationLabel: 'Organisation',
        term: (key: string) => key,
        // As stores/masjidStore.ts reads it: the payload's vertical, and a masjid until there is one.
        get orgType() { return this.masjid?.vertical?.org_type ?? 'masjid'; },
    });
    const asideStore: any = vue.reactive({ asideMenuItems: start.items ?? menu.MASJID_DASHBOARD_ASIDE_MENU });
    const route: any = vue.reactive({ meta: { dashboardType: start.dashboardType ?? 'masjid' } });

    layout.classes.clear();
    const screen = await mountSfc('components/dashboard/DashboardAside.vue', {}, {
        '@/core/access/orgAccess': orgAccess,
        '@/core/types/config/AsideMenuItem': typesOnly,
        '@/stores/authStore': { useAuthStore: () => ({ user: { type: start.userType } }) },
        '@/stores/config/dashboardAsideStore': { useDashboardAsideStore: () => asideStore },
        '@/stores/masjidStore': { useMasjidStore: () => masjidStore },
        '@/stores/tenantSwitchStore': { useTenantSwitchStore: () => ({ mismatch: null, chromeOrgName: 'Al-Noor' }) },
        'vue-router': { useRoute: () => route },
    });
    // Unmounted when the test ends, pass or fail: a sidebar left mounted would answer the NEXT
    // test's lookups in the page (its close button would be found instead of the new one).
    t.after(() => screen.unmount());
    await flush();

    return { screen, masjidStore, asideStore, route };
}

/** The entries in the menu itself, in order. */
const entries = (screen: Mounted): Node[] => screen.all((n) => n.tag === 'router-link' && String(n.props.class).includes('dashboard-aside-menu-item'));
/** Where each one goes: the labels follow the organisation's vocabulary, the routes do not. */
const routes = (list: Node[]): string[] => list.map((n) => String(n.props.to));
const switchedOffLinks = (screen: Mounted): Node[] => screen.all((n) => n.tag === 'router-link' && String(n.props.class).includes('aside-switched-off-link'));

/** Tap every entry with the drawer open, and say which ones left it open. */
function leftOpenBy(list: Node[]): string[] {
    const stayed: string[] = [];
    for (const entry of list) {
        openDrawer();
        click(entry);
        if (drawerIsOpen()) stayed.push(String(entry.props.to));
    }
    layout.classes.clear();

    return stayed;
}

// --------------------------------------------------------------------------------------------- tests

test('after an in-tab organisation switch every entry still closes the drawer', async (t) => {
    const { screen, masjidStore } = await mountAside(t, { userType: 'MasjidAdmin', masjid: school() });
    const before = routes(entries(screen));
    assert.ok(before.includes('/masjid/tv-display') && before.includes('/masjid/groups'), `the school's own entries are drawn: ${before.join(', ')}`);
    assert.deepEqual(leftOpenBy(entries(screen)), [], 'as first drawn');

    // What tenantSwitchStore.switchTo() does to this sidebar: the stores are emptied ...
    masjidStore.masjid = undefined;
    await flush();
    const whileEmpty = routes(entries(screen));
    assert.ok(!whileEmpty.includes('/masjid/tv-display') && !whileEmpty.includes('/masjid/groups'), 'entries that need the organisation go while there is none');

    // ... and the organisation that was switched to arrives.
    masjidStore.masjid = school();
    await flush();
    assert.deepEqual(routes(entries(screen)), before);
    assert.ok(before.length - whileEmpty.length > 0, 'so some entries were drawn again, after the sidebar was mounted');
    assert.deepEqual(leftOpenBy(entries(screen)), [], 'entries drawn after the switch');
});

test('a sidebar painted before its organisation arrived closes the drawer from every entry once it has', async (t) => {
    const { screen, masjidStore } = await mountAside(t, { userType: 'MasjidAdmin', masjid: null });
    const early = routes(entries(screen));
    assert.ok(early.length > 0 && !early.includes('/masjid/tv-display'));

    masjidStore.masjid = school();
    await flush();
    const late = entries(screen).filter((entry) => !early.includes(String(entry.props.to)));
    assert.ok(routes(late).includes('/masjid/tv-display') && routes(late).includes('/masjid/groups'), `drawn late: ${routes(late).join(', ')}`);

    assert.deepEqual(leftOpenBy(late), [], 'the entries drawn late');
    assert.deepEqual(leftOpenBy(entries(screen)), [], 'every entry');
});

test('a SuperAdmin going from the platform menu into an organisation: every entry and every switched-off link closes the drawer', async (t) => {
    const { screen, masjidStore, asideStore, route } = await mountAside(t, {
        userType: 'SuperAdmin', masjid: null, items: menu.SUPER_DASHBOARD_ASIDE_MENU, dashboardType: 'super',
    });
    const platform = entries(screen);
    assert.ok(platform.length > 0);
    assert.deepEqual(leftOpenBy(platform), []);

    // router.ts swaps the list, and the organisation's payload follows.
    asideStore.asideMenuItems = menu.MASJID_DASHBOARD_ASIDE_MENU;
    route.meta.dashboardType = 'masjid';
    masjidStore.masjid = plainMasjid();
    await flush();

    const organisation = entries(screen);
    assert.ok(organisation.length > platform.length, 'a longer menu, so entries the first mount never saw');
    assert.deepEqual(leftOpenBy(organisation), []);

    const off = switchedOffLinks(screen);
    assert.ok(routes(off).includes('/masjid/tv-display'), `the grant it lacks is listed as switched off: ${routes(off).join(', ')}`);
    assert.deepEqual(leftOpenBy(off), []);
});

test('the close button still closes the drawer', async (t) => {
    const { screen } = await mountAside(t, { userType: 'MasjidAdmin', masjid: school() });

    openDrawer();
    assert.equal(click(screen.all((n) => n.props.id === 'dashboard_aside_close_btn')[0]), true);
    assert.equal(drawerIsOpen(), false);
});

test('each link closes the drawer itself: nothing is looked up in the page once and left at that', () => {
    const source = readFileSync(new URL('../components/dashboard/DashboardAside.vue', import.meta.url), 'utf8');
    const template = source.slice(0, source.indexOf('<script'));

    assert.equal((template.match(/<router-link/g) ?? []).length, 3, 'the menu entry, switched-off link and final Help entry');
    assert.equal((template.match(/<router-link[^>]*@click="closeAsideOnSmallScreens"/g) ?? []).length, 3);
    assert.doesNotMatch(source, /querySelectorAll/);
});

test('Help is the last rendered link, including after a SuperAdmin switched-off list', async (t) => {
    for (const userType of ['MasjidAdmin', 'SuperAdmin']) {
        const { screen } = await mountAside(t, { userType, masjid: plainMasjid() });
        const links = screen.all(n => n.tag === 'router-link');
        assert.equal(links.at(-1)?.props.to, '/masjid/help/admin');
        assert.equal(links.at(-1)?.textContent, 'Help');
        assert.deepEqual(leftOpenBy([links.at(-1)!]), []);
        screen.unmount();
    }
});
