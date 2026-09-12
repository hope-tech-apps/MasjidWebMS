<template>
    <!-- The whole realm, direction and all.
         Every view under this router-view already sets its own :dir/:lang from
         the same module-level choice, and this is the bar ABOVE them: without
         it a parent who taps العربية gets an Arabic page under an English,
         left-to-right header — and "Sign out", the one control a stranded
         parent needs, is the last English word left on the screen. The two
         attributes are read from the same singleton the views read, so there is
         one choice and not two that can disagree. -->
    <div class="min-vh-100 bg-light" :dir="dir" :lang="lang">
        <nav class="navbar navbar-expand bg-white border-bottom sticky-top">
            <div class="container-fluid px-3 px-lg-4">
                <router-link :to="`/family/${masjidId}`" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
                    <img :src="'/manara-icon.svg'" alt="" width="32" height="32">
                    <!-- The school's own name, in whichever language the school
                         wrote it, so it is fenced with dir="auto" like every
                         other string this portal did not write. -->
                    <span class="fw-semibold text-dark" dir="auto">{{ orgName || t('layout_portal') }}</span>
                </router-link>

                <!-- `push-end`, not `ms-auto`: this app loads the LTR build of
                     Bootstrap 5, where `.ms-auto` compiles to a physical
                     `margin-left` and pins the account block to the LEFT of an
                     Arabic bar while reading, in the markup, as though it
                     should flip. Same argument, and same remedy, as the style
                     block at the foot of FamilyClass.vue. -->
                <div v-if="familyStore.isSignedIn" class="push-end d-flex align-items-center gap-3">
                    <span class="text-muted small d-none d-sm-inline" dir="auto">{{ familyStore.displayName }}</span>
                    <button class="btn btn-sm btn-outline-secondary" @click="signOut">{{ t('layout_sign_out') }}</button>
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
import { useFamilyLang } from '@/views/family/familyI18n';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();

// The same composable the five views use, and a module-level singleton, so the
// header is never in a different language from the page under it.
const { lang, dir, t } = useFamilyLang();

const masjidId = computed(() => String(route.params.masjidId ?? familyStore.masjidId ?? ''));
const orgName = ref('');
const orgLogo = ref<string | null>(null);

onMounted(async () => {
    // The school's name and logo, from the public directory endpoint — these are
    // the only things the portal shows before a parent has any credential, so a
    // stranger seeing them learns only what the app directory already publishes.
    try {
        const res = await FamilyApiService.get(`/api/mobile/masjids/${masjidId.value}`);
        orgName.value = res.data?.data?.name ?? '';
        orgLogo.value = res.data?.data?.logo?.original_url ?? null;
    } catch {
        orgName.value = '';
        orgLogo.value = null;
    }
});

const signOut = () => {
    familyStore.signOut();
    router.push(`/family/${masjidId.value}/sign-in`);
};
</script>

<style scoped>
/* Logical, because the LTR Bootstrap build's "logical"-sounding utilities are
   not: `.ms-auto` is `margin-left: auto` and stays on the left under RTL. One
   rule that is right in both directions, rather than a [dir="rtl"] override
   that would be a second copy to keep in step. */
.push-end { margin-inline-start: auto; }

/* Bootstrap gives `.navbar-brand` a physical `margin-right`, which under RTL
   sits between the brand and the edge of the bar instead of between the brand
   and what follows it. */
.navbar-brand { margin-right: 0; margin-inline-end: 1rem; }
</style>
