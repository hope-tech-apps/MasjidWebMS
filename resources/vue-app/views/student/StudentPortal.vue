<template>
    <main class="container py-4 student-portal" style="max-width:760px">
        <header class="d-flex align-items-center justify-content-between gap-3 mb-4"><h1 class="h3 mb-0">Student portal</h1><button v-if="signedIn" class="btn btn-outline-secondary" @click="signOut">Sign out</button></header>
        <p v-if="error" class="alert alert-warning" role="alert">{{ error }}</p>
        <p v-if="availability==='checking'" role="status">Checking student sign-in…</p>
        <p v-else-if="availability==='off'" role="status">Student sign-in is not available at this school.</p>
        <div v-else-if="availability==='error'" class="alert alert-warning" role="alert">Could not check student sign-in. Please try again. <button class="btn btn-link" @click="checkAvailability()">Try again</button></div>
        <form v-else-if="!signedIn" @submit.prevent="signIn" class="card card-body gap-3">
            <label>Username<input v-model="username" class="form-control" autocomplete="username" required maxlength="40" autocapitalize="none" spellcheck="false"></label>
            <label>Password<input v-model="password" type="password" class="form-control" autocomplete="current-password" required maxlength="72"></label>
            <button class="btn btn-success" :disabled="busy">Sign in</button><p class="small text-muted mb-0">Use the account your school office gave you. Ask the office if you need a reset.</p>
        </form>
        <form v-else-if="mustChange" @submit.prevent="changePassword" class="card card-body gap-3">
            <h2 class="h5">Choose your own password</h2><p>Use at least 10 characters with a letter and a number. <span v-if="requiredPassword">Your one-time password cannot open schoolwork yet.</span></p>
            <label>Current password<input v-model="currentPassword" class="form-control" type="password" autocomplete="current-password" required></label>
            <label>New password<input v-model="newPassword" class="form-control" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></label>
            <label>Confirm new password<input v-model="confirmation" class="form-control" type="password" autocomplete="new-password" required></label>
            <button class="btn btn-success" :disabled="busy">Save password</button><button v-if="!requiredPassword" type="button" class="btn btn-light" @click="mustChange=false">Back to schoolwork</button>
        </form>
        <template v-else>
            <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Student pages"><button v-for="p in ['Classes','Timetable','Calendar']" :key="p" class="btn" :class="page === p ? 'btn-success' : 'btn-outline-success'" @click="openPage(p)">{{ p }}</button><button class="btn btn-link" @click="mustChange=true">Change password</button></nav>
            <div v-if="page==='Classes'">
                <label class="d-block mb-3">Your class<select v-model="memberId" class="form-select" @change="attempt(loadClass)"><option v-for="c in classes" :key="c.membership_id" :value="c.membership_id">{{ c.name }}</option></select></label>
                <p class="small text-muted" v-if="selectedClass?.teachers?.length">Teachers: {{ selectedClass.teachers.join(', ') }}</p>
                <h2 class="h5">Work</h2><p v-if="!work.length">No work has been set yet.</p>
                <article v-for="a in work" :key="a.id" class="card card-body mb-3">
                    <h3 class="h6">{{ a.title }}</h3><p class="small mb-2">{{ a.subject }}<span v-if="a.due_on"> · Due {{ a.due_on_label }}</span></p>
                    <button v-if="a.students_hand_in || a.has_hand_in" class="btn btn-sm btn-outline-success align-self-start" @click="openHandIn(a)">Your hand-in</button>
                    <section v-if="openWork?.id===a.id" class="mt-3">
                        <p v-if="history?.marked" class="text-muted">This work has been marked. Your hand-in is read-only.</p>
                        <form v-else-if="a.students_hand_in" @submit.prevent="submit" class="d-flex flex-column gap-2">
                            <label>Hand in as<select v-model="kind" class="form-select"><option value="file">File</option><option value="photo">Photo</option><option value="link">Link</option></select></label>
                            <label v-if="kind==='link'">Web link<input v-model="link" type="url" class="form-control" required placeholder="https://"></label>
                            <label v-else>{{ kind==='photo' ? 'Photo' : 'File' }}<input :key="kind + uploadKey" type="file" class="form-control" :accept="kind==='photo' ? 'image/jpeg,image/png' : '.pdf,.doc,.docx,.jpg,.jpeg,.png'" @change="file=($event.target as HTMLInputElement).files?.[0] ?? null" required></label>
                            <p class="small text-muted mb-0">PDF, Word, JPEG or PNG; up to 8 MB. Replacing keeps every previous version.</p>
                            <button class="btn btn-success" :disabled="busy">{{ history?.history?.length ? 'Replace hand-in' : 'Hand in work' }}</button>
                        </form>
                        <ol class="list-unstyled mt-3"><li v-for="h in history?.history ?? []" :key="h.id" class="border-top py-2 small">Version {{ h.version }} · {{ h.submitted_at_label }} <strong v-if="h.late"> · Late</strong><br><a v-if="h.kind==='link'" :href="h.link" target="_blank" rel="noopener noreferrer">{{ h.link }}</a><button v-else class="btn btn-link btn-sm px-0" @click="download(a,h)">{{ h.file_name }}</button></li></ol>
                    </section>
                </article>
                <h2 class="h5">Your marks</h2><p v-if="!marks?.scores?.length">No marks yet.</p>
                <p v-if="marks?.summary?.weighting?.percent != null">Weighted average: {{ marks.summary.weighting.percent }}%</p>
                <ul class="list-group mb-3"><li v-for="(s,i) in marks?.scores ?? []" :key="i" class="list-group-item">{{ s.assignment?.title }}: {{ scoreText(s) }}</li></ul>
                <p v-if="marks?.scores_truncated" class="small text-muted">Showing the latest {{ marks.scores_shown }} marks. The summary includes all recorded marks.</p>
                <article v-for="r in marks?.reports ?? []" :key="r.id" class="mb-3"><h3 class="h6">{{ r.period }}</h3><p v-for="g in r.letter_grades" :key="g.subject">{{ g.subject }}: {{ g.final_letter ?? 'Not assessed' }}<span v-if="g.percentage != null"> ({{ g.percentage }}%)</span></p></article>
            </div>
            <section v-if="page==='Timetable'"><h2 class="h5">Your timetable</h2><label>Week<input v-model="week" type="date" class="form-control mb-3" @change="openPage('Timetable')"></label><article v-for="d in timetable?.days ?? []" :key="d.date" class="card card-body mb-2"><h3 class="h6">{{ d.date_label }}</h3><p v-if="d.closed">No school: {{ d.reason }}</p><p v-else-if="!d.meetings.length" class="small text-muted mb-0">No lessons scheduled.</p><p v-for="m in d.meetings" :key="m.id" class="mb-1">{{ m.starts_at_label }}–{{ m.ends_at_label }} · {{ m.label }}<span v-if="m.room_name"> · {{ m.room_name }}</span><span v-if="m.teachers.length"> · {{ m.teachers.join(', ') }}</span></p></article></section>
            <section v-if="page==='Calendar'"><h2 class="h5">School calendar</h2><article v-for="y in calendar?.years ?? []" :key="y.id" class="card card-body mb-2"><h3 class="h6">{{ y.label }}</h3><p>{{ y.first_day_label }} to {{ y.last_day_label }}</p><p v-for="c in y.closures ?? []" :key="c.closed_on">{{ c.closed_on_label }} · {{ c.reason }}</p><p v-for="t in y.terms ?? []" :key="t.id">{{ t.name }}: {{ t.starts_on_label }} to {{ t.ends_on_label }}</p></article><p v-if="!calendar?.years?.length">The school has not published its calendar yet.</p></section>
        </template>
    </main>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import StudentApi from '@/core/services/StudentAccountApiService';
import { preparePhoto } from '@/core/helpers/preparePhoto';
import { apiErrorText } from '@/core/services/ApiErrors';
const route=useRoute(); const school=String(route.params.masjidId); const base=`/api/student/masjids/${school}`;
const signedIn=ref(!!StudentApi.token(school)), mustChange=ref(false), busy=ref(false), error=ref('');
const availability=ref<'checking'|'on'|'off'|'error'>('checking');
const requiredPassword=ref(false);
const username=ref(''), password=ref(''), currentPassword=ref(''), newPassword=ref(''), confirmation=ref('');
const classes=ref<any[]>([]), work=ref<any[]>([]), marks=ref<any>(null), memberId=ref<number|null>(null), page=ref('Classes');
const openWork=ref<any>(null), histories=ref<Record<number,any>>({}), history=computed(()=>histories.value[openWork.value?.id]);
const selectedClass=computed(()=>classes.value.find(c=>c.membership_id===Number(memberId.value)));
const uploadKey=ref(0);
const kind=ref('file'), link=ref(''), file=ref<File|null>(null), week=ref(''), timetable=ref<any>(null), calendar=ref<any>(null);
function scoreText(s:any){ if(s.status==='missing') return 'Not handed in'; if(s.status==='excused') return 'Excused'; if(s.mark_label) return s.mark_label; if(s.assignment?.scale==='levels') return `Level ${s.points_earned} of 4`; return `${s.points_earned} / ${s.assignment?.points_possible}`; }
// A shared device may change account while a request or photo decode is still in flight.
let sessionVersion = 0;
let classRead = 0;
let pageRead = 0;
let handRead = 0;
function clearPrivateData() {
    classes.value=[]; work.value=[]; marks.value=null; histories.value={}; memberId.value=null;
    openWork.value=null; timetable.value=null; calendar.value=null; page.value='Classes';
    file.value=null; link.value=''; password.value=''; currentPassword.value=''; newPassword.value=''; confirmation.value='';
    classRead++; pageRead++; handRead++; uploadKey.value++;
}
function endSession() {
    sessionVersion++;
    StudentApi.clear(school); signedIn.value=false; mustChange.value=false; requiredPassword.value=false;
    clearPrivateData(); username.value=''; busy.value=false;
}
async function attempt(fn:()=>Promise<void>) {
    const version=sessionVersion;
    busy.value=true; error.value='';
    try { await fn(); }
    catch(e:any) {
        if(version!==sessionVersion)return;
        if(e.response?.status===403 && e.response?.data?.message?.startsWith('Change your one-time')) {
            mustChange.value=true; requiredPassword.value=true;
        } else if([401,403,404].includes(e.response?.status)) {
            endSession();
            if(e.response?.status===404)await checkAvailability(false);
        }
        error.value=apiErrorText(e)||'Please try again.';
    } finally { if(version===sessionVersion)busy.value=false; }
}
async function loadClass() {
    if(!memberId.value)return;
    const version=sessionVersion, read=++classRead, requested=Number(memberId.value);
    openWork.value=null; histories.value={}; work.value=[]; marks.value=null;
    const prefix=base+'/classes/'+requested;
    const [w,m]=await Promise.all([StudentApi.get(prefix+'/work'),StudentApi.get(prefix+'/marks')]);
    if(version!==sessionVersion || read!==classRead || Number(memberId.value)!==requested)return;
    work.value=w.data.data ?? []; marks.value=m.data.data;
}
async function load() {
    const version=sessionVersion;
    const r=await StudentApi.get(base+'/classes');
    if(version!==sessionVersion)return;
    classes.value=r.data.data ?? []; memberId.value=classes.value[0]?.membership_id ?? null;
    await loadClass();
}
async function signIn() {
    const version=++sessionVersion;
    const body={username:username.value,password:password.value};
    clearPrivateData();
    await attempt(async()=>{
        const r=await StudentApi.post(base+'/auth/sign-in',body);
        if(version!==sessionVersion)return;
        StudentApi.adopt(school,r.data.data.token); signedIn.value=true;
        mustChange.value=r.data.data.must_change_password; requiredPassword.value=mustChange.value;
        if(!mustChange.value)await load();
    });
}
async function changePassword() {
    const version=++sessionVersion;
    const body={current_password:currentPassword.value,password:newPassword.value,password_confirmation:confirmation.value};
    await attempt(async()=>{
        const r=await StudentApi.put(base+'/password',body);
        if(version!==sessionVersion)return;
        StudentApi.adopt(school,r.data.data.token); clearPrivateData();
        mustChange.value=false; requiredPassword.value=false;
        await load();
    });
}
async function signOut() {
    const version=++sessionVersion;
    const pending=StudentApi.post(base+'/sign-out');
    signedIn.value=false; mustChange.value=false; requiredPassword.value=false; clearPrivateData(); username.value='';
    await attempt(async()=>{
        try { await pending; }
        finally { if(version===sessionVersion)endSession(); }
    });
}
async function openPage(p:string) {
    const version=sessionVersion, read=++pageRead;
    page.value=p;
    await attempt(async()=>{
        if(p==='Timetable') {
            const r=await StudentApi.get(base+'/timetable'+(week.value?'?week='+week.value:''));
            if(version===sessionVersion && read===pageRead)timetable.value=r.data.data;
        }
        if(p==='Calendar') {
            const r=await StudentApi.get(base+'/school-calendar');
            if(version===sessionVersion && read===pageRead)calendar.value=r.data.data;
        }
    });
}
async function openHandIn(a:any) {
    const version=sessionVersion, member=memberId.value, read=++handRead;
    openWork.value=a; link.value=''; file.value=null; uploadKey.value++;
    await attempt(async()=>{
        const r=await StudentApi.get(base+'/classes/'+member+'/work/'+a.id+'/hand-ins');
        if(version===sessionVersion && member===memberId.value && read===handRead)histories.value[a.id]=r.data.data;
    });
}
async function submit() {
    const version=sessionVersion, member=memberId.value, assignment=openWork.value;
    const selectedKind=kind.value, selectedFile=file.value, selectedLink=link.value;
    await attempt(async()=>{
        const body=new FormData(); body.set('kind',selectedKind);
        if(selectedKind==='link')body.set('link',selectedLink);
        else if(selectedFile)body.set('file',await preparePhoto(selectedFile));
        if(version!==sessionVersion || member!==memberId.value || assignment?.id!==openWork.value?.id)return;
        await StudentApi.post(base+'/classes/'+member+'/work/'+assignment.id+'/hand-ins',body);
        if(version===sessionVersion && member===memberId.value && assignment.id===openWork.value?.id)await openHandIn(assignment);
    });
}
async function download(a:any,h:any) {
    const version=sessionVersion, member=memberId.value;
    await attempt(()=>StudentApi.download(base+'/classes/'+member+'/work/'+a.id+'/hand-ins/'+h.id+'/download',h.file_name,()=>version===sessionVersion));
}
let idleTimer:ReturnType<typeof setInterval>;
async function checkAvailability(loadSession=true) {
    const version=sessionVersion;
    availability.value='checking';
    try {
        await StudentApi.get(base+'/availability');
        if(version!==sessionVersion)return;
        availability.value='on';
        if(loadSession && signedIn.value)await attempt(load);
    } catch(e:any) {
        if(version!==sessionVersion)return;
        if(e.response?.status===404){endSession();availability.value='off';}
        else availability.value='error';
    }
}
onMounted(()=>{
    checkAvailability();
    idleTimer=setInterval(()=>{
        if(signedIn.value && Date.now()-StudentApi.lastResponseAt()>=15*60*1000){
            endSession();error.value='Your student session expired. Sign in again.';
        }
    },1000);
});
onBeforeUnmount(()=>{sessionVersion++;clearInterval(idleTimer);});
</script>
