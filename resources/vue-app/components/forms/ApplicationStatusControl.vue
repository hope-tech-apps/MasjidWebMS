<template>
    <section class="border rounded p-3 mb-3">
        <h3 class="h6">Application status</h3>
        <p v-if="applicationLabel" dir="auto">{{ applicationLabel }}</p>
        <p v-if="error" class="text-danger" role="alert">{{ error }}</p>
        <p v-if="!detail" role="status">Loading application…</p>
        <template v-else>
            <label class="form-label">Status
                <select class="form-select" v-model="status"><option value="new">New</option><option value="accepted">Accepted</option><option value="wait_listed">Wait-listed</option><option value="declined">Declined</option></select>
            </label>
            <label class="form-label d-block">Staff note (families will not see this)<textarea class="form-control" v-model="note" maxlength="4000" /></label>
            <label class="d-block mb-2"><input type="checkbox" v-model="withoutEmail" /> Mark without emailing (correction)</label>
            <p class="small text-muted">Changing to accepted, wait-listed or declined queues the school's message to the guardian emails on this response. Saving the same status sends nothing.</p>
            <button type="button" class="btn btn-primary" :disabled="busy" @click="save">Save status</button>
            <ul class="mt-3 mb-0">
                <li v-for="entry in detail.history" :key="entry.id">
                    {{ label(entry.status) }} · {{ entry.changed_by }} · {{ entry.changed_at }}
                    <p v-if="entry.note" class="mb-0">{{ entry.note }}</p>
                    <span v-if="!entry.emailed" class="small">Marked without emailing</span>
                    <span v-for="(mail, index) in entry.mails" :key="index" class="d-block small">{{ mail.guardian_email }}: {{ mail.status }} {{ mail.sent_at || mail.failed_at || '' }}</span>
                </li>
            </ul>
            <button type="button" class="btn btn-link btn-sm" @click="load">Refresh delivery status</button>
        </template>
    </section>
</template>
<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
import { serverMessage } from '@/core/helpers/serverMessage';
const props = defineProps<{ masjidId: string; responseId: number; applicationLabel?: string }>();
const emit = defineEmits(['changed']);
const detail = ref<any>(null); const status = ref('new'); const note = ref(''); const withoutEmail = ref(false); const busy = ref(false); const error = ref('');
let generation = 0; let alive = true;
const base = () => `/api/admin/masjids/${props.masjidId}/applications/${props.responseId}` as const;
const label = (value: string) => ({ new: 'New', accepted: 'Accepted', wait_listed: 'Wait-listed', declined: 'Declined' }[value] ?? value);
async function load() {
    const run = ++generation; detail.value = null; error.value = ''; note.value = ''; withoutEmail.value = false; busy.value = false;
    try { const r = await ApiService.get(base()); if (alive && run === generation) { detail.value = r.data.data; status.value = detail.value.status; } }
    catch (e: any) { if (alive && run === generation) error.value = serverMessage(e, 'The application could not be loaded.'); }
}
async function save() {
    if (busy.value) return; const run = generation; busy.value = true; error.value = '';
    try { const r = await ApiService.put(`${base()}/status`, { status: status.value, note: note.value, without_email: withoutEmail.value }); if (alive && run === generation) { detail.value = r.data.data; note.value = ''; emit('changed'); } }
    catch (e: any) { if (alive && run === generation) error.value = serverMessage(e, 'The status could not be saved. Check the guardian emails and try again.'); }
    finally { if (alive && run === generation) { busy.value = false; withoutEmail.value = false; } }
}
watch(() => [props.masjidId, props.responseId], load, { immediate: true });
onBeforeUnmount(() => { alive = false; ++generation; });
</script>
