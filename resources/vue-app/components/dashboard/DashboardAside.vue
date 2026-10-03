<template>
    <aside id="dashboard_aside" aria-label="Main navigation">
        <!-- The same night-to-emerald ground as the sign-in screen (AuthShell). -->
        <div class="aside-glow" aria-hidden="true"></div>
        <div class="aside-lattice" aria-hidden="true"></div>

        <div class="d-flex flex-column aside-contents-container">
            <div id="dashboard_aside_header" class="d-flex align-items-center gap-2 justify-content-between">
                <div class="aside-identity">
                    <template v-if="route.meta.dashboardType === 'super'">
                        <span class="aside-identity-tile aside-identity-tile--brand">
                            <img :src="'/manara-icon.svg'" alt="" width="40" height="40">
                        </span>
                        <span class="aside-identity-text">
                            <span class="aside-identity-name">Manara</span>
                            <span class="aside-identity-sub">Super dashboard</span>
                        </span>
                    </template>

                    <!--
                        Hidden while the server and this tab disagree about which
                        organisation is bound (S5). The header already renders the
                        server's answer; a logo from the other one, right beside
                        it, would put the contradiction back on screen in the one
                        element nobody reads as text.
                    -->
                    <template v-else-if="masjidStore.masjid && !tenantSwitchStore.mismatch">
                        <span class="aside-identity-tile" :class="{ 'aside-identity-tile--wide': logoIsWide }">
                            <img v-if="logoUrl" :src="logoUrl" alt="" @load="measureLogo">
                            <span v-else class="aside-identity-initials">{{ initials }}</span>
                        </span>
                        <span class="aside-identity-text">
                            <span class="aside-identity-name">{{ tenantSwitchStore.chromeOrgName }}</span>
                            <span class="aside-identity-sub">{{ masjidStore.organizationLabel }}</span>
                        </span>
                    </template>
                </div>

                <button id="dashboard_aside_close_btn" type="button" class="aside-toggle-btn" aria-label="Close menu">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-x-lg aside-toggle-icon"
                        viewBox="0 0 16 16" aria-hidden="true">
                        <path
                            d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z" />
                    </svg>
                </button>
            </div>

            <nav id="dashboard_aside_menu" aria-label="Sections">
                <template v-for="menuItem in dashboardAsideStore.asideMenuItems">
                    <router-link v-if="stateOf(menuItem) === 'visible'"
                        :to="menuItem.to" class="dashboard-aside-menu-item">
                        <div class="menu-item-icon" aria-hidden="true">
                            <i v-if="iconFor(menuItem)" class="bi" :class="iconFor(menuItem)"></i>
                            <span v-else v-html="menuItem.svg_icon"></span>
                        </div>
                        <div class="menu-item-text">
                            {{ title(menuItem) }}
                        </div>
                    </router-link>
                </template>

                <!--
                    SuperAdmin only, and only when there is something in it: the screens
                    this organisation does not have. The sidebar above is exactly what its
                    administrators see; these stay one click away so the owner can still
                    set the organisation up. Collapsed, so it never reads as part of the menu.
                -->
                <details v-if="switchedOffItems.length" class="aside-switched-off">
                    <summary class="aside-switched-off-summary">
                        Switched off for {{ masjidStore.masjid?.name }} ({{ switchedOffItems.length }})
                    </summary>
                    <div class="d-flex flex-column gap-1 mt-2">
                        <router-link v-for="menuItem in switchedOffItems" :key="menuItem.to" :to="menuItem.to"
                            class="aside-switched-off-link" @click="closeAsideOnSmallScreens">
                            <i class="bi bi-dash-circle" aria-hidden="true"></i>
                            <span>{{ title(menuItem) }}</span>
                            <span class="visually-hidden">(switched off for this organisation)</span>
                        </router-link>
                    </div>
                </details>
            </nav>

            <div class="aside-footer">
                <img :src="'/manara-icon.svg'" alt="" width="22" height="22">
                <span class="aside-footer-name">Manara</span>
                <span class="aside-footer-by">by Hope Tech</span>
            </div>
        </div>
    </aside>
</template>

<script setup lang="ts">
import { menuItemState, menuItemTitle, MenuItemState } from '@/core/access/orgAccess';
import { AsideMenuItem } from '@/core/types/config/AsideMenuItem';
import { useAuthStore } from '@/stores/authStore';
import { useDashboardAsideStore } from '@/stores/config/dashboardAsideStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useTenantSwitchStore } from '@/stores/tenantSwitchStore';
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';

// Lifecycle hooks
onMounted(() => {

    // get dashboard layout
    dashboardLayout.value = document.getElementById('dashboard_layout')

    // add click listner to close aside button
    asideCloseButton.value = document.getElementById('dashboard_aside_close_btn')
    if (asideCloseButton.value) {
        asideCloseButton.value.addEventListener('click', () => {
            if (dashboardLayout.value) {
                dashboardLayout.value.classList.remove('aside-hidden')
            }
        })
    }

    // add click listner to aside menu items
    asideMenuItems.value = document.querySelectorAll('.dashboard-aside-menu-item')
    if (asideMenuItems.value.length) {
        asideMenuItems.value.forEach(elm => {
            elm.addEventListener('click', () => {
                if (dashboardLayout.value) {
                    dashboardLayout.value.classList.remove('aside-hidden')
                }
            })
        })

    }
})

// Routing
const route = useRoute();

// Stores
const dashboardAsideStore = useDashboardAsideStore();
const authStore = useAuthStore();
const masjidStore = useMasjidStore();
const tenantSwitchStore = useTenantSwitchStore();

/**
 * Where an item goes for this person and this organisation — the one predicate
 * the router, the header search and the SuperAdmin's switch panel also read
 * (core/access/orgAccess.ts). A SuperAdmin no longer sees every item: they see
 * what the organisation has, and the rest in the "Switched off" list below.
 */
const stateOf = (menuItem: AsideMenuItem): MenuItemState =>
    menuItemState(menuItem, authStore.user?.type, masjidStore.masjid, masjidStore.orgType);

/** The label in the tenant's own vocabulary; `term()` falls back to the masjid pack. */
const title = (menuItem: AsideMenuItem): string => menuItemTitle(menuItem, masjidStore.term);

// Only once the organisation has loaded: before that every grant reads as
// missing, and the list would flash items the organisation actually has.
const switchedOffItems = computed<AsideMenuItem[]>(() => {
    if (authStore.user?.type !== 'SuperAdmin' || route.meta.dashboardType === 'super' || !masjidStore.masjid) {
        return [];
    }

    return dashboardAsideStore.asideMenuItems.filter(menuItem => stateOf(menuItem) === 'switched_off');
});

// The menu items above get this listener in onMounted; these links can appear
// after mount (once the organisation loads), so they carry it themselves.
const closeAsideOnSmallScreens = () => {
    document.getElementById('dashboard_layout')?.classList.remove('aside-hidden');
};

/**
 * One icon family for the whole rail. The menu constants carry hand-picked SVGs
 * that mix outline and solid glyphs at different weights; on the dark rail the
 * mix read as uneven. Each known route gets its Bootstrap Icons outline glyph
 * (the icon font the app already loads); an unmapped route keeps its own SVG.
 */
const MENU_ICONS: Record<string, string> = {
    '/masjid/details': 'bi-building',
    '/masjid/announcements': 'bi-megaphone',
    '/masjid/splash-announcements': 'bi-window-stack',
    '/masjid/broadcasts': 'bi-broadcast',
    '/masjid/tv-display': 'bi-tv',
    '/masjid/events': 'bi-calendar-event',
    '/masjid/services': 'bi-grid-1x2',
    '/masjid/donation': 'bi-heart',
    '/masjid/about': 'bi-info-circle',
    '/masjid/gallery': 'bi-images',
    '/masjid/flyers': 'bi-file-earmark-richtext',
    '/masjid/pages': 'bi-globe2',
    '/masjid/form-responses': 'bi-ui-checks',
    '/masjid/payment-methods': 'bi-credit-card',
    '/masjid/team': 'bi-person-badge',
    '/masjid/notifications': 'bi-bell',
    '/masjid/contact-requests': 'bi-envelope',
    '/masjid/contacts': 'bi-people',
    '/masjid/groups': 'bi-easel2',
    '/masjid/roster-import': 'bi-upload',
    '/masjid/teachers': 'bi-person-workspace',
    '/masjid/school-calendar': 'bi-calendar3',
    '/masjid/class-store': 'bi-shop',
    '/masjid/attendance': 'bi-clipboard-check',
    '/masjid/offerings': 'bi-card-checklist',
    '/masjid/appointment-requests': 'bi-calendar-check',
    '/masjid/donations/dashboard': 'bi-graph-up-arrow',
    '/masjid/zakat': 'bi-calculator',
    '/masjid/impact-report': 'bi-bar-chart-line',
    '/masjid/funds': 'bi-piggy-bank',
    '/masjid/jummah-lunch': 'bi-cup-hot',
    '/masjid/shop': 'bi-bag',
    '/masjid/donations': 'bi-cash-coin',
    '/masjid/recurring-donations': 'bi-arrow-repeat',
    '/masjid/annual-statements': 'bi-file-earmark-text',
    '/masjid/properties': 'bi-houses',
    '/masjid/assistant': 'bi-stars',
    '/masjid/mobile-features': 'bi-phone',
    '/hadith': 'bi-book',
    '/azkar': 'bi-moon-stars',
    '/tasabih': 'bi-record-circle',
    '/dashboard/super/users': 'bi-people',
    '/dashboard/super/masjids': 'bi-buildings',
    '/dashboard/super/onboarding': 'bi-box-arrow-in-up-right',
    '/dashboard/super/studio': 'bi-palette',
    '/dashboard/super/app-config': 'bi-sliders',
};
const iconFor = (menuItem: AsideMenuItem): string | null =>
    typeof menuItem.to === 'string' ? (MENU_ICONS[menuItem.to] ?? null) : null;

/** The organisation's logo, when it has one. */
const logoUrl = computed<string | null>(() => masjidStore.masjid?.logo?.original_url || null);

/** Two letters for an organisation without a logo. */
const initials = computed<string>(() => {
    const words = (tenantSwitchStore.chromeOrgName || '').split(/\s+/).filter(Boolean);
    return ((words[0]?.[0] ?? '') + (words[1]?.[0] ?? '')).toUpperCase() || 'M';
});

/**
 * A wordmark logo (wider than it is tall) gets the whole header row instead of
 * being squeezed into a square beside the name.
 */
const logoIsWide = ref<boolean>(false);
const measureLogo = (event: Event): void => {
    const img = event.target as HTMLImageElement;
    logoIsWide.value = img.naturalWidth > img.naturalHeight * 1.6;
};

// Html refs
const dashboardLayout = ref<HTMLElement | null>();
const asideCloseButton = ref<HTMLElement | null>();
const asideMenuItems = ref<NodeListOf<Element>>();

</script>

<style scoped>
/*
 * The rail is the one dark surface in the app, drawn like the sign-in stage:
 * a navy ground, an emerald glow at the top, and the masjids lattice fading
 * in from the bottom. Label and icon colours are chosen for contrast on
 * #0b2340: #c9d3df is 10.2:1, white 15.6:1.
 */
.aside-glow {
    position: absolute;
    inset: -30% -40% auto -20%;
    height: 60%;
    background: radial-gradient(closest-side, rgb(1 177 81 / 26%), transparent);
    pointer-events: none;
}

.aside-lattice {
    position: absolute;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='64' height='64' viewBox='0 0 64 64'%3E%3Cg fill='none' stroke='rgba(255,255,255,0.07)' stroke-width='1'%3E%3Cpath d='M18 18h28v28H18z'/%3E%3Cpath d='M32 12.2 51.8 32 32 51.8 12.2 32z'/%3E%3Cpath d='M32 0v12.2M32 51.8V64M0 32h12.2M51.8 32H64'/%3E%3Cpath d='M0 0l8 8M64 0l-8 8M0 64l8-8M64 64l-8-8'/%3E%3C/g%3E%3C/svg%3E");
    background-size: 64px 64px;
    -webkit-mask-image: linear-gradient(to top, #000 0%, transparent 45%);
    mask-image: linear-gradient(to top, #000 0%, transparent 45%);
    pointer-events: none;
}

#dashboard_aside_header {
    position: relative;
    flex-shrink: 0;
    padding: 0.35rem 0.25rem 1rem;
    margin-bottom: 0.5rem;
    border-bottom: 1px solid rgb(255 255 255 / 8%);
}

.aside-identity {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
    flex: 1;
}

.aside-identity-tile {
    flex: none;
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    padding: 4px;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 0 0 1px rgb(255 255 255 / 14%), 0 6px 18px rgb(0 0 0 / 30%);
    overflow: hidden;
}

.aside-identity-tile img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.aside-identity-tile--wide {
    width: 100%;
    height: 52px;
    padding: 6px 10px;
}

.aside-identity-tile--wide + .aside-identity-text {
    display: none;
}

.aside-identity-tile--brand {
    padding: 0;
    background: transparent;
}

.aside-identity-initials {
    color: var(--mn-navy, #0b2340);
    font-weight: 750;
    font-size: 1rem;
    letter-spacing: 0.02em;
}

.aside-identity-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.aside-identity-name {
    color: #fff;
    font-weight: 700;
    font-size: 0.98rem;
    line-height: 1.25;
    letter-spacing: -0.01em;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.aside-identity-sub {
    color: #93a3b8;
    font-size: 0.78rem;
    font-weight: 550;
    letter-spacing: 0.02em;
}

#dashboard_aside_menu {
    position: relative;
    color: #c9d3df;
    display: flex;
    flex-direction: column;
    gap: 2px;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    margin: 0 -0.25rem;
    padding: 0.15rem 0.25rem 1.25rem;
    /* The last items fade out under the footer rather than butting into it. */
    -webkit-mask-image: linear-gradient(to bottom, #000 calc(100% - 1.5rem), transparent);
    mask-image: linear-gradient(to bottom, #000 calc(100% - 1.5rem), transparent);
    scrollbar-width: thin;
    scrollbar-color: rgb(255 255 255 / 18%) transparent;
}

#dashboard_aside_menu::-webkit-scrollbar { width: 6px; }
#dashboard_aside_menu::-webkit-scrollbar-thumb { background: rgb(255 255 255 / 16%); border-radius: 999px; border: 0; }

#dashboard_aside_menu .dashboard-aside-menu-item {
    position: relative;
    color: #c9d3df;
    display: flex;
    gap: 0.8rem;
    align-items: center;
    justify-content: start;
    padding: 0.55rem 0.75rem;
    border-radius: 10px;
    text-decoration: none;
    transition: background-color 0.15s ease, color 0.15s ease;
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-icon {
    flex: none;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.25rem;
    height: 1.25rem;
    overflow: hidden;
    color: #8fa0b5;
    transition: color 0.15s ease;
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-icon span {
    width: 100% !important;
    height: 100% !important;
    display: flex;
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-icon :deep(svg) {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain;
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-icon :deep(svg path) {
    fill: currentColor;
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-icon .bi {
    font-size: 1.1rem;
    line-height: 1;
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-text {
    font-size: 0.93rem;
    font-weight: 520;
    line-height: 1.3;
}

#dashboard_aside_menu .dashboard-aside-menu-item:hover {
    background-color: rgb(255 255 255 / 6%);
    color: #fff;
}

#dashboard_aside_menu .dashboard-aside-menu-item:hover .menu-item-icon {
    color: #dbe4ee;
}

#dashboard_aside_menu .router-link-active.dashboard-aside-menu-item {
    background: linear-gradient(90deg, rgb(1 177 81 / 24%), rgb(1 177 81 / 8%));
    color: #fff;
    box-shadow: inset 0 0 0 1px rgb(1 177 81 / 22%);
}

#dashboard_aside_menu .router-link-active.dashboard-aside-menu-item::before {
    content: '';
    position: absolute;
    left: -0.25rem;
    top: 22%;
    bottom: 22%;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: #01b151;
}

#dashboard_aside_menu .router-link-active.dashboard-aside-menu-item .menu-item-icon {
    color: #5fe39a;
}

#dashboard_aside_menu .router-link-active.dashboard-aside-menu-item .menu-item-text {
    font-weight: 620;
}

#dashboard_aside_menu .dashboard-aside-menu-item:focus-visible,
#dashboard_aside_menu .aside-switched-off-summary:focus-visible,
#dashboard_aside_menu .aside-switched-off-link:focus-visible {
    outline: 2px solid #5fe39a;
    outline-offset: 1px;
}

/* The SuperAdmin's "Switched off" list: set apart by a rule, a smaller size and
   an outline icon rather than by fading the text, which would fail contrast. */
#dashboard_aside_menu .aside-switched-off {
    margin-top: 0.75rem;
    padding: 0.75rem 0.75rem 0;
    border-top: 1px solid rgb(255 255 255 / 10%);
    color: #c9d3df;
    font-size: 0.85rem;
}

#dashboard_aside_menu .aside-switched-off-summary {
    cursor: pointer;
    padding: 0.25rem 0;
    border-radius: 0.25rem;
}

#dashboard_aside_menu .aside-switched-off-link {
    color: #c9d3df;
    display: flex;
    gap: 0.5rem;
    align-items: center;
    padding: 0.4rem 0.5rem;
    border-radius: 8px;
    text-decoration: none;
}

#dashboard_aside_menu .aside-switched-off-link:hover,
#dashboard_aside_menu .aside-switched-off-link.router-link-active {
    background-color: rgb(255 255 255 / 7%);
    color: #fff;
}

.aside-footer {
    position: relative;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.85rem 0.5rem 0.25rem;
    border-top: 1px solid rgb(255 255 255 / 8%);
    color: #aab7c8;
    font-size: 0.82rem;
}

.aside-footer img {
    border-radius: 6px;
}

.aside-footer-name {
    color: #e6edf5;
    font-weight: 700;
    letter-spacing: -0.01em;
}

@media (prefers-reduced-motion: reduce) {
    #dashboard_aside_menu .dashboard-aside-menu-item,
    #dashboard_aside_menu .dashboard-aside-menu-item .menu-item-icon {
        transition: none;
    }
}
</style>
