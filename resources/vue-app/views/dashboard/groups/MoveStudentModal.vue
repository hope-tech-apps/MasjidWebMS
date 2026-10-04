<template>
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)"
         role="dialog" aria-modal="true" aria-labelledby="move-student-title"
         @click.self="cancel" @keydown.esc="cancel">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 id="move-student-title" class="modal-title">
                        <i class="bi bi-arrow-right-circle me-2"></i> Move to another class
                    </h5>
                    <button type="button" class="btn-close" aria-label="Close" :disabled="saving" @click="cancel"></button>
                </div>

                <!-- AFTER THE MOVE: the server's own lines, and they stay until
                     the office taps OK, because they can carry things to do. -->
                <template v-if="done">
                    <div class="modal-body" aria-live="polite">
                        <p class="fw-semibold mb-2"><i class="bi bi-check-circle text-success me-1"></i> Moved</p>
                        <ul class="mb-0 ps-3">
                            <li v-for="(line, i) in done" :key="i" class="mb-1">{{ line }}</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" @click="finish">OK</button>
                    </div>
                </template>

                <form v-else @submit.prevent="save">
                    <div class="modal-body">
                        <p class="mb-3">
                            <span class="fw-semibold">{{ studentName }}</span> is in
                            <span class="fw-semibold">{{ groupName }}</span>.
                        </p>

                        <div class="mb-3">
                            <label class="form-label" for="move-to-class">Move to</label>
                            <div v-if="classesState === 'loading'" class="form-text">Loading classes…</div>
                            <div v-else-if="classesState === 'failed'" class="text-danger small" role="alert">
                                <i class="bi bi-exclamation-triangle me-1"></i> Could not load the classes.
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-2" @click="loadClasses">Try again</button>
                            </div>
                            <div v-else-if="options.length === 0" class="form-text">
                                This school has no other class that is running. Add the class first, on the Classes page.
                            </div>
                            <select v-else id="move-to-class" class="form-select" v-model="form.toGroupId" :disabled="saving">
                                <option :value="null" disabled>Choose a class</option>
                                <option v-for="option in options" :key="option.id" :value="option.id">{{ option.name }}</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="move-first-day">First day in the new class</label>
                            <input id="move-first-day" type="date" class="form-control" :max="schoolToday"
                                   v-model="form.movedOn" :disabled="saving">
                            <div class="form-text">
                                {{ groupName }} stops asking for their attendance from this day. If {{ groupName }} has
                                already marked them on or after it, those days stay with {{ groupName }} and the new class
                                expects them from the day after.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="move-grade">Grade in the new class</label>
                            <input id="move-grade" type="text" class="form-control" maxlength="32"
                                   v-model="form.gradeLabel" :disabled="saving">
                            <div class="form-text">
                                Shown on the class list and to the teacher. Change it if the grade changes with the class.
                            </div>
                        </div>

                        <div v-if="form.toGroupId" class="border rounded p-3 bg-light" aria-live="polite">
                            <div class="fw-semibold mb-2">What will happen</div>

                            <div v-if="previewState === 'checking'" class="text-muted">Checking…</div>

                            <div v-else-if="previewState === 'failed'" class="text-danger" role="alert">
                                <i class="bi bi-exclamation-triangle me-1"></i> {{ previewError }}
                                <button v-if="previewAction === 'retry'" type="button"
                                        class="btn btn-sm btn-outline-secondary ms-2" @click="check">Try again</button>
                                <button v-else-if="previewAction === 'reload'" type="button"
                                        class="btn btn-sm btn-outline-secondary ms-2" @click="reloadRoster">Reload the roster</button>
                            </div>

                            <div v-else-if="preview && !preview.can_move" class="text-danger" role="alert">
                                <div v-for="(line, i) in refusalLines" :key="i" class="mb-1">
                                    <i v-if="i === 0" class="bi bi-x-octagon me-1"></i>{{ line }}
                                </div>
                                <button v-if="preview.open_group" type="button" class="btn btn-sm btn-outline-secondary mt-1"
                                        @click="openClass(preview.open_group.id)">
                                    Open {{ preview.open_group.name }}
                                </button>
                            </div>

                            <ul v-else-if="preview" class="mb-0 ps-3">
                                <li v-for="(line, i) in preview.lines" :key="i" class="mb-1">{{ line }}</li>
                            </ul>
                        </div>

                        <!-- A refusal from the move itself: shown here, in the
                             dialog, and the check above runs again by itself. -->
                        <div v-if="saveError" class="alert alert-danger mt-3 mb-0" role="alert">
                            <i class="bi bi-x-octagon me-1"></i>
                            <span v-for="(line, i) in saveError.split('\n')" :key="i" class="d-block">{{ line }}</span>
                            <button v-if="saveOpenGroup" type="button" class="btn btn-sm btn-outline-danger mt-2"
                                    @click="openClass(saveOpenGroup.id)">
                                Open {{ saveOpenGroup.name }}
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" :disabled="saving" @click="cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary" :disabled="!canMove">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ chosenName ? `Move to ${chosenName}` : 'Move' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * MOVE A STUDENT TO ANOTHER CLASS: the office's dialog.
 *
 * One read and one write. Choosing a class (or changing the day) asks the server what the move
 * would do, and the dialog prints the server's own lines; the Move button is off until that answer
 * says the move can happen. Saving sends what was shown back with the request, so a move the
 * server would now make differently is refused and the office reads again.
 *
 * THE BUTTON IS OFF, AND THE HANDLER REFUSES TOO: while the check is running, after a refusal, and
 * from the tap until the answer. A second tap that lands before the button is redrawn sends
 * nothing.
 *
 * No Teleport in here. The roster tab wraps this in one; mounted on its own it can be driven by
 * the suite (tests/roster-move-mounted.test.ts).
 */
import { computed, onMounted, ref, watch } from 'vue';
import type { GroupMembership, MovePreview } from '@/core/types/data/masjid-related/Group';
import { useGroupsStore } from '@/stores/masjid/groupsStore';
import { apiErrorText } from '@/core/services/ApiErrors';
import { classOptions, fullName, moveBody } from '@/core/helpers/rosterMove';

const props = defineProps<{
    groupId: number;
    groupName: string;
    membership: GroupMembership;
    /** Today on the school's clock: the date field's default and its latest day. */
    schoolToday: string;
}>();

const emit = defineEmits<{
    (event: 'close'): void;
    /** The move was saved and the office has read what happened. */
    (event: 'moved'): void;
    /** The roster this dialog was opened from is out of date. */
    (event: 'reload'): void;
    (event: 'open-class', groupId: number): void;
}>();

const groupsStore = useGroupsStore();

const studentName = computed(() => fullName(props.membership.contact));

const remembered = groupsStore.lastMoveChoice;
const form = ref<{ toGroupId: number | null; movedOn: string; gradeLabel: string }>({
    toGroupId: null,
    movedOn: remembered.movedOn && remembered.movedOn <= props.schoolToday ? remembered.movedOn : props.schoolToday,
    gradeLabel: props.membership.grade_label ?? '',
});

// ------------------------------------------------------------- the class list

const classes = ref<any[]>([]);
const classesState = ref<'loading' | 'failed' | 'ready'>('loading');

const options = computed(() => classOptions(classes.value, props.groupId, form.value.movedOn));
const chosenName = computed(() => options.value.find((o) => o.id === form.value.toGroupId)?.name ?? '');

const loadClasses = async () => {
    classesState.value = 'loading';
    try {
        classes.value = await groupsStore.fetchClassesForMove();
        classesState.value = 'ready';

        // The class chosen for the last student, when it is still on offer.
        if (form.value.toGroupId === null && options.value.some((o) => o.id === remembered.toGroupId)) {
            form.value.toGroupId = remembered.toGroupId;
        }
    } catch {
        classesState.value = 'failed';
    }
};

// ------------------------------------------------------------ what will happen

const preview = ref<MovePreview | null>(null);
const previewState = ref<'idle' | 'checking' | 'failed' | 'ready'>('idle');
const previewError = ref('');
const previewAction = ref<'retry' | 'reload' | null>(null);
let asked = 0;

const refusalLines = computed(() => (preview.value?.refusal ?? '').split('\n').filter(Boolean));

const check = async () => {
    const toGroupId = form.value.toGroupId;
    if (!toGroupId || !form.value.movedOn) {
        preview.value = null;
        previewState.value = 'idle';
        return;
    }

    const mine = ++asked;
    previewState.value = 'checking';
    preview.value = null;

    try {
        const answer = await groupsStore.previewMove(props.groupId, props.membership.id, toGroupId, form.value.movedOn);
        if (mine !== asked) return;   // a newer choice has been made since

        preview.value = answer;
        previewState.value = 'ready';
    } catch (error: any) {
        if (mine !== asked) return;

        const status = error?.response?.status;
        previewState.value = 'failed';
        if (status === 404) {
            previewError.value = 'This roster has changed. Reload it.';
            previewAction.value = 'reload';
        } else if (status === 403) {
            previewError.value = 'You do not have permission to move students.';
            previewAction.value = null;
        } else if (status === 422) {
            previewError.value = apiErrorText(error, 'Could not check this move. Try again.');
            previewAction.value = 'retry';
        } else {
            previewError.value = 'Could not check this move. Try again.';
            previewAction.value = 'retry';
        }
    }
};

watch([() => form.value.toGroupId, () => form.value.movedOn], () => check());

// A class that has ended by the chosen day drops off the list; so does the choice.
watch(options, (list) => {
    if (form.value.toGroupId !== null && !list.some((o) => o.id === form.value.toGroupId)) {
        form.value.toGroupId = null;
    }
});

// ------------------------------------------------------------------- the move

const saving = ref(false);
const saveError = ref('');
const saveOpenGroup = ref<{ id: number; name: string } | null>(null);
const done = ref<string[] | null>(null);

const canMove = computed(() => !saving.value && previewState.value === 'ready' && preview.value?.can_move === true);

const save = async () => {
    // The guard, not only the disabled button: a second tap before the redraw sends nothing.
    if (!canMove.value || !preview.value || form.value.toGroupId === null) return;

    saving.value = true;
    saveError.value = '';
    saveOpenGroup.value = null;

    try {
        done.value = await groupsStore.moveMembership(props.groupId, props.membership.id, moveBody({
            toGroupId: form.value.toGroupId,
            movedOn: form.value.movedOn,
            gradeLabel: form.value.gradeLabel,
        }, preview.value));

        groupsStore.lastMoveChoice = { toGroupId: form.value.toGroupId, movedOn: form.value.movedOn };
    } catch (error: any) {
        // Shown in the dialog, which stays open, and the check runs again by
        // itself: what the office reads next is what the server would do now.
        saveError.value = apiErrorText(error, 'The move could not be saved. Nothing was moved.');
        saveOpenGroup.value = error?.response?.data?.open_group ?? null;
        await check();
    } finally {
        saving.value = false;
    }
};

const cancel = () => {
    if (saving.value) return;

    if (done.value) {
        finish();
    } else {
        emit('close');
    }
};

const finish = () => emit('moved');
const reloadRoster = () => emit('reload');
const openClass = (groupId: number) => emit('open-class', groupId);

onMounted(async () => {
    await loadClasses();
    document.getElementById('move-to-class')?.focus();
});
</script>
