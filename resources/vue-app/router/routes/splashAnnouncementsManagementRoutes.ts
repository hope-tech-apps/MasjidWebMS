import { RouteRecordRaw } from "vue-router"

const splashAnnouncementsManagementRoutes: RouteRecordRaw[] = [
    {
        path: 'splash-announcements',
        name: 'masjid.splashAnnouncements',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Splash Announcements',
            requiresModule: 'splash'
        },
        component: () => import("@/views/dashboard/splash-announcements/SplashAnnouncementsView.vue")
    },
    {
        path: 'splash-announcements/create',
        name: 'masjid.splashAnnouncements.create',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Create Splash Announcement',
            requiresModule: 'splash'
        },
        component: () => import("@/views/dashboard/splash-announcements/SplashAnnouncementFormView.vue")
    },
    {
        path: 'splash-announcements/:splash_id/edit',
        name: 'masjid.splashAnnouncements.edit',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Edit Splash Announcement',
            requiresModule: 'splash'
        },
        component: () => import("@/views/dashboard/splash-announcements/SplashAnnouncementFormView.vue")
    },
    {
        path: 'splash-announcements/:splash_id',
        name: 'masjid.splashAnnouncements.details',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Splash Announcement Details',
            requiresModule: 'splash'
        },
        component: () => import("@/views/dashboard/splash-announcements/SplashAnnouncementDetailsView.vue")
    }
]

export default splashAnnouncementsManagementRoutes
