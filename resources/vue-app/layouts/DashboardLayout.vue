<template>
    <div id="dashboard_layout" class="">
        <div id="dashboard_overall_layer"></div>
        <DashboardAside />
        <div id="header_main_container">
            <DashboardHeader />
            <main id="dashboard_main">
                <!-- The server bound a different organisation than this tab
                     selected. Above everything, because every number below it
                     belongs to the organisation the notice names. -->
                <TenantMismatchNotice v-if="tenantSwitchStore.mismatch" class="mx-3 mt-3"
                    :server-name="tenantSwitchStore.nameFor(tenantSwitchStore.mismatch.server)"
                    :selected-name="tenantSwitchStore.nameFor(tenantSwitchStore.mismatch.selected)"
                    :busy="tenantSwitchStore.switching" @reconcile="reconcileWithServer" />

                <!-- Signed in with nothing to show: no organisation granted, or
                     several granted and none chosen. Neither is reachable while
                     the multi-membership gate is shut. -->
                <NoOrganisationNotice v-if="tenantSwitchStore.hasNoOrganisation" class="mx-3 mt-3" reason="granted" />
                <NoOrganisationNotice v-else-if="tenantSwitchStore.mustChooseOrganisation" class="mx-3 mt-3"
                    reason="chosen" />

                <!-- A SuperAdmin opened a screen this organisation does not have
                     (from "Switched off for …" in the sidebar, or a typed URL). -->
                <SwitchedOffNotice v-if="switchedOffHere" class="mx-3 mt-3" :org-name="masjidStore.masjid?.name" />

                <!--
                    Keyed by the switch counter (S5). Emptying the pinia stores
                    does not empty a SCREEN: the row a modal is holding, the list
                    a table copied into a local ref, the id a detail view was
                    opened with all belong to the component, and a switch that
                    leaves the route unchanged re-renders none of it. Changing
                    the key remounts the screen so it starts again in the new
                    organisation.

                    The counter never moves until a switch completes, so this
                    renders exactly as an unkeyed RouterView for everyone who
                    never switches.
                -->
                <RouterView :key="tenantSwitchStore.viewGeneration"></RouterView>
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
import TenantMismatchNotice from '@/components/dashboard/TenantMismatchNotice.vue';
import NoOrganisationNotice from '@/components/dashboard/NoOrganisationNotice.vue';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useTenantSwitchStore } from '@/stores/tenantSwitchStore';
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
const tenantSwitchStore = useTenantSwitchStore();

/**
 * Put the tab where the server already is.
 *
 * Runs the ordinary switch — new epoch, stores emptied, screen remounted —
 * towards the organisation the server bound, because the only safe way to agree
 * with it is to go through the same door as any other switch. Doing less (just
 * moving the selection) would leave the other organisation's rows in the stores,
 * which is the disagreement the notice is warning about.
 */
async function reconcileWithServer(): Promise<void> {
    const server = tenantSwitchStore.mismatch?.server;
    if (server) await tenantSwitchStore.switchTo(server);
}

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