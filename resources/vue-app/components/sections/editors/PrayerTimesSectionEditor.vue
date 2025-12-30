<template>
    <div class="prayer-times-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <textarea
                    class="form-control"
                    v-model="localContent.title"
                    @input="emitUpdate"
                    rows="3"
                    placeholder="Enter title text"
                    required
                ></textarea>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Subtitle</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.subtitle"
                    @input="emitUpdate"
                    placeholder="Enter subtitle text"
                />
            </div>
            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="prayer_times_image"
                    type="photo"
                    label="Prayer Times Image"
                    :current-image-src="localContent.image_url || undefined"
                    @image-change="onImageChange"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { PrayerTimesSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/data/interfaces/UploadedImageInfo';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';

const props = defineProps<{
    modelValue: PrayerTimesSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: PrayerTimesSectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

const localContent = ref<PrayerTimesSectionContent>({
    title: props.modelValue?.title || '',
    subtitle: props.modelValue?.subtitle || '',
    image_url: props.modelValue?.image_url || null,
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

