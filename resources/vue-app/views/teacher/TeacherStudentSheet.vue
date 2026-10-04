<!--
    What a teacher sees on tapping a student's row on the Roster tab.

    The avatar (and the way to change it), the student's name, their grade and
    their age. THAT IS ALL OF IT, and it is all there is to give: the sheet is
    drawn from `studentSheetModel()`, which copies exactly those four things out
    of the class payload, so nothing the payload grows later can appear here.

    NOTHING ABOUT A PARENT, by the owner's decision (2026-10-04): no name, no
    phone, no email, no count. Parents' phone numbers are for the school office,
    and the one line at the bottom says so. The teacher never sees the date of
    birth either: the server sends a whole number, or null.

    The dialog is the same modal the avatar picker always used on this screen;
    on a phone it sits at the bottom of the screen, under the thumb.
-->
<template>
    <div ref="root" class="modal fade show d-block tc-student-sheet" tabindex="-1" role="dialog" aria-modal="true"
         :aria-labelledby="titleId" style="background:rgba(0,0,0,.5)"
         @click.self="emit('close')">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 :id="titleId" class="modal-title">{{ model.name }}</h5>
                    <button type="button" class="btn-close" aria-label="Close" @click="emit('close')"></button>
                </div>

                <div v-if="!choosingAvatar" class="modal-body">
                    <div class="d-flex align-items-center gap-3">
                        <PersonAvatar :avatar="model.avatar"
                                      :first-name="student.contact?.first_name" :last-name="student.contact?.last_name"
                                      :size="72" />
                        <div class="flex-grow-1">
                            <div class="fw-semibold">{{ model.name }}</div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                                <span v-if="model.grade" class="badge bg-primary-subtle text-primary-emphasis fw-normal">
                                    {{ model.grade }}
                                </span>
                                <span v-if="ageLine" class="small" :class="model.age === null ? 'text-muted' : ''">{{ ageLine }}</span>
                            </div>
                        </div>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary mt-3" @click="choosingAvatar = true">
                        <i class="bi bi-person-badge me-1"></i>Change avatar
                    </button>

                    <p class="text-muted small mt-3 mb-0">{{ EMERGENCY_LINE }}</p>
                </div>

                <div v-else class="modal-body">
                    <button type="button" class="btn btn-sm btn-link px-0 mb-2" @click="choosingAvatar = false">
                        <i class="bi bi-chevron-left me-1"></i>Back to {{ model.name }}
                    </button>
                    <AvatarPicker
                        :masjid-id="masjidId"
                        :avatar="student.contact?.avatar"
                        :first-name="student.contact?.first_name"
                        :last-name="student.contact?.last_name"
                        :catalogue-endpoint="`/api/teacher/masjids/${masjidId}/avatars`"
                        :family-endpoint="`${base}/members/${student.membership_id}/avatar`"
                        @saved="onAvatarSaved" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import AvatarPicker from '@/components/common/AvatarPicker.vue';
import { trapTab } from '@/core/helpers/focusTrap';
import { EMERGENCY_LINE, sheetAgeLine, studentSheetModel } from '@/core/helpers/studentAge';

const props = defineProps<{
    /** One entry of the class payload's `students`. */
    student: any;
    masjidId: number | string;
    /** The class's teacher API base: `/api/teacher/masjids/{id}/groups/{id}`. */
    base: string;
    /**
     * Whether this group is a CLASS (`kind === 'class'`). Ages are kept for
     * students in classes only, so in a ḥalaqa or a team the sheet says nothing
     * about an age: "the office can add a date of birth" would not be true there.
     */
    isClass: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    /** The server's answer to a saved avatar (a student, names only). The sheet stays open. */
    (e: 'avatar-saved', student: any): void;
}>();

const root = ref<HTMLElement | null>(null);
const choosingAvatar = ref(false);

const model = computed(() => studentSheetModel(props.student));
const ageLine = computed(() => (props.isClass ? sheetAgeLine(model.value.age) : ''));
const titleId = computed(() => `tc-student-sheet-title-${props.student?.membership_id ?? 'x'}`);

const onAvatarSaved = (saved: any) => {
    emit('avatar-saved', saved);
    choosingAvatar.value = false;
};

// Heard on the DOCUMENT, not on the dialog. Saving an avatar removes the button
// that had focus, focus falls to the page, and a key pressed then never reaches
// a listener on the dialog: Escape did nothing, and Tab walked the page behind
// the backdrop. (Found walking it; the "More" menu on this screen listens the
// same way.)
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        emit('close');
        return;
    }

    trapTab(event, root.value);
};

onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    root.value?.focus?.();
});
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<style scoped>
/* Fingers: the same 44px targets TeacherClass.phone.css gives the rest of the
   screen. That file is scoped to TeacherClass.vue and reaches only this
   component's root, so the sheet keeps its own. */
@media (max-width: 575.98px), (pointer: coarse) {
    .tc-student-sheet .btn {
        min-height: 44px;
    }

    .tc-student-sheet .btn-close {
        flex-shrink: 0;
        width: 16px;
        height: 16px;
        padding: 14px;
    }
}

/* A row of the roster is tapped with a thumb, so on a phone the sheet opens
   where the thumb already is: against the bottom edge, full width. Wider
   screens keep the centred modal the rest of this screen uses. */
@media (max-width: 575.98px) {
    .tc-student-sheet .modal-dialog {
        align-items: flex-end;
        min-height: 100%;
        margin: 0;
        max-width: none;
    }

    .tc-student-sheet .modal-content {
        border-radius: 1rem 1rem 0 0;
        border-bottom: 0;
        padding-bottom: env(safe-area-inset-bottom, 0);
        max-height: 92vh;
    }
}
</style>
