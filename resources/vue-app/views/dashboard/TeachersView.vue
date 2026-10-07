<template>
    <div>
        <PageDataContainer
            title="Teachers"
            :buttonProps="{ title: 'Add Teacher', type: 'button', class: 'btn btn-success', disabled: false }"
            @headerButtonClick="openCreateModal"
        >
            <div class="container w-100">
                <!-- Stats Card -->
                <div class="row mb-4">
                    <div class="col-md-4 col-lg-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="bi bi-mortarboard-fill"></i>
                            </div>
                            <div class="stats-content">
                                <div class="stats-label">Total Teachers</div>
                                <div class="stats-value">{{ teachers.length }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- Error State -->
                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    {{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="loadData">Retry</button>
                </div>

                <!-- Empty State -->
                <div v-else-if="teachers.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-mortarboard fs-1 d-block mb-3"></i>
                    <p class="mb-1">No teachers yet</p>
                    <p class="small">Add a teacher to give them a login and assign the {{ classesTerm.toLowerCase() }} they lead.</p>
                </div>

                <!-- Teachers Table -->
                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th class="text-center">Status</th>
                                <th>{{ lastOpenedLabel(masjidStore.term('organization')) }}</th>
                                <th>{{ classesTerm }}</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="teacher in teachers" :key="teacher.id">
                                <td class="fw-semibold">{{ teacher.name }}</td>
                                <td class="text-break">{{ teacher.email }}</td>
                                <td class="text-nowrap">{{ teacher.phone || '—' }}</td>
                                <td class="text-center">
                                    <span v-if="teacher.invited" class="badge bg-warning-subtle text-warning">
                                        <i class="bi bi-envelope me-1"></i>Invited
                                    </span>
                                    <span v-else class="badge bg-success-subtle text-success">
                                        <i class="bi bi-check-circle me-1"></i>Active
                                    </span>
                                </td>
                                <td class="small text-nowrap">
                                    <span v-if="teacher.last_seen_at">{{ formatLastOpened(teacher.last_seen_at) }}</span>
                                    <span v-else class="text-muted" :title="NOT_OPENED_HINT">{{ NOT_OPENED_TEXT }}</span>
                                </td>
                                <td>
                                    <div v-if="teacher.classes.length" class="d-flex flex-wrap gap-1">
                                        <span
                                            v-for="cls in teacher.classes"
                                            :key="cls.id"
                                            class="badge bg-light text-dark border"
                                        >
                                            {{ cls.name }}
                                        </span>
                                    </div>
                                    <span v-else class="text-muted small">No {{ classesTerm.toLowerCase() }} assigned</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Teacher actions">
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary"
                                            title="Edit teacher"
                                            @click="openEditModal(teacher)"
                                        >
                                            <i class="bi bi-pencil"></i>
                                            <span class="d-none d-lg-inline ms-1">Edit</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            :title="teacher.invited ? 'Resend the set-password invite' : 'Send a set-password invite'"
                                            :disabled="resendingId === teacher.id"
                                            @click="resendInvite(teacher)"
                                        >
                                            <span v-if="resendingId === teacher.id" class="spinner-border spinner-border-sm" role="status"></span>
                                            <i v-else class="bi bi-envelope"></i>
                                            <span class="d-none d-lg-inline ms-1">Resend invite</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-outline-danger"
                                            title="Remove teacher from this school"
                                            @click="askRemove(teacher)"
                                        >
                                            <i class="bi bi-trash"></i>
                                            <span class="d-none d-lg-inline ms-1">Remove</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </PageDataContainer>

        <!-- Add Teacher Modal -->
        <Teleport to="body">
            <div v-if="showFormModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="closeFormModal">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-mortarboard me-2"></i>
                                {{ isEditing ? 'Edit Teacher' : 'Add Teacher' }}
                            </h5>
                            <button type="button" class="btn-close" @click="closeFormModal"></button>
                        </div>
                        <form @submit.prevent="submitForm">
                            <div class="modal-body">
                                <p v-if="!isEditing" class="text-muted small">
                                    The teacher is emailed. A new teacher gets a link to set up their login; someone who
                                    already teaches at another Manara school keeps their existing login and password and is
                                    simply added here. Assign at least one {{ classesTerm.toLowerCase() }} they will lead.
                                </p>
                                <p v-else class="text-muted small">
                                    Update this teacher's details and the {{ classesTerm.toLowerCase() }} they lead. Their
                                    email address can't be changed.
                                </p>

                                <!-- Pre-filling the edit form from GET /teachers/{id}. -->
                                <div v-if="loadingTeacher" class="text-center py-4">
                                    <span class="spinner-border spinner-border-sm text-primary me-2" role="status"></span>
                                    <span class="text-muted small">Loading teacher...</span>
                                </div>

                                <!-- A teacher who also belongs to another school: name and phone are
                                     one record shared by every school, so they are read-only here
                                     (TeachersController::update refuses them). -->
                                <div v-if="isEditing && sharedTeacher" class="alert alert-info py-2 small" role="note">
                                    This teacher also belongs to another Manara school, so their name and phone are shared
                                    and can't be changed here. Ask them or Manara support. You can change the
                                    {{ classesTerm.toLowerCase() }} they lead at this school.
                                </div>

                                <!-- A refusal with no field of its own on this form: a network drop, a 500,
                                     or a 422 under a key the form does not render (class_subjects.*). -->
                                <div v-if="formError" class="alert alert-danger py-2" role="alert">
                                    {{ formError }}
                                </div>

                                <div v-show="!loadingTeacher" class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Name <span class="text-danger">*</span></label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            :class="{ 'is-invalid': fieldErrors.name }"
                                            v-model.trim="form.name"
                                            :disabled="isEditing && sharedTeacher"
                                            required
                                        >
                                        <div v-if="fieldErrors.name" class="invalid-feedback">{{ fieldErrors.name }}</div>
                                        <!-- True for every add, so it says nothing about any one address: a person
                                             who already has a Manara login keeps the name on it, and the list shows
                                             that name, not the one typed here. -->
                                        <div v-else-if="!isEditing" class="form-text">
                                            If this person already has a Manara login, the name on that login is the one shown in your list.
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">
                                            Email <span v-if="!isEditing" class="text-danger">*</span>
                                        </label>
                                        <input
                                            type="email"
                                            class="form-control"
                                            :class="{ 'is-invalid': fieldErrors.email }"
                                            v-model.trim="form.email"
                                            :disabled="isEditing"
                                            :required="!isEditing"
                                        >
                                        <div v-if="fieldErrors.email" class="invalid-feedback">{{ fieldErrors.email }}</div>
                                        <div v-else-if="isEditing" class="form-text">Email can't be changed.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Phone <span class="text-muted small">(optional)</span></label>
                                        <input
                                            type="tel"
                                            class="form-control"
                                            :class="{ 'is-invalid': fieldErrors.phone }"
                                            v-model.trim="form.phone"
                                            :disabled="isEditing && sharedTeacher"
                                            placeholder=""
                                        >
                                        <div v-if="fieldErrors.phone" class="invalid-feedback">{{ fieldErrors.phone }}</div>
                                    </div>

                                    <!-- Class multiselect -->
                                    <div class="col-12">
                                        <label class="form-label">
                                            {{ classesTerm }} <span class="text-danger">*</span>
                                        </label>

                                        <div v-if="pickerState === 'loading'" class="text-muted small py-2">
                                            <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                                            Loading {{ classesTerm.toLowerCase() }}...
                                        </div>

                                        <!-- The classes were not read (the request failed, or the reply did not
                                             carry them). Not the same as a school with none, and not said as one. -->
                                        <div v-else-if="pickerState === 'failed'" class="alert alert-danger py-2 mb-0" role="alert">
                                            Could not load the {{ classesTerm.toLowerCase() }}.
                                            <button type="button" class="btn btn-sm btn-outline-danger ms-2" @click="loadClasses">Retry</button>
                                        </div>

                                        <!-- Only when the server answered and its list was empty. -->
                                        <div v-else-if="pickerState === 'empty'" class="alert alert-warning py-2 mb-0">
                                            No {{ classesTerm.toLowerCase() }} exist yet. Create one first, then assign it here.
                                        </div>

                                        <div
                                            v-else
                                            class="class-picker border rounded"
                                            :class="{ 'border-danger': fieldErrors.class_ids }"
                                        >
                                            <div class="form-check" v-for="option in classOptions" :key="option.id">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :id="`class_${option.id}`"
                                                    :value="option.id"
                                                    v-model="form.class_ids"
                                                >
                                                <label class="form-check-label w-100" :for="`class_${option.id}`">
                                                    {{ option.name }}
                                                </label>
                                                <!-- WHICH SUBJECTS in this class (owner, 2026-09-21).
                                                     Only once the class is ticked. None ticked means
                                                     the whole class — what a full-time teacher is —
                                                     so an admin who adds a teacher and skips this
                                                     gets exactly what they always got. -->
                                                <div v-if="form.class_ids.includes(option.id)"
                                                     class="d-flex flex-wrap gap-3 ms-4 mt-1 mb-2 small">
                                                    <div v-for="subj in subjectOptions" :key="subj.value" class="form-check form-check-inline m-0">
                                                        <input class="form-check-input" type="checkbox"
                                                               :id="`subj_${option.id}_${subj.value}`"
                                                               :checked="subjectsFor(option.id).includes(subj.value)"
                                                               @change="toggleSubject(option.id, subj.value)">
                                                        <label class="form-check-label" :for="`subj_${option.id}_${subj.value}`">{{ subj.label }}</label>
                                                    </div>
                                                    <span class="text-muted">
                                                        {{ subjectsFor(option.id).length ? '' : 'All subjects' }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-if="fieldErrors.class_ids" class="text-danger small mt-1">
                                            {{ fieldErrors.class_ids }}
                                        </div>
                                        <div v-else-if="classOptions.length" class="form-text">
                                            Select at least one.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="closeFormModal" :disabled="saving">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-success" :disabled="saving || loadingTeacher || !canSubmit">
                                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    {{ isEditing ? 'Save Changes' : 'Add Teacher' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Remove Teacher confirm modal -->
        <Teleport to="body">
            <div v-if="deleteTarget" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="cancelRemove">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title text-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                Remove Teacher
                            </h5>
                            <button type="button" class="btn-close" @click="cancelRemove" :disabled="deleting"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2">
                                Remove <strong>{{ deleteTarget.name }}</strong> from this school?
                            </p>
                            <p class="text-muted small mb-0">
                                They lose access to this school and its {{ classesTerm.toLowerCase() }}. If this is the only
                                school they teach at, their login is retired. This does not delete any student records.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="cancelRemove" :disabled="deleting">
                                Cancel
                            </button>
                            <button type="button" class="btn btn-danger" @click="confirmRemove" :disabled="deleting">
                                <span v-if="deleting" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { Teacher, TeacherClass, TeacherPayload, TeacherSubject, TeacherUpdatePayload } from '@/core/types/data/masjid-related/Teacher';
import { useTeachersStore } from '@/stores/masjid/teachersStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { apiErrorText } from '@/core/services/ApiErrors';
import { sortTeacherFormErrors, teacherPickerState } from '@/core/helpers/teacherForm';
import type { TeacherPickerState } from '@/core/helpers/teacherForm';
import { lastOpenedLabel, NOT_OPENED_HINT, NOT_OPENED_TEXT, formatLastOpened } from '@/core/helpers/lastOpened';
import Swal from 'sweetalert2';

/**
 * The ADMIN "Teachers" screen — provisioning a scoped staff login and the
 * classes it leads. The teacher's own dashboard is a separate shell
 * (teacherRoutes.ts); this is the door an admin uses to create one.
 *
 * What a "class" is CALLED comes from the terminology pack, so a school reads
 * "Classrooms", a masjid reads "Halaqat" and a community org reads "Teams" off
 * the same screen — nothing here hardcodes any of the three. The class options
 * are EVERY live class of the school, served whole with the teachers list
 * (`teachersStore.classOptions`). They are not read from `groupsStore`: its list
 * is the Classes screen's paginated one, a page of 15.
 */

// Stores
const teachersStore = useTeachersStore();
const masjidStore = useMasjidStore();

// State
const loading = ref(false);
const loadError = ref('');
const classesLoading = ref(false);
const showFormModal = ref(false);
const saving = ref(false);
/** A refusal with no field of its own on the form (network drop, 500, a 422 under a key the form does not render). */
const formError = ref('');
/** field name -> first message, from the 422 validation bag; only the fields the form renders. */
const fieldErrors = ref<Record<string, string>>({});

/**
 * The teacher currently being edited, or `null` in create mode. The one modal
 * serves both flows: `null` -> "Add Teacher" + POST, an id -> "Edit Teacher" +
 * PUT, pre-filled from GET /teachers/{id}.
 */
const editingId = ref<number | null>(null);
/** True while the edit modal is pre-filling from GET /teachers/{id}. */
const loadingTeacher = ref(false);
/**
 * The teacher being edited also belongs to another school (GET /teachers/{id}
 * `shared`). Their name and phone are one record every school shares, so the form
 * makes them read-only and never sends a phone.
 */
const sharedTeacher = ref(false);

/** The teacher queued for removal (drives the confirm modal); `null` when idle. */
const deleteTarget = ref<Teacher | null>(null);
const deleting = ref(false);

/** The row whose invite is being re-sent, so only its button spins. */
const resendingId = ref<number | null>(null);

const emptyForm = (): TeacherPayload => ({ name: '', email: '', phone: '', class_ids: [], class_subjects: {} });

/**
 * The subjects a class assignment can be narrowed to — GroupStaff::SUBJECTS, in
 * the same order and words. A copy, so it is pinned: TeacherSubjectListTest fails
 * if the two drift, which is how the hifz quality list rotted for 18 days.
 */
const subjectOptions = ref<{ value: TeacherSubject; label: string }[]>([
    { value: 'quran', label: "Qur'an" },
    { value: 'arabic', label: 'Arabic' },
    { value: 'islamic_studies', label: 'Islamic Studies' },
]);
const subjectsFor = (classId: number): TeacherSubject[] => form.value.class_subjects?.[classId] ?? [];
const toggleSubject = (classId: number, subject: TeacherSubject) => {
    const map = { ...(form.value.class_subjects ?? {}) };
    const current = new Set(map[classId] ?? []);
    current.has(subject) ? current.delete(subject) : current.add(subject);
    // Empty is "all subjects" on the server, so it is sent as null, never [].
    map[classId] = current.size ? [...current] as TeacherSubject[] : null;
    form.value.class_subjects = map;
};
const form = ref<TeacherPayload>(emptyForm());

// Computed
const teachers = computed<Teacher[]>(() => teachersStore.teachers);

/** What this tenant calls a class — "Classrooms", "Halaqat", "Teams". */
const classesTerm = computed<string>(() => masjidStore.term('groups'));

/** Create vs edit: the one modal renders both, keyed off `editingId`. */
const isEditing = computed<boolean>(() => editingId.value !== null);

/** The assignable class options: every live class of the school, in display order. */
const classOptions = computed<TeacherClass[]>(() => teachersStore.classOptions);

/**
 * What the picker shows: a spinner, the classes, "none exist yet", or "could not
 * load" with Retry. "None exist" needs an answered read (`classOptionsKnown`).
 */
const pickerState = computed<TeacherPickerState>(() =>
    teacherPickerState({
        loading: classesLoading.value,
        known: teachersStore.classOptionsKnown,
        count: classOptions.value.length
    })
);

const canSubmit = computed<boolean>(() =>
    // Email is required to CREATE, but is fixed (not sent) when editing.
    !!form.value.name && (isEditing.value || !!form.value.email) && form.value.class_ids.length > 0
);

// Lifecycle
onBeforeMount(async () => {
    // One read: the list reply carries the picker's classes too.
    classesLoading.value = true;
    try {
        await loadData();
    } finally {
        classesLoading.value = false;
    }
});

// Methods
const loadData = async () => {
    loading.value = true;
    loadError.value = '';
    try {
        await teachersStore.fetchTeachers();
    } catch (error) {
        loadError.value = apiErrorText(error, 'Failed to load teachers.');
    } finally {
        loading.value = false;
    }
};

const loadClasses = async () => {
    classesLoading.value = true;
    try {
        // Every class of the school, not a page of them (see teachersStore).
        await teachersStore.fetchClassOptions();
    } catch (error) {
        // Non-fatal for the list screen. The store has marked the classes as not
        // read, so the picker says "Could not load" with Retry, not "none exist".
        console.error('Failed to load classes for the multiselect: ', error);
    } finally {
        classesLoading.value = false;
    }
};

const openCreateModal = () => {
    editingId.value = null;
    loadingTeacher.value = false;
    sharedTeacher.value = false;
    form.value = emptyForm();
    formError.value = '';
    fieldErrors.value = {};
    showFormModal.value = true;
    // Refresh the options in case a class was added since the page loaded.
    if (classOptions.value.length === 0) loadClasses();
};

/**
 * Open the SAME modal in edit mode, pre-filled from GET /teachers/{id}.
 *
 * The modal opens immediately with a spinner while the read is in flight, so a
 * slow request never leaves the admin staring at a dead button. `class_ids` from
 * the detail read ticks the multiselect; email is shown disabled.
 */
const openEditModal = async (teacher: Teacher) => {
    editingId.value = teacher.id;
    sharedTeacher.value = false;
    formError.value = '';
    fieldErrors.value = {};
    // Seed name/email from the row so the modal is not empty for the split second
    // before the detail read lands.
    form.value = { name: teacher.name, email: teacher.email, phone: '', class_ids: [], class_subjects: {} };
    showFormModal.value = true;
    // Make sure the class options are present to tick.
    if (classOptions.value.length === 0) loadClasses();

    loadingTeacher.value = true;
    try {
        const detail = await teachersStore.fetchTeacher(teacher.id);
        sharedTeacher.value = detail.shared === true;
        form.value = {
            name: detail.name,
            email: detail.email,
            phone: detail.phone ?? '',
            class_ids: Array.isArray(detail.class_ids) ? [...detail.class_ids] : [],
            // Round-tripped as stored, so saving a renamed teacher keeps what they teach.
            class_subjects: { ...((detail as any).class_subjects ?? {}) }
        };

    } catch (error) {
        // Couldn't pre-fill — close and tell the admin rather than show a stale form.
        showFormModal.value = false;
        editingId.value = null;
        Swal.fire({
            icon: 'error',
            title: 'Could not load teacher',
            text: apiErrorText(error, 'Failed to load this teacher for editing.')
        });
    } finally {
        loadingTeacher.value = false;
    }
};

const closeFormModal = () => {
    showFormModal.value = false;
    editingId.value = null;
    loadingTeacher.value = false;
};

/**
 * Show a Laravel 422 validation bag: one message under each field the form
 * renders, and every other message in the banner. Returns whether anything was
 * shown, so a refusal can never end with the spinner stopping and nothing said.
 */
const applyFieldErrors = (error: any): boolean => {
    if (error?.response?.status !== 422) return false;

    const sorted = sortTeacherFormErrors(error?.response?.data?.data);
    fieldErrors.value = sorted.fields;
    formError.value = sorted.banner;

    return Object.keys(sorted.fields).length > 0 || sorted.banner !== '';
};

const submitForm = async () => {
    if (!canSubmit.value || loadingTeacher.value) return;
    saving.value = true;
    formError.value = '';
    fieldErrors.value = {};
    try {
        if (isEditing.value && editingId.value !== null) {
            // Edit: email is fixed and not sent; class_ids is the full new set.
            const payload: TeacherUpdatePayload = {
                name: form.value.name,
                // A shared teacher's phone is shown (read-only) but is one record
                // every school holds; the server refuses any value, so none is sent.
                phone: sharedTeacher.value ? '' : form.value.phone,
                class_ids: form.value.class_ids,
                class_subjects: form.value.class_subjects
            };
            const updated = await teachersStore.updateTeacher(editingId.value, payload);
            closeFormModal();
            await loadData();
            Swal.fire({
                icon: 'success',
                title: 'Teacher updated',
                text: `${updated?.name || payload.name} has been saved.`,
                timer: 3000,
                showConfirmButton: false
            });
        } else {
            const created = await teachersStore.createTeacher(form.value);
            closeFormModal();
            await loadData();
            Swal.fire({
                icon: 'success',
                title: 'Teacher added',
                // Deliberately the same words whether this is a new login or an
                // existing teacher joining from another school: the server's reply
                // is built to be indistinguishable, and the screen must not
                // undo that by guessing.
                text: created ? `${created.name} has been added to this school and emailed at ${created.email}.` : undefined,
                timer: 3000,
                showConfirmButton: false
            });
        }
    } catch (error: any) {
        // A 422 renders inline where the form has the field and in the banner where
        // it does not; anything else shows as a form-wide banner.
        if (!applyFieldErrors(error)) {
            formError.value = apiErrorText(error, isEditing.value ? 'Failed to save the teacher.' : 'Failed to add the teacher.');
        }
    } finally {
        saving.value = false;
    }
};

// ------------------------------------------------------------------- remove

/** Queue a teacher for removal — opens the confirm modal (no window.confirm). */
const askRemove = (teacher: Teacher) => {
    deleteTarget.value = teacher;
};

const cancelRemove = () => {
    if (deleting.value) return;
    deleteTarget.value = null;
};

const confirmRemove = async () => {
    const target = deleteTarget.value;
    if (!target) return;
    deleting.value = true;
    try {
        await teachersStore.deleteTeacher(target.id);
        deleteTarget.value = null;
        await loadData();
        Swal.fire({
            icon: 'success',
            title: 'Teacher removed',
            text: `${target.name} has been removed from this school.`,
            timer: 3000,
            showConfirmButton: false
        });
    } catch (error) {
        deleteTarget.value = null;
        Swal.fire({
            icon: 'error',
            title: 'Could not remove teacher',
            text: apiErrorText(error, 'Failed to remove the teacher.')
        });
    } finally {
        deleting.value = false;
    }
};

// -------------------------------------------------------------- resend invite

/** Re-send (or send) the set-password invite; surfaces the server message. */
const resendInvite = async (teacher: Teacher) => {
    if (resendingId.value !== null) return;
    resendingId.value = teacher.id;
    try {
        const message = await teachersStore.resendInvite(teacher.id);
        Swal.fire({
            icon: 'success',
            title: 'Invitation sent',
            text: message || `An invitation has been sent to ${teacher.name}.`,
            timer: 3000,
            showConfirmButton: false
        });
    } catch (error) {
        // A 422 here means the teacher has no email on file.
        Swal.fire({
            icon: 'error',
            title: 'Could not send invitation',
            text: apiErrorText(error, 'Failed to send the invitation.')
        });
    } finally {
        resendingId.value = null;
    }
};

// Lock body scroll while either modal (form or remove-confirm) is open.
watch(
    () => showFormModal.value || deleteTarget.value !== null,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
    }
);
</script>

<style scoped>
/* Stats Card — matches the other management screens (GroupsView). */
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 1.5rem;
    color: white;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}

.stats-icon {
    width: 60px;
    height: 60px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
}

.stats-content {
    flex: 1;
}

.stats-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.stats-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
}

/* Scrollable checkbox list for the class multiselect. */
.class-picker {
    max-height: 12rem;
    overflow-y: auto;
    padding: 0.5rem 0.75rem;
}

.class-picker .form-check {
    padding-top: 0.15rem;
    padding-bottom: 0.15rem;
}

/* Modal */
.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
