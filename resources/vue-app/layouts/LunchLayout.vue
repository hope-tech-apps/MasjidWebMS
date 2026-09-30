<template>
    <div class="min-vh-100 mn-realm">
        <nav class="navbar navbar-expand sticky-top mn-topbar">
            <div class="container-fluid px-3 px-lg-4">
                <span class="navbar-brand d-flex align-items-center gap-2">
                    <span class="mn-topbar-tile">
                        <img v-if="logoUrl" :src="logoUrl" alt="" width="32" height="32">
                        <img v-else :src="'/manara-icon.svg'" alt="" width="32" height="32">
                    </span>
                    <span class="mn-topbar-name">{{ masjidName }}</span>
                </span>

                <div class="ms-auto d-flex align-items-center gap-3">
                    <span class="mn-topbar-chip d-none d-sm-inline">Jummah Lunch</span>
                    <span v-if="staffName" class="mn-topbar-person d-none d-md-inline">{{ staffName }}</span>
                    <button class="btn btn-sm btn-outline-secondary" :disabled="signingOut" @click="signOut">
                        <span v-if="signingOut" class="spinner-border spinner-border-sm"></span>
                        <span v-else>Sign out</span>
                    </button>
                </div>
            </div>
        </nav>

        <main class="container py-4" style="max-width: 1100px;">
            <router-view />
        </main>
    </div>
</template>

<script setup lang="ts">
/**
 * The shell a Jummah-lunch login sees.
 *
 * There is no sidebar and no masjid switcher, because there is nothing else for
 * them to go to — the whole realm is one board. That is the design, not an
 * unfinished layout: a nav item leading to a screen the server will refuse is
 * worse than no nav item.
 */
import ApiService from '@/core/services/ApiService';
import { useAuthStore } from '@/stores/authStore';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { setOrgTitle } from '@/core/pageTitle';
import { useRouter } from 'vue-router';
import { useStaffChrome } from '@/core/helpers/staffChrome';

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
