import { RouteRecordRaw } from "vue-router";

/**
 * The PUBLIC Jummah-lunch ordering pages — top-level, like the family portal and
 * the Stripe donation result pages: no auth, no admin chrome, no router guard.
 *
 * The organisation is in the PATH because a hungry visitor arrives from a link
 * or a QR code with no session to infer it from; the page sends that id as the
 * `masjid-id` header the public /api/v1 endpoints expect. The order page hands
 * off to Stripe's hosted checkout for online orders, and the /order/:uuid page
 * doubles as Stripe's success/cancel return target.
 */
const jummahLunchRoutes: RouteRecordRaw[] = [
    {
        path: "/jummah-lunch/:masjidId(\\d+)",
        name: "jummahLunchOrder",
        component: () => import("@/views/lunch/LunchOrderPage.vue"),
        meta: { pageTitle: "Jummah Lunch" },
    },
    {
        path: "/jummah-lunch/:masjidId(\\d+)/order/:uuid",
        name: "jummahLunchOrderStatus",
        component: () => import("@/views/lunch/LunchOrderStatus.vue"),
        meta: { pageTitle: "Your Order" },
    },
];

export default jummahLunchRoutes;
