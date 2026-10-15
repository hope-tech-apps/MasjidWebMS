<template>
    <section class="card card-body my-3"><h3 class="h6">Student hand-ins</h3><p v-if="error" class="alert alert-warning" role="alert">{{ error }}</p><p v-if="message" class="text-success" role="status">{{ message }}</p>
        <form v-if="teacher" @submit.prevent="save" class="mb-3"><label class="d-block"><input v-model="on" type="checkbox"> Students hand in this work</label><label class="d-block my-2">Due date<input v-model="due" type="date" class="form-control"></label><button class="btn btn-sm btn-outline-success" :disabled="busy">Save hand-in settings</button></form>
        <p v-if="!rows.length" class="small text-muted">No student hand-ins yet.</p>
        <p v-if="teacher" class="small">Enter marks in the gradebook below. Once marked, the student can no longer replace their hand-in.</p>
        <article v-for="s in rows" :key="s.membership_id" class="border-top pt-2"><strong>{{ s.name }}</strong><span v-if="s.marked" class="badge bg-secondary ms-2">Marked</span><ol class="list-unstyled"><li v-for="h in s.history" :key="h.id" class="small py-2">Version {{ h.version }} · {{ new Date(h.submitted_at).toLocaleString() }} <strong v-if="h.late"> · Late</strong><br><a v-if="h.kind==='link'" :href="h.link" rel="noopener noreferrer" target="_blank">{{ h.link }}</a><button v-else class="btn btn-link btn-sm px-0" @click="download(h)">{{ h.file_name }}</button></li></ol></article>
    </section>
</template>
<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import TeacherApi from '@/core/services/TeacherApiService';
import Api from '@/core/services/ApiService';
import { apiErrorText } from '@/core/services/ApiErrors';
const props=defineProps<{base:string;assignmentId:number;teacher?:boolean;revision?:unknown}>();
const rows=ref<any[]>([]),on=ref(false),due=ref(''),error=ref(''),message=ref(''),busy=ref(false);
const url=()=>`${props.base}/assignments/${props.assignmentId}`;
async function load(){error.value='';try{const r=await (props.teacher?TeacherApi.get(`${url()}/hand-ins`):Api.get(`${url()}/hand-ins` as any));const d=r.data.data;rows.value=d.students;on.value=d.students_hand_in;due.value=d.due_on ?? '';}catch(e){error.value=apiErrorText(e)||'Could not load hand-ins.';}}
async function save(){busy.value=true;message.value='';try{await TeacherApi.put(`${url()}/student-hand-in`,{students_hand_in:on.value?1:0,due_on:due.value||null});message.value='Hand-in settings saved.';}catch(e){error.value=apiErrorText(e)||'Could not save.';}finally{busy.value=false;}}
async function download(h:any){try{const path=`${url()}/hand-ins/${h.id}/download`;const blob=props.teacher?await TeacherApi.blobUrl(path):URL.createObjectURL((await Api.VueApp.axios.get(path,{responseType:'blob'})).data);const a=document.createElement('a');a.href=blob;a.download=h.file_name;a.click();setTimeout(()=>URL.revokeObjectURL(blob),1000);}catch(e){error.value=apiErrorText(e)||'Could not download.';}}
onMounted(load);watch(()=>[props.assignmentId,props.revision],load);
</script>
