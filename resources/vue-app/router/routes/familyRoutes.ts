import { RouteRecordRaw } from "vue-router";
import { useFamilyStore } from "@/stores/familyStore";

/**
 * The parent portal.
 *
 * A TOP-LEVEL route, deliberately not a child of AdminDashboardApp: this realm
 * has a different principal (a Contact, not a User), a different guard, a
 * different token and no admin chrome. Nesting it under the admin app would put
 * the staff router guard — which reads authStore and redirects to /auth — in
 * front of every parent screen.
 *
 * The organisation is in the PATH because a parent arriving from an emailed link
 * has no session to infer it from, and the sign-in endpoints bind their tenant
 * from that id (routes/family.php: `family.guest`). It is an assertion the
 * caller makes; the API verifies it, and after sign-in the token — not the URL —
 * is what binds the tenant.
 */
const familyRoutes: RouteRecordRaw[] = [
    {
        path: '/family/:masjidId(\\d+)',
        component: () => import("@/layouts/FamilyLayout.vue"),
        children: [
            {
                path: 'sign-in',
                name: 'familySignIn',
                component: () => import("@/views/family/FamilySignIn.vue"),
                meta: { pageTitle: "Parent Sign In" },
            },
            {
                // The office's emailed link lands here. NOT behind the family
                // guard (no `meta.family`): a parent arriving from an invite has
                // no session yet — producing one is the entire job of this
                // screen — so guarding it would bounce them to the code-based
                // sign-in page, which is the friction the invite exists to
                // remove.
                //
                // Declared before the empty-path home route only for
                // readability; `/invite` and `''` cannot match the same URL.
                path: 'invite',
                name: 'familyInvite',
                component: () => import("@/views/family/FamilyInvite.vue"),
                meta: { pageTitle: "Parent Portal" },
            },
            {
                path: '',
                name: 'familyHome',
                component: () => import("@/views/family/FamilyHome.vue"),
                meta: { pageTitle: "Family Portal", family: true },
            },
            {
                // Child mode. NOT behind the family guard: its credential is the
                // student hand-off token, which deliberately fails every parent
                // surface, so requiring a parent session here would lock the
                // child out of the one screen meant for them.
                path: 'student/:groupId(\\d+)/:membershipId(\\d+)',
                name: 'studentMode',
                component: () => import("@/views/family/StudentMode.vue"),
                meta: { pageTitle: "My Avatar" },
            },
            {
                path: 'classes/:groupId(\\d+)',
                name: 'familyClass',
                component: () => import("@/views/family/FamilyClass.vue"),
                meta: { pageTitle: "Class", family: true },
            },
            {
                // Read-only, behind the parent guard like every other screen
                // that calls an authenticated family endpoint.
                path: 'calendar',
                name: 'familyCalendar',
                component: () => import("@/views/family/FamilyCalendar.vue"),
                meta: { pageTitle: "School Calendar", family: true },
            },
        ],
        beforeEnter: (to) => {
            if (!to.meta?.family) {
                return true;
            }

            const familyStore = useFamilyStore();

            // A token minted for a DIFFERENT organisation must not be used to
            // address this one. The API would refuse it anyway — `family.tenant`
            // binds from the token and 403s on a mismatch — but sending the
            // request at all is a realm confusion the client should not author.
            const signedInHere = familyStore.isSignedIn
                && String(familyStore.masjidId) === String(to.params.masjidId);

            return signedInHere ? true : `/family/${to.params.masjidId}/sign-in`;
        },
    },
];

export default familyRoutes;
