<template>
    <Teleport to="body">
        <div
            v-if="student"
            ref="root"
            class="modal d-block student-details-panel"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            tabindex="-1"
            @click.self="close"
            @keydown="onKeydown"
        >
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 :id="titleId" class="modal-title">
                            <i class="bi bi-person-vcard me-2" aria-hidden="true"></i>Student details
                        </h5>
                        <button ref="closeButton" type="button" class="btn-close" aria-label="Close student details" @click="close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <PersonAvatar
                                :avatar="(student.contact as any)?.avatar"
                                :first-name="student.contact?.first_name"
                                :last-name="student.contact?.last_name"
                                :size="56" />
                            <div style="min-width: 0">
                                <div class="fs-5 fw-semibold text-break">{{ personName(student.contact) }}</div>
                                <div>
                                    <span v-if="!isConfirmedRow(student)" class="badge bg-warning-subtle text-warning me-1">
                                        Unconfirmed
                                    </span>
                                    <!--
                                        MOUNT POINT (S1, move a student): the moved badges.
                                        "Moved to {class} {date}" REPLACES "Left {date}" while the
                                        student is current in that class, by the roster row's own
                                        rule. A filled slot draws instead of the plain badge below.
                                    -->
                                    <slot name="moved-badges" :student="student">
                                        <span v-if="student.left_on" class="badge bg-secondary-subtle text-secondary">
                                            Left {{ storedDayLabel(student.left_on) }}
                                        </span>
                                    </slot>
                                </div>
                                <!-- The student's own address, when the record holds one. Text: the
                                     people to call are the confirmed guardians below. -->
                                <div v-if="ownAddress" class="small text-muted text-break">{{ ownAddress }}</div>
                            </div>
                        </div>

                        <h6 class="text-muted mb-2">In this class</h6>
                        <div class="row g-2 align-items-center mb-1">
                            <label class="col-4 col-form-label col-form-label-sm" :for="gradeId">Grade</label>
                            <div class="col-8">
                                <!-- The same field as the roster row, saved the same way, on change. -->
                                <input
                                    :id="gradeId"
                                    type="text"
                                    class="form-control form-control-sm"
                                    :value="student.grade_label ?? ''"
                                    :disabled="savingGrade"
                                    placeholder="—"
                                    maxlength="32"
                                    @change="emit('save-grade', student, ($event.target as HTMLInputElement).value)"
                                />
                            </div>
                        </div>
                        <div class="small text-muted mb-1">Joined {{ joinedLabel }}</div>
                        <!-- MOUNT POINT (S1, move a student): the line "Moved from {class} {date}". -->
                        <slot name="moved-from" :student="student"></slot>

                        <!--
                            MOUNT POINT (S3, ages): "Age {n}", for anyone who can read the roster.
                        -->
                        <slot name="age" :student="student"></slot>
                        <!--
                            MOUNT POINT (S3, ages): the date-of-birth form (StudentBirthDateForm.vue),
                            drawn only for an admin who may edit the roster.
                        -->
                        <slot name="birth-date" :student="student"></slot>

                        <!--
                            GUARDIANS, IN THREE LISTS, AND ONLY THE FIRST CAN BE TAPPED.
                            A claim a registration form wrote is somebody who was not signed in
                            saying they are this child's parent. It is shown, and it is never the
                            number the office calls. The lists come from core/helpers/studentDetails.
                        -->
                        <template v-if="details.studentHasLeft">
                            <p class="mt-4 mb-2">
                                This student has left this class. For their guardians, open the class they are in now.
                            </p>
                            <ul v-if="details.former.length" class="list-unstyled small mb-0">
                                <li v-for="guardian in details.former" :key="guardian.id" class="mb-1">
                                    {{ guardian.name }}<span v-if="!guardian.confirmed" class="text-muted"> (not confirmed)</span>
                                </li>
                            </ul>
                        </template>

                        <template v-else>
                            <h6 class="text-muted mt-4 mb-2">Guardians</h6>
                            <p v-if="details.confirmed.length === 0" class="small mb-0">
                                No confirmed guardian is on this class's roster for this student.
                            </p>
                            <ul v-else class="list-unstyled mb-0">
                                <li v-for="guardian in details.confirmed" :key="guardian.id" class="guardian-line">
                                    <div class="fw-semibold text-break">{{ guardian.name }}</div>
                                    <div>
                                        <a v-if="guardian.phoneHref" :href="guardian.phoneHref" class="guardian-link">
                                            <i class="bi bi-telephone me-2" aria-hidden="true"></i>{{ guardian.phone }}
                                        </a>
                                        <span v-else class="small text-muted">{{ guardian.phone ?? NO_PHONE }}</span>
                                    </div>
                                    <div>
                                        <a v-if="guardian.emailHref" :href="guardian.emailHref" class="guardian-link text-break">
                                            <i class="bi bi-envelope me-2" aria-hidden="true"></i>{{ guardian.email }}
                                        </a>
                                        <span v-else class="small text-muted text-break">{{ guardian.email ?? NO_EMAIL }}</span>
                                    </div>
                                    <div class="small text-muted">
                                        Consent: {{ guardian.consent }}<span v-if="guardian.consentDay">, {{ storedDayLabel(guardian.consentDay) }}</span>
                                    </div>
                                </li>
                            </ul>

                            <template v-if="details.unconfirmed.length">
                                <h6 class="text-muted mt-4 mb-2">Not confirmed (from a registration form)</h6>
                                <ul class="list-unstyled small mb-2">
                                    <li v-for="guardian in details.unconfirmed" :key="guardian.id" class="mb-1 text-break">
                                        {{ guardian.name }}
                                        <span class="text-muted d-block">{{ guardian.address }}</span>
                                    </li>
                                </ul>
                                <p class="small text-warning-emphasis mb-0">
                                    Not confirmed. Anyone can post the registration form. Check who posted this before
                                    confirming it on the roster.
                                </p>
                            </template>

                            <template v-if="details.departed.length">
                                <h6 class="text-muted mt-4 mb-2">No longer in this class</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li v-for="guardian in details.departed" :key="guardian.id" class="mb-1 text-break">
                                        {{ guardian.name }}
                                        <span class="text-muted">Left {{ storedDayLabel(guardian.leftOn) }}</span>
                                    </li>
                                </ul>
                            </template>
                        </template>

                        <p class="small text-muted mt-4 mb-0">
                            Allergies, medical notes and emergency contacts are not kept on a student's record. If the
                            family filled in an enrolment form, they are in Forms, under that form's responses.
                        </p>
                    </div>

                    <div class="modal-footer justify-content-start">
                        <!--
                            MOUNT POINT (S1, move a student): the "Move" button, an icon AND the
                            word. Only for a student who has not left, in a class.
                        -->
                        <slot name="move" :student="student"></slot>
                        <button
                            v-if="!student.left_on"
                            type="button"
                            class="btn btn-outline-secondary"
                            @click="emit('withdraw', student)"
                        >
                            <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Left the class
                        </button>
                        <!-- The Member Directory, which opens this person's record itself
                             (ContactsView reads `?contact=`). A real link, so it can be
                             opened in a new tab; followed here, it leaves this page and
                             the panel goes with it. -->
                        <router-link
                            class="btn btn-outline-primary"
                            :to="{ name: 'masjid.contacts', query: fullRecordQuery(student.contact_id) }"
                        >
                            <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open full record
                        </router-link>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import { GroupMembership } from '@/core/types/data/masjid-related/Group';
import { trapTab } from '@/core/helpers/focusTrap';
import {
    NO_EMAIL,
    NO_PHONE,
    fullRecordQuery,
    isConfirmedRow,
    personName,
    storedDayLabel,
    studentDetails,
    type StudentDetails,
} from '@/core/helpers/studentDetails';

/**
 * "Student details", for the office: what the roster already holds about one student, in
 * one place, opened by tapping the student's name.
 *
 * NO REQUEST IS MADE HERE. Every field is on the roster payload the page loaded: the
 * student's own row, and the guardian entries that name them. So this panel can show
 * nothing the office could not already read on the roster, and it cannot go stale apart
 * from the roster.
 *
 * WHO IS OFFERED AS SOMEONE TO CALL is decided in core/helpers/studentDetails.ts, not in
 * this template: confirmed entries that are still in the class get a tap-to-call and an
 * email link, and nobody else does.
 *
 * The office roster only. A teacher's screen has its own sheet and carries nothing about
 * a parent.
 *
 * Two other features mount into this panel through the named slots marked MOUNT POINT:
 * the Move button and its badges (moving a student to another class), and the age and
 * date of birth. With a slot left empty the panel simply does not draw that part.
 */
const props = defineProps<{
    /** The student row being read, or null while the panel is shut. */
    student: GroupMembership | null;
    /** The whole roster the row came from: the guardian entries are read off it. */
    memberships: GroupMembership[];
    /** The grade is being saved, so its field is off. */
    savingGrade?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    'save-grade': [membership: GroupMembership, value: string];
    withdraw: [membership: GroupMembership];
}>();

const uid = Math.random().toString(36).slice(2, 8);
const titleId = `student-details-title-${uid}`;
const gradeId = `student-details-grade-${uid}`;
const root = ref<HTMLElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);

const empty: StudentDetails = { studentHasLeft: false, confirmed: [], unconfirmed: [], departed: [], former: [] };
const details = computed<StudentDetails>(() => (props.student ? studentDetails(props.memberships, props.student) : empty));

/** The student's own email or phone, as text. Both when the record holds both. */
const ownAddress = computed<string>(() => [props.student?.contact?.email, props.student?.contact?.phone]
    .map(value => (value ?? '').trim())
    .filter(Boolean)
    .join(' · '));

/** `joined_at` is an instant, drawn in the reader's own day as the roster row draws it. */
const joinedLabel = computed<string>(() => {
    const iso = props.student?.joined_at;
    if (!iso) return '—';
    const date = new Date(iso);
    return isNaN(date.getTime()) ? iso : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
});

// Whatever had focus before the panel opened (the student's name) gets it back.
let returnFocusTo: HTMLElement | null = null;

watch(() => props.student?.id ?? null, async (id, before) => {
    if (id !== null && (before === null || before === undefined)) {
        returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        await nextTick();
        closeButton.value?.focus();
    } else if (id === null && returnFocusTo) {
        if (document.contains(returnFocusTo)) returnFocusTo.focus();
        returnFocusTo = null;
    }
}, { immediate: true });

const close = () => emit('close');

/** Escape closes; Tab and Shift+Tab stay inside the panel. */
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        event.stopPropagation();
        close();
        return;
    }

    trapTab(event, root.value);
};
</script>

<style scoped>
/* Above the page, level with this screen's other dialogs, below SweetAlert2 (1060). */
.student-details-panel {
    background: rgba(0, 0, 0, 0.5);
    z-index: 1055;
}

.guardian-line + .guardian-line {
    border-top: 1px solid var(--bs-border-color);
    margin-top: 0.75rem;
    padding-top: 0.75rem;
}

/* A tap-to-call link is used on a phone, in a hurry: a full 44px target. */
.guardian-link {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
}
</style>
