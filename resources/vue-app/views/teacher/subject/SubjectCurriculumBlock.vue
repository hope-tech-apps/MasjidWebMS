<template>
    <div class="mb-4" :aria-label="`Curriculum grade ${block.grade_key}`">
        <h4 v-if="showGrade" class="h6">{{ block.grade_label }}</h4>
        <label class="d-block mb-2">Entry
            <select class="form-select" v-model="choice" :aria-label="`Entry for ${block.grade_label}`" @change="choose($event)">
                <option v-for="entry in block.entries" :key="entry.week_no" :value="entry.week_no">{{ entry.week_no }} · {{ entry.title }}</option>
            </select>
        </label>
        <SubjectMarkEditor v-if="piece" :key="selected" :piece="piece" :students="block.students" :levels="levels"
            :base="base" :api="api" :readonly="readonly" @dirty="setDirty" @saved="saved" />
    </div>
</template>
<script setup lang="ts">
import { computed, ref } from 'vue';
import SubjectMarkEditor from './SubjectMarkEditor.vue';
import type { WorkApi, WorkBlock, WorkLevel, WorkPiece } from './subjectWork';
const props = defineProps<{ block: WorkBlock; levels: WorkLevel[]; base: string; api: WorkApi; readonly?: boolean; showGrade: boolean; confirmDiscard: () => Promise<boolean> }>();
const emit = defineEmits<{ dirty: [value: boolean] }>();
const selected = ref(props.block.selected_week_no ?? props.block.opening_week_no);
const choice = ref(selected.value);
const piece = computed(() => props.block.entries.find(e => e.week_no === selected.value));
const dirty = ref(false);
const setDirty = (value: boolean) => { dirty.value = value; emit('dirty', value); };
const choose = async (event: Event) => {
    const select = event.target as HTMLSelectElement;
    const next = Number(choice.value); choice.value = selected.value; select.value = String(selected.value);
    if (next === selected.value) return;
    if (dirty.value && !await props.confirmDiscard()) return;
    selected.value = next; choice.value = next;
};
const saved = (value: WorkPiece) => { if (piece.value) Object.assign(piece.value, value); };
</script>
