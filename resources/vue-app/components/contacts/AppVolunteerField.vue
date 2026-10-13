<template>
    <section class="border rounded p-3 mb-4 volunteer-field" aria-label="Volunteer link">
        <h2 class="h5">Volunteer link</h2>
        <p class="text-muted">The app opens this page. Save an https address to offer Volunteer in the app and as a tab.</p>
        <p v-if="loading" role="status">Loading Volunteer link…</p>
        <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
        <fieldset v-if="loaded" :disabled="saving" class="border-0 p-0">
            <label for="volunteer-title" class="form-label">Title</label>
            <input id="volunteer-title" v-model="title" class="form-control mb-3" maxlength="30" />
            <label for="volunteer-url" class="form-label">Address</label>
            <input id="volunteer-url" v-model="address" class="form-control mb-3" type="url" maxlength="500" placeholder="https://example.org/volunteer" />
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <a v-if="testUrl" class="btn btn-outline-secondary" :href="testUrl" target="_blank" rel="noopener noreferrer">Test this link</a>
                <button type="button" class="btn btn-success" :disabled="!title.trim() || (!!address && !testUrl)" @click="save">{{ saving ? 'Saving…' : 'Save Volunteer link' }}</button>
            </div>
            <p v-if="saved" class="text-success mt-2 mb-0" role="status">Saved.</p>
        </fieldset>
    </section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ApiService from '@/core/services/ApiService';
import { registerUnsavedChanges } from '@/core/tenancy/unsavedChanges';
import Swal from 'sweetalert2';
const props=defineProps<{orgId:number}>(); const emit=defineEmits<{updated:[data:{title:string;url:string|null}]}>();
const title=ref('Volunteer'); const address=ref('');const baseline=ref('');const loading=ref(false);const loaded=ref(false);const saving=ref(false);const saved=ref(false);const error=ref('');let epoch=0;
const fingerprint=()=>JSON.stringify([title.value,address.value]);
const dirty=computed(()=>loaded.value && baseline.value!==fingerprint());
const testUrl=computed(()=>{try{const u=new URL(address.value);return u.protocol==='https:'&&u.hostname&&!u.username&&!u.password&&address.value.length<=500 ? address.value : null;}catch{return null;}});
const url=()=>`/api/admin/masjids/${props.orgId}/app-volunteer`;
watch(()=>props.orgId,async()=>{const e=++epoch;loading.value=true;loaded.value=false;error.value='';try{const r=await ApiService.get(url() as any);if(e===epoch){title.value=r.data.data.title;address.value=r.data.data.url??'';baseline.value=fingerprint();loaded.value=true;}}catch{if(e===epoch)error.value='Volunteer link could not be loaded.';}finally{if(e===epoch)loading.value=false;}},{immediate:true});
async function save(){if(saving.value)return;const e=epoch;saving.value=true;error.value='';saved.value=false;const data={title:title.value,url:address.value||null};try{const r=await ApiService.put(url() as any,data,'url',{'Content-Type':'application/json'});if(e===epoch){title.value=r.data.data.title;address.value=r.data.data.url??'';baseline.value=fingerprint();saved.value=true;emit('updated',r.data.data);}}catch(e:any){if(e===epoch)error.value=e?.response?.data?.message??'Not saved. Check the address and try again.';}finally{if(e===epoch)saving.value=false;}}
const unregister=registerUnsavedChanges(async()=>!dirty.value||(await Swal.fire({title:'Leave without saving?',text:'Your Volunteer link changes have not been saved.',icon:'warning',showCancelButton:true,confirmButtonText:'Leave',cancelButtonText:'Keep editing'})).isConfirmed);
function beforeUnload(event:BeforeUnloadEvent){if(!dirty.value)return;event.preventDefault();event.returnValue='';}
onMounted(()=>window.addEventListener('beforeunload',beforeUnload));
onBeforeUnmount(()=>{++epoch;unregister();window.removeEventListener('beforeunload',beforeUnload);});
</script>
<style scoped>
.volunteer-field { min-inline-size: 0; overflow-wrap: anywhere; }
.volunteer-field input { max-inline-size: 100%; min-inline-size: 0; }
.volunteer-field button, .volunteer-field a { min-block-size: 44px; }
</style>
