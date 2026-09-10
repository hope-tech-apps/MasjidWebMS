<template>
    <div class="min-vh-100 bg-light">
        <nav class="navbar navbar-expand bg-white border-bottom sticky-top">
            <div class="container-fluid px-3 px-lg-4">
                <span class="navbar-brand d-flex align-items-center gap-2">
                    <img v-if="logoUrl" :src="logoUrl" alt="" width="32" height="32"
                         class="rounded" style="object-fit: cover;">
                    <img v-else :src="'/manara-icon.svg'" alt="" width="32" height="32">
                    <span class="fw-semibold text-dark">{{ masjidName }}</span>
                </span>

                <div class="ms-auto d-flex align-items-center gap-3">
                    <span class="text-muted small d-none d-sm-inline">Jummah Lunch</span>
                    <span v-if="staffName" class="text-muted small d-none d-md-inline">{{ staffName }}</span>
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
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';

const authStore = useAuthStore();
const router = useRouter();
const signingOut = ref(false);

// The masjid rides on the login payload (AuthController attaches it from the
// membership), so this shell needs no extra request to name the organisation.
const masjidName = computed<string>(() => authStore.user?.masjid?.name ?? 'Jummah Lunch');
const logoUrl = computed<string | null>(() => authStore.user?.masjid?.logo?.original_url ?? null);
const staffName = computed<string>(() => authStore.user?.name ?? '');

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
