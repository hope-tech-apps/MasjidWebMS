<template>
    <div class="text-editor">
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
                <label class="form-label">Content <span class="text-danger">*</span></label>
                <textarea 
                    class="form-control" 
                    v-model="localContent.content"
                    @input="emitUpdate"
                    rows="8"
                    required
                    placeholder="HTML content supported"
                ></textarea>
                <small class="text-muted">HTML tags are supported</small>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Layout</label>
                <select 
                    class="form-select" 
                    v-model="localContent.layout"
                    @change="emitUpdate"
                >
                    <option value="single_column">Single Column</option>
                    <option value="two_columns">Two Columns</option>
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
        </div>
    </div>
</template>

<script setup lang="ts">
import { TextSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: TextSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: TextSectionContent];
}>();

const localContent = ref<TextSectionContent>({
    heading: props.modelValue?.heading || '',
    content: props.modelValue?.content || '',
    layout: props.modelValue?.layout || 'single_column',
    background_color: props.modelValue?.background_color || '#ffffff',
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

