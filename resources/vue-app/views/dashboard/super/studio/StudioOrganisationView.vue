<template>
    <div class="card border-0 py-4 px-3 w-100 studio">
        <div class="card-header bg-white border-0 d-flex flex-column gap-1">
            <router-link :to="{ name: 'studio.drafts', query: { tab: 'organisations' } }" class="small text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i>Manara Studio
            </router-link>
            <div class="d-flex flex-wrap align-items-center gap-2 min-w-0">
                <div class="card-title fs-4 fw-semibold mb-0 text-break">{{ store.snapshot?.org.name || 'Organisation' }}</div>
                <span v-if="typeLabel" class="status-pill">{{ typeLabel }}</span>
                <span class="status-pill live">Live organisation</span>
            </div>
        </div>

        <div class="card-body w-100">
            <div v-if="store.loading && !store.snapshot" class="text-center py-5">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>

            <div v-else-if="store.error && !store.snapshot" class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                {{ store.error }}
                <button type="button" class="btn btn-sm btn-outline-danger ms-3" @click="load">Retry</button>
            </div>

            <template v-else-if="store.snapshot">
                <div v-if="store.error" class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span class="flex-grow-1">This organisation could not be read again, so what is shown may be out of date. {{ store.error }}</span>
                    <button type="button" class="btn btn-sm btn-warning" @click="load">Retry</button>
                </div>

                <p v-if="draftStore.optionsError" class="alert alert-danger small">
                    {{ draftStore.optionsError }}
                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" @click="draftStore.fetchOptions()">Retry</button>
                </p>

                <div class="row g-4">
                    <div class="col-12 col-xl-7 d-flex flex-column gap-3">
                        <template v-for="key in sectionKeys" :key="key">
                            <LiveFeaturesCard v-if="key === 'features'" :vertical-label="typeLabel" />
                            <LiveBrandCard v-else-if="key === 'brand'" />
                            <LiveSectionCard v-else-if="isReadOnly(key)" :title="SECTION_TITLES[key]" :rows="rowsOf(key)"
                                :edit-in="editInOf(key)" :screen="EDIT_SCREENS[key]" :org-id="orgId"
                                :empty-text="EMPTY_SECTION_TEXT[key]" />
                        </template>
                    </div>

                    <div class="col-12 col-xl-5">
                        <button type="button" class="btn btn-outline-success btn-sm w-100 d-xl-none mb-2"
                            :aria-expanded="previewOpen" aria-controls="live-preview-column" @click="previewOpen = !previewOpen">
                            {{ previewOpen ? 'Hide preview' : 'Show preview' }}
                        </button>
                        <div id="live-preview-column" class="studio-preview-column" :class="{ 'd-none d-xl-block': !previewOpen }">
                            <LivePreviewColumn />
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * An organisation that already exists, opened in Manara Studio
 * (docs/manara-studio-w2.md S9, R14). No draft is made: the screen reads the
 * organisation's snapshot (OrganisationSnapshot::of) and lays it out in
 * Studio's section order.
 *
 *  - Features and Brand are changed here, through the writers that already
 *    exist (the bulk capability PATCH, the theme save, S8's regeneration),
 *    each behind a Save and a confirm, because every one is live at once.
 *  - Every other section is read-only, with "Edit in {screen}" to the admin
 *    screen that writes it.
 *  - The preview column draws the organisation with the unsaved changes, as
 *    the draft's does; the layout (col-xl-7 / col-xl-5, the sticky column below
 *    the fixed header) is StudioView's, so the two Studio screens read alike.
 *
 * Leaving with unsaved Features or Brand changes asks first (the browser's own
 * prompt for a reload or a closed tab); nothing is autosaved here.
 */
import LiveBrandCard from '@/components/super/studio/live/LiveBrandCard.vue';
import LiveFeaturesCard from '@/components/super/studio/live/LiveFeaturesCard.vue';
import LivePreviewColumn from '@/components/super/studio/live/LivePreviewColumn.vue';
import LiveSectionCard from '@/components/super/studio/live/LiveSectionCard.vue';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { EDIT_IN_STUDIO, EDIT_SCREENS, EMPTY_SECTION_TEXT, SECTION_TITLES, orgTypeName, sectionRows, SectionRow } from '@/core/studio/liveOrganisation';
import { StudioOrganisationSectionKey } from '@/core/types/data/StudioOrganisation';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { useStudioOrganisationStore } from '@/stores/super/studioOrganisationStore';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute } from 'vue-router';

type ReadOnlySection = keyof typeof EDIT_SCREENS;

const store = useStudioOrganisationStore();
// Reference data only: the organisation types' and prayer choices' names (/onboarding/options).
const draftStore = useStudioDraftStore();
const route = useRoute();

const previewOpen = ref(false);

const orgId = computed(() => Number(route.params.id));

/** The sections in the order the server serves them (Studio's order). */
const sectionKeys = computed(() => Object.keys(store.snapshot?.sections ?? {}) as StudioOrganisationSectionKey[]);

const typeLabel = computed(() => orgTypeName(draftStore.options, store.snapshot?.org.org_type));

/** A section edited on another screen: the server says so, and this screen has a name for it. */
function isReadOnly(key: StudioOrganisationSectionKey): key is ReadOnlySection {
    return key in EDIT_SCREENS && store.snapshot?.sections[key].edit_in !== EDIT_IN_STUDIO;
}

function rowsOf(key: StudioOrganisationSectionKey): SectionRow[] {
    return store.snapshot ? sectionRows(key, store.snapshot, draftStore.options) : [];
}

function editInOf(key: StudioOrganisationSectionKey): string {
    return store.snapshot?.sections[key].edit_in ?? '';
}

async function load() {
    if (!Number.isFinite(orgId.value)) return;
    await Promise.all([store.fetchSnapshot(orgId.value), draftStore.fetchOptions()]);
}

// Another organisation in the same view (the id in the URL changed).
watch(orgId, async (id, previous) => {
    if (id === previous || !Number.isFinite(id)) return;
    await load();
});

function onBeforeUnload(event: BeforeUnloadEvent) {
    if (!store.hasUnsavedChanges()) return;
    event.preventDefault();
    event.returnValue = '';
}

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    void load();
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
});

/** Whether the pending changes may be dropped: none, or the operator says so. */
async function mayDropChanges(): Promise<boolean> {
    if (!store.hasUnsavedChanges()) return true;

    const answer = await QSwal.fire({
        icon: 'warning',
        title: 'Leave without saving?',
        text: 'The feature and colour changes made here have not been applied to the organisation.',
        confirmButtonText: 'Leave',
        cancelButtonText: 'Stay',
    });
    return answer.isConfirmed;
}

onBeforeRouteLeave(async () => {
    if (!(await mayDropChanges())) return false;
    store.reset();
    return true;
});

// The same screen for another organisation (the id in the URL changed): opening
// it drops this one's pending changes, so the same question comes first.
onBeforeRouteUpdate(async (to, from) => {
    if (to.params.id === from.params.id) return true;
    return mayDropChanges();
});
</script>

<style scoped>
.min-w-0 {
    min-width: 0;
}

.status-pill {
    display: inline-block;
    border: 1px solid var(--input-border, #ccc);
    border-radius: 2rem;
    padding: .15rem .65rem;
    font-size: .8rem;
    color: #555;
}

.status-pill.live {
    background: var(--cgreen, #01b151);
    border-color: var(--cgreen, #01b151);
    color: #fff;
}

@media (min-width: 1200px) {
    .studio-preview-column {
        position: sticky;
        top: calc(var(--dash-header-height, 4rem) + 1rem);
        max-height: calc(100vh - var(--dash-header-height, 4rem) - 2rem);
        overflow-y: auto;
    }
}
</style>
