<template>
    <div ref="root" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)"
         role="dialog" aria-modal="true" aria-labelledby="put-back-title"
         @click.self="cancel">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 id="put-back-title" class="modal-title">
                        <i class="bi bi-arrow-counterclockwise me-2"></i> {{ title }}
                    </h5>
                    <button ref="closeButton" type="button" class="btn-close" aria-label="Close" :disabled="saving" @click="cancel"></button>
                </div>

                <div class="modal-body" aria-live="polite">
                    <div v-if="state === 'checking'" class="text-muted">Checking…</div>

                    <div v-else-if="state === 'failed'" class="text-danger" role="alert">
                        <i class="bi bi-exclamation-triangle me-1"></i> Could not check this. Try again.
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" @click="read">Try again</button>
                    </div>

                    <div v-else-if="state === 'gone'" class="text-danger" role="alert">
                        <i class="bi bi-exclamation-triangle me-1"></i> This roster has changed. Reload it.
                    </div>

                    <!-- The lines that say why not are red and each carries the
                         sign: colour is never the only signal. What to do
                         about them, and what the server adds about consent
                         and Manara Bucks, are plain. -->
                    <template v-else-if="decision">
                        <p v-for="(line, i) in decision.lines" :key="i" class="mb-2"
                           :class="{ 'text-danger': decision.stops.includes(i) }">
                            <i v-if="decision.stops.includes(i)" class="bi bi-x-octagon me-1" aria-hidden="true"></i>{{ line }}
                        </p>
                    </template>

                    <div v-if="saveError" class="alert alert-danger mt-3 mb-0" role="alert">
                        <i class="bi bi-x-octagon me-1"></i> {{ saveError }}
                    </div>
                </div>

                <div class="modal-footer">
                    <button v-if="state === 'gone'" type="button" class="btn btn-outline-secondary" @click="emit('reload')">
                        Reload the roster
                    </button>
                    <button v-if="decision?.openGroup" type="button" class="btn btn-outline-secondary"
                            :disabled="saving" @click="emit('open-class', decision.openGroup.id)">
                        Open {{ decision.openGroup.name }}
                    </button>
                    <!-- NO BUTTON THAT PUTS THEM BACK in the blocked form, while
                         checking, or after a failed check: `confirmLabel` is
                         null there, and the handler refuses too. -->
                    <button v-if="decision?.confirmLabel" type="button" class="btn btn-success" :disabled="saving" @click="putBack">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                        {{ decision.confirmLabel }}
                    </button>
                    <button type="button" class="btn btn-secondary" :disabled="saving" @click="cancel">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * "PUT THIS STUDENT BACK ON THE ROSTER", for every row that has left.
 *
 * Putting a student back re-opens EVERY guardian entry beside them in this class. On a row that
 * was MOVED those are the entries the move left behind. One of them can belong to an adult the
 * office has since removed where the student is now, and one of them can hold a consent the
 * family has since withdrawn or narrowed in the class it was carried to or from, which would be
 * in force again. The server's undo is deliberately ungated (an undo that can be refused is the
 * one direction that verb must never have), so the guard is here, on the only screen that offers
 * the action:
 *
 *   1. when the dialog opens it READS THE ROSTER AGAIN, quietly, into its own state, so what it
 *      decides from is seconds old and not as old as the page;
 *   2. the row is found again by id, and `putBackForm` picks one of four forms from the server's
 *      `moved_to_state`;
 *   3. in the `blocked` form (a guardian the server names, or a consent it says would come back)
 *      there is no button that puts the student back, and `putBack()` refuses whatever called it.
 *
 * What the server says about consent and Manara Bucks is printed as it came, above the button in
 * every form that has one.
 *
 * THIS FILE IS THE ONLY PLACE IN THE ADMIN SPA THAT SENDS THE UNDO. The roster tab opens this
 * dialog for every row, moved or not, and sends nothing itself (pinned in roster-move.test.ts).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { GroupMembership } from '@/core/types/data/masjid-related/Group';
import { useGroupsStore } from '@/stores/masjid/groupsStore';
import { apiErrorText } from '@/core/services/ApiErrors';
import { trapTab } from '@/core/helpers/focusTrap';
import { putBackForm } from '@/core/helpers/rosterMove';
import type { PutBackForm } from '@/core/helpers/rosterMove';

const props = defineProps<{
    groupId: number;
    /** The row as the page drew it. Only its id is trusted: the dialog reads the roster again. */
    membership: GroupMembership;
}>();

const emit = defineEmits<{
    (event: 'close'): void;
    (event: 'done'): void;
    (event: 'reload'): void;
    (event: 'open-class', groupId: number): void;
}>();

const groupsStore = useGroupsStore();

const state = ref<'checking' | 'failed' | 'gone' | 'ready'>('checking');
const decision = ref<PutBackForm | null>(null);
const saving = ref(false);
const saveError = ref('');

const title = computed(() => decision.value?.title ?? 'Put them back on the roster?');

const read = async () => {
    state.value = 'checking';
    decision.value = null;

    try {
        const roster = await groupsStore.readRoster(props.groupId);
        const row = roster?.rows.find((r: GroupMembership) => r.id === props.membership.id);

        if (!roster || !row || !row.left_on) {
            // Removed, or already put back, by somebody else since the page was drawn.
            state.value = 'gone';
            return;
        }

        decision.value = putBackForm(row, roster.rows);
        state.value = 'ready';
    } catch {
        state.value = 'failed';
    }
};

const putBack = async () => {
    // The guard, not only the missing button.
    if (saving.value || state.value !== 'ready' || !decision.value?.confirmLabel) return;

    saving.value = true;
    saveError.value = '';

    try {
        await groupsStore.putBack(props.groupId, props.membership.id);
        emit('done');
    } catch (error) {
        saveError.value = apiErrorText(error, 'That change could not be undone.');
    } finally {
        saving.value = false;
    }
};

const cancel = () => {
    if (!saving.value) emit('close');
};

// THE KEYBOARD COMES INTO THE DIALOG AND STAYS IN IT. The dialog is teleported
// to <body> and opened from a button in the roster row, so until focus is moved
// the focused element is that row button, behind the backdrop: a screen reader
// announces nothing, Tab walks on to the row's Remove, and Escape never reaches
// a listener on the dialog itself. So focus goes to Close on mount, Escape and
// Tab are heard on the DOCUMENT (as TeacherStudentSheet hears them), and the
// button that opened the dialog gets focus back when it shuts.
const root = ref<HTMLElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);
let opener: HTMLElement | null = null;

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        cancel();   // Cancel, and like Cancel it waits for a save in flight
        return;
    }

    trapTab(event, root.value);
};

onMounted(() => {
    opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    document.addEventListener('keydown', onKeydown);
    closeButton.value?.focus();
    read();
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    // After a put back the row is redrawn and its green button is gone; then
    // there is nothing to go back to.
    if (opener && document.contains(opener)) opener.focus();
});
</script>
