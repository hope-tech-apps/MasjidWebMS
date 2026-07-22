import { RouteRecordRaw } from "vue-router"
import announcementsManagementRoutes from "@/router/routes/announcementsManagementRoutes"
import servicesManagementRoutes from "@/router/routes/servicesManagementRoutes"
import generalDataManagementRoutes from "@/router/routes/generalDataManagementRoutes"
import eventsManagementRoutes from "@/router/routes/EventsManagementRoutes"
import pagesManagementRoutes from "@/router/routes/pagesManagementRoutes"
import splashAnnouncementsManagementRoutes from "@/router/routes/splashAnnouncementsManagementRoutes"

const dashboardRoutes: RouteRecordRaw[] = [
    {
        path: '/masjid',
        name: 'dashboardLayout',
        component: () => import("@/layouts/DashboardLayout.vue"),
        meta: {
            auth: true
        },
        redirect: '/masjid/details',
        children: [
            // {
            //     path: 'dashboard',
            //     name: 'masjid.dashboard',
            //     meta: {
            //         auth: true
            //     },
            //     component: () => import("@/views/dashboard/DashboardView.vue")
            // },
            {
                path: 'details',
                name: 'masjid.details',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Mosque Settings'
                },
                component: () => import("@/views/dashboard/MosqueDetailsTabsView.vue")
            },
            ...announcementsManagementRoutes,
            ...splashAnnouncementsManagementRoutes,
            ...eventsManagementRoutes,
            ...servicesManagementRoutes,
            ...pagesManagementRoutes,
            {
                path: 'donation',
                name: 'masjid.donation',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Donation'
                },
                component: () => import("@/views/dashboard/DonationView.vue")
            },
            {
                path: 'about',
                name: 'masjid.about',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'About Masjid'
                },
                component: () => import("@/views/dashboard/AboutUsView.vue")
            },
            {
                path: 'gallery',
                name: 'masjid.gallery',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Gallery'
                },
                component: () => import("@/views/dashboard/PhotoGalleryView.vue")
            },
            ...generalDataManagementRoutes,
            {
                path: 'iqama',
                name: 'masjid.iqama',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Iqama Settings'
                },
                component: () => import("@/views/dashboard/IqamaTimeSettingsView.vue")
            },
            {
                path: 'jumaa',
                name: 'masjid.jumaa',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Jumaa Settings'
                },
                component: () => import("@/views/dashboard/JumaaSettingsView.vue")
            },
            {
                path: 'notifications',
                name: 'masjid.notifications',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Notifications'
                },
                component: () => import("@/views/dashboard/NotificationFormView.vue")
            },
            {
                path: 'contact-requests',
                name: 'masjid.contactRequests',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Contact Requests'
                },
                component: () => import("@/views/dashboard/ContactRequestsView.vue")
            },
            {
                path: 'contacts',
                name: 'masjid.contacts',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Member Directory',
                    requiresCrm: true
                },
                component: () => import("@/views/dashboard/ContactsView.vue")
            },
            {
                path: 'funds',
                name: 'masjid.funds',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Donation Funds',
                    requiresCrm: true
                },
                component: () => import("@/views/dashboard/FundsView.vue")
            },
            {
                path: 'donations',
                name: 'masjid.donations',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Donations',
                    requiresCrm: true
                },
                component: () => import("@/views/dashboard/DonationsView.vue")
            },
            {
                path: 'recurring-donations',
                name: 'masjid.recurringDonations',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Recurring Donations',
                    requiresCrm: true
                },
                component: () => import("@/views/dashboard/RecurringDonationsView.vue")
            },
            {
                path: 'annual-statements',
                name: 'masjid.annualStatements',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Year-End Statements',
                    requiresCrm: true
                },
                component: () => import("@/views/dashboard/AnnualStatementsView.vue")
            },
            {
                path: 'assistant',
                name: 'masjid.assistant',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Masjid Assistant',
                    requiresAssistant: true
                },
                component: () => import("@/views/dashboard/AssistantView.vue")
            },
            {
                path: 'mobile-features',
                name: 'masjid.mobileFeatures',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin'],
                    pageTitle: 'Mobile Features'
                },
                component: () => import("@/views/dashboard/super/MobileAppFeaturesControlView.vue")
            },
            {
                path: 'admin/profile',
                name: 'masjid.adminProfile',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Admin Profile'
                },
                component: () => import("@/views/dashboard/ProfileView.vue")
            }
        ]
    }
]

export default dashboardRoutes
