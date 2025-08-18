<template>
    <header id="dashboard_header">
        <div class="d-flex flex-wrap flex-md-nowrap align-items-center gap-4 justify-content-between">
            <!-- Toggle Aside Button -->
            <button id="dashboard_aside_toggle_btn" type="button" class="aside-toggle-btn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-list aside-toggle-icon"
                    viewBox="0 0 16 16">
                    <path fill-rule="evenodd"
                        d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5" />
                </svg>
            </button>
            <div
                class="d-flex flex-column flex-md-row flex-end flex-md-start align-items-center gap-3 justify-content-between w-100">
                <!-- First Element - Title & Button -->
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <div class="fs-4 fw-semibold">
                        <span v-if="route.meta?.dashboardType !== 'super'">
                            {{ masjidStore.masjid?.name }}
                        </span>
                        <span v-else>
                            Super Dashboard
                        </span>
                    </div>
                    <button id="refresh_button" @click.prevent="reloadPage()" title="reload page"
                        class="btn btn-sm btn-icon btn-success">
                        <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M28 6.00009V12.0001C28 12.2653 27.8946 12.5197 27.7071 12.7072C27.5196 12.8947 27.2652 13.0001 27 13.0001H21C20.7348 13.0001 20.4804 12.8947 20.2929 12.7072C20.1054 12.5197 20 12.2653 20 12.0001C20 11.7349 20.1054 11.4805 20.2929 11.293C20.4804 11.1054 20.7348 11.0001 21 11.0001H24.5863L22.7575 9.17134C20.899 7.30435 18.3756 6.25103 15.7413 6.24259H15.685C13.0727 6.23647 10.563 7.25894 8.69875 9.08884C8.50777 9.26712 8.25462 9.36358 7.99343 9.35759C7.73224 9.3516 7.48377 9.24363 7.30118 9.05678C7.11858 8.86994 7.01635 8.61905 7.01636 8.3578C7.01638 8.09654 7.11863 7.84567 7.30125 7.65884C9.56066 5.45059 12.5998 4.22244 15.759 4.24093C18.9183 4.25942 21.9428 5.52305 24.1763 7.75759L26 9.58634V6.00009C26 5.73488 26.1054 5.48052 26.2929 5.29299C26.4804 5.10545 26.7348 5.00009 27 5.00009C27.2652 5.00009 27.5196 5.10545 27.7071 5.29299C27.8946 5.48052 28 5.73488 28 6.00009ZM23.3013 22.9113C21.4185 24.7504 18.8867 25.7731 16.2549 25.7576C13.6231 25.7422 11.1035 24.6899 9.2425 22.8288L7.41375 21.0001H11C11.2652 21.0001 11.5196 20.8947 11.7071 20.7072C11.8946 20.5197 12 20.2653 12 20.0001C12 19.7349 11.8946 19.4805 11.7071 19.293C11.5196 19.1054 11.2652 19.0001 11 19.0001H5C4.73478 19.0001 4.48043 19.1054 4.29289 19.293C4.10536 19.4805 4 19.7349 4 20.0001V26.0001C4 26.2653 4.10536 26.5197 4.29289 26.7072C4.48043 26.8947 4.73478 27.0001 5 27.0001C5.26522 27.0001 5.51957 26.8947 5.70711 26.7072C5.89464 26.5197 6 26.2653 6 26.0001V22.4138L7.82875 24.2426C10.059 26.4841 13.088 27.7484 16.25 27.7576H16.3162C19.4514 27.7657 22.4634 26.5383 24.7 24.3413C24.8826 24.1545 24.9849 23.9036 24.9849 23.6424C24.9849 23.3811 24.8827 23.1302 24.7001 22.9434C24.5175 22.7566 24.269 22.6486 24.0078 22.6426C23.7466 22.6366 23.4935 22.7331 23.3025 22.9113H23.3013Z"
                                fill="white" />
                        </svg>
                    </button>
                </div>

                <!-- Second Element - Search & User Menu -->
                <div
                    class="d-flex flex-column flex-sm-row flex-column-reverse align-items-center justify-content-end gap-4">

                    <!-- Search -->
                    <div class="d-flex align-items-center justify-content-start gap-2 input search-container">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M11.5 21C16.7467 21 21 16.7467 21 11.5C21 6.25329 16.7467 2 11.5 2C6.25329 2 2 6.25329 2 11.5C2 16.7467 6.25329 21 11.5 21Z"
                                stroke="#828282" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M22 22L20 20" stroke="#828282" stroke-width="1.5" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        <Field type="text" name="searchFieldValue" v-model="searchValue" class="search-field"
                            placeholder="Search anything here..." aria-autocomplete="none">
                        </Field>
                        <button v-if="searchValue?.length" type="button" @click.prevent="clearSearchResults"
                            class="btn btn-sm btn-icon btn-light-success btn-rounded rounded-3 align-self-end close-search-results-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor"
                                class="bi bi-x-lg aside-toggle-icon" viewBox="0 0 16 16">
                                <path
                                    d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z" />
                            </svg>
                        </button>
                        <div v-if="searchResults.length"
                            class="d-flex flex-column gap-2 shadow rounded-2 search-results-container">
                            <template v-for="result in searchResults">
                                <router-link :to="result.url" @click="clearSearchResults" class="search-result">
                                    <template v-if="result.data">
                                        <span v-if="'title' in result.data">
                                            {{ result.data.title }}
                                        </span>
                                        <span v-if="'name' in result.data">
                                            {{ result.data.name }}
                                        </span>
                                        <span v-else-if="'details' in result.data">
                                            {{ result.data.details }}
                                        </span>
                                        <span v-else-if="'description' in result.data">
                                            {{ result.data.description }}
                                        </span>
                                        <span v-else>
                                            {{ result.title }}
                                        </span>
                                    </template>
                                    <span v-else>
                                        {{ result.title }}
                                    </span>
                                </router-link>
                            </template>
                        </div>
                    </div>

                    <!-- Account Dropdown Menu -->
                    <div class="btn-group">
                        <div class="dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            <div class="account-dropdown-avatar">
                                <img :src="authStore.user?.avatar.original_url" alt="user" class="avatar-img">
                            </div>
                        </div>
                        <ul class="dropdown-menu">
                            <li>
                                <span @click.prevent="(event: Event) => { event.stopPropagation() }"
                                    class="dropdown-item email-text">
                                    {{ authStore.user?.email }}
                                </span>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li v-if="authStore.user?.type === 'SuperAdmin'">
                                <button @click.prevent="goToSuperDashboard" class="dropdown-item">
                                    Super Dashboard
                                </button>
                                <router-link to="/auth/dashboards" class="dropdown-item">Dashboards</router-link>
                            </li>
                            <li v-if="authStore.user?.type === 'MasjidAdmin'">
                                <router-link :to="{ name: 'masjid.adminProfile' }"
                                    class="dropdown-item">Profile</router-link>
                            </li>
                            <li v-if="authStore.user?.type === 'SuperAdmin'">
                                <router-link :to="{ name: 'profile' }" class="dropdown-item">My Profile</router-link>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li>
                                <button type="button" @click.prevent="logout"
                                    class="dropdown-item d-flex align-items-center justify-content-centr gap-2">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M11.25 20.25C11.25 20.4489 11.171 20.6397 11.0303 20.7803C10.8897 20.921 10.6989 21 10.5 21H4.5C4.30109 21 4.11032 20.921 3.96967 20.7803C3.82902 20.6397 3.75 20.4489 3.75 20.25V3.75C3.75 3.55109 3.82902 3.36032 3.96967 3.21967C4.11032 3.07902 4.30109 3 4.5 3H10.5C10.6989 3 10.8897 3.07902 11.0303 3.21967C11.171 3.36032 11.25 3.55109 11.25 3.75C11.25 3.94891 11.171 4.13968 11.0303 4.28033C10.8897 4.42098 10.6989 4.5 10.5 4.5H5.25V19.5H10.5C10.6989 19.5 10.8897 19.579 11.0303 19.7197C11.171 19.8603 11.25 20.0511 11.25 20.25ZM21.5306 11.4694L17.7806 7.71937C17.6399 7.57864 17.449 7.49958 17.25 7.49958C17.051 7.49958 16.8601 7.57864 16.7194 7.71937C16.5786 7.86011 16.4996 8.05098 16.4996 8.25C16.4996 8.44902 16.5786 8.63989 16.7194 8.78063L19.1897 11.25H10.5C10.3011 11.25 10.1103 11.329 9.96967 11.4697C9.82902 11.6103 9.75 11.8011 9.75 12C9.75 12.1989 9.82902 12.3897 9.96967 12.5303C10.1103 12.671 10.3011 12.75 10.5 12.75H19.1897L16.7194 15.2194C16.5786 15.3601 16.4996 15.551 16.4996 15.75C16.4996 15.949 16.5786 16.1399 16.7194 16.2806C16.8601 16.4214 17.051 16.5004 17.25 16.5004C17.449 16.5004 17.6399 16.4214 17.7806 16.2806L21.5306 12.5306C21.6004 12.461 21.6557 12.3783 21.6934 12.2872C21.7312 12.1962 21.7506 12.0986 21.7506 12C21.7506 11.9014 21.7312 11.8038 21.6934 11.7128C21.6557 11.6217 21.6004 11.539 21.5306 11.4694Z"
                                            fill="#6B6C6F" />
                                    </svg>
                                    <span>Logout</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </header>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Field } from 'vee-validate';
import { useAuthStore } from '@/stores/authStore';
import { useRoute, useRouter } from 'vue-router';
import { useMasjidStore } from '@/stores/masjidStore';
import { useDashboardSearchStore } from '@/stores/dashboardSearchStore';
import { DashboardSearchResultData, DashboardSearchResultRecord, GENERAL_DASHBOARD_ROUTES_RESULTS, MASJID_DASHBOARD_ROUTES_RESULTS, SUPER_DASHBOARD_ROUTES_RESULTS } from '@/core/types/data/custom/DashboardSearch';

// Lifecycle hooks
onMounted(() => {
    asideToggleButton.value = document.getElementById('dashboard_aside_toggle_btn')
    if (asideToggleButton.value) {
        asideToggleButton.value.addEventListener('click', () => {
            dashboardLayout.value = document.getElementById('dashboard_layout')
            if (dashboardLayout.value) {
                dashboardLayout.value.classList.toggle('aside-hidden')
            }
        })
    }
})

// Routing
const router = useRouter();
const route = useRoute();

// Html refs
const dashboardLayout = ref<HTMLElement | null>();
const asideToggleButton = ref<HTMLElement | null>();

// Stores
const authStore = useAuthStore();
const masjidStore = useMasjidStore();
const searchStore = useDashboardSearchStore();

// Custom constants
let debounceTimer: number;
const searchValue = ref<string>();
const masjidResultsTemp = ref<DashboardSearchResultData>();
const superResultsTemp = ref<DashboardSearchResultData>();
const masjidDashboardSearchResults = ref<DashboardSearchResultRecord[]>([]);
const superDashboardSearchResults = ref<DashboardSearchResultRecord[]>([]);
const frontendSearchResults = ref<DashboardSearchResultRecord[]>([]);

const searchResults = computed(() => {
    return [
        ...masjidDashboardSearchResults.value,
        ...superDashboardSearchResults.value,
        ...frontendSearchResults.value
    ];
})

// watch
watch(searchValue, async () => {
    clearTimeout(debounceTimer)
    debounceTimer = window.setTimeout(async () => {
        if (searchValue.value && searchValue.value?.length > 2) {

            frontendSearchResults.value = [];

            if (masjidStore.masjid?.id) {
                await searchStore.fetchMasjidSearchData(searchValue.value, masjidResultsTemp).finally(() => {
                    if (masjidResultsTemp.value)
                        masjidDashboardSearchResults.value = searchStore.mapResultsDataRecords(masjidResultsTemp.value);
                });
                frontendSearchResults.value.push(...MASJID_DASHBOARD_ROUTES_RESULTS.filter(obj => {
                    return obj.title.toLowerCase().includes(searchValue.value?.toLowerCase() as string);
                }));
                frontendSearchResults.value.push(...GENERAL_DASHBOARD_ROUTES_RESULTS.filter(obj => {
                    return obj.title.toLowerCase().includes(searchValue.value?.toLowerCase() as string);
                }));
            }

            if (authStore.user?.type === 'SuperAdmin') {
                await searchStore.fetchSuperSearchData(searchValue.value, superResultsTemp).finally(() => {
                    if (superResultsTemp.value)
                        superDashboardSearchResults.value = searchStore.mapResultsDataRecords(superResultsTemp.value);
                });
                frontendSearchResults.value.push(...SUPER_DASHBOARD_ROUTES_RESULTS.filter(obj => {
                    return obj.title.toLowerCase().includes(searchValue.value?.toLowerCase() as string);
                }));
            }

        }
    }, 500);
});

// Functions
function logout() {
    authStore.logout()
        .finally(() => {
            router.push('/auth/sign-in');
        });
}

function reloadPage() {
    location.reload();
}

const clearSearchResults = () => {
    masjidDashboardSearchResults.value = [];
    superDashboardSearchResults.value = [];
    frontendSearchResults.value = [];
    searchValue.value = '';
}

const goToSuperDashboard = () => {
    // dashboardAsideStore.asideMenuItems = SUPER_DASHBOARD_ASIDE_MENU;
    // masjidStore.masjid = null;
    // authStore.dashboardMasjidId = null;
    router.push('/dashboard/super');
}

</script>

<style scoped>
#refresh_button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 50%;
}

#refresh_button svg {
    width: 100% !important;
    height: 100% !important;
}

.input {
    display: flex;
    width: auto;
    background-color: white;
    border: none !important;
    border-radius: .5rem;
    padding: .5rem 1.25rem;
}

.input svg {
    width: 1.25rem;
}

.search-field {
    border: none;
    background-color: white;
}

.search-field:focus {
    border: none;
    outline: none;
}

.search-field::placeholder {
    font-size: small;
    color: var(--muted-gray);
}

.search-container {
    position: relative;
}

/* .close-search-results-btn {} */

.search-results-container {
    position: absolute;
    top: calc(100% + .5rem);
    width: 100%;
    z-index: 1050;
    padding: 1rem;
    right: 0;
    background-color: white;
    max-height: calc(100vh - 10rem);
    overflow: auto;
}

.search-results-container .search-result {
    text-wrap: word;
    text-decoration: none;
    padding: .5rem 1rem;
    border-radius: .5rem;
    color: var(--cgreen-active)
}

.search-results-container .search-result:hover {
    background-color: var(--input-border);
}

.dropdown-toggle {
    cursor: pointer;
}

.dropdown-menu,
.dropdown-divider {
    border-color: var(--lighted-gray);
}

.account-dropdown-avatar {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 50%;
    overflow: hidden;
    object-fit: cover;
}

.avatar-img {
    height: 100%;
}

.dropdown-item {
    cursor: pointer;
    --bs-dropdown-link-active-bg: var(--cgreen-active);
}

.dropdown-item:focus {
    background-color: var(--cgreen-active) !important;
}

.dropdown-menu .dropdown-item:focus svg path {
    fill: white !important;
}

.dropdown-item.email-text {
    cursor: text !important;
    --bs-dropdown-link-active-bg: white !important;
    --bs-dropdown-link-active-color: color: var(--muted-gray) !important;
}
</style>