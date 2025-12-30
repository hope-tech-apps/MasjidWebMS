<template>
    <div class="map-editor">
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
            <div class="col-md-4 mb-3">
                <label class="form-label">Map Type</label>
                <select 
                    class="form-select" 
                    v-model="localContent.map_type"
                    @change="emitUpdate"
                >
                    <option value="embed">Google Maps Embed</option>
                    <option value="iframe">Custom iFrame</option>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Zoom Level</label>
                <input 
                    type="number" 
                    class="form-control" 
                    v-model.number="localContent.zoom_level"
                    @input="emitUpdate"
                    min="1"
                    max="20"
                />
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Height</label>
                <input 
                    type="text" 
                    class="form-control" 
                    v-model="localContent.height"
                    @input="emitUpdate"
                    placeholder="e.g., 400px"
                />
            </div>
            <div class="col-12 mb-3">
                <div class="form-check form-switch">
                    <input 
                        class="form-check-input" 
                        type="checkbox" 
                        v-model="localContent.show_marker"
                        @change="emitUpdate"
                        id="showMarker"
                    />
                    <label class="form-check-label" for="showMarker">
                        Show location marker
                    </label>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { MapSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: MapSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: MapSectionContent];
}>();

const localContent = ref<MapSectionContent>({
    heading: props.modelValue?.heading || '',
    map_type: props.modelValue?.map_type || 'embed',
    zoom_level: props.modelValue?.zoom_level ?? 15,
    show_marker: props.modelValue?.show_marker ?? true,
    height: props.modelValue?.height || '400px',
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

