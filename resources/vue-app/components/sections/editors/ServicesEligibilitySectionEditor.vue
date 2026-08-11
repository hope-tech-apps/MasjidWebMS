<template>
    <div class="services-eligibility-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                    placeholder="e.g., Our Services"
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Description</label>
                <textarea
                    class="form-control"
                    v-model="localContent.description"
                    @input="emitUpdate"
                    rows="2"
                    placeholder="Optional text shown under the heading"
                ></textarea>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Layout</label>
                <select
                    class="form-select"
                    v-model="localContent.layout"
                    @change="emitUpdate"
                >
                    <option value="cards">Cards</option>
                    <option value="list">List (one per row)</option>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Columns</label>
                <select
                    class="form-select"
                    v-model.number="localContent.columns"
                    @change="emitUpdate"
                    :disabled="localContent.layout !== 'cards'"
                >
                    <option :value="1">1 per row</option>
                    <option :value="2">2 per row</option>
                    <option :value="3">3 per row</option>
                    <option :value="4">4 per row</option>
                </select>
                <div class="form-text">Only applies to the cards layout.</div>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Background Color</label>
                <input
                    type="color"
                    class="form-control form-control-color flex-shrink-0"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                />
            </div>

            <!-- Services -->
            <div class="col-12 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Services</h6>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        @click="addService"
                    >
                        <i class="bi bi-plus-circle"></i> Add Service
                    </button>
                </div>

                <div
                    v-for="(service, index) in localContent.services"
                    :key="index"
                    class="card mb-3"
                >
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">{{ service.name || `Service ${index + 1}` }}</h6>
                            <div class="btn-group">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveServiceUp(index)"
                                    :disabled="index === 0"
                                    title="Move Up"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveServiceDown(index)"
                                    :disabled="index === localContent.services.length - 1"
                                    title="Move Down"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    @click="removeService(index)"
                                    title="Remove Service"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 mb-3">
                                <ImageDraggableInput
                                    :name="`service_image_${index}`"
                                    type="photo"
                                    label="Service Image"
                                    :current-image-src="service.image_url || undefined"
                                    @image-change="(data) => onServiceImageChange(index, data)"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="service.name"
                                    @input="emitUpdate"
                                    placeholder="e.g., Primary Medical Care"
                                    required
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea
                                    class="form-control"
                                    v-model="service.description"
                                    @input="emitUpdate"
                                    rows="3"
                                    placeholder="What this service covers and who provides it"
                                ></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="localContent.services.length === 0" class="alert alert-info">
                    No services added yet. Click "Add Service" to list the first one.
                </div>
            </div>

            <!-- Eligibility -->
            <div class="col-12 mb-3">
                <h6 class="mb-2">Who Qualifies</h6>

                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Eligibility Heading</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="localContent.eligibility.heading"
                                    @input="emitUpdate"
                                    placeholder="e.g., Eligibility"
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Intro</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="localContent.eligibility.intro"
                                    @input="emitUpdate"
                                    placeholder="e.g., To receive care you must meet all of the following:"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0">Criteria</label>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        @click="addCriterion"
                                    >
                                        <i class="bi bi-plus-circle"></i> Add Criterion
                                    </button>
                                </div>

                                <div
                                    v-for="(criterion, cIndex) in localContent.eligibility.criteria"
                                    :key="cIndex"
                                    class="input-group mb-2"
                                >
                                    <input
                                        type="text"
                                        class="form-control"
                                        :value="criterion"
                                        @input="onCriterionInput(cIndex, $event)"
                                        placeholder="e.g., Household income at or below 200% of the Federal Poverty Level"
                                    />
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger"
                                        @click="removeCriterion(cIndex)"
                                        title="Remove Criterion"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>

                                <div
                                    v-if="localContent.eligibility.criteria.length === 0"
                                    class="form-text"
                                >
                                    One line per requirement. Write them the way a visitor
                                    can check them against their own situation.
                                </div>
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Note</label>
                                <textarea
                                    class="form-control"
                                    v-model="localContent.eligibility.note"
                                    @input="emitUpdate"
                                    rows="2"
                                    placeholder="e.g., Bring proof of income and residency to your first visit."
                                ></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <h6 class="mb-1">Highlighted Card</h6>
                        <p class="text-muted small mb-3">
                            The one thing people ask about most — a Yellow Card, a
                            referral pass, a sliding-scale programme. Leave the title
                            blank to hide the card entirely.
                        </p>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Badge</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="localContent.eligibility.highlight.badge"
                                    @input="emitUpdate"
                                    placeholder="e.g., Yellow Card"
                                />
                                <div class="form-text">Small pill above the title.</div>
                            </div>

                            <div class="col-md-8 mb-3">
                                <label class="form-label">Title</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="localContent.eligibility.highlight.title"
                                    @input="emitUpdate"
                                    placeholder="e.g., Do you have a Yellow Card?"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Subtitle</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="localContent.eligibility.highlight.subtitle"
                                    @input="emitUpdate"
                                    placeholder="e.g., Income at or below 200% of the Federal Poverty Level"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Body</label>
                                <textarea
                                    class="form-control"
                                    v-model="localContent.eligibility.highlight.body"
                                    @input="emitUpdate"
                                    rows="3"
                                    placeholder="What the card covers and how to get one"
                                ></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Call to action -->
            <div class="col-12 mb-3">
                <h6 class="mb-2">Call to Action</h6>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Button Text</label>
                        <input
                            type="text"
                            class="form-control"
                            v-model="localContent.button_text"
                            @input="emitUpdate"
                            placeholder="e.g., Check If You Qualify"
                        />
                        <div class="form-text">Leave blank to hide the button.</div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label d-block">Button Goes To</label>
                        <div class="btn-group w-100 mb-2" role="group">
                            <input
                                type="radio"
                                class="btn-check"
                                name="servicesEligibilityButtonLinkType"
                                id="servicesEligibilityLinkTypePage"
                                value="page"
                                v-model="buttonLinkType"
                                @change="onLinkTypeChange"
                                autocomplete="off"
                            >
                            <label class="btn btn-outline-primary" for="servicesEligibilityLinkTypePage">
                                Internal Page
                            </label>

                            <input
                                type="radio"
                                class="btn-check"
                                name="servicesEligibilityButtonLinkType"
                                id="servicesEligibilityLinkTypeExternal"
                                value="external"
                                v-model="buttonLinkType"
                                @change="onLinkTypeChange"
                                autocomplete="off"
                            >
                            <label class="btn btn-outline-primary" for="servicesEligibilityLinkTypeExternal">
                                External Link
                            </label>
                        </div>

                        <div v-if="buttonLinkType === 'page'">
                            <select
                                class="form-select"
                                v-model="localContent.button_page_id"
                                @change="emitUpdate"
                            >
                                <option :value="null">-- Select a page --</option>
                                <option
                                    v-for="page in availablePages"
                                    :key="page.id"
                                    :value="page.id"
                                >
                                    {{ page.title }}
                                </option>
                            </select>
                            <div class="form-text">
                                Point this at the page holding your intake form, so the
                                link keeps working if the page is renamed.
                            </div>
                        </div>

                        <div v-else>
                            <input
                                type="url"
                                class="form-control"
                                v-model="localContent.button_link"
                                @input="emitUpdate"
                                placeholder="https://example.com"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import {
    ServicesEligibilitySectionContent,
    EligibilityBlock,
    ServiceItem,
} from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { Page } from '@/core/types/data/masjid-related/Page';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject, onMounted } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';
import { usePagesStore } from '@/stores/masjid/pagesStore';

const props = defineProps<{
    modelValue: ServicesEligibilitySectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: ServicesEligibilitySectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

const pagesStore = usePagesStore();

const availablePages = ref<Page[]>([]);
const buttonLinkType = ref<'page' | 'external'>('page');

// Pending uploads are keyed by position (services.<i>.image_url), so every
// reorder/remove has to re-key them or the photo lands on the wrong service.
const SERVICE_IMAGE_KEY = /^services\.(\d+)\.image_url$/;

const newService = (): ServiceItem => ({
    name: '',
    description: '',
    image_url: null,
});

// The eligibility block is a fixed object rather than a list, so every field of
// it has to be defaulted here: content authored before one existed must not
// blow up a v-model on a nested path.
const normalizeEligibility = (value?: EligibilityBlock): EligibilityBlock => ({
    heading: value?.heading || '',
    intro: value?.intro || '',
    criteria: value?.criteria ? [...value.criteria] : [],
    note: value?.note || '',
    highlight: {
        badge: value?.highlight?.badge || '',
        title: value?.highlight?.title || '',
        subtitle: value?.highlight?.subtitle || '',
        body: value?.highlight?.body || '',
    },
});

const normalize = (value?: ServicesEligibilitySectionContent): ServicesEligibilitySectionContent => ({
    heading: value?.heading || '',
    description: value?.description || '',
    services: value?.services ? [...value.services] : [],
    layout: value?.layout || 'cards',
    columns: value?.columns || 3,
    eligibility: normalizeEligibility(value?.eligibility),
    button_text: value?.button_text || '',
    button_page_id: value?.button_page_id ?? null,
    button_link: value?.button_link ?? null,
    background_color: value?.background_color || '#ffffff',
});

const localContent = ref<ServicesEligibilitySectionContent>(normalize(props.modelValue));

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = normalize(newVal);
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

/**
 * Re-key the not-yet-uploaded service images after the list order changes.
 * `mapIndex` returns the service's new position, or null when it is removed.
 */
const remapServiceImageFiles = (mapIndex: (oldIndex: number) => number | null) => {
    if (!sectionImages) {
        return;
    }

    const pending = sectionImages.getImageFiles()
        .map((entry) => {
            const match = entry.fieldName.match(SERVICE_IMAGE_KEY);
            return match ? { oldIndex: Number(match[1]), file: entry.file } : null;
        })
        .filter((entry): entry is { oldIndex: number; file: File } => entry !== null);

    if (pending.length === 0) {
        return;
    }

    // Clear every service entry first so a swap cannot overwrite its own counterpart.
    pending.forEach(({ oldIndex }) => {
        sectionImages.addImageFile(`services.${oldIndex}.image_url`, undefined);
    });

    pending.forEach(({ oldIndex, file }) => {
        const newIndex = mapIndex(oldIndex);
        if (newIndex !== null) {
            sectionImages.addImageFile(`services.${newIndex}.image_url`, file);
        }
    });
};

/* ---------------- services ---------------- */

const addService = () => {
    localContent.value.services.push(newService());
    emitUpdate();
};

const removeService = (index: number) => {
    localContent.value.services.splice(index, 1);
    remapServiceImageFiles((oldIndex) => {
        if (oldIndex === index) return null;
        return oldIndex > index ? oldIndex - 1 : oldIndex;
    });
    emitUpdate();
};

const swapServices = (a: number, b: number) => {
    const services = [...localContent.value.services];
    [services[a], services[b]] = [services[b], services[a]];
    localContent.value.services = services;
    remapServiceImageFiles((oldIndex) => {
        if (oldIndex === a) return b;
        if (oldIndex === b) return a;
        return oldIndex;
    });
    emitUpdate();
};

const moveServiceUp = (index: number) => {
    if (index > 0) {
        swapServices(index - 1, index);
    }
};

const moveServiceDown = (index: number) => {
    if (index < localContent.value.services.length - 1) {
        swapServices(index, index + 1);
    }
};

const onServiceImageChange = (index: number, data: UploadedImageInfo) => {
    localContent.value.services[index].image_url = data.src || null;

    // Array notation the backend maps back onto content
    // (SectionType::SERVICES_ELIGIBILITY => ['services.*.image_url']). Called with
    // an undefined file when the admin clears the image, which drops the pending
    // upload instead of leaving it queued against a service that no longer wants it.
    if (sectionImages) {
        sectionImages.addImageFile(`services.${index}.image_url`, data.file);
    }

    emitUpdate();
};

/* ---------------- eligibility criteria ---------------- */

const addCriterion = () => {
    localContent.value.eligibility.criteria.push('');
    emitUpdate();
};

const removeCriterion = (index: number) => {
    localContent.value.eligibility.criteria.splice(index, 1);
    emitUpdate();
};

// `criteria` is an array of plain strings, so v-model on the element cannot write
// back into the array slot — the value is bound and written by index here.
const onCriterionInput = (index: number, event: Event) => {
    const target = event.target as HTMLInputElement;
    localContent.value.eligibility.criteria[index] = target.value;
    emitUpdate();
};

/* ---------------- call to action ---------------- */

const onLinkTypeChange = () => {
    // Clear the other field when switching types, so the two can never disagree
    // about where the button goes.
    if (buttonLinkType.value === 'page') {
        localContent.value.button_link = null;
    } else {
        localContent.value.button_page_id = null;
    }
    emitUpdate();
};

onMounted(async () => {
    await pagesStore.fetchMasjidPagesPaginated(1);
    if (pagesStore.pagesPaginated?.data) {
        availablePages.value = pagesStore.pagesPaginated.data;
    }

    buttonLinkType.value = localContent.value.button_link ? 'external' : 'page';
});
</script>

<style scoped>
.card {
    border: 1px solid #dee2e6;
}

.card-body {
    background-color: #f8f9fa;
}
</style>
