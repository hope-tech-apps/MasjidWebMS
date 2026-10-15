<template>
    <form v-if="course?.staff" @submit.prevent="save" class="border rounded p-3 my-2">
        <h4 class="h6" dir="auto">{{ course.name }} · {{ year }}</h4>
        <p>Calculated final: {{ course.calculated_letter || 'Not graded' }} · Final: {{ course.letter || 'Not graded' }} · Average: {{ course.average_percentage ?? 'Not available' }}%</p>
        <label class="form-label d-block">Final letter override<select class="form-select" v-model="letter"><option value="">Use calculated letter</option><option v-for="row in scale" :key="row.letter" :value="row.letter">{{ row.letter }}</option></select></label>
        <label class="form-label d-block">Staff-only reason<textarea class="form-control" v-model="reason" :required="!!letter" maxlength="4000" /></label>
        <p v-if="course.staff.at" class="small">Recorded by {{ course.staff.by_name || 'Name not recorded' }} at {{ course.staff.at_display }}</p>
        <p v-if="error" class="text-danger" role="alert">{{ error }}</p>
        <p v-if="saved" role="status">Final letter saved.</p>
        <button class="btn btn-outline-primary" :disabled="busy">Save final letter</button>
    </form>
</template>
<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
import TeacherApiService from '@/core/services/TeacherApiService';
import { serverMessage } from '@/core/helpers/serverMessage';
const props = defineProps<{ course: any; year: string; scale: any[]; url: string; teacher?: boolean }>();
const emit = defineEmits(['saved']);
const letter = ref(''); const reason = ref(''); const busy = ref(false); const error = ref(''); const saved = ref(false); let generation = 0; let alive = true;
watch(() => [props.url,props.course], () => { generation++; letter.value=props.course.staff?.override_letter ?? ''; reason.value=props.course.staff?.reason ?? ''; error.value=''; saved.value=false; }, {immediate:true});
async function save() {
    if (busy.value) return; const run=generation; busy.value=true; error.value=''; saved.value=false;
    try { const body={class_subject_id:props.course.staff.class_subject_id,school_year_id:props.course.staff.school_year_id,letter:letter.value || null,reason:letter.value?reason.value:null}; if(props.teacher) await TeacherApiService.put(props.url,body); else await ApiService.put(props.url as any,body,'json'); if (alive && run===generation) { saved.value=true; emit('saved'); } }
    catch (e) { if (alive && run===generation) error.value=serverMessage(e,'The final letter could not be saved.'); }
    finally { if (alive && run===generation) busy.value=false; }
}
onBeforeUnmount(()=>{alive=false;generation++;});
</script>
