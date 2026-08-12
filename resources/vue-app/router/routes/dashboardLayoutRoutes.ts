import { RouteRecordRaw } from "vue-router"
import announcementsManagementRoutes from "@/router/routes/announcementsManagementRoutes"
import servicesManagementRoutes from "@/router/routes/servicesManagementRoutes"
import generalDataManagementRoutes from "@/router/routes/generalDataManagementRoutes"
import eventsManagementRoutes from "@/router/routes/EventsManagementRoutes"
import pagesManagementRoutes from "@/router/routes/pagesManagementRoutes"
import splashAnnouncementsManagementRoutes from "@/router/routes/splashAnnouncementsManagementRoutes"
import appointmentsManagementRoutes from "@/router/routes/appointmentsManagementRoutes"
import offeringsManagementRoutes from "@/router/routes/offeringsManagementRoutes"

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
            {
                // Flyer Studio. No `requiresCrm` on purpose — making a flyer is
                // content authoring like forms and pages, not part of the CRM
                // money path, so it must not be hidden from masjids without CRM.
                path: 'flyers',
                name: 'masjid.flyers',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Flyer Studio'
                },
                component: () => import("@/views/dashboard/FlyerStudioView.vue")
            },
            {
                // Same view, opened on an existing draft. The Assistant's
                // draft_flyer tool hands the admin a link of exactly this shape,
                // so the path is part of that tool's contract.
                path: 'flyers/:flyer_id',
                name: 'masjid.flyerStudio',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Flyer Studio'
                },
                component: () => import("@/views/dashboard/FlyerStudioView.vue")
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
                // The tenant's groups. `pageTitle` is the vertical-neutral word
                // on purpose: the SCREEN names itself from the terminology pack
                // ("Classrooms" / "Halaqat" / "Teams") once the masjid payload
                // has loaded, and a route meta string cannot reach the store.
                path: 'groups',
                name: 'masjid.groups',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Groups',
                    requiresCrm: true
                },
                component: () => import("@/views/dashboard/GroupsView.vue")
            },
            {
                // Route NAME is load-bearing: the groups list links here by name.
                path: 'groups/:groupId',
                name: 'masjid.groupDetail',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Group Detail',
                    requiresCrm: true
                },
                component: () => import("@/views/dashboard/GroupDetailView.vue")
            },
            // The clinic's intake queue. Registered here, with the other
            // per-feature route files, because these are children of the
            // /masjid dashboard layout — see appointmentsManagementRoutes.ts.
            ...appointmentsManagementRoutes,
            // Offerings, their fee plans and their registrations. Sits between
            // the roster screens above and the giving screens below because it
            // spans both: an offering is a program structure over the member
            // directory, and its fee plans are prices. See
            // offeringsManagementRoutes.ts.
            ...offeringsManagementRoutes,
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
                // The overview: header totals, per-fund breakdown, and a filtered
                // feed that shares one filter contract with the CSV export, so the
                // dashboard and the accountant's file can never disagree.
                path: 'donations/dashboard',
                name: 'masjid.donationsDashboard',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Giving Dashboard',
                    requiresCrm: true
                },
                component: () => import("@/views/dashboard/DonationsDashboardView.vue")
            },
            {
                // Route NAME is load-bearing: the dashboard's per-fund
                // "view details" links here by name.
                path: 'donations/funds/:fundId',
                name: 'masjid.fundDetail',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Fund Detail',
                    requiresCrm: true
                },
                component: () => import("@/views/dashboard/FundDetailView.vue")
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
                path: 'properties',
                name: 'masjid.properties',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Properties & Rent',
                    requiresCrm: true
                },
                component: () => import("@/views/dashboard/PropertiesView.vue")
            },
            {
                path: 'form-responses',
                name: 'masjid.formResponses',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Form Responses'
                },
                component: () => import("@/views/dashboard/FormResponsesView.vue")
            },
            {
                path: 'assistant',
                name: 'masjid.assistant',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    pageTitle: 'Manara Assistant',
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
