<template>
    <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-layout-text-sidebar me-2"></i>
                        {{ isEdit ? 'Edit Section' : 'Add Section to Page' }}
                    </h5>
                    <button type="button" class="btn-close" @click="$emit('close')"></button>
                </div>
                <div class="modal-body">
                    <!-- Mode Selection (only for new sections) -->
                    <div v-if="!isEdit" class="mb-4">
                        <div class="btn-group w-100" role="group">
                            <input
                                type="radio"
                                class="btn-check"
                                name="sectionMode"
                                id="modeCreateNew"
                                value="create"
                                v-model="mode"
                                autocomplete="off"
                            >
                            <label class="btn btn-outline-primary" for="modeCreateNew">
                                <i class="bi bi-plus-circle me-2"></i>
                                Create New Section
                            </label>

                            <input
                                type="radio"
                                class="btn-check"
                                name="sectionMode"
                                id="modeAttachExisting"
                                value="attach"
                                v-model="mode"
                                autocomplete="off"
                            >
                            <label class="btn btn-outline-primary" for="modeAttachExisting">
                                <i class="bi bi-link-45deg me-2"></i>
                                Attach Existing Section
                            </label>
                        </div>
                    </div>

                    <!-- Attach Existing Section Mode -->
                    <div v-if="!isEdit && mode === 'attach'">
                        <div class="mb-4">
                            <label class="form-label">
                                Select Section from Library <span class="text-danger">*</span>
                            </label>
                            <select
                                class="form-select"
                                v-model="selectedSectionId"
                                required
                            >
                                <option value="">-- Select a Section --</option>
                                <option
                                    v-for="section in sectionsLibrary"
                                    :key="section.id"
                                    :value="section.id"
                                >
                                    {{ section.title || 'Untitled' }} ({{ section.section_type_label }})
                                </option>
                            </select>
                            <small class="form-text text-muted">
                                Select a section from your library to add to this page
                            </small>
                        </div>

                        <!-- Preview of selected section -->
                        <div v-if="selectedSection" class="alert alert-info">
                            <h6 class="alert-heading">
                                <i class="bi bi-info-circle me-2"></i>
                                Section Preview
                            </h6>
                            <p class="mb-1"><strong>Title:</strong> {{ selectedSection.title || 'Untitled' }}</p>
                            <p class="mb-1"><strong>Type:</strong> {{ selectedSection.section_type_label }}</p>
                            <p class="mb-0"><strong>Status:</strong>
                                <span :class="selectedSection.is_active ? 'text-success' : 'text-danger'">
                                    {{ selectedSection.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </p>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Order</label>
                            <input
                                type="number"
                                class="form-control"
                                v-model.number="attachOrder"
                                required
                                min="1"
                            />
                        </div>
                    </div>

                    <!-- Create New Section Mode -->
                    <form v-if="isEdit || mode === 'create'" @submit.prevent="handleSubmit">
                        <!-- Section Type Selection (only for new sections) -->
                        <div v-if="!isEdit" class="mb-4">
                            <label class="form-label">
                                Section Type <span class="text-danger">*</span>
                            </label>
                            <select
                                class="form-select"
                                v-model="formData.section_type"
                                @change="onSectionTypeChange"
                                required
                            >
                                <option value="">-- Select Section Type --</option>
                                <option
                                    v-for="type in sectionTypes"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ type.label }} - {{ type.description }}
                                </option>
                            </select>
                        </div>

                        <!-- Section Type Badge (for edit mode) -->
                        <div v-else class="mb-3">
                            <label class="form-label">Section Type</label>
                            <div>
                                <span class="badge bg-info fs-6">{{ formData.section_type }}</span>
                            </div>
                        </div>

                        <div class="row">
                            <!-- Title -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Section Title</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="formData.title"
                                    placeholder="Optional section title"
                                />
                            </div>

                            <!-- Order -->
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Order</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    v-model.number="formData.order"
                                    required
                                    min="1"
                                />
                            </div>

                            <!-- Is Active -->
                            <div class="col-md-3 mb-3">
                                <label class="form-label d-block">Status</label>
                                <div class="form-check form-switch mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        v-model="formData.is_active"
                                        id="sectionActiveSwitch"
                                    />
                                    <label class="form-check-label" for="sectionActiveSwitch">
                                        {{ formData.is_active ? 'Active' : 'Inactive' }}
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Dynamic Content Editor -->
                        <div v-if="formData.section_type" class="mt-4">
                            <h6 class="mb-3">Section Content</h6>
                            <component
                                :is="currentEditor"
                                v-model="formData.content"
                            />
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" @click="$emit('close')">
                        Cancel
                    </button>

                    <!-- Attach button for attach mode -->
                    <button
                        v-if="!isEdit && mode === 'attach'"
                        type="button"
                        class="btn btn-primary"
                        @click="handleAttach"
                        :disabled="loading || !selectedSectionId"
                    >
                        <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
                        <i class="bi bi-link-45deg me-1"></i>
                        Attach Section
                    </button>

                    <!-- Create/Update button for create/edit mode -->
                    <button
                        v-else
                        type="button"
                        class="btn btn-primary"
                        @click="handleSubmit"
                        :disabled="loading || (!isEdit && mode === 'create' && !formData.section_type)"
                    >
                        <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
                        <i class="bi" :class="isEdit ? 'bi-check-circle me-1' : 'bi-plus-circle me-1'"></i>
                        {{ isEdit ? 'Update Section' : 'Create Section' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { PageSection, SectionType } from '@/core/types/data/masjid-related/PageSection';
import { usePagesStore } from '@/stores/masjid/pagesStore';
import { ref, computed, onMounted, shallowRef, provide } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';
import Swal from 'sweetalert2';

// Import section editors
import PrayerTimesSectionEditor from '@/components/sections/editors/PrayerTimesSectionEditor.vue';
import TextSectionEditor from '@/components/sections/editors/TextSectionEditor.vue';
import AboutUsSectionEditor from '@/components/sections/editors/AboutUsSectionEditor.vue';
import ImageTextGridSectionEditor from '@/components/sections/editors/ImageTextGridSectionEditor.vue';
import GridCardsSectionEditor from '@/components/sections/editors/GridCardsSectionEditor.vue';
import DonationSectionEditor from '@/components/sections/editors/DonationSectionEditor.vue';
import ContactFormSectionEditor from '@/components/sections/editors/ContactFormSectionEditor.vue';
import ServicesSectionEditor from '@/components/sections/editors/ServicesSectionEditor.vue';
import ListSectionEditor from '@/components/sections/editors/ListSectionEditor.vue';
import AnnouncementsSectionEditor from '@/components/sections/editors/AnnouncementsSectionEditor.vue';
import StatsSectionEditor from '@/components/sections/editors/StatsSectionEditor.vue';
import MissionVisionSectionEditor from '@/components/sections/editors/MissionVisionSectionEditor.vue';
import CTASectionEditor from '@/components/sections/editors/CTASectionEditor.vue';
import PageTitleSectionEditor from '@/components/sections/editors/PageTitleSectionEditor.vue';

// Props
const props = defineProps<{
    section?: PageSection;
    pageId: number;
}>();

// Emits
const emit = defineEmits<{
    close: [];
    saved: [];
}>();

// Store
const pagesStore = usePagesStore();

// Section images composable
const sectionImages = useSectionImages();

// Provide to child components
provide('sectionImages', sectionImages);

// State
const loading = ref(false);
const mode = ref<'create' | 'attach'>('create');
const selectedSectionId = ref<number | ''>('');
const attachOrder = ref(1);
const formData = ref<any>({
    section_type: '',
    title: '',
    content: {},
    order: 1,
    is_active: true,
    settings: {}
});

// Computed
const isEdit = computed(() => !!props.section);
const sectionTypes = computed(() => pagesStore.sectionTypes);
const sectionsLibrary = computed(() => pagesStore.sectionsLibrary);
const selectedSection = computed(() => {
    if (!selectedSectionId.value) return null;
    return sectionsLibrary.value.find(s => s.id === selectedSectionId.value);
});

// Editor component mapping
const editorMap: Record<SectionType, any> = {
    'page_title': PageTitleSectionEditor,
    'prayer_times': PrayerTimesSectionEditor,
    'text': TextSectionEditor,
    'about_us': AboutUsSectionEditor,
    'image_text_grid': ImageTextGridSectionEditor,
    'grid_cards': GridCardsSectionEditor,
    'donation': DonationSectionEditor,
    'contact_form': ContactFormSectionEditor,
    'services_list': ServicesSectionEditor,
    'announcements_list': AnnouncementsSectionEditor,
    'gallery': ListSectionEditor,
    'stats': StatsSectionEditor,
    'mission_vision': MissionVisionSectionEditor,
    'cta': CTASectionEditor,
};

const currentEditor = shallowRef<any>(null);

// Lifecycle
onMounted(async () => {
    await pagesStore.fetchSectionTypes();

    // Fetch sections library for attach mode
    if (!props.section) {
        await pagesStore.fetchSectionsLibrary();
    }

    if (props.section) {
        formData.value = {
            section_type: props.section.section_type,
            title: props.section.title || '',
            content: props.section.content || {},
            order: props.section.order,
            is_active: props.section.is_active,
            settings: props.section.settings || {}
        };
        currentEditor.value = editorMap[props.section.section_type];
    } else {
        // Set default order for new sections
        const sections = await pagesStore.fetchPageSections(props.pageId);
        formData.value.order = sections.length + 1;
        attachOrder.value = sections.length + 1;
    }
});

// Methods
const onSectionTypeChange = () => {
    const selectedType = sectionTypes.value.find(t => t.value === formData.value.section_type);
    if (selectedType) {
        formData.value.content = selectedType.default_content;
        currentEditor.value = editorMap[formData.value.section_type as SectionType];
    }
};

const handleAttach = async () => {
    if (!selectedSectionId.value) return;

    loading.value = true;

    try {
        await pagesStore.attachSectionToPage(props.pageId, selectedSectionId.value as number, attachOrder.value);

        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'Section attached to page successfully',
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });

        emit('saved');
    } catch (error: any) {
        console.error('Error attaching section:', error);

        let errorMessage = 'Failed to attach section. Please try again.';

        if (error.response?.data?.message) {
            errorMessage = error.response.data.message;
        }

        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: errorMessage,
        });
    } finally {
        loading.value = false;
    }
};

/**
 * Strip base64 image data from content before sending to backend
 * The backend will replace these with actual uploaded file URLs
 */
const stripBase64Images = (content: any): any => {
    if (typeof content !== 'object' || content === null) {
        return content;
    }

    if (Array.isArray(content)) {
        return content.map(item => stripBase64Images(item));
    }

    const cleaned: any = {};
    for (const [key, value] of Object.entries(content)) {
        if (typeof value === 'string' && value.startsWith('data:image/')) {
            // This is a base64 image - replace with null
            // The backend will fill in the actual URL from the uploaded file
            cleaned[key] = null;
        } else if (typeof value === 'object' && value !== null) {
            cleaned[key] = stripBase64Images(value);
        } else {
            cleaned[key] = value;
        }
    }
    return cleaned;
};

const handleSubmit = async () => {
    loading.value = true;

    try {
        // Prepare form data with images
        const formDataToSend = new FormData();

        // Strip base64 images from content before sending
        const cleanedContent = stripBase64Images(formData.value.content);

        // Add basic fields
        formDataToSend.append('section_type', formData.value.section_type);
        formDataToSend.append('title', formData.value.title || '');
        formDataToSend.append('content', JSON.stringify(cleanedContent));
        formDataToSend.append('order', formData.value.order.toString());
        formDataToSend.append('is_active', formData.value.is_active ? '1' : '0');

        if (formData.value.settings && Object.keys(formData.value.settings).length > 0) {
            formDataToSend.append('settings', JSON.stringify(formData.value.settings));
        }

        // Add image files
        const imageFiles = sectionImages.getImageFiles();
        imageFiles.forEach(({ fieldName, file }) => {
            formDataToSend.append(fieldName, file);
        });

        if (isEdit.value && props.section) {
            // For update, we need to use POST with _method=PUT
            formDataToSend.append('_method', 'PUT');
            await pagesStore.updateSectionWithImages(props.pageId, props.section.id, formDataToSend);
        } else {
            await pagesStore.createSectionWithImages(props.pageId, formDataToSend);
        }

        // Clear image files after successful submission
        sectionImages.clearImageFiles();

        emit('saved');
    } catch (error: any) {
        console.error('Error saving section:', error);
        console.error('Error response:', error.response);

        let errorMessage = 'Failed to save section. Please try again.';

        // Check for validation errors
        if (error.response?.status === 413) {
            errorMessage = 'Uploaded file is too large for server limits. Please use a smaller file or ask support to increase upload/post limits.';
        } else if (error.response?.data?.data) {
            const validationErrors = error.response.data.data;
            const errorMessages = Object.values(validationErrors).flat();
            errorMessage = errorMessages.join('\n');
        } else if (error.response?.data?.message) {
            errorMessage = error.response.data.message;
        }

        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: errorMessage,
        });
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
.modal {
    display: block;
}

.btn-check:checked + .btn-outline-primary {
    background-color: #0d6efd;
    color: white;
}

.alert-info {
    background-color: #e7f3ff;
    border-color: #b3d9ff;
    color: #004085;
}
</style>

