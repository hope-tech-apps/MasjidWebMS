import { RouteRecordRaw } from "vue-router"

const broadcastsManagementRoutes: RouteRecordRaw[] = [
    {
        path: 'broadcasts',
        name: 'masjid.broadcasts',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Broadcasts'
        },
        component: () => import("@/views/dashboard/broadcasts/BroadcastsView.vue")
    },
    {
        path: 'broadcasts/compose',
        name: 'masjid.broadcasts.compose',
        meta: {
            auth: true,
            allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
            pageTitle: 'Compose Broadcast'
        },
        component: () => import("@/views/dashboard/broadcasts/BroadcastComposerView.vue")
    }
]

export default broadcastsManagementRoutes
