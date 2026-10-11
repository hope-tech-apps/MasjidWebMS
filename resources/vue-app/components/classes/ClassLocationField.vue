<template>
    <form class="w-100 mt-2" @submit.prevent="save">
        <label for="class-location">Location (optional)</label>
        <p class="text-muted small mb-1">Pick a location or type a new name. Leave it empty to clear this class's location.</p>
        <div class="d-flex flex-wrap gap-2">
            <input id="class-location" v-model="name" list="class-location-options" maxlength="60" class="form-control" :disabled="loading || saving" />
            <datalist id="class-location-options"><option v-for="location in locations" :key="location.id" :value="location.name" /></datalist>
            <button type="submit" class="btn btn-outline-primary" :disabled="loading || saving || loadingFailed">{{ saving ? 'Saving…' : 'Save location' }}</button>
        </div>
        <p v-if="error" role="alert" class="text-danger">{{ error }}</p>
        <p v-if="saved" role="status">Location saved.</p>
        <button v-if="error && loadingFailed" type="button" class="btn btn-outline-secondary" @click="load">Retry location</button>
    </form>
</template>

<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue';
import ApiService from '@/core/services/ApiService';
import { apiErrorText } from '@/core/services/ApiErrors';
const props = defineProps<{ base: string }>();
const name = ref(''); const locations = ref<any[]>([]); const loading = ref(false); const saving = ref(false);
const error = ref(''); const saved = ref(false); const loadingFailed = ref(false); let generation = 0;
function apply(data: any) { name.value = data.location?.name ?? ''; locations.value = data.locations ?? []; }
async function load() {
    const run = ++generation; loading.value = true; loadingFailed.value = false; error.value = ''; saved.value = false; name.value = ''; locations.value = [];
    try { const r = await ApiService.get(`${props.base}/location` as any); if (run === generation) apply(r.data.data); }
    catch (e) { if (run === generation) { error.value = apiErrorText(e, 'The location could not be loaded.'); loadingFailed.value = true; } }
    finally { if (run === generation) loading.value = false; }
}
async function save() {
    if (saving.value || loading.value || loadingFailed.value) return;
    const run = generation; saving.value = true; error.value = ''; saved.value = false;
    try { const r = await ApiService.put(`${props.base}/location` as any, { name: name.value.trim() }); if (run === generation) { apply(r.data.data); saved.value = true; } }
    catch (e) { if (run === generation) error.value = apiErrorText(e, 'The location could not be saved.'); }
    finally { if (run === generation) saving.value = false; }
}
watch(() => props.base, () => { saving.value = false; load(); }, { immediate: true });
onBeforeUnmount(() => { ++generation; });
</script>
