<template>
    <div class="min-vh-100 mn-realm" :class="{ 'lunch-works-elsewhere': worksElsewhere }">
        <nav class="navbar navbar-expand sticky-top mn-topbar">
            <div class="container-fluid px-3 px-lg-4">
                <span class="navbar-brand d-flex align-items-center gap-2" :class="{ 'lunch-brand-shrinks': worksElsewhere }">
                    <span class="mn-topbar-tile">
                        <img v-if="logoUrl" :src="logoUrl" alt="" width="32" height="32">
                        <img v-else :src="'/manara-icon.svg'" alt="" width="32" height="32">
                    </span>
                    <span class="mn-topbar-name" :class="{ 'text-truncate': worksElsewhere }">{{ masjidName }}</span>
                </span>

                <div class="ms-auto d-flex align-items-center gap-3" :class="{ 'flex-shrink-0': worksElsewhere }">
                    <span class="mn-topbar-chip d-none d-sm-inline">Jummah Lunch</span>
                    <!-- Renders nothing for a lunch login, which is nearly every
                         one. Only someone who also teaches at a school (areas[]
                         on /api/lunch/user) gets the switch, with their schools
                         listed in it. No schools are passed: this shell has one
                         organisation and nothing to pick between. -->
                    <TeacherSchoolPicker :choices="[]" :current-id="null" :switching="switching"
                                         :areas="authStore.user?.areas" current-area="lunch"
                                         @choose-area="switchArea" />
                    <router-link to="/lunch/help/lunch" class="nav-link mn-topbar-link">Help</router-link>
                    <span v-if="staffName" class="mn-topbar-person d-none d-md-inline">{{ staffName }}</span>
                    <button class="btn btn-sm btn-outline-secondary" :disabled="signingOut" @click="signOut">
                        <span v-if="signingOut" class="spinner-border spinner-border-sm"></span>
                        <span v-else>Sign out</span>
                    </button>
                </div>
            </div>
        </nav>

        <main class="container py-4" style="max-width: 1100px;">
            <!-- The other area would not open (switchArea). Above the board, not
                 instead of it: the board still works. -->
            <div v-if="areaRefusal" class="alert alert-warning small d-flex align-items-start gap-2" role="alert">
                <span class="flex-grow-1">{{ areaRefusal }}</span>
                <button type="button" class="btn-close" aria-label="Dismiss this message" @click="areaRefusal = ''"></button>
            </div>

            <router-view />
        </main>
    </div>
</template>

<script setup lang="ts">
/**
 * The shell a Jummah-lunch login sees.
 *
 * There is no sidebar and, for a lunch login, no switcher, because there is
 * nothing else for them to go to — the whole realm is one board. That is the
 * design, not an unfinished layout: a nav item leading to a screen the server
 * will refuse is worse than no nav item.
 *
 * The one exception is somebody who ALSO teaches at a school. One login can hold
 * a role at each organisation, and the server then lists the places it works
 * (`areas[]` on the user payload). For that login alone the header carries the
 * teacher shell's own picker, with the schools in it, and switchArea() below
 * moves them there. A login without `areas` sees the header it always saw.
 */
import ApiService from '@/core/services/ApiService';
import TeacherSchoolPicker from '@/components/teacher/TeacherSchoolPicker.vue';
import { useAuthStore } from '@/stores/authStore';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { setOrgTitle } from '@/core/pageTitle';
import { useRouter } from 'vue-router';
import { useStaffChrome } from '@/core/helpers/staffChrome';
import { bumpTenantEpoch, forgetServerTenant } from '@/core/tenancy/tenantRequests';
import { resetTenantScopedStores } from '@/stores/plugins/tenantStoreReset';

// The staff theme (resources/css/custom/theme.css) for as long as this shell is up.
useStaffChrome();

const authStore = useAuthStore();
const router = useRouter();
const signingOut = ref(false);

// The masjid rides on the login payload (AuthController attaches it from the
// membership), so this shell needs no extra request to name the organisation.
const masjidName = computed<string>(() => authStore.user?.masjid?.name ?? 'Jummah Lunch');
const logoUrl = computed<string | null>(() => authStore.user?.masjid?.logo?.original_url ?? null);
const staffName = computed<string>(() => authStore.user?.name ?? '');

// The tab title's organisation half (core/pageTitle.ts): whichever org this
// shell is showing, cleared on the way out so the next screen is not titled
// with a school the user has just left.
watch(() => authStore.user?.masjid?.name, (name) => setOrgTitle(name), { immediate: true });
onBeforeUnmount(() => setOrgTitle(null));

// --------------------------------------------- somebody who also teaches

/**
 * Whether the server listed other places this login works. Only the header's
 * room is decided here (on a phone it goes onto two rows, and a long name may
 * shorten, so the switch fits); what the switch offers is the picker's business.
 */
const worksElsewhere = computed<boolean>(() => {
    const areas: unknown = authStore.user?.areas;

    return Array.isArray(areas) && areas.length > 0;
});

const switching = ref(false);

/** Why the other area did not open, in the server's words. Empty when there is nothing to say. */
const areaRefusal = ref('');

/**
 * Move to a school this login teaches at (`areas[]`, TeacherSchoolPicker).
 *
 * The teacher shell's switchArea, step for step. There is no switch endpoint:
 * the server answers every area's `/user` in that area's own voice, so moving IS
 * asking the other one and then starting its shell. It is asked FIRST, while the
 * board is still standing: if that role was taken away since the page loaded,
 * the person stays here and reads what the server said.
 *
 * Then what every organisation switch does, in the same order (responses on the
 * wire and rows in the stores belong to this organisation), the school and the
 * area are remembered so the load asks for them first, and the page starts again
 * from nothing in the teacher shell.
 */
async function switchArea(place: { area: 'teacher' | 'lunch'; masjidId: number; label: string; home: string }): Promise<void> {
    if (switching.value) return;

    switching.value = true;
    areaRefusal.value = '';

    const refusal = await authStore.askStaffArea(place.area);
    if (refusal !== null) {
        // The server's sentence as it came, closed with a full stop if it had none.
        const said = /[.!?]$/.test(refusal) ? refusal : `${refusal}.`;
        areaRefusal.value = `Manara could not open ${place.label}. The answer was: ${said} You are still in ${masjidName.value}.`;
        switching.value = false;
        return;
    }

    try {
        bumpTenantEpoch();
        resetTenantScopedStores();
    } catch (error) {
        // Not fatal: the load below is what actually guarantees a clean slate.
        console.warn('[tenant] store reset before the area switch failed', error);
    }

    forgetServerTenant();
    authStore.saveDashboardMasjidId(place.masjidId);
    authStore.saveStaffArea(place.area);
    window.location.assign(place.home);
}

const signOut = async () => {
    signingOut.value = true;
    try {
        await ApiService.post('/api/lunch/logout', {} as any);
    } catch {
        // Sign out locally regardless of what the server says.
    } finally {
        authStore.removeAuth();
        signingOut.value = false;
        router.push('/auth/sign-in');
    }
};
</script>

<style scoped>
/*
 * Only for a login that also teaches somewhere (worksElsewhere): the switch is one
 * more control in the header, `.navbar-brand` never wraps, and on a phone a long
 * organisation name would push Sign out off the edge. The name may shorten; the
 * controls keep their size. The teacher shell's rule, for the teacher shell's reason.
 */
.lunch-brand-shrinks {
    min-width: 0;
    flex: 0 1 auto;
}

@media (max-width: 575.98px) {
    /* On a phone the switch, with its word, does not fit beside the name: measured
       at 375px the name was left ten pixels, one letter. Two rows instead, as the
       teacher shell does for its schedule link: the name, then the controls. */
    .lunch-works-elsewhere .mn-topbar > .container-fluid { flex-wrap: wrap; row-gap: .25rem; }
    .lunch-works-elsewhere .mn-topbar .ms-auto { flex-wrap: wrap; gap: .375rem !important; max-width: 100%; }
}
</style>
