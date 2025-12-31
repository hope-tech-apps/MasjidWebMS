<template>
    <div class="social-media-editor">
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Social media links are managed separately. This section controls how they are displayed.
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
            <div class="col-md-6 mb-3">
                <label class="form-label">Layout</label>
                <select 
                    class="form-select" 
                    v-model="localContent.layout"
                    @change="emitUpdate"
                >
                    <option value="icons">Icons Only</option>
                    <option value="buttons">Buttons</option>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Icon Size</label>
                <select 
                    class="form-select" 
                    v-model="localContent.icon_size"
                    @change="emitUpdate"
                >
                    <option value="small">Small</option>
                    <option value="medium">Medium</option>
                    <option value="large">Large</option>
                </select>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { SocialMediaSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: SocialMediaSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: SocialMediaSectionContent];
}>();

const localContent = ref<SocialMediaSectionContent>({
    heading: props.modelValue?.heading || '',
    description: props.modelValue?.description || '',
    layout: props.modelValue?.layout || 'icons',
    icon_size: props.modelValue?.icon_size || 'medium',
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

