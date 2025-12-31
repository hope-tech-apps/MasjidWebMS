<template>
    <div class="grid-cards-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Items Per Row <span class="text-danger">*</span></label>
                <select
                    class="form-select"
                    v-model.number="localContent.items_per_row"
                    @change="emitUpdate"
                >
                    <option :value="1">1 item per row</option>
                    <option :value="2">2 items per row</option>
                    <option :value="3">3 items per row</option>
                    <option :value="4">4 items per row</option>
                    <option :value="6">6 items per row</option>
                </select>
            </div>

            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Cards</h6>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        @click="addCard"
                    >
                        <i class="bi bi-plus-circle"></i> Add Card
                    </button>
                </div>

                <div
                    v-for="(item, index) in localContent.items"
                    :key="index"
                    class="card mb-3"
                >
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">Card {{ index + 1 }}</h6>
                            <div class="btn-group">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveCardUp(index)"
                                    :disabled="index === 0"
                                    title="Move Up"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveCardDown(index)"
                                    :disabled="index === localContent.items.length - 1"
                                    title="Move Down"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    @click="removeCard(index)"
                                    :disabled="localContent.items.length === 1"
                                    title="Remove Card"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 mb-3">
                                <label class="form-label">Title <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="item.title"
                                    @input="emitUpdate"
                                    placeholder="Enter card title"
                                    required
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Text <span class="text-danger">*</span></label>
                                <textarea
                                    class="form-control"
                                    v-model="item.text"
                                    @input="emitUpdate"
                                    rows="4"
                                    placeholder="Enter card text"
                                    required
                                ></textarea>
                            </div>

                            <div class="col-12 mb-3">
                                <ImageDraggableInput
                                    :name="`card_image_${index}`"
                                    type="photo"
                                    label="Card Image"
                                    :current-image-src="item.image_url || undefined"
                                    @image-change="(data) => onCardImageChange(index, data)"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="localContent.items.length === 0" class="alert alert-info">
                    No cards added yet. Click "Add Card" to create your first card.
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { GridCardsSectionContent, GridCardItem } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/data/interfaces/UploadedImageInfo';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch, inject } from 'vue';
import { useSectionImages } from '@/composables/useSectionImages';

const props = defineProps<{
    modelValue: GridCardsSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: GridCardsSectionContent];
}>();

// Get the section images composable from parent (if provided)
const sectionImages = inject<ReturnType<typeof useSectionImages> | null>('sectionImages', null);

const localContent = ref<GridCardsSectionContent>({
    items_per_row: props.modelValue?.items_per_row || 3,
    items: props.modelValue?.items && props.modelValue.items.length > 0
        ? [...props.modelValue.items]
        : [
            {
                title: '',
                text: '',
                image_url: null,
            }
        ],
});

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = {
            items_per_row: newVal.items_per_row || 3,
            items: newVal.items && newVal.items.length > 0 ? [...newVal.items] : [{
                title: '',
                text: '',
                image_url: null,
            }],
        };
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

const addCard = () => {
    localContent.value.items.push({
        title: '',
        text: '',
        image_url: null,
    });
    emitUpdate();
};

const removeCard = (index: number) => {
    if (localContent.value.items.length > 1) {
        localContent.value.items.splice(index, 1);
        emitUpdate();
    }
};

const moveCardUp = (index: number) => {
    if (index > 0) {
        const items = [...localContent.value.items];
        [items[index - 1], items[index]] = [items[index], items[index - 1]];
        localContent.value.items = items;
        emitUpdate();
    }
};

const moveCardDown = (index: number) => {
    if (index < localContent.value.items.length - 1) {
        const items = [...localContent.value.items];
        [items[index], items[index + 1]] = [items[index + 1], items[index]];
        localContent.value.items = items;
        emitUpdate();
    }
};

const onCardImageChange = (index: number, data: UploadedImageInfo) => {
    localContent.value.items[index].image_url = data.src || null;

    // Add image file to the parent's collection with array notation
    if (sectionImages && data.file) {
        sectionImages.addImageFile(`items.${index}.image_url`, data.file);
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

