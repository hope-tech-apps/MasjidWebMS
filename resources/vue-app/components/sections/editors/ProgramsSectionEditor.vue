<template>
    <div class="programs-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                    placeholder="e.g., Our Programs"
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Description</label>
                <textarea
                    class="form-control"
                    v-model="localContent.description"
                    @input="emitUpdate"
                    rows="2"
                    placeholder="Optional text shown under the heading"
                ></textarea>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Layout</label>
                <select
                    class="form-select"
                    v-model="localContent.layout"
                    @change="emitUpdate"
                >
                    <option value="cards">Cards</option>
                    <option value="list">List (one per row)</option>
                    <option value="accordion">Accordion (expand to read)</option>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Columns</label>
                <select
                    class="form-select"
                    v-model.number="localContent.columns"
                    @change="emitUpdate"
                    :disabled="localContent.layout !== 'cards'"
                >
                    <option :value="1">1 per row</option>
                    <option :value="2">2 per row</option>
                    <option :value="3">3 per row</option>
                    <option :value="4">4 per row</option>
                </select>
                <div class="form-text">Only applies to the cards layout.</div>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Background Color</label>
                <input
                    type="color"
                    class="form-control form-control-color flex-shrink-0"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                />
            </div>

            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Programs</h6>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        @click="addProgram"
                    >
                        <i class="bi bi-plus-circle"></i> Add Program
                    </button>
                </div>

                <div
                    v-for="(program, index) in localContent.programs"
                    :key="index"
                    class="card mb-3"
                >
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">{{ program.name || `Program ${index + 1}` }}</h6>
                            <div class="btn-group">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveProgramUp(index)"
                                    :disabled="index === 0"
                                    title="Move Up"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveProgramDown(index)"
                                    :disabled="index === localContent.programs.length - 1"
                                    title="Move Down"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    @click="removeProgram(index)"
                                    title="Remove Program"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 mb-3">
                                <ImageDraggableInput
                                    :name="`program_image_${index}`"
                                    type="photo"
                                    label="Program Image"
                                    :current-image-src="program.image_url || undefined"
                                    @image-change="(data) => onProgramImageChange(index, data)"
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="program.name"
                                    @input="emitUpdate"
                                    placeholder="e.g., Quran &amp; Islamic Studies"
                                    required
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Grade or Age Level</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="program.level"
                                    @input="emitUpdate"
                                    placeholder="e.g., Pre-K, Grades 1-2, Ages 4-6"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Schedule</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="program.schedule"
                                    @input="emitUpdate"
                                    placeholder="e.g., Monday - Friday, 8:00 AM - 3:00 PM"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Summary</label>
                                <textarea
                                    class="form-control"
                                    v-model="program.summary"
                                    @input="emitUpdate"
                                    rows="3"
                                    placeholder="A paragraph describing this program"
                                ></textarea>
                            </div>

                            <div class="col-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0">What It Covers</label>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        @click="addHighlight(index)"
                                    >
                                        <i class="bi bi-plus-circle"></i> Add Point
                                    </button>
                                </div>

                                <div
                                    v-for="(highlight, hIndex) in program.highlights"
                                    :key="hIndex"
                                    class="input-group mb-2"
                                >
                                    <input
                                        type="text"
                                        class="form-control"
                                        :value="highlight"
                                        @input="onHighlightInput(index, hIndex, $event)"
                                        placeholder="e.g., Daily Quran memorization"
                                    />
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger"
                                        @click="removeHighlight(index, hIndex)"
                                        title="Remove Point"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>

                                <div v-if="program.highlights.length === 0" class="form-text">
                                    Optional bullet points listed under the summary.
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Link URL</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="program.link_url"
                                    @input="emitUpdate"
                                    placeholder="https://example.com or /page-slug"
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Link Text</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="program.link_text"
                                    @input="emitUpdate"
                                    placeholder="e.g., Learn More"
                                />
                                <div class="form-text">Only shown when a link URL is set.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="localContent.programs.length === 0" class="alert alert-info">
                    No programs added yet. Click "Add Program" to create your first one.
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ProgramsSectionContent, ProgramItem } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';

const props = defineProps<{
    modelValue: ProgramsSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: ProgramsSectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

// Pending uploads are keyed by position (programs.<i>.image_url), so every
// reorder/remove has to re-key them or the image lands on the wrong program.
const PROGRAM_IMAGE_KEY = /^programs\.(\d+)\.image_url$/;

const newProgram = (): ProgramItem => ({
    name: '',
    level: '',
    schedule: '',
    summary: '',
    highlights: [],
    image_url: null,
    link_url: '',
    link_text: '',
});

const normalize = (value?: ProgramsSectionContent): ProgramsSectionContent => ({
    heading: value?.heading || '',
    description: value?.description || '',
    programs: value?.programs
        ? value.programs.map((program) => ({
            ...program,
            // A program saved before this field existed, or hand-edited, must not
            // crash the v-for; an absent list is an empty one.
            highlights: program.highlights ? [...program.highlights] : [],
        }))
        : [],
    layout: value?.layout || 'cards',
    columns: value?.columns || 3,
    background_color: value?.background_color || '#ffffff',
});

const localContent = ref<ProgramsSectionContent>(normalize(props.modelValue));

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = normalize(newVal);
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

/**
 * Re-key the not-yet-uploaded program images after the order changes.
 * `mapIndex` returns the program's new position, or null when it is removed.
 */
const remapProgramImageFiles = (mapIndex: (oldIndex: number) => number | null) => {
    if (!sectionImages) {
        return;
    }

    const pending = sectionImages.getImageFiles()
        .map((entry) => {
            const match = entry.fieldName.match(PROGRAM_IMAGE_KEY);
            return match ? { oldIndex: Number(match[1]), file: entry.file } : null;
        })
        .filter((entry): entry is { oldIndex: number; file: File } => entry !== null);

    if (pending.length === 0) {
        return;
    }

    // Clear every program entry first so a swap cannot overwrite its own counterpart.
    pending.forEach(({ oldIndex }) => {
        sectionImages.addImageFile(`programs.${oldIndex}.image_url`, undefined);
    });

    pending.forEach(({ oldIndex, file }) => {
        const newIndex = mapIndex(oldIndex);
        if (newIndex !== null) {
            sectionImages.addImageFile(`programs.${newIndex}.image_url`, file);
        }
    });
};

const addProgram = () => {
    localContent.value.programs.push(newProgram());
    emitUpdate();
};

const removeProgram = (index: number) => {
    localContent.value.programs.splice(index, 1);
    remapProgramImageFiles((oldIndex) => {
        if (oldIndex === index) return null;
        return oldIndex > index ? oldIndex - 1 : oldIndex;
    });
    emitUpdate();
};

const swapPrograms = (a: number, b: number) => {
    const programs = [...localContent.value.programs];
    [programs[a], programs[b]] = [programs[b], programs[a]];
    localContent.value.programs = programs;
    remapProgramImageFiles((oldIndex) => {
        if (oldIndex === a) return b;
        if (oldIndex === b) return a;
        return oldIndex;
    });
    emitUpdate();
};

const moveProgramUp = (index: number) => {
    if (index > 0) {
        swapPrograms(index - 1, index);
    }
};

const moveProgramDown = (index: number) => {
    if (index < localContent.value.programs.length - 1) {
        swapPrograms(index, index + 1);
    }
};

const addHighlight = (index: number) => {
    localContent.value.programs[index].highlights.push('');
    emitUpdate();
};

const removeHighlight = (index: number, highlightIndex: number) => {
    localContent.value.programs[index].highlights.splice(highlightIndex, 1);
    emitUpdate();
};

// `highlights` is an array of plain strings, so v-model on the element cannot
// write back into the array slot — the value is bound and written by index here.
const onHighlightInput = (index: number, highlightIndex: number, event: Event) => {
    const target = event.target as HTMLInputElement;
    localContent.value.programs[index].highlights[highlightIndex] = target.value;
    emitUpdate();
};

const onProgramImageChange = (index: number, data: UploadedImageInfo) => {
    localContent.value.programs[index].image_url = data.src || null;

    // Array notation the backend maps back onto content
    // (SectionType::PROGRAMS => ['programs.*.image_url']). Called with an undefined
    // file when the admin clears the image, which drops the pending upload instead
    // of leaving it queued against a program that no longer wants it.
    if (sectionImages) {
        sectionImages.addImageFile(`programs.${index}.image_url`, data.file);
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
