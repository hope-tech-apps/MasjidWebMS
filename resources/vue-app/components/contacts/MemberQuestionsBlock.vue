<template>
    <section v-if="data" class="border rounded p-3 mb-3 app-answers" aria-label="From the app">
        <h6>From the app</h6>
        <p class="small text-muted">Optional answers the member gave. The office can read them.</p>
        <dl class="mb-0">
            <div v-for="question in data.questions" :key="question.kind">
                <dt>{{ question.prompt }}</dt>
                <dd><span v-if="!question.answer.length">Not answered</span><ul v-else class="mb-1"><li v-for="option in question.answer" :key="option.id">{{ option.label }}</li></ul><span v-if="question.text" class="d-block" dir="auto">{{ question.text }}</span></dd>
            </div>
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
