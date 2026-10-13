<template>
    <section class="card mw-100 member-options" aria-label="App member question options">
        <div class="card-body">
            <h3 class="h5">App member question options</h3>
            <p class="text-muted">Optional adult questions. Only the office sees the answers. Deactivated choices stay on existing answers.</p>
            <p v-if="loading" role="status">Loading option lists…</p>
            <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
            <div v-if="loaded">
                <fieldset v-for="question in questions" :key="question.key" :disabled="saving" class="border-0 p-0 mb-4">
                    <legend class="h6">{{ question.label }}</legend>
                    <ol class="list-unstyled">
                        <li v-for="(option,index) in lists[question.key]" :key="option.id ?? `new-${index}`" class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <input v-model="option.label" :aria-label="`${question.label} option ${index+1}`" maxlength="120" class="form-control option-label" />
                            <span v-if="option.is_other" class="small text-muted">Other: allows extra words</span>
                            <button type="button" class="btn btn-outline-secondary" :disabled="index===0" :aria-label="`Move ${option.label} up`" @click="move(question.key,index,-1)">Up</button>
                            <button type="button" class="btn btn-outline-secondary" :disabled="index===lists[question.key].length-1" :aria-label="`Move ${option.label} down`" @click="move(question.key,index,1)">Down</button>
                            <button type="button" class="btn btn-outline-secondary" :aria-label="`${option.active ? 'Deactivate' : 'Activate'} ${option.label}`" @click="option.active=!option.active">{{ option.active ? 'Deactivate' : 'Activate' }}</button>
                        </li>
                    </ol>
                    <label :for="`new-${question.key}`" class="form-label">New option</label>
                    <div class="d-flex flex-wrap gap-2">
                        <input :id="`new-${question.key}`" v-model="newLabels[question.key]" maxlength="120" class="form-control option-label" />
                        <button type="button" class="btn btn-outline-primary" :disabled="!newLabels[question.key].trim() || lists[question.key].length>=100" @click="add(question.key)">Add {{ question.key }} option</button>
                    </div>
                </fieldset>
                <p v-if="saved" role="status" class="text-success">Saved.</p>
                <button type="button" class="btn btn-success" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save option lists' }}</button>
            </div>
        </div>
    </section>
</template>
<script setup lang="ts">
import { ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
type Key='occupation'|'help';
type Option={id?:number;label:string;active:boolean;is_other?:boolean};
const props=defineProps<{orgId:number}>();
const questions=[{key:'occupation' as Key,label:'What do you do?'},{key:'help' as Key,label:'How could you help?'}];
const lists=ref<Record<Key,Option[]>>({occupation:[],help:[]});
const newLabels=ref({occupation:'',help:''}); const loading=ref(false); const loaded=ref(false); const saving=ref(false); const saved=ref(false); const error=ref(''); let epoch=0;
const url=()=>`/api/admin/masjids/${props.orgId}/member-question-options`;
function adopt(data:any){ lists.value={occupation:data.occupation.map((o:Option)=>({...o})),help:data.help.map((o:Option)=>({...o}))}; }
watch(()=>props.orgId,async()=>{const e=++epoch; loaded.value=false; loading.value=true; error.value=''; saved.value=false;newLabels.value={occupation:'',help:''};try{const r=await ApiService.get(url() as any);if(e===epoch){adopt(r.data.data);loaded.value=true;}}catch{if(e===epoch)error.value='Option lists could not be loaded.';}finally{if(e===epoch)loading.value=false;}},{immediate:true});
function move(key:Key,index:number,step:number){const rows=lists.value[key];const to=index+step;if(to<0||to>=rows.length)return;[rows[index],rows[to]]=[rows[to],rows[index]];saved.value=false;}
function add(key:Key){const label=newLabels.value[key].trim();if(!label||lists.value[key].length>=100)return;lists.value[key].push({label,active:true});newLabels.value[key]='';saved.value=false;}
async function save(){if(saving.value)return;const e=epoch;saving.value=true;error.value='';saved.value=false;try{const r=await ApiService.put(url() as any,lists.value,'url',{'Content-Type':'application/json'});if(e===epoch){adopt(r.data.data);saved.value=true;}}catch(e:any){error.value=e?.response?.data?.message??'Not saved. Check the labels and try again.';}finally{if(e===epoch)saving.value=false;}}
</script>
<style scoped>
.member-options { min-inline-size: 0; overflow-wrap: anywhere; }
.option-label { flex: 1 1 12rem; inline-size: 12rem; max-inline-size: 100%; min-inline-size: 0; }
.member-options button { min-block-size: 44px; }
</style>
