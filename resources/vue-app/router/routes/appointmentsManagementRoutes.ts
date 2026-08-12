import { RouteRecordRaw } from "vue-router"

/**
 * Appointment requests — the Community vertical's intake queue (T-021).
 *
 * These are CHILD routes of the `/masjid` dashboard layout, spread into
 * `dashboardLayoutRoutes` alongside the other per-feature files (pages, events,
 * services…). They cannot be registered at the top level of `routes.ts`: the
 * paths are relative and the screens need DashboardLayout's chrome and its
 * `auth` meta, both of which come from the parent record.
 *
 * `requiresCrm` is not decoration — the backend routes live inside the `crm`
 * middleware group (routes/admin.php), so a tenant without crm_enabled would be
 * 403'd by the API. The guard just avoids handing them a dead screen first.
 *
 * There is deliberately NO org-type gate here, although the SIDEBAR entry has
 * one. The screen is offered to community organizations because that is whose
 * workflow it is, but the public intake endpoint accepts a request from ANY
 * tenant that has the form on its site — so a masjid running a counselling or
 * clinic service can still receive these, and a route gate would leave that data
 * with no screen at all. Hiding a menu item is a default; hiding the data is a
 * bug. The real boundary stays where it belongs: `permission:view contacts` plus
 * the CRM gate, server-side.
 */
const appointmentsManagementRoutes: RouteRecordRaw[] = [
    {
        // `pageTitle` is the document title and cannot reach the store, so it
        // stays the authored, vertical-neutral name of the FEATURE — the same
        // reasoning as the groups routes.
        path: 'appointment-requests',
        name: 'masjid.appointmentRequests',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Appointment Requests',
            requiresCrm: true
        },
        component: () => import("@/views/dashboard/AppointmentRequestsView.vue")
    },
    {
        // Route NAME is load-bearing: the queue links to each request by name.
        path: 'appointment-requests/:requestId',
        name: 'masjid.appointmentRequestDetail',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Appointment Request',
            requiresCrm: true
        },
        component: () => import("@/views/dashboard/AppointmentRequestDetailView.vue")
    }
]

export default appointmentsManagementRoutes
