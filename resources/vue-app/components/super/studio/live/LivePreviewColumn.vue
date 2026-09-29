<template>
    <section class="studio-preview" aria-labelledby="live-preview-title">
        <div class="d-flex align-items-center justify-content-between gap-2">
            <h5 id="live-preview-title" class="fw-semibold mb-0">Preview</h5>
            <span v-if="store.previewQueued || store.previewLoading" class="spinner-border spinner-border-sm text-muted" role="status">
                <span class="visually-hidden">Updating the preview…</span>
            </span>
        </div>

        <p class="studio-hint mb-0">{{ store.previewColoursIncomplete ? CAPTION_SAVED : CAPTION }}</p>
        <p v-if="store.previewColoursIncomplete" class="studio-hint mb-0" role="status">
            The colours you are typing are not complete yet, so this still shows the saved colours.
        </p>

        <div v-if="store.previewError" class="alert alert-danger py-2 px-3 mb-0 small d-flex flex-wrap align-items-center gap-2" role="alert">
            <span class="flex-grow-1">{{ store.previewError }}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="store.refreshPreview()">Retry</button>
        </div>

        <div v-if="!preview" class="text-center py-4">
            <div v-if="store.previewQueued || store.previewLoading" class="spinner-border text-success" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <p v-else-if="!platforms.length" class="studio-hint mb-0">
            This organisation has no platform on record and no website switched on, so there is nothing to draw.
        </p>

        <template v-else>
            <ul class="nav nav-tabs" role="tablist" aria-label="Platforms">
                <li v-for="(platform, index) in platforms" :key="platform" class="nav-item" role="presentation">
                    <button :id="`live-preview-tab-${platform}`" ref="tabButtons" type="button" class="nav-link" role="tab"
                        :class="{ active: platform === activePlatform }" :aria-selected="platform === activePlatform"
                        :aria-controls="platform === activePlatform ? `live-preview-pane-${platform}` : undefined"
                        :tabindex="platform === activePlatform ? 0 : -1"
                        @click="chosen = platform" @keydown="onTabKey($event, index)">
                        {{ platformLabel(platform) }}
                    </button>
                </li>
            </ul>

            <div :id="`live-preview-pane-${activePlatform}`" role="tabpanel"
                :aria-labelledby="`live-preview-tab-${activePlatform}`" class="d-flex flex-column gap-2">
                <template v-if="activePlatform === 'web'">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Screen size">
                            <button v-for="option in VIEWPORTS" :key="option.value" type="button" class="btn"
                                :class="viewport === option.value ? 'btn-success' : 'btn-outline-success'"
                                :aria-pressed="viewport === option.value" @click="viewport = option.value">
                                {{ option.label }}
                            </button>
                        </div>
                        <span class="small text-muted">Its pages as they are now</span>
                    </div>
                    <WebFrame :pages="preview.web.pages" :theme-layout="preview.web.theme_layout" :tokens="preview.web_tokens"
                        :home-slug="preview.web.home_slug" :name="preview.org.name" :host="preview.org.host" :logo-url="logoUrl"
                        :logo-missing="!logoUrl" :viewport="viewport" :max-scale="viewport === 'mobile' ? .6 : 1" />
                </template>
                <template v-else-if="activePlatform === 'ios'">
                    <p v-if="preview.app.ios.source === 'features'" class="studio-hint mb-0">
                        The app menu is switched off for every phone, so iPhones draw the menu from the stored feature list, not the switches.
                    </p>
                    <IosFrame :preview="preview" :logo-url="logoUrl" />
                </template>
                <template v-else-if="activePlatform === 'android'">
                    <p class="studio-hint mb-0">
                        The Android app now in production (version 13) always draws these four tabs, whatever the switches or the stored menu say.
                    </p>
                    <AndroidFrame :preview="preview" :logo-url="logoUrl" />
                </template>
                <TvFrame v-else-if="activePlatform === 'tvos'" :preview="preview" :answers="tvAnswers" :logo-url="logoUrl" />
            </div>

            <PlatformContrastList :rows="preview.platform_contrast" />
        </template>
    </section>
</template>

<script setup lang="ts">
/**
 * The preview column of a live organisation in Studio (docs/manara-studio-w2.md
 * S9): the draft preview's frames and tabs (components/super/studio/preview/),
 * fed from the organisation store instead of the draft's.
 *
 * Everything drawn is the store's `preview`, the server's derivation for this
 * organisation with the pending feature and colour changes applied in memory
 * (POST /studio/organisations/{id}/preview). One tab per platform it serves:
 * the publishing row's, plus the web whenever the website is on. The website
 * frame draws the organisation's own pages. The logo is the one on file.
 *
 * While a colour is half typed the server is not sent the colours, so the
 * caption says the mockup shows the saved ones (store.previewColoursIncomplete).
 *
 * The TV board computes its prayer times from the organisation's coordinates,
 * timezone and calculation settings. It is told no iqama was given: an iqama
 * time drawn here would be invented, whatever the organisation has on record.
 *
 * The tabs follow the ARIA tabs pattern, as the draft's preview does
 * (core/helpers/tabKeys.ts).
 */
import AndroidFrame from '@/components/super/studio/preview/AndroidFrame.vue';
import IosFrame from '@/components/super/studio/preview/IosFrame.vue';
import PlatformContrastList from '@/components/super/studio/preview/PlatformContrastList.vue';
import TvFrame from '@/components/super/studio/preview/TvFrame.vue';
import WebFrame from '@/components/super/studio/preview/WebFrame.vue';
import { tabIndexForKey } from '@/core/helpers/tabKeys';
import { emptyAnswers } from '@/core/studio/draftAnswers';
import { platformLabel } from '@/core/studio/platforms';
import { StudioAnswers, StudioPlatform } from '@/core/types/data/Studio';
import { useStudioOrganisationStore } from '@/stores/super/studioOrganisationStore';
import { computed, nextTick, ref } from 'vue';

const CAPTION = 'A themed mockup of this live organisation with your unsaved changes; nothing is saved until you press Save.';
/** While a colour edit is incomplete the mockup is drawn without it, so it must not claim to show it. */
const CAPTION_SAVED = 'A themed mockup of this live organisation as it is saved now, with your unsaved switch changes; nothing is saved until you press Save.';

const VIEWPORTS: { value: 'desktop' | 'mobile'; label: string }[] = [
    { value: 'desktop', label: 'Desktop' },
    { value: 'mobile', label: 'Phone' },
];

const store = useStudioOrganisationStore();

const preview = computed(() => store.preview);
const platforms = computed<StudioPlatform[]>(() => preview.value?.platforms ?? []);
const logoUrl = computed(() => store.snapshot?.sections.brand.data.logo_url ?? null);

/** The TV board's answers: where and how the prayer times are computed, and nothing else. */
const tvAnswers = computed<StudioAnswers>(() => {
    const identity = store.snapshot?.sections.identity.data;
    const calculation = store.snapshot?.sections.prayer.data.calculation ?? null;

    return {
        ...emptyAnswers(),
        identity: {
            name: identity?.name ?? null,
            latitude: identity?.latitude ?? null,
            longitude: identity?.longitude ?? null,
            timezone: identity?.timezone ?? null,
        },
        prayer: {
            method: calculation?.method ?? null,
            madhab: calculation?.madhab ?? null,
            high_latitude_rule: calculation?.high_latitude_rule ?? null,
            iqama_given: false,
        },
    };
});

/** The tab the operator picked; the first platform when that one is not served. */
const chosen = ref<StudioPlatform | null>(null);
const activePlatform = computed<StudioPlatform | null>(() =>
    chosen.value && platforms.value.includes(chosen.value) ? chosen.value : platforms.value[0] ?? null);

const tabButtons = ref<HTMLButtonElement[]>([]);

/** Arrow keys, Home and End choose another tab and move focus to it. */
async function onTabKey(event: KeyboardEvent, index: number) {
    const target = tabIndexForKey(event.key, index, platforms.value.length);
    if (target === null) return;

    event.preventDefault();
    chosen.value = platforms.value[target];
    await nextTick();
    tabButtons.value.find((button) => button.id === `live-preview-tab-${platforms.value[target]}`)?.focus();
}

const viewport = ref<'desktop' | 'mobile'>('desktop');
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
