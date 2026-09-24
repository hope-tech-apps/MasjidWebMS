<template>
    <StudioPanel title="Brand" note="The client's logo and four colours. Nothing is chosen until you choose it.">
        <div class="studio-field">
            <span class="studio-label">Logo <span v-if="webChosen" class="req">*</span></span>
            <div class="d-flex flex-column flex-sm-row gap-3 align-items-start">
                <div class="logo-frame" :class="{ empty: !store.logoUrl }">
                    <img v-if="store.logoUrl" :src="store.logoUrl" alt="The draft's logo" />
                    <!-- Provisioned: the draft's private copy is deleted, and the store does not ask for it. -->
                    <span v-else-if="store.readOnly && store.draft?.logo" class="small text-muted text-center px-1">On the organisation</span>
                    <span v-else-if="store.draft?.logo && !store.logoError" class="spinner-border spinner-border-sm text-muted" role="status">
                        <span class="visually-hidden">Loading logo…</span>
                    </span>
                    <span v-else class="small text-muted">No logo</span>
                </div>
                <div class="d-flex flex-column gap-2">
                    <div class="d-flex flex-wrap gap-2">
                        <label class="btn btn-outline-success btn-sm mb-0" :class="{ disabled: store.logoBusy || !store.editable }">
                            {{ store.logoBusy ? 'Working…' : store.draft?.logo ? 'Replace logo' : 'Upload logo' }}
                            <input type="file" class="visually-hidden" accept="image/*,.svg"
                                :disabled="store.logoBusy || !store.editable" @change="onFile" />
                        </label>
                        <button v-if="store.draft?.logo" type="button" class="btn btn-outline-danger btn-sm"
                            :disabled="store.logoBusy || !store.editable" @click="remove">
                            Remove
                        </button>
                    </div>
                    <p v-if="store.draft?.logo" class="studio-hint">
                        {{ store.draft.logo.original_name }}
                        <template v-if="store.draft.logo.width && store.draft.logo.height">
                            · {{ store.draft.logo.width }}×{{ store.draft.logo.height }} px
                        </template>
                    </p>
                    <p v-if="store.readOnly && store.draft?.logo" class="studio-hint">
                        This logo, its icons and its share image now belong to the organisation; open it to see or
                        change them.
                    </p>
                    <p class="studio-hint">
                        PNG or JPEG is sent as it is. SVG, WebP, GIF and other images are converted to PNG here,
                        transparency kept, at most 2048 px on the longest side.
                    </p>
                    <p v-if="store.logoError" class="studio-error">{{ store.logoError }}</p>
                    <p v-if="needsLogo" class="studio-error">The website needs a logo before you can go on.</p>
                    <p v-if="report?.aspect_warning" class="studio-hint text-warning-emphasis">
                        This logo is more than twice as wide as it is tall, so it will look small in square spaces
                        such as the app icon.
                    </p>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-4">
            <BrandColourField v-for="field in COLOUR_FIELDS" :key="field.key" :id="field.key" :label="field.label"
                :model-value="brand[field.key]" :candidates="candidates" :disabled="!store.editable"
                empty-note="Not chosen yet." @update:model-value="brand[field.key] = $event" />
        </div>

        <div class="studio-field">
            <span class="studio-label">Text on the brand colours</span>
            <p class="studio-hint">
                Left to the theme, the text colour is picked for contrast. Set one by hand only when the client asks
                for it; the check below says whether it reads.
            </p>
            <div class="d-flex flex-wrap gap-4">
                <div v-for="ink in INK_FIELDS" :key="ink.key" class="d-flex flex-column gap-1">
                    <div class="form-check">
                        <input :id="`studio-ink-${ink.key}`" class="form-check-input" type="checkbox" :checked="!!inks[ink.key]"
                            :disabled="!store.editable || (!inks[ink.key] && !report)" @change="toggleInk(ink.key, ($event.target as HTMLInputElement).checked)" />
                        <label class="form-check-label" :for="`studio-ink-${ink.key}`">{{ ink.label }} by hand</label>
                    </div>
                    <BrandColourField v-if="inks[ink.key]" :id="`ink-${ink.key}`" :label="ink.label"
                        :model-value="inks[ink.key]" :disabled="!store.editable" @update:model-value="setInk(ink.key, $event)" />
                    <p v-else class="studio-hint">
                        Theme: <span v-if="autoInk(ink.key)" class="mini-swatch" :style="{ backgroundColor: autoInk(ink.key) ?? undefined }"></span>
                        {{ autoInk(ink.key) ?? 'once the colours are chosen' }}
                    </p>
                </div>
            </div>
        </div>

        <PaletteReport :report="report" :loading="store.previewLoading" :error="store.previewError" />
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * Foundation's Brand panel (docs/manara-studio-w1.md S5, D13, R16, R25).
 *
 *  - The logo is uploaded to the draft (the wizard only sampled it and threw it
 *    away): prepared in the browser (core/helpers/prepareLogo.ts), stored
 *    privately, and shown back as a blob fetched with the bearer.
 *  - Its commonest colours are offered as one-click choices for each of the
 *    four brand colours; none is applied on its own, because a draft starts
 *    with no colours and the operator chooses (R25).
 *  - The contrast check is the server's report for the answers as they stand,
 *    unsaved changes included (the preview endpoint), so what it says is what
 *    Step 3's gate will say.
 */
import BrandColourField from '@/components/super/studio/foundation/BrandColourField.vue';
import PaletteReport from '@/components/super/studio/foundation/PaletteReport.vue';
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { logoRequired, webSelected } from '@/core/studio/foundationGate';
import { StudioColourKey, StudioInkKey, StudioPalettePair } from '@/core/types/data/Studio';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed } from 'vue';

const COLOUR_FIELDS: { key: StudioColourKey; label: string }[] = [
    { key: 'primary_color', label: 'Primary' },
    { key: 'secondary_color', label: 'Secondary' },
    { key: 'accent_color', label: 'Accent' },
    { key: 'background_color', label: 'Background' },
];

/** Ink token => the report pair that grades it (PaletteContrast::INK_PAIRS). */
const INK_FIELDS: { key: StudioInkKey; label: string; pair: string }[] = [
    { key: 'onPrimary', label: 'Text on primary', pair: 'on_primary' },
    { key: 'onSecondary', label: 'Text on secondary', pair: 'on_secondary' },
    { key: 'onAccent', label: 'Text on accent', pair: 'on_accent' },
];

const store = useStudioDraftStore();

const brand = computed(() => store.answers.brand);
const inks = computed(() => brand.value.ink_overrides ?? {});
const candidates = computed(() => brand.value.extracted ?? []);

const webChosen = computed(() => webSelected(store.answers));
const needsLogo = computed(() => logoRequired(store.answers, !!store.draft?.logo));

/**
 * The report for the answers as they stand (the preview's, unsaved edits
 * included) once there is a preview; the saved draft's until then. A preview
 * with no report means the colours are no longer all chosen, and that wins.
 */
const report = computed(() => (store.preview ? store.preview.palette : store.draft?.palette) ?? null);

/** The ink the theme uses on one colour, from the report's own pair. */
function autoInk(key: StudioInkKey): string | null {
    const pairKey = INK_FIELDS.find((ink) => ink.key === key)?.pair;
    const pair: StudioPalettePair | undefined = report.value?.pairs.find((p) => p.key === pairKey);
    return pair?.foreground ?? null;
}

function setInk(key: StudioInkKey, value: string | null) {
    brand.value.ink_overrides = { ...(brand.value.ink_overrides ?? {}), [key]: value };
}

/**
 * Ticking starts from the ink the theme uses today, so nothing changes until the
 * operator changes it. It is offered only once the report exists, so there is
 * always a real starting colour.
 */
function toggleInk(key: StudioInkKey, byHand: boolean) {
    setInk(key, byHand ? autoInk(key) : null);
}

async function onFile(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file) await store.uploadLogo(file);
}

async function remove() {
    const result = await QSwal.fire({
        icon: 'warning',
        title: 'Remove the logo?',
        text: 'The file is deleted from the draft. The colours stay as they are.',
        confirmButtonText: 'Remove',
        cancelButtonText: 'Keep',
    });
    if (result.isConfirmed) await store.removeLogo();
}
</script>

<style scoped>
.logo-frame {
    width: 7rem;
    height: 7rem;
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: .4rem;
    flex: 0 0 auto;
    /* A checkerboard, so a transparent logo shows as transparent. */
    background-color: #fff;
    background-image:
        linear-gradient(45deg, #f0f0f0 25%, transparent 25%),
        linear-gradient(-45deg, #f0f0f0 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, #f0f0f0 75%),
        linear-gradient(-45deg, transparent 75%, #f0f0f0 75%);
    background-size: 16px 16px;
    background-position: 0 0, 0 8px, 8px -8px, -8px 0;
}

.logo-frame.empty {
    background-image: none;
}

.logo-frame img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.mini-swatch {
    display: inline-block;
    width: .9rem;
    height: .9rem;
    border-radius: .2rem;
    border: 1px solid #ccc;
    vertical-align: -2px;
}
</style>
