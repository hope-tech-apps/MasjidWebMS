<template>
    <div class="impact-stats-editor">
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                    placeholder="e.g., Our Impact"
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Description</label>
                <textarea
                    class="form-control"
                    v-model="localContent.description"
                    @input="emitUpdate"
                    rows="2"
                    placeholder="Optional text shown under the heading"
                ></textarea>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Period</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.period"
                    @input="emitUpdate"
                    placeholder="e.g., In 2025, Since 2010"
                />
                <div class="form-text">
                    The window these numbers cover, shown once above them. Optional,
                    but a funder will ask.
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Layout</label>
                <select
                    class="form-select"
                    v-model="localContent.layout"
                    @change="emitUpdate"
                >
                    <option value="row">Row (one line)</option>
                    <option value="grid">Grid</option>
                </select>
            </div>

            <div class="col-md-2 mb-3">
                <label class="form-label">Columns</label>
                <select
                    class="form-select"
                    v-model.number="localContent.columns"
                    @change="emitUpdate"
                    :disabled="localContent.layout !== 'grid'"
                >
                    <option :value="2">2 per row</option>
                    <option :value="3">3 per row</option>
                    <option :value="4">4 per row</option>
                </select>
                <div class="form-text">Grid only.</div>
            </div>

            <div class="col-md-2 mb-3">
                <label class="form-label">Background Color</label>
                <input
                    type="color"
                    class="form-control form-control-color flex-shrink-0"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                />
            </div>

            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Numbers</h6>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        @click="addStat"
                    >
                        <i class="bi bi-plus-circle"></i> Add Number
                    </button>
                </div>

                <div class="alert alert-secondary py-2 small">
                    Type each number exactly as it should appear — "6,000+", "$6.3M",
                    "1 in 4". It is printed as written and never reformatted, so the
                    figure on the page stays the figure you reported.
                </div>

                <div
                    v-for="(stat, index) in localContent.stats"
                    :key="index"
                    class="card mb-3"
                >
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">{{ statHeading(stat, index) }}</h6>
                            <div class="btn-group">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveStat(index, -1)"
                                    :disabled="index === 0"
                                    title="Move Up"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveStat(index, 1)"
                                    :disabled="index === localContent.stats.length - 1"
                                    title="Move Down"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    @click="removeStat(index)"
                                    title="Remove Number"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Value <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="stat.value"
                                    @input="emitUpdate"
                                    placeholder="e.g., 6,000+"
                                    required
                                />
                            </div>

                            <div class="col-md-8 mb-3">
                                <label class="form-label">Label <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="stat.label"
                                    @input="emitUpdate"
                                    placeholder="e.g., patient visits"
                                    required
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea
                                    class="form-control"
                                    v-model="stat.description"
                                    @input="emitUpdate"
                                    rows="2"
                                    placeholder="Optional line under the number, e.g., delivered at no cost to patients"
                                ></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="localContent.stats.length === 0" class="alert alert-info">
                    No numbers added yet. Click "Add Number" to add the first one.
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ImpactStatsSectionContent, ImpactStat } from '@/core/types/data/masjid-related/PageSection';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: ImpactStatsSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: ImpactStatsSectionContent];
}>();

const newStat = (): ImpactStat => ({
    value: '',
    label: '',
    description: '',
});

const normalize = (value?: ImpactStatsSectionContent): ImpactStatsSectionContent => ({
    heading: value?.heading || '',
    description: value?.description || '',
    period: value?.period || '',
    stats: value?.stats ? [...value.stats] : [],
    layout: value?.layout || 'row',
    columns: value?.columns || 3,
    background_color: value?.background_color || '#ffffff',
});

const localContent = ref<ImpactStatsSectionContent>(normalize(props.modelValue));

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = normalize(newVal);
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

const statHeading = (stat: ImpactStat, index: number): string => {
    if (!stat.value && !stat.label) {
        return `Number ${index + 1}`;
    }
    return [stat.value, stat.label].filter((part) => !!part).join(' ');
};

// No image pipeline here — nothing in this section uploads, so there are no
// position-keyed files to re-key when the order changes.
const addStat = () => {
    localContent.value.stats.push(newStat());
    emitUpdate();
};

const removeStat = (index: number) => {
    localContent.value.stats.splice(index, 1);
    emitUpdate();
};

const moveStat = (index: number, offset: number) => {
    const target = index + offset;
    if (target < 0 || target >= localContent.value.stats.length) {
        return;
    }
    const stats = [...localContent.value.stats];
    [stats[index], stats[target]] = [stats[target], stats[index]];
    localContent.value.stats = stats;
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
