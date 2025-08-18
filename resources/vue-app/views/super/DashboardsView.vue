<template>
    <div class="d-flex flex-column align-items-center justify-content-center gap-5 w-100 vh-100">
        <div class="d-flex flex-column align-items-center justify-content-center gap-2">
            <div class="display-6 text-cgreen text-center fw-bold">
                Masjid App Admin Login
            </div>

            <span class="fs-5 text-cdark text-center">
                go to
                <router-link to="/dashboard/super" @click.prevent="goToSuperDashboard"
                    class="text-success super-dashboard-link">
                    Super Admin Dashboard
                </router-link>
                or
            </span>

            <div class="fs-2 text-cdark text-center fw-bold">
                Select A Mosque
            </div>
        </div>

        <div class="container">
            <div class="d-flex flex-row flex-wrap align-items-center justify-content-center gap-4">
                <button v-for="masjid in masjids" type="button" @click="setAuthUserMasjidId(masjid)"
                    class="btn btn-light card border-0 shadow mosque-card">
                    <div
                        class="card-body text-center d-flex flex-column align-items-center justify-content-center gap-4 w-100">
                        <div class="rounded-2 overflow-hidden mosque-logo">
                            <img :src="masjid.logo?.original_url" :alt="`${masjid.name}_logo`" class="w-100 rounded-2">
                        </div>
                        <div class="fs-6 fw-bold text-cdark">
                            {{ masjid.name }}
                        </div>
                    </div>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { SUPER_DASHBOARD_ASIDE_MENU } from '@/core/constants/dashboardAsideMenuItems';
import { Masjid } from '@/core/types/data/Masjid';
import { useAuthStore } from '@/stores/authStore';
import { useDashboardAsideStore } from '@/stores/config/dashboardAsideStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useMasjidsStore } from '@/stores/super/masjidsStore';
import { computed, onBeforeMount } from 'vue';
import { useRouter } from 'vue-router';

onBeforeMount(async () => {
    if (authStore.user?.type === "SuperAdmin") {
        await masjidsStore.fetchMasjidsList()
    } else {
        router.push('/');
    }
});

// Routing
const router = useRouter();

// Stores
const masjidsStore = useMasjidsStore();
const authStore = useAuthStore();
const masjidStore = useMasjidStore();
const dashboardAsideStore = useDashboardAsideStore();

// Computed
const masjids = computed(() => {
    return masjidsStore.masjids
});

async function setAuthUserMasjidId(masjid: Masjid) {
    authStore.dashboardMasjidId = masjid.id;
    authStore.saveDashboardMasjidId(masjid.id);
    await masjidStore.fetchMasjid(masjid.id)
        .finally(async () => {
            await router.push('/masjid');
        });
}

const goToSuperDashboard = () => {
    dashboardAsideStore.asideMenuItems = SUPER_DASHBOARD_ASIDE_MENU;
    masjidStore.masjid = null;
    authStore.dashboardMasjidId = null;
    router.push('/dashboard/super');
}

</script>

<style scoped>
.mosque-card {
    width: 16rem;
    height: 14rem;
}

.mosque-card .mosque-logo {
    max-width: 100%;
    height: 8rem;
    object-fit: contain;
}

.mosque-card .mosque-logo img {
    height: 100%;
    object-fit: contain;
}

.super-dashboard-link {
    text-decoration: none;
}
</style>