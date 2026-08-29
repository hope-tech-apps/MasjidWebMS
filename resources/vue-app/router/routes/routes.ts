import { RouteRecordRaw } from "vue-router";
import authRoutes from "@/router/routes/authLayoutRoutes";
import dashboardRoutes from "@/router/routes/dashboardLayoutRoutes";
import superDashboardRoutes from "@/router/routes/superDashboardRoutes";
import familyRoutes from "@/router/routes/familyRoutes";
import teacherRoutes from "@/router/routes/teacherRoutes";
import jummahLunchRoutes from "@/router/routes/jummahLunchRoutes";

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        name: 'home',
        component: () => import("@/AdminDashboardApp.vue"),
        redirect: '/auth',
        children: [
            ...authRoutes,
            ...dashboardRoutes,
            ...superDashboardRoutes
        ]
    },
    // The parent portal — its own realm, outside the admin app's guard.
    ...familyRoutes,
    // The teacher shell — a scoped staff realm, top-level so it carries no
    // admin sidebar or dashboard chrome.
    ...teacherRoutes,
    // Public Jummah-lunch ordering — no auth, no chrome.
    ...jummahLunchRoutes,
    {
        // Public donor-facing result pages (Stripe Checkout success/cancel URLs).
        // Standalone — no auth or dashboard chrome.
        path: '/donations/thank-you',
        name: 'donation-thank-you',
        component: () => import("@/views/general/DonationThankYou.vue")
    },
    {
        path: '/donations/cancelled',
        name: 'donation-cancelled',
        component: () => import("@/views/general/DonationCancelled.vue")
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/auth/404'
    }
]

export default routes