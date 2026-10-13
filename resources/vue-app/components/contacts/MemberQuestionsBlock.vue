<template>
    <section v-if="data" class="border rounded p-3 mb-3 app-answers" aria-label="From the app">
        <h6>From the app</h6>
        <p class="small text-muted">Optional answers the member gave. The office can read them.</p>
        <dl class="mb-0">
            <dt>What do you do?</dt><dd>{{ data.occupation?.label ?? 'Not answered' }}<span v-if="data.occupation_text" class="d-block" dir="auto">{{ data.occupation_text }}</span></dd>
            <dt>How could you help?</dt><dd><span v-if="!data.help.length">Not answered</span><ul v-else class="mb-1"><li v-for="option in data.help" :key="option.id">{{ option.label }}</li></ul><span v-if="data.help_text" dir="auto">{{ data.help_text }}</span></dd>
            <dt>Which programs interest you?</dt><dd><span v-if="!data.interests.length">None chosen</span><ul v-else class="mb-0"><li v-for="service in data.interests" :key="service.id">{{ service.title }}</li></ul></dd>
        </dl>
    </section>
</template>
<script setup lang="ts">
import { ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
const props=defineProps<{orgId:number;contactId:number}>(); const data=ref<any>(null); let epoch=0;
watch(()=>[props.orgId,props.contactId],async()=>{const e=++epoch;data.value=null;try{const r=await ApiService.get(`/api/admin/masjids/${props.orgId}/contacts/${props.contactId}/from-app` as any);if(e===epoch)data.value=r.data.data;}catch{if(e===epoch)data.value=null;}},{immediate:true});
</script>
<style scoped>
.app-answers { min-inline-size: 0; overflow-wrap: anywhere; }
</style>
