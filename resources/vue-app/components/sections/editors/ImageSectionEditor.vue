<template>
    <div class="image-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="image_url"
                    type="photo"
                    label="Image"
                    :current-image-src="localContent.image_url || undefined"
                    @image-change="onImageChange"
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Alt Text <span class="text-danger">*</span></label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.alt_text"
                    @input="emitUpdate"
                    placeholder="Describe the image for screen readers"
                    required
                />
                <div class="form-text">
                    Read aloud to visually impaired visitors and shown if the image fails to
                    load. Describe what the image shows; leave blank only if it is purely
                    decorative.
                </div>
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Caption</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.caption"
                    @input="emitUpdate"
                    placeholder="Optional caption shown under the image"
                />
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Width</label>
                <select
                    class="form-select"
                    v-model="localContent.max_width"
                    @change="emitUpdate"
                >
                    <option value="full">Full width (edge to edge)</option>
                    <option value="container">Container (matches page content)</option>
                    <option value="narrow">Narrow (centered column)</option>
                </select>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Background Color</label>
                <input
                    type="color"
                    class="form-control form-control-color flex-shrink-0"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ImageSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/data/interfaces/UploadedImageInfo';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';

const props = defineProps<{
    modelValue: ImageSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: ImageSectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

const localContent = ref<ImageSectionContent>({
    image_url: props.modelValue?.image_url || null,
    alt_text: props.modelValue?.alt_text || '',
    caption: props.modelValue?.caption || '',
    max_width: props.modelValue?.max_width || 'container',
    background_color: props.modelValue?.background_color || '#ffffff',
});

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = { ...newVal };
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

const onImageChange = (data: UploadedImageInfo) => {
    localContent.value.image_url = data.src || null;

    // Register the file with the parent modal under the content key the backend
    // writes the uploaded URL back into (SectionType::IMAGE => ['image_url']).
    if (sectionImages) {
        sectionImages.addImageFile('image_url', data.file);
    }

    emitUpdate();
};
</script>
