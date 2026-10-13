<template>
    <div v-if="loaded" class="row g-2 mb-3" aria-label="From the app filters">
        <div v-for="filter in filters" :key="filter.key" class="col-12 col-md-4">
            <label :for="`app-${filter.key}`" class="form-label small">{{ filter.label }}</label>
            <select :id="`app-${filter.key}`" v-model="selected[filter.key]" class="form-select form-select-sm" :aria-label="filter.label" @change="emit('change',{...selected})">
                <option :value="null">Everyone</option>
                <option v-for="option in filter.options" :key="option.id" :value="option.id">{{ option.label ?? option.title }}{{ option.active === false ? ' (inactive)' : '' }}</option>
            </select>
        </div>
    </div>
</template>
<script setup lang="ts">
import { ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
const props=defineProps<{orgId:number}>();
const emit=defineEmits<{change:[filters:Record<string,number|null>]}>();
const labels:Record<string,string>={field:'Field',occupation:'Profession',help:'How they could help'};
const filters=ref<{key:string;label:string;options:any[]}[]>([]);
const empty=()=>({field_id:null,occupation_id:null,help_id:null,interest_id:null});
const selected=ref<Record<string,number|null>>(empty());
const loaded=ref(false);let epoch=0;
watch(()=>props.orgId,async()=>{const e=++epoch;loaded.value=false;selected.value=empty();try{const r=await ApiService.get(`/api/admin/masjids/${props.orgId}/contacts/from-app-filters` as any);if(e===epoch){filters.value=r.data.data.questions.map((q:any)=>({key:q.kind==='help'?'help_id':`${q.kind}_id`,label:labels[q.kind],options:q.options}));filters.value.push({key:'interest_id',label:'Interested in',options:r.data.data.interests});loaded.value=true;}}catch{if(e===epoch)loaded.value=false;}},{immediate:true});
</script>
