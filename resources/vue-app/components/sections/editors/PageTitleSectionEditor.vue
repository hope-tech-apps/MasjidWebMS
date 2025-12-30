<template>
    <div class="page-title-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.title"
                    @input="emitUpdate"
                    placeholder="Enter page title (e.g., About Us)"
                    required
                />
            </div>
            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="page_title_background_image"
                    type="photo"
                    label="Background Image"
                    :current-image-src="localContent.background_image_url || undefined"
                    @image-change="onImageChange"
                />
                <small class="text-muted">This image will be displayed as the background of the page title section</small>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { PageTitleSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/data/interfaces/UploadedImageInfo';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';

const props = defineProps<{
    modelValue: PageTitleSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: PageTitleSectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

const localContent = ref<PageTitleSectionContent>({
    title: props.modelValue?.title || '',
    background_image_url: props.modelValue?.background_image_url || null,
});

const imageFile = ref<File | undefined>();

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = { ...newVal };
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

const onImageChange = (data: UploadedImageInfo) => {
    localContent.value.background_image_url = data.src || null;
    imageFile.value = data.file;

    // Add image file to the parent's collection
    if (sectionImages) {
        sectionImages.addImageFile('background_image_url', data.file);
    }

    emitUpdate();
};
</script>

