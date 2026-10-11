<template>
    <main class="duty-claim min-vh-100 bg-light py-4 px-2" lang="en">
        <div class="card shadow-sm mx-auto duty-card"><div class="card-body">
            <p v-if="busy" role="status">Working…</p>
            <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
            <div v-if="page">
                <img v-if="page.school.logo_url" :src="page.school.logo_url" :alt="page.school.name" class="duty-logo mb-2">
                <h1 class="h4" dir="auto">{{ page.school.name }}</h1><h2 class="h5" dir="auto">{{ page.rota.name }} days for {{ page.family.name }}</h2>
                <p v-if="page.message" class="alert alert-warning" role="status">{{ page.message }}</p>
                <p v-if="notice" class="alert alert-success" role="status">{{ notice }}</p>
                <h3 class="h6 mt-3">Days you hold</h3>
                <p v-if="!page.held.length">No days yet.</p>
                <article v-for="day in page.held" :key="day" class="border rounded p-2 mb-2"><p class="mb-1">{{ formatDay(day) }}</p><button v-if="day > page.today" class="btn btn-sm btn-outline-primary" :disabled="busy" @click="givingBack=day">Give back {{ formatDay(day) }}</button><span v-else class="small text-muted">This day counts toward your turns.</span></article>
                <p class="mt-3">You owe {{ page.still_owed }} more {{ page.still_owed === 1 ? 'day' : 'days' }}.</p>
                <form v-if="page.still_owed > 0" @submit.prevent="claim">
                    <p>Pick up to {{ page.still_owed }} {{ page.still_owed === 1 ? 'day' : 'days' }}. You can pick fewer and come back.</p>
                    <p v-if="!page.available.length">No days have room right now. Please contact the school office.</p>
                    <label v-for="day in page.available" :key="day.date" class="d-block border rounded p-3 mb-2"><input v-model="picked" type="checkbox" :value="day.date" :disabled="busy || (!picked.includes(day.date) && picked.length >= page.still_owed)"> <span>{{ formatDay(day.date) }}</span><span class="d-block small text-muted">{{ day.left }} of {{ page.rota.capacity }} left</span></label>
                    <button class="btn btn-primary w-100" type="submit" :disabled="busy || !picked.length">Save my days</button>
                </form>
                <p v-if="page.school.email" class="small mt-3">Need help? <a :href="`mailto:${page.school.email}`">Contact the school office</a>.</p>
            </div>
        </div></div>
        <div v-if="givingBack" class="duty-backdrop" @keydown.esc="givingBack=null"><div class="card duty-dialog" role="dialog" aria-modal="true" aria-labelledby="giveTitle"><div class="card-body"><h2 id="giveTitle" class="h5">Give back {{ formatDay(givingBack) }}?</h2><p>This place will open for another family. You can choose another available day afterwards.</p><div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" :disabled="busy" @click="giveBack">Confirm give back</button><button class="btn btn-outline-secondary" :disabled="busy" @click="givingBack=null">Keep my day</button></div></div></div></div>
    </main>
</template>
<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { formatSchoolDay } from '@/core/types/data/masjid-related/SchoolCalendar';
const route=useRoute(); const page=ref<any>(null); const busy=ref(false); const error=ref(''); const notice=ref(''); const picked=ref<string[]>([]); const givingBack=ref<string|null>(null);
let token=''; const unavailable='This private link is unavailable. Ask the school office for a new link.';
const formatDay=(day:string)=>formatSchoolDay(day,'en-US');
async function send(action:string,body:any={}) {
    if(busy.value)return;busy.value=true;error.value='';notice.value='';
    try {
        const response=await fetch(`/api/v1/schools/${route.params.masjidId}/duty-rotas/${route.params.rotaId}/${action}`,{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({token,...body})});
        const result=await response.json();
        if(!response.ok){if(response.status===410 || response.status===404){page.value=null;throw new Error(unavailable);}const fields=result.errors || result.data;throw new Error(response.status===429?'Please wait a minute and try again.':fields&&typeof fields==='object'?Object.values(fields).flat().join(' '):result.message || 'Could not save your days. Please try again.');}
        page.value=result.data;picked.value=[];if(action==='claim')notice.value=result.data.saved?.length?`Saved: ${result.data.saved.map(formatDay).join('; ')}.`:'';if(action==='give-back'){givingBack.value=null;notice.value='Your day has been given back. You can choose another day below.';}
    }catch(e){error.value=e instanceof Error?e.message:'Could not open your days. Please try again.';}finally{busy.value=false;}
}
function claim(){if(picked.value.length && picked.value.length<=page.value.still_owed)send('claim',{dates:[...picked.value]});}
function giveBack(){send('give-back',{date:givingBack.value});}
onMounted(()=>{token=new URLSearchParams(window.location.hash.slice(1)).get('token') || '';window.history.replaceState(null,'',window.location.pathname+window.location.search);if(!token){error.value=unavailable;return;}send('page');});
</script>
<style scoped>
.duty-claim, .duty-card, .duty-claim label { min-width: 0; overflow-wrap: anywhere; }
.duty-card { width: 100%; max-width: 36rem; }
.duty-claim .btn { white-space: normal; max-width: 100%; }
.duty-logo { max-width: 10rem; max-height: 5rem; object-fit: contain; }
.duty-backdrop { position: fixed; inset: 0; z-index: 1055; background: var(--bs-body-bg); padding: .75rem; display: flex; align-items: center; justify-content: center; }
.duty-dialog { width: 100%; max-width: 32rem; max-height: calc(100dvh - 1.5rem); overflow-y: auto; min-width: 0; }
</style>
