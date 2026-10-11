<template>
    <details class="mb-3 border rounded p-3">
        <summary class="fw-semibold">Copy allergies from a form</summary>
        <p class="small text-muted mt-2">Preview the matches first. Office edits, including clears, win. No answers are copied automatically.</p>
        <div class="d-flex flex-wrap align-items-end gap-2">
            <div class="copy-allergies-form">
                <label :for="`allergy-form-${masjidId}`" class="form-label small">Enrolment form</label>
                <select :id="`allergy-form-${masjidId}`" v-model="formId" class="form-select form-select-sm" :disabled="busy">
                    <option value="">Choose a form</option>
                    <option v-for="form in forms" :key="form.id" :value="String(form.id)">{{ form.name }}</option>
                </select>
            </div>
            <div class="copy-allergies-field">
                <label :for="`allergy-field-${masjidId}`" class="form-label small">Child field name</label>
                <input :id="`allergy-field-${masjidId}`" v-model="field" class="form-control form-control-sm" maxlength="100" :disabled="busy" />
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" :disabled="busy || !formId || !field.trim()" @click="run(true)">Preview</button>
        </div>
        <p v-if="error" class="alert alert-danger small mt-2" role="alert">{{ error }}</p>
        <div v-if="result" class="mt-3" role="status">
            <div class="d-flex flex-wrap gap-3 small">
                <span>Children read: {{ result.counts.children_read }}</span>
                <span>With an allergy: {{ result.counts.with_allergy }}</span>
                <span>Matched: {{ result.counts.matched }}</span>
                <span>Left unchanged: blank answer: {{ result.counts.blank_answer ?? 0 }}</span>
                <span>Too long: enter by hand: {{ result.counts.too_long ?? 0 }}</span>
                <span>Already set: {{ result.counts.already_set }}</span>
                <span>Unmatched: {{ result.counts.unmatched }}</span>
                <span>Ambiguous: {{ result.counts.ambiguous }}</span>
                <span>Office edits win: {{ result.counts.office_wins }}</span>
                <span v-if="result.counts.superseded">Earlier answers ignored: {{ result.counts.superseded }}</span>
                <span v-if="result.counts.empty">Empty answers: {{ result.counts.empty }}</span>
                <span v-if="result.counts.invalid">Invalid or too long: {{ result.counts.invalid }}</span>
            </div>
            <p class="small mt-2">{{ copied ? 'Copied' : 'Would copy' }}: {{ result.counts.written }}</p>
            <ul v-if="unresolved.length" class="small text-break">
                <li v-for="child in unresolved" :key="`${child.response_id}-${child.entry_index}`">{{ child.name }}: {{ child.outcome }} (response {{ child.response_id }}, child entry {{ child.entry_index + 1 }})</li>
            </ul>
            <p v-if="unresolved.length" class="small">Resolve these children by hand in Student details. No match is guessed.</p>
            <button v-if="!copied" type="button" class="btn btn-sm btn-primary" :disabled="busy" @click="run(false)">Copy now</button>
        </div>
    </details>
</template>

<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
import type { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
const props = defineProps<{ masjidId: number }>();
const emit = defineEmits<{ copied: [] }>();
const forms = ref<any[]>([]), formId = ref(''), field = ref('allergies'), result = ref<any>(null);
const busy = ref(false), copied = ref(false), error = ref('');
let active = true;
onBeforeUnmount(() => { active = false; });
watch([formId, field], () => { result.value = null; copied.value = false; });
const unresolved = computed(() => (result.value?.children ?? []).filter(c => ['unmatched', 'ambiguous', 'too_long', 'too long: enter by hand', 'invalid'].includes(c.outcome)));
onMounted(async () => {
    try {
        const response = await ApiService.get(`/api/admin/masjids/${props.masjidId}/students/allergy-forms` as BackendApiRoute);
        if (active) forms.value = response.data.data;
    } catch { if (active) error.value = 'Could not load forms.'; }
});
async function run(preview: boolean) {
    if (busy.value || !formId.value || !field.value.trim() || (!preview && !result.value)) return;
    busy.value = true; error.value = '';
    try {
        const response = await ApiService.post(`/api/admin/masjids/${props.masjidId}/students/copy-allergies` as BackendApiRoute, { form_id: Number(formId.value), field: field.value.trim(), dry_run: preview });
        if (!active) return;
        result.value = response.data.data; copied.value = !preview;
        if (!preview) emit('copied');
    } catch { if (active) error.value = 'Could not copy allergies. Check the selected form and child field, then try again.'; }
    finally { if (active) busy.value = false; }
}
</script>

<style scoped>
.copy-allergies-form { flex: 1 1 14rem; min-inline-size: 0; }
.copy-allergies-field { flex: 1 1 10rem; min-inline-size: 0; }
</style>
