<template>
    <div class="donation-editor">
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
                <textarea
                    class="form-control"
                    v-model="localContent.subtitle"
                    @input="emitUpdate"
                    rows="3"
                    placeholder="Enter subtitle text"
                ></textarea>
            </div>
            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="donation_image"
                    type="photo"
                    label="Donation Image"
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
                    placeholder="e.g., Donate Now"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { DonationSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/data/interfaces/UploadedImageInfo';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';

const props = defineProps<{
    modelValue: DonationSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: DonationSectionContent];
}>();

// Inject the sectionImages composable from parent modal
const sectionImages = inject<ReturnType<typeof useSectionImages>>('sectionImages');

const localContent = ref<DonationSectionContent>({
    title: props.modelValue?.title || '',
    subtitle: props.modelValue?.subtitle || '',
    image_url: props.modelValue?.image_url || null,
    button_text: props.modelValue?.button_text || 'Donate Now',
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

    // Add image file to the composable for upload
    if (sectionImages && data.file) {
        sectionImages.addImageFile('image_url', data.file);
    }

    emitUpdate();
};
</script>

