<template>
    <aside id="dashboard_aside">
        <div class="d-flex flex-column gap-3 aside-contents-container">
            <div id="dashboard_aside_header" class="d-flex align-items-center gap-2 justify-content-between">
                <button id="dashboard_aside_close_btn" type="button" class="aside-toggle-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-x-lg aside-toggle-icon"
                        viewBox="0 0 16 16">
                        <path
                            d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z" />
                    </svg>
                </button>
                <div class="w-100 px-3 py-2 logo">
                    <svg v-if="route.meta.dashboardType === 'super'" viewBox="0 0 64 64" fill="none"
                        xmlns="http://www.w3.org/2000/svg" class="logo-super-dash">
                        <path
                            d="M10.6667 34.6667H26.6667C27.3739 34.6667 28.0522 34.3857 28.5523 33.8856C29.0524 33.3855 29.3333 32.7072 29.3333 32V10.6667C29.3333 9.95942 29.0524 9.28115 28.5523 8.78105C28.0522 8.28095 27.3739 8 26.6667 8H10.6667C9.95942 8 9.28115 8.28095 8.78105 8.78105C8.28095 9.28115 8 9.95942 8 10.6667V32C8 32.7072 8.28095 33.3855 8.78105 33.8856C9.28115 34.3857 9.95942 34.6667 10.6667 34.6667ZM8 53.3333C8 54.0406 8.28095 54.7189 8.78105 55.219C9.28115 55.7191 9.95942 56 10.6667 56H26.6667C27.3739 56 28.0522 55.7191 28.5523 55.219C29.0524 54.7189 29.3333 54.0406 29.3333 53.3333V42.6667C29.3333 41.9594 29.0524 41.2811 28.5523 40.7811C28.0522 40.281 27.3739 40 26.6667 40H10.6667C9.95942 40 9.28115 40.281 8.78105 40.7811C8.28095 41.2811 8 41.9594 8 42.6667V53.3333ZM34.6667 53.3333C34.6667 54.0406 34.9476 54.7189 35.4477 55.219C35.9478 55.7191 36.6261 56 37.3333 56H53.3333C54.0406 56 54.7189 55.7191 55.219 55.219C55.7191 54.7189 56 54.0406 56 53.3333V34.6667C56 33.9594 55.7191 33.2811 55.219 32.781C54.7189 32.281 54.0406 32 53.3333 32H37.3333C36.6261 32 35.9478 32.281 35.4477 32.781C34.9476 33.2811 34.6667 33.9594 34.6667 34.6667V53.3333ZM37.3333 26.6667H53.3333C54.0406 26.6667 54.7189 26.3857 55.219 25.8856C55.7191 25.3855 56 24.7072 56 24V10.6667C56 9.95942 55.7191 9.28115 55.219 8.78105C54.7189 8.28095 54.0406 8 53.3333 8H37.3333C36.6261 8 35.9478 8.28095 35.4477 8.78105C34.9476 9.28115 34.6667 9.95942 34.6667 10.6667V24C34.6667 24.7072 34.9476 25.3855 35.4477 25.8856C35.9478 26.3857 36.6261 26.6667 37.3333 26.6667Z"
                            fill="white" />
                    </svg>

                    <img v-else-if="masjidStore.masjid" :src="masjidStore.masjid?.logo?.original_url" alt="logo"
                        class="w-100">
                </div>
            </div>

            <div id="dashboard_aside_menu">
                <template v-for="menuItem in dashboardAsideStore.asideMenuItems">
                    <router-link v-if="menuItem.allowed_types.includes(authStore.user?.type as UserType)
                        && (!menuItem.requiresCrm || masjidStore.masjid?.crm_enabled)
                        && (!menuItem.requiresAssistant || masjidStore.masjid?.assistant_enabled)"
                        :to="menuItem.to" class="dashboard-aside-menu-item">
                        <div class="menu-item-icon">
                            <span v-html="menuItem.svg_icon"></span>
                        </div>
                        <div class="menu-item-text">
                            {{ menuItem.title }}
                        </div>
                    </router-link>
                </template>
            </div>

        </div>
    </aside>
</template>

<script setup lang="ts">
import { UserType } from '@/core/types/data/User';
import { useAuthStore } from '@/stores/authStore';
import { useDashboardAsideStore } from '@/stores/config/dashboardAsideStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { onMounted, ref } from 'vue';
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

// Html refs
const dashboardLayout = ref<HTMLElement | null>();
const asideCloseButton = ref<HTMLElement | null>();
const asideMenuItems = ref<NodeListOf<Element>>();

</script>

<style scoped>
#dashboard_aside_header {
    box-sizing: content-box;
    overflow: hidden;
    flex-shrink: 0;
}

#dashboard_aside_header .logo {
    display: flex;
    align-items: start;
    justify-content: center;
}

#dashboard_aside_header .logo img {
    object-fit: contain;
    max-height: 2.5rem;
}

#dashboard_aside_header .logo svg.logo-super-dash {
    width: 3rem !important;
    height: 3rem !important;
}

#dashboard_aside_menu {
    margin: .5rem;
    color: var(--cgreen-light);
    background-color: var(--cgreen);
    display: flex;
    flex-direction: column;
    gap: .5rem;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
}

#dashboard_aside_menu .dashboard-aside-menu-item {
    color: var(--cgreen-light);
    display: flex;
    gap: 1rem;
    align-items: center;
    justify-content: start;
    padding: .5rem 1rem;
    border-radius: .5rem;
    text-decoration: none;
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    overflow: hidden;
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-icon span {
    width: 100% !important;
    height: 100% !important;
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-icon svg {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain;
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-icon svg path {
    /* width: 100% !important;
    height: 100% !important; */
    fill: var(--cgreen-light);
}

#dashboard_aside_menu .dashboard-aside-menu-item .menu-item-text {
    font-size: 1rem;
    font-weight: 400;
}

#dashboard_aside_menu .dashboard-aside-menu-item:hover,
#dashboard_aside_menu .router-link-active.dashboard-aside-menu-item {
    background-color: var(--cgreen-active);
}

</style>
