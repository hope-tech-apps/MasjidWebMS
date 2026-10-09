<template>
    <section data-work-area="own" class="mb-4">
        <h3 class="h5">Your own pieces</h3>
        <button v-if="!readonly && !form" type="button" class="btn btn-outline-primary mb-2" @click="edit()">Add a piece</button>
        <form v-if="form && !readonly" class="border rounded p-3 mb-3" @submit.prevent="save">
            <label class="d-block mb-2">Title <input v-model="form.title" aria-label="Piece title" class="form-control" maxlength="255" required :disabled="busy" :aria-invalid="fieldErrors.title ? 'true' : undefined" :aria-describedby="fieldErrors.title ? `${prefix}-title` : undefined"></label>
            <p v-if="fieldErrors.title" :id="`${prefix}-title`" class="text-danger small">{{ fieldErrors.title }}</p>
            <label class="d-block">Detail (optional) <textarea v-model="form.detail" aria-label="Piece detail" class="form-control" rows="3" :disabled="busy" :aria-invalid="fieldErrors.detail ? 'true' : undefined" :aria-describedby="fieldErrors.detail ? `${prefix}-detail` : undefined"></textarea></label>
            <p v-if="fieldErrors.detail" :id="`${prefix}-detail`" class="text-danger small">{{ fieldErrors.detail }}</p>
            <p v-if="error" class="text-danger small mt-2" role="alert">{{ error }}</p>
            <button class="btn btn-primary mt-2" :disabled="busy">{{ busy ? 'Saving…' : 'Save piece' }}</button>
            <button type="button" class="btn btn-link mt-2" :disabled="busy" @click="cancel">Cancel</button>
        </form>
        <div v-if="readError" class="mb-2">
            <p class="text-danger small" role="alert">{{ readError }}</p>
            <button type="button" class="btn btn-outline-secondary" @click="refresh">Reload list</button>
        </div>
        <p v-if="error && !form" class="text-danger small" role="alert">{{ error }}</p>
        <p v-if="!pieces.length" class="text-muted small">No own pieces yet.</p>
        <article v-for="piece in pieces" :key="piece.piece_id" class="border-bottom py-2">
            <button type="button" class="btn btn-link text-start px-0" :aria-expanded="opened === piece.piece_id ? 'true' : 'false'" @click="open(piece)">{{ piece.title }}</button>
            <p class="piece-detail mb-1" dir="auto">{{ piece.detail }}</p>
            <p class="small text-muted">{{ piece.mark_count }} {{ piece.mark_count === 1 ? 'student marked' : 'students marked' }}</p>
            <template v-if="!readonly">
                <button type="button" class="btn btn-outline-secondary btn-sm me-2" :disabled="busy" :aria-label="`Edit piece ${piece.piece_id}`" @click="edit(piece)">Edit</button>
                <button type="button" class="btn btn-outline-danger btn-sm" :disabled="busy" :aria-label="`Delete piece ${piece.piece_id}`" @click="askDelete(piece)">Delete</button>
            </template>
            <SubjectMarkEditor v-if="opened === piece.piece_id" :piece="piece" :students="students" :levels="levels" :base="base" :api="api" :readonly="readonly"
                @dirty="marksDirty = $event" @saved="Object.assign(piece, $event)" />
        </article>
        <div v-if="removing && !readonly" class="border rounded p-3 mt-2" role="group" aria-label="Confirm piece deletion">
            <p>{{ deleteWords }}</p>
            <button type="button" class="btn btn-danger" :disabled="busy" @click="remove">Delete piece</button>
            <button type="button" class="btn btn-link" :disabled="busy" @click="removing = null">Cancel</button>
        </div>
    </section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import SubjectMarkEditor from './SubjectMarkEditor.vue';
import { workError, workFieldErrors, type WorkApi, type WorkLevel, type WorkPiece, type WorkStudent } from './subjectWork';
const props = defineProps<{ pieces: WorkPiece[]; students: WorkStudent[]; levels: WorkLevel[]; base: string; api: WorkApi; readonly?: boolean; confirmDiscard: () => Promise<boolean>; refresh: () => Promise<void>; readError?: string }>();
const emit = defineEmits<{ dirty: [value: boolean];  }>();
const form = ref<{ id: number | null; title: string; detail: string } | null>(null);
const fieldErrors = ref<Record<string, string>>({}); const prefix = useId();
const baseline = ref(''); const error = ref(''); const busy = ref(false); const opened = ref<number | null>(null);
const marksDirty = ref(false); const removing = ref<{ id: number; count: number } | null>(null); let alive = true;
const deleteWords = computed(() => {
    const correction = form.value?.id === removing.value?.id && formDirty.value;
    const parts = [removing.value?.count ? `its ${removing.value.count} marks` : '', correction ? 'the correction you were typing' : ''].filter(Boolean);
    return `Delete this piece${parts.length ? ` and ${parts.join(' and ')}` : ''}?`;
});
const formDirty = computed(() => form.value !== null && JSON.stringify(form.value) !== baseline.value);
watch(() => formDirty.value || marksDirty.value, value => emit('dirty', value), { flush: 'sync' });
const open = async (piece: WorkPiece) => {
    if (busy.value || marksDirty.value && !await props.confirmDiscard()) return;
    opened.value = opened.value === piece.piece_id ? null : piece.piece_id;
};
const edit = async (piece?: WorkPiece) => {
    if (props.readonly || busy.value || formDirty.value && !await props.confirmDiscard()) return;
    form.value = { id: piece?.piece_id ?? null, title: piece?.title ?? '', detail: piece?.detail ?? '' };
    baseline.value = JSON.stringify(form.value); error.value = ''; fieldErrors.value = {}; removing.value = null;
};
const cancel = async () => { if (!formDirty.value || await props.confirmDiscard()) { form.value = null; error.value = ''; } };
const save = async () => {
    if (props.readonly || busy.value || !form.value) return;
    if (!form.value.title.trim()) { fieldErrors.value = { title: 'Give this piece a title.' }; return; }
    const f = form.value; busy.value = true; error.value = ''; fieldErrors.value = {};
    try {
        const body = { title: f.title, detail: f.detail || null };
        if (f.id === null) await props.api.post(`${props.base}/pieces`, body);
        else await props.api.put(`${props.base}/pieces/${f.id}`, body);
        if (alive) { form.value = null; await props.refresh(); }
    } catch (failure) { if (alive) { const errors = workFieldErrors(failure, ['title', 'detail']); fieldErrors.value = errors.fields; error.value = errors.general; } }
    finally { if (alive) busy.value = false; }
};
const askDelete = async (piece: WorkPiece) => {
    if (props.readonly || busy.value) return;
    if (opened.value === piece.piece_id && marksDirty.value && !await props.confirmDiscard()) return;
    if (opened.value === piece.piece_id) opened.value = null;
    removing.value = { id: piece.piece_id!, count: piece.mark_count }; error.value = '';
};
const remove = async () => {
    if (props.readonly || busy.value || !removing.value) return;
    busy.value = true; error.value = ''; fieldErrors.value = {};
    try {
        await props.api.delete(`${props.base}/pieces/${removing.value.id}`, { mark_count: removing.value.count });
        if (alive) { if (form.value?.id === removing.value.id) { form.value = null; fieldErrors.value = {}; } removing.value = null; await props.refresh(); }
    } catch (failure: any) {
        if (!alive) return;
        if (failure?.response?.status === 409 && Number.isInteger(failure.response.data.mark_count)) removing.value!.count = failure.response.data.mark_count;
        error.value = workError(failure, 'This piece could not be deleted.');
    } finally { if (alive) busy.value = false; }
};
onBeforeUnmount(() => { alive = false; emit('dirty', false); });
</script>
<style scoped>
section { min-width: 0; overflow-wrap: anywhere; }
.piece-detail { white-space: pre-wrap; }
button { min-height: 44px; white-space: normal; overflow-wrap: anywhere; }
@media (max-width: 767px) {
    button, input, select, textarea { min-height: 44px; min-width: 44px; }
}
</style>
