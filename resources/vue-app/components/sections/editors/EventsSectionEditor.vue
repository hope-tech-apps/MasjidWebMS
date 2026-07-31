<template>
    <div class="events-editor">
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            This section lists your upcoming events automatically. Add and edit the events
            themselves under Events — here you only choose the wording around the list and
            how many events to show.
        </div>
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                    placeholder="e.g., Upcoming Events"
                />
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Description</label>
                <textarea
                    class="form-control"
                    v-model="localContent.description"
                    @input="emitUpdate"
                    rows="3"
                    placeholder="Optional text shown under the heading"
                ></textarea>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Items Per Page</label>
                <input
                    type="number"
                    class="form-control"
                    v-model.number="localContent.items_per_page"
                    @input="emitUpdate"
                    min="1"
                    max="100"
                    placeholder="e.g., 10"
                />
                <div class="form-text">Number of events displayed per page.</div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { EventsSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: EventsSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: EventsSectionContent];
}>();

const localContent = ref<EventsSectionContent>({
    heading: props.modelValue?.heading || 'Upcoming Events',
    description: props.modelValue?.description || '',
    items_per_page: props.modelValue?.items_per_page || 10,
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
