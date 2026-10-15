<template>
    <section class="card"><div class="card-body">
        <h1 class="h4">Applications</h1>
        <p>Choose application forms in each form's settings. Waiting-list positions use the original arrival time within each grade.</p>
        <details class="mb-3"><summary>Choose application forms</summary>
            <label>Form<select class="form-select" v-model="settingForm"><option value="">Choose form</option><option v-for="form in forms" :key="form.id" :value="form.id">{{ form.name }}</option></select></label>
            <ApplicationFormSetting v-if="settingForm" :masjid-id="school" :form-id="Number(settingForm)" @changed="load" />
        </details>
        <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p><p v-if="message" role="status">{{ message }}</p>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <label>Grade<select v-model="grade" class="form-select"><option value="">All grades</option><option v-for="g in grades" :key="g" :value="g">{{ g || 'No grade' }}</option></select></label>
            <label>Status<select v-model="status" class="form-select"><option value="">All statuses</option><option value="new">New</option><option value="accepted">Accepted</option><option value="wait_listed">Wait-listed</option><option value="declined">Declined</option></select></label>
            <button type="button" class="btn btn-outline-secondary" @click="load">Refresh</button>
        </div>
        <div class="table-responsive"><table class="table"><thead><tr><th>Child / grade</th><th>Form / submitted</th><th>Status / waiting-list place</th><th>Office action</th></tr></thead><tbody>
            <tr v-for="child in filtered" :key="`${child.response_id}:${child.entry_index}`">
                <td>{{ child.first_name }} {{ child.last_name }}<small class="d-block">{{ child.grade }}</small></td>
                <td>{{ child.form_name }}<small class="d-block">{{ child.submitted_at }}</small></td>
                <td>{{ label(child.status) }}<span v-if="child.waitlist_position"> · {{ child.waitlist_position }}</span></td>
                <td><button class="btn btn-sm btn-outline-primary" @click="selected = child.response_id">Status and email history</button>
                    <p v-if="child.roster_class" class="mt-2 mb-0">Added to {{ child.roster_class }}</p>
                    <div v-else-if="child.status === 'accepted'" class="d-flex flex-wrap gap-1 mt-2">
                        <select class="form-select" :aria-label="`Class for ${child.first_name} ${child.last_name}`" v-model="classChoices[`${child.response_id}:${child.entry_index}`]"><option value="">Choose class</option><option v-for="c in data.classes" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                        <button class="btn btn-sm btn-outline-primary" :disabled="busy || !classChoices[`${child.response_id}:${child.entry_index}`]" @click="add(child)">Add to roster with guardians</button>
                    </div>
                </td>
            </tr>
        </tbody></table></div>
        <p v-if="!data.children.length" class="text-muted">No responses to application forms yet.</p>
        <ApplicationStatusControl v-if="selected" :masjid-id="school" :response-id="selected" @changed="load" />
        <form @submit.prevent="saveTexts" class="border rounded p-3 mt-4">
            <h2 class="h6">Messages to families</h2><p>Plain text. Safe placeholders: {school} and {children}. Staff notes are kept out of every email. These messages apply to future status changes.</p>
            <label v-for="s in ['accepted', 'wait_listed', 'declined']" :key="s" class="d-block form-label">{{ label(s) }}<textarea class="form-control" v-model="texts[`${s}_text`]" maxlength="8000" required /></label>
            <button class="btn btn-primary" :disabled="busy">Save messages</button>
        </form>
    </div></section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
import { serverMessage } from '@/core/helpers/serverMessage';
import { useMasjidStore } from '@/stores/masjidStore';
import ApplicationFormSetting from '@/components/forms/ApplicationFormSetting.vue';
import ApplicationStatusControl from '@/components/forms/ApplicationStatusControl.vue';
const store = useMasjidStore(); const school = computed(() => String(store.masjid?.id ?? ''));
const forms = ref<any[]>([]); const settingForm = ref<number | string>('');
const data = ref<any>({ children: [], classes: [], settings: {} }); const texts = ref<any>({}); const selected = ref<number | null>(null); const status = ref(''); const grade = ref(''); const error = ref(''); const message = ref(''); const busy = ref(false); const classChoices = ref<Record<string, any>>({});
const grades = computed(() => [...new Set<string>(data.value.children.map((c: any) => c.grade || ''))]);
const filtered = computed(() => data.value.children.filter((c: any) => (!status.value || status.value === c.status) && (!grade.value || c.grade === grade.value)));
const label = (s: string) => ({ new: 'New', accepted: 'Accepted', wait_listed: 'Wait-listed', declined: 'Declined' }[s] ?? s);
const base = () => `/api/admin/masjids/${school.value}/applications` as const; let generation = 0; let alive = true;
async function load() { const run = ++generation; error.value = ''; if (!school.value) return; try { const r = await ApiService.get(base()); if (alive && run === generation) { data.value = r.data.data; texts.value = { ...data.value.settings }; const options = await ApiService.get(`/api/admin/masjids/${school.value}/forms/options`); if (alive && run === generation) forms.value = Array.isArray(options.data.data) ? options.data.data : []; } } catch { if (alive && run === generation) error.value = 'Applications could not be loaded.'; } }
async function saveTexts() { if (busy.value) return; busy.value = true; error.value = ''; const run = generation; const payload = Object.fromEntries(['accepted_text', 'wait_listed_text', 'declined_text'].map(k => [k, texts.value[k]])); try { await ApiService.put(`${base()}/settings`, payload); if (alive && run === generation) message.value = 'Messages saved.'; } catch (e: any) { if (alive && run === generation) error.value = serverMessage(e, 'The messages could not be saved. Use only the two supported placeholders.'); } finally { busy.value = false; } }
async function add(child: any) { if (busy.value) return; busy.value = true; error.value = ''; message.value = ''; const run = generation; const url = `${base()}/${child.response_id}/roster` as const; const payload = { entry_index: child.entry_index, group_id: classChoices.value[`${child.response_id}:${child.entry_index}`] }; try { const r = await ApiService.post(url, payload); if (alive && run === generation) { child.roster_class = r.data.data.roster_class || data.value.classes.find((c: any) => String(c.id) === String(payload.group_id))?.name; message.value = r.data.data.message; } } catch (e: any) { if (alive && run === generation) error.value = Object.values(e.response?.data?.errors ?? {}).flat().join(' ') || serverMessage(e, 'The child could not be added.'); } finally { busy.value = false; } }
watch(school, () => { data.value = { children: [], classes: [], settings: {} }; selected.value = null; settingForm.value = ''; forms.value = []; texts.value = {}; classChoices.value = {}; message.value = ''; status.value = ''; grade.value = ''; void load(); }, { immediate: true });
onBeforeUnmount(() => { alive = false; ++generation; });
</script>
