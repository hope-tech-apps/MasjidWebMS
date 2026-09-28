<template>
    <StudioPanel title="Brand" :note="`The logo, its images and the four colours ${orgName}'s website and apps are painted with.`">
        <div class="studio-field">
            <span class="studio-label">Images on file</span>
            <div class="d-flex flex-wrap gap-3">
                <figure v-for="image in images" :key="image.key" class="brand-image m-0">
                    <div class="image-frame" :class="{ empty: !image.url, wide: image.key === 'share_image_url' }">
                        <img v-if="image.url" :src="image.url" :alt="`${orgName}'s ${image.label.toLowerCase()}`" />
                        <span v-else class="small text-muted">None yet</span>
                    </div>
                    <figcaption class="small text-muted">{{ image.label }}</figcaption>
                </figure>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                <button type="button" class="btn btn-sm btn-outline-success" :disabled="!brand?.logo_url || store.regenerating" @click="regenerate">
                    <span v-if="store.regenerating" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    Regenerate favicon, touch icon and share image
                </button>
                <span v-if="!brand?.logo_url" class="studio-hint">Made from the logo, and this organisation has none.</span>
            </div>
            <p v-if="regenerateFailure" class="studio-error">{{ regenerateFailure }}</p>
            <p v-if="regenerated" class="studio-hint text-success" role="status">{{ regenerated }}</p>
        </div>

        <div class="d-flex flex-wrap gap-4">
            <BrandColourField v-for="field in COLOUR_FIELDS" :key="field.key" :id="`live-${field.key}`" :label="field.label"
                :model-value="store.colours[field.key]" :disabled="store.savingColours" empty-note="Not set." any-theme-form
                @update:model-value="setColour(field.key, $event)" />
        </div>

        <p v-if="!brand?.has_theme" class="studio-hint">
            This organisation has no saved theme yet; saving these colours creates one.
        </p>

        <p class="studio-hint">
            For a live organisation the check below is advisory: nothing is blocked, and Save colours names any pair that
            fails before it saves.
        </p>
        <PaletteReport :report="store.preview?.palette ?? null" :loading="store.previewQueued || store.previewLoading" :error="store.previewError"
            :stale="!!store.previewError" />

        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-success" :disabled="!canSave" @click="saveColours">
                <span v-if="store.savingColours" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ store.savingColours ? 'Saving…' : 'Save colours' }}
            </button>
            <button type="button" class="btn btn-outline-secondary" :disabled="!store.changedColours.length || store.savingColours"
                @click="discardColours">
                Discard
            </button>
            <span v-if="store.changedColours.length && !store.coloursComplete" class="studio-hint">
                All four colours need a value before they can be saved.
            </span>
            <span v-else-if="!store.changedColours.length" class="small text-muted">No unsaved changes.</span>
        </div>
        <p v-if="colourFailure" class="studio-error" role="alert">{{ colourFailure }}</p>
        <p v-if="coloursSaved" class="studio-hint text-success" role="status">{{ coloursSaved }}</p>
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * A live organisation's Brand in Studio (docs/manara-studio-w2.md S9, R14).
 *
 *  - The four colours reuse Foundation's BrandColourField. An edit is pending
 *    in the store and reaches the preview a moment later, so the mockups and the
 *    contrast report (PaletteReport, the server's PaletteContrast figures from
 *    the same preview) follow it. Nothing live changes until Save colours.
 *  - Save colours goes through the theme screen's own endpoint with the four
 *    colour fields only. The report gates Step 3 for a draft; for a live
 *    organisation it is advisory, so a failing pair does not block the save:
 *    the confirm dialog names each one and asks.
 *  - Regenerate is S8's: the favicon, touch icon and share image rebuilt from
 *    the current logo. On an organisation with none of the three it ADDS keys
 *    to its public settings, which the owner decides; the dialog says so in
 *    words. It uses the saved background colour, never an unsaved one.
 */
import BrandColourField from '@/components/super/studio/foundation/BrandColourField.vue';
import PaletteReport from '@/components/super/studio/foundation/PaletteReport.vue';
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { dialogHtml } from '@/core/studio/liveOrganisation';
import { failingPairLines } from '@/core/studio/paletteLabels';
import { StudioColourKey } from '@/core/types/data/Studio';
import { useStudioOrganisationStore } from '@/stores/super/studioOrganisationStore';
import { computed, ref } from 'vue';

const COLOUR_FIELDS: { key: StudioColourKey; label: string }[] = [
    { key: 'primary_color', label: 'Primary' },
    { key: 'secondary_color', label: 'Secondary' },
    { key: 'accent_color', label: 'Accent' },
    { key: 'background_color', label: 'Background' },
];

const store = useStudioOrganisationStore();

const colourFailure = ref<string | null>(null);
const coloursSaved = ref<string | null>(null);
const regenerateFailure = ref<string | null>(null);
const regenerated = ref<string | null>(null);

const orgName = computed(() => store.snapshot?.org.name || 'this organisation');
const brand = computed(() => store.snapshot?.sections.brand.data ?? null);

const images = computed(() => [
    { key: 'logo_url', label: 'Logo', url: brand.value?.logo_url ?? null },
    { key: 'favicon_url', label: 'Favicon', url: brand.value?.favicon_url ?? null },
    { key: 'touch_icon_url', label: 'Touch icon', url: brand.value?.touch_icon_url ?? null },
    { key: 'share_image_url', label: 'Share image', url: brand.value?.share_image_url ?? null },
]);

const canSave = computed(() => store.changedColours.length > 0 && store.coloursComplete && !store.savingColours);

function labelOf(key: StudioColourKey): string {
    return COLOUR_FIELDS.find((field) => field.key === key)?.label ?? key;
}

function setColour(key: StudioColourKey, value: string | null) {
    colourFailure.value = null;
    coloursSaved.value = null;
    store.setColour(key, value);
}

function discardColours() {
    colourFailure.value = null;
    coloursSaved.value = null;
    store.discardColours();
}

/** The failing pairs for the colours being saved, or null when the preview does not describe them. */
function failingPairs(): string[] | null {
    return failingPairLines(store.preview?.palette ?? null, {
        queued: store.previewQueued,
        loading: store.previewLoading,
        error: store.previewError,
    });
}

async function saveColours() {
    if (!canSave.value) return;

    const values = { ...store.colours };
    const before = store.savedColours;
    const failing = failingPairs();

    const sentences = [`The live website and apps of ${orgName.value} change colour now, as soon as you confirm.`];
    const items = store.changedColours.map((key) => `${labelOf(key)}: ${before[key] ?? 'not set'} → ${values[key]}`);

    if (failing === null) {
        sentences.push(store.previewError
            ? 'The contrast check failed for these colours, so it cannot say whether they are readable; look at them before you save.'
            : 'The contrast check has not finished for these colours; look at it before you save.');
    } else if (failing.length) {
        sentences.push(`${failing.length === 1 ? 'One text pair fails' : `${failing.length} text pairs fail`} the contrast check and will be hard to read: ${failing.join('; ')}. Save anyway?`);
    }

    const answer = await QSwal.fire({
        icon: 'warning',
        titleText: `Save these colours for ${orgName.value}?`,
        html: dialogHtml(sentences, items),
        confirmButtonText: failing?.length ? 'Save anyway' : 'Save colours',
        cancelButtonText: 'Not yet',
    });
    if (!answer.isConfirmed) return;

    colourFailure.value = null;
    coloursSaved.value = null;

    const result = await store.saveColours(values);
    if (!result.ok) {
        colourFailure.value = result.message;
        return;
    }
    coloursSaved.value = 'Colours saved. The live website and apps now use them.';
}

async function regenerate() {
    const current = brand.value;
    if (!current?.logo_url || store.regenerating) return;

    const org = orgName.value;
    const sentences = current.has_derivatives
        ? [`The favicon, touch icon and share image ${org} has now are replaced with new ones made from its current logo.`]
        : [
            `${org} has no favicon, home-screen icon or link-share image yet.`,
            'Regenerating adds all three to its website and to its settings payload, which changes its browser tab icon '
                + 'and how its links look when they are shared.',
            `${org} is a live organisation: go ahead only with the owner's go.`,
        ];

    if (store.changedColours.length) {
        sentences.push('They are made with the saved background colour, not the unsaved one on this screen.');
    }

    const answer = await QSwal.fire({
        icon: 'warning',
        titleText: current.has_derivatives ? 'Replace the three images?' : `Add the three images to ${org}?`,
        html: dialogHtml(sentences),
        confirmButtonText: current.has_derivatives ? 'Replace them' : 'The owner agreed: add them',
        cancelButtonText: 'Not now',
    });
    if (!answer.isConfirmed) return;

    regenerateFailure.value = null;
    regenerated.value = null;

    const result = await store.regenerateBrandAssets();
    if (!result.ok) {
        regenerateFailure.value = result.message;
        return;
    }
    regenerated.value = 'Regenerated. The new images are shown above.';
}
</script>

<style scoped>
.brand-image {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .25rem;
}

.image-frame {
    width: 5.5rem;
    height: 5.5rem;
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: .35rem;
    /* A checkerboard, so a transparent image shows as transparent. */
    background-color: #fff;
    background-image:
        linear-gradient(45deg, #f0f0f0 25%, transparent 25%),
        linear-gradient(-45deg, #f0f0f0 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, #f0f0f0 75%),
        linear-gradient(-45deg, transparent 75%, #f0f0f0 75%);
    background-size: 16px 16px;
    background-position: 0 0, 0 8px, 8px -8px, -8px 0;
}

.image-frame.wide {
    width: 10.5rem;
}

.image-frame.empty {
    background-image: none;
    text-align: center;
}

.image-frame img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
</style>
