<template>
    <section class="card family-deposit" :dir="dir" :lang="lang"><div class="card-body">
        <h1 class="h5">{{ t('reenrol_deposit') }}<span v-if="page"> · {{ page.name }}</span></h1>
        <p v-if="page?.child_name" class="fw-semibold" dir="auto">{{ page.child_name }}</p>
        <p v-if="error" class="alert alert-danger" role="alert">{{ t('reenrol_error') }}<span v-if="issues" class="d-block" dir="auto">{{ issues }}</span></p>
        <p v-if="saved" role="status">{{ t('reenrol_saved') }} · {{ t(paid ? 'reenrol_paid' : 'reenrol_unpaid') }}</p>
        <p v-if="loading" role="status">{{ t('cal_loading') }}</p>
        <form v-if="page && !saved" @submit.prevent="submit">
            <template v-for="section in page.schema.sections" :key="section.id">
                <h2 v-if="section.title" class="h6" dir="auto">{{ section.title }}</h2>
                <p v-if="section.description" dir="auto">{{ section.description }}</p>
                <template v-if="section.repeatable">
                    <template v-for="(entry, index) in answers[section.id]" :key="index">
                        <FamilyDepositField v-for="field in section.fields" :key="field.name" :input-id="`${section.id}-${index}-${field.name}`" :field="field" v-model="entry[field.name]" />
                    </template>
                </template>
                <template v-else>
                    <FamilyDepositField v-for="field in section.fields" :key="field.name" :input-id="`${section.id}-${field.name}`" :field="field" v-model="answers[field.name]" @file="files[field.name] = $event" />
                </template>
            </template>
            <label class="form-label">{{ t('reenrol_payment_method') }}
                <select class="form-select" v-model="payWith"><option v-if="page.card" value="card">{{ t('reenrol_card') }}</option><option v-if="page.office" value="office">{{ t('reenrol_office_payment') }}</option></select>
            </label>
            <p v-if="payWith === 'office' && page.office_instructions" dir="auto">{{ page.office_instructions }}</p>
            <label v-if="payWith === 'card' && page.cover_fees && !page.requires_fee_coverage" class="d-block mb-2"><input type="checkbox" v-model="coverFees" /> {{ t('reenrol_cover_fees') }}</label>
            <p v-if="quote">{{ t('reenrol_total') }}: {{ money(quote.total_minor, quote.currency) }}</p>
            <button class="btn btn-primary" :disabled="busy || quoting || !quote || !payWith">{{ t('reenrol_submit') }}</button>
        </form>
        <button v-if="uuid && !paid" class="btn btn-outline-primary mt-2" :disabled="busy" @click="readPayment">{{ t('reenrol_check_payment') }}</button>
        <button v-if="canPay && uuid" class="btn btn-outline-primary mt-2" :disabled="busy" @click="resume">{{ t('reenrol_pay_now') }}</button>
        <a class="d-block mt-3" :href="back">{{ t('reenrol_back') }}</a>
    </div></section>
</template>
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import FamilyApiService from '@/core/services/FamilyApiService';
import { serverMessage } from '@/core/helpers/serverMessage';
import FamilyDepositField from '@/components/forms/FamilyDepositField.vue';
import { useFamilyLang } from '@/views/family/familyI18n';
const route = useRoute(); const { t, lang, dir, locale } = useFamilyLang();
const school = computed(() => String(route.params.masjidId));
const back = computed(() => `/family/${school.value}/classes/${route.params.groupId}`);
const endpoint = () => `/api/family/masjids/${school.value}/groups/${route.params.groupId}/members/${route.params.membershipId}/reenrolment/${route.params.reenrolmentId}/deposit`;
const page = ref<any>(null); const answers = ref<Record<string, any>>({}); const files = ref<Record<string, File | null>>({}); const quote = ref<any>(null); const payWith = ref(''); const coverFees = ref(false); const busy = ref(false); const quoting = ref(false); const loading = ref(false); const error = ref(false); const issues = ref(''); const saved = ref(false); const paid = ref(false); const canPay = ref(false); const uuid = ref(''); let clientKey = ''; let generation = 0; let quoteRun = 0; let alive = true;
const money = (minor: number, currency: string) => new Intl.NumberFormat(locale.value, { style: 'currency', currency }).format(minor / 100);
// Native fetch deliberately carries no family/staff token into the public Forms realm.
async function publicCall(path: string, body?: any) {
    const formData = body instanceof FormData;
    const response = await fetch(`${import.meta.env?.VITE_APP_URL ?? ''}/api/v1/${path}`, { method: body ? 'POST' : 'GET', credentials: 'omit', headers: { 'masjid-id': school.value, Accept: 'application/json', ...(body && !formData ? { 'Content-Type': 'application/json' } : {}) }, body: body ? (formData ? body : JSON.stringify(body)) : undefined });
    const result = await response.json(); if (!response.ok) throw { response: { status: response.status, data: result } }; return result.data;
}
async function load() {
    const run = ++generation; page.value = null; answers.value = {}; files.value = {}; quote.value = null; error.value = false; issues.value = ''; saved.value = false; paid.value = false; canPay.value = false; uuid.value = ''; loading.value = true; clientKey = crypto.randomUUID();
    try { const r = await FamilyApiService.get(endpoint()); if (!alive || run !== generation) return; page.value = r.data.data; answers.value = page.value.data;
        for (const section of page.value.schema.sections) if (section.repeatable && !answers.value[section.id]) answers.value[section.id] = [{}];
        payWith.value = page.value.card ? 'card' : page.value.office ? 'office' : ''; quote.value = page.value.quote;
        if (typeof route.query.form_response === 'string') { if (!page.value.response_uuids?.includes(route.query.form_response)) { page.value = null; throw new Error('Wrong deposit response'); } uuid.value = route.query.form_response; }
        else uuid.value = page.value.response_uuids?.[0] ?? '';
        if (uuid.value) { saved.value = true; await readPayment(); }
    } catch (e: any) { if (alive && run === generation) { error.value = true; issues.value = typeof e.response?.data?.message === 'string' ? e.response.data.message : serverMessage(e, ''); } } finally { if (alive && run === generation) loading.value = false; }
}
async function price() { if (!page.value || saved.value) return; const request = ++quoteRun; const run = generation; quoting.value = true; error.value = false; issues.value = ''; try { const r = await FamilyApiService.post(`${endpoint()}/quote`, { data: answers.value, pay_with: payWith.value, cover_fees: coverFees.value }); if (alive && run === generation && request === quoteRun) quote.value = r.data.data; } catch (e: any) { if (alive && run === generation && request === quoteRun) { quote.value = null; error.value = true; issues.value = typeof e.response?.data?.message === 'string' ? e.response.data.message : serverMessage(e, ''); } } finally { if (alive && run === generation && request === quoteRun) quoting.value = false; } }
async function submit() {
    if (busy.value) return; const run = generation; busy.value = true; error.value = false; issues.value = '';
    // The submissions endpoint reads `data` as an array: send it as nested fields (a JSON string was refused with 422).
    const body = new FormData(); appendNested(body, 'data', answers.value); body.append('reenrolment_token', page.value.token); body.append('client_submission_key', clientKey); body.append('pay_with', payWith.value); body.append('cover_fees', coverFees.value ? '1' : '0'); body.append('return_path', location.pathname);
    for (const [key, file] of Object.entries(files.value)) if (file) body.append(`files[${key}]`, file);
    try { const result = await publicCall(`forms/${page.value.form_id}/responses`, body); if (!alive || run !== generation) return; uuid.value = result.uuid ?? ''; saved.value = true; if (result.checkout_url) location.assign(result.checkout_url); else if (uuid.value) await readPayment(); }
    catch (e: any) { if (alive && run === generation) { error.value = true; issues.value = typeof e.response?.data?.message === 'string' ? e.response.data.message : serverMessage(e, ''); if (e.response?.data?.data?.uuid) { uuid.value = e.response.data.data.uuid; saved.value = true; canPay.value = false; await readPayment(); } } }
    finally { if (alive && run === generation) busy.value = false; }
}
function appendNested(body: FormData, key: string, value: any): void {
    if (Array.isArray(value)) { value.forEach((item, i) => appendNested(body, `${key}[${i}]`, item)); return; }
    if (value !== null && typeof value === 'object') { for (const [k, v] of Object.entries(value)) appendNested(body, `${key}[${k}]`, v); return; }
    body.append(key, value === null || value === undefined ? '' : typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
}
async function readPayment() { const run = generation; try { const result = await publicCall(`form-responses/${uuid.value}`); if (alive && run === generation) { if (result.form_id !== page.value.form_id) throw new Error('Wrong form'); paid.value = !result.cancelled && result.payment_status === 'paid'; canPay.value = result.can_pay; } } catch (e: any) { if (alive && run === generation) { error.value = true; issues.value = typeof e.response?.data?.message === 'string' ? e.response.data.message : serverMessage(e, ''); } } }
async function resume() { if (busy.value) return; const run = generation; busy.value = true; try { const result = await publicCall(`form-responses/${uuid.value}/checkout`, { return_path: location.pathname, reenrolment_token: page.value.token }); if (alive && run === generation && result.checkout_url) location.assign(result.checkout_url); } catch (e: any) { if (alive && run === generation) { error.value = true; issues.value = typeof e.response?.data?.message === 'string' ? e.response.data.message : serverMessage(e, ''); } } finally { if (alive && run === generation) busy.value = false; } }
watch(() => [route.params.masjidId, route.params.groupId, route.params.membershipId, route.params.reenrolmentId], load, { immediate: true });
watch([answers, payWith, coverFees], price, { deep: true }); onBeforeUnmount(() => { alive = false; ++generation; ++quoteRun; });
</script>
<style scoped>
.family-deposit { min-width: 0; max-width: 100%; overflow-wrap: anywhere; text-align: start; }
</style>
