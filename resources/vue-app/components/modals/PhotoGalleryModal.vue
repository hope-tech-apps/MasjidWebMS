<template>
    <div class="modal fade" :id="modalId" tabindex="-1" aria-labelledby="photoGalleryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <Form @submit="onSubmit" :validation-schema="validationSchema" class="modal-content px-2 py-2">
                <div class="modal-header border-0">
                    <h5 class="modal-title" id="photoGalleryModalLabel">
                        {{ title }}
                    </h5>
                    <button type="button" class="btn-close rounded-circle btn btn-light" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <template v-if="!showPhoto">
                        <div class="d-flex flex-column">
                            <ImageDraggableInput name="photo_input" type="photo" label="Photo Gallery" :multiple="true"
                                :current-image-src="photoSrc" :reset-signal="resetSignal" @image-change="imageChange"
                                @files-change="filesChange" />
                            <Field type="file" v-model="photoInputModel" name="photo_input" class="d-none"></Field>
                            <ErrorMessage name="photo_input" class="error-message" />
                        </div>
                    </template>
                    <div v-else class="img-container">
                        <img :src="photoSrc" alt="photo" class="image" />
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light border border-1-danger mx-4"
                        data-bs-dismiss="modal">Close</button>
                    <LoadingButton v-if="showPhoto" :loading="actionLoadingStatus" type="submit"
                        classes="btn btn-danger">
                        Delete Image
                    </LoadingButton>
                    <LoadingButton v-else :loading="actionLoadingStatus" type="submit" classes="btn btn-success">
                        Save Image
                    </LoadingButton>
                    <slot name="control_buttons"></slot>
                </div>
            </Form>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Media } from '@/core/types/data/Media';
import { computed, onMounted, PropType, ref, toRefs } from 'vue';
import { ErrorMessage, Form, useForm, Field } from 'vee-validate';
import ImageDraggableInput from '../form/ImageDraggableInput.vue';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { object, string } from 'yup';
import LoadingButton from '../form/LoadingButton.vue';

// Props
const props = defineProps({
    modalId: {
        type: String,
        required: true
    },
    showPhoto: {
        type: Boolean,
        required: true
    },
    photo: {
        type: Object as PropType<Media | null>,
        required: false
    },
    actionLoadingStatus: {
        type: Boolean,
        required: false
    },
    // Parent bumps this after a successful upload to clear the image input.
    resetSignal: {
        type: Number,
        required: false,
        default: 0
    }
});

// Destructions
const { modalId, showPhoto, photo, resetSignal } = toRefs(props);

// Lifecycle hooks
onMounted(() => {
    if (showPhoto.value && photo?.value) {
        photoInputModel.value = photo.value?.original_url;
        setFieldValue('photo_input', photo.value?.original_url);
    }
});


// Emits
const emits = defineEmits(['imageChange', 'filesChange', 'submit']);

// Computed
const photoSrc = computed(() => {
    return photo?.value ? photo.value.original_url : '';
});
const title = computed(() => {
    if (showPhoto.value) {
        return 'View Image';
    } else {
        return 'Upload New Image';
    }
})

// Form
const validationSchema = computed(() => {
    if (!showPhoto.value) {
        return object().shape({
            photo_input: string().required('At least one photo is required')
        });
    } else {
        return {};
    }
});

const { setFieldValue } = useForm({
    validationSchema: validationSchema
});
const photoInputModel = ref<string>('');
const selectedFiles = ref<File[]>([]);

// Functions
function imageChange(data: UploadedImageInfo) {
    // Kept for the single-image (view/replace) path; multi-upload uses filesChange.
    emits('imageChange', data);
}

function filesChange(files: File[]) {
    selectedFiles.value = files;
    // Drive the required-field validation off the selected files in multi mode.
    setFieldValue('photo_input', files.length ? 'selected' : '');
    photoInputModel.value = files.length ? 'selected' : '';
    emits('filesChange', files);
}

const onSubmit = () => {
    emits('submit', selectedFiles.value);
}

</script>

<style scoped>
.image-container {
    width: 100%;
    border-radius: 1rem;
    overflow: hidden;
    object-fit: contain;
    display: flex;
    align-items: center;
    justify-content: center;
}

.image {
    width: 100%;
    border-radius: 1rem;
}
</style>