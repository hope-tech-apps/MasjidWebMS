<template>
    <!--
        THE SCHOOL'S OWN LIST OF SUBJECTS (T-001.3).

        What a teacher can file work under, and what the lesson plan's subject
        picker adds to the pacing guide's. Kept by the office here; a teacher
        never edits it. A disclosure, not a card, for the reason the records
        export beside it is: it is set up once and must not compete with the
        class list for attention.
    -->
    <details class="subjects-card" @toggle="onToggle">
        <summary><i class="bi bi-journal-bookmark me-2"></i>Subjects</summary>

        <p class="text-muted small mt-2 mb-2">
            The subjects this school teaches. Teachers choose from this list when they set work, and the
            lesson-plan subject list adds them to the pacing guide's. Work keeps the name it was set under,
            so renaming or removing a subject here never changes a mark a family has seen.
        </p>

        <div v-if="loading" class="text-muted small">Loading…</div>
        <div v-else-if="loadError" class="alert alert-warning py-2 small">
            {{ loadError }} <button class="btn btn-sm btn-link p-0 ms-2" @click="load">Retry</button>
        </div>

        <template v-else-if="loaded">
            <p v-if="!subjects.length" class="text-muted small mb-2">
                No subjects yet, so teachers can type any subject they like on their work.
                <span v-if="guideSubjects.length">
                    Your pacing guide names {{ guideSubjects.join(', ') }}; add the ones you teach.
                </span>
            </p>

            <ul v-else class="list-group mb-3">
                <li v-for="s in subjects" :key="s.id" class="list-group-item">
                    <div v-if="editingId !== s.id" class="d-flex align-items-center gap-3 flex-wrap">
                        <div class="flex-grow-1">
                            <div class="fw-semibold small" dir="auto">{{ s.name }}</div>
                            <div class="text-muted small">{{ gradesSummary(s.grade_labels) }}</div>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" :aria-label="`Edit ${s.name}`" @click="startEdit(s)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-outline-danger" :aria-label="`Remove ${s.name}`" :disabled="saving" @click="askRemove(s)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <div v-if="removingId === s.id" class="w-100 alert alert-warning small mb-0">
                            Remove {{ s.name }}? {{ workNote(s.work_count) }}
                            <div class="mt-2 d-flex gap-2">
                                <button class="btn btn-sm btn-danger" :disabled="saving" @click="remove(s)">Remove it</button>
                                <button class="btn btn-sm btn-light" @click="removingId = null">Keep it</button>
                            </div>
                        </div>
                    </div>

                    <div v-else>
                        <SubjectFields :form="form" :idp="`edit-${s.id}`" />
                        <p v-if="workNote(s.work_count)" class="text-muted small mt-2 mb-0">{{ workNote(s.work_count) }}</p>
                        <div class="mt-2 d-flex gap-2">
                            <button class="btn btn-sm btn-success" :disabled="saving || !form.name.trim()" @click="save(s.id)">
                                {{ saving ? 'Saving…' : 'Save' }}
                            </button>
                            <button class="btn btn-sm btn-light" :disabled="saving" @click="cancel">Cancel</button>
                        </div>
                    </div>
                </li>
            </ul>

            <div v-if="editingId === null" class="border rounded p-2">
                <div class="small fw-semibold mb-2">Add a subject</div>
                <SubjectFields :form="form" idp="new" />
                <div class="mt-2">
                    <button class="btn btn-sm btn-success" :disabled="saving || !form.name.trim()" @click="save(null)">
                        {{ saving ? 'Adding…' : 'Add subject' }}
                    </button>
                </div>
            </div>

            <p v-if="saveError" class="text-danger small mt-2 mb-0" role="alert">{{ saveError }}</p>
        </template>
    </details>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, ref } from 'vue';
import ApiService from '@/core/services/ApiService';
import { useAuthStore } from '@/stores/authStore';
import {
    GRADE_LEVELS, clashWith, firstError, gradesSummary, subjectFormFrom, subjectPayload, workNote,
    type SubjectForm, type SubjectRow,
} from '@/core/helpers/schoolSubjects';

/**
 * The name, grades and order of one subject: shared by "add" and "edit" so the two
 * cannot drift. A grade list left empty means every grade.
 */
const SubjectFields = defineComponent({
    props: { form: { type: Object as () => SubjectForm, required: true }, idp: { type: String, required: true } },
    setup(props) {
        return () => h('div', { class: 'row g-2' }, [
            h('div', { class: 'col-12 col-md-5' }, [
                h('label', { class: 'form-label small text-muted mb-1', for: `${props.idp}-name` }, 'Name'),
                h('input', {
                    id: `${props.idp}-name`, type: 'text', maxlength: 64, class: 'form-control form-control-sm',
                    placeholder: 'e.g. Arabic Language', value: props.form.name,
                    onInput: (e: Event) => { props.form.name = (e.target as HTMLInputElement).value; },
                }),
            ]),
            h('div', { class: 'col-6 col-md-2' }, [
                h('label', { class: 'form-label small text-muted mb-1', for: `${props.idp}-pos` }, 'Order'),
                h('input', {
                    id: `${props.idp}-pos`, type: 'number', min: 0, max: 1000, class: 'form-control form-control-sm',
                    value: props.form.position,
                    onInput: (e: Event) => { props.form.position = (e.target as HTMLInputElement).value; },
                }),
            ]),
            h('div', { class: 'col-12' }, [
                h('div', { class: 'form-label small text-muted mb-1' }, 'Grades (none ticked means every grade)'),
                h('div', { class: 'd-flex flex-wrap gap-2' }, GRADE_LEVELS.map((level) =>
                    h('label', { class: 'form-check form-check-inline small mb-0', key: level }, [
                        h('input', {
                            type: 'checkbox', class: 'form-check-input', checked: props.form.grades.includes(level),
                            onChange: (e: Event) => {
                                const on = (e.target as HTMLInputElement).checked;
                                props.form.grades = on
                                    ? [...props.form.grades, level]
                                    : props.form.grades.filter((g) => g !== level);
                            },
                        }),
                        h('span', { class: 'form-check-label ms-1' }, level),
                    ])
                )),
            ]),
        ]);
    },
});

const authStore = useAuthStore();

const subjects = ref<SubjectRow[]>([]);
const guideSubjects = ref<string[]>([]);
const loading = ref(false);
const loaded = ref(false);
const loadError = ref('');
const saving = ref(false);
const saveError = ref('');
const editingId = ref<number | null>(null);
const removingId = ref<number | null>(null);
const form = ref<SubjectForm>(subjectFormFrom(null));

// Read from the STORE, not localStorage: a second tab switching organisation
// rewrites that key under this one (see GroupsView's export).
const base = computed(() => `/api/admin/masjids/${authStore.dashboardMasjidId}/school-subjects`);

const load = async () => {
    if (!authStore.dashboardMasjidId) return;
    loading.value = true;
    loadError.value = '';
    try {
        const res = await ApiService.get(base.value as any);
        subjects.value = res.data?.data ?? [];
        guideSubjects.value = res.data?.meta?.guide_subjects ?? [];
        loaded.value = true;
    } catch (e: any) {
        loadError.value = firstError(e, 'The subjects could not be loaded.');
    } finally {
        loading.value = false;
    }
};

// Loaded when the disclosure is first opened, not on every visit to the page.
const onToggle = (e: Event) => {
    if ((e.target as HTMLDetailsElement).open && !loaded.value && !loading.value) load();
};

const startEdit = (s: SubjectRow) => {
    saveError.value = '';
    removingId.value = null;
    editingId.value = s.id;
    form.value = subjectFormFrom(s);
};

const cancel = () => {
    editingId.value = null;
    saveError.value = '';
    form.value = subjectFormFrom(null);
};

/** JSON, not the service's default form encoding: an empty grade list must arrive as an empty list. */
const sendJson = (method: 'post' | 'put', url: string, body: unknown) =>
    (ApiService.VueApp.axios as any)[method](url, body, { headers: { 'Content-Type': 'application/json' } });

const save = async (id: number | null) => {
    saveError.value = '';
    const clash = clashWith(form.value.name, subjects.value, id);
    if (clash) { saveError.value = 'That subject is already on the list.'; return; }

    saving.value = true;
    try {
        const body = subjectPayload(form.value);
        if (id === null) await sendJson('post', base.value, body);
        else await sendJson('put', `${base.value}/${id}`, body);
        editingId.value = null;
        form.value = subjectFormFrom(null);
        await load();
    } catch (e: any) {
        saveError.value = firstError(e, 'That subject could not be saved.');
    } finally {
        saving.value = false;
    }
};

const askRemove = (s: SubjectRow) => { saveError.value = ''; removingId.value = removingId.value === s.id ? null : s.id; };

const remove = async (s: SubjectRow) => {
    saving.value = true;
    saveError.value = '';
    try {
        await ApiService.delete(`${base.value}/${s.id}` as any);
        removingId.value = null;
        await load();
    } catch (e: any) {
        saveError.value = firstError(e, 'That subject could not be removed.');
    } finally {
        saving.value = false;
    }
};
</script>

<style scoped>
/* A disclosure, like the records export beside it. */
.subjects-card {
    background: #fff;
    border: 1px solid #e6e9ec;
    border-radius: 10px;
    padding: 12px 16px;
}
.subjects-card > summary {
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    list-style: none;
}
.subjects-card > summary::-webkit-details-marker { display: none; }
</style>
