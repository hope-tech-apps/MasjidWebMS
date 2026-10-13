<template>
    <section v-if="data && Object.keys(data).length" class="card mb-3 member-counts" aria-label="Member question counts">
        <div class="card-body">
            <p class="small text-muted">Counts of current directory members who answered. Each member counts once per choice. Unanswered members are excluded.</p>
            <div class="row g-3">
                <div v-for="table in tables" :key="table.kind" class="col-12 col-md-6">
                    <h6>{{ table.title }}</h6>
                    <table class="table table-sm">
                        <caption class="visually-hidden">{{ table.title }}</caption>
                        <thead><tr><th scope="col">Option</th><th scope="col" class="text-end">Members</th></tr></thead>
                        <tbody><tr v-for="row in data[table.kind]" :key="row.id"><td dir="auto">{{ row.label }}</td><td class="text-end">{{ row.count }}</td></tr></tbody>
                    </table>
                </div>
            </div>
            <button type="button" class="btn btn-outline-secondary" :disabled="downloading" @click="download">{{ downloading ? 'Downloading…' : 'Download CSV' }}</button>
            <p v-if="error" class="text-danger mt-2" role="alert">{{ error }}</p>
        </div>
    </section>
    <p v-else-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
</template>
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
const props=defineProps<{orgId:number}>();
const data=ref<Record<string,{id:number;label:string;count:number}[]>|null>(null);
const error=ref(''); const downloading=ref(false); let epoch=0;
const url=()=>`/api/admin/masjids/${props.orgId}/contacts/from-app-counts`;
const tables=computed(()=>[{kind:'field',title:'Members by field'},{kind:'occupation',title:'Members by profession'}].filter(t=>data.value?.[t.kind]));
watch(()=>props.orgId,async()=>{const e=++epoch;data.value=null;error.value='';downloading.value=false;try{const r=await ApiService.get(url() as any);if(e===epoch)data.value=r.data.data;}catch(err:any){if(e!==epoch)return;if(err?.response?.status!==403&&err?.response?.status!==404)error.value='Member counts could not be loaded.';}},{immediate:true});
async function download(){
    if(downloading.value)return;const e=epoch;downloading.value=true;error.value='';
    try{const r=await ApiService.VueApp.axios.get(`${url()}/csv`,{responseType:'blob'});if(e!==epoch)return;
        const address=URL.createObjectURL(r.data);const link=document.createElement('a');link.href=address;link.download='member-question-counts.csv';link.click();URL.revokeObjectURL(address);
    }catch{if(e===epoch)error.value='The CSV could not be downloaded. Try again.';}finally{if(e===epoch)downloading.value=false;}
}
</script>
<style scoped>
.member-counts { min-inline-size: 0; overflow-wrap: anywhere; }
.member-counts table { inline-size: 100%; table-layout: fixed; }
.member-counts button { min-block-size: 44px; max-inline-size: 100%; }
</style>
