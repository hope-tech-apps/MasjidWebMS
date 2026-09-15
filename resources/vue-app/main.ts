// This import used to import tailwind to the system
// import '../css/app.css'
import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
import 'bootstrap'
import '../css/custom.css'
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

const app = createApp({});
app.component('admin-dashboard', AdminDashboardApp);

// Set API Service COnfig
ApiService.init(app, API_CONFIG.base_url);

// Use Pinia Stores
app.use(pinia);

// Check Authentication then Mount the App
(async () => {

    // Constants
    const TOKEN = localStorage.getItem(LOCAL_STORAGE_KEYS.token);
    const DASHBOARD_MASJID_ID = localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);

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