import { RouteRecordRaw } from "vue-router"

const pagesManagementRoutes: RouteRecordRaw[] = [
    {
        path: 'pages',
        name: 'masjid.pages',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            // The page builder is an organisation capability (config/capabilities.php).
            requiresCapability: 'web_pages',
            pageTitle: 'Pages Management'
        },
        component: () => import("@/views/dashboard/pages/PagesView.vue")
    },
    {
        path: 'sections-library',
        name: 'masjid.sections-library',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            // The page builder is an organisation capability (config/capabilities.php).
            requiresCapability: 'web_pages',
            pageTitle: 'Sections Library'
        },
        component: () => import("@/views/dashboard/sections/SectionsLibraryView.vue")
    },
    {
        path: 'pages/:pageId/sections',
        name: 'masjid.pages.sections',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            // The page builder is an organisation capability (config/capabilities.php).
            requiresCapability: 'web_pages',
            pageTitle: 'Page Sections'
        },
        component: () => import("@/views/dashboard/pages/PageSectionsView.vue")
    },
]

export default pagesManagementRoutes

