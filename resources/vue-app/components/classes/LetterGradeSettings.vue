<template>
    <section class="border rounded p-3 mt-3" aria-label="Letter grade settings">
        <h2 class="h5">Letter grades and term report cards</h2>
        <p v-if="error" class="text-danger small" role="alert">{{ error }}</p>
        <p v-if="!settings" class="small">Loading letter grades…</p>
        <template v-else>
            <p v-if="settings.setup_message" class="small">{{ settings.setup_message }}</p>
            <label class="d-block mb-3"><input v-model="settings.enabled" type="checkbox" :disabled="(!settings.can_enable && !settings.enabled) || saving"> Use letter grades</label>
            <label class="d-block mb-3">Letter grades from grade
                <select v-model="settings.from_grade" class="form-select" :disabled="saving">
                    <option v-for="grade in settings.grades" :key="grade" :value="grade">{{ grade }}</option>
                </select>
            </label>
            <p class="small text-muted">Younger students keep their 4-to-1 levels. Terms use the dates in the school calendar. Sent cards keep their saved grades when this scale changes.</p>
            <div v-for="(row, i) in settings.scale" :key="i" class="d-flex gap-2 mb-2 flex-wrap">
                <label>Letter <input v-model="row.letter" :aria-label="`Letter ${i + 1}`" class="form-control" maxlength="20" :disabled="saving"></label>
                <label>Lowest percentage <input v-model.number="row.minimum" :aria-label="`Lowest percentage ${i + 1}`" class="form-control" type="number" min="0" max="100" step="0.01" :disabled="saving"></label>
                <button type="button" class="btn btn-outline-secondary align-self-end" :disabled="saving" @click="settings.scale.splice(i, 1)">Remove row</button>
            </div>
            <p class="small text-muted">Keep rows in descending order, with unique letters and cut-offs. The lowest row starts at 0. Each cut-off includes that percentage.</p>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="settings.scale.push({ letter: '', minimum: 0 })">Add row</button>
                <button type="button" class="btn btn-primary" :disabled="saving" @click="save">Save letter grades</button>
                <span v-if="saved" role="status">Saved</span>
            </div>
        </template>
    </section>
</template>
<script setup lang="ts">
import { ref, onMounted, watch, onBeforeUnmount } from 'vue';
import ApiService from '@/core/services/ApiService';
const props = defineProps<{ schoolId: number }>();
const settings = ref<any>(null);
const saving = ref(false);
const saved = ref(false);
const error = ref('');
let generation = 0;
const url = () => `/api/admin/masjids/${props.schoolId}/letter-grade-settings`;
const load = async () => {
    const mine = ++generation; settings.value = null; error.value = ''; saved.value = false;
    try { const res = await ApiService.get(url()); if (mine === generation) settings.value = res.data.data; }
    catch { if (mine === generation) error.value = 'Could not load the letter grade settings.'; }
};
const save = async () => {
    const mine = generation; saving.value = true; saved.value = false; error.value = '';
    const body = { enabled: settings.value.enabled, from_grade: settings.value.from_grade, scale: settings.value.scale.map((row: any) => ({ letter: row.letter, minimum: row.minimum })) };
    try {
        const res = await ApiService.put(url(), body);
        if (mine !== generation) return;
        settings.value = { ...settings.value, ...res.data.data }; saved.value = true;
    } catch (e: any) {
        if (mine === generation) error.value = Object.values(e?.response?.data?.data ?? e?.response?.data?.errors ?? {}).flat().join(' ') || 'Could not save the letter grade settings.';
    } finally { if (mine === generation) saving.value = false; }
};
watch(() => props.schoolId, () => { saving.value = false; void load(); });
onMounted(load);
onBeforeUnmount(() => { generation++; });
</script>
