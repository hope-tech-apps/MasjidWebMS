<template>
    <section class="studio-layout d-flex flex-column gap-3" aria-labelledby="studio-layout-title">
        <header class="d-flex flex-column gap-1">
            <h5 id="studio-layout-title" class="fw-semibold mb-0">Layout</h5>
            <p class="studio-hint mb-0">
                The starter website {{ orgName }} gets. Each card is this client's site with that layout: their name,
                their switches and what they told you, with a marked space wherever something is still to come.
                Show one in the preview, then approve it.
            </p>
            <p v-if="!webChosen" class="studio-hint mb-0">
                This client has no website among its platforms, so no layout is needed for Generate.
            </p>
            <p v-else-if="presets && !approvedKey" class="studio-error mb-0">
                The website needs an approved layout before Generate.
            </p>
        </header>

        <div v-if="loading" class="text-muted small" role="status">
            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
            Loading the layouts…
        </div>

        <div v-else-if="store.presetsError || !presets" class="alert alert-danger d-flex flex-wrap align-items-center gap-2 mb-0" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span class="flex-grow-1">
                The layouts could not be loaded.
                <span v-if="store.presetsError" class="d-block small">{{ store.presetsError }}</span>
            </span>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="load">Retry</button>
        </div>

        <p v-else-if="!presets.length" class="studio-hint mb-0">There are no layouts for this organisation type.</p>

        <div v-else class="row g-3">
            <div v-for="preset in presets" :key="preset.key" class="col-12 col-md-6 col-xxl-4">
                <article class="preset-card h-100" :class="{ chosen: preset.key === chosenKey, approved: preset.key === approvedKey }"
                    :aria-labelledby="`preset-title-${preset.key}`">
                    <div class="thumb">
                        <WebFrame v-if="thumbs[preset.key]?.preview" :pages="thumbs[preset.key]!.preview!.web.pages"
                            :theme-layout="thumbs[preset.key]!.preview!.web.theme_layout" :tokens="thumbs[preset.key]!.preview!.web_tokens"
                            :name="thumbs[preset.key]!.preview!.org.name" :host="thumbs[preset.key]!.preview!.org.host"
                            :logo-url="store.logoUrl" :logo-missing="!store.draft?.logo" :interactive="false" />
                        <div v-else-if="thumbs[preset.key]?.error" class="thumb-state text-danger small">
                            {{ thumbs[preset.key]!.error }}
                            <button type="button" class="btn btn-sm btn-link p-0" @click="loadThumb(preset.key)">Retry</button>
                        </div>
                        <div v-else class="thumb-state">
                            <span class="spinner-border spinner-border-sm text-muted" role="status">
                                <span class="visually-hidden">Drawing this layout…</span>
                            </span>
                        </div>
                    </div>

                    <div class="d-flex flex-column gap-2 p-3 flex-grow-1">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <h6 :id="`preset-title-${preset.key}`" class="fw-semibold mb-0">{{ preset.label }}</h6>
                            <span v-if="preset.is_default" class="badge text-bg-light border">Default</span>
                            <span v-if="preset.key === approvedKey" class="badge bg-success">Approved</span>
                            <span v-else-if="preset.key === chosenKey" class="badge text-bg-secondary">In the preview</span>
                        </div>
                        <p v-if="preset.summary" class="small text-muted mb-0">{{ preset.summary }}</p>
                        <p class="small mb-0">
                            <span class="text-muted">Pages:</span> {{ preset.pages.map((page) => page.title).join(', ') }}
                        </p>
                        <p v-if="preset.key === approvedKey && approvedAt" class="small text-success mb-0">
                            Approved {{ approvedAt }}
                        </p>

                        <div class="d-flex flex-wrap gap-2 mt-auto pt-1">
                            <button type="button" class="btn btn-sm btn-outline-success"
                                :disabled="store.readOnly || preset.key === chosenKey" @click="choose(preset.key)">
                                Show in preview
                            </button>
                            <button type="button" class="btn btn-sm btn-success"
                                :disabled="store.readOnly || preset.key === approvedKey" @click="approve(preset.key)">
                                Approve this layout
                            </button>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
/**
 * Step 2, Layout (docs/manara-studio-w1.md S4, S5, R27, D8).
 *
 * The cards are the server's presets for this organisation type (GET
 * /api/admin/studio/layout-presets, config/studio_layouts.php): their names,
 * summaries and pages come from there, and nothing here names a preset, a
 * page or a section type. Each card's thumbnail is a WebFrame of the plan the
 * server makes of THIS draft with that preset (the preview endpoint, with only
 * the preset swapped), so the operator compares the sites the client would
 * actually get: sections a switched-off feature drops are gone, and the
 * client's own words are in place.
 *
 * "Show in preview" writes the choice (`layout.preset`) and clears any
 * approval, which was of another layout; the preview column then draws it.
 * "Approve this layout" writes `layout.preset` and `layout.approved_at`
 * together. Generate needs the website's approved preset (R27, enforced again
 * by S8 on the server). The store autosaves the section like any other edit.
 */
import WebFrame from '@/components/super/studio/preview/WebFrame.vue';
import { approvedLayout, chosenLayout } from '@/core/studio/draftAnswers';
import { StudioPreview } from '@/core/types/data/Studio';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, onMounted, reactive, watch } from 'vue';

type Thumb = { preview: StudioPreview | null; error: string | null };

const store = useStudioDraftStore();

const orgType = computed(() => store.answers.identity.org_type ?? null);
const orgName = computed(() => store.answers.identity.name?.trim() || 'this organisation');
const webChosen = computed(() => (store.answers.platforms.platforms ?? []).includes('web'));

const presets = computed(() => store.presets);
const loading = computed(() => store.presetsLoading && !store.presets);

/**
 * The draft's choice, only when it is one of this organisation type's presets:
 * a preset kept from before the type changed is not a choice any more, and the
 * server previews the default in its place (StudioPreview `preset_source`).
 */
const chosenKey = computed(() => {
    const key = store.answers.layout.preset ?? null;
    return key && presets.value?.some((preset) => preset.key === key) ? key : null;
});
const approvedKey = computed(() => (store.answers.layout.approved_at ? chosenKey.value : null));

const approvedAt = computed(() => {
    const value = store.answers.layout.approved_at;
    const date = value ? new Date(value) : null;
    return date && !isNaN(date.getTime())
        ? date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
        : '';
});

const thumbs = reactive<Record<string, Thumb>>({});

async function loadThumb(key: string) {
    thumbs[key] = { preview: null, error: null };
    const outcome = await store.previewPreset(key);
    thumbs[key] = outcome.ok ? { preview: outcome.data, error: null } : { preview: null, error: outcome.message };
}

function loadThumbs() {
    for (const preset of presets.value ?? []) {
        void loadThumb(preset.key);
    }
}

function load() {
    if (orgType.value) void store.fetchPresets(orgType.value);
}

function choose(key: string) {
    if (store.readOnly || key === chosenKey.value) return;
    Object.assign(store.answers.layout, chosenLayout(key));
}

function approve(key: string) {
    if (store.readOnly) return;
    Object.assign(store.answers.layout, approvedLayout(key, new Date()));
}

// Each list of presets that arrives (every visit, and Retry) redraws every card.
watch(presets, () => loadThumbs());

onMounted(() => {
    // Every visit reloads the presets: the organisation type may have changed
    // in Foundation since they were last fetched, and the list is small.
    load();
});
</script>

<style scoped>
.preset-card {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    background: #fff;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.preset-card.chosen {
    border-color: #6c757d;
}

.preset-card.approved {
    border-color: var(--cgreen, #01b151);
    box-shadow: 0 0 0 1px var(--cgreen, #01b151);
}

.thumb {
    background: #f5f6f8;
    padding: .5rem;
    border-bottom: 1px solid var(--input-border, #e6e6e6);
    pointer-events: none;
}

.thumb-state {
    min-height: 8rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    pointer-events: auto;
}

.studio-hint {
    color: #6c757d;
    font-size: .85rem;
}

.studio-error {
    color: #a02622;
    font-size: .85rem;
}
</style>
