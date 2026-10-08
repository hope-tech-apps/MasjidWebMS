<template>
    <div class="teacher-class-subjects ms-4 mb-2 small">
        <button type="button" class="btn btn-sm btn-outline-secondary" :aria-label="`Subjects for ${name}`" :aria-expanded="expanded" @click="expand">
            Subjects for {{ name }}
        </button>
        <p v-if="modelValue === undefined" class="text-muted mt-1 mb-1">Choose subjects for this class, or choose All subjects.</p>
        <div v-if="expanded" class="mt-2">
            <p v-if="entry.loading" class="text-muted mb-1">Loading subjects…</p>
            <div v-else-if="entry.error" role="alert">
                {{ entry.error }} <button type="button" class="btn btn-sm btn-outline-secondary" @click="load">Retry</button>
            </div>
            <template v-else-if="entry.subjects">
                <div class="form-check">
                    <input :id="`subjects_all_${classId}`" class="form-check-input" type="radio" :name="`subject_mode_${classId}`" :checked="modelValue === null" @change="choose(null)">
                    <label :for="`subjects_all_${classId}`" class="form-check-label">All subjects</label>
                </div>
                <div class="form-check">
                    <input :id="`subjects_none_${classId}`" class="form-check-input" type="radio" :name="`subject_mode_${classId}`" :checked="Array.isArray(modelValue) && !modelValue.length" @change="choose([])">
                    <label :for="`subjects_none_${classId}`" class="form-check-label">No subjects</label>
                </div>
                <div v-for="subject in offered" :key="subject.id" class="form-check">
                    <input :id="`subject_${classId}_${subject.id}`" class="form-check-input" type="checkbox" :checked="Array.isArray(modelValue) && modelValue.includes(subject.id)" @change="toggle(subject.id)">
                    <label :for="`subject_${classId}_${subject.id}`" class="form-check-label">{{ subject.name }}{{ subject.hidden_at ? ' (hidden)' : '' }}</label>
                </div>
                <p v-if="!offered.length" class="text-muted mb-1">This class has no visible subjects.</p>
            </template>
        </div>
        <p v-if="error" class="text-danger mt-1 mb-0" role="alert" :data-class-subject-error="classId">{{ error }}</p>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted } from 'vue';
import ApiService from '@/core/services/ApiService';
import { apiErrorText } from '@/core/services/ApiErrors';
import type { ClassSubject } from '@/core/types/data/masjid-related/ClassSubject';

const props = defineProps<{
    classId: number; name: string; base: string; modelValue: number[] | null | undefined; error?: string;
    cache: Record<number, { subjects?: ClassSubject[]; loading?: boolean; error?: string }>;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: number[] | null] }>();
const expanded = ref(props.modelValue === undefined);
// Hidden choices already assigned remain available until the office removes them.
const retainedHidden = ref(new Set(Array.isArray(props.modelValue) ? props.modelValue : []));
// The cache belongs to one modal; a pending read may finish after its class is unticked.
const entry = computed(() => props.cache[props.classId] ?? {});
const offered = computed(() => (entry.value.subjects ?? []).filter(s => !s.hidden_at || retainedHidden.value.has(s.id)));
const choose = (ids: number[] | null) => emit('update:modelValue', ids);
const toggle = (id: number) => {
    const ids = new Set(Array.isArray(props.modelValue) ? props.modelValue : []);
    ids.has(id) ? ids.delete(id) : ids.add(id);
    // Order every choice by the class catalog, including retained hidden ids.
    choose((entry.value.subjects ?? []).filter(s => ids.has(s.id)).map(s => s.id));
};
const load = async () => {
    if (entry.value.subjects || entry.value.loading) return;
    if (!props.cache[props.classId]) props.cache[props.classId] = {};
    const state = props.cache[props.classId];
    state.loading = true; state.error = '';
    try {
        const response = await ApiService.get(`${props.base}/groups/${props.classId}/subjects` as any);
        if (response.data?.status !== 'success' || !Array.isArray(response.data.data)) throw new Error('The subjects could not be loaded.');
        state.subjects = [...response.data.data].sort((a, b) => a.position - b.position || a.id - b.id);
    } catch (failure) {
        state.error = apiErrorText(failure, 'The subjects could not be loaded.');
    } finally { state.loading = false; }
};
const expand = () => { expanded.value = !expanded.value; if (expanded.value) load(); };
onMounted(() => { if (expanded.value) load(); });
</script>

<style scoped>
.teacher-class-subjects { min-width: 0; overflow-wrap: anywhere; }
.teacher-class-subjects .btn { white-space: normal; text-align: left; }
</style>
