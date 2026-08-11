<template>
    <div class="carousel-editor">
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Height</label>
                <select
                    class="form-select"
                    v-model="localContent.height"
                    @change="emitUpdate"
                >
                    <option value="short">Short</option>
                    <option value="medium">Medium</option>
                    <option value="tall">Tall</option>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label d-block">Autoplay</label>
                <div class="form-check form-switch mt-2">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="carouselAutoplaySwitch"
                        v-model="localContent.autoplay"
                        @change="emitUpdate"
                    />
                    <label class="form-check-label" for="carouselAutoplaySwitch">
                        {{ localContent.autoplay ? 'Rotates automatically' : 'Manual only' }}
                    </label>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Slide Duration (ms)</label>
                <input
                    type="number"
                    class="form-control"
                    v-model.number="localContent.interval_ms"
                    @input="emitUpdate"
                    min="1000"
                    max="30000"
                    step="500"
                    :disabled="!localContent.autoplay"
                />
                <div class="form-text">How long each slide stays on screen. 6000 = 6 seconds.</div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="carouselArrowsSwitch"
                        v-model="localContent.show_arrows"
                        @change="emitUpdate"
                    />
                    <label class="form-check-label" for="carouselArrowsSwitch">
                        Show previous / next arrows
                    </label>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="carouselDotsSwitch"
                        v-model="localContent.show_dots"
                        @change="emitUpdate"
                    />
                    <label class="form-check-label" for="carouselDotsSwitch">
                        Show slide dots
                    </label>
                </div>
            </div>

            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Slides</h6>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        @click="addSlide"
                    >
                        <i class="bi bi-plus-circle"></i> Add Slide
                    </button>
                </div>

                <div
                    v-for="(slide, index) in localContent.slides"
                    :key="index"
                    class="card mb-3"
                >
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">Slide {{ index + 1 }}</h6>
                            <div class="btn-group">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveSlideUp(index)"
                                    :disabled="index === 0"
                                    title="Move Up"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveSlideDown(index)"
                                    :disabled="index === localContent.slides.length - 1"
                                    title="Move Down"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    @click="removeSlide(index)"
                                    title="Remove Slide"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 mb-3">
                                <ImageDraggableInput
                                    :name="`slide_image_${index}`"
                                    type="photo"
                                    label="Slide Image"
                                    :current-image-src="slide.image_url || undefined"
                                    @image-change="(data) => onSlideImageChange(index, data)"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Title</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="slide.title"
                                    @input="emitUpdate"
                                    placeholder="Optional headline shown over the slide"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Caption</label>
                                <textarea
                                    class="form-control"
                                    v-model="slide.caption"
                                    @input="emitUpdate"
                                    rows="2"
                                    placeholder="Optional supporting text"
                                ></textarea>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Link URL</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="slide.link_url"
                                    @input="emitUpdate"
                                    placeholder="https://example.com or /page-slug"
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Link Text</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="slide.link_text"
                                    @input="emitUpdate"
                                    placeholder="e.g., Learn More"
                                />
                                <div class="form-text">Only shown when a link URL is set.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="localContent.slides.length === 0" class="alert alert-info">
                    No slides added yet. Click "Add Slide" to create your first slide.
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { CarouselSectionContent, CarouselSlide } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';

const props = defineProps<{
    modelValue: CarouselSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: CarouselSectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

// Pending slide uploads are keyed by position (slides.<i>.image_url), so every
// reorder/remove has to re-key them or the image lands on the wrong slide.
const SLIDE_IMAGE_KEY = /^slides\.(\d+)\.image_url$/;

const newSlide = (): CarouselSlide => ({
    image_url: '',
    title: '',
    caption: '',
    link_url: '',
    link_text: '',
});

const normalize = (value?: CarouselSectionContent): CarouselSectionContent => ({
    slides: value?.slides ? [...value.slides] : [],
    autoplay: value?.autoplay ?? true,
    interval_ms: value?.interval_ms || 6000,
    show_arrows: value?.show_arrows ?? true,
    show_dots: value?.show_dots ?? true,
    height: value?.height || 'medium',
});

const localContent = ref<CarouselSectionContent>(normalize(props.modelValue));

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = normalize(newVal);
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

/**
 * Re-key the not-yet-uploaded slide images after the slide order changes.
 * `mapIndex` returns the slide's new position, or null when it is being removed.
 */
const remapSlideImageFiles = (mapIndex: (oldIndex: number) => number | null) => {
    if (!sectionImages) {
        return;
    }

    const pending = sectionImages.getImageFiles()
        .map((entry) => {
            const match = entry.fieldName.match(SLIDE_IMAGE_KEY);
            return match ? { oldIndex: Number(match[1]), file: entry.file } : null;
        })
        .filter((entry): entry is { oldIndex: number; file: File } => entry !== null);

    if (pending.length === 0) {
        return;
    }

    // Clear every slide entry first so a swap cannot overwrite its own counterpart.
    pending.forEach(({ oldIndex }) => {
        sectionImages.addImageFile(`slides.${oldIndex}.image_url`, undefined);
    });

    pending.forEach(({ oldIndex, file }) => {
        const newIndex = mapIndex(oldIndex);
        if (newIndex !== null) {
            sectionImages.addImageFile(`slides.${newIndex}.image_url`, file);
        }
    });
};

const addSlide = () => {
    localContent.value.slides.push(newSlide());
    emitUpdate();
};

const removeSlide = (index: number) => {
    localContent.value.slides.splice(index, 1);
    remapSlideImageFiles((oldIndex) => {
        if (oldIndex === index) return null;
        return oldIndex > index ? oldIndex - 1 : oldIndex;
    });
    emitUpdate();
};

const swapSlides = (a: number, b: number) => {
    const slides = [...localContent.value.slides];
    [slides[a], slides[b]] = [slides[b], slides[a]];
    localContent.value.slides = slides;
    remapSlideImageFiles((oldIndex) => {
        if (oldIndex === a) return b;
        if (oldIndex === b) return a;
        return oldIndex;
    });
    emitUpdate();
};

const moveSlideUp = (index: number) => {
    if (index > 0) {
        swapSlides(index - 1, index);
    }
};

const moveSlideDown = (index: number) => {
    if (index < localContent.value.slides.length - 1) {
        swapSlides(index, index + 1);
    }
};

const onSlideImageChange = (index: number, data: UploadedImageInfo) => {
    localContent.value.slides[index].image_url = data.src || '';

    // Array notation the backend maps back onto content
    // (SectionType::CAROUSEL => ['slides.*.image_url']). Called with an undefined
    // file when the admin clears the slide, which drops the pending upload instead
    // of leaving it queued against a slide that no longer wants it.
    if (sectionImages) {
        sectionImages.addImageFile(`slides.${index}.image_url`, data.file);
    }

    emitUpdate();
};
</script>

<style scoped>
.card {
    border: 1px solid #dee2e6;
}

.card-body {
    background-color: #f8f9fa;
}
</style>
