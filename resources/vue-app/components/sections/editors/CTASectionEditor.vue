<template>
    <div class="cta-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Heading <span class="text-danger">*</span></label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                    required
                />
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Description</label>
                <textarea
                    class="form-control"
                    v-model="localContent.description"
                    @input="emitUpdate"
                    rows="3"
                ></textarea>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Button Text</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.button_text"
                    @input="emitUpdate"
                />
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Button Link</label>
                <input
                    type="url"
                    class="form-control"
                    v-model="localContent.button_link"
                    @input="emitUpdate"
                    placeholder="https://example.com"
                />
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Button Style</label>
                <select
                    class="form-select"
                    v-model="localContent.button_style"
                    @change="emitUpdate"
                >
                    <option value="primary">Primary</option>
                    <option value="secondary">Secondary</option>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Background Color</label>
                <input
                    type="color"
                    class="form-control form-control-color"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                />
            </div>
            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="cta_background_image"
                    type="photo"
                    label="Background Image"
                    :current-image-src="localContent.background_image_url || undefined"
                    @image-change="onImageChange"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { CTASectionContent } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/data/interfaces/UploadedImageInfo';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: CTASectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: CTASectionContent];
}>();

const localContent = ref<CTASectionContent>({
    heading: props.modelValue?.heading || '',
    description: props.modelValue?.description || '',
    button_text: props.modelValue?.button_text || 'Learn More',
    button_link: props.modelValue?.button_link || '',
    button_style: props.modelValue?.button_style || 'primary',
    background_image_url: props.modelValue?.background_image_url || null,
    background_color: props.modelValue?.background_color || '#f8f9fa',
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
    emitUpdate();
};
</script>

