<template>
    <div class="card border">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold"><i class="bi bi-journal-text me-2"></i>Staff notes</span>
                <div class="small text-muted">Internal only — never shown to the person who asked.</div>
            </div>
            <span class="badge bg-light text-dark border">{{ notes.length }}</span>
        </div>

        <div class="card-body notes-scroll">
            <div v-if="notes.length === 0" class="text-muted small text-center py-3">
                No notes yet. Write down what happened when you called — it is the only
                record of this request's history.
            </div>

            <!--
                Newest first, as the server returns them: an operator picking a
                request back up wants the last thing that happened, not the first.
            -->
            <div v-for="note in notes" :key="note.id" class="mb-3">
                <div class="small text-muted">
                    {{ note.author?.name || 'A former staff member' }}
                    &middot;
                    {{ formatDateTime(note.created_at) }}
                </div>
                <div class="note-body">{{ note.body }}</div>
            </div>
        </div>

        <div class="card-footer bg-white">
            <form @submit.prevent="submitNote">
                <div class="d-flex gap-2 align-items-start">
                    <textarea
                        class="form-control"
                        rows="2"
                        v-model.trim="noteBody"
                        placeholder="Called back, no answer — trying again Thursday…"
                        :maxlength="maxNoteLength"
                        :disabled="saving"
                    ></textarea>
                    <button class="btn btn-success" type="submit" :disabled="saving || !noteBody">
                        <span v-if="saving" class="spinner-border spinner-border-sm" role="status"></span>
                        <i v-else class="bi bi-send"></i>
                    </button>
                </div>
                <div v-if="saveError" class="text-danger small mt-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ saveError }}
                </div>
            </form>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { AppointmentRequestNote } from '@/core/types/data/masjid-related/AppointmentRequest';
import { useAppointmentRequestsStore } from '@/stores/masjid/appointmentRequestsStore';
import { useAppointmentRequestDisplay } from '@/composables/useAppointmentRequestDisplay';
import { apiErrorText } from '@/core/services/ApiErrors';

/**
 * The notes thread on one appointment request.
 *
 * A note body is encrypted at rest for the same reason the reason-for-visit is
 * ("needs an interpreter", "asked us not to call the house"), so it obeys the
 * same rules: it is never logged, never echoed into a URL, and never leaves this
 * screen. A failed save reports its MESSAGE inline — the note the operator just
 * typed stays in the box so their work is not lost, and no Swal toast carries
 * the body around the DOM.
 */

const props = defineProps<{
    requestId: number;
    notes: AppointmentRequestNote[];
}>();

const emit = defineEmits<{ (e: 'added'): void }>();

// Stores
const appointmentRequestsStore = useAppointmentRequestsStore();

// Display helpers
const { formatDateTime } = useAppointmentRequestDisplay();

// State
const noteBody = ref('');
const saving = ref(false);
const saveError = ref('');

/** Mirrors the server's `max:10000` on StoreAppointmentRequestNoteRequest. */
const maxNoteLength = 10000;

const submitNote = async () => {
    if (!noteBody.value || saving.value) return;

    saving.value = true;
    saveError.value = '';
    try {
        await appointmentRequestsStore.addNote(props.requestId, noteBody.value);
        // Cleared only after the server has it — a failed save must not throw
        // away what someone wrote.
        noteBody.value = '';
        emit('added');
    } catch (error) {
        saveError.value = apiErrorText(error, 'Failed to save the note.');
    } finally {
        saving.value = false;
    }
};
</script>

<style scoped>
.notes-scroll {
    max-height: 22rem;
    overflow-y: auto;
}

.note-body {
    white-space: pre-wrap;
}
</style>
