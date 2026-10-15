<template>
    <section class="card"><div class="card-body">
        <h1 class="h4">Re-enrolment</h1>
        <p>Families confirm each child for the next school year. Answers do not move children between classes.</p>
        <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p><p v-if="message" role="status">{{ message }}</p>
        <form @submit.prevent="saveCampaign" class="border rounded p-3 mb-3">
            <h2 class="h6">Open or update re-enrolment</h2>
            <div class="d-flex flex-wrap gap-2">
                <label>Target school year<select class="form-select" v-model="draft.school_year_id" required><option value="">Choose year</option><option v-for="year in data.years" :key="year.id" :value="year.id">{{ year.label }}</option></select></label>
                <label>Opens on<input class="form-control" type="date" v-model="draft.opens_on" required /></label>
                <label>Closes on<input class="form-control" type="date" v-model="draft.closes_on" required /></label>
                <label>Deposit form (optional)<select class="form-select" v-model="draft.deposit_form_id"><option value="">No deposit</option><option v-for="form in data.forms" :key="form.id" :value="form.id">{{ form.name }}{{ form.takes_payment ? '' : ' (no payment)' }}</option></select></label>
            </div>
            <p class="small text-muted">Create the target year in School Calendar first. A form without payment shows no deposit. Dates include the opening and closing day in the school's timezone.</p>
            <button class="btn btn-primary" :disabled="busy">Save dates</button>
        </form>
        <label>View year<select class="form-select mb-3" v-model="selected" @change="selectCampaign"><option v-for="campaign in data.campaigns" :key="campaign.id" :value="campaign.id">{{ campaign.year }}</option></select></label>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <label>Class<select v-model="classFilter" class="form-select"><option value="">All classes</option><option v-for="c in classes" :key="c.id" :value="String(c.id)">{{ c.name }}</option></select></label>
            <label>Grade<select v-model="gradeFilter" class="form-select"><option value="">All grades</option><option v-for="grade in grades" :key="grade" :value="grade">{{ grade || 'No grade' }}</option></select></label>
            <label>Answer<select v-model="answerFilter" class="form-select"><option value="">All answers</option><option value="returning">Returning</option><option value="not_returning">Not returning</option><option value="unanswered">No answer yet</option></select></label>
            <label>Deposit<select v-model="depositFilter" class="form-select"><option value="">All deposits</option><option value="paid">Paid</option><option value="unpaid">Not paid</option></select></label>
        </div>
        <p class="small">Reminders go only to confirmed guardians with email and no answer for this year, one email listing each guardian's children. There are no automatic reminders.</p>
        <button class="btn btn-outline-primary mb-3" type="button" :disabled="busy || !selected || data.open === false || !data.reminder_count" @click="remind">Email unanswered families</button>
        <p v-if="data.last_reminder" class="small">Last reminder: {{ data.last_reminder.at }} · {{ data.last_reminder.count }} families emailed.</p>
        <div class="table-responsive"><table class="table"><thead><tr><th>Child / class</th><th>Current / next grade</th><th>Answer</th><th>Deposit</th><th>Record paper / phone answer</th></tr></thead><tbody>
            <tr v-for="child in filtered" :key="child.student_contact_id">
                <td>{{ child.name }}<small class="d-block">{{ child.classes.map((c: any) => c.name).join(', ') }}</small></td>
                <td>{{ child.grade }}<input :aria-label="`Next grade for ${child.name}`" class="form-control" v-model="child.next_grade" maxlength="80" placeholder="Choose next year's grade" /><button class="btn btn-link btn-sm" :disabled="busy" @click="paper(child)">Save grade</button></td>
                <td>{{ answerLabel(child.answer) }}<small v-if="child.answered_at" class="d-block">{{ child.recorded_by_office ? 'Recorded by the office' : child.answered_by }} · {{ child.answered_at }}</small></td>
                <td>{{ child.answer === 'returning' && child.deposit ? (child.deposit.paid ? 'Paid' : 'Not paid') : '—' }}</td>
                <td><button class="btn btn-sm btn-outline-primary me-1" :disabled="busy" @click="paper(child, 'returning')">Returning</button><button class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="paper(child, 'not_returning')">Not returning</button></td>
            </tr>
        </tbody></table></div>
        <h2 class="h6 mt-3">Reminder delivery</h2><button class="btn btn-link btn-sm" @click="selectCampaign">Refresh delivery status</button>
        <ul><li v-for="mail in data.mails" :key="mail.id">{{ mail.guardian_email }}: {{ mail.status }} · {{ mail.sent_at || mail.failed_at || mail.queued_at }}</li></ul>
    </div></section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
import { serverMessage } from '@/core/helpers/serverMessage';
import { useMasjidStore } from '@/stores/masjidStore';
const store = useMasjidStore(); const school = computed(() => String(store.masjid?.id ?? ''));
const data = ref<any>({ campaigns: [], years: [], forms: [], children: [], mails: [] });
const selected = ref<number | string>(''); const error = ref(''); const message = ref(''); const busy = ref(false);
const draft = ref<any>({ school_year_id: '', opens_on: '', closes_on: '', deposit_form_id: '' });
const classFilter = ref(''); const gradeFilter = ref(''); const answerFilter = ref(''); const depositFilter = ref('');
const classes = computed<any[]>(() => [...new Map(data.value.children.flatMap((c: any) => c.classes).map((c: any) => [c.id, c])).values()]);
const grades = computed(() => [...new Set<string>(data.value.children.map((c: any) => c.grade || ''))]);
const filtered = computed(() => data.value.children.filter((c: any) => (!classFilter.value || c.classes.some((group: any) => String(group.id) === classFilter.value)) && (!gradeFilter.value || c.grade === gradeFilter.value) && (!answerFilter.value || (answerFilter.value === 'unanswered' ? !c.answer : c.answer === answerFilter.value)) && (!depositFilter.value || (c.answer === 'returning' && c.deposit && c.deposit.paid === (depositFilter.value === 'paid')))));
const base = () => `/api/admin/masjids/${school.value}/reenrolment` as const;
const answerLabel = (answer: string) => answer === 'returning' ? 'Returning' : answer === 'not_returning' ? 'Not returning' : 'No answer yet';
let generation = 0; let alive = true;
function editCampaign() { const c = data.value.campaigns.find((c: any) => Number(c.id) === Number(selected.value)); if (c) draft.value = { school_year_id: c.school_year_id, opens_on: c.opens_on, closes_on: c.closes_on, deposit_form_id: c.deposit_form_id ?? '' }; }
async function load() { const run = ++generation; data.value = { campaigns: [], years: [], forms: [], children: [], mails: [] }; selected.value = ''; error.value = ''; message.value = ''; if (!school.value) return;
    try { const r = await ApiService.get(base()); if (alive && run === generation) { data.value = r.data.data; selected.value = data.value.campaigns[0]?.id ?? ''; editCampaign(); } } catch { if (alive && run === generation) error.value = 'Re-enrolment could not be loaded.'; }
}
async function selectCampaign() { if (!selected.value) return; const run = ++generation; const target = `${base()}/${selected.value}` as const; error.value = ''; try { const r = await ApiService.get(target); if (alive && run === generation) { data.value = { ...data.value, ...r.data.data }; editCampaign(); } } catch { if (alive && run === generation) error.value = 'This year could not be loaded.'; } }
async function act(work: () => Promise<any>, success: string) { if (busy.value) return; busy.value = true; error.value = ''; message.value = ''; const run = generation; const campaign = selected.value; try { await work(); if (alive && run === generation) { message.value = success; await load(); if (alive && campaign && data.value.campaigns.some((c: any) => String(c.id) === String(campaign))) { selected.value = campaign; await selectCampaign(); } message.value = success; } } catch (e: any) { if (alive && run === generation) error.value = serverMessage(e, 'The change could not be saved. Check the dates and try again.'); } finally { busy.value = false; } }
async function saveCampaign() { const target = base(); const payload = { ...draft.value, deposit_form_id: draft.value.deposit_form_id || null }; await act(() => ApiService.put(target, payload), 'Re-enrolment dates saved.'); }
async function paper(child: any, answer?: string) { const target = `${base()}/${selected.value}/children/${child.student_contact_id}` as const; await act(() => ApiService.put(target, { next_grade: child.next_grade, ...(answer ? { answer } : {}) }), 'Office record saved.'); }
async function remind() { if (busy.value || !data.value.reminder_count || !confirm(`Email ${data.value.reminder_count} unanswered families?`)) return; const target = `${base()}/${selected.value}/reminders` as const; let queued = 0; await act(async () => { const r = await ApiService.post(target, {}); queued = r.data.data.queued; }, 'Reminder request recorded.'); if (!error.value) message.value = `${queued} email(s) queued.`; }
watch(school, load, { immediate: true }); onBeforeUnmount(() => { alive = false; ++generation; });
</script>
