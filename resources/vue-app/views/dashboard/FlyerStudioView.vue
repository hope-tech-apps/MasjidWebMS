<template>
    <div>
        <PageDataContainer title="Flyer Studio" :hideButton="true">
            <template #headerButtons>
                <button v-if="store.design || store.unavailableDraft" type="button" :disabled="busy" class="btn btn-outline-secondary" @click="changeTemplate">
                    <i class="bi bi-arrow-left me-1"></i>Change design
                </button>
                <button
                    v-if="store.design"
                    type="button"
                    class="btn btn-success"
                    :disabled="!store.canSave || busy"
                    :title="saveHint"
                    @click="save"
                >
                    <span v-if="store.saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    Save draft
                </button>
            </template>

            <div class="container-fluid px-0">

                <!-- Loading -->
                <div v-if="store.loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <section v-if="!store.loading && !store.design && !store.unavailableDraft" class="mb-4" aria-labelledby="saved-drafts-title">
                    <h6 id="saved-drafts-title">Saved drafts</h6>
                    <div v-if="store.draftsError" class="alert alert-warning" role="alert">
                        {{ store.draftsError }}
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" :disabled="busy || store.draftsLoading" @click="store.fetchDrafts(store.draftsPage.current_page)">Try again</button>
                    </div>
                    <p v-if="store.draftsLoading" role="status">Loading saved drafts...</p>
                    <p v-else-if="!store.draftsError && !store.drafts.length" class="text-muted">No saved drafts yet.</p>
                    <div v-if="store.drafts.length" class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead><tr><th>Title</th><th>Template</th><th>Last saved</th><th>Created by</th><th>Actions</th></tr></thead>
                            <tbody>
                                <tr v-for="draft in store.drafts" :key="draft.id">
                                    <td>{{ draft.title }}</td>
                                    <td>{{ draft.template_name || 'Unavailable design' }}</td>
                                    <td>{{ savedDate(draft.updated_at) }}</td>
                                    <td>{{ draft.creator?.name || 'Not recorded' }}</td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" :disabled="busy || store.draftsLoading" @click="open(draft.id)">Open</button>
                                            <button type="button" class="btn btn-outline-danger" :disabled="busy || store.draftsLoading" @click="remove(draft)">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <Pagination :options="draftPagination" @pageChange="draftPageChanged" />
                    </div>
                </section>

                <div v-if="store.opening" class="text-muted mb-3" role="status">Opening draft...</div>
                <div v-for="warning in store.openWarnings" :key="warning" class="alert alert-warning" role="alert">{{ warning }}</div>
                <div v-if="store.unavailableDraft" class="alert alert-warning" role="alert">
                    This draft’s design is no longer available. Its saved contents and images are shown below. Preview and saving are unavailable; the draft has been kept.
                    <h6 class="mt-3">{{ store.title }}</h6>
                </div>
                <section v-if="Object.keys(store.preservedContent).length" class="mb-4">
                    <h6>{{ store.unavailableDraft ? 'Saved contents' : 'Fields no longer in this design' }}</h6>
                    <p v-if="!store.unavailableDraft" class="text-muted">These values cannot be edited or previewed here. They will be kept when you save.</p>
                    <dl v-for="(value, name) in store.preservedContent" :key="name">
                        <dt>{{ name }}</dt><dd class="text-break" style="white-space: pre-wrap">{{ savedValue(value) }}</dd>
                    </dl>
                </section>
                <section v-if="Object.keys(store.recoveryImages).length" class="mb-4">
                    <h6>Saved images</h6>
                    <figure v-for="(image, variant) in store.recoveryImages" :key="variant">
                        <figcaption>{{ variant === 'source' ? 'Original photo' : 'Cut-out' }}</figcaption>
                        <img :src="image" :alt="variant === 'source' ? 'Saved original photo' : 'Saved cut-out'" class="img-fluid" style="max-height: 240px">
                    </figure>
                </section>

                <!-- Step 1 — pick a design -->
                <div v-if="!store.loading && !store.design && !store.unavailableDraft">
                    <p class="text-muted">
                        Pick a design. Everything is drawn here in the browser, and the export is a
                        download — nothing is posted anywhere.
                    </p>

                    <div v-for="group in grouped" :key="group.kind" class="mb-4">
                        <h6 class="text-uppercase text-muted small fw-semibold mb-2">{{ group.label }}</h6>

                        <div class="row g-3">
                            <div v-for="design in group.designs" :key="design.key" class="col-md-6 col-xl-4">
                                <button type="button" class="design-card w-100 text-start" :disabled="busy" @click="store.selectTemplate(design.key)">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <span class="fw-semibold">{{ design.manifest.name }}</span>
                                        <span v-if="isMeasured(design.key)" class="badge bg-primary-subtle text-primary-emphasis">
                                            Measured
                                        </span>
                                    </div>
                                    <div class="small text-muted mt-1">{{ design.manifest.summary }}</div>
                                    <div v-if="!design.template" class="small text-warning-emphasis mt-2">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Not set up on this server — build and
                                        export only.
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2 — fill it in -->
                <div v-else-if="!store.loading && store.design" class="row g-4">

                    <!-- Editor -->
                    <div class="col-lg-5">
                        <div class="mb-4">
                            <label class="form-label fw-semibold mb-1" for="flyer-title">Draft name</label>
                            <input id="flyer-title" type="text" class="form-control" v-model.trim="store.title">
                            <div class="form-text">What this flyer is called in the dashboard. It is not printed on it.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold mb-1" for="flyer-palette">Colours</label>
                            <select id="flyer-palette" class="form-select" v-model="store.paletteKey" @change="store.changePalette">
                                <option v-if="store.savedPalette" value="" disabled>Saved colours</option>
                                <option :value="store.savedPalette ? 'brand' : ''">From our brand colours</option>
                                <option v-for="option in store.paletteOptions" :key="option.key" :value="option.key">
                                    {{ option.name }}
                                </option>
                            </select>
                            <div class="form-text">
                                Every colour is checked for contrast before it reaches the flyer.
                            </div>
                        </div>

                        <p v-if="store.localImageLabels.length" class="alert alert-warning small">
                            Images added to these fields are included in downloads but are not stored in drafts: {{ store.localImageLabels.join(', ') }}. Add them again after reopening.
                        </p>
                        <hr class="my-4">

                        <FlyerSlotForm
                            :slots="store.slots"
                            :content="store.content"
                            :images="store.images"
                            :cutoutSlot="store.cutoutSlot"
                            :cutoutStatus="store.cutoutStatus"
                            :cutoutError="store.cutoutError"
                            :cutoutAvailable="store.cutoutAvailable"
                            :uploading="store.uploading"
                            @update-slot="store.setSlot"
                            @update-list="store.setListItems"
                            @add-item="store.addListItem"
                            @remove-item="store.removeListItem"
                            @image="onImage"
                            @clear-image="store.clearImage"
                            @use-cutout="store.useCutout"
                            @retry-photo="store.retryUpload"
                        />
                    </div>

                    <!-- Preview + export -->
                    <div class="col-lg-7">
                        <div class="preview-column">
                            <div v-if="!fontLoaded" class="alert alert-warning py-2 px-3 small">
                                <i class="bi bi-type me-1"></i>
                                Montserrat has not loaded, so the flyer is drawing in Helvetica. The layout is right;
                                the lettering is not what the masjid's flyers use — so exporting is held back until
                                the font is there rather than letting a PNG go out in the wrong face.
                            </div>

                            <div v-if="store.paletteAudit.length" class="alert alert-danger py-2 px-3 small">
                                <i class="bi bi-exclamation-octagon me-1"></i>
                                These colours do not clear the contrast minimum:
                                {{ store.paletteAudit.map((entry) => entry.name).join(', ') }}. Pick another palette.
                            </div>

                            <div v-if="overflowing" class="alert alert-warning py-2 px-3 small">
                                <i class="bi bi-arrows-collapse me-1"></i>
                                There is more here than the layout holds. Shorten a line — or, for a long programme,
                                split it across two flyers rather than shrinking the type.
                            </div>

                            <div v-if="!store.templatesAvailable" class="alert alert-secondary py-2 px-3 small">
                                <i class="bi bi-cloud-slash me-1"></i>
                                The flyer designs are not set up on this server yet. You can build and export this
                                flyer; saving it as a draft needs the designs seeded first.
                            </div>

                            <FlyerPreview
                                ref="previewRef"
                                :manifest="store.manifest"
                                :html="store.design.html"
                                :content="store.renderContent"
                                :cssVars="store.cssVars"
                                @overflow="onOverflow"
                            />

                            <div class="mt-3">
                                <div v-if="store.missingRequired.length" class="small text-muted mb-2">
                                    Still needed:
                                    <span class="fw-semibold">
                                        {{ store.missingRequired.map((slot) => slot.label).join(', ') }}
                                    </span>
                                </div>

                                <div class="d-flex flex-wrap gap-2">
                                    <button
                                        v-for="size in exportSizes"
                                        :key="size.key"
                                        type="button"
                                        class="btn btn-outline-primary"
                                        :disabled="!store.isComplete || exporting === size.key"
                                        :title="size.note"
                                        @click="exportPng(size)"
                                    >
                                        <span v-if="exporting === size.key" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                        <i v-else class="bi bi-download me-1"></i>
                                        {{ size.label }}
                                    </button>
                                </div>

                                <div class="form-text mt-2">
                                    Exported at full canvas resolution, not at the size of this preview.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeMount, onMounted, onUnmounted, reactive, ref, watchEffect } from 'vue';
import Swal from 'sweetalert2';
import PageDataContainer from '@/components/PageDataContainer.vue';
import FlyerPreview from '@/components/flyer/FlyerPreview.vue';
import FlyerSlotForm from '@/components/flyer/FlyerSlotForm.vue';
import Pagination from '@/components/partials/Pagination.vue';
import { PageChangeData } from '@/core/types/elements/Pagination';
import { useFlyersStore } from '@/stores/masjid/flyersStore';
import {
    Flyer,
    FlyerDesign,
    FlyerExportSize,
    FlyerKind,
    FLYER_EXPORT_SIZES
} from '@/core/types/data/masjid-related/Flyer';

// Store
const store = useFlyersStore();

// State
const previewRef = ref<InstanceType<typeof FlyerPreview> | null>(null);
const exporting = ref<string | null>(null);
const overflowing = ref(false);
const fontLoaded = ref(true);

const exportSizes: FlyerExportSize[] = FLYER_EXPORT_SIZES;

/** The only design reproduced from real flyers; the rest are archetypes. */
const MEASURED_KEYS = ['food'];

/**
 * The flyer face. Every template names it and none of them import it — the app supplies
 * it (resources/flyer-templates/index.json records that it is self-hosted), and these
 * are the weights the templates actually ask for.
 */
const FLYER_FONT_FAMILY = 'Montserrat';
const FLYER_FONT_WEIGHTS = [400, 500, 800, 900];

/** Long enough that two different faces cannot measure the same width by accident. */
const FONT_PROBE = 'HALAL FOOD SALE 1234567890';

const KIND_LABELS: Record<FlyerKind, string> = {
    food: 'Food sale',
    event: 'Events',
    janazah: 'Janazah'
};

// Computed
const busy = computed(() => store.saving || store.uploading || store.opening || store.deleting !== null);
// The shared pager takes toRefs(options), so keep this object's identity stable.
const draftPagination = reactive({ itemsTotal: 0, perPage: 15, currentPage: 1 });
watchEffect(() => {
    draftPagination.itemsTotal = store.draftsPage.total;
    draftPagination.perPage = store.draftsPage.per_page;
    draftPagination.currentPage = store.draftsPage.current_page;
});
const grouped = computed(() => {
    const kinds: FlyerKind[] = ['food', 'event', 'janazah'];

    return kinds
        .map((kind) => ({
            kind,
            label: KIND_LABELS[kind],
            designs: store.designs.filter((design: FlyerDesign) => design.manifest.kind === kind)
        }))
        .filter((group) => group.designs.length > 0);
});

const saveHint = computed(() => {
    if (!store.canSave) return 'This design is not set up on this server yet.';
    return 'Save this flyer as a draft.';
});

// Lifecycle
onBeforeMount(async () => {
    await store.initialise();
});

onMounted(async () => {
    fontLoaded.value = await verifyFont();
});

onUnmounted(() => {
    store.stopPolling();
});

// Methods
function savedDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : 'Not recorded';
}

function savedValue(value: unknown): string {
    return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
}

function draftPageChanged(data: PageChangeData): void {
    if (!busy.value && data.toPage !== store.draftsPage.current_page) store.fetchDrafts(data.toPage);
}

async function open(id: number): Promise<void> {
    if (busy.value) return;
    try {
        await store.openDraft(id);
    } catch (error: any) {
        Swal.fire({ icon: 'error', title: 'Error!', text: error?.response?.data?.message ?? error?.message ?? 'The draft could not be opened.' });
    }
}

async function remove(draft: Flyer): Promise<void> {
    if (busy.value) return;
    const result = await Swal.fire({
        title: 'Delete this draft?',
        text: `“${draft.title}” and its unshared stored images will be permanently deleted.`,
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, delete draft', cancelButtonText: 'Cancel', confirmButtonColor: '#d33'
    });
    if (!result.isConfirmed || busy.value) return;
    try {
        await store.deleteDraft(draft.id);
    } catch (error: any) {
        Swal.fire({ icon: 'error', title: 'Error!', text: error?.response?.data?.message ?? error?.message ?? 'The draft could not be deleted.' });
    }
}

function isMeasured(key: string): boolean {
    return MEASURED_KEYS.includes(key);
}

/**
 * Is the flyer face actually loaded and in use?
 *
 * document.fonts.check() on its own cannot answer this, which is why the old guard
 * could never fire: a family that matches no @font-face has nothing outstanding to
 * load, so check() says true and a flyer drawn entirely in Helvetica passes.
 *
 * So the faces are REQUESTED first — fonts.load() is what actually pulls a self-hosted
 * @font-face down, and a browser only fetches a face something is painting with —
 * then waited on, then checked, and finally confirmed by measurement: if Montserrat is
 * really resolving, a line of text is not the width the fallback would have drawn it.
 */
async function verifyFont(): Promise<boolean> {
    const fonts = document.fonts;
    if (!fonts) return false;

    try {
        // load() answers with the faces it matched, which is also how we learn whether
        // the app declares any at all.
        const matched = await Promise.all(
            FLYER_FONT_WEIGHTS.map((weight) => fonts.load(`${weight} 100px "${FLYER_FONT_FAMILY}"`))
        );
        await fonts.ready;

        // A declared face has to have finished loading. Where the app declares none the
        // family can only be coming from the operating system, and check() has no
        // opinion worth having about it — the measurement is then the whole answer.
        const declared = matched.some((faces) => faces.length > 0);
        const settled = !declared || FLYER_FONT_WEIGHTS.every(
            (weight) => fonts.check(`${weight} 100px "${FLYER_FONT_FAMILY}"`)
        );

        return settled && resolvesToFlyerFont();
    } catch {
        // A browser without the Font Loading API cannot promise the face is there, and
        // a flyer is not worth exporting on a promise nobody made.
        return false;
    }
}

/** Measured against a fallback, because that is the only thing that proves the face. */
function resolvesToFlyerFont(): boolean {
    const ctx = document.createElement('canvas').getContext('2d');
    if (!ctx) return false;

    ctx.font = '900 100px monospace';
    const fallback = ctx.measureText(FONT_PROBE).width;

    ctx.font = `900 100px "${FLYER_FONT_FAMILY}", monospace`;
    const flyer = ctx.measureText(FONT_PROBE).width;

    return flyer > 0 && Math.abs(flyer - fallback) > 0.5;
}

function onOverflow(value: boolean): void {
    overflowing.value = value;
}

async function changeTemplate(): Promise<void> {
    if (busy.value) return;
    const result = await Swal.fire({
        title: 'Start a different design?',
        text: 'What you have filled in will be cleared.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, change design'
    });

    if (result.isConfirmed) store.reset();
}

async function onImage(slot: string, file: File): Promise<void> {
    try {
        await store.setImage(slot, file);
    } catch (error: any) {
        Swal.fire({ icon: 'error', title: 'Error!', text: error?.message ?? 'That image could not be used.' });
    }
}

async function exportPng(size: FlyerExportSize): Promise<void> {
    if (!previewRef.value) return;

    exporting.value = size.key;

    try {
        // Re-verified at the moment of export rather than trusted from mount: the font
        // can still be in flight when the screen opens, and a PNG in the fallback face
        // looks almost right — which is the kind of wrong that gets noticed after it is
        // printed and taped to the door.
        fontLoaded.value = await verifyFont();

        if (!fontLoaded.value) {
            throw new Error(
                `${FLYER_FONT_FAMILY} has not loaded, so this flyer would export in Helvetica instead of the `
                + 'lettering the masjid\'s flyers use. Reload the page and try again.'
            );
        }

        await previewRef.value.download(size, filename(size));
    } catch (error: any) {
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: error?.message ?? 'The flyer could not be exported.'
        });
    } finally {
        exporting.value = null;
    }
}

function filename(size: FlyerExportSize): string {
    const base = (store.title || store.manifest?.name || 'flyer')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

    return `${base || 'flyer'}-${size.width}x${size.height}.png`;
}

async function save(): Promise<void> {
    try {
        await store.saveDraft();
        Swal.fire({
            icon: 'success',
            title: 'Saved!',
            text: 'The flyer was saved as a draft.',
            timer: 2000,
            showConfirmButton: false
        });
    } catch (error: any) {
        const data = error?.response?.data?.data;
        const text = (data && typeof data === 'object')
            ? Object.values(data).flat().join(' ')
            : (error?.response?.data?.message ?? error?.message ?? 'Failed to save the flyer.');
        Swal.fire({ icon: 'error', title: 'Error!', text });
    }
}
</script>

<style scoped>
.design-card {
    height: 100%;
    padding: 1rem;
    border: 1px solid #dee2e6;
    border-radius: 10px;
    background: #fff;
    transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
}

.design-card:hover {
    border-color: #0d6efd;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
    transform: translateY(-2px);
}

/* The preview follows the form down a long manifest. */
.preview-column {
    position: sticky;
    top: 1rem;
}
</style>
