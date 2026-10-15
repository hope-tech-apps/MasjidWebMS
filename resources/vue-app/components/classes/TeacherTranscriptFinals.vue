<template>
    <details class="border rounded p-3 mb-3" @toggle="opened">
        <summary>Annual final letters for transcripts</summary>
        <p v-if="error" class="text-danger" role="alert">{{ error }}</p>
        <template v-if="data"><p class="small">{{ data.method }}</p><p v-if="!data.students.length">No published high-school term grades for your subjects yet.</p>
            <article v-for="child in data.students" :key="child.membership_id"><h3 class="h6 mt-3">{{ child.name }}</h3><template v-for="(year,i) in child.years" :key="i"><TranscriptFinalEditor v-for="course in year.courses" :key="`${course.staff.class_subject_id}:${course.staff.school_year_id}`" :course="course" :year="year.year" :scale="data.scale" :url="`${base()}/members/${child.membership_id}/transcript-finals`" teacher @saved="load" /></template></article>
        </template>
        <button v-else-if="error" class="btn btn-outline-secondary" @click="load">Retry</button>
    </details>
</template>
<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import TeacherApiService from '@/core/services/TeacherApiService';
import { serverMessage } from '@/core/helpers/serverMessage';
import TranscriptFinalEditor from '@/components/classes/TranscriptFinalEditor.vue';
const props=defineProps<{schoolId:string;groupId:string}>();const data=ref<any>(null);const error=ref('');let generation=0;let alive=true;
const base=()=>`/api/teacher/masjids/${props.schoolId}/groups/${props.groupId}`;
function opened(event:Event){if((event.target as HTMLDetailsElement).open && !data.value)load();}
async function load(){const run=++generation;error.value='';try{const r=await TeacherApiService.get(`${base()}/transcript-finals`);if(alive && run===generation)data.value=r.data.data;}catch(e){if(alive && run===generation)error.value=serverMessage(e,'Annual final letters could not be loaded.');}}
watch(()=>[props.schoolId,props.groupId],()=>{generation++;data.value=null;error.value='';});onBeforeUnmount(()=>{alive=false;generation++;});
</script>
