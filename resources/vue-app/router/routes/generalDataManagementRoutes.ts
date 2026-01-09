import { RouteRecordRaw } from "vue-router"

const generalDataManagementRoutes: RouteRecordRaw[] = [
    {
        path: '/hadith',
        name: 'hadith',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Hadiths'
        },
        component: () => import("@/views/dashboard/hadith/HadithsView.vue")
    },
    {
        path: '/hadith/:hadith_id',
        name: 'hadith.details',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Hadith Details'
        },
        component: () => import("@/views/dashboard/hadith/HadithsDetailsView.vue")
    },
    {
        path: '/hadith/:hadith_id/edit',
        name: 'hadith.edit',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Edit Hadith'
        },
        component: () => import("@/views/dashboard/hadith/HadithFormView.vue")
    },
    {
        path: '/hadith/create',
        name: 'hadith.create',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Create Hadith'
        },
        component: () => import("@/views/dashboard/hadith/HadithFormView.vue")
    },
    {
        path: '/azkar',
        name: 'adhkar',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Azkar'
        },
        component: () => import("@/views/dashboard/azkar/AzkarView.vue")
    },
    {
        path: '/azkar/:zikr_id',
        name: 'azkar.details',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Zikr Details'
        },
        component: () => import("@/views/dashboard/azkar/AzkarDetailsView.vue")
    },
    {
        path: '/azkar/:zikr_id/edit',
        name: 'azkar.edit',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Edit Hadith'
        },
        component: () => import("@/views/dashboard/azkar/AzkarFormView.vue")
    },
    {
        path: '/azkar/create',
        name: 'azkar.create',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Create Hadith'
        },
        component: () => import("@/views/dashboard/azkar/AzkarFormView.vue")
    },
    {
        path: '/tasabih',
        name: 'tasabih',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Tasabih'
        },
        component: () => import("@/views/dashboard/tasabih/TasabihView.vue")
    },
    {
        path: '/tasabih/:tasbih_id',
        name: 'tasabih.details',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Tasbih Details'
        },
        component: () => import("@/views/dashboard/tasabih/TasabihDetailsView.vue")
    },
    {
        path: '/tasabih/:tasbih_id/edit',
        name: 'tasabih.edit',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Edit Tasabih'
        },
        component: () => import("@/views/dashboard/tasabih/TasabihFormView.vue")
    },
    {
        path: '/tasabih/create',
        name: 'tasabih.create',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Create Tasabih'
        },
        component: () => import("@/views/dashboard/tasabih/TasabihFormView.vue")
    }
]

export default generalDataManagementRoutes
