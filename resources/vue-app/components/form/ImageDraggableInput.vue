<template>
    <div class="image-input-group">
        <label for="image_input" class="label">
            {{ label }}
        </label>

        <div v-if="!imageUploaded" id="drag_and_drop_container_id" ref="imageInputContainer"
            @dragover.prevent="onDrag" @dragleave.prevent="onDragLeave" @drop.prevent="onDrop"
            class="image-input-container">

            <!-- allow -->
            <div class="d-flex flex-column align-items-center justify-content-center gap-4 allow-drag">
                <svg width="128" height="102" viewBox="0 0 128 102" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M106.647 34.0509C100.731 10.484 76.8311 -3.82498 53.2642 2.09087C34.8472 6.71411 21.4744 22.6316 20.0966 41.5698C7.01976 43.7263 -1.83302 56.0752 0.323497 69.152C2.24054 80.7775 12.3139 89.2913 24.0961 89.2446H44.094V81.2455H24.0961C15.2606 81.2455 8.0979 74.0827 8.0979 65.2472C8.0979 56.4117 15.2606 49.249 24.0961 49.249C26.3052 49.249 28.0957 47.4584 28.0957 45.2494C28.0757 25.3693 44.1757 9.23709 64.0558 9.21734C81.2649 9.20009 96.079 21.3663 99.4079 38.2502C99.5682 39.0723 99.9825 39.8234 100.592 40.3975C101.202 40.9716 101.977 41.3396 102.808 41.4498C113.742 43.0069 121.343 53.133 119.786 64.0671C118.388 73.8855 110.005 81.196 100.088 81.2455H84.0896V89.2446H100.088C115.55 89.1978 128.047 76.6252 128 61.1629C127.961 48.2918 119.151 37.1053 106.647 34.0509Z"
                        fill="#01B151" />
                    <path
                        d="M61.2522 50.4091L45.2539 66.4073L50.8933 72.0467L60.0923 62.8877V101.244H68.0914V62.8877L77.2504 72.0467L82.8898 66.4073L66.8915 50.4091C65.3315 48.8583 62.8122 48.8583 61.2522 50.4091Z"
                        fill="#01B151" />
                </svg>
                <button type="button" @click.prevent="triggerFileInput" class="btn btn-success btn-lg mx-2">
                    Choose File to Upload
                </button>
                <span class="d-block fs-7 mx-2 text-center">
                    or drag and drop your files here
                </span>
                <input type="file" ref="fileInput" @change.prevent="onFileInputChange"
                    :accept="allowedTypes.toString()" :multiple="multiple" class="d-none">
            </div>

            <!-- not allowed -->
            <div class="d-flex flex-column align-items-center justify-content-center gap-4 prevent-drag">
                <svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" fill="currentColor"
                    class="bi bi-ban text-danger" viewBox="0 0 16 16">
                    <path
                        d="M15 8a6.97 6.97 0 0 0-1.71-4.584l-9.874 9.875A7 7 0 0 0 15 8M2.71 12.584l9.874-9.875a7 7 0 0 0-9.874 9.874ZM16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0" />
                </svg>
                <span class="d-block fs-7 text-danger">
                    Sorry, not allowed
                </span>
            </div>

        </div>

        <!-- Single-image preview -->
        <div v-if="imageUploaded && !multiple" class="image-preview">
            <button type="button" @click.prevent="removeUploadedImage"
                class="btn btn-icon btn-sm btn-danger rounded-circle remove-image">
                <i class="bi bi-trash"></i>
            </button>
            <img :src="uploadedImageSrc" class="image" />
        </div>

        <!-- Multi-image preview -->
        <div v-if="imageUploaded && multiple" class="d-flex flex-column gap-3 w-100">
            <div class="d-flex flex-wrap gap-3">
                <div v-for="(src, index) in uploadedSrcs" :key="index" class="image-preview multi-image-preview">
                    <button type="button" @click.prevent="removeUploadedImageAt(index)"
                        class="btn btn-icon btn-sm btn-danger rounded-circle remove-image">
                        <i class="bi bi-trash"></i>
                    </button>
                    <img :src="src" class="image" />
                </div>
            </div>
            <button type="button" @click.prevent="triggerFileInput" class="btn btn-success btn-sm align-self-start">
                Add more images
            </button>
        </div>

        <!-- notes - 1 -->
        <span v-if="!imageUploaded" class="d-block text-success image-input-note">
            {{ `Note/1: Accepted types (${allowedTypes.map(elm => {return elm.split('/')[1]}).join(', ')}, up to ${maxImageSizeInMb} MB, no more than 4000 px in any dimension)` }}
        </span>

        <!-- notes - 2 -->
        <span v-if="!imageUploaded" class="d-block text-success image-input-note">
            {{ multiple ? 'Note/2: You can upload multiple images at once.' : 'Note/2: Only upload one image.' }}
        </span>

        <!-- Error Messages -->
        <div v-if="hasErrors" class="alert alert-danger mt-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Upload Error:</strong>
            <div v-if="uploadErrorMessages.allowed">{{ uploadErrorMessages.allowed }}</div>
            <div v-if="uploadErrorMessages.load">{{ uploadErrorMessages.load }}</div>
            <div v-if="uploadErrorMessages.read">{{ uploadErrorMessages.read }}</div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ALLOWED_ICON_TYPES, ALLOWED_IMAGE_TYPES, MAX_IMAGE_SIZE, MAX_IMAGE_X_DIMENSION_IN_PX, MAX_IMAGE_Y_DIMENSION_IN_PX } from '@/core/constants/allowedImageProperties';
import { DraggableImageType, UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { computed, onBeforeMount, PropType, ref, toRefs, watch } from 'vue';

// Props
const props = defineProps({
    label: {
        type: String,
        required: true
    },
    currentImageSrc: {
        type: String,
        requiried: false,
        default: ''
    },
    type: {
        type: Object as PropType<DraggableImageType>,
        required: false,
        default: 'image'
    },
    // Opt-in multi-image mode. Defaults to false so every existing single-image
    // form keeps its current behaviour unchanged.
    multiple: {
        type: Boolean,
        required: false,
        default: false
    },
    // Increment this from the parent to clear the input after a successful upload.
    resetSignal: {
        type: Number,
        required: false,
        default: 0
    }
});

const { label, currentImageSrc, type, multiple, resetSignal } = toRefs(props);

// Emits
const emits = defineEmits(['imageChange', 'filesChange']);

// Lifecycle hooks
onBeforeMount(() => {
    if(currentImageSrc.value) {
        uploadedImageSrc.value = currentImageSrc.value;
    }
})

// Html refs
const imageInputContainer = ref<HTMLElement>();
const fileInput = ref<HTMLInputElement>();

// Custom constants
const uploadedImageFile = ref<File>();
const uploadedImage = ref<HTMLImageElement>();
const uploadedImageSrc = ref<string>();
// Multi-image mode collects every validated file/preview here.
const uploadedFiles = ref<File[]>([]);
const uploadedSrcs = ref<string[]>([]);
const uploadErrorMessages = ref({
    allowed: "",
    load: "",
    read: ""
});

// Computed
const imageUploaded = computed(() => {
    if (multiple.value) {
        return uploadedSrcs.value.length > 0;
    }
    return uploadedImageSrc.value ? true : false;
});
const allowedTypes = computed(() => {
    if(type.value === 'photo') {
        return ALLOWED_IMAGE_TYPES;
    } else if (type.value === 'icon') {
        return ALLOWED_ICON_TYPES;
    } else {
        return ['image/*'];
    }
});
const hasErrors = computed(() => {
    return !!(uploadErrorMessages.value.allowed || uploadErrorMessages.value.load || uploadErrorMessages.value.read);
});
const maxImageSizeInMb = computed(() => Math.round(MAX_IMAGE_SIZE / (1024 * 1024)));

// Watch
// emit the image change with the image file and source (data)
watch([() => uploadedImageFile.value, () => uploadedImageSrc.value], () => {
    let uploadedImageInfo: UploadedImageInfo = {
        file: uploadedImageFile.value,
        src: uploadedImageSrc.value
    }
    emits('imageChange', uploadedImageInfo);
});
watch(() => currentImageSrc.value, () => {
    uploadedImageSrc.value = currentImageSrc.value;
});
// Parent bumps resetSignal after a successful upload to clear the input.
watch(() => resetSignal.value, () => {
    removeUploadedImage();
});

// Functions
function onDrag(event: DragEvent) {
    event.preventDefault();
    const target = event.target as HTMLElement;
    if (target && target.classList.contains("image-input-container")) {
        target.classList.add("on-drag");
        if (event.dataTransfer?.items && !multiple.value) {
            if (event.dataTransfer.items.length !== 1) {
                target.classList.add('show-prevent');
            } else {
                target.classList.remove('show-prevent');
            }
        }
    }
}

function onDragLeave(event: DragEvent) {
    event.preventDefault();
    const target = event.target as HTMLElement;
    if (target && target.classList.contains("image-input-container")) {
        const rect = target.getBoundingClientRect();
        const isOutside =
            event.clientX < rect.left ||
            event.clientX >= rect.right ||
            event.clientY < rect.top ||
            event.clientY >= rect.bottom;

        if (isOutside) {
            target.classList.remove("on-drag");
            target.classList.remove("show-prevent");
        }
    }
}

function onDrop(event: DragEvent) {
    event.preventDefault();
    const target = event.target as HTMLElement;

    if (target && target.classList.contains("image-input-container")) {
        if (multiple.value) {
            if (event.dataTransfer?.files?.length) {
                loadMultipleImages(Array.from(event.dataTransfer.files));
            }
        } else if ((!target.classList.contains("show-prevent"))) {
            if (event.dataTransfer?.files?.length == 1) {
                uploadedImageFile.value = event.dataTransfer.files[0];
                checkAndLoadImage(uploadedImageFile.value);
            }
        }

        target.classList.remove("on-drag");
        target.classList.remove("show-prevent");
    } else {
        let draggableContainer = document.querySelector(".image-input-container");
        if (draggableContainer) {
            draggableContainer.classList.remove("on-drag");
            draggableContainer.classList.remove("show-prevent");
        }
    }
}

const checkAndLoadImage = (file: File) => {
    // Clear any previous error messages
    uploadErrorMessages.value = { allowed: "", load: "", read: "" };

    const reader = new FileReader();

    // Reader load with use of Image load
    reader.onload = (ev) => {
        const image = new Image();
        image.src = ev.target?.result as string;

        // Image load
        image.onload = () => {

            // Check if file type is allowed (use computed allowedTypes based on type prop)
            const currentAllowedTypes = allowedTypes.value;
            const isAllowed = currentAllowedTypes.includes('image/*') || currentAllowedTypes.includes(file.type);

            if (!isAllowed) {
                uploadErrorMessages.value.allowed = "This file type is not allowed.";
                return;
            }

            // Check file size
            if (file.size >= MAX_IMAGE_SIZE) {
                uploadErrorMessages.value.allowed = `File size is too large. Maximum size is ${maxImageSizeInMb.value}MB.`;
                return;
            }

            // Skip dimension checks for SVG files (they don't have natural dimensions)
            const isSvg = file.type === 'image/svg+xml' || file.type === 'image/svg';

            // Check image dimensions for non-SVG files
            if (!isSvg) {
                if (image.naturalHeight >= MAX_IMAGE_Y_DIMENSION_IN_PX ||
                    image.naturalWidth >= MAX_IMAGE_X_DIMENSION_IN_PX) {
                    uploadErrorMessages.value.allowed = `Image dimensions are too large. Maximum dimensions are ${MAX_IMAGE_X_DIMENSION_IN_PX}x${MAX_IMAGE_Y_DIMENSION_IN_PX}px.`;
                    return;
                }
            }

            // Clear error messages on successful validation
            uploadErrorMessages.value = { allowed: "", load: "", read: "" };

            // Save image
            uploadedImage.value = image;
            uploadedImageSrc.value = image.src;
            uploadedImageFile.value = file;
        };

        // Image loading errors
        image.onerror = () => {
            uploadErrorMessages.value.load = "Failed to load the file."
        };

    };

    // Reading errors
    reader.onerror = () => {
        uploadErrorMessages.value.read = "Failed to read the file."
    };

    // Read the file
    reader.readAsDataURL(file);
}

const onFileInputChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (!target.files || target.files.length === 0) {
        return;
    }
    if (multiple.value) {
        loadMultipleImages(Array.from(target.files));
    } else if (target.files.length === 1) {
        checkAndLoadImage(target.files[0]);
    }
    // Clear the native input value so the same file(s) can be re-selected and the
    // input does not visually retain the previous selection.
    target.value = '';
}

// Validate and append a batch of files in multi-image mode.
const loadMultipleImages = (files: File[]) => {
    uploadErrorMessages.value = { allowed: "", load: "", read: "" };

    files.forEach((file) => {
        const reader = new FileReader();
        reader.onload = (ev) => {
            const image = new Image();
            image.src = ev.target?.result as string;
            image.onload = () => {
                const currentAllowedTypes = allowedTypes.value;
                const isAllowed = currentAllowedTypes.includes('image/*') || currentAllowedTypes.includes(file.type);
                if (!isAllowed) {
                    uploadErrorMessages.value.allowed = "One or more files are not an allowed image type.";
                    return;
                }
                if (file.size >= MAX_IMAGE_SIZE) {
                    uploadErrorMessages.value.allowed = `One or more files are too large. Maximum size is ${maxImageSizeInMb.value}MB.`;
                    return;
                }
                const isSvg = file.type === 'image/svg+xml' || file.type === 'image/svg';
                if (!isSvg) {
                    if (image.naturalHeight >= MAX_IMAGE_Y_DIMENSION_IN_PX ||
                        image.naturalWidth >= MAX_IMAGE_X_DIMENSION_IN_PX) {
                        uploadErrorMessages.value.allowed = `One or more images exceed the maximum ${MAX_IMAGE_X_DIMENSION_IN_PX}x${MAX_IMAGE_Y_DIMENSION_IN_PX}px dimensions.`;
                        return;
                    }
                }
                uploadedFiles.value.push(file);
                uploadedSrcs.value.push(image.src);
                emits('filesChange', uploadedFiles.value);
            };
            image.onerror = () => {
                uploadErrorMessages.value.load = "Failed to load one or more files.";
            };
        };
        reader.onerror = () => {
            uploadErrorMessages.value.read = "Failed to read one or more files.";
        };
        reader.readAsDataURL(file);
    });
}

const removeUploadedImage = () => {
    uploadErrorMessages.value = { allowed: "", load: "", read: "" };
    uploadedImage.value = undefined;
    uploadedImageFile.value = undefined;
    uploadedImageSrc.value = undefined;
    uploadedFiles.value = [];
    uploadedSrcs.value = [];
    if (multiple.value) {
        emits('filesChange', []);
    }
}

const removeUploadedImageAt = (index: number) => {
    uploadedFiles.value.splice(index, 1);
    uploadedSrcs.value.splice(index, 1);
    emits('filesChange', uploadedFiles.value);
}

const triggerFileInput = () => {
    fileInput.value?.click();
}

</script>

<style scoped>
.image-input-group {
    display: flex;
    flex-direction: column;
    align-items: start;
    justify-content: start;
    gap: .5rem;
}

.image-input-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1.5rem;
    width: 100%;
    border: 1px dashed var(--cgreen);
    padding-top: 3rem;
    padding-bottom: 3rem;
    border-radius: .5rem;
}

.image-input-container.on-drag {
    opacity: .5;
}

.image-input-container.on-drag .allow-drag {
    visibility: hidden !important;
}

.image-input-container .prevent-drag {
    display: none !important;
}

.image-input-container.show-prevent .allow-drag {
    display: none !important;
}

.image-input-container.show-prevent .prevent-drag {
    display: flex !important;
}

.image-input-note {
    font-size: .85rem;
}

.image-preview {
    max-width: 500px;
    max-height: 500px;
    border-radius: 1rem;
    overflow: hidden;
    position: relative;
    min-height: 5rem;
    border: 1px solid var(--input-border);
}

.image-preview .remove-image {
    position: absolute;
    right: .25rem;
    top: .25rem;
}

.image-preview .image {
    max-width: 100%;
    border-radius: 1rem;
}

.multi-image-preview {
    width: 140px;
    height: 140px;
    min-height: 0;
}

.multi-image-preview .image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>
