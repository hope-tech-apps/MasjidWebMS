<template>
    <div v-if="loaded" class="row g-2 mb-3" aria-label="From the app filters">
        <div v-for="filter in filters" :key="filter.key" class="col-12 col-md-4">
            <label :for="`app-${filter.key}`" class="form-label small">{{ filter.label }}</label>
            <select :id="`app-${filter.key}`" v-model="selected[filter.key]" class="form-select form-select-sm" :aria-label="filter.label" @change="emit('change',{...selected})">
                <option :value="null">Everyone</option>
                <option v-for="option in options[filter.list]" :key="option.id" :value="option.id">{{ option.label ?? option.title }}{{ option.active === false ? ' (inactive)' : '' }}</option>
            </select>
        </div>
    </div>
</template>
<script setup lang="ts">
import { ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
const props=defineProps<{orgId:number}>();
const emit=defineEmits<{change:[filters:Record<string,number|null>]}>();
const filters=[{key:'occupation_id',label:'What they do',list:'occupation'},{key:'help_id',label:'How they could help',list:'help'},{key:'interest_id',label:'Interested in',list:'interests'}];
const selected=ref<Record<string,number|null>>({occupation_id:null,help_id:null,interest_id:null});
const loaded=ref(false);const options=ref<Record<string,any[]>>({occupation:[],help:[],interests:[]});let epoch=0;
watch(()=>props.orgId,async()=>{const e=++epoch;loaded.value=false;selected.value={occupation_id:null,help_id:null,interest_id:null};try{const r=await ApiService.get(`/api/admin/masjids/${props.orgId}/contacts/from-app-filters` as any);if(e===epoch){options.value=r.data.data;loaded.value=true;}}catch{if(e===epoch)loaded.value=false;}},{immediate:true});
</script>
