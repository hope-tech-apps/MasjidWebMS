import { RouteRecordRaw } from "vue-router";
import authRoutes from "@/router/routes/authLayoutRoutes";
import dashboardRoutes from "@/router/routes/dashboardLayoutRoutes";
import superDashboardRoutes from "@/router/routes/superDashboardRoutes";

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