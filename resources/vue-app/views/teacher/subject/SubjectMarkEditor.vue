<template>
    <div class="subject-marks">
        <p class="fw-semibold mb-1" dir="auto">{{ heading ?? shown.title }}</p>
        <p v-if="shown.standard_code" class="small mb-1" dir="auto">{{ shown.standard_code }}</p>
        <p v-if="shown.detail" class="small" dir="auto">{{ shown.detail }}</p>
        <p v-if="shown.wording_changed && shown.marked_against_date" class="small text-muted">Marked against the wording of {{ shown.marked_against_date }}</p>
        <div v-for="(student, index) in students" :key="student.id" class="mark-row" role="group" :aria-label="`Marks for ${student.name}`">
            <strong dir="auto">{{ student.name }}</strong>
            <template v-if="readonly">
                <span>{{ levelWords(draft[index].level) }}</span>
                <p class="mark-comment mb-0" dir="auto">{{ draft[index].comment }}</p>
            </template>
            <template v-else>
                <div class="mark-levels">
                    <button v-for="level in orderedLevels" :key="level.level" type="button" class="btn btn-outline-primary"
                        :class="{ active: draft[index].level === level.level }" :aria-pressed="draft[index].level === level.level ? 'true' : 'false'"
                        :aria-label="`${level.level} ${level.short_label} for ${student.name}`" :disabled="saving"
                        @click="draft[index].level = draft[index].level === level.level ? null : level.level">{{ level.level }}</button>
                </div>
                <textarea v-model="draft[index].comment" class="form-control mark-comment" rows="2" :disabled="saving"
                    :aria-label="`Comment for ${student.name}`" placeholder="Comment"></textarea>
            </template>
        </div>
        <p v-if="error" class="text-danger small mt-2" role="alert">{{ error }}</p>
        <p v-if="notice" class="text-success small mt-2" role="status">{{ notice }}</p>
        <button v-if="!readonly" type="button" class="btn btn-primary mt-2" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
        <span v-if="dirty && !readonly" class="small ms-2">Changes not saved.</span>
    </div>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { workError, type WorkApi, type WorkLevel, type WorkMark, type WorkPiece, type WorkStudent } from './subjectWork';
const props = defineProps<{ piece: WorkPiece; students: WorkStudent[]; levels: WorkLevel[]; base: string; api: WorkApi; readonly?: boolean; heading?: string }>();
const emit = defineEmits<{ dirty: [value: boolean]; saved: [piece: WorkPiece] }>();
const makeDraft = () => props.students.map(student => {
    const mark = props.piece.marks.find(m => m.group_membership_id === student.id);
    return { group_membership_id: student.id, level: mark?.level ?? null, comment: mark?.comment ?? '' };
});
const draft = ref<WorkMark[]>(makeDraft());
const baseline = ref(JSON.stringify(draft.value));
const shown = ref({ ...props.piece });
const dirty = computed(() => JSON.stringify(draft.value) !== baseline.value);
const orderedLevels = computed(() => props.levels.slice().sort((a, b) => b.level - a.level));
const saving = ref(false); const error = ref(''); const notice = ref(''); let alive = true;
watch(() => props.piece, value => {
    shown.value = { ...value };
    if (!dirty.value && !saving.value) { draft.value = makeDraft(); baseline.value = JSON.stringify(draft.value); }
});
watch(() => [props.piece.title, props.piece.detail], () => { shown.value.title = props.piece.title; shown.value.detail = props.piece.detail; });
watch(dirty, value => { notice.value = ''; emit('dirty', value); }, { flush: 'sync' });
const levelWords = (level: number | null) => level === null ? 'Not marked' : `${level} ${props.levels.find(l => l.level === level)?.short_label ?? ''}`;
const save = async () => {
    if (props.readonly || saving.value) return;
    saving.value = true; error.value = ''; notice.value = '';
    const sent = draft.value.map(m => ({ ...m, comment: m.comment || null }));
    const piece = shown.value;
    const identity = piece.piece_id ? { piece_id: piece.piece_id } : piece.source === 'guide'
        ? { guide_subject: piece.guide_subject, grade_label: piece.grade_label, week_no: piece.week_no } : { lesson_plan_id: piece.lesson_plan_id };
    try {
        const response = await props.api.put(`${props.base}/marks`, { source: piece.source, ...identity, marks: sent });
        if (!alive) return;
        shown.value = { ...piece, piece_id: response.data.data.piece_id, marks: sent.filter(m => m.level !== null || m.comment?.trim()), mark_count: sent.filter(m => m.level !== null || m.comment?.trim()).length };
        baseline.value = JSON.stringify(draft.value); emit('dirty', false); notice.value = 'Saved.';
        // First saves copy the current server words. Refresh only this editor, preserving other drafts.
        try {
            const page = (await props.api.get(`${props.base}/work`)).data.data;
            if (!alive) return;
            const saved = [...page.curriculum.flatMap((b: any) => b.entries), ...page.lesson_plans, ...page.own_pieces]
                .find((p: WorkPiece) => shown.value.piece_id && p.piece_id === shown.value.piece_id);
            if (saved) shown.value = saved;
        } catch (readError) { if (alive) error.value = workError(readError, 'Marks saved, but the saved wording could not be reloaded.'); }
        if (alive) emit('saved', shown.value);
    } catch (failure) { if (alive) error.value = workError(failure, 'These marks could not be saved.'); }
    finally { if (alive) saving.value = false; }
};
onBeforeUnmount(() => { alive = false; emit('dirty', false); });
</script>
<style scoped>
.subject-marks { min-width: 0; overflow-wrap: anywhere; }
.mark-row { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 2fr); gap: .75rem; align-items: center; padding: .75rem 0; border-bottom: 1px solid #dee2e6; }
.mark-levels { display: flex; gap: .25rem; }
.mark-levels button { min-width: 44px; min-height: 44px; padding: .4rem; }
.mark-comment { min-width: 0; width: 100%; white-space: pre-wrap; }
@media (max-width: 767px) {
    .mark-row { grid-template-columns: minmax(0, 1fr); }
    .mark-comment { grid-column: 1; }
}
</style>
