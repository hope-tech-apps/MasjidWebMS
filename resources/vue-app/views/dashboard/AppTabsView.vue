<template>
    <div class="app-tabs-page mw-100">
        <div v-if="enabled || volunteerEnabled" class="card">
            <div class="card-body">
                <h1 class="h3">App tabs</h1>
                <AppVolunteerField v-if="volunteerEnabled && orgId" :org-id="orgId" @updated="refreshFeatures" />
                <div v-if="enabled">
                <p>Home is always first. Choose up to four more. Everything else stays in the app's side menu. Most apps put Menu last.</p>
                <p class="text-muted">The change reaches phones when people next open the app, on app versions that support it.</p>
                <p v-if="loading" role="status">Loading app features…</p>
                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    {{ loadError }} <button type="button" class="btn btn-outline-secondary" @click="load">Try again</button>
                </div>
                <div v-else>
                    <div class="app-tabs-phone border rounded-4 bg-body-tertiary p-3 mb-3" aria-label="App tab preview">
                        <p class="small text-muted">Your app</p>
                        <div class="app-tabs-strip border-top pt-3" role="list" aria-label="Bottom tabs, in order">
                            <div data-tab-preview class="app-tabs-preview text-body" role="listitem"><span class="d-block fw-semibold">Home</span><small>Locked</small></div>
                            <div v-for="tab in validChosen" :key="tab.feature_id" data-tab-preview class="app-tabs-preview text-body" role="listitem" dir="auto">{{ tab.name }}</div>
                        </div>
                    </div>
                    <p v-if="validChosen.length === 0" class="text-muted">Your app is using its standard tabs.</p>
                    <p v-if="hasUnavailable" class="text-warning-emphasis">Unavailable choices are left out on phones. Saving removes them from this list.</p>
                    <p v-if="dirty" class="text-warning-emphasis" role="status">Unsaved changes</p>
                    <p v-if="saved && !dirty" class="text-success" role="status">Saved.</p>
                    <fieldset :disabled="saving" class="border-0 p-0 m-0">
                        <legend class="h5">Chosen tabs</legend>
                        <ol v-if="chosen.length" class="app-tabs-choices p-0">
                            <li v-for="(tab, index) in chosen" :key="choiceKey(tab)" class="app-tabs-choice border rounded p-3 mb-2">
                                <div class="app-tabs-label"><span class="fw-semibold" dir="auto">{{ tab.name }}</span><small v-if="!tab.available" class="d-block text-warning-emphasis">no longer available</small></div>
                                <div class="app-tabs-actions d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-outline-secondary" :disabled="index === 0" :aria-label="`Move ${tab.name} up`" @click="move(index, -1)">Up</button>
                                    <button type="button" class="btn btn-outline-secondary" :disabled="index === chosen.length - 1" :aria-label="`Move ${tab.name} down`" @click="move(index, 1)">Down</button>
                                    <button type="button" class="btn btn-outline-danger" :aria-label="`Remove ${tab.name}`" @click="remove(index)">Remove</button>
                                </div>
                            </li>
                        </ol>
                        <h2 class="h5 mt-4">Switched-on app features</h2>
                        <p v-if="chosen.length >= 4" class="text-warning-emphasis" role="status">You can choose up to four tabs after Home. Remove a tab before adding another.</p>
                        <ul class="list-unstyled">
                            <li v-for="feature in unchosen" :key="feature.id" class="app-tabs-choice border rounded p-3 mb-2">
                                <span class="app-tabs-label" dir="auto">{{ feature.name }}</span>
                                <button type="button" class="btn btn-outline-primary" :disabled="chosen.length >= 4"  :aria-label="`Add ${feature.name} to tabs`" @click="add(feature)">Add to tabs</button>
                            </li>
                        </ul>
                        <p v-if="features.length === 0" class="text-muted">There are no switched-on app features to choose from.</p>
                    </fieldset>
                    <p v-if="saveError" class="alert alert-danger" role="alert">Not saved. {{ saveError }}</p>
                    <button type="button" class="btn btn-success" :disabled="saving || (!dirty && !hasUnavailable)" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
                </div>
            </div>
        </div>
        <p v-else class="text-muted" role="status">App tabs are not available for this organisation.</p>
    </div>
</template>

<script setup lang="ts">
import AppVolunteerField from '@/components/contacts/AppVolunteerField.vue';
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { registerUnsavedChanges, confirmUnsavedChanges } from '@/core/tenancy/unsavedChanges';
import { onBeforeRouteLeave } from 'vue-router';
import Swal from 'sweetalert2';
import ApiService from '@/core/services/ApiService';
import type { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';

type Feature = { id: number | 'volunteer' | 'menu'; key: string; name: string };
type Choice = { id?: number; feature_id: number | 'volunteer' | 'menu' | null; key: string; name: string; available: boolean };
type Editor = { features: Feature[]; chosen: Choice[] };
const auth = useAuthStore();
const org = useMasjidStore();
const orgId = computed(() => auth.dashboardMasjidId ?? org.masjid?.id);
const volunteerEnabled = computed(() => org.masjid?.capabilities?.app_volunteer_entry === true);
const enabled = computed(() => org.masjid?.capabilities?.app_bottom_tabs === true);
const features = ref<Feature[]>([]);
const chosen = ref<Choice[]>([]);
const baseline = ref('[]');
const loading = ref(false);
const saving = ref(false);
const loadError = ref('');
const saveError = ref('');
const saved = ref(false);
let epoch = 0;
const choiceKey = (tab: Choice) => tab.feature_id ?? `retired-${tab.id}`;
const fingerprint = () => JSON.stringify(chosen.value.map(choiceKey));
const dirty = computed(() => fingerprint() !== baseline.value);
const validChosen = computed(() => chosen.value.filter(tab => tab.available && tab.feature_id !== null));
const hasUnavailable = computed(() => chosen.value.some(tab => !tab.available));
const unchosen = computed(() => features.value.filter(feature => !chosen.value.some(tab => tab.feature_id === feature.id)));
const url = (): BackendApiRoute => `/api/admin/masjids/${orgId.value}/app-bottom-tabs`;
function adopt(data: Editor) {
    features.value = data.features;
    chosen.value = data.chosen.map(tab => ({ ...tab }));
    baseline.value = fingerprint();
}
function errorText(error: any, fallback: string) {
    const data = error?.response?.data;
    const field = data?.data?.feature_ids;
    return (Array.isArray(field) ? field[0] : undefined) ?? data?.message ?? fallback;
}
async function load() {
    if (!enabled.value || !orgId.value) return;
    const current = ++epoch;
    loading.value = true; loadError.value = ''; saveError.value = ''; saved.value = false;
    try {
        const response = await ApiService.get(url());
        if (current === epoch) adopt(response.data.data);
    } catch (error) {
        if (current === epoch) loadError.value = errorText(error, 'App features could not be loaded.');
    } finally { if (current === epoch) loading.value = false; }
}
async function refreshFeatures() {
    if (!enabled.value) return;
    const current=epoch;
    try {
        const response=await ApiService.get(url());
        if (current!==epoch) return;
        features.value=response.data.data.features;
        chosen.value=chosen.value.map(tab=>{
            const live=features.value.find(feature=>feature.id===tab.feature_id);
            return {...tab,available:!!live,name:live?.name??tab.name,key:live?.key??tab.key};
        });
    } catch { loadError.value='App features could not be refreshed. Reload before choosing Volunteer.'; }
}
function changed() { saved.value = false; saveError.value = ''; }
function add(feature: Feature) {
    if (saving.value || chosen.value.length >= 4 || chosen.value.some(tab => tab.feature_id === feature.id)) return;
    chosen.value.push({ feature_id: feature.id, key: feature.key, name: feature.name, available: true }); changed();
}
function move(index: number, step: number) {
    const next = index + step;
    if (saving.value || next < 0 || next >= chosen.value.length) return;
    const tabs = [...chosen.value]; [tabs[index], tabs[next]] = [tabs[next], tabs[index]];
    chosen.value = tabs; changed();
}
function remove(index: number) {
    if (saving.value) return;
    chosen.value.splice(index, 1); changed();
}
async function save() {
    if (saving.value || !enabled.value || (!dirty.value && !hasUnavailable.value)) return;
    const current = epoch;
    saving.value = true; saveError.value = ''; saved.value = false;
    try {
        const response = await ApiService.put(url(), { feature_ids: validChosen.value.map(tab => tab.feature_id) }, 'url', { 'Content-Type': 'application/json' });
        if (current === epoch) { adopt(response.data.data); saved.value = true; }
    } catch (error) {
        if (current === epoch) saveError.value = errorText(error, 'Your changes could not be saved. Try again.');
    } finally { if (current === epoch) saving.value = false; }
}
watch([orgId, enabled], () => {
    ++epoch; features.value = []; chosen.value = []; baseline.value = '[]'; loading.value = false; saving.value = false;
    if (enabled.value) void load();
}, { immediate: true });
async function confirmLeave() {
    if (!dirty.value) return true;
    const answer = await Swal.fire({ title: 'Leave without saving?', text: 'Your app tab changes have not been saved.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Leave', cancelButtonText: 'Keep editing' });
    return answer.isConfirmed;
}
onBeforeRouteLeave(confirmUnsavedChanges);
const unregister = registerUnsavedChanges(confirmLeave);
function beforeUnload(event: BeforeUnloadEvent) {
    if (!dirty.value) return;
    event.preventDefault(); event.returnValue = '';
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => { unregister(); ++epoch; window.removeEventListener('beforeunload', beforeUnload); });
</script>

<style scoped>
.app-tabs-page, .app-tabs-label, .app-tabs-preview { min-inline-size: 0; overflow-wrap: anywhere; }
.app-tabs-phone { inline-size: 390px; max-inline-size: 100%; min-block-size: 180px; }
.app-tabs-strip { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .4rem; }
.app-tabs-preview { text-align: center; font-size: .8rem; }
.app-tabs-choices { list-style-position: inside; }
.app-tabs-choice { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
.app-tabs-label { flex: 1 1 8rem; }
.app-tabs-actions { max-inline-size: 100%; }
.app-tabs-page button { min-block-size: 44px; }
</style>
