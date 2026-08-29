import { RouteRecordRaw } from "vue-router";
import { useAuthStore } from "@/stores/authStore";

/**
 * The teacher shell.
 *
 * A TOP-LEVEL route, deliberately not a child of AdminDashboardApp: a Teacher is
 * a scoped staff login with NO admin sidebar and no admin app chrome. Nesting it
 * under the admin app would drag the whole DashboardLayout (and its aside) in
 * front of every teacher screen.
 *
 * Unlike the parent portal, a Teacher shares the admin principal and token
 * (authStore), so this realm rides the SAME global guard: `meta.auth` +
 * `meta.allowedUsers: ['Teacher']` make router.beforeEach enforce sign-in and
 * user type. The `beforeEnter` below mirrors familyRoutes' defence-in-depth so a
 * non-Teacher can never land here even if the global guard is ever changed.
 */
const teacherRoutes: RouteRecordRaw[] = [
    {
        path: '/teacher',
        component: () => import("@/layouts/TeacherLayout.vue"),
        meta: { auth: true, allowedUsers: ['Teacher'] },
        beforeEnter: () => {
            const authStore = useAuthStore();
            if (!authStore.isAuthenticated) {
                return '/auth/sign-in';
            }
            if (authStore.user?.type !== 'Teacher') {
                return '/auth/401';
            }
            return true;
        },
        children: [
            {
                path: '',
                name: 'teacherClasses',
                component: () => import("@/views/teacher/TeacherClasses.vue"),
                meta: { pageTitle: 'My Classes' },
            },
            {
                path: 'classes/:groupId(\\d+)',
                name: 'teacherClass',
                component: () => import("@/views/teacher/TeacherClass.vue"),
                meta: { pageTitle: 'Class' },
            },
        ],
    },
];

export default teacherRoutes;
