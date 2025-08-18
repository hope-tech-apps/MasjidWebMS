<template>
    <div id="dashboard_layout" class="">
        <div id="dashboard_overall_layer"></div>
        <DashboardAside />
        <div id="header_main_container">
            <DashboardHeader />
            <main id="dashboard_main">
                <RouterView></RouterView>
            </main>
            <DashboardFooter />
        </div>
    </div>
</template>

<script setup lang="ts">
import DashboardAside from '@/components/dashboard/DashboardAside.vue';
import DashboardHeader from '@/components/dashboard/DashboardHeader.vue';
import { RouterView, useRouter } from 'vue-router';
import { onBeforeMount, onMounted, onUpdated, ref } from 'vue';
import DashboardFooter from '@/components/dashboard/DashboardFooter.vue';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';

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

// Stores
const authStore = useAuthStore();
const masjidStore = useMasjidStore();

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