<template>
    <div class="about-us-editor">
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
                    placeholder="Enter about us text (plain text, not HTML)"
                    required
                ></textarea>
            </div>
            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="about_us_image"
                    type="photo"
                    label="About Us Image"
                    :current-image-src="localContent.image_url || undefined"
                    @image-change="onImageChange"
                />
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Button Text</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.button_text"
                    @input="emitUpdate"
                    placeholder="e.g., Learn More"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { AboutUsSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/data/interfaces/UploadedImageInfo';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';

const props = defineProps<{
    modelValue: AboutUsSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: AboutUsSectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

const localContent = ref<AboutUsSectionContent>({
    title: props.modelValue?.title || '',
    subtitle: props.modelValue?.subtitle || '',
    text: props.modelValue?.text || '',
    image_url: props.modelValue?.image_url || null,
    button_text: props.modelValue?.button_text || '',
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
    localContent.value.image_url = data.src || null;
    imageFile.value = data.file;
    
    // Add image file to the parent's collection
    if (sectionImages) {
        sectionImages.addImageFile('image_url', data.file);
    }
    
    emitUpdate();
};
</script>

