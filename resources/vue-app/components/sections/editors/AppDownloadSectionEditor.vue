<template>
    <div class="app-download-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                />
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Description</label>
                <textarea
                    class="form-control"
                    v-model="localContent.description"
                    @input="emitUpdate"
                    rows="2"
                ></textarea>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">App Store URL</label>
                <input
                    type="url"
                    class="form-control"
                    v-model="localContent.app_store_url"
                    @input="emitUpdate"
                    placeholder="https://apps.apple.com/..."
                />
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Play Store URL</label>
                <input
                    type="url"
                    class="form-control"
                    v-model="localContent.play_store_url"
                    @input="emitUpdate"
                    placeholder="https://play.google.com/..."
                />
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label d-block">Show QR Code</label>
                <div class="form-check form-switch mt-2">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        v-model="localContent.show_qr_code"
                        @change="emitUpdate"
                        id="showQrCode"
                    />
                    <label class="form-check-label" for="showQrCode">
                        Display QR code for download
                    </label>
                </div>
            </div>
            <div class="col-12 mb-3">
                <ImageDraggableInput
                    name="app_icon"
                    type="photo"
                    label="App Icon"
                    :current-image-src="localContent.app_icon_url || undefined"
                    @image-change="onImageChange"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { AppDownloadSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/data/interfaces/UploadedImageInfo';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: AppDownloadSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: AppDownloadSectionContent];
}>();

const localContent = ref<AppDownloadSectionContent>({
    heading: props.modelValue?.heading || '',
    description: props.modelValue?.description || '',
    app_store_url: props.modelValue?.app_store_url || '',
    play_store_url: props.modelValue?.play_store_url || '',
    show_qr_code: props.modelValue?.show_qr_code ?? false,
    app_icon_url: props.modelValue?.app_icon_url || null,
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
    localContent.value.app_icon_url = data.src || null;
    imageFile.value = data.file;
    emitUpdate();
};
</script>

