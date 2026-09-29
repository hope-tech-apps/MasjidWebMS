<template>
    <!--
        The Scheduled list (T-002.4): class stories and new conversations that are written
        and waiting for their time, or that were refused at send time.

        One component for both, drawn from the same row shape (core/helpers/scheduledSend
        `storyRow` / `messageRow`), so the two lists cannot drift. It makes no request: it
        raises `send-now`, `cancel` and `save`, and each screen's own client (the teacher's
        or the office's) does the call. Edit, Send now and Cancel are offered only when the
        server said this viewer may (`canChange`: the author and the office). A co-teacher
        SEES the row and is offered nothing that would be refused.
    -->
    <section v-if="rows.length || error" class="card border-0 shadow-sm mb-4" data-test="scheduled-list"
             :aria-label="`Scheduled ${kindLabel}`">
        <div class="card-header bg-white">
            <strong class="small">Scheduled {{ kindLabel }}</strong>
            <span class="text-muted small ms-2">({{ timezone || 'school time' }})</span>
        </div>

        <div v-if="error" class="alert alert-danger small m-3 mb-0" role="alert">{{ error }}</div>

        <ul class="list-group list-group-flush">
            <li v-for="row in rows" :key="`${row.kind}-${row.id}`" class="list-group-item" data-test="scheduled-row">
                <template v-if="editingKey === keyOf(row)">
                    <input v-model="draft.heading" type="text" maxlength="255" class="form-control form-control-sm mb-2"
                           :placeholder="row.kind === 'story' ? 'Title (optional)' : 'Subject'" :aria-label="row.kind === 'story' ? 'Title' : 'Subject'">
                    <textarea v-model="draft.body" rows="3" class="form-control form-control-sm mb-2" aria-label="Text"></textarea>
                    <SendLaterField :enabled="true" always-on v-model="draft.at" :timezone="timezone" :max-days="maxDays"
                                    :error="draftChanged(row) ? draftError : null" />
                    <p v-if="row.status === 'failed'" class="small text-muted mt-2 mb-0">
                        Choose a new time to put it back in the queue.
                    </p>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-success" :disabled="busy || !draftReady" @click="save(row)">
                            {{ busy ? 'Saving…' : 'Save' }}
                        </button>
                        <button type="button" class="btn btn-sm btn-link text-muted" :disabled="busy" @click="editingKey = null">Cancel editing</button>
                    </div>
                </template>

                <template v-else>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <div v-if="row.heading" class="fw-semibold small">{{ row.heading }}</div>
                            <div class="text-muted small">
                                <span v-if="row.about">About {{ row.about }} · </span>
                                <template v-if="row.status === 'failed'">Was due</template>
                                <template v-else-if="row.status === 'sending'">Sending</template>
                                <template v-else>Goes out</template>
                                {{ describeSchoolTime(row.whenLocal) }}
                                <span v-if="row.author"> · {{ row.author }}</span>
                            </div>
                        </div>
                        <span class="badge text-nowrap" :class="row.status === 'failed' ? 'bg-danger' : 'bg-secondary'">
                            {{ row.status === 'failed' ? 'Not sent' : row.status === 'sending' ? 'Sending…' : 'Scheduled' }}
                        </span>
                    </div>

                    <p class="small mt-2 mb-1" style="white-space: pre-wrap;">{{ row.body }}</p>

                    <p v-if="row.status === 'failed'" class="small text-danger mb-1" role="alert" data-test="scheduled-failure">
                        {{ failureText(row) }}
                    </p>

                    <div v-if="row.canChange && row.status !== 'sending'" class="d-flex flex-wrap gap-2 mt-2">
                        <template v-if="confirmingKey === keyOf(row)">
                            <span class="small align-self-center">Cancel this {{ row.kind === 'story' ? 'story' : 'message' }}?</span>
                            <button type="button" class="btn btn-sm btn-danger" :disabled="busy" @click="confirm(row)">Yes, cancel it</button>
                            <button type="button" class="btn btn-sm btn-link text-muted" @click="confirmingKey = null">Keep it</button>
                        </template>
                        <template v-else>
                            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="startEdit(row)">Edit</button>
                            <button v-if="row.status !== 'failed'" type="button" class="btn btn-sm btn-outline-success"
                                    :disabled="busy" @click="$emit('send-now', row)">Send now</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" :disabled="busy" @click="confirmingKey = keyOf(row)">Cancel</button>
                        </template>
                    </div>
                    <p v-else-if="row.status !== 'sending'" class="small text-muted fst-italic mb-0">
                        Only the author or the office can change this.
                    </p>
                </template>
            </li>
        </ul>
    </section>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import SendLaterField from '@/components/common/SendLaterField.vue';
import { describeSchoolTime, failureText, schoolNow, sendAtError, type ScheduledRow } from '@/core/helpers/scheduledSend';

const props = defineProps<{
    rows: ScheduledRow[];
    /** `meta.scheduling.timezone` and `.max_days_ahead`. */
    timezone?: string | null;
    maxDays?: number | null;
    /** True while a request from this list is in flight. */
    busy?: boolean;
    /** The last request's failure, in words. */
    error?: string;
}>();

const emit = defineEmits<{
    (e: 'send-now', row: ScheduledRow): void;
    (e: 'cancel', row: ScheduledRow): void;
    /** `sendAt` is set only when the time was changed; the server keeps the old one otherwise. */
    (e: 'save', row: ScheduledRow, fields: { heading: string; body: string; sendAt: string | null }): void;
}>();

const kindLabel = computed(() => {
    const kinds = new Set(props.rows.map((r) => r.kind));

    return kinds.size === 1 && kinds.has('message') ? 'messages' : 'stories';
});

const keyOf = (row: ScheduledRow) => `${row.kind}-${row.id}`;

const editingKey = ref<string | null>(null);
const confirmingKey = ref<string | null>(null);
const draft = reactive({ heading: '', body: '', at: '' });

const draftError = computed(() => sendAtError(draft.at, props.timezone, props.maxDays ?? 30));

// A text-only edit keeps the old time (which may already be behind us for a failed row),
// so the time is only checked when it was changed.
const draftChanged = (row: ScheduledRow) => draft.at !== row.whenLocal;

const draftReady = computed(() => {
    const row = props.rows.find((r) => keyOf(r) === editingKey.value);

    if (!row) return false;

    return draft.body.trim() !== ''
        && (row.kind === 'story' || draft.heading.trim() !== '')
        && (!draftChanged(row) || draftError.value === null);
});

const startEdit = (row: ScheduledRow) => {
    editingKey.value = keyOf(row);
    confirmingKey.value = null;
    draft.heading = row.heading;
    draft.body = row.body;
    // A failed item's own time is behind us, so it is offered tomorrow morning: saving
    // then puts it back in the queue. A waiting item keeps the time it has.
    draft.at = row.status === 'failed'
        ? `${schoolNow(props.timezone, new Date(), 24 * 60).slice(0, 10)}T08:00`
        : row.whenLocal;
};

const save = (row: ScheduledRow) => {
    emit('save', row, {
        heading: draft.heading.trim(),
        body: draft.body.trim(),
        sendAt: draft.at !== row.whenLocal ? draft.at : null,
    });
    editingKey.value = null;
};

const confirm = (row: ScheduledRow) => {
    confirmingKey.value = null;
    emit('cancel', row);
};
</script>
