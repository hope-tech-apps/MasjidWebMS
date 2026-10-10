<template>
    <section data-work-area="notes" class="mb-4">
        <h3 class="h5">Notes and updates</h3>
        <button v-if="!readonly && !form" type="button" class="btn btn-outline-primary mb-2" @click="start()">New note</button>
        <form v-if="form && !readonly" class="border rounded p-3 mb-3" @submit.prevent="save">
            <template v-if="form.id === null">
                <label class="d-block mb-2">About
                    <select v-model="form.about" class="form-select" aria-label="Note about" :disabled="busy">
                        <option value="class">For the whole class</option><option value="student">About one student</option>
                    </select>
                </label>
                <label v-if="form.about === 'student'" class="d-block mb-2">Student
                    <select v-model="form.student" class="form-select" aria-label="Student for note" required :disabled="busy" :aria-invalid="fieldErrors.group_membership_id ? 'true' : undefined" :aria-describedby="fieldErrors.group_membership_id ? `${prefix}-student` : undefined">
                        <option :value="null" disabled>Pick a student</option><option v-for="student in students" :key="student.id" :value="student.id">{{ student.name }}</option>
                    </select>
                </label>
            </template>
            <p v-else>{{ form.studentName }}</p>
            <p v-if="fieldErrors.group_membership_id" :id="`${prefix}-student`" class="text-danger small">{{ fieldErrors.group_membership_id }}</p>
            <label class="d-block">Note <textarea v-model="form.body" aria-label="Note text" class="form-control" rows="3" required :disabled="busy" :aria-invalid="fieldErrors.body ? 'true' : undefined" :aria-describedby="fieldErrors.body ? `${prefix}-body` : undefined"></textarea></label>
            <p v-if="fieldErrors.body" :id="`${prefix}-body`" class="text-danger small">{{ fieldErrors.body }}</p>
            <div v-if="sharing" class="mt-2">
                <label class="share-control"><input v-model="form.shared_with_family" type="checkbox" :disabled="busy" :aria-invalid="fieldErrors.shared_with_family ? 'true' : undefined" :aria-describedby="fieldErrors.shared_with_family ? `${prefix}-sharing` : undefined"> Share with the family</label>
                <p v-if="form.shared_with_family" class="small mb-0">{{ form.about === 'student' ? "This student's family can read this." : 'Every family in this class can read this.' }}</p>
                <p v-if="fieldErrors.shared_with_family" :id="`${prefix}-sharing`" class="text-danger small">{{ fieldErrors.shared_with_family }}</p>
            </div>
            <p v-if="error" class="text-danger small mt-2" role="alert">{{ error }}</p>
            <button class="btn btn-primary mt-2" :disabled="busy">{{ busy ? 'Saving…' : 'Save' }}</button>
            <button type="button" class="btn btn-link mt-2" :disabled="busy" @click="cancel">Cancel</button>
        </form>
        <div v-if="readError" class="mb-2">
            <p class="text-danger small" role="alert">{{ readError }}</p>
            <button type="button" class="btn btn-outline-secondary" @click="refresh">Reload list</button>
        </div>
        <p v-if="error && !form" class="text-danger small" role="alert">{{ error }}</p>
        <p v-if="!notes.length" class="text-muted small">No notes yet.</p>
        <article v-for="note in notes" :key="note.id" class="border-bottom py-2">
            <strong dir="auto">{{ note.student_name }}</strong>
            <span v-if="sharing && note.shared_with_family" class="small d-block">Shared with the family</span>
            <p class="note-text mb-1" dir="auto">{{ note.body }}</p>
            <p class="small text-muted">{{ note.author_name }} · <time :datetime="note.created_at">{{ formatDate(note.created_at) }}</time></p>
            <template v-if="!readonly">
                <button type="button" class="btn btn-outline-secondary btn-sm me-2" :aria-label="`Edit note: ${firstWords(note.body)}`" :disabled="busy" @click="start(note)">Edit</button>
                <button type="button" class="btn btn-outline-danger btn-sm" :aria-label="`Delete note: ${firstWords(note.body)}`" :disabled="busy" @click="removeId = note.id; error = ''">Delete</button>
            </template>
        </article>
        <div v-if="removeId !== null && !readonly" class="border rounded p-3 mt-2" role="group" aria-label="Confirm note deletion">
            <p>{{ form?.id === removeId && dirty ? 'Delete this note and the correction you were typing?' : 'Delete this note?' }}</p>
            <button type="button" class="btn btn-danger" :disabled="busy" @click="remove">Delete note</button>
            <button type="button" class="btn btn-link" :disabled="busy" @click="removeId = null">Cancel</button>
        </div>
    </section>
</template>
<script setup lang="ts">
import { computed, inject, onBeforeUnmount, ref, useId, watch } from 'vue';
import { firstWords, workError, workFieldErrors, workMoment, type WorkApi, type WorkNote, type WorkStudent } from './subjectWork';
const props = defineProps<{ notes: WorkNote[]; students: WorkStudent[]; base: string; api: WorkApi; readonly?: boolean; confirmDiscard: () => Promise<boolean>; refresh: () => Promise<void>; readError?: string }>();
const sharing = inject('subjectSharing', computed(() => false));
const emit = defineEmits<{ dirty: [value: boolean];  }>();
const form = ref<{ id: number | null; about: string; student: number | null; body: string; studentName: string; shared_with_family?: boolean } | null>(null);
const fieldErrors = ref<Record<string, string>>({}); const prefix = useId();
const baseline = ref(''); const error = ref(''); const busy = ref(false); const removeId = ref<number | null>(null); let alive = true;
const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== baseline.value);
watch(dirty, value => emit('dirty', value), { flush: 'sync' });
const start = async (note?: WorkNote) => {
    if (props.readonly || busy.value || dirty.value && !await props.confirmDiscard()) return;
    form.value = { id: note?.id ?? null, about: note?.group_membership_id ? 'student' : 'class', student: note?.group_membership_id ?? null, body: note?.body ?? '', studentName: note?.student_name ?? '', ...(sharing.value ? { shared_with_family: note?.shared_with_family === true } : {}) };
    baseline.value = JSON.stringify(form.value); error.value = ''; fieldErrors.value = {}; removeId.value = null;
    editingVersion.value = note?.version;
};
// The version of the note as it was when its form opened; a later list refresh must not replace it.
const editingVersion = ref<string | undefined>(undefined);
const cancel = async () => { if (!dirty.value || await props.confirmDiscard()) { form.value = null; error.value = ''; } };
const save = async () => {
    if (props.readonly || busy.value || !form.value) return;
    const f = form.value;
    if (!f.body.trim()) { fieldErrors.value = { body: 'Write a note before saving.' }; return; }
    if (f.id === null && f.about === 'student' && !f.student) { fieldErrors.value = { group_membership_id: 'Pick a student.' }; return; }
    busy.value = true; error.value = ''; fieldErrors.value = {};
    try {
        const shared = sharing.value ? { shared_with_family: f.shared_with_family === true } : {};
        // An edit sends the tick only when the teacher changed it, and the version it was loaded with:
        // a tab left open must never share a note again after someone un-shared it.
        const opened = JSON.parse(baseline.value) as { shared_with_family?: boolean };
        const tick = sharing.value && (opened.shared_with_family === true) !== (f.shared_with_family === true) ? { shared_with_family: f.shared_with_family === true } : {};
        if (f.id !== null) await props.api.put(`${props.base}/notes/${f.id}`, { body: f.body, ...tick, ...(sharing.value ? { version: editingVersion.value } : {}) });
        else await props.api.post(`${props.base}/notes`, { group_membership_id: f.about === 'student' ? f.student : null, body: f.body, ...shared });
        if (alive) { form.value = null; emit('dirty', false); await props.refresh(); }
    } catch (failure) { if (alive) { const errors = workFieldErrors(failure, ['body', 'group_membership_id', ...(sharing.value ? ['shared_with_family'] : [])]); fieldErrors.value = errors.fields; error.value = errors.general; } }
    finally { if (alive) busy.value = false; }
};
const remove = async () => {
    if (props.readonly || busy.value || removeId.value === null) return;
    busy.value = true; error.value = ''; fieldErrors.value = {};
    try { await props.api.delete(`${props.base}/notes/${removeId.value}`); if (alive) { if (form.value?.id === removeId.value) { form.value = null; fieldErrors.value = {}; } removeId.value = null; await props.refresh(); } }
    catch (failure) { if (alive) error.value = workError(failure, 'This note could not be deleted.'); }
    finally { if (alive) busy.value = false; }
};
const formatDate = workMoment;
onBeforeUnmount(() => { alive = false; emit('dirty', false); });
</script>
<style scoped>
section { min-width: 0; overflow-wrap: anywhere; }
.share-control { display: flex; align-items: center; gap: .5rem; min-height: 44px; cursor: pointer; }
.share-control input { flex: 0 0 auto; }
.note-text { white-space: pre-wrap; }
button { min-height: 44px; }
@media (max-width: 767px) {
    button, input, select, textarea { min-height: 44px; min-width: 44px; }
}
</style>
