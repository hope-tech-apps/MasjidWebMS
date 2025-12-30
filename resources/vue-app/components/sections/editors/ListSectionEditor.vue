<template>
    <div class="list-editor">
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            This section displays data from an external API endpoint. Configure display options below.
        </div>
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
                <input 
                    type="text" 
                    class="form-control" 
                    v-model="localContent.description"
                    @input="emitUpdate"
                />
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Layout</label>
                <select 
                    class="form-select" 
                    v-model="localContent.layout"
                    @change="emitUpdate"
                >
                    <option value="grid">Grid</option>
                    <option value="list">List</option>
                    <option value="cards">Cards</option>
                    <option value="masonry">Masonry</option>
                    <option value="timeline">Timeline</option>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Items to Show</label>
                <input 
                    type="number" 
                    class="form-control" 
                    v-model.number="localContent.items_to_show"
                    @input="emitUpdate"
                    min="1"
                />
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Columns</label>
                <input 
                    type="number" 
                    class="form-control" 
                    v-model.number="localContent.columns"
                    @input="emitUpdate"
                    min="1"
                    max="6"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: any;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: any];
}>();

const localContent = ref({
    heading: props.modelValue?.heading || '',
    description: props.modelValue?.description || '',
    layout: props.modelValue?.layout || 'grid',
    items_to_show: props.modelValue?.items_to_show || 6,
    columns: props.modelValue?.columns || 3,
});

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = { ...newVal };
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};
</script>

