import { RouteRecordRaw } from "vue-router";
import { useAuthStore } from "@/stores/authStore";

/**
 * The lunch shell.
 *
 * A TOP-LEVEL route, deliberately not a child of AdminDashboardApp — same
 * reasoning as teacherRoutes. Nesting it under the admin app would drag the
 * whole DashboardLayout and its sidebar in front of a login that may not open a
 * single item in it.
 *
 * A LunchStaff shares the admin principal and token (authStore), so this realm
 * rides the SAME global guard: `meta.auth` + `meta.allowedUsers: ['LunchStaff']`
 * make router.beforeEach enforce sign-in and user type. The `beforeEnter` below
 * is defence in depth, so a non-LunchStaff can never land here even if the
 * global guard is ever changed.
 *
 * The view is the ADMIN's JummahLunchView, unchanged. One board, one
 * implementation; the store picks the API prefix from the principal's type.
 */
const lunchRoutes: RouteRecordRaw[] = [
    {
        path: '/lunch',
        component: () => import("@/layouts/LunchLayout.vue"),
        meta: { auth: true, allowedUsers: ['LunchStaff'] },
        beforeEnter: () => {
            const authStore = useAuthStore();
            if (!authStore.isAuthenticated) {
                return '/auth/sign-in';
            }
            if (authStore.user?.type !== 'LunchStaff') {
                return '/auth/401';
            }
            return true;
        },
        children: [
            {
                path: '',
                name: 'lunchBoard',
                component: () => import("@/views/dashboard/JummahLunchView.vue"),
                meta: { pageTitle: 'Jummah Lunch' },
            },
        ],
    },
];

export default lunchRoutes;
