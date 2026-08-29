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
                                <th class="text-center">Status</th>
                                <th>{{ classesTerm }}</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="teacher in teachers" :key="teacher.id">
                                <td class="fw-semibold">{{ teacher.name }}</td>
                                <td class="text-break">{{ teacher.email }}</td>
                                <td class="text-center">
                                    <span v-if="teacher.invited" class="badge bg-warning-subtle text-warning">
                                        <i class="bi bi-envelope me-1"></i>Invited
                                    </span>
                                    <span v-else class="badge bg-success-subtle text-success">
                                        <i class="bi bi-check-circle me-1"></i>Active
                                    </span>
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
                                    The teacher receives an emailed invitation to set up their login. Assign at least one
                                    {{ classesTerm.toLowerCase() }} they will lead.
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

                                <!-- A refusal that is not tied to one field (e.g. email already a teacher). -->
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
                                            required
                                        >
                                        <div v-if="fieldErrors.name" class="invalid-feedback">{{ fieldErrors.name }}</div>
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
                                        >
                                        <div v-if="fieldErrors.phone" class="invalid-feedback">{{ fieldErrors.phone }}</div>
                                    </div>

                                    <!-- Class multiselect -->
                                    <div class="col-12">
                                        <label class="form-label">
                                            {{ classesTerm }} <span class="text-danger">*</span>
                                        </label>

                                        <div v-if="classesLoading" class="text-muted small py-2">
                                            <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                                            Loading {{ classesTerm.toLowerCase() }}...
                                        </div>

                                        <div v-else-if="classOptions.length === 0" class="alert alert-warning py-2 mb-0">
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
import { Teacher, TeacherClass, TeacherPayload, TeacherUpdatePayload } from '@/core/types/data/masjid-related/Teacher';
import { Group } from '@/core/types/data/masjid-related/Group';
import { useTeachersStore } from '@/stores/masjid/teachersStore';
import { useGroupsStore } from '@/stores/masjid/groupsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { apiErrorText } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * The ADMIN "Teachers" screen — provisioning a scoped staff login and the
 * classes it leads. The teacher's own dashboard is a separate shell
 * (teacherRoutes.ts); this is the door an admin uses to create one.
 *
 * What a "class" is CALLED comes from the terminology pack, so a school reads
 * "Classrooms", a masjid reads "Halaqat" and a community org reads "Teams" off
 * the same screen — nothing here hardcodes any of the three. The class options
 * are loaded from the existing Groups/Classrooms endpoint via `groupsStore`, so
 * there is a single source of truth for the tenant's classes.
 */

// Stores
const teachersStore = useTeachersStore();
const groupsStore = useGroupsStore();
const masjidStore = useMasjidStore();

// State
const loading = ref(false);
const loadError = ref('');
const classesLoading = ref(false);
const showFormModal = ref(false);
const saving = ref(false);
/** A refusal not tied to one field (network drop, "already a teacher", 500). */
const formError = ref('');
/** field name -> first message, from the 422 validation bag. */
const fieldErrors = ref<Record<string, string>>({});

/**
 * The teacher currently being edited, or `null` in create mode. The one modal
 * serves both flows: `null` -> "Add Teacher" + POST, an id -> "Edit Teacher" +
 * PUT, pre-filled from GET /teachers/{id}.
 */
const editingId = ref<number | null>(null);
/** True while the edit modal is pre-filling from GET /teachers/{id}. */
const loadingTeacher = ref(false);

/** The teacher queued for removal (drives the confirm modal); `null` when idle. */
const deleteTarget = ref<Teacher | null>(null);
const deleting = ref(false);

/** The row whose invite is being re-sent, so only its button spins. */
const resendingId = ref<number | null>(null);

const emptyForm = (): TeacherPayload => ({ name: '', email: '', phone: '', class_ids: [] });
const form = ref<TeacherPayload>(emptyForm());

// Computed
const teachers = computed<Teacher[]>(() => teachersStore.teachers);

/** What this tenant calls a class — "Classrooms", "Halaqat", "Teams". */
const classesTerm = computed<string>(() => masjidStore.term('groups'));

/** Create vs edit: the one modal renders both, keyed off `editingId`. */
const isEditing = computed<boolean>(() => editingId.value !== null);

/** The assignable class options, narrowed to what the multiselect needs. */
const classOptions = computed<TeacherClass[]>(() =>
    ((groupsStore.groupsPaginated?.data as Group[]) || []).map((g) => ({ id: g.id, name: g.name }))
);

const canSubmit = computed<boolean>(() =>
    // Email is required to CREATE, but is fixed (not sent) when editing.
    !!form.value.name && (isEditing.value || !!form.value.email) && form.value.class_ids.length > 0
);

// Lifecycle
onBeforeMount(async () => {
    await Promise.all([loadData(), loadClasses()]);
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
        // Reuse the existing Classrooms/Groups endpoint — one source of truth.
        await groupsStore.fetchGroups();
    } catch (error) {
        // Non-fatal for the list screen; the modal surfaces the empty state.
        console.error('Failed to load classes for the multiselect: ', error);
    } finally {
        classesLoading.value = false;
    }
};

const openCreateModal = () => {
    editingId.value = null;
    loadingTeacher.value = false;
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
    formError.value = '';
    fieldErrors.value = {};
    // Seed name/email from the row so the modal is not empty for the split second
    // before the detail read lands.
    form.value = { name: teacher.name, email: teacher.email, phone: '', class_ids: [] };
    showFormModal.value = true;
    // Make sure the class options are present to tick.
    if (classOptions.value.length === 0) loadClasses();

    loadingTeacher.value = true;
    try {
        const detail = await teachersStore.fetchTeacher(teacher.id);
        form.value = {
            name: detail.name,
            email: detail.email,
            phone: detail.phone ?? '',
            class_ids: Array.isArray(detail.class_ids) ? [...detail.class_ids] : []
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

/** Map a Laravel 422 validation bag to one message per field. */
const applyFieldErrors = (error: any): boolean => {
    const bag = error?.response?.data?.data;
    if (error?.response?.status === 422 && bag && typeof bag === 'object' && !Array.isArray(bag)) {
        const mapped: Record<string, string> = {};
        for (const [field, messages] of Object.entries(bag)) {
            const first = Array.isArray(messages) ? messages[0] : messages;
            // `class_ids.0`, `class_ids.*` etc. all belong to the one control.
            const key = field.split('.')[0];
            if (!mapped[key]) mapped[key] = String(first);
        }
        fieldErrors.value = mapped;
        return Object.keys(mapped).length > 0;
    }
    return false;
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
                phone: form.value.phone,
                class_ids: form.value.class_ids
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
                // The server's own message mentions the emailed invite.
                text: created ? `${created.name} has been invited by email to set up their login.` : undefined,
                timer: 3000,
                showConfirmButton: false
            });
        }
    } catch (error: any) {
        // Field-level 422s render inline; anything else shows as a form-wide banner.
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
