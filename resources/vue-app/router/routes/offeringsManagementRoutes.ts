import { RouteRecordRaw } from "vue-router"

/**
 * Offerings, their fee plans and their registrations — the T-006 registration +
 * billing engine's operator screens.
 *
 * These are CHILD routes of the `/masjid` dashboard layout, spread into
 * `dashboardLayoutRoutes` alongside the other per-feature files (pages, events,
 * appointments…). They cannot be registered at the top level of `routes.ts`: the
 * paths are relative and the screens need DashboardLayout's chrome and its
 * `auth` meta, both of which come from the parent record.
 *
 * `requiresCrm` is not decoration — the backend routes live inside the `crm`
 * middleware group (routes/admin.php), so a tenant without crm_enabled would be
 * 403'd by the API. The guard just avoids handing them a dead screen first.
 *
 * NO ORG-TYPE GATE, deliberately. The pilot for this feature is a school selling
 * tuition, but an offering is "a thing people register and pay for" and every
 * vertical has those — a masjid's summer camp, a community org's membership
 * year. The public registration endpoints accept a sign-up from ANY tenant that
 * has the offering on its site, so an org-gated route would leave real money
 * with no screen at all. Hiding a menu item is a default; hiding data is a bug.
 * The real boundary stays server-side: `permission:view contacts` for the
 * program structure, `permission:view/manage donations` for anything that
 * decides or waives a price, plus the CRM gate.
 *
 * `pageTitle` stays the authored, vertical-neutral word: it is the document
 * title and cannot reach the store, so the tenant's own vocabulary ("Programs",
 * "Services") is applied INSIDE the screens — the same reasoning as the groups
 * and appointment routes.
 */
const offeringsManagementRoutes: RouteRecordRaw[] = [
    {
        path: 'offerings',
        name: 'masjid.offerings',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Programs & Registration',
            requiresCrm: true
        },
        component: () => import("@/views/dashboard/OfferingsView.vue")
    },
    {
        // Route NAME is load-bearing: the list links to each offering by name.
        path: 'offerings/:offeringId',
        name: 'masjid.offeringDetail',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Program',
            requiresCrm: true
        },
        component: () => import("@/views/dashboard/OfferingDetailView.vue")
    },
    {
        // One registration, nested under its offering because that is how the
        // API resolves it: a registration is looked up THROUGH its offering, so
        // a registration id from another offering of this same organization is
        // a 404 rather than a readable row. The URL mirrors that.
        path: 'offerings/:offeringId/registrations/:registrationId',
        name: 'masjid.offeringRegistrationDetail',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Registration',
            requiresCrm: true
        },
        component: () => import("@/views/dashboard/OfferingRegistrationDetailView.vue")
    }
]

export default offeringsManagementRoutes
