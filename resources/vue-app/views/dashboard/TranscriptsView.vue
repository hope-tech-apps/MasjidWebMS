<template>
    <section class="card"><div class="card-body">
        <h1 class="h4">Transcripts and GPA</h1>
        <p v-if="error" class="text-danger" role="alert">{{ error }}</p><p v-if="message" role="status">{{ message }}</p>
        <template v-if="data">
            <p class="small">{{ data.method }}</p>
            <details class="border rounded p-3 mb-3"><summary>School GPA policy and course settings</summary>
                <form @submit.prevent="savePolicy" class="mt-3">
                    <div v-for="(row, i) in points" :key="i" class="d-flex align-items-center gap-3 mb-2"><label>Letter<input class="form-control" v-model="row.letter" required maxlength="20" /></label><label>Points <input class="form-control" v-model="row.points" inputmode="decimal" required /></label><label><input type="checkbox" v-model="row.failing" /> Failing letter</label></div>
                    <label class="form-label">Honors extra<input class="form-control" v-model="honors" inputmode="decimal" required /></label>
                    <label class="form-label ms-2">AP extra<input class="form-control" v-model="ap" inputmode="decimal" required /></label>
                    <button class="btn btn-primary ms-2" :disabled="busy">Save GPA policy</button>
                </form>
                <button type="button" class="btn btn-outline-secondary my-2" @click="points.push({letter:'',points:'0.00',failing:false})">Add points-table letter</button>
                <form v-for="subject in data.subjects" :key="subject.id" @submit.prevent="saveCourse(subject)" class="border-top py-3">
                    <h2 class="h6">{{ subject.class }} · {{ subject.name }}</h2>
                    <label class="form-label">Credit<input class="form-control" v-model="credits[subject.id].credit" inputmode="decimal" required /></label>
                    <label class="form-label ms-2">Level<select class="form-select" v-model="credits[subject.id].level"><option value="regular">Regular</option><option value="honors">Honors</option><option value="ap">AP</option></select></label>
                    <button class="btn btn-outline-primary ms-2" :disabled="busy">Save {{ subject.name }}</button>
                </form>
            </details>
            <form class="d-flex gap-2 mb-3" @submit.prevent="load(1)"><label>Find student<input class="form-control" v-model="search" /></label><button class="btn btn-outline-secondary" :disabled="busy">Search</button></form>
            <label class="form-label d-block">Student<select class="form-select" v-model="selected" @change="openStudent"><option value="">Choose a student</option><option v-for="student in data.students.data" :key="student.id" :value="String(student.id)">{{ student.first_name }} {{ student.last_name }}</option></select></label>
            <div v-if="data.students.last_page > 1" class="d-flex gap-2 mb-3"><button class="btn btn-outline-secondary" :disabled="data.students.current_page <= 1 || busy" @click="load(data.students.current_page - 1)">Previous</button><span>{{ data.students.current_page }} / {{ data.students.last_page }}</span><button class="btn btn-outline-secondary" :disabled="data.students.current_page >= data.students.last_page || busy" @click="load(data.students.current_page + 1)">Next</button></div>
            <section v-if="studentData" class="border rounded p-3 my-3">
                <TranscriptDocument :document="studentData.document" />
                <details><summary>Final letter overrides</summary>
                    <template v-for="(year, i) in studentData.document.years" :key="i"><TranscriptFinalEditor v-for="course in year.courses.filter(c => c.staff)" :key="`${course.staff.class_subject_id}:${course.staff.school_year_id}`" :course="course" :year="year.year" :scale="data.scale" :url="`${base()}/students/${selected}/finals`" @saved="openStudent" /></template>
                </details>
                <h2 class="h6 mt-4">Official issues</h2>
                <button class="btn btn-primary mb-2" :disabled="busy" @click="issue">Issue a new official transcript</button>
                <p class="small">Each issue freezes the figures, school policy and PDF. A later grade change needs a new issue.</p>
                <ul><li v-for="copy in studentData.issues" :key="copy.id"><button class="btn btn-link" :disabled="busy" @click="download(`/issues/${copy.id}/pdf`,'official-transcript.pdf')">{{ copy.serial }} · {{ copy.issued_at }}</button></li></ul>
                <details class="border rounded p-3 mt-3"><summary>Prior-school years</summary>
                    <p>Enter courses to include them in cumulative GPA. A PDF alone contributes no credits or GPA. Each year is marked with the prior school.</p>
                    <ul><li v-for="year in studentData.prior_years" :key="year.id">From {{ year.school_name }} · {{ year.year_label }} <button type="button" class="btn btn-link" @click="editPrior(year)">Edit prior year</button><button v-if="year.group_resource_id" class="btn btn-link" :disabled="busy" @click="download(`/students/${selected}/prior/${year.id}/pdf`,'prior-transcript.pdf')">Download prior PDF</button></li></ul>
                    <form @submit.prevent="savePrior">
                        <label class="form-label d-block">Prior school<input class="form-control" v-model="prior.school_name" required maxlength="1000" /></label>
                        <label class="form-label">School year<input class="form-control" v-model="prior.year_label" required maxlength="100" placeholder="2025-2026" /></label>
                        <label class="form-label ms-2">Grade<select class="form-select" v-model="prior.grade_label"><option v-for="grade in ['9th','10th','11th','12th']" :key="grade">{{ grade }}</option></select></label>
                        <div><label>First date, if known<input type="date" class="form-control" v-model="prior.starts_on" /></label><label class="ms-2">Last date, if known<input type="date" class="form-control" v-model="prior.ends_on" /></label></div>
                        <div v-for="(course, i) in prior.courses" :key="i" class="d-flex flex-wrap gap-2 align-items-end mt-2">
                            <label>Course<input class="form-control" v-model="course.name" required maxlength="250" /></label><label>Letter<select class="form-select" v-model="course.letter"><option v-for="point in points" :key="point.letter">{{ point.letter }}</option></select></label>
                            <label>Credit<input class="form-control" v-model="course.credit" inputmode="decimal" required /></label><label>Level<select class="form-select" v-model="course.level"><option value="regular">Regular</option><option value="honors">Honors</option><option value="ap">AP</option></select></label>
                            <button type="button" class="btn btn-outline-secondary" @click="prior.courses.splice(i,1)">Remove course</button>
                        </div>
                        <button type="button" class="btn btn-outline-secondary my-2" @click="prior.courses.push({name:'',letter:points[0]?.letter || 'A',credit:'1.00',level:'regular'})">Add prior course</button>
                        <label class="form-label d-block">Prior transcript PDF, optional<input type="file" :key="prior.id" accept="application/pdf" class="form-control" @change="pickFile" /></label>
                        <label v-if="priorFile" class="form-label d-block">Student's class for the private file<select class="form-select" v-model="prior.membership_id" required><option value="">Choose class</option><option v-for="row in studentData.memberships" :key="row.id" :value="String(row.id)">{{ row.class }}</option></select></label>
                        <button class="btn btn-primary" :disabled="busy">Save prior year</button>
                    </form>
                </details>
                <h2 class="h6 mt-4">Share with another Manara school</h2>
                <p>The family consents in its portal, or the office records signed paper consent. The receiving office sees only this student's name and birth date until it accepts. Acceptance copies the latest issued official transcript; if none exists, the sending office's request authorises a new issue.</p>
                <form @submit.prevent="requestShare" class="d-flex gap-2"><label>Receiving school<select class="form-select" v-model="receiving" required><option value="">Choose school</option><option v-for="school in data.receiving_schools" :key="school.id" :value="String(school.id)">{{ school.name }}</option></select></label><button class="btn btn-primary" :disabled="busy">Request sharing</button></form>
                <article v-for="share in studentData.shares" :key="share.id" class="border rounded p-3 mt-2">
                    <p>To {{ share.receiving_school }} · Request #{{ share.id }} · {{ share.status }} · Requested {{ share.requested_at }} by staff #{{ share.requested_by_user_id }}</p>
                    <p v-if="share.consented_at">Family consent: {{ share.consent_method }} · {{ share.consented_at }} · {{ share.consented_by_contact_id ? `guardian #${share.consented_by_contact_id}` : `office #${share.consented_by_user_id}` }}</p>
                    <p v-if="share.resolved_at">{{ share.status }} at {{ share.resolved_at }} · {{ share.withdrawn_by_contact_id ? `guardian #${share.withdrawn_by_contact_id}` : `office #${share.resolved_by_user_id}` }}</p>
                    <form v-if="share.status === 'awaiting_family'" @submit.prevent="paper(share)"><label>Where the signed paper consent is held<textarea class="form-control" v-model="evidence[share.id]" required maxlength="4000" /></label><button class="btn btn-outline-primary ms-2" :disabled="busy">Record paper consent</button></form>
                    <button v-if="['awaiting_family','pending'].includes(share.status)" class="btn btn-outline-secondary mt-2" :disabled="busy" @click="withdraw(share)">Withdraw request</button>
                </article>
                <h2 class="h6 mt-3">Received on this student's record</h2>
                <p v-if="!studentData.received.length">No received transcripts.</p>
                <p v-for="copy in studentData.received" :key="copy.id">From {{ copy.origin_school }} · {{ copy.received_at }} · Read-only <button class="btn btn-link" :disabled="busy" @click="download(`/received/${copy.id}/pdf`,'received-transcript.pdf')">Download</button></p>
            </section>
            <h2 class="h5 mt-4">Incoming requests and history</h2><p v-if="!inbox.length">No incoming requests.</p>
            <article v-for="share in inbox" :key="share.id" class="border rounded p-3 my-2">
                <p>From {{ share.sending_school }} · {{ share.student_name }} · {{ share.date_of_birth || 'No birth date on file' }} · {{ share.status }} · {{ share.resolved_at || share.requested_at }}</p>
                <template v-if="share.status === 'pending'"><label>Link to an enrolled student<select class="form-select" v-model="linkChoices[share.id]"><option value="">Keep unlinked until enrolled</option><option v-for="child in data.students.data" :key="child.id" :value="String(child.id)">{{ child.first_name }} {{ child.last_name }}</option></select></label><button class="btn btn-primary m-2" :disabled="busy" @click="accept(share)">Accept and copy transcript</button><button class="btn btn-outline-secondary" :disabled="busy" @click="decline(share)">Decline</button></template>
            </article>
            <h2 class="h5 mt-4">Received transcripts</h2>
            <article v-for="copy in data.received" :key="copy.id" class="border rounded p-3 my-2">
                <p>{{ copy.student_name }} · From {{ copy.origin_school }} · {{ copy.received_at }} · Read-only</p><button class="btn btn-link" :disabled="busy" @click="download(`/received/${copy.id}/pdf`,'received-transcript.pdf')">Download</button>
                <template v-if="!copy.contact_id"><label>Link to student<select class="form-select" v-model="receivedLinks[copy.id]"><option value="">Choose student</option><option v-for="child in data.students.data" :key="child.id" :value="String(child.id)">{{ child.first_name }} {{ child.last_name }}</option></select></label><button class="btn btn-outline-primary ms-2" :disabled="busy || !receivedLinks[copy.id]" @click="link(copy)">Link received copy</button></template>
            </article>
        </template>
        <button v-else-if="error" class="btn btn-outline-secondary" @click="load()">Retry</button><p v-else role="status">Loading transcripts...</p>
    </div></section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
import { useMasjidStore } from '@/stores/masjidStore';
import { serverMessage } from '@/core/helpers/serverMessage';
import TranscriptDocument from '@/components/classes/TranscriptDocument.vue';
import TranscriptFinalEditor from '@/components/classes/TranscriptFinalEditor.vue';
const store=useMasjidStore(); const school=computed(()=>String(store.masjid?.id ?? '')); const base=()=>`/api/admin/masjids/${school.value}/transcripts` as any;
const data=ref<any>(null); const studentData=ref<any>(null); const inbox=ref<any[]>([]); const selected=ref(''); const search=ref(''); const receiving=ref(''); const error=ref(''); const message=ref(''); const busy=ref(false);
const points=ref<any[]>([]); const honors=ref('0.50'); const ap=ref('1.00'); const credits=ref<Record<number,any>>({}); const evidence=ref<Record<number,string>>({}); const linkChoices=ref<Record<number,string>>({}); const receivedLinks=ref<Record<number,string>>({}); const priorFile=ref<File|null>(null);
const blankPrior=()=>({id:null as number|null,school_name:'',year_label:'',grade_label:'9th',starts_on:'',ends_on:'',courses:[] as any[],membership_id:''}); const prior=ref(blankPrior());
let generation=0; let studentGeneration=0; let alive=true;
const decimal=(value:number)=>`${Math.floor(value/100)}.${String(value%100).padStart(2,'0')}`;
async function load(page=1) {
    const run=++generation; error.value=''; if(!school.value)return;
    try {const [r,requests]=await Promise.all([ApiService.get(`${base()}?search=${encodeURIComponent(search.value)}&page=${page}`),ApiService.get(`${base()}/inbox`)]);if(!alive || run!==generation)return;
        data.value=r.data.data; inbox.value=requests.data.data; points.value=[...new Set([...Object.keys(data.value.policy.points),...data.value.scale.map((row:any)=>row.letter)])].map(letter=>({letter,points:data.value.policy.points[letter]===undefined?'':decimal(data.value.policy.points[letter]),failing:data.value.policy.failing_letters.includes(letter)}));honors.value=decimal(data.value.policy.honors_extra);ap.value=decimal(data.value.policy.ap_extra);
        credits.value=Object.fromEntries(data.value.subjects.map((subject:any)=>[subject.id,{credit:subject.setting?decimal(subject.setting.credit_hundredths):'',level:subject.setting?.level ?? 'regular'}]));
    } catch(e){if(alive && run===generation)error.value=serverMessage(e,'Transcripts could not be loaded.');}
}
async function openStudent() {
    const run=++studentGeneration; const schoolRun=generation; studentData.value=null; prior.value=blankPrior(); priorFile.value=null; error.value=''; if(!selected.value)return;
    try{const r=await ApiService.get(`${base()}/students/${selected.value}`);if(alive && run===studentGeneration && schoolRun===generation)studentData.value=r.data.data;}
    catch(e){if(alive && run===studentGeneration && schoolRun===generation)error.value=serverMessage(e,'This transcript could not be loaded.');}
}
async function mutate(action:()=>Promise<any>,success:string,refreshSchool=false) {
    if(busy.value)return; const run=generation; busy.value=true;error.value='';message.value='';
    try{await action();if(!alive || run!==generation)return;message.value=success;if(refreshSchool)await load(data.value?.students.current_page ?? 1);if(selected.value)await openStudent();}
    catch(e){if(alive && run===generation)error.value=serverMessage(e,'This transcript change could not be saved.');}
    finally{if(alive)busy.value=false;}
}
const saveCourse=(subject:any)=>mutate(()=>ApiService.put(`${base()}/courses/${subject.id}`,{...credits.value[subject.id]},'json'),'Course settings saved.');
const savePolicy=()=>{if(new Set(points.value.map(row=>row.letter)).size!==points.value.length){error.value='Use one row per letter in the points table.';return;}return mutate(()=>ApiService.put(`${base()}/settings`,{points:Object.fromEntries(points.value.map(row=>[row.letter,row.points])),honors_extra:honors.value,ap_extra:ap.value,failing_letters:points.value.filter(row=>row.failing).map(row=>row.letter)},'json'),'GPA policy saved.');};
const issue=()=>mutate(()=>ApiService.post(`${base()}/students/${selected.value}/issues`,{}),'Official transcript issued.');
const requestShare=()=>mutate(()=>ApiService.post(`${base()}/students/${selected.value}/shares`,{receiving_masjid_id:receiving.value}),'Request created. Family consent is required.');
const paper=(share:any)=>mutate(()=>ApiService.post(`${base()}/shares/${share.id}/paper-consent`,{evidence:evidence.value[share.id]}),'Paper consent recorded.');
const withdraw=(share:any)=>mutate(()=>ApiService.post(`${base()}/shares/${share.id}/withdraw`,{}),'Request withdrawn.');
const accept=(share:any)=>mutate(()=>ApiService.post(`${base()}/inbox/${share.id}/accept`,{contact_id:linkChoices.value[share.id] || null}),'Transcript copied to this school.',true);
const decline=(share:any)=>mutate(()=>ApiService.post(`${base()}/inbox/${share.id}/decline`,{}),'Request declined.',true);
const link=(copy:any)=>mutate(()=>ApiService.put(`${base()}/received/${copy.id}/student`,{contact_id:receivedLinks.value[copy.id]},'json'),'Received transcript linked.',true);
function editPrior(year:any){prior.value={...blankPrior(),...year,courses:year.courses.map((course:any)=>({...course,credit:decimal(course.credit_hundredths)})),membership_id:''};priorFile.value=null;}
function pickFile(event:Event){priorFile.value=(event.target as HTMLInputElement).files?.[0] ?? null;}
async function savePrior(){
    const body=new FormData(); for(const key of ['school_name','year_label','grade_label','starts_on','ends_on'])body.append(key,prior.value[key]);
    // A JSON string is decoded explicitly at the request boundary for an empty or populated multipart list.
    body.append('courses_json',JSON.stringify(prior.value.courses)); if(priorFile.value){body.append('file',priorFile.value);body.append('membership_id',prior.value.membership_id);}
    if(prior.value.id)body.append('_method','PUT');
    await mutate(()=>ApiService.post(`${base()}/students/${selected.value}/prior${prior.value.id?`/${prior.value.id}`:''}`,body),'Prior year saved.');
}
async function download(path:string,name:string){
    if(busy.value)return;const run=generation;busy.value=true;error.value='';
    try{const r=await ApiService.VueApp.axios.get(`${base()}${path}`,{responseType:'blob'});if(!alive || run!==generation)return;const objectUrl=URL.createObjectURL(r.data);const anchor=document.createElement('a');anchor.href=objectUrl;anchor.download=name;anchor.click();URL.revokeObjectURL(objectUrl);}
    catch(e){if(alive && run===generation)error.value=serverMessage(e,'The transcript file could not be downloaded.');}
    finally{if(alive)busy.value=false;}
}
watch(school,()=>{generation++;studentGeneration++;data.value=null;studentData.value=null;selected.value='';search.value='';message.value='';busy.value=false;load();},{immediate:true});
onBeforeUnmount(()=>{alive=false;generation++;studentGeneration++;});
</script>
