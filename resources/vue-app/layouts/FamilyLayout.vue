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
                <div class="push-end d-flex align-items-center gap-3">
                    <!-- "Your schools": only once the parent holds a session at
                         more than one school (each school is its own slot, see
                         core/helpers/familySessions.ts), so the parent with one
                         school sees the bar exactly as before. Each row is the
                         school's own name, fenced with dir="auto" like every
                         other string this portal did not write. -->
                    <div v-if="signedInIds.length > 1" ref="schoolsMenu" class="position-relative"
                         @keydown.esc="schoolsOpen = false">
                        <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                aria-haspopup="true" :aria-expanded="schoolsOpen"
                                :aria-label="t('layout_your_schools')" :title="t('layout_your_schools')"
                                @click="schoolsOpen = !schoolsOpen">
                            <i class="bi bi-buildings"></i>
                            <span class="d-none d-sm-inline">{{ t('layout_your_schools') }}</span>
                            <i class="bi bi-chevron-down small"></i>
                        </button>
                        <ul v-if="schoolsOpen" class="dropdown-menu show schools-menu shadow-sm"
                            :aria-label="t('layout_your_schools')">
                            <li v-for="id in signedInIds" :key="id">
                                <router-link :to="`/family/${id}`" class="dropdown-item" dir="auto"
                                             :class="{ active: id === masjidId }"
                                             :aria-current="id === masjidId ? 'page' : undefined"
                                             @click="schoolsOpen = false">{{ schoolLabel(id) }}</router-link>
                            </li>
                        </ul>
                    </div>

                    <template v-if="signedInHere">
                        <!-- Only once the school has published a calendar
                             (`school_calendar_published` on the parent's own /me);
                             the route stays reachable by URL. Icon-only on a phone,
                             so the accessible name carries the words. -->
                        <router-link v-if="calendarPublished" :to="`/family/${masjidId}/calendar`"
                                     class="small text-decoration-none d-inline-flex align-items-center gap-1"
                                     :class="isCalendarActive ? 'fw-semibold text-success' : 'text-muted'"
                                     :aria-label="t('layout_calendar')" :title="t('layout_calendar')">
                            <i class="bi bi-calendar3"></i><span class="d-none d-sm-inline">{{ t('layout_calendar') }}</span>
                        </router-link>
                        <span class="text-muted small d-none d-sm-inline" dir="auto">{{ familyStore.displayNameFor(masjidId) }}</span>
                        <button class="btn btn-sm btn-outline-secondary" @click="signOut">{{ t('layout_sign_out') }}</button>
                    </template>
                </div>
            </div>
        </nav>

        <main class="container py-4" style="max-width: 900px;">
            <!-- Keyed by school, and held back while the parent has no session
                 for it. A parent moving between two schools stays inside this
                 one route record, so each screen must be REBUILT for the new
                 school rather than carried over: nothing school A loaded (a
                 class, a thread, a translation) survives into school B, and a
                 screen for a school the parent has not signed in to never gets to
                 send an unsigned request before the redirect below lands. -->
            <router-view v-if="allowed" :key="masjidId" />
        </main>
    </div>
</template>

<script setup lang="ts">
import { useFamilyStore } from '@/stores/familyStore';
import FamilyApiService from '@/core/services/FamilyApiService';
import { useFamilyLang } from '@/views/family/familyI18n';
// Nastaliq for Urdu. Declared for the whole realm, downloaded only when Urdu is
// actually on screen — see the file for why that costs other languages nothing.
import '@/views/family/urduFont.css';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { FAMILY_SESSIONS_KEY, familyRouteRedirect } from '@/core/helpers/familySessions';
import { setOrgTitle } from '@/core/pageTitle';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();

// The same composable the five views use, and a module-level singleton, so the
// header is never in a different language from the page under it.
const { lang, dir, t } = useFamilyLang();

const masjidId = computed(() => String(route.params.masjidId ?? ''));
const isCalendarActive = computed(() => route.name === 'familyCalendar');
const signedInHere = computed(() => familyStore.isSignedInTo(masjidId.value));
const signedInIds = computed(() => familyStore.signedInMasjidIds);

/**
 * The session check the route guard makes on the way in, made again here
 * because `beforeEnter` does not fire when only the school in the URL changes,
 * which is exactly what the "Your schools" menu does. `true` means this screen
 * may render for this school.
 */
const redirectTarget = computed(() =>
    familyRouteRedirect(familyStore.slots, masjidId.value, !!route.meta?.family));
const allowed = computed(() => redirectTarget.value === true);

watch(redirectTarget, (target) => {
    if (target !== true) router.replace(target);
}, { immediate: true });

// School names from the public directory endpoint, by school id. These are the
// only things the portal shows before a parent has any credential, so a
// stranger seeing them learns only what the app directory already publishes.
// The current school's name is the bar's title; the others fill the menu.
const schoolNames = ref<Record<string, string>>({});
const requestedNames = new Set<string>();

const loadSchoolName = async (id: string) => {
    if (!id || requestedNames.has(id)) return;
    requestedNames.add(id);

    try {
        const res = await FamilyApiService.get(`/api/mobile/masjids/${id}`);
        schoolNames.value = { ...schoolNames.value, [id]: res.data?.data?.name ?? '' };
    } catch {
        // Let a later visit try again rather than caching the failure.
        requestedNames.delete(id);
    }
};

const orgName = computed(() => schoolNames.value[masjidId.value] ?? '');
const schoolLabel = (id: string) => schoolNames.value[id] || `${t('layout_portal')} #${id}`;

// The tab title's organisation half (core/pageTitle.ts): whichever org this
// shell is showing, cleared on the way out so the next screen is not titled
// with a school the user has just left.
watch(orgName, (name) => setOrgTitle(name), { immediate: true });
onBeforeUnmount(() => setOrgTitle(null));

watch(masjidId, (id) => loadSchoolName(id), { immediate: true });
watch(signedInIds, (ids) => ids.forEach(loadSchoolName), { immediate: true });

// ---- the schools menu
const schoolsOpen = ref(false);
const schoolsMenu = ref<HTMLElement | null>(null);

const closeOnOutsideClick = (e: MouseEvent) => {
    if (schoolsOpen.value && schoolsMenu.value && !schoolsMenu.value.contains(e.target as Node)) {
        schoolsOpen.value = false;
    }
};

// Another tab signed in or out of a school: take storage's word for it. (The
// event fires only in tabs OTHER than the one that wrote.)
const onStorage = (e: StorageEvent) => {
    if (e.key === null || e.key === FAMILY_SESSIONS_KEY) familyStore.syncFromStorage();
};

onMounted(() => {
    document.addEventListener('click', closeOnOutsideClick);
    window.addEventListener('storage', onStorage);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeOnOutsideClick);
    window.removeEventListener('storage', onStorage);
});

// The menu belongs to one screen; a school change or a sign-out closes it.
watch([masjidId, signedInIds], () => { schoolsOpen.value = false; });

/**
 * Whether to offer the Calendar link: `school_calendar_published` on the
 * parent's own /me. The directory read above is public and carries nothing
 * about the family realm, so this is its own authenticated GET — made only
 * while signed in to THIS organisation, and again after a sign-in lands (the
 * layout outlives the sign-in screen under it) or the parent moves to another
 * school. Any failure just hides the link: the views own session handling, and
 * a missing link is harmless.
 */
const calendarPublished = ref(false);

const loadCalendarFlag = async () => {
    const id = masjidId.value;

    if (!signedInHere.value || !id) {
        calendarPublished.value = false;
        return;
    }

    try {
        const res = await FamilyApiService.get(`/api/family/masjids/${id}/me`);

        // A slow answer for the school the parent has since left must not set
        // the link for the one they are on now.
        if (id === masjidId.value) {
            calendarPublished.value = res.data?.data?.school_calendar_published === true;
        }
    } catch {
        if (id === masjidId.value) calendarPublished.value = false;
    }
};

watch([signedInHere, masjidId], loadCalendarFlag, { immediate: true });

// This school only: a parent signed in to two schools stays signed in to the
// other. The sign-in screen for this school is where they land.
const signOut = () => {
    familyStore.signOut(masjidId.value);
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

/* Bootstrap positions `.dropdown-menu` from a physical `left`, which under RTL
   opens the list off the wrong edge of its button. Logical again. */
.schools-menu {
    inset-inline-start: auto;
    inset-inline-end: 0;
    inset-block-start: 100%;
    min-width: 12rem;
    max-width: min(20rem, calc(100vw - 2rem));
    margin-top: 0.25rem;
}
.schools-menu .dropdown-item {
    white-space: normal;
    overflow-wrap: anywhere;
}
</style>
