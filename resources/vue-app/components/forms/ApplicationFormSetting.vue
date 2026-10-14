<template>
    <section class="border rounded p-3 mb-3">
        <h3 class="h6">Applications</h3>
        <p v-if="error" class="text-danger" role="alert">{{ error }}</p>
        <label><input type="checkbox" v-model="application" :disabled="busy || !loaded" /> Treat responses to this form as school applications</label>
        <button type="button" class="btn btn-sm btn-outline-primary ms-2" :disabled="busy || !loaded" @click="save">Save application setting</button>
        <p class="small text-muted mb-0">The office can accept, wait-list or decline each response under Applications.</p>
    </section>
</template>
<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
const props = defineProps<{ masjidId: string; formId: number }>();
const emit = defineEmits(['changed']);
const application = ref(false); const loaded = ref(false); const busy = ref(false); const error = ref(''); let generation = 0; let alive = true;
const url = () => `/api/admin/masjids/${props.masjidId}/applications/forms/${props.formId}` as const;
async function load() { const run = ++generation; loaded.value = false; error.value = ''; try { const r = await ApiService.get(url()); if (alive && run === generation) { application.value = r.data.data.application; loaded.value = true; } } catch { if (alive && run === generation) error.value = 'The application setting could not be loaded.'; } }
async function save() { if (busy.value) return; const run = generation; busy.value = true; error.value = ''; try { const r = await ApiService.put(url(), { application: application.value }); if (alive && run === generation) { application.value = r.data.data.application; emit('changed'); } } catch { if (alive && run === generation) error.value = 'The application setting could not be saved.'; } finally { if (alive && run === generation) busy.value = false; } }
watch(() => [props.masjidId, props.formId], load, { immediate: true }); onBeforeUnmount(() => { alive = false; ++generation; });
</script>
