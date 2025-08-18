import { RouteRecordRaw } from "vue-router"

const announcementsManagementRoutes: RouteRecordRaw[] = [
    {
        path: 'announcements',
        name: 'masjid.announcements',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Announcements'
        },
        component: () => import("@/views/dashboard/announcements/AnnouncementsView.vue")
    },
    {
        path: 'announcements/create',
        name: 'masjid.announcements.create',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Create Announcement'
        },
        component: () => import("@/views/dashboard/announcements/AnnouncementFormView.vue")
    },
    {
        path: 'announcements/:announcement_id/edit',
        name: 'masjid.announcements.edit',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Edit Announcement'
        },
        component: () => import("@/views/dashboard/announcements/AnnouncementFormView.vue")
    },
    {
        path: 'announcements/:announcement_id',
        name: 'masjid.announcements.details',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Announcement Details'
        },
        component: () => import("@/views/dashboard/announcements/AnnouncementDetailsView.vue")
    }
]

export default announcementsManagementRoutes