<template>
    <div class="mission-vision-editor">
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
                <label class="form-label">Layout</label>
                <select
                    class="form-select"
                    v-model="localContent.layout"
                    @change="emitUpdate"
                >
                    <option value="side_by_side">Side by Side</option>
                    <option value="stacked">Stacked</option>
                </select>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Items</label>
                <div v-for="(item, index) in localContent.items" :key="index" class="card mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <label class="form-label">Type</label>
                                <select
                                    class="form-select form-select-sm"
                                    v-model="item.type"
                                    @change="emitUpdate"
                                >
                                    <option value="mission">Mission</option>
                                    <option value="vision">Vision</option>
                                </select>
                            </div>
                            <div class="col-md-8 mb-2">
                                <label class="form-label">Title</label>
                                <input
                                    type="text"
                                    class="form-control form-control-sm"
                                    v-model="item.title"
                                    @input="emitUpdate"
                                />
                            </div>
                            <div class="col-12 mb-2">
                                <label class="form-label">Content</label>
                                <textarea
                                    class="form-control form-control-sm"
                                    v-model="item.content"
                                    @input="emitUpdate"
                                    rows="3"
                                ></textarea>
                            </div>
                            <div class="col-12 mb-2">
                                <ImageDraggableInput
                                    :name="`mission_vision_icon_${index}`"
                                    type="photo"
                                    label="Icon Image"
                                    :current-image-src="item.icon_url || undefined"
                                    @image-change="(data) => onItemImageChange(index, data)"
                                />
                            </div>
                            <div class="col-12">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger w-100"
                                    @click="removeItem(index)"
                                >
                                    <i class="bi bi-trash"></i> Remove Item
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" @click="addItem">
                    <i class="bi bi-plus"></i> Add Item
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { MissionVisionSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { UploadedImageInfo } from '@/core/types/data/interfaces/UploadedImageInfo';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: MissionVisionSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: MissionVisionSectionContent];
}>();

const localContent = ref<MissionVisionSectionContent>({
    heading: props.modelValue?.heading || '',
    items: props.modelValue?.items || [],
    layout: props.modelValue?.layout || 'side_by_side',
});

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = { ...newVal };
    }
}, { deep: true });

const addItem = () => {
    localContent.value.items.push({
        type: 'mission',
        title: '',
        content: '',
        icon_url: null,
    });
    emitUpdate();
};

const removeItem = (index: number) => {
    localContent.value.items.splice(index, 1);
    emitUpdate();
};

const onItemImageChange = (index: number, data: UploadedImageInfo) => {
    if (localContent.value.items[index]) {
        localContent.value.items[index].icon_url = data.src || null;
        emitUpdate();
    }
};

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};
</script>

