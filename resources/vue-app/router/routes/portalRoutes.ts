import { RouteRecordRaw } from "vue-router";

/**
 * The per-school front door.
 *
 * TOP-LEVEL, and deliberately NOT a child of `/family/:masjidId`, for two
 * reasons that are both visible in the files it would otherwise inherit:
 *
 *  1. `FamilyLayout.vue` stamps `/manara-icon.svg` into its navbar on every
 *     child screen. This is the one page that must carry the SCHOOL's mark and
 *     not the vendor's.
 *  2. `familyRoutes.ts` has a `beforeEnter` that bounces any unauthenticated
 *     visit to `/family/:id/sign-in` — the PARENT door. A teacher following the
 *     school's own portal link would be shown a parent form and no way out.
 *
 * No auth guard of any kind: this page is the thing you see BEFORE you have a
 * credential, and it holds nothing but a name, a logo and two links.
 *
 * A non-numeric id never matches and falls through to the existing catch-all.
 */
const portalRoutes: RouteRecordRaw[] = [
    {
        path: '/portal/:masjidId(\\d+)',
        name: 'orgPortal',
        component: () => import("@/views/portal/OrgPortal.vue"),
        meta: { pageTitle: "Portal" },
    },
    {
        /**
         * The same page, WITHOUT the id in the path.
         *
         * This is the address as a school's own domain serves it —
         * alrazischool.org/portal rewrites to this app, so the browser's path is
         * `/portal` and there is no id to read. The organisation comes instead
         * from `window.__PORTAL_MASJID__`, injected by the proxy that knows
         * which school's domain the request arrived on.
         *
         * Declared AFTER the id form so a real `/portal/14` still matches that
         * one, and kept as a separate record rather than making the param
         * optional: an optional param would also match `/portal/` and quietly
         * render an id-less page on this app's own domain, where no proxy has
         * injected anything and there is nothing to fall back to.
         */
        path: '/portal',
        name: 'orgPortalHosted',
        component: () => import("@/views/portal/OrgPortal.vue"),
        meta: { pageTitle: "Portal" },
    },
];

export default portalRoutes;
