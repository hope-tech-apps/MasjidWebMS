<template>
    <div class="image-text-grid-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.title"
                    @input="emitUpdate"
                    placeholder="Enter title"
                    required
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Subtitle</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.subtitle"
                    @input="emitUpdate"
                    placeholder="Enter subtitle"
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Text <span class="text-danger">*</span></label>
                <textarea
                    class="form-control"
                    v-model="localContent.text"
                    @input="emitUpdate"
                    rows="6"
                    placeholder="Enter text content"
                    required
                ></textarea>
            </div>

            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="main_image"
                    type="photo"
                    label="Main Image"
                    :current-image-src="localContent.main_image_url || undefined"
                    @image-change="onMainImageChange"
                />
            </div>

            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="header_image"
                    type="photo"
                    label="Header Image"
                    :current-image-src="localContent.header_image_url || undefined"
                    @image-change="onHeaderImageChange"
                />
            </div>

            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="footer_image"
                    type="photo"
                    label="Footer Image"
                    :current-image-src="localContent.footer_image_url || undefined"
                    @image-change="onFooterImageChange"
                />
            </div>

            <div class="col-12 mb-3">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="showButtonToggle"
                        v-model="localContent.show_button"
                        @change="emitUpdate"
                    />
                    <label class="form-check-label" for="showButtonToggle">
                        Show Button
                    </label>
                </div>
            </div>

            <div class="col-12 mb-3" v-if="localContent.show_button">
                <label class="form-label">Button Text</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.button_text"
                    @input="emitUpdate"
                    placeholder="e.g., Learn More"
                />
            </div>

            <div class="col-12 mb-3" v-if="localContent.show_button">
                <label class="form-label">Button Link Type</label>
                <div class="btn-group w-100 mb-3" role="group">
                    <input
                        type="radio"
                        class="btn-check"
                        name="buttonLinkType"
                        id="linkTypePage"
                        value="page"
                        v-model="buttonLinkType"
                        @change="onLinkTypeChange"
                        autocomplete="off"
                    >
                    <label class="btn btn-outline-primary" for="linkTypePage">
                        Internal Page
                    </label>

                    <input
                        type="radio"
                        class="btn-check"
                        name="buttonLinkType"
                        id="linkTypeExternal"
                        value="external"
                        v-model="buttonLinkType"
                        @change="onLinkTypeChange"
                        autocomplete="off"
                    >
                    <label class="btn btn-outline-primary" for="linkTypeExternal">
                        External Link
                    </label>
                </div>

                <div v-if="buttonLinkType === 'page'">
                    <label class="form-label">Button Redirection Page</label>
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
                    <small class="form-text text-muted">
                        Select which page the button should redirect to
                    </small>
                </div>

                <div v-else-if="buttonLinkType === 'external'">
                    <label class="form-label">External Link URL</label>
                    <input
                        type="url"
                        class="form-control"
                        v-model="localContent.button_link"
                        @input="emitUpdate"
                        placeholder="https://example.com"
                    />
                    <small class="form-text text-muted">
                        Enter the full URL including https://
                    </small>
                </div>
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Content Direction</label>
                <select
                    class="form-select"
                    v-model="localContent.content_direction"
                    @change="emitUpdate"
                >
                    <option value="ltr">Left to Right (LTR)</option>
                    <option value="rtl">Right to Left (RTL)</option>
                </select>
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Background Color</label>
                <input
                    type="color"
                    class="form-control form-control-color"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                    title="Choose background color"
                />
                <small class="form-text text-muted">
                    Current color: {{ localContent.background_color }}
                </small>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ImageTextGridSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/data/interfaces/UploadedImageInfo';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject, onMounted } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';
import { usePagesStore } from '@/stores/masjid/pagesStore';
import { Page } from '@/core/types/data/masjid-related/Page';

const props = defineProps<{
    modelValue: ImageTextGridSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: ImageTextGridSectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

// Store
const pagesStore = usePagesStore();

// Available pages for button redirection
const availablePages = ref<Page[]>([]);

// Button link type (page or external)
const buttonLinkType = ref<'page' | 'external'>('page');

const localContent = ref<ImageTextGridSectionContent>({
    title: props.modelValue?.title || '',
    subtitle: props.modelValue?.subtitle || '',
    text: props.modelValue?.text || '',
    main_image_url: props.modelValue?.main_image_url || null,
    header_image_url: props.modelValue?.header_image_url || null,
    footer_image_url: props.modelValue?.footer_image_url || null,
    button_text: props.modelValue?.button_text || '',
    button_page_id: props.modelValue?.button_page_id || null,
    button_link: props.modelValue?.button_link || null,
    show_button: props.modelValue?.show_button ?? true,
    content_direction: props.modelValue?.content_direction || 'ltr',
    background_color: props.modelValue?.background_color || '#ffffff',
});

const mainImageFile = ref<File | undefined>();
const headerImageFile = ref<File | undefined>();
const footerImageFile = ref<File | undefined>();

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = { ...newVal };
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

const onLinkTypeChange = () => {
    // Clear the other field when switching types
    if (buttonLinkType.value === 'page') {
        localContent.value.button_link = null;
    } else {
        localContent.value.button_page_id = null;
    }
    emitUpdate();
};

const updateButtonLinkType = () => {
    // Determine which type is active based on content
    if (localContent.value.button_link) {
        buttonLinkType.value = 'external';
    } else if (localContent.value.button_page_id) {
        buttonLinkType.value = 'page';
    } else {
        buttonLinkType.value = 'page'; // Default to page
    }
};

const onMainImageChange = (data: UploadedImageInfo) => {
    localContent.value.main_image_url = data.src || null;
    mainImageFile.value = data.file;

    // Add image file to the parent's collection
    if (sectionImages) {
        sectionImages.addImageFile('main_image_url', data.file);
    }

    emitUpdate();
};

const onHeaderImageChange = (data: UploadedImageInfo) => {
    localContent.value.header_image_url = data.src || null;
    headerImageFile.value = data.file;

    // Add image file to the parent's collection
    if (sectionImages) {
        sectionImages.addImageFile('header_image_url', data.file);
    }

    emitUpdate();
};

const onFooterImageChange = (data: UploadedImageInfo) => {
    localContent.value.footer_image_url = data.src || null;
    footerImageFile.value = data.file;

    // Add image file to the parent's collection
    if (sectionImages) {
        sectionImages.addImageFile('footer_image_url', data.file);
    }

    emitUpdate();
};

// Fetch available pages on mount
onMounted(async () => {
    await pagesStore.fetchMasjidPagesPaginated(1);
    if (pagesStore.pagesPaginated?.data) {
        availablePages.value = pagesStore.pagesPaginated.data;
    }

    // Initialize button link type based on existing content
    updateButtonLinkType();
});
</script>

