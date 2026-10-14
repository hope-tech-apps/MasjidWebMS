import { RouteRecordRaw } from "vue-router";
import { useAuthStore } from "@/stores/authStore";
import TeacherApiService from "@/core/services/TeacherApiService";

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
                path: 'help/:book/:task?',
                component: () => import('@/views/guides/GuideScreen.vue'),
                props: { realm: 'teacher' },
                meta: { pageTitle: 'Help' },
            },
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
            {
                path: 'period-registers/classes/:classId(\\d+)',
                name: 'teacherClassPeriodAttendance',
                component: () => import('@/views/teacher/TeacherPeriodRegister.vue'),
                meta: { pageTitle: 'Class Attendance' },
            },
            {
                path: 'period-registers/:meetingId(\\d+)',
                name: 'teacherPeriodRegister',
                component: () => import('@/views/teacher/TeacherPeriodRegister.vue'),
                meta: { pageTitle: 'Period register' },
            },
            {
                // Read-only named meetings at the selected school.
                path: 'schedule',
                name: 'teacherSchedule',
                component: () => import("@/views/teacher/TeacherSchedule.vue"),
                meta: { pageTitle: 'My schedule' },
                beforeEnter: async () => {
                    // The self payload names the default school; this tab may have selected another.
                    const school = Number(useAuthStore().dashboardMasjidId);
                    if (!Number.isInteger(school) || school < 1) return '/auth/404';
                    try {
                        const response = await TeacherApiService.get(`/api/teacher/masjids/${school}/school`);
                        return response.data?.data?.school_timetable_enabled === true || '/auth/404';
                    } catch {
                        return '/auth/404';
                    }
                },
            },
            {
                // The office edits the calendar; a teacher reads it.
                path: 'calendar',
                name: 'teacherCalendar',
                component: () => import("@/views/teacher/TeacherCalendar.vue"),
                meta: { pageTitle: 'School Calendar' },
            },
        ],
    },
];

export default teacherRoutes;
