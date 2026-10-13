// This import used to import tailwind to the system
// import '../css/app.css'
import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
import 'bootstrap'
import '../css/custom.css'
// The staff theme, active only under body.mn-app (core/helpers/staffChrome.ts).
import '../css/custom/theme.css'
import { createApp } from 'vue'
import AdminDashboardApp from '@/AdminDashboardApp.vue'
import router from '@/router/router'
import pinia from '@/stores/index'
import ApiService from '@/core/services/ApiService'
import { useAuthStore } from '@/stores/authStore'
import { API_CONFIG, LOCAL_STORAGE_KEYS } from '@/core/constants/appConfigConstants'
import { useMasjidStore } from './stores/masjidStore'
import { useTenantSwitchStore } from '@/stores/tenantSwitchStore'
import VueTelInput from 'vue-tel-input';
import 'vue-tel-input/vue-tel-input.css';
import Swal from 'sweetalert2';
import DOMPurify from 'dompurify';
import { installSwalSanitizer, SWAL_PURIFY_CONFIG } from '@/core/plugins/swalSanitize';
import { startInAreaThatAnswered } from '@/core/helpers/staffAreas';
import { watchStaffAreaRefusals } from '@/core/tenancy/teacherSchoolGuard';

// Before anything can open a dialog: SweetAlert2 renders a title, a button caption and more as
// HTML, so every one of them is cleaned at the one door (core/plugins/swalSanitize.ts).
installSwalSanitizer(Swal, (dirty) => String(DOMPurify.sanitize(dirty, SWAL_PURIFY_CONFIG)));

const app = createApp({});
app.component('admin-dashboard', AdminDashboardApp);

// Set API Service COnfig
ApiService.init(app, API_CONFIG.base_url);

// The lunch board rides this client, which has no handling of a refusal. For a
// login that works in two areas (lunch staff here, a teacher somewhere else) a
// refusal on the board can be the lunch ROLE going while the session lives on in
// the other area; the guard asks, and reloads into the area that still has them.
// It does nothing for an administrator or for a login with one kind of role.
watchStaffAreaRefusals(ApiService.VueApp.axios);

// Use Pinia Stores
app.use(pinia);

// Check Authentication then Mount the App
(async () => {

    // Constants
    const TOKEN = localStorage.getItem(LOCAL_STORAGE_KEYS.token);
    const DASHBOARD_MASJID_ID = localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);
    // Read BEFORE the session is re-identified: fetchAuthUser() rewrites it, and
    // the last step below needs to know what it was when this page began to load.
    const STAFF_AREA = localStorage.getItem(LOCAL_STORAGE_KEYS.staff_area);

    // Stores
    const authStore = useAuthStore();
    const masjidStore = useMasjidStore();

    try {
        if (TOKEN) {
            ApiService.setHeader(TOKEN);
            await authStore.fetchAuthUser()
                .finally(async () => {

                    // Authenticate
                    authStore.token = TOKEN;
                    authStore.authenticate();

                    // Set auth related data: dashboardMasjidId
                    if (authStore.isAuthenticated) {
                        if (authStore.user?.type === 'SuperAdmin') {
                            authStore.dashboardMasjidId = DASHBOARD_MASJID_ID;
                        }

                        // S5: the stored organisation is a claim from a previous
                        // page life, and it outlives the thing that justified it.
                        // A membership can be revoked, a masjid trashed, an admin
                        // moved — and none of that clears localStorage. So the id
                        // is re-validated against the grants the server just
                        // returned, and dropped when it is no longer one of them.
                        //
                        // rehydrateSelection() returns false when there is nothing
                        // to validate against (no `memberships` in the payload: a
                        // SuperAdmin, or a backend older than S4), and then the
                        // original line below runs untouched — which is every
                        // request in production today.
                        const tenantSwitchStore = useTenantSwitchStore();

                        if (!tenantSwitchStore.rehydrateSelection(DASHBOARD_MASJID_ID) && DASHBOARD_MASJID_ID)
                            authStore.saveDashboardMasjidId(DASHBOARD_MASJID_ID);

                        await masjidStore.fetchMasjid();
                    }

                });
        } else {
            throw (new Error('Authentication Failed at Begining.'));
        }
    } catch (error: any) {
        console.log('Error: \n', error);
        ApiService.setHeader();
        authStore.removeAuth();
    } finally {

        // A login that works in two areas, loading a page in an area that no
        // longer has it (that role was taken away, and the other area answered):
        // start in the area that did answer, instead of on its "401 Unauthorized".
        //
        // BEFORE app.use(router), and awaited: see startInAreaThatAnswered for why
        // it is a navigation and why it has to finish first. It does nothing for
        // every login that was not in a two-area session on this browser: they
        // start where the address bar says, as they always did.
        await startInAreaThatAnswered(router, STAFF_AREA, window.location.pathname, authStore.user?.type, console.log);

        // Use Router
        app.use(router);

        // Use VueTelInput
        app.use(VueTelInput, {
            mode: 'international',
            autoFormat: true,
            autoDefaultCountry: false
        });
        
        // Mount
        app.mount('#app');
    }
})();