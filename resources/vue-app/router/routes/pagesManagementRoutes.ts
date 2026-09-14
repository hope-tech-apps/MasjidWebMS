import { RouteRecordRaw } from "vue-router"

const pagesManagementRoutes: RouteRecordRaw[] = [
    {
        path: 'pages',
        name: 'masjid.pages',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            // The page builder: the organisation's own admins need the `web_pages`
            // grant, and nobody (SuperAdmins included) reaches it once the
            // `website` module is switched off (config/capabilities.php).
            requiresCapability: 'web_pages',
            requiresModule: 'website',
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
            // The page builder: the organisation's own admins need the `web_pages`
            // grant, and nobody (SuperAdmins included) reaches it once the
            // `website` module is switched off (config/capabilities.php).
            requiresCapability: 'web_pages',
            requiresModule: 'website',
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
            // The page builder: the organisation's own admins need the `web_pages`
            // grant, and nobody (SuperAdmins included) reaches it once the
            // `website` module is switched off (config/capabilities.php).
            requiresCapability: 'web_pages',
            requiresModule: 'website',
            pageTitle: 'Page Sections'
        },
        component: () => import("@/views/dashboard/pages/PageSectionsView.vue")
    },
]

export default pagesManagementRoutes

