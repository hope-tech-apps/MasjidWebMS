<template>
    <div class="mt-4">
        <label :for="`student-allergies-${membershipId}`" class="form-label">Allergies or special needs</label>
        <p v-if="loading" class="small text-muted" role="status">Loading the record...</p>
        <div v-else>
            <textarea :id="`student-allergies-${membershipId}`" v-model="answer" class="form-control" rows="3" :disabled="saving || !loaded" />
            <p class="small mt-1" :class="answerLength > 500 ? 'text-danger' : 'text-muted'" role="status">{{ answerLength }} / 500 characters</p>
            <p v-if="record?.unavailable" class="alert alert-warning small mt-2" role="alert">Allergy note unavailable: ask the office</p>
            <p v-if="record?.source" class="small text-muted mt-1">
                {{ record.source === 'form' ? 'from the enrolment form' : 'entered by the office' }}
                <span v-if="record.changed_at"> · {{ record.changed_at.slice(0, 10) }}</span>
            </p>
            <p class="small text-muted mt-1">Only the office and assigned class teachers see this. An empty answer clears the flag. Office edits win over later form copies.</p>
            <button type="button" class="btn btn-sm btn-primary" :disabled="saving || !loaded" @click="save">{{ saving ? 'Saving...' : 'Save allergies' }}</button>
        </div>
        <p v-if="error" class="alert alert-danger small mt-2" role="alert">{{ error }}</p>
        <p v-if="saved" class="small text-success mt-2" role="status">Allergies saved.</p>
    </div>
</template>

<script setup lang="ts">
import { onMounted, onBeforeUnmount, computed, ref } from 'vue';
import ApiService from '@/core/services/ApiService';
import type { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
const props = defineProps<{ masjidId: number; groupId: number; membershipId: number }>();
const emit = defineEmits<{ saved: [record: any] }>();
const answer = ref('');
const record = ref<any>(null);
const answerLength = computed(() => Array.from(answer.value).length);
const loading = ref(true), loaded = ref(false), saving = ref(false), saved = ref(false), error = ref('');
let active = true;
onBeforeUnmount(() => { active = false; });
const url = `/api/admin/masjids/${props.masjidId}/groups/${props.groupId}/members/${props.membershipId}/allergies` as BackendApiRoute;
onMounted(async () => {
    try {
        const response = await ApiService.get(url);
        if (!active) return;
        record.value = response.data.data; answer.value = record.value.allergies ?? ''; loaded.value = true;
    } catch { if (active) error.value = 'Could not load allergies. Close the panel and try again.'; }
    finally { if (active) loading.value = false; }
});
async function save() {
    if (saving.value || !loaded.value) return;
    if (answerLength.value > 500) {
        error.value = `This answer has ${answerLength.value} characters. Shorten it to 500 or fewer before saving.`;
        saved.value = false; return;
    }
    saving.value = true; error.value = ''; saved.value = false;
    try {
        const response = await ApiService.put(url, { allergies: answer.value });
        if (!active) return;
        record.value = response.data.data; answer.value = record.value.allergies ?? ''; saved.value = true;
        emit('saved', record.value);
    } catch { if (active) error.value = 'Could not save allergies. Keep the answer under 500 characters and try again.'; }
    finally { if (active) saving.value = false; }
}
</script>
