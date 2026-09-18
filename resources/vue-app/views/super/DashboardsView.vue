<template>
    <!-- `min-vh-100`, not `vh-100`: with eight organisations the grid is taller
         than a phone screen, and a fixed 100vh box makes the page scroll inside
         a container that was told it is exactly one screen tall — which is why
         the heading slid away and the cards looked cut off. `py-4` keeps the
         first card off the status bar. -->
    <div class="d-flex flex-column align-items-center justify-content-center gap-4 gap-md-5 w-100 min-vh-100 py-4">
        <div class="d-flex flex-column align-items-center justify-content-center gap-2">
            <img :src="'/manara-icon.svg'" alt="Manara" width="84" height="84" class="mb-1" />
            <div class="text-cgreen text-center fw-bold brand-title">
                Manara
            </div>

            <span class="fs-5 text-cdark text-center">
                go to
                <router-link to="/dashboard/super" @click.prevent="goToSuperDashboard"
                    class="text-success super-dashboard-link">
                    Super Admin Dashboard
                </router-link>
                or
            </span>

            <div class="text-cdark text-center fw-bold pick-title">
                Select A Mosque
            </div>
        </div>

        <div class="container">
            <!-- A GRID, not fixed-width cards in a flex row. The cards were
                 16rem wide, so on a 375px phone exactly one fitted per row and
                 the list became eight screens of scrolling. `auto-fill` with a
                 minimum column gives two per row on a phone and as many as fit
                 on a laptop, with no breakpoint list to maintain. -->
            <div class="mosque-grid">
                <button v-for="masjid in masjids" :key="masjid.id" type="button"
                    @click="setAuthUserMasjidId(masjid)"
                    class="btn btn-light card border-0 shadow mosque-card">
                    <div
                        class="card-body text-center d-flex flex-column align-items-center justify-content-between gap-3 w-100 h-100">
                        <div class="rounded-2 overflow-hidden mosque-logo d-flex align-items-center justify-content-center w-100">
                            <!-- An organisation with no logo used to render an
                                 empty bordered box (the ZZ sandbox on
                                 production). Show its initials instead, so every
                                 card says what it is. -->
                            <img v-if="masjid.logo?.original_url" :src="masjid.logo.original_url"
                                :alt="`${masjid.name} logo`" class="rounded-2 mosque-logo__img" loading="lazy">
                            <span v-else class="mosque-logo__initials text-cgreen fw-bold">{{ initials(masjid.name) }}</span>
                        </div>
                        <div class="fw-bold text-cdark mosque-card__name">
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

/** Up to two initials, for an organisation with no logo uploaded. */
const initials = (name?: string | null): string =>
    (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('') || '?';

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
/* Two columns on the narrowest phone, more as the screen allows. `1fr` rather
   than a fixed width so two cards always fill the row instead of leaving a
   ragged gutter. */
.mosque-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr));
    gap: 1rem;
    width: 100%;
}

@media (min-width: 576px) {
    .mosque-grid {
        grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
        gap: 1.5rem;
    }
}

.mosque-card {
    width: 100%;
    /* Was a fixed 14rem. A long name like "Intellicor International Academy"
       wrapped to three lines and pushed itself against the card's edge. */
    min-height: 11rem;
}

@media (min-width: 576px) {
    .mosque-card {
        min-height: 14rem;
    }
}

.mosque-card .mosque-logo {
    height: 5rem;
}

@media (min-width: 576px) {
    .mosque-card .mosque-logo {
        height: 8rem;
    }
}

.mosque-card .mosque-logo__img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.mosque-card .mosque-logo__initials {
    font-size: 2rem;
    line-height: 1;
}

/* The name has to stay readable at 150px wide: smaller on a phone, and allowed
   to wrap rather than overflow its card. */
.mosque-card__name {
    font-size: 0.85rem;
    line-height: 1.2;
    overflow-wrap: anywhere;
}

@media (min-width: 576px) {
    .mosque-card__name {
        font-size: 1rem;
    }
}

/* Bootstrap has no responsive variants of `display-*` or `fs-*`, so
   `display-md-4` and `fs-md-2` would have been dead classes that quietly did
   nothing. Sized here instead. */
.brand-title {
    font-size: calc(1.475rem + 2.7vw);
    font-weight: 700;
    line-height: 1.2;
}

.pick-title {
    font-size: 1.25rem;
}

@media (min-width: 576px) {
    .brand-title {
        font-size: 3.5rem;
    }

    .pick-title {
        font-size: 1.75rem;
    }
}

.super-dashboard-link {
    text-decoration: none;
}
</style>