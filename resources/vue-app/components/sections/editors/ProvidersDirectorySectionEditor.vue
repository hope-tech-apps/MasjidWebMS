<template>
    <div class="providers-directory-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                    placeholder="e.g., Our Providers"
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
                    <option value="grid">Grid (photo cards)</option>
                    <option value="list">List (one per row)</option>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Columns</label>
                <select
                    class="form-select"
                    v-model.number="localContent.columns"
                    @change="emitUpdate"
                    :disabled="localContent.layout === 'list'"
                >
                    <option :value="2">2 per row</option>
                    <option :value="3">3 per row</option>
                    <option :value="4">4 per row</option>
                </select>
                <div class="form-text">Only applies to the grid layout.</div>
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

            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Providers</h6>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        @click="addProvider"
                    >
                        <i class="bi bi-plus-circle"></i> Add Provider
                    </button>
                </div>

                <div
                    v-for="(provider, index) in localContent.providers"
                    :key="index"
                    class="card mb-3"
                >
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">{{ providerHeading(provider, index) }}</h6>
                            <div class="btn-group">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveProviderUp(index)"
                                    :disabled="index === 0"
                                    title="Move Up"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveProviderDown(index)"
                                    :disabled="index === localContent.providers.length - 1"
                                    title="Move Down"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    @click="removeProvider(index)"
                                    title="Remove Provider"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 mb-3">
                                <ImageDraggableInput
                                    :name="`provider_photo_${index}`"
                                    type="photo"
                                    label="Photo"
                                    :current-image-src="provider.photo_url || undefined"
                                    @image-change="(data) => onProviderPhotoChange(index, data)"
                                />
                            </div>

                            <div class="col-md-8 mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="provider.name"
                                    @input="emitUpdate"
                                    placeholder="e.g., Dr. Layla Haddad"
                                    required
                                />
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Credential</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="provider.credential"
                                    @input="emitUpdate"
                                    list="providerCredentialSuggestions"
                                    placeholder="e.g., MD"
                                />
                                <div class="form-text">
                                    The letters after the name, shown as "Name, MD".
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Specialty</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="provider.specialty"
                                    @input="emitUpdate"
                                    placeholder="e.g., Family Medicine"
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="provider.department"
                                    @input="emitUpdate"
                                    list="providerDepartmentSuggestions"
                                    placeholder="e.g., Primary Care"
                                />
                                <div class="form-text">
                                    Providers sharing a department are shown together.
                                    Leave blank to list this person on their own.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <datalist id="providerDepartmentSuggestions">
                    <option
                        v-for="department in usedDepartments"
                        :key="department"
                        :value="department"
                    ></option>
                </datalist>

                <!-- Common post-nominals, offered as suggestions only: the field stays
                     free text because no closed set covers every profession or state. -->
                <datalist id="providerCredentialSuggestions">
                    <option
                        v-for="credential in CREDENTIAL_SUGGESTIONS"
                        :key="credential"
                        :value="credential"
                    ></option>
                </datalist>

                <div v-if="localContent.providers.length === 0" class="alert alert-info">
                    No providers added yet. Click "Add Provider" to build the directory.
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ProvidersDirectorySectionContent, ProviderItem } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject, computed } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';

const props = defineProps<{
    modelValue: ProvidersDirectorySectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: ProvidersDirectorySectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

// Pending photo uploads are keyed by position (providers.<i>.photo_url), so every
// reorder/remove has to re-key them or the photo lands on the wrong person —
// which on a care-team page means publishing a clinician's face under another
// clinician's name and credentials.
const PROVIDER_PHOTO_KEY = /^providers\.(\d+)\.photo_url$/;

// Offered as datalist suggestions only — the field stays free text, because no
// closed set of post-nominals covers every profession or every state's licensing.
const CREDENTIAL_SUGGESTIONS: string[] = [
    'MD',
    'DO',
    'NP',
    'FNP-C',
    'PA-C',
    'RN',
    'LPN',
    'DDS',
    'DMD',
    'PharmD',
    'LCSW',
    'PhD',
];

const newProvider = (): ProviderItem => ({
    name: '',
    credential: '',
    specialty: '',
    department: '',
    photo_url: null,
});

const normalize = (value?: ProvidersDirectorySectionContent): ProvidersDirectorySectionContent => ({
    heading: value?.heading || '',
    description: value?.description || '',
    providers: value?.providers ? [...value.providers] : [],
    layout: value?.layout || 'grid',
    columns: value?.columns || 3,
    background_color: value?.background_color || '#ffffff',
});

const localContent = ref<ProvidersDirectorySectionContent>(normalize(props.modelValue));

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = normalize(newVal);
    }
}, { deep: true });

// Feeds the department datalist so the second provider in a department is typed
// consistently with the first — a typo here silently splits a group in two.
const usedDepartments = computed<string[]>(() => {
    const seen = new Set<string>();
    localContent.value.providers.forEach((provider) => {
        const name = (provider.department || '').trim();
        if (name) {
            seen.add(name);
        }
    });
    return Array.from(seen);
});

const providerHeading = (provider: ProviderItem, index: number): string => {
    if (!provider.name) {
        return `Provider ${index + 1}`;
    }
    return provider.credential ? `${provider.name}, ${provider.credential}` : provider.name;
};

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

/**
 * Re-key the not-yet-uploaded photos after the list order changes.
 * `mapIndex` returns the provider's new position, or null when they are removed.
 */
const remapProviderPhotoFiles = (mapIndex: (oldIndex: number) => number | null) => {
    if (!sectionImages) {
        return;
    }

    const pending = sectionImages.getImageFiles()
        .map((entry) => {
            const match = entry.fieldName.match(PROVIDER_PHOTO_KEY);
            return match ? { oldIndex: Number(match[1]), file: entry.file } : null;
        })
        .filter((entry): entry is { oldIndex: number; file: File } => entry !== null);

    if (pending.length === 0) {
        return;
    }

    // Clear every provider entry first so a swap cannot overwrite its own counterpart.
    pending.forEach(({ oldIndex }) => {
        sectionImages.addImageFile(`providers.${oldIndex}.photo_url`, undefined);
    });

    pending.forEach(({ oldIndex, file }) => {
        const newIndex = mapIndex(oldIndex);
        if (newIndex !== null) {
            sectionImages.addImageFile(`providers.${newIndex}.photo_url`, file);
        }
    });
};

const addProvider = () => {
    localContent.value.providers.push(newProvider());
    emitUpdate();
};

const removeProvider = (index: number) => {
    localContent.value.providers.splice(index, 1);
    remapProviderPhotoFiles((oldIndex) => {
        if (oldIndex === index) return null;
        return oldIndex > index ? oldIndex - 1 : oldIndex;
    });
    emitUpdate();
};

const swapProviders = (a: number, b: number) => {
    const providers = [...localContent.value.providers];
    [providers[a], providers[b]] = [providers[b], providers[a]];
    localContent.value.providers = providers;
    remapProviderPhotoFiles((oldIndex) => {
        if (oldIndex === a) return b;
        if (oldIndex === b) return a;
        return oldIndex;
    });
    emitUpdate();
};

const moveProviderUp = (index: number) => {
    if (index > 0) {
        swapProviders(index - 1, index);
    }
};

const moveProviderDown = (index: number) => {
    if (index < localContent.value.providers.length - 1) {
        swapProviders(index, index + 1);
    }
};

const onProviderPhotoChange = (index: number, data: UploadedImageInfo) => {
    localContent.value.providers[index].photo_url = data.src || null;

    // Array notation the backend maps back onto content
    // (SectionType::PROVIDERS_DIRECTORY => ['providers.*.photo_url']). Called with
    // an undefined file when the admin clears the photo, which drops the pending
    // upload instead of leaving it queued against someone who no longer wants it.
    if (sectionImages) {
        sectionImages.addImageFile(`providers.${index}.photo_url`, data.file);
    }

    emitUpdate();
};
</script>

<style scoped>
.card {
    border: 1px solid #dee2e6;
}

.card-body {
    background-color: #f8f9fa;
}
</style>
