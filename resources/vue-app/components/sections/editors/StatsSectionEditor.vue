<template>
    <div class="stats-editor">
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
                    <option value="horizontal">Horizontal</option>
                    <option value="vertical">Vertical</option>
                </select>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Stats</label>
                <div v-for="(stat, index) in localContent.stats" :key="index" class="card mb-2">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <input 
                                    type="text" 
                                    class="form-control form-control-sm" 
                                    v-model="stat.label"
                                    @input="emitUpdate"
                                    placeholder="Label"
                                />
                            </div>
                            <div class="col-md-3">
                                <input 
                                    type="text" 
                                    class="form-control form-control-sm" 
                                    v-model="stat.value"
                                    @input="emitUpdate"
                                    placeholder="Value"
                                />
                            </div>
                            <div class="col-md-4">
                                <input 
                                    type="text" 
                                    class="form-control form-control-sm" 
                                    v-model="stat.icon"
                                    @input="emitUpdate"
                                    placeholder="Icon class"
                                />
                            </div>
                            <div class="col-md-1">
                                <button 
                                    type="button" 
                                    class="btn btn-sm btn-danger" 
                                    @click="removeStat(index)"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" @click="addStat">
                    <i class="bi bi-plus"></i> Add Stat
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { StatsSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: StatsSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: StatsSectionContent];
}>();

const localContent = ref<StatsSectionContent>({
    heading: props.modelValue?.heading || '',
    stats: props.modelValue?.stats || [],
    layout: props.modelValue?.layout || 'horizontal',
});

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = { ...newVal };
    }
}, { deep: true });

const addStat = () => {
    localContent.value.stats.push({ label: '', value: '', icon: '' });
    emitUpdate();
};

const removeStat = (index: number) => {
    localContent.value.stats.splice(index, 1);
    emitUpdate();
};

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};
</script>

