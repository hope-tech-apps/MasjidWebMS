<template>
    <details class="card card-body mb-3"><summary class="fw-semibold">Student accounts</summary>
        <p class="small mt-2">Students use the school’s usernames and passwords at <a :href="`/student/${schoolId}`" target="_blank" rel="noopener noreferrer">the student portal</a>. Keep printed slips private.</p>
        <p v-if="error" class="alert alert-warning" role="alert">{{ error }}</p><p v-if="message" class="text-success" role="status">{{ message }}</p>
        <form class="d-flex gap-2 align-items-end mb-3" @submit.prevent="saveSettings"><label>Students sign in from grade<select v-model="fromGrade" class="form-select"><option v-for="g in grades" :key="g">{{ g }}</option></select></label><button class="btn btn-outline-success" :disabled="busy">Save starting grade</button></form>
        <p class="small">Resetting signs the student out and replaces their password. The new password is shown only once. They must change it at first sign-in.</p>
        <div v-for="row in rows" :key="row.contact_id" class="border-top py-2"><div class="fw-semibold">{{ row.name }} · {{ row.grade }}</div><p class="small text-muted mb-1">{{ row.account.enabled ? 'Account on' : (row.account.has_account ? 'Account off' : 'No account') }} · Last sign-in: {{ row.account.last_sign_in_label ?? 'Never' }}</p>
            <p v-if="!row.eligible" class="small">Check the current class and starting grade before issuing an account.</p>
            <button v-if="!row.eligible && row.account.enabled" type="button" class="btn btn-sm btn-outline-danger mb-2" :disabled="busy" @click="disable(row)">Turn off</button>
            <form v-if="row.eligible" class="d-flex flex-wrap gap-2" @submit.prevent="reset(row)"><label class="flex-grow-1"><span class="visually-hidden">Username</span><input v-model="row.username" :aria-label="`Username for ${row.name}`" class="form-control" required minlength="3" maxlength="40" pattern="[A-Za-z0-9._-]+" autocomplete="off"></label><button class="btn btn-outline-success" :disabled="busy">{{ row.account.has_account ? 'Reset password' : 'Create account' }}</button><button v-if="row.account.enabled" type="button" class="btn btn-outline-danger" :disabled="busy" @click="disable(row)">Turn off</button></form>
        </div>
        <label class="small mt-3"><input v-model="confirmSlips" type="checkbox"> Reset all eligible students in this class and prepare new slips ({{ eligibleCount }})</label><button class="btn btn-outline-success mt-2" :disabled="busy || !confirmSlips || !eligibleCount" @click="makeSlips">Make class slips</button>
        <section v-if="slips.length" class="student-slip-preview mt-3"><p>Print or copy these passwords now. Closing this box removes them from this screen.</p><div v-for="s in slips" :key="s.username" class="student-slip border p-3 my-2"><strong>{{ s.name }}</strong><p class="mb-0">Student portal: {{ portalUrl }}<br>Username: {{ s.username }}<br>One-time password: <code>{{ s.one_time_password }}</code><br>Change this password when you first sign in.</p></div><div class="d-flex gap-2"><button class="btn btn-success" @click="printSlips">Print slips</button><button class="btn btn-outline-secondary" @click="slips=[]">Done with passwords</button></div></section>
    </details>
</template>
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import Api from '@/core/services/ApiService';
import { apiErrorText } from '@/core/services/ApiErrors';
const props=defineProps<{schoolId:number;groupId:number}>();
const base=computed(()=>`/api/admin/masjids/${props.schoolId}/student-sign-in`);
const rows=ref<any[]>([]),slips=ref<any[]>([]),grades=ref<string[]>([]),fromGrade=ref('6th'),error=ref(''),message=ref(''),busy=ref(false),confirmSlips=ref(false);
const eligibleCount=computed(()=>rows.value.filter(r=>r.eligible).length);
const portalUrl=typeof window!=='undefined'?`${window.location?.origin ?? ''}/student/${props.schoolId}`:`/student/${props.schoolId}`;
async function run(fn:()=>Promise<void>){busy.value=true;error.value='';message.value='';try{await fn();}catch(e){error.value=apiErrorText(e)||'Could not save student accounts.';}finally{busy.value=false;}}
async function load(){const [a,s]=await Promise.all([Api.get(`${base.value}/groups/${props.groupId}/accounts` as any),Api.get(`${base.value}/settings` as any)]);rows.value=(a.data.data ?? []).map((r:any)=>({...r,username:r.account.username ?? ''}));grades.value=s.data.data.grades;fromGrade.value=s.data.data.from_grade;}
async function reset(row:any){slips.value=[];await run(async()=>{const r=await Api.put(`${base.value}/accounts/${row.contact_id}` as any,{username:row.username},'json');slips.value=[r.data.data];await load();});}
async function disable(row:any){await run(async()=>{await Api.delete(`${base.value}/accounts/${row.contact_id}` as any);message.value='Account turned off.';await load();});}
async function saveSettings(){await run(async()=>{await Api.put(`${base.value}/settings` as any,{from_grade:fromGrade.value},'json');message.value='Starting grade saved. Existing student sessions were signed out.';await load();});}
async function makeSlips(){slips.value=[];await run(async()=>{const r=await Api.post(`${base.value}/groups/${props.groupId}/slips` as any,{contact_ids:rows.value.filter(r=>r.eligible).map(r=>r.contact_id)});slips.value=r.data.data.slips;confirmSlips.value=false;await load();});}
function printSlips(){window.print();}
onMounted(()=>run(load));
</script>
<style>
@media print { body:has(.student-slip-preview) * { visibility:hidden; } body:has(.student-slip-preview) .student-slip, body:has(.student-slip-preview) .student-slip * { visibility:visible; } .student-slip-preview { position:absolute;top:0;left:0;width:100%; } .student-slip { break-inside:avoid; } }
</style>
