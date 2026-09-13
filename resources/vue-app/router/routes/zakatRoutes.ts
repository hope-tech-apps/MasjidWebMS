import { RouteRecordRaw } from "vue-router"

/**
 * The zakat calculator screen (T-043c).
 *
 * Its own route file, registered as a child of the /masjid dashboard layout in
 * dashboardLayoutRoutes.ts, the appointmentsManagementRoutes shape.
 *
 * NO `requiresCrm` and no `requiresOrgTypes`, on purpose. The screen is two
 * things: the place an organisation records the metal price its PUBLIC
 * calculator answers from, and the calculator itself. Neither touches the
 * donations ledger, the funds or a contact — a masjid with no CRM at all still
 * publishes a zakat calculator, and gating the only screen that can set the
 * price behind the CRM flag would leave that tenant's public endpoint answering
 * "threshold unknown" for ever with no way to fix it.
 *
 * The server decides who may WRITE (`permission:manage donations`); this route
 * only decides who is shown the screen. That is the same relationship every
 * other route here has with its middleware.
 */
const zakatRoutes: RouteRecordRaw[] = [
    {
        path: 'zakat',
        name: 'masjid.zakat',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Zakat Calculator'
        },
        component: () => import("@/views/dashboard/ZakatCalculatorView.vue")
    }
]

export default zakatRoutes
