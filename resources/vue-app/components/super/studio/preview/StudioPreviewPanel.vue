<template>
    <section class="studio-preview" aria-labelledby="studio-preview-title">
        <div class="d-flex align-items-center justify-content-between gap-2">
            <h5 id="studio-preview-title" class="fw-semibold mb-0">Preview</h5>
            <span v-if="store.previewLoading" class="spinner-border spinner-border-sm text-muted" role="status">
                <span class="visually-hidden">Updating the preview…</span>
            </span>
        </div>

        <p class="studio-hint mb-0">{{ CAPTION }}</p>

        <div v-if="store.previewError" class="alert alert-danger py-2 px-3 mb-0 small d-flex flex-wrap align-items-center gap-2" role="alert">
            <span class="flex-grow-1">{{ store.previewError }}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="store.refreshPreview()">Retry</button>
        </div>

        <div v-if="!preview" class="text-center py-4">
            <div v-if="store.previewLoading" class="spinner-border text-success" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <p v-else-if="!platforms.length" class="studio-hint mb-0">Choose a platform in Foundation to see it here.</p>

        <template v-else>
            <ul class="nav nav-tabs" role="tablist" aria-label="Platforms">
                <li v-for="platform in platforms" :key="platform" class="nav-item" role="presentation">
                    <button :id="`studio-preview-tab-${platform}`" type="button" class="nav-link" role="tab"
                        :class="{ active: platform === activePlatform }" :aria-selected="platform === activePlatform"
                        :aria-controls="`studio-preview-pane-${platform}`" @click="chosen = platform">
                        {{ platformLabel(platform) }}
                    </button>
                </li>
            </ul>

            <div :id="`studio-preview-pane-${activePlatform}`" role="tabpanel"
                :aria-labelledby="`studio-preview-tab-${activePlatform}`" class="d-flex flex-column gap-2">
                <p v-if="!preview.web_tokens" class="studio-hint mb-0">Drawn in greys until all four colours are chosen.</p>

                <template v-if="activePlatform === 'web'">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Screen size">
                            <button v-for="option in VIEWPORTS" :key="option.value" type="button" class="btn"
                                :class="viewport === option.value ? 'btn-success' : 'btn-outline-success'"
                                :aria-pressed="viewport === option.value" @click="viewport = option.value">
                                {{ option.label }}
                            </button>
                        </div>
                        <span class="small" :class="preview.web.approved ? 'text-success' : 'text-muted'">
                            {{ layoutStatus }}
                        </span>
                    </div>
                    <WebFrame :pages="preview.web.pages" :theme-layout="preview.web.theme_layout" :tokens="preview.web_tokens"
                        :name="preview.org.name" :host="preview.org.host" :logo-url="store.logoUrl"
                        :logo-missing="!store.draft?.logo" :viewport="viewport" :max-scale="viewport === 'mobile' ? .6 : 1" />
                </template>
                <IosFrame v-else-if="activePlatform === 'ios'" :preview="preview" :logo-url="store.logoUrl" />
                <AndroidFrame v-else-if="activePlatform === 'android'" :preview="preview" :logo-url="store.logoUrl" />
                <TvFrame v-else-if="activePlatform === 'tvos'" :preview="preview" :answers="store.answers" :logo-url="store.logoUrl" />
            </div>

            <PlatformContrastList :rows="preview.platform_contrast" />
        </template>
    </section>
</template>

<script setup lang="ts">
/**
 * Studio's preview column (docs/manara-studio-w1.md S5, D6): one tab per
 * platform the draft has chosen, each a themed mockup rather than a build.
 *
 * Everything drawn comes from the store's `preview`, the server's one
 * derivation (POST /studio/drafts/{id}/preview, R19), which refreshPreview()
 * keeps current a moment after every edit, unsaved edits included. The tabs are
 * the platforms that derivation says the draft has, so the column never shows a
 * platform the answers do not. The contrast rows under the frames are advisory
 * (R16); the Brand panel's report is the one that gates.
 */
import AndroidFrame from '@/components/super/studio/preview/AndroidFrame.vue';
import IosFrame from '@/components/super/studio/preview/IosFrame.vue';
import PlatformContrastList from '@/components/super/studio/preview/PlatformContrastList.vue';
import TvFrame from '@/components/super/studio/preview/TvFrame.vue';
import WebFrame from '@/components/super/studio/preview/WebFrame.vue';
import { platformLabel } from '@/core/studio/platforms';
import { StudioPlatform } from '@/core/types/data/Studio';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, ref } from 'vue';

const CAPTION = 'The apps look the same for every organisation; only colours, logo, name, tabs and menu change.';

const VIEWPORTS: { value: 'desktop' | 'mobile'; label: string }[] = [
    { value: 'desktop', label: 'Desktop' },
    { value: 'mobile', label: 'Phone' },
];

const store = useStudioDraftStore();

const preview = computed(() => store.preview);
const platforms = computed<StudioPlatform[]>(() => preview.value?.platforms ?? []);

/** The tab the operator picked; the first platform when that one is no longer chosen. */
const chosen = ref<StudioPlatform | null>(null);
const activePlatform = computed<StudioPlatform | null>(() =>
    chosen.value && platforms.value.includes(chosen.value) ? chosen.value : platforms.value[0] ?? null);

const viewport = ref<'desktop' | 'mobile'>('desktop');

/** Which layout the website frame shows, and whether it is approved (R27). */
const layoutStatus = computed(() => {
    const web = preview.value?.web;
    if (!web) return '';
    if (web.preset_source === 'default') return 'The default layout, until one is chosen at Layout';
    return web.approved ? 'Layout approved' : 'Layout chosen, not approved yet';
});
</script>

<style scoped>
.studio-preview {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    padding: 1rem;
    background: #fff;
    display: flex;
    flex-direction: column;
    gap: .75rem;
}

.studio-hint {
    color: #6c757d;
    font-size: .8rem;
}

.nav-tabs .nav-link {
    color: #6c757d;
}

.nav-tabs .nav-link.active {
    color: #198754;
    font-weight: 600;
}
</style>
