<template>
    <header id="dashboard_header">
        <div class="mn-header">
            <!-- Toggle Aside Button -->
            <button id="dashboard_aside_toggle_btn" type="button" class="aside-toggle-btn" aria-label="Show or hide the menu">
                <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-list aside-toggle-icon"
                    viewBox="0 0 16 16" aria-hidden="true">
                    <path fill-rule="evenodd"
                        d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5" />
                </svg>
            </button>

            <div class="mn-header-main">
                <!-- First Element - Title & Button -->
                <div class="mn-header-title">
                    <div class="mn-header-org">
                        <!--
                            The organisation the SERVER bound, not the one this
                            tab asked for (S5 of docs/multi-tenant-admin-design.md).
                            For every principal the server sends no memberships
                            for — a SuperAdmin, any backend older than S4 —
                            `chromeOrgName` IS `masjidStore.masjid?.name`, the
                            expression that used to be written here.
                        -->
                        <template v-if="route.meta?.dashboardType !== 'super'">
                            <span class="mn-header-eyebrow">{{ masjidStore.organizationLabel }}</span>
                            <span class="mn-header-name">{{ tenantSwitchStore.chromeOrgName }}</span>
                        </template>
                        <template v-else>
                            <span class="mn-header-eyebrow">Manara</span>
                            <span class="mn-header-name">Super Dashboard</span>
                        </template>
                    </div>

                    <!-- Nothing at all for an account with one organisation. -->
                    <OrgSwitcher v-if="route.meta?.dashboardType !== 'super'" />
                    <button id="refresh_button" type="button" @click.prevent="reloadPage()" title="Reload page"
                        aria-label="Reload page" class="mn-icon-btn">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                            stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 11a8 8 0 0 0-14.7-4.4L4 8" /><path d="M4 4v4h4" />
                            <path d="M4 13a8 8 0 0 0 14.7 4.4L20 16" /><path d="M20 20v-4h-4" />
                        </svg>
                    </button>
                </div>

                <!-- Second Element - Search & User Menu -->
                <div class="mn-header-actions">

                    <!-- Search -->
                    <div class="search-container mn-search">
                        <svg class="mn-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
                        </svg>
                        <Field id="dashboard_search_input" type="text" name="searchFieldValue" v-model="searchValue"
                            class="search-field" placeholder="Search anything…" aria-label="Search"
                            aria-autocomplete="none" autocomplete="off" @keydown.esc="clearSearchResults">
                        </Field>
                        <kbd v-if="!searchValue?.length" class="mn-search-kbd" aria-hidden="true">{{ shortcutLabel }}</kbd>
                        <button v-if="searchValue?.length" type="button" @click.prevent="clearSearchResults"
                            aria-label="Clear search" title="Clear search"
                            class="mn-search-clear close-search-results-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" width="12" height="12"
                                viewBox="0 0 16 16" aria-hidden="true">
                                <path
                                    d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z" />
                            </svg>
                        </button>
                        <div v-if="searchResults.length"
                            class="d-flex flex-column gap-1 search-results-container">
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
                        <button type="button" class="dropdown-toggle mn-account" data-bs-toggle="dropdown"
                            aria-expanded="false" :aria-label="`Account menu for ${displayName}`">
                            <span class="account-dropdown-avatar">
                                <img v-if="avatarUrl && !avatarFailed" :src="avatarUrl" alt="" class="avatar-img"
                                    @error="avatarFailed = true">
                                <span v-else class="mn-avatar-initials">{{ initials }}</span>
                            </span>
                            <span class="mn-account-text">
                                <span class="mn-account-name">{{ displayName }}</span>
                                <span class="mn-account-role">{{ roleLabel }}</span>
                            </span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
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
                                    class="dropdown-item d-flex align-items-center gap-2">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M10 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5" /><path d="M16 16l4-4-4-4" /><path d="M20 12H10" />
                                    </svg>
                                    <span>Sign out</span>
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
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Field } from 'vee-validate';
import { useAuthStore } from '@/stores/authStore';
import { useRoute, useRouter } from 'vue-router';
import { useMasjidStore } from '@/stores/masjidStore';
import { useTenantSwitchStore } from '@/stores/tenantSwitchStore';
import { useDashboardSearchStore } from '@/stores/dashboardSearchStore';
import OrgSwitcher from '@/components/dashboard/OrgSwitcher.vue';
import { DashboardSearchResultData, DashboardSearchResultRecord, GENERAL_DASHBOARD_ROUTES_RESULTS, MASJID_DASHBOARD_ROUTES_RESULTS, SUPER_DASHBOARD_ROUTES_RESULTS } from '@/core/types/data/custom/DashboardSearch';
import { itemFitsOrgType, moduleIsOff } from '@/core/access/orgAccess';
import { MASJID_DASHBOARD_ASIDE_MENU } from '@/core/constants/dashboardAsideMenuItems';

// Lifecycle hooks
onMounted(() => {
    window.addEventListener('keydown', focusSearchOnShortcut)
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

onBeforeUnmount(() => window.removeEventListener('keydown', focusSearchOnShortcut))

/** Cmd-K (Ctrl-K elsewhere) puts the cursor in the search, as in most apps people already use. */
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
const shortcutLabel = isMac ? '⌘K' : 'Ctrl K';
function focusSearchOnShortcut(event: KeyboardEvent): void {
    if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'k' || event.defaultPrevented) return;

    // Inside a text field or a rich editor, Ctrl/Cmd-K belongs to that field
    // (the page builder's "insert link", for one). Only take it elsewhere.
    const target = event.target as HTMLElement | null;
    const search = document.getElementById('dashboard_search_input');
    const editable = !!target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
    if (editable && target !== search) return;

    event.preventDefault();
    (search as HTMLInputElement | null)?.focus();
}

// Routing
const router = useRouter();
const route = useRoute();

// Html refs
const dashboardLayout = ref<HTMLElement | null>();
const asideToggleButton = ref<HTMLElement | null>();

// Stores
const authStore = useAuthStore();
const masjidStore = useMasjidStore();
const tenantSwitchStore = useTenantSwitchStore();
const searchStore = useDashboardSearchStore();

// The account button: a photo when there is one, otherwise the person's initials.
// It used to be an <img> with alt "user", which showed the word "user" to every
// account without a photo.
const avatarUrl = computed<string | null>(() => authStore.user?.avatar?.original_url || null);
const avatarFailed = ref<boolean>(false);
watch(avatarUrl, () => { avatarFailed.value = false; });
const displayName = computed<string>(() => authStore.user?.name?.trim() || authStore.user?.email || 'Account');
const initials = computed<string>(() => {
    const words = displayName.value.replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean);
    return ((words[0]?.[0] ?? '') + (words[1]?.[0] ?? '')).toUpperCase() || '?';
});
const roleLabel = computed<string>(() => {
    switch (authStore.user?.type) {
        case 'SuperAdmin': return 'Super admin';
        case 'MasjidAdmin': return 'Administrator';
        default: return '';
    }
});

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
                frontendSearchResults.value.push(...withModuleRules(MASJID_DASHBOARD_ROUTES_RESULTS.filter(obj => {
                    return obj.title.toLowerCase().includes(searchValue.value?.toLowerCase() as string);
                })));
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
/**
 * Page links follow the sidebar: a link to a module the organisation has switched
 * off is dropped for its administrators, and kept for a SuperAdmin with
 * " (switched off)" on the end. Matched on the authored title first, so typing
 * "switched" never finds anything.
 *
 * Before that, the sidebar's org-type rule (itemFitsOrgType): a link whose sidebar
 * item is for other org types, and whose module nobody switched on here, is dropped
 * for everyone, exactly as the item is. A school's Services and Donation link are
 * never in `modules_off` (it was not offered them), and their editing APIs refuse
 * its admins, so the link would open a screen that cannot save.
 */
function withModuleRules(records: DashboardSearchResultRecord[]): DashboardSearchResultRecord[] {
    return records.flatMap(record => {
        if (!record.requiresModule) return [record];

        const item = MASJID_DASHBOARD_ASIDE_MENU.find(entry => entry.to === record.url);
        if (item && !itemFitsOrgType(item, masjidStore.masjid, masjidStore.orgType)) return [];

        if (!moduleIsOff(masjidStore.masjid, record.requiresModule)) return [record];

        return authStore.user?.type === 'SuperAdmin'
            ? [{ ...record, title: `${record.title} (switched off)` }]
            : [];
    });
}

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
.mn-header {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.mn-header-main {
    display: flex;
    flex: 1;
    min-width: 0;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.mn-header-title {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    min-width: 0;
}

.mn-header-org {
    display: flex;
    flex-direction: column;
    min-width: 0;
    line-height: 1.15;
}

.mn-header-eyebrow {
    color: #5d6275;
    font-size: 0.72rem;
    font-weight: 650;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.mn-header-name {
    color: #111827;
    font-size: 1.2rem;
    font-weight: 740;
    letter-spacing: -0.02em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.mn-icon-btn {
    flex: none;
    display: inline-grid;
    place-items: center;
    width: 2.1rem;
    height: 2.1rem;
    border: 0;
    border-radius: 9px;
    color: #5d6275;
    background: #fff;
    box-shadow: 0 0 0 1px #e3e8ee, 0 1px 2px rgb(16 24 40 / 5%);
    transition: background-color 0.15s ease, color 0.15s ease;
}

.mn-icon-btn:hover {
    background: #f5f7f9;
    color: #111827;
}

.mn-icon-btn:focus-visible {
    outline: none;
    box-shadow: 0 0 0 4px rgb(1 177 81 / 25%);
}

.mn-header-actions {
    display: flex;
    align-items: center;
    gap: 0.9rem;
}

.mn-search {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: clamp(12rem, 26vw, 22rem);
    height: 2.5rem;
    padding: 0 0.6rem 0 0.8rem;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 0 0 1px #e3e8ee, 0 1px 2px rgb(16 24 40 / 4%);
    transition: box-shadow 0.15s ease;
}

.mn-search:focus-within {
    box-shadow: 0 0 0 1px #007a38, 0 0 0 4px rgb(1 177 81 / 16%);
}

.mn-search-icon {
    flex: none;
    color: #8a94a3;
}

.search-field {
    flex: 1;
    min-width: 0;
    border: none;
    background: transparent;
    font-size: 0.93rem;
    color: #111827;
}

.search-field:focus {
    border: none;
    outline: none;
}

.search-field::placeholder {
    color: #8a94a3;
}

.mn-search-kbd {
    flex: none;
    padding: 0.1rem 0.4rem;
    border-radius: 6px;
    background: #f2f4f7;
    color: #5d6275;
    box-shadow: inset 0 0 0 1px #e3e8ee;
    font-family: inherit;
    font-size: 0.72rem;
    font-weight: 600;
}

.mn-search-clear {
    flex: none;
    display: inline-grid;
    place-items: center;
    width: 1.5rem;
    height: 1.5rem;
    border: 0;
    border-radius: 6px;
    background: #f2f4f7;
    color: #5d6275;
}

.mn-search-clear:hover {
    background: #e8f6ee;
    color: #005c2a;
}

.search-container {
    position: relative;
}

.search-results-container {
    position: absolute;
    top: calc(100% + 0.5rem);
    width: 100%;
    min-width: 18rem;
    z-index: 1050;
    padding: 0.4rem;
    right: 0;
    background-color: white;
    border-radius: 12px;
    box-shadow: 0 0 0 1px #e3e8ee, 0 16px 40px rgb(16 24 40 / 14%);
    max-height: calc(100vh - 10rem);
    overflow: auto;
}

.search-results-container .search-result {
    text-decoration: none;
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
    font-size: 0.93rem;
    font-weight: 550;
    /* Result link text on white and on the hover fill: #016B31 is 6.67:1 / 5.81:1. */
    color: #016B31;
}

.search-results-container .search-result:hover {
    background-color: #e8f6ee;
}

.mn-account {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.25rem 0.5rem 0.25rem 0.25rem;
    border: 0;
    border-radius: 12px;
    background: transparent;
    cursor: pointer;
    transition: background-color 0.15s ease;
}

.mn-account:hover,
.mn-account[aria-expanded='true'] {
    background: #eaeef2;
}

.mn-account:focus-visible {
    outline: none;
    box-shadow: 0 0 0 4px rgb(1 177 81 / 25%);
}

.mn-account::after {
    margin-left: 0.15rem;
    color: #8a94a3;
}

.account-dropdown-avatar {
    flex: none;
    display: grid;
    place-items: center;
    width: 2.3rem;
    height: 2.3rem;
    border-radius: 50%;
    overflow: hidden;
    background: linear-gradient(135deg, #0d2a4d, #0f3b2e);
    box-shadow: 0 0 0 2px #fff, 0 0 0 3px #e3e8ee;
}

.avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.mn-avatar-initials {
    color: #fff;
    font-size: 0.85rem;
    font-weight: 700;
    letter-spacing: 0.02em;
}

.mn-account-text {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    line-height: 1.2;
    max-width: 11rem;
}

.mn-account-name {
    color: #111827;
    font-size: 0.9rem;
    font-weight: 650;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}

.mn-account-role {
    color: #5d6275;
    font-size: 0.76rem;
    font-weight: 500;
}

.dropdown-item.email-text {
    cursor: text !important;
    color: #5d6275;
    font-size: 0.85rem;
}

.dropdown-item.email-text:hover,
.dropdown-item.email-text:focus {
    background: transparent !important;
    color: #5d6275 !important;
}

@media (max-width: 1199px) {
    .mn-account-text {
        display: none;
    }
}

@media (max-width: 767px) {
    /* Two clean rows on a phone: menu button and organisation on the first,
       search and account on the second. */
    .mn-header {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        align-items: center;
        column-gap: 0.75rem;
        row-gap: 0.75rem;
    }

    .mn-header-main {
        display: contents;
    }

    .mn-header-title {
        justify-content: space-between;
    }

    .mn-header-actions {
        grid-column: 1 / -1;
        width: 100%;
        justify-content: space-between;
    }

    .mn-search {
        flex: 1;
        width: auto;
    }

    .mn-search-kbd {
        display: none;
    }
}
</style>
