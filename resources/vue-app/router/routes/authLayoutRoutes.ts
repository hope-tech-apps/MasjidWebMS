import { RouteRecordRaw } from "vue-router"

const authRoutes: RouteRecordRaw[] = [
    {
        path: '/auth',
        name: 'authLayout',
        redirect: '/auth/sign-in',
        component: () => import("@/layouts/AuthLayout.vue"),
        children: [
            {
                path: 'sign-in',
                name: 'signIn',
                component: () => import("@/views/auth/SignIn.vue"),
                meta: {
                    pageTitle: "Sign-In"
                }
            },
            {
                path: 'forgot-password',
                name: 'forgotPassword',
                component: () => import("@/views/auth/ForgotPassword.vue"),
                meta: {
                    pageTitle: "Forgot Password"
                }
            },
            {
                // Landed on from an emailed link carrying ?token=&email=.
                path: 'reset-password',
                name: 'resetPassword',
                component: () => import("@/views/auth/ResetPassword.vue"),
                meta: {
                    pageTitle: "Set Password"
                }
            },
            {
                path: 'dashboards',
                name: 'dashboards',
                component: () => import("@/views/super/DashboardsView.vue"),
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: "Dashboards"
                }
            },
            {
                path: '401',
                name: 'unauth',
                component: () => import("@/views/general/401Unauthorized.vue"),
                meta: {
                    pageTitle: "Unauthorized"
                }
            },
            {
                path: '404',
                name: 'notfound',
                component: () => import("@/views/general/404NotFound.vue"),
                meta: {
                    pageTitle: "Not Found"
                }
            }
        ]
    }
]

export default authRoutes