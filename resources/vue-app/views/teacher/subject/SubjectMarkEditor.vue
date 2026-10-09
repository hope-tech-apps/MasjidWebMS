<template>
    <div class="subject-marks">
        <p class="fw-semibold mb-1" dir="auto">{{ heading ?? pieceTitle(shown) }}</p>
        <p v-if="shown.standard_code" class="small mb-1" dir="auto">{{ shown.standard_code }}</p>
        <p v-if="shown.detail" class="small" dir="auto">{{ shown.detail }}</p>
        <p v-if="shown.wording_changed && shown.marked_against_date" class="small text-muted">Marked against the wording of {{ shown.marked_against_date }}. The guide has since changed.</p>
        <p v-if="shown.moved_to" class="small text-muted">This lesson plan is now under {{ shown.moved_to }}. Its marks stay here.</p>
        <div v-for="(student, index) in students" :key="student.id" class="mark-row" role="group" :aria-label="`Marks for ${student.name}`">
            <strong dir="auto">{{ student.name }}</strong>
            <template v-if="readonly">
                <span>{{ levelWords(draft[index].level) }}</span>
                <p class="mark-comment mb-0" dir="auto">{{ draft[index].comment }}</p>
            </template>
            <template v-else>
                <div>
                    <div class="mark-levels">
                        <button v-for="level in orderedLevels" :key="level.level" type="button" class="btn btn-outline-primary"
                            :class="{ active: draft[index].level === level.level }" :aria-pressed="draft[index].level === level.level ? 'true' : 'false'"
                            :aria-label="`${level.level} ${level.short_label} for ${student.name}`" :disabled="saving"
                            :aria-invalid="fieldErrors[student.id]?.level ? 'true' : undefined" :aria-describedby="fieldErrors[student.id]?.level ? errorId(student.id, 'level') : undefined"
                            @click="draft[index].level = draft[index].level === level.level ? null : level.level">{{ level.level }}</button>
                    </div>
                    <p v-if="fieldErrors[student.id]?.level" :id="errorId(student.id, 'level')" class="text-danger small">{{ fieldErrors[student.id].level }}</p>
                </div>
                <div>
                    <textarea v-model="draft[index].comment" class="form-control mark-comment" rows="2" :disabled="saving"
                        :aria-label="`Comment for ${student.name}`" :aria-invalid="fieldErrors[student.id]?.comment ? 'true' : undefined"
                        :aria-describedby="fieldErrors[student.id]?.comment ? errorId(student.id, 'comment') : undefined" placeholder="Comment"></textarea>
                    <p v-if="fieldErrors[student.id]?.comment" :id="errorId(student.id, 'comment')" class="text-danger small">{{ fieldErrors[student.id].comment }}</p>
                </div>
                <div v-if="conflicts.includes(student.id)" class="mark-conflict">
                    <p class="text-danger small">Someone else changed this mark. Reload to see it.</p>
                    <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="reload">Reload</button>
                </div>
            </template>
        </div>
        <p v-if="error" class="text-danger small mt-2" role="alert">{{ error }}</p>
        <p v-if="notice" class="text-success small mt-2" role="status">{{ notice }}</p>
        <button v-if="!readonly" type="button" class="btn btn-primary mt-2" :disabled="saving || !dirty || conflicts.length > 0" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
        <span v-if="dirty && !readonly" class="small ms-2">Changes not saved.</span>
    </div>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { pieceTitle, workError, workFieldErrors, type WorkApi, type WorkLevel, type WorkMark, type WorkPiece, type WorkStudent } from './subjectWork';
const props = defineProps<{ piece: WorkPiece; students: WorkStudent[]; levels: WorkLevel[]; base: string; api: WorkApi; readonly?: boolean; heading?: string;
    /** Called with what the server accepted, even if this editor has already been left: an emit from an unmounted component goes nowhere. */
    accept?: (piece: WorkPiece) => void }>();
const emit = defineEmits<{ dirty: [value: boolean]; saved: [piece: WorkPiece] }>();
const makeDraft = (piece = props.piece) => props.students.map(student => {
    const mark = piece.marks.find(m => m.group_membership_id === student.id);
    return { group_membership_id: student.id, level: mark?.level ?? null, comment: mark?.comment ?? '', updated_at: mark?.updated_at ?? null };
});
const publish = (piece: WorkPiece) => { if (props.accept) props.accept(piece); else emit('saved', piece); };
const draft = ref<WorkMark[]>(makeDraft());
const baseline = ref<WorkMark[]>(makeDraft());
const shown = ref({ ...props.piece });
const differs = (mark: WorkMark, index: number) => mark.level !== baseline.value[index].level || (mark.comment ?? '') !== (baseline.value[index].comment ?? '');
const dirty = computed(() => draft.value.some(differs));
const fieldErrors = ref<Record<number, Record<string, string>>>({}); const conflicts = ref<number[]>([]);
const prefix = useId(); const errorId = (student: number, field: string) => `${prefix}-mark-${student}-${field}`;
const orderedLevels = computed(() => props.levels.slice().sort((a, b) => b.level - a.level));
const saving = ref(false); const error = ref(''); const notice = ref(''); let alive = true; let saveGeneration = 0;
watch(() => props.piece, value => {
    shown.value = { ...value };
    if (!dirty.value && !saving.value) { draft.value = makeDraft(); baseline.value = draft.value.map(m => ({ ...m })); }
});
watch(() => [props.piece.title, props.piece.detail], () => { shown.value.title = props.piece.title; shown.value.detail = props.piece.detail; });
watch(dirty, value => { notice.value = ''; emit('dirty', value); }, { flush: 'sync' });
const levelWords = (level: number | null) => level === null ? 'Not marked' : `${level} ${props.levels.find(l => l.level === level)?.short_label ?? ''}`;
const readPiece = async (id: number | null) => {
    const page = (await props.api.get(`${props.base}/work`)).data.data;
    return [...page.curriculum.flatMap((b: any) => b.entries), ...page.lesson_plans, ...page.own_pieces]
        .find((p: WorkPiece) => id ? p.piece_id === id : p.source === shown.value.source &&
            (p.source === 'guide' ? p.guide_subject === shown.value.guide_subject && p.grade_label === shown.value.grade_label && p.week_no === shown.value.week_no : p.lesson_plan_id === shown.value.lesson_plan_id));
};
// Reload is an explicit choice to replace this editor's draft; failed reads keep the typing.
const reload = async () => {
    if (saving.value) return;
    ++saveGeneration; saving.value = true; error.value = '';
    try {
        const saved = await readPiece(shown.value.piece_id);
        if (!alive) return;
        if (!saved) { error.value = 'This piece could not be reloaded.'; return; }
        shown.value = saved; draft.value = makeDraft(saved); baseline.value = makeDraft(saved);
        fieldErrors.value = {}; conflicts.value = []; publish(saved);
    } catch (failure) { if (alive) error.value = workError(failure, 'These marks could not be reloaded.'); }
    finally { if (alive) saving.value = false; }
};
const refreshWording = async (accepted: WorkPiece, generation: number) => {
    try {
        const saved = await readPiece(accepted.piece_id);
        if (!alive || generation !== saveGeneration || !saved) return;
        // This read refreshes wording only. It must never overwrite a subsequent save or typing.
        shown.value = { ...accepted, title: saved.title, detail: saved.detail, standard_code: saved.standard_code,
            wording_changed: saved.wording_changed, marked_against_date: saved.marked_against_date };
        publish(shown.value);
    } catch (failure) { if (alive && generation === saveGeneration) error.value = workError(failure, 'Marks saved, but the saved wording could not be reloaded.'); }
};
const save = async () => {
    if (props.readonly || saving.value || !dirty.value || conflicts.value.length) return;
    const generation = ++saveGeneration;
    saving.value = true; error.value = ''; notice.value = ''; fieldErrors.value = {};
    const sent = draft.value.filter(differs).map(m => ({ ...m, comment: m.comment?.trim() ? m.comment : null }));
    const piece = shown.value;
    const identity = piece.piece_id ? { piece_id: piece.piece_id } : piece.source === 'guide'
        ? { guide_subject: piece.guide_subject, grade_label: piece.grade_label, week_no: piece.week_no } : { lesson_plan_id: piece.lesson_plan_id };
    try {
        const response = await props.api.put(`${props.base}/marks`, { source: piece.source, ...identity, marks: sent });
        // No early return when the teacher has already left this entry: the page must still learn what was saved.
        const versions: Pick<WorkMark, 'group_membership_id' | 'updated_at'>[] = response.data.data.marks ?? [];
        const merged = new Map(piece.marks.map(m => [m.group_membership_id, m]));
        let count = piece.mark_count;
        for (const mark of sent) {
            const updated_at = versions.find(m => m.group_membership_id === mark.group_membership_id)?.updated_at ?? null;
            const saved = { ...mark, updated_at };
            const existed = merged.has(mark.group_membership_id); const keep = mark.level !== null || !!mark.comment;
            count += Number(keep) - Number(existed);
            if (keep) merged.set(mark.group_membership_id, saved); else merged.delete(mark.group_membership_id);
            const index = draft.value.findIndex(m => m.group_membership_id === mark.group_membership_id);
            draft.value[index] = { ...saved, comment: saved.comment ?? '' };
        }
        shown.value = { ...piece, piece_id: response.data.data.piece_id, marks: [...merged.values()], mark_count: count };
        baseline.value = draft.value.map(m => ({ ...m }));
        // Publish the accepted values and server versions before any read can yield or unmount us.
        publish(shown.value);
        if (!alive) return;
        emit('dirty', false); notice.value = 'Saved.';
        const accepted = shown.value;
        void refreshWording(accepted, generation);
    } catch (failure: any) {
        if (!alive) return;
        if (failure?.response?.status === 409 && Array.isArray(failure.response.data.students)) {
            conflicts.value = failure.response.data.students.map((s: any) => s.group_membership_id);
        } else {
            const keys = sent.flatMap((_, i) => [`marks.${i}.level`, `marks.${i}.comment`]);
            const errors = workFieldErrors(failure, keys); error.value = errors.general;
            for (const [key, message] of Object.entries(errors.fields)) {
                const [, index, field] = key.split('.'); const id = sent[Number(index)].group_membership_id;
                (fieldErrors.value[id] ??= {})[field] = message;
            }
        }
    } finally { if (alive) saving.value = false; }
};
onBeforeUnmount(() => { alive = false; emit('dirty', false); });
</script>
<style scoped>
.subject-marks { min-width: 0; overflow-wrap: anywhere; }
.mark-row { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 2fr); gap: .75rem; align-items: center; padding: .75rem 0; border-bottom: 1px solid #dee2e6; }
.mark-row > div { min-width: 0; }
.mark-conflict { grid-column: 1 / -1; }
.mark-levels { display: flex; gap: .5rem; }
/* Filled when chosen, as the report card's levels are: the theme's outline button only darkens its edge. */
.mark-levels button[aria-pressed="true"] { background-color: var(--bs-primary, #005c2a); border-color: var(--bs-primary, #005c2a); color: #fff; font-weight: 700; }
.mark-levels button { min-width: 44px; min-height: 44px; padding: .4rem; }
.mark-comment { min-width: 0; width: 100%; white-space: pre-wrap; }
@media (max-width: 767px) {
    .mark-row { grid-template-columns: minmax(0, 1fr); }
    .mark-comment { grid-column: 1; min-width: 44px; }
    button, input, select, textarea { min-height: 44px; min-width: 44px; }
}
</style>
