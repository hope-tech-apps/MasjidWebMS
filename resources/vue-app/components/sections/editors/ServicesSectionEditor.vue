<template>
    <div class="services-editor">
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            This section displays services from your masjid. Configure display options below.
        </div>
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input 
                    type="text" 
                    class="form-control" 
                    v-model="localContent.title"
                    @input="emitUpdate"
                    placeholder="Enter section title"
                    required
                />
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Subtitle</label>
                <textarea 
                    class="form-control" 
                    v-model="localContent.subtitle"
                    @input="emitUpdate"
                    rows="3"
                    placeholder="Enter subtitle text"
                ></textarea>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Button Text</label>
                <input 
                    type="text" 
                    class="form-control" 
                    v-model="localContent.button_text"
                    @input="emitUpdate"
                    placeholder="e.g., View All Services"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ServicesListSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: ServicesListSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: ServicesListSectionContent];
}>();

const localContent = ref<ServicesListSectionContent>({
    title: props.modelValue?.title || '',
    subtitle: props.modelValue?.subtitle || '',
    button_text: props.modelValue?.button_text || 'View All',
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

