<template>
    <section :dir="dir" :lang="lang" class="card my-3"><div class="card-body">
        <h2 class="h5">{{ t('tr_title') }} · {{ t('tr_unofficial') }}</h2>
        <p v-if="error" class="text-danger" role="alert">{{ t('tr_error') }}</p>
        <template v-if="document">
            <TranscriptDocument :document="document" :labels="labels" />
            <button class="btn btn-outline-primary" :disabled="busy" @click="download">{{ t('tr_download') }}</button>
            <article v-for="share in shares" :key="share.id" class="border rounded p-3 mt-3">
                <p v-if="['awaiting_family','pending'].includes(share.status)">{{ t('tr_consent',share.receiving_school) }}</p>
                <p v-else>{{ t('tr_sent_to',share.receiving_school) }} · {{ t(`tr_${share.status}`) }} {{ share.resolved_on_display }}</p>
                <button v-if="share.status === 'awaiting_family'" class="btn btn-primary me-2" :disabled="busy" @click="act(share,'consent')">{{ t('tr_agree') }}</button>
                <p v-else-if="share.status === 'pending'">{{ t('tr_pending') }}</p>
                <button v-if="['awaiting_family','pending'].includes(share.status)" class="btn btn-outline-secondary" :disabled="busy" @click="act(share,'withdraw')">{{ t('tr_withdraw') }}</button>
            </article>
        </template>
        <button v-else-if="error" class="btn btn-outline-secondary" @click="load">{{ t('cal_retry') }}</button>
        <p v-else role="status">{{ t('cal_loading') }}</p>
    </div></section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FamilyApiService from '@/core/services/FamilyApiService';
import { useFamilyLang } from '@/views/family/familyI18n';
import TranscriptDocument from '@/components/classes/TranscriptDocument.vue';
const props=defineProps<{masjidId:string;groupId:string;membershipId:number}>();
const {t,lang,dir}=useFamilyLang(); const document=ref<any>(null); const shares=ref<any[]>([]); const error=ref(false); const busy=ref(false); let generation=0; let alive=true;
const labels=computed(()=>Object.fromEntries(['present','not_graded','unofficial','date_of_birth','attended','none','from','file_only','course','level','final','attempted','earned','plain','weighted','cumulative','regular','honors','ap'].map(key=>[key,t(`tr_${key}`)])));
const url=()=>`/api/family/masjids/${props.masjidId}/groups/${props.groupId}/members/${props.membershipId}/transcript`;
async function load() {
    const run=++generation; error.value=false; document.value=null; shares.value=[]; busy.value=false;
    try {const r=await FamilyApiService.get(url()); if(alive && run===generation){document.value=r.data.data;shares.value=r.data.shares ?? [];}}
    catch {if(alive && run===generation) error.value=true;}
}
async function act(share:any,action:string) {
    if(busy.value)return; const run=generation; busy.value=true; error.value=false;
    try {await FamilyApiService.post(`${url()}/shares/${share.id}/${action}`,action==='consent'?{consent:'1'}:{}); if(alive && run===generation) await load();}
    catch {if(alive && run===generation)error.value=true;}
    finally {if(alive && run===generation)busy.value=false;}
}
async function download() {
    if(busy.value)return;const run=generation;busy.value=true;error.value=false;
    try {const objectUrl=await FamilyApiService.blobUrl(`${url()}/pdf`);if(!alive || run!==generation){URL.revokeObjectURL(objectUrl);return;}const link=window.document.createElement('a');link.href=objectUrl;link.download='unofficial-transcript.pdf';link.click();URL.revokeObjectURL(objectUrl);}
    catch {if(alive && run===generation)error.value=true;}
    finally {if(alive && run===generation)busy.value=false;}
}
watch(()=>[props.masjidId,props.groupId,props.membershipId],load,{immediate:true});
onBeforeUnmount(()=>{alive=false;generation++;});
</script>
