<!--
    A student's DATE OF BIRTH, for the office: read it, add it, change it, remove it.

    SELF-CONTAINED on purpose. It talks to its own three endpoints and keeps no
    state in any store, so the Student details panel mounts it with four ids and
    one function:

        <StudentBirthDateForm :masjid-id="…" :group-id="…" :membership-id="…" :contact-id="…"
                              :after-change="patchTheRow" />

    `afterChange` is A FUNCTION, NOT AN EVENT, on purpose. The panel can be
    closed (or moved to another student) while a save is still on its way, which
    unmounts this form, and Vue drops an emit from a component that is gone: the
    server had saved the date and the roster row went on showing a dash until
    the page was reloaded. A function handed in as a prop still runs. It is told
    which roster row the answer is about, and the parent patches that row's age
    in place; the roster is not read again.

    The roster list carries only the whole-number age. The date itself is read
    HERE, one student at a time, from `GET …/members/{id}/birth-date`, and this
    is the only screen that shows it.

    WHO SEES THIS SECTION is decided by the server's answer, not by a permission
    list (the admin SPA holds none): the GET takes `manage contacts`, and when it
    answers 403, or says this row is not a student in a class (422), or the row
    is gone (404), nothing is drawn at all. Anybody who can read the roster
    still sees the age beside the name.

    REMOVE names the CONTACT (`DELETE …/contacts/{id}/birth-date`), not the
    roster row, and the server never refuses it: the date is on the contact and
    outlives the row.
-->
<template>
    <div v-if="state !== 'hidden'" class="student-birth-date">
        <div class="fw-semibold small mb-1">Date of birth</div>

        <div v-if="state === 'loading'" class="text-muted small">Loading…</div>

        <div v-else-if="state === 'failed'" class="small" role="alert">
            <span class="text-danger">{{ loadError }}</span>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-2" @click="load">Try again</button>
        </div>

        <template v-else>
            <!-- What is on file, and what can be done about it. -->
            <div v-if="!editing" class="d-flex flex-wrap align-items-center gap-2">
                <span v-if="date" class="small">{{ words }}</span>
                <span v-else-if="unreadable" class="small text-danger">
                    The stored date cannot be read. Enter it again.
                </span>
                <span v-else class="small text-muted">Not on file</span>

                <template v-if="!confirmingRemove">
                    <button ref="editButton" type="button" class="btn btn-sm btn-outline-primary" :disabled="busy" @click="startEditing">
                        {{ date ? 'Change' : 'Add date of birth' }}
                    </button>
                    <button v-if="date || unreadable" type="button" class="btn btn-sm btn-outline-danger"
                            :disabled="busy" @click="askToRemove">
                        Remove
                    </button>
                </template>
            </div>

            <!-- Removing asks once, in the page, so a slip does not cost the office a date it typed. -->
            <div v-if="confirmingRemove" class="d-flex flex-wrap align-items-center gap-2 mt-2">
                <span class="small">Remove the date of birth? The class lists will stop showing an age.</span>
                <button type="button" class="btn btn-sm btn-danger" :disabled="busy" @click="remove">
                    {{ removing ? 'Removing…' : 'Yes, remove it' }}
                </button>
                <button ref="keepButton" type="button" class="btn btn-sm btn-light" :disabled="busy" @click="keepIt">
                    Keep it
                </button>
            </div>

            <form v-if="editing" class="d-flex flex-wrap align-items-end gap-2" @submit.prevent="save">
                <div>
                    <label class="visually-hidden" :for="inputId">Date of birth</label>
                    <input :id="inputId" ref="dateInput" v-model="draft" type="date" class="form-control form-control-sm"
                           style="width: 11rem" :min="BIRTH_DATE_MIN" :max="max" :disabled="saving" required />
                </div>
                <button type="submit" class="btn btn-sm btn-primary" :disabled="saving">
                    {{ saving ? 'Saving…' : 'Save' }}
                </button>
                <button type="button" class="btn btn-sm btn-light" :disabled="saving" @click="cancelEditing">
                    Cancel
                </button>
            </form>

            <div v-if="error" class="text-danger small mt-1" role="alert">{{ error }}</div>
            <div v-else-if="notice" class="text-success small mt-1" role="status">{{ notice }}</div>

            <div class="form-text">
                Used only to show the student's age on class lists. Only the office sees the date:
                here, and in the school records export.
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
import { apiErrorText } from '@/core/services/ApiErrors';
import { BIRTH_DATE_MIN, birthDateMax, birthDateProblem, birthDateWords } from '@/core/helpers/studentAge';

/** What `afterChange` is told: the roster row the answer is about, and its new age. */
interface BirthDateChange {
    membershipId: number | string;
    age: number | null;
    /** The age is the one the family gave at registration (no date of birth on file). */
    given: boolean;
    held: boolean;
}

const props = defineProps<{
    masjidId: number | string;
    groupId: number | string;
    /** The student's roster row in this class: how the office names the child to read and set. */
    membershipId: number | string;
    /** The student's contact: what Remove names, because the date is kept on the contact. */
    contactId: number | string;
    /**
     * A date was saved or removed, so the roster's `age` for this student is
     * out of date. Called with the roster row the answer is about and the new
     * number (or null), and the parent patches that row. A function and not an
     * event: it must still run when this form was unmounted while the request
     * was on its way (see the note at the top of this file).
     */
    afterChange?: (change: BirthDateChange) => void;
}>();

type State = 'loading' | 'ready' | 'failed' | 'hidden';

const state = ref<State>('loading');
const loadError = ref('');
const date = ref<string | null>(null);
const unreadable = ref(false);
const schoolToday = ref<string | null>(null);

const editing = ref(false);
const draft = ref('');
const saving = ref(false);
const removing = ref(false);
const confirmingRemove = ref(false);
const error = ref('');
const notice = ref('');

const busy = computed(() => saving.value || removing.value);
const words = computed(() => birthDateWords(date.value));
const max = computed(() => birthDateMax(schoolToday.value));
const inputId = computed(() => `student-birth-date-${props.membershipId}`);

const rowUrl = () =>
    `/api/admin/masjids/${props.masjidId}/groups/${props.groupId}/members/${props.membershipId}/birth-date` as any;
const contactUrl = () => `/api/admin/masjids/${props.masjidId}/contacts/${props.contactId}/birth-date` as any;

/** The server's `{date_of_birth, age, unreadable, school_today}`, onto the screen. */
const show = (data: any) => {
    date.value = typeof data?.date_of_birth === 'string' ? data.date_of_birth : null;
    unreadable.value = data?.unreadable === true;
    if (typeof data?.school_today === 'string') schoolToday.value = data.school_today;
};

// Each load is numbered: when the panel moves to another student while an
// answer is still on its way, the late answer is the previous child's date and
// must not be drawn under this child's name.
let asked = 0;

const load = async () => {
    const mine = ++asked;

    state.value = 'loading';
    editing.value = false;
    confirmingRemove.value = false;
    error.value = '';
    notice.value = '';
    date.value = null;
    unreadable.value = false;

    try {
        const res = await ApiService.get(rowUrl());
        if (mine !== asked) return;

        show(res.data?.data);
        state.value = 'ready';
    } catch (e: any) {
        if (mine !== asked) return;

        const status = e?.response?.status;

        // Not theirs to see (403), not a student in a class (422), or no such
        // row (404): the section is simply not drawn.
        if (status === 403 || status === 422 || status === 404) {
            state.value = 'hidden';
            return;
        }

        loadError.value = apiErrorText(e, 'The date of birth could not be loaded.');
        state.value = 'failed';
    }
};

// WHERE THE KEYBOARD IS AFTER EACH STEP. Every step here takes away the button
// that was just pressed ("Add date of birth" gives way to the field, "Remove" to
// its question), and a browser then drops focus onto the page: the next Tab
// would land on the panel's Close button instead of the thing just opened. So
// each step says where focus goes next.
const editButton = ref<HTMLButtonElement | null>(null);
const keepButton = ref<HTMLButtonElement | null>(null);
const dateInput = ref<HTMLInputElement | null>(null);

const focusNext = async (target: () => HTMLElement | null) => {
    await nextTick();
    target()?.focus?.();
};

const startEditing = () => {
    draft.value = date.value ?? '';
    error.value = '';
    notice.value = '';
    editing.value = true;
    focusNext(() => dateInput.value);
};

const cancelEditing = () => {
    editing.value = false;
    error.value = '';
    focusNext(() => editButton.value);
};

const askToRemove = () => {
    confirmingRemove.value = true;
    // The safe answer, so Enter straight after a slip onto Remove keeps the date.
    focusNext(() => keepButton.value);
};

const keepIt = () => {
    confirmingRemove.value = false;
    focusNext(() => editButton.value);
};

const save = async () => {
    // A second tap while the first is still on its way sends nothing.
    if (busy.value) return;

    const problem = birthDateProblem(draft.value, schoolToday.value);
    if (problem) {
        error.value = problem;
        return;
    }

    const mine = asked;
    // Read now: by the time the answer arrives the panel may show another
    // student, or be shut.
    const tell = props.afterChange;
    const membershipId = props.membershipId;

    saving.value = true;
    error.value = '';
    notice.value = '';

    try {
        const res = await ApiService.put(rowUrl(), { date_of_birth: draft.value });

        // THE ROSTER IS TOLD FIRST, whatever became of this form meanwhile:
        // the server saved the date for that row.
        tell?.({ membershipId, age: res.data?.data?.age ?? null, given: res.data?.data?.age_given === true, held: true });
        if (mine !== asked) return;

        show(res.data?.data);
        editing.value = false;
        notice.value = res.data?.message ?? 'Date of birth saved.';
        focusNext(() => editButton.value);
    } catch (e) {
        if (mine !== asked) return;

        // The form stays open with what was typed, and says why.
        error.value = apiErrorText(e, 'The date of birth could not be saved.');
    } finally {
        saving.value = false;
    }
};

/**
 * After a date was removed: ask, by roster row, what the roster shows for this
 * student now, and tell the roster when it is the age their family gave. Quiet:
 * a failure here leaves a dash on the row until the roster is next read.
 */
const showTheFamilysAgeAgain = async (url: string, membershipId: number | string, tell?: (change: BirthDateChange) => void) => {
    try {
        const data = (await ApiService.get(url as any)).data?.data;

        if (data?.age_given === true && typeof data?.age === 'number') {
            tell?.({ membershipId, age: data.age, given: true, held: false });
        }
    } catch {
        // Nothing to show.
    }
};

const remove = async () => {
    if (busy.value) return;

    const mine = asked;
    const tell = props.afterChange;
    const membershipId = props.membershipId;
    // The row's own address, taken now: the panel may be on another student by the time the clear answers.
    const row = rowUrl();

    removing.value = true;
    error.value = '';
    notice.value = '';

    try {
        const res = await ApiService.delete(contactUrl());

        // THE ROSTER IS TOLD FIRST: the date is gone from that row.
        tell?.({ membershipId, age: null, given: false, held: false });

        // The clear answers the same for every contact, so it cannot say what
        // this row shows now. That is read by ROSTER ROW: when the family gave
        // an age at registration, the row shows that again instead of a dash.
        void showTheFamilysAgeAgain(row, membershipId, tell);
        if (mine !== asked) return;

        date.value = null;
        unreadable.value = false;
        confirmingRemove.value = false;
        notice.value = res.data?.message ?? 'Date of birth removed.';
        focusNext(() => editButton.value);
    } catch (e) {
        if (mine !== asked) return;

        error.value = apiErrorText(e, 'The date of birth could not be removed.');
    } finally {
        removing.value = false;
    }
};

onMounted(load);
watch(() => [props.masjidId, props.groupId, props.membershipId, props.contactId], load);
</script>

<style scoped>
/* The panel this sits in is full screen on a phone, and its other controls are
   44px targets. These are the small button class for a mouse, and full height
   for a finger. */
@media (max-width: 575.98px), (pointer: coarse) {
    .student-birth-date .btn,
    .student-birth-date .form-control {
        min-height: 44px;
    }

    .student-birth-date .btn {
        display: inline-flex;
        align-items: center;
    }
}
</style>
