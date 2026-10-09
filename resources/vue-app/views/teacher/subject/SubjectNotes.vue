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
                    <select v-model="form.student" class="form-select" aria-label="Student for note" required :disabled="busy">
                        <option :value="null" disabled>Pick a student</option><option v-for="student in students" :key="student.id" :value="student.id">{{ student.name }}</option>
                    </select>
                </label>
            </template>
            <p v-else>{{ form.studentName }}</p>
            <label class="d-block">Note <textarea v-model="form.body" aria-label="Note text" class="form-control" rows="3" required :disabled="busy"></textarea></label>
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
            <p class="note-text mb-1" dir="auto">{{ note.body }}</p>
            <p class="small text-muted">{{ note.author_name }} · <time :datetime="note.created_at">{{ formatDate(note.created_at) }}</time></p>
            <template v-if="!readonly">
                <button type="button" class="btn btn-outline-secondary btn-sm me-2" :aria-label="`Edit note ${note.id}`" :disabled="busy" @click="start(note)">Edit</button>
                <button type="button" class="btn btn-outline-danger btn-sm" :aria-label="`Delete note ${note.id}`" :disabled="busy" @click="removeId = note.id; error = ''">Delete</button>
            </template>
        </article>
        <div v-if="removeId !== null && !readonly" class="border rounded p-3 mt-2" role="group" aria-label="Confirm note deletion">
            <p>Delete this note?</p>
            <button type="button" class="btn btn-danger" :disabled="busy" @click="remove">Delete note</button>
            <button type="button" class="btn btn-link" :disabled="busy" @click="removeId = null">Cancel</button>
        </div>
    </section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { workError, type WorkApi, type WorkNote, type WorkStudent } from './subjectWork';
const props = defineProps<{ notes: WorkNote[]; students: WorkStudent[]; base: string; api: WorkApi; readonly?: boolean; confirmDiscard: () => Promise<boolean>; refresh: () => Promise<void>; readError?: string }>();
const emit = defineEmits<{ dirty: [value: boolean];  }>();
const form = ref<{ id: number | null; about: string; student: number | null; body: string; studentName: string } | null>(null);
const baseline = ref(''); const error = ref(''); const busy = ref(false); const removeId = ref<number | null>(null); let alive = true;
const dirty = computed(() => form.value !== null && JSON.stringify(form.value) !== baseline.value);
watch(dirty, value => emit('dirty', value), { flush: 'sync' });
const start = async (note?: WorkNote) => {
    if (props.readonly || busy.value || dirty.value && !await props.confirmDiscard()) return;
    form.value = { id: note?.id ?? null, about: note?.group_membership_id ? 'student' : 'class', student: note?.group_membership_id ?? null, body: note?.body ?? '', studentName: note?.student_name ?? '' };
    baseline.value = JSON.stringify(form.value); error.value = ''; removeId.value = null;
};
const cancel = async () => { if (!dirty.value || await props.confirmDiscard()) { form.value = null; error.value = ''; } };
const save = async () => {
    if (props.readonly || busy.value || !form.value) return;
    const f = form.value;
    if (!f.body.trim()) { error.value = 'Write a note before saving.'; return; }
    if (f.id === null && f.about === 'student' && !f.student) { error.value = 'Pick a student.'; return; }
    busy.value = true; error.value = '';
    try {
        if (f.id !== null) await props.api.put(`${props.base}/notes/${f.id}`, { body: f.body });
        else await props.api.post(`${props.base}/notes`, { group_membership_id: f.about === 'student' ? f.student : null, body: f.body });
        if (alive) { form.value = null; emit('dirty', false); await props.refresh(); }
    } catch (failure) { if (alive) error.value = workError(failure, 'This note could not be saved.'); }
    finally { if (alive) busy.value = false; }
};
const remove = async () => {
    if (props.readonly || busy.value || removeId.value === null) return;
    busy.value = true; error.value = '';
    try { await props.api.delete(`${props.base}/notes/${removeId.value}`); if (alive) { removeId.value = null; await props.refresh(); } }
    catch (failure) { if (alive) error.value = workError(failure, 'This note could not be deleted.'); }
    finally { if (alive) busy.value = false; }
};
const formatDate = (value: string) => new Date(value).toLocaleString();
onBeforeUnmount(() => { alive = false; emit('dirty', false); });
</script>
<style scoped>
section { min-width: 0; overflow-wrap: anywhere; }
.note-text { white-space: pre-wrap; }
button { min-height: 44px; }
</style>
