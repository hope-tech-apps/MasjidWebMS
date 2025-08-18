import { RouteRecordRaw } from "vue-router"

const servicesManagementRoutes: RouteRecordRaw[] = [
    {
        path: 'services',
        name: 'masjid.services',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Services'
        },
        component: () => import("@/views/dashboard/services/ServicesView.vue")
    },
    {
        path: 'services/create',
        name: 'masjid.services.create',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Create Service'
        },
        component: () => import("@/views/dashboard/services/ServiceFormView.vue")
    },
    {
        path: 'services/:service_id/edit',
        name: 'masjid.services.edit',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Edit Service'
        },
        component: () => import("@/views/dashboard/services/ServiceFormView.vue")
    },
    {
        path: 'services/:service_id',
        name: 'masjid.services.details',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Service Details'
        },
        component: () => import("@/views/dashboard/services/ServiceDetailsView.vue")
    },
]

export default servicesManagementRoutes