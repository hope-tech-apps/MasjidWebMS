import { createRouter, createWebHistory, RouteRecordRaw } from "vue-router";
import routes from "@/router/routes/routes";
import { useAuthStore } from "@/stores/authStore";
import { useDashboardAsideStore } from "@/stores/config/dashboardAsideStore";
import { MASJID_DASHBOARD_ASIDE_MENU, SUPER_DASHBOARD_ASIDE_MENU } from "@/core/constants/dashboardAsideMenuItems";
import { useMasjidStore } from "@/stores/masjidStore";
import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants";

const router = createRouter({
    history: createWebHistory(),
    routes: routes as RouteRecordRaw[]
})

router.beforeEach((to, from, next) => {

    if (to.meta.pageTitle) {
        document.title = `${to.meta.pageTitle} | ${import.meta.env.VITE_APP_NAME}`;
    } else {
        document.title = `${import.meta.env.VITE_APP_NAME}`;
    }

    // Stores
    const authStore = useAuthStore();
    const dashboardAsideStore = useDashboardAsideStore();
    const masjidStore = useMasjidStore();

    // next()
    if (!to.meta?.auth) {
        if (to.fullPath === '/auth/sign-in') {
            if (authStore.isAuthenticated) {
                if (authStore.user?.type === 'SuperAdmin') {
                    next('/auth/dashboards');
                } else if (authStore.user?.type === 'MasjidAdmin') {
                    next('/masjid');
                } else {
                    next('/auth/401');
                }
            } else {
                next();
            }
        } else {
            next();
        }
    } else {
        if (authStore.isAuthenticated) {
            // Set Dashboard Aside Menu Items
            if (to.meta.dashboardType === "super") {
                dashboardAsideStore.asideMenuItems = SUPER_DASHBOARD_ASIDE_MENU;
                masjidStore.masjid = null;
                authStore.dashboardMasjidId = null;
                localStorage.removeItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);
            } else {
                dashboardAsideStore.asideMenuItems = MASJID_DASHBOARD_ASIDE_MENU;
            }

            // Check
            if (Array.isArray(to.meta?.allowedUsers)) {
                if (authStore.user) {
                    if (to.meta.allowedUsers.includes(authStore.user.type)) {
                        // CRM-gated routes: only hard-block once the masjid payload is
                        // loaded and crm_enabled is explicitly false. While the masjid is
                        // still null/undefined (e.g. on first load / hard refresh) we let
                        // navigation through to avoid a race that would break the route.
                        if (to.meta.requiresCrm && masjidStore.masjid && !masjidStore.masjid.crm_enabled) {
                            next('/auth/401');
                        } else if (to.meta.requiresAssistant && masjidStore.masjid && !masjidStore.masjid.assistant_enabled) {
                            // Same shape as the CRM gate: only hard-block once we know the
                            // flag is false. The backend gate (EnsureAssistantEnabled) is
                            // the real boundary — this just avoids a dead screen.
                            next('/auth/401');
                        } else {
                            next();
                        }
                    } else {
                        next('/auth/401');
                    }
                } else {
                    next();
                }
            } else {
                next();
            }
        } else {
            next("/auth");
        }
    }

    document.documentElement.scroll({
        top: 0,
        left: 0,
        behavior: "smooth"
    });
})

export default router