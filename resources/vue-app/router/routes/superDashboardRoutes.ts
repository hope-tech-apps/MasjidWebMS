import { RouteRecordRaw } from "vue-router";

const dashboardRoutes: RouteRecordRaw[] = [
    {
        path: '/dashboard/super',
        name: 'superAdminDashboard',
        component: () => import("@/layouts/DashboardLayout.vue"),
        meta: {
            auth: true
        },
        redirect: '/dashboard/super/users',
        children: [
            {
                path: 'masjids',
                name: 'masjids',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'Masjids Accounts',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/super/masjid/MasjidsView.vue")
            },
            {
                path: 'masjids/:masjid_id',
                name: 'masjids.details',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'Masjid Details',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/super/masjid/MasjidDetailsView.vue")
            },
            {
                path: 'masjids/create',
                name: 'masjids.create',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'Create Masjid',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/super/masjid/MasjidFormView.vue")
            },
            {
                path: 'masjids/:masjid_id/edit',
                name: 'masjids.edit',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'Edit Masjid',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/super/masjid/MasjidFormView.vue")
            },
            {
                path: 'users',
                name: 'users',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'Users Accounts',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/super/user/UsersView.vue")
            },
            {
                path: 'users/:user_id',
                name: 'users.details',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'User Details',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/super/user/UserDetailsView.vue")
            },
            {
                path: 'users/create',
                name: 'users.create',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'Create User',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/super/user/UserFormView.vue")
            },
            {
                path: 'users/:user_id/edit',
                name: 'users.edit',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'Edit User',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/super/user/UserFormView.vue")
            },
            {
                path: 'onboarding',
                name: 'masjid.onboarding',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'Onboard Masjid',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/super/OnboardingWizardView.vue")
            },
            {
                path: 'app-config',
                name: 'appConfig',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'App Version Control',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/super/AppConfigView.vue")
            },
            {
                path: 'profile',
                name: 'profile',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'My Profile',
                    dashboardType: 'super'
                },
                component: () => import("@/views/dashboard/ProfileView.vue")
            }
        ]
    }
]

export default dashboardRoutes