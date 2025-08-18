import { RouteRecordRaw } from "vue-router"

const eventsManagementRoutes: RouteRecordRaw[] = [
    {
        path: 'events',
        name: 'masjid.events',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Events'
        },
        component: () => import("@/views/dashboard/events/EventsView.vue")
    },
    {
        path: 'events/create',
        name: 'masjid.events.create',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Create Event'
        },
        component: () => import("@/views/dashboard/events/EventFormView.vue")
    },
    {
        path: 'events/:event_id/edit',
        name: 'masjid.events.edit',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Edit Event'
        },
        component: () => import("@/views/dashboard/events/EventFormView.vue")
    },
    {
        path: 'events/:event_id',
        name: 'masjid.events.details',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Event Details'
        },
        component: () => import("@/views/dashboard/events/EventDetailsView.vue")
    }
]

export default eventsManagementRoutes