<template>
    <section class="class-subject-manager border rounded p-3 mb-4" aria-label="Class subjects">
        <button type="button" class="btn btn-outline-secondary" :aria-expanded="String(expanded)" :aria-controls="`${id}-body`" @click="expanded = !expanded">Class subjects ({{ subjects.length }})</button>
        <div v-if="expanded" :id="`${id}-body`" class="mt-3">
        <p class="text-muted small">The office manages this class's subjects. Removing a subject hides it and keeps all its work.</p>
        <p v-if="loading" role="status">Loading subjects…</p>
        <div v-if="error" class="alert alert-warning" role="alert">
            {{ error }} <button v-if="loadFailed" type="button" class="btn btn-sm btn-outline-secondary" @click="load">Retry</button>
        </div>
        <p v-if="success" role="status" class="text-muted small">{{ success }}</p>
        <template v-if="loaded">
            <ol class="list-group mb-3">
                <li v-for="(subject, index) in subjects" :key="subject.id" class="list-group-item">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-semibold" dir="auto">{{ subject.name }}</span>
                        <span v-if="subject.hidden_at" class="badge bg-secondary">Hidden</span>
                        <span class="text-muted small">Holds: {{ toolLabel(subject.tool) }}</span>
                        <span v-if="followed(subject).length" class="text-muted small">Follows the curriculum for: {{ followed(subject).join(', ') }}</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="saving" :aria-label="`Rename ${subject.name}`" @click="edit(subject)">Rename</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="saving || subject.hidden_at != null || visibleNeighbour(index, -1) === null" :aria-label="`Move ${subject.name} up`" @click="move(index, -1)">Up</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="saving || subject.hidden_at != null || visibleNeighbour(index, 1) === null" :aria-label="`Move ${subject.name} down`" @click="move(index, 1)">Down</button>
                        <button v-if="subject.hidden_at" type="button" class="btn btn-sm btn-outline-success" :disabled="saving" :aria-label="`Bring back ${subject.name}`" @click="restore(subject)">Bring back</button>
                        <button v-else type="button" class="btn btn-sm btn-outline-danger" :disabled="saving" :aria-label="`Remove ${subject.name}`" @click="removing = subject">Remove</button>
                    </div>
                    <div v-if="removing?.id === subject.id" class="alert alert-warning mt-2 mb-0">
                        Hide {{ subject.name }}? All its work is kept. Nothing is deleted.
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-danger" :disabled="saving" @click="hide(subject)">Hide subject</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="saving" @click="removing = null">Keep subject</button>
                        </div>
                    </div>
                </li>
            </ol>
            <form data-subject-form @submit.prevent="save">
                <h4 class="h6">{{ editing === null ? 'Add a subject' : 'Edit subject' }}</h4>
                <div v-if="editing === null" class="row g-2 mb-2">
                    <div class="col-12 col-md-6">
                        <label :for="`${id}-school`" class="form-label">From the school's list</label>
                        <select :id="`${id}-school`" class="form-select" data-subject-field="school" :disabled="saving" value="" @change="fromList($event)">
                            <option value="">Choose a subject</option>
                            <option v-for="name in schoolNames" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label :for="`${id}-curriculum`" class="form-label">From the curriculum</label>
                        <select :id="`${id}-curriculum`" class="form-select" data-subject-field="curriculum" :disabled="saving" value="" @change="fromCurriculum($event)">
                            <option value="">Choose a subject</option>
                            <option v-for="name in guideSubjects" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-12">
                        <label :for="`${id}-name`" class="form-label">Name</label>
                        <input :id="`${id}-name`" ref="nameField" v-model="form.name" data-subject-field="name" class="form-control" maxlength="64" required :disabled="saving">
                    </div>
                    <div v-if="editing === null" class="col-12 col-md-6">
                        <label :for="`${id}-guide`" class="form-label">Follows the curriculum for</label>
                        <select :id="`${id}-guide`" v-model="form.guide_subject" data-subject-field="guide" class="form-select" :disabled="saving">
                            <option v-if="editing === null" :value="undefined">Choose automatically</option>
                            <option :value="null">Nothing</option>
                            <option v-for="name in guideChoices" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </div>
                    <fieldset v-if="editing !== null" class="col-12 curriculum-checklist">
                        <legend class="h6">Follows the curriculum for</legend>
                        <label v-for="name in guideSubjects" :key="name" class="d-flex gap-2 align-items-start py-2">
                            <input type="checkbox" class="form-check-input flex-shrink-0" :data-guide-subject="name" :checked="form.guide_subjects.includes(name)" :disabled="saving" @change="toggleGuide(name, $event)">
                            <span dir="auto">{{ name }}<small class="d-block text-muted">{{ guideGrades[name]?.length ? guideGrades[name].join(', ') : "no entries for this class's grades" }}</small></span>
                        </label>
                        <p v-if="!guideSubjects.length" class="text-muted small">This school has no curriculum subjects yet.</p>
                    </fieldset>
                    <div class="col-12 col-md-6">
                        <label :for="`${id}-tool`" class="form-label">Holds</label>
                        <select :id="`${id}-tool`" v-model="form.tool" data-subject-field="tool" class="form-select" :disabled="saving">
                            <option v-if="editing === null" :value="undefined">Choose automatically</option>
                            <option :value="null">Nothing</option>
                            <option value="hifdh">Hifdh log</option>
                            <option value="arabic_letters">Arabic letters</option>
                            <option value="english_letters">English letters</option>
                        </select>
                    </div>
                </div>
                <label v-if="editing === null" class="form-check d-flex gap-2 align-items-start mt-3">
                    <input v-model="attachSavedWork" data-subject-field="attach" class="form-check-input" type="checkbox" :disabled="saving">
                    <span>Attach saved work under this name</span>
                </label>
                <p v-if="editing === null" class="text-muted small">Choose this only to attach work already saved under the new subject's name.</p>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" class="btn btn-success" :disabled="saving || !form.name.trim()">{{ editing === null ? 'Add subject' : 'Save subject' }}</button>
                    <button v-if="editing !== null" type="button" class="btn btn-outline-secondary" :disabled="saving" @click="cancel">Cancel edit</button>
                </div>
            </form>
            <button type="button" class="btn btn-outline-success mt-3" :disabled="saving" @click="addCurrentGrades">Add subjects for current grades</button>
            <p class="text-muted small mt-2 mb-0">Adds missing subjects for the students' current grades. Existing subjects and work are kept.</p>
        </template>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import ApiService from '@/core/services/ApiService';
import { classSubjectApi } from '@/composables/useClassSubjects';
import { apiErrorText } from '@/core/services/ApiErrors';
import type { ClassSubject, ClassSubjectTool } from '@/core/types/data/masjid-related/ClassSubject';

const props = defineProps<{ base: string }>();
const emit = defineEmits<{ changed: [subjects: ClassSubject[]] }>();
const id = `class-subject-form-${useId()}`;
const expanded = ref(false);
const subjects = ref<ClassSubject[]>([]);
const guideSubjects = ref<string[]>([]);
const guideGrades = ref<Record<string, string[]>>({});
const schoolNames = ref<string[]>([]);
const loading = ref(true);
const loaded = ref(false);
const saving = ref(false);
const loadFailed = ref(false);
const error = ref('');
const success = ref('');
const editing = ref<number | null>(null);
const removing = ref<ClassSubject | null>(null);
const nameField = ref<HTMLElement | null>(null);
type SubjectForm = { name: string; guide_subject: string | null | undefined; guide_subjects: string[]; tool: ClassSubjectTool | null | undefined };
const freshForm = (): SubjectForm => ({ name: '', guide_subject: undefined, guide_subjects: [], tool: undefined });
const form = ref(freshForm());
const attachSavedWork = ref(false);
const guideChoices = computed(() => [...new Set([...guideSubjects.value, ...(form.value.guide_subject ? [form.value.guide_subject] : [])])]);
let alive = true;
let generation = 0;
const api = () => classSubjectApi(ApiService, props.base);
const toolLabel = (tool: ClassSubjectTool | null) => tool ? { hifdh: 'Hifdh log', arabic_letters: 'Arabic letters', english_letters: 'English letters' }[tool] : 'Nothing';

const reload = async () => {
    const token = ++generation;
    const response = await api().list();
    if (!alive || token !== generation) return;
    subjects.value = response.data;
    guideSubjects.value = response.meta.guide_subjects;
    guideGrades.value = response.meta.guide_subject_grades ?? {};
    loaded.value = true;
    emit('changed', subjects.value);
};
const load = async () => {
    if (!alive || saving.value) return;
    loading.value = true; loadFailed.value = false; error.value = '';
    try {
        await reload();
        const names = await api().schoolNames();
        if (alive) schoolNames.value = names;
    } catch (failure) {
        if (alive) { error.value = apiErrorText(failure, 'The subjects could not be loaded.'); loadFailed.value = true; }
    } finally { if (alive) loading.value = false; }
};
const guideTouched = ref(false);
const cancel = () => { editing.value = null; form.value = freshForm(); attachSavedWork.value = false; guideTouched.value = false; };
const edit = (subject: ClassSubject) => {
    if (saving.value) return;
    editing.value = subject.id;
    form.value = { name: subject.name, guide_subject: subject.guide_subject, guide_subjects: [...followed(subject)], tool: subject.tool };
    guideTouched.value = false;
    error.value = ''; success.value = ''; removing.value = null;
    nextTick(() => nameField.value?.focus());
};
const followed = (subject: ClassSubject) => subject.guide_subjects ?? (subject.guide_subject ? [subject.guide_subject] : []);
const toggleGuide = (name: string, event: Event) => {
    const ticked = (event.target as HTMLInputElement).checked;
    guideTouched.value = true;
    form.value.guide_subjects = ticked ? [...new Set([...form.value.guide_subjects, name])] : form.value.guide_subjects.filter(value => value !== name);
};
const fromList = (event: Event) => { const name = (event.target as HTMLSelectElement).value; if (name) form.value.name = name; };
const fromCurriculum = (event: Event) => {
    const name = (event.target as HTMLSelectElement).value;
    if (name) { form.value.name = name; form.value.guide_subject = name; }
};
const mutate = async (write: () => Promise<unknown>, words: string | ((response: any) => string), after?: () => void) => {
    if (saving.value || !alive) return;
    saving.value = true; error.value = ''; success.value = '';
    let wrote = false;
    let savedWords = typeof words === 'string' ? words : '';
    try {
        const response = await write(); wrote = true;
        savedWords = typeof words === 'function' ? words(response) : words;
        if (!alive) return;
        after?.();
        await reload();
        if (alive) success.value = savedWords;
    } catch (failure) {
        if (alive) error.value = wrote
            ? `${savedWords} The list could not reload. ${apiErrorText(failure, 'Try again.')}`
            : apiErrorText(failure, 'The subject could not be changed.');
    } finally { if (alive) saving.value = false; }
};
const save = () => {
    if (!form.value.name.trim() || saving.value) return;
    const payload: { name: string; guide_subject?: string | null; guide_subjects?: string[]; tool?: ClassSubjectTool | null; attach_saved_work?: boolean } = { name: form.value.name.trim() };
    if (editing.value !== null) {
        // Sent only when the office changed a tick: renaming a subject must never reorder or trim what it follows.
        // Kept in the saved order, new ticks after it, so the first followed subject (which older screens read) does not move.
        if (guideTouched.value) {
            payload.guide_subjects = form.value.guide_subjects.filter(name => guideSubjects.value.includes(name));
            payload.guide_subject = payload.guide_subjects[0] ?? null;
        }
    } else if (form.value.guide_subject !== undefined) payload.guide_subject = form.value.guide_subject;
    if (form.value.tool !== undefined) payload.tool = form.value.tool;
    if (editing.value === null && attachSavedWork.value) payload.attach_saved_work = true;
    return mutate(() => api().save(editing.value, payload), editing.value === null ? 'Subject added.' : 'Subject saved.', cancel);
};
// Visible subjects exchange their slots; hidden rows keep their ordered positions.
const visibleNeighbour = (index: number, direction: number): number | null => {
    for (let candidate = index + direction; candidate >= 0 && candidate < subjects.value.length; candidate += direction) {
        if (!subjects.value[candidate].hidden_at) return candidate;
    }
    return null;
};
const move = (index: number, direction: number) => {
    const target = visibleNeighbour(index, direction);
    if (saving.value || subjects.value[index].hidden_at || target === null) return;
    const ids = subjects.value.map(s => s.id); [ids[index], ids[target]] = [ids[target], ids[index]];
    return mutate(() => api().reorder(ids), 'Subject order saved.');
};
const hide = (subject: ClassSubject) => mutate(() => api().hide(subject.id), 'Subject hidden. All its work is kept.', () => { removing.value = null; });
const restore = (subject: ClassSubject) => mutate(() => api().restore(subject.id), 'Subject brought back.');
const addCurrentGrades = () => mutate(() => api().addCurrentGrades(), response => response?.data?.meta?.subjects_added === 0
    ? 'This class already has the subjects for its current grades.' : 'Subjects for current grades added.');
onMounted(load);
onBeforeUnmount(() => { alive = false; ++generation; });
</script>

<style scoped>
.class-subject-manager { text-align: start; min-width: 0; overflow-wrap: anywhere; }
.curriculum-checklist { min-width: 0; }
.curriculum-checklist label span { min-width: 0; overflow-wrap: anywhere; }
.class-subject-manager .btn { white-space: normal; min-height: 44px; }
</style>
