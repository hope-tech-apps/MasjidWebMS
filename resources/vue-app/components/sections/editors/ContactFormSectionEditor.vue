<template>
    <div class="contact-form-editor">
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            This section displays a contact form with a map. The form fields are predefined.
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
                    placeholder="e.g., Send Message"
                />
            </div>
            <div class="col-12 mb-3">
                <label class="form-label d-block">Map Display</label>
                <div class="form-check form-switch mt-2">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        v-model="localContent.show_map"
                        @change="emitUpdate"
                        id="showMap"
                    />
                    <label class="form-check-label" for="showMap">
                        Show map below the contact form
                    </label>
                </div>
                <small class="text-muted">When enabled, a map will be displayed below the contact form showing the mosque location.</small>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ContactFormSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: ContactFormSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: ContactFormSectionContent];
}>();

const localContent = ref<ContactFormSectionContent>({
    title: props.modelValue?.title || '',
    subtitle: props.modelValue?.subtitle || '',
    button_text: props.modelValue?.button_text || 'Send Message',
    show_map: props.modelValue?.show_map ?? true,
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

