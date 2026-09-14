<template>
    <div id="dashboard_layout" class="">
        <div id="dashboard_overall_layer"></div>
        <DashboardAside />
        <div id="header_main_container">
            <DashboardHeader />
            <main id="dashboard_main">
                <!-- A SuperAdmin opened a screen this organisation does not have
                     (from "Switched off for …" in the sidebar, or a typed URL). -->
                <SwitchedOffNotice v-if="switchedOffHere" class="mx-3 mt-3" :org-name="masjidStore.masjid?.name" />
                <RouterView></RouterView>
            </main>
            <DashboardFooter />
        </div>
    </div>
</template>

<script setup lang="ts">
import DashboardAside from '@/components/dashboard/DashboardAside.vue';
import DashboardHeader from '@/components/dashboard/DashboardHeader.vue';
import { RouterView, useRoute, useRouter } from 'vue-router';
import { computed, onBeforeMount, onMounted, onUpdated, ref } from 'vue';
import DashboardFooter from '@/components/dashboard/DashboardFooter.vue';
import SwitchedOffNotice from '@/components/dashboard/SwitchedOffNotice.vue';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { hasGrant, moduleIsOff } from '@/core/access/orgAccess';

// Lifecycle hooks
onBeforeMount(async () => {
    if(!authStore.isAuthenticated) {
        authStore.removeAuth();
        router.push("/");
    } else {
        await masjidStore.fetchMasjid();
    }
})

onMounted(() => {

    // Set overall layer click listnere to hide the Aside in small screens
    overallLayer.value = document.getElementById('dashboard_overall_layer');
    if (overallLayer.value) {
        overallLayer.value.addEventListener('click', () => {
            dashboardLayout.value = document.getElementById('dashboard_layout');
            if (dashboardLayout.value) {
                dashboardLayout.value.classList.remove('aside-hidden');
            }
        });
    }

    setDashboardMainTopMargin()

})

onUpdated(() => {
    setDashboardMainTopMargin()
})

// Routing
const router = useRouter();
const route = useRoute();

// Stores
const authStore = useAuthStore();
const masjidStore = useMasjidStore();

/**
 * Whether the SuperAdmin is looking at a screen this organisation's administrators
 * cannot open. Same rules as the sidebar's "Switched off" list (menuItemState): a
 * module switched off, or a grant the organisation lacks on a screen that names no
 * module. A screen that names BOTH (Web Pages Management) follows its module, so the
 * owner editing a site its own admins may not edit gets no notice.
 */
const switchedOffHere = computed<boolean>(() => {
    const masjid = masjidStore.masjid;
    if (authStore.user?.type !== 'SuperAdmin' || !masjid) return false;

    const meta = route.meta;
    if (meta.requiresModule) return moduleIsOff(masjid, meta.requiresModule);

    return !!meta.requiresCapability && !hasGrant(masjid, meta.requiresCapability);
});

// Html refs
const dashboardLayout = ref<HTMLElement | null>();
const overallLayer = ref<HTMLElement | null>();
const dashboardHeader = ref<HTMLElement | null>();
const dashboardMain = ref<HTMLElement | null>();

// functions
function setDashboardMainTopMargin() {
    /* Set the top margin for main content to be related to the header height
    ** to display the main under the header correctly since the header has a
    ** position of fixed value.
    **
    ** The Observer is for listen for any changing in the dashboard header size.
    */
    dashboardHeader.value = document.getElementById('dashboard_header')
    dashboardMain.value = document.getElementById('dashboard_main')
    
    const resizeObserver = new ResizeObserver(() => {
        dashboardHeader.value = document.getElementById('dashboard_header')
        dashboardMain.value = document.getElementById('dashboard_main')
        if (dashboardHeader.value && dashboardMain.value) {
            let headerHeight = parseFloat(getComputedStyle(dashboardHeader.value).height)
            let remValue = parseFloat(getComputedStyle(document.documentElement).fontSize)
            const mainTopMargin = (headerHeight / remValue)
            dashboardMain.value.style.marginTop = mainTopMargin + 'rem'
        }
    })

    if (dashboardHeader.value) resizeObserver.observe(dashboardHeader.value)
}

</script>

<style scoped></style>