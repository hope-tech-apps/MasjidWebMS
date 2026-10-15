<template>
    <div v-if="enabled" class="duty-rota card">
        <div class="card-body">
            <h1 class="h3">Duty rota</h1>
            <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
            <div v-if="result" class="alert alert-success" role="status">{{ result }}</div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <label class="flex-grow-1">Rota
                    <select v-model="selectedId" class="form-select" :disabled="busy" @change="choose"><option v-for="r in setup.rotas" :key="r.id" :value="r.id">{{ r.name }}</option></select>
                </label>
                <button class="btn btn-primary align-self-end" :disabled="busy || !setup.years.length" @click="edit(null)">Create rota</button>
                <button v-if="data" class="btn btn-outline-primary align-self-end" :disabled="busy" @click="edit(data.rota)">Edit {{ data.rota.name }}</button>
            </div>
            <p v-if="!setup.years.length && !busy" class="text-muted">Add a school year in School Calendar first.</p>
            <p v-if="busy" role="status">Working…</p>
            <div v-if="data">
                <p>{{ data.totals.families }} {{ plural(data.totals.families, 'family', 'families') }} · {{ data.totals.taken }} places taken / {{ data.totals.available }} places available for the rest of the year · {{ data.totals.needs }} {{ plural(data.totals.needs, 'family', 'families') }} needing a day</p>
                <p v-if="!data.rota.active" class="alert alert-warning">This rota is inactive. Private links and scheduled reminders are stopped.</p>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button v-if="data.rota.source_form_id" class="btn btn-outline-primary" :disabled="busy" @click="preview">Build from the form</button>
                    <button class="btn btn-outline-primary" :disabled="busy" @click="exportCsv">Export CSV</button>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Duty rota view">
                    <button v-for="t in ['By day','Needs a day','Mail history']" :key="t" role="tab" :aria-selected="tab === t" :class="['btn', tab === t ? 'btn-primary active' : 'btn-outline-primary']" @click="tab=t">{{ t }}</button>
                </div>
                <div v-if="tab === 'By day'" role="tabpanel">
                    <details v-if="earlier.length" class="mb-3"><summary class="fw-semibold">Earlier ({{ earlier.length }} {{ plural(earlier.length, 'day') }})</summary>
                        <section v-for="day in earlier" :key="day.date" class="border rounded p-3 mt-2">
                            <h2 class="h5">{{ formatDay(day.date) }} · {{ day.taken }} of {{ day.capacity }}</h2>
                            <div v-for="a in day.assignments" :key="a.id" class="duty-family border-top py-2">
                                <strong dir="auto">{{ a.name }}</strong><span v-if="a.registration_count > 1"> · {{ a.registration_count }} registrations</span>
                                <div><a v-if="a.phone" :href="`tel:${a.phone}`" dir="ltr">{{ a.phone }}</a> <a v-if="a.email" :href="`mailto:${a.email}`" dir="ltr">{{ a.email }}</a><span v-else>No email: call them</span></div>
                                <p class="small mb-2">{{ a.source }} · {{ a.reminder_sent_at ? 'Reminder sent' : a.reminder_queued_at ? 'Reminder queued' : 'Reminder not sent' }}</p>
                                <button class="btn btn-sm btn-outline-primary" :disabled="busy" @click="move(a,day.date)">Move</button> <button class="btn btn-sm btn-outline-danger" :disabled="busy" @click="remove(a,day.date)">Remove</button>
                            </div>
                            <button class="btn btn-sm btn-outline-primary" :disabled="busy" @click="add(day.date)">Add a family</button>
                        </section>
                    </details>
                    <section v-for="day in current" :key="day.date" class="border rounded p-3 mb-3">
                        <h2 class="h5">{{ formatDay(day.date) }} · {{ day.taken }} of {{ day.capacity }}</h2>
                        <div v-for="a in day.assignments" :key="a.id" class="duty-family border-top py-2">
                            <strong dir="auto">{{ a.name }}</strong><span v-if="a.registration_count > 1"> · {{ a.registration_count }} registrations</span>
                            <div><a v-if="a.phone" :href="`tel:${a.phone}`" dir="ltr">{{ a.phone }}</a> <a v-if="a.email" :href="`mailto:${a.email}`" dir="ltr">{{ a.email }}</a><span v-else>No email: call them</span></div>
                            <p class="small mb-2">{{ a.source }} · {{ a.reminder_sent_at ? 'Reminder sent' : a.reminder_queued_at ? 'Reminder queued' : 'Reminder not sent' }}</p>
                            <button class="btn btn-sm btn-outline-primary" :disabled="busy" @click="move(a,day.date)">Move</button> <button class="btn btn-sm btn-outline-danger" :disabled="busy" @click="remove(a,day.date)">Remove</button>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" :disabled="busy" @click="add(day.date)">Add a family</button>
                    </section>
                </div>
                <div v-else-if="tab === 'Needs a day'" role="tabpanel">
                    <button v-if="needs.length" class="btn btn-primary mb-3" :disabled="busy || !data.rota.active" @click="email(null)">Email {{ needs.length === 1 ? 'the' : 'all' }} {{ needs.length }} {{ plural(needs.length, 'family', 'families') }} who {{ plural(needs.length, 'needs', 'need') }} a day</button>
                    <p v-else>Every family has its days.</p>
                    <section v-for="f in needs" :key="f.id" class="border rounded p-3 mb-3">
                        <h2 class="h5" dir="auto">{{ f.name }}</h2><p>{{ f.still_owed }} more {{ plural(f.still_owed,'day') }} owed<span v-if="f.registration_count > 1"> · {{ f.registration_count }} registrations</span></p>
                        <p v-if="f.unplaced_picks?.length">Picks that were full: {{ f.unplaced_picks.map(formatDay).join('; ') }}</p>
                        <p v-for="notice in f.notices" :key="notice">{{ notice }}</p>
                        <p>{{ f.link_mail_status ? `Link email ${f.link_mail_status} ${formatWhen(f.link_mail_at)}` : f.link_sent_at ? `Link sent ${formatWhen(f.link_sent_at)}` : 'Link not sent' }}</p>
                        <div><a v-if="f.phone" :href="`tel:${f.phone}`" dir="ltr">{{ f.phone }}</a> <a v-if="f.email" :href="`mailto:${f.email}`" dir="ltr">{{ f.email }}</a><strong v-else>No email: call them</strong></div>
                        <div class="d-flex flex-wrap gap-2 mt-2"><button class="btn btn-outline-primary" :disabled="busy || !data.rota.active" @click="copyLink(f,false)">Copy claim link</button><button class="btn btn-outline-primary" :disabled="busy || !data.rota.active" @click="copyLink(f,true)">Reissue and copy link</button><button class="btn btn-outline-primary" :disabled="busy || !f.email || !data.rota.active" @click="email(f)">Email this family</button></div>
                        <p v-if="links[f.id]" class="small mt-2">Private link for {{ f.name }} (copy if needed): <input class="form-control" :value="links[f.id]" readonly :aria-label="`Private link for ${f.name}`"></p>
                    </section>
                </div>
                <div v-else role="tabpanel">
                    <p v-if="!data.mails.length">No emails yet.</p>
                    <article v-for="m in data.mails" :key="m.id" class="border-bottom py-2"><strong dir="auto">{{ m.name }}</strong> · {{ m.kind }} · {{ m.status === 'sending' ? 'queued' : m.status }} · {{ formatWhen(m.sent_at || m.failed_at || m.queued_at) }}</article>
                </div>
                <details v-if="data.history.length" class="mt-3"><summary>Released days and changes</summary><p v-for="h in data.history" :key="h.id">{{ familyName(h.family_id) }}: {{ formatDay(h.duty_on) }} · {{ h.release_reason }}</p></details>
            </div>
        </div>
        <div v-if="dialog" class="duty-backdrop" @keydown.esc="close">
            <div class="duty-dialog card shadow" role="dialog" aria-modal="true" aria-labelledby="dutyTitle">
                <form class="card-body" @submit.prevent="saveDialog">
                    <h2 id="dutyTitle" class="h5">{{ dialog.title }}</h2>
                    <p v-if="dialogError" class="alert alert-danger" role="alert">{{ dialogError }}</p>
                    <div v-if="dialog.kind === 'edit'">
                        <label class="d-block mb-2">Name<input id="dutyName" v-model="draft.name" class="form-control" required maxlength="120"></label>
                        <label class="d-block mb-2">School year<select v-model="draft.school_year_id" class="form-select" :disabled="!!dialog.rota && !!data.families.length"><option v-for="year in setup.years" :value="year.id" :key="year.id">{{ year.label }}</option></select></label>
                        <label class="d-block mb-2">Capacity per day<input v-model.number="draft.capacity" type="number" min="1" max="20" class="form-control" required></label>
                        <label class="d-block mb-2">Turns owed per family<input v-model.number="draft.turns_owed" type="number" min="1" max="1000" class="form-control" required></label>
                        <label class="d-block mb-2">Source form (optional)<select v-model="draft.source_form_id" class="form-select" :disabled="!!dialog.rota && !!data.families.length" @change="draft.source_field=null"><option :value="null">None</option><option v-for="f in setup.forms" :value="f.id" :key="f.id">{{ f.name }}</option></select></label>
                        <label v-if="draft.source_form_id" class="d-block mb-2">School meeting days field<select v-model="draft.source_field" class="form-select" :disabled="!!dialog.rota && !!data.families.length" required><option :value="null">Choose a field</option><option v-for="f in sourceFields" :value="f.name" :key="f.name">{{ f.label }}</option></select></label>
                        <label class="d-block mb-2">Reminder lead (days)<input v-model.number="draft.reminder_lead" type="number" min="0" max="14" class="form-control" required></label>
                        <label class="d-block mb-2">Reminder line<textarea v-model="draft.reminder_line" class="form-control" maxlength="2000" placeholder="Cleaning starts at 1:30 PM after class."></textarea></label>
                        <label class="d-block mb-3"><input v-model="draft.active" type="checkbox"> Active</label>
                        <p v-if="dialog.rota" class="small text-muted">After families are added, the year and source stay with this rota to preserve history. Create a new rota to change them.</p>
                    </div>
                    <div v-else-if="dialog.kind === 'assignment' && dialog.action !== 'remove'">
                        <label v-if="dialog.action === 'add'" class="d-block mb-2">Family<select id="dutyFamily" v-model="placement.family_id" class="form-select"><option :value="null">Type a new family</option><option v-for="f in data.families" :key="f.id" :value="f.id">{{ f.name }}</option></select></label>
                        <div v-if="dialog.action === 'add' && !placement.family_id">
                            <label class="d-block mb-2">Family name<input v-model="placement.name" class="form-control" required maxlength="200"></label>
                            <label class="d-block mb-2">Email<input v-model="placement.email" type="email" class="form-control" maxlength="254"></label>
                            <label class="d-block mb-2">Phone<input v-model="placement.phone" type="tel" class="form-control" maxlength="50"></label>
                        </div>
                        <label class="d-block mb-2">Day<select id="dutyMoveDay" v-model="placement.date" class="form-select" required><option value="">Choose a day</option><option v-for="d in placementDays" :key="d.date" :value="d.date">{{ formatDay(d.date) }} · {{ Math.max(0,d.capacity-d.taken) }} of {{ d.capacity }} left</option></select></label>
                        <label class="d-block mb-3"><input id="dutyOver" v-model="placement.over_capacity" type="checkbox"> Allow this day to go over capacity deliberately</label>
                    </div>
                    <p v-if="dialog.kind === 'build'">{{ buildCounts }}</p>
                    <p v-if="dialog.kind === 'email'">Email {{ dialog.family ? dialog.family.name : `${needs.length} ${plural(needs.length, 'family', 'families')} who ${plural(needs.length, 'needs', 'need')} a day` }} about {{ data.rota.name }}? Each email issues a new private link and voids the previous link. Families without an email are skipped and counted.</p>
                    <p v-if="dialog.action === 'remove'">Remove {{ dialog.family.name }} from {{ formatDay(dialog.date) }}? The day reopens and the released record is kept.</p>
                    <div class="d-flex flex-wrap gap-2"><button type="submit" class="btn btn-primary" :disabled="busy">{{ dialog.kind === 'build' ? 'Confirm build' : dialog.kind === 'email' ? 'Confirm email' : dialog.action === 'remove' ? 'Remove day' : 'Save' }}</button><button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="close">Cancel</button></div>
                </form>
            </div>
        </div>
    </div>
</template>
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { formatSchoolDay } from '@/core/types/data/masjid-related/SchoolCalendar';
import { LOCAL_STORAGE_KEYS } from '@/core/constants/appConfigConstants';
const auth=useAuthStore(); const masjid=useMasjidStore();
const enabled=computed(()=>masjid.masjid?.capabilities?.family_duty_rota === true);
const base=computed(()=>`/api/admin/masjids/${auth.dashboardMasjidId}/duty-rotas`);
let reloadNeeded=false;
const busy=ref(false); const error=ref(''); const result=ref(''); const dialogError=ref(''); const tab=ref('By day');
const setup=ref<any>({rotas:[],years:[],forms:[]}); const data=ref<any>(null); const selectedId=ref<number|null>(null); const dialog=ref<any>(null); const draft=ref<any>({}); const placement=ref<any>({}); const counts=ref<any>(null); const links=ref<Record<number,string>>({});
const formatDay=(d:string)=>formatSchoolDay(d,'en-US');
const plural=(n:number,singular:string,multiple=singular+'s')=>n===1?singular:multiple;
const formatWhen=(value:string)=>new Date(value).toLocaleString('en-US',{timeZone:data.value?.timezone || 'UTC',hour12:true});
const needs=computed(()=>data.value?.families.filter((f:any)=>f.still_owed>0) || []);
const earlier=computed(()=>data.value?.days.filter((d:any)=>d.date<data.value.today) || []);
const current=computed(()=>data.value?.days.filter((d:any)=>d.date>=data.value.today) || []);
const sourceFields=computed(()=>setup.value.forms.find((f:any)=>f.id===Number(draft.value.source_form_id))?.fields || []);
// Days still to come only. The day an Add was opened on stays listed even when full, so the box is never blank;
// the server still refuses a full day unless the over-capacity box is ticked.
const placementDays=computed(()=>data.value?.days.filter((d:any)=>d.date>=data.value.today && d.date!==dialog.value?.date && (placement.value.over_capacity || d.taken<d.capacity || (dialog.value?.action==='add' && d.date===placement.value.date))) || []);
const buildCounts=computed(()=>counts.value ? `${counts.value.registrations} ${plural(counts.value.registrations,'registration')}, ${counts.value.picks} ${plural(counts.value.picks,'pick')}: ${counts.value.placed} placed, ${counts.value.unplaced} could not be placed` : '');
const familyName=(id:number)=>data.value.families.find((f:any)=>f.id===id)?.name || 'Family';
function failure(e:any):string { const errors=e?.response?.data?.errors || e?.response?.data?.data; return errors && typeof errors==='object' ? Object.values(errors).flat().join(' ') : e?.response?.data?.message || 'Could not complete this action. Please try again.'; }
async function request(run:()=>Promise<any>,apply:(value:any)=>void,onFailure?:(e:any)=>Promise<void>):Promise<boolean> {
    if(busy.value || !enabled.value)return false; const context=base.value;busy.value=true;error.value='';dialogError.value='';
    try{const response=await run();if(base.value!==context || !enabled.value)return false;apply(response.data.data);return true;}catch(e){if(base.value===context){if(dialog.value)dialogError.value=failure(e);else error.value=failure(e);if(onFailure)await onFailure(e);}return false;}finally{busy.value=false;if(reloadNeeded){reloadNeeded=false;void load();}}
}
async function load(){if(!enabled.value)return;await request(()=>ApiService.get(base.value as any),value=>{setup.value=value;data.value=value.selected;selectedId.value=value.selected?.rota.id || null;});}
async function choose(){if(!selectedId.value)return;await request(()=>ApiService.get(`${base.value}/${selectedId.value}` as any),value=>{data.value=value;result.value='';});}
function close(){if(!busy.value){dialog.value=null;dialogError.value='';}}
function edit(rota:any){draft.value=rota?{...rota}:{name:'Cleaning',school_year_id:setup.value.years.at(-1)?.id,capacity:3,turns_owed:2,source_form_id:null,source_field:null,reminder_lead:3,reminder_line:'',active:true};dialog.value={kind:'edit',rota,title:rota?`Edit ${rota.name}`:'Create duty rota'};}
function move(family:any,date:string){placement.value={date:'',over_capacity:false};dialog.value={kind:'assignment',action:'move',family,date,title:`Move ${family.name} from ${formatDay(date)}`};}
function add(date:string){placement.value={date,family_id:null,name:'',email:'',phone:'',over_capacity:false};dialog.value={kind:'assignment',action:'add',date:null,title:`Add a family on ${formatDay(date)}`};}
function remove(family:any,date:string){dialog.value={kind:'assignment',action:'remove',family,date,title:`Remove ${family.name} from ${formatDay(date)}`};}
function email(family:any){dialog.value={kind:'email',family,title:family?`Email ${family.name} about ${data.value.rota.name}`:'Email families who need a day'};}
async function preview(){await request(()=>ApiService.post(`${base.value}/${selectedId.value}/preview` as any,{}),value=>{counts.value=value;dialog.value={kind:'build',title:`Build ${data.value.rota.name} from the form`};});}
async function saveDialog(){
    const d=dialog.value;if(!d || busy.value)return;
    if(d.kind==='email'){if(d.family)delete links.value[d.family.id];else links.value={};}const url=`${base.value}/${selectedId.value}`;
    const done=await request(()=>d.kind==='edit'?(d.rota?ApiService.put(url as any,draft.value):ApiService.post(base.value as any,draft.value)):d.kind==='build'?ApiService.post(`${url}/build` as any,{confirmed:true}):d.kind==='email'?ApiService.post(`${url}/email` as any,{confirmed:true,...(d.family?{family_id:d.family.id}:{})}):ApiService.post(`${url}/assignments` as any,{action:d.action,...(d.action==='remove'?{}:d.action==='add'&&placement.value.family_id?{date:placement.value.date,family_id:placement.value.family_id,over_capacity:placement.value.over_capacity}:placement.value),...(d.family?{assignment_id:d.family.id}:{})}),value=>{
        data.value=value;selectedId.value=value.rota.id;
        if(!setup.value.rotas.some((r:any)=>r.id===value.rota.id))setup.value.rotas.push(value.rota);else setup.value.rotas=setup.value.rotas.map((r:any)=>r.id===value.rota.id?value.rota:r);
        if(d.kind==='email'){const n=value.result.queued;const skipped=value.result.skipped_no_email;result.value=`${n} ${plural(n,'email')} queued.`+(skipped?` ${skipped} ${plural(skipped,'family','families')} ${skipped===1?'has':'have'} no email: call them.`:'');}else result.value=d.kind==='build'?'The form build is complete.':'Saved.';
    },async e=>{
        if(d.kind==='assignment' && d.action!=='remove' && e?.response?.status===422 && failure(e).includes('That day is full')){
            try{const response=await ApiService.get(url as any);if(`${base.value}/${selectedId.value}`===url && dialog.value===d && enabled.value){data.value=response.data.data;if(d.action!=='add')placement.value.date='';}}catch{dialogError.value+=' Could not refresh the days. Close and reload before trying again.';}
        }
    });if(done){dialog.value=null;}
}
async function copyLink(f:any,reissue=false){if(reissue)delete links.value[f.id];await request(()=>ApiService.post(`${base.value}/${selectedId.value}/families/${f.id}/link` as any,{reissue}),value=>{links.value[f.id]=value.url;result.value=reissue?`New private link for ${f.name}. The old link is void.`:`Private link for ${f.name} copied; the link below can also be copied.`;navigator.clipboard?.writeText(value.url).catch(()=>{});});}
async function exportCsv(){await request(async()=>{const response=await fetch(`${base.value}/${selectedId.value}/export`,{headers:{Authorization:`Bearer ${localStorage.getItem(LOCAL_STORAGE_KEYS.token)}`,Accept:'text/csv'}});if(!response.ok)throw new Error('export');const url=URL.createObjectURL(await response.blob());const a=document.createElement('a');a.href=url;a.download='duty-rota.csv';a.click();URL.revokeObjectURL(url);return {data:{data:null}};},()=>{result.value='CSV exported.';});}
watch(()=>auth.dashboardMasjidId,()=>{data.value=null;dialog.value=null;setup.value={rotas:[],years:[],forms:[]};links.value={};if(busy.value)reloadNeeded=true;else load();});
onMounted(load);
</script>
<style scoped>
.duty-rota, .duty-family, .duty-rota label { min-width: 0; overflow-wrap: anywhere; }
.duty-rota input, .duty-rota select, .duty-rota textarea { max-width: 100%; min-width: 0; }
.duty-rota a { display: inline-block; max-width: 100%; overflow-wrap: anywhere; }
.duty-backdrop { position: fixed; inset: 0; z-index: 1055; background: var(--bs-modal-backdrop-bg, var(--bs-body-bg)); display: flex; align-items: center; justify-content: center; padding: .75rem; }
.duty-dialog { width: 100%; max-width: 36rem; max-height: calc(100dvh - 1.5rem); overflow-y: auto; min-width: 0; overflow-wrap: anywhere; }
@media (max-width: 400px) { .duty-rota > .card-body { padding: .75rem; } .duty-rota .btn { white-space: normal; max-width: 100%; } }
</style>
