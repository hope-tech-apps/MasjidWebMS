<template>
    <div class="min-vh-100 bg-light">
        <nav class="navbar navbar-expand bg-white border-bottom sticky-top">
            <div class="container-fluid px-3 px-lg-4">
                <router-link :to="`/family/${masjidId}`" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
                    <img :src="'/manara-icon.svg'" alt="" width="32" height="32">
                    <span class="fw-semibold text-dark">{{ orgName || 'Family Portal' }}</span>
                </router-link>

                <div v-if="familyStore.isSignedIn" class="ms-auto d-flex align-items-center gap-3">
                    <span class="text-muted small d-none d-sm-inline">{{ familyStore.displayName }}</span>
                    <button class="btn btn-sm btn-outline-secondary" @click="signOut">Sign out</button>
                </div>
            </div>
        </nav>

        <main class="container py-4" style="max-width: 900px;">
            <router-view />
        </main>
    </div>
</template>

<script setup lang="ts">
import { useFamilyStore } from '@/stores/familyStore';
import FamilyApiService from '@/core/services/FamilyApiService';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();

const masjidId = computed(() => String(route.params.masjidId ?? familyStore.masjidId ?? ''));
const orgName = ref('');

onMounted(async () => {
    // The school's name, from the public directory endpoint — this is the one
    // thing the portal shows before a parent has any credential, so a stranger
    // seeing it learns only what the app directory already publishes.
    try {
        const res = await FamilyApiService.get(`/api/mobile/masjids/${masjidId.value}`);
        orgName.value = res.data?.data?.name ?? '';
    } catch {
        orgName.value = '';
    }
});

const signOut = () => {
    familyStore.signOut();
    router.push(`/family/${masjidId.value}/sign-in`);
};
</script>
