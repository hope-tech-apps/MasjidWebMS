<template>
    <!--
        "Earlier versions" of an edited message, for the OFFICE only (W7,
        2026-10-01). The history is the office's audit: no teacher and no family
        screen shows it, and the server refuses it unless the viewer may read the
        conversation. Loaded on demand, when the disclosure is opened.
    -->
    <div class="message-edit-history mt-1">
        <button type="button" class="btn btn-link btn-sm p-0 small text-muted" :aria-expanded="open ? 'true' : 'false'"
                aria-label="Show earlier versions of this message" @click="toggle">
            {{ open ? 'Hide earlier versions' : 'Earlier versions' }}
        </button>

        <div v-if="open" class="border-start ps-2 mt-1 small">
            <div v-if="loading" class="text-muted">Loading&hellip;</div>
            <div v-else-if="error" class="text-danger" role="alert">{{ error }}</div>
            <div v-else-if="!rows.length" class="text-muted">No earlier versions.</div>
            <div v-for="(row, index) in rows" :key="row.id" class="mb-2">
                <div class="text-muted">
                    {{ versionLabel(index) }}<template v-if="row.edited_by"> &middot; replaced by {{ row.edited_by }}</template><template v-if="row.replaced_at"> &middot; {{ stamp(row.replaced_at) }}</template>
                </div>
                <div class="message-body" dir="auto" style="white-space: pre-wrap;">{{ row.previous_body }}</div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';
import { versionLabel, type MessageEditRow } from '@/core/helpers/messageEdit';
import { serverMessage } from '@/core/helpers/serverMessage';

const props = defineProps<{
    /** Fetches the earlier versions, oldest first. */
    load: () => Promise<MessageEditRow[]>;
    /** The message's `edited_at`: a newer edit makes an already-loaded list stale. */
    editedAt?: string | null;
}>();

const open = ref(false);
const loading = ref(false);
const error = ref('');
const rows = ref<MessageEditRow[]>([]);

const stamp = (iso: string) => {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
};

const fetchRows = async () => {
    loading.value = true;
    error.value = '';
    try {
        rows.value = await props.load();
    } catch (e) {
        error.value = serverMessage(e, 'The earlier versions could not be loaded.');
    } finally {
        loading.value = false;
    }
};

const toggle = async () => {
    open.value = !open.value;
    if (open.value) await fetchRows();
};

// Edited again while open: show the new chain rather than a stale one.
watch(() => props.editedAt, () => {
    if (open.value) void fetchRows();
});
</script>
