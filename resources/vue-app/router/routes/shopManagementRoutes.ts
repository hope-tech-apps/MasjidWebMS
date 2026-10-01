import { RouteRecordRaw } from "vue-router"

/**
 * The online shop (shop slice B3): the catalogue and the pickup list.
 *
 * `shop` is a PARENT record holding the four screens as children, so the sidebar's one "Shop" entry
 * stays lit on every one of them (a link is active when its record is in the current route's
 * `matched` list, and a sibling is not). The parent's component only renders the child; each
 * screen carries its own Products / Pickup list tabs. Visiting `/masjid/shop` itself lands on
 * Products.
 *
 * `requiresCapability: 'shop'`, not a module: the shop is OFF for every organisation until a
 * SuperAdmin grants it, and the server's `capability:shop` gate is the boundary (a SuperAdmin passes
 * it; the router only blocks once the organisation payload says it is not had). No `requiresCrm`:
 * the shop's routes sit outside the member directory on purpose, so a masjid selling uniforms need
 * not first switch it on. The server also asks `view donations` to read and `manage donations` to
 * write; the SPA has no per-person permission list, and both belong to every administrator
 * (SuperAdmin, MasjidAdmin), which is what `allowedUsers` says.
 */
const shopManagementRoutes: RouteRecordRaw[] = [
    {
        path: 'shop',
        component: () => import("@/views/dashboard/shop/ShopRoutes.vue"),
        redirect: '/masjid/shop/products',
        children: [
            {
                path: 'products',
                name: 'masjid.shop.products',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    requiresCapability: 'shop',
                    pageTitle: 'Shop'
                },
                component: () => import("@/views/dashboard/shop/ShopProductsView.vue")
            },
            {
                // Literal path ahead of the id wildcard; the id is digits only, so the two never meet.
                path: 'products/new',
                name: 'masjid.shop.productNew',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    requiresCapability: 'shop',
                    pageTitle: 'New Product'
                },
                component: () => import("@/views/dashboard/shop/ShopProductEditorView.vue")
            },
            {
                path: 'products/:productId(\\d+)/edit',
                name: 'masjid.shop.productEdit',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    requiresCapability: 'shop',
                    pageTitle: 'Edit Product'
                },
                component: () => import("@/views/dashboard/shop/ShopProductEditorView.vue")
            },
            {
                path: 'pickup',
                name: 'masjid.shop.pickup',
                meta: {
                    auth: true,
                    allowedUsers: ['SuperAdmin', 'MasjidAdmin'],
                    requiresCapability: 'shop',
                    pageTitle: 'Shop Pickup List'
                },
                component: () => import("@/views/dashboard/shop/ShopPickupView.vue")
            }
        ]
    }
]

export default shopManagementRoutes
