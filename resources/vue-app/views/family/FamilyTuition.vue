<template>
    <main :dir="dir" :lang="lang">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><h1 class="h4">{{ t('tuition_title') }}</h1><FamilyLangPicker /></div>
        <p v-if="error" class="alert alert-warning" role="alert">{{ t(error) }}</p>
        <p v-if="pending" class="alert alert-info" role="status">{{ t('tuition_pending') }}</p>
        <p v-if="loaded && !accounts.length">{{ t('tuition_empty') }}</p>
        <section v-for="account in accounts" :key="account.id" class="card p-3 mb-3">
            <!-- Names and amounts are fenced from browser machine translation: it turned children's
                 names into nonsense words and can mangle figures a family pays against. -->
            <h2 class="h5"><template v-for="(c, i) in account.children" :key="c.id"><template v-if="i">{{ t('tuition_list_separator') }}</template><bdi class="notranslate" translate="no">{{ c.name }}</bdi></template></h2>
            <!-- A negative balance read as a debt with a minus sign; say what it is instead. -->
            <p v-if="account.balance_minor < 0">{{ t('tuition_paid_ahead') }}: <bdi class="notranslate" translate="no">{{ money(-account.balance_minor, account.currency) }}</bdi></p>
            <p v-else :class="{ 'mb-1': owedItems(account).length }">{{ t('tuition_balance') }}: <bdi class="notranslate" translate="no">{{ money(account.balance_minor, account.currency) }}</bdi></p>
            <!-- One total left a family guessing whether it was tuition after a discount or two fees; list its parts. -->
            <div v-if="account.balance_minor > 0 && owedItems(account).length" class="mb-3 tuition-owed">
                <span>{{ t('tuition_owed_items') }}:</span>
                <ul class="mb-0">
                    <li v-for="item in owedItems(account)" :key="item.line_id"><!-- One isolate for the description and the child's name, brackets included: separate isolates leave the brackets to the page direction and an Arabic page flips them. --><bdi>{{ item.description }}<template v-if="item.child"> (<span class="notranslate" translate="no">{{ item.child }}</span>)</template></bdi>:<bdi class="notranslate" translate="no">{{ money(item.amount_minor, account.currency) }}</bdi><template v-if="account.school_today && item.due_on > account.school_today">{{ t('tuition_list_separator') }}{{ t('tuition_due_by', date(item.due_on)) }}</template></li>
                </ul>
            </div>
            <p v-if="account.next_due_on">{{ t('tuition_coming_up') }}: <bdi class="notranslate" translate="no">{{ money(account.next_due_minor, account.currency) }}</bdi> {{ t('tuition_on') }} {{ date(account.next_due_on) }}</p>
            <p v-if="!account.card_available">{{ t('tuition_card_unavailable') }}</p>
            <form v-else-if="account.balance_minor > 0" class="d-flex flex-wrap gap-2 align-items-end mb-3" @submit.prevent="pay(account)">
                <label>{{ t('tuition_amount') }} ({{ account.currency.toUpperCase() }})<input v-model="amounts[account.id]" class="form-control" inputmode="decimal" required /></label>
                <button class="btn btn-outline-primary" type="button" @click="amounts[account.id] = decimal(account.balance_minor, account.currency)">{{ t('tuition_pay_full') }}</button>
                <button class="btn btn-primary" :disabled="busy" type="submit">{{ t('tuition_pay') }}</button>
                <small v-if="account.minimum_payment_minor">{{ t('tuition_minimum') }}: <bdi class="notranslate" translate="no">{{ money(account.minimum_payment_minor, account.currency) }}</bdi></small>
            </form>
            <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
                <label>{{ t('tuition_from') }}<input v-model="ranges[account.id].from" class="form-control" type="date" /></label>
                <label>{{ t('tuition_to') }}<input v-model="ranges[account.id].to" class="form-control" type="date" /></label>
                <button class="btn btn-outline-primary" :disabled="busy" @click="history(account)">{{ t('tuition_history') }}</button>
                <button class="btn btn-outline-primary" :disabled="busy" @click="download(account)">{{ t('tuition_download') }}</button>
            </div>
            <template v-if="statements[account.id]">
                <p>{{ t('tuition_opening') }}: <bdi class="notranslate" translate="no">{{ money(statements[account.id].opening_minor, account.currency) }}</bdi></p>
                <div class="table-responsive"><table class="table"><thead><tr><th>{{ t('tuition_date') }}</th><th>{{ t('tuition_child') }}</th><th>{{ t('tuition_entry') }}</th><th>{{ t('tuition_description') }}</th><th>{{ t('tuition_amount') }}</th></tr></thead><tbody>
                    <tr v-for="line in statements[account.id].lines" :key="line.id"><td>{{ date(line.date) }}</td><td><bdi class="notranslate" translate="no">{{ line.child }}</bdi></td><td>{{ t('tuition_' + line.kind) }}<span v-if="line.method">{{ t('tuition_list_separator') }}{{ t('tuition_method_' + line.method) }}</span></td><td>{{ line.description }}</td><td><bdi class="notranslate" translate="no">{{ money(line.amount_minor, account.currency) }}</bdi></td></tr>
                </tbody></table></div>
                <p>{{ t('tuition_closing') }}: <bdi class="notranslate" translate="no">{{ money(statements[account.id].closing_minor, account.currency) }}</bdi></p>
            </template>
            <small>{{ t('tuition_school_recorded') }}</small>
        </section>
    </main>
</template>
<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import FamilyApiService from '@/core/services/FamilyApiService';
import FamilyLangPicker from './FamilyLangPicker.vue';
import { useFamilyLang } from './familyI18n';
import { tuitionMinor, tuitionMoney as money, tuitionDecimal as decimal } from '@/core/helpers/tuitionMoney';
import { formatSchoolDay } from '@/core/types/data/masjid-related/SchoolCalendar';
const { t, dir, lang, locale } = useFamilyLang();
const route = useRoute(), base = `/api/family/masjids/${route.params.masjidId}/tuition`;
const accounts = ref<any[]>([]), statements = ref<Record<number, any>>({}), amounts = ref<Record<number, string>>({}), ranges = ref<Record<number, {from:string;to:string}>>({});
const loaded = ref(false), busy = ref(false), pending = ref(route.query.checkout === 'success'), error = ref('');
const date = (value: string) => formatSchoolDay(value, locale.value);
// Older payloads (a cached page during a deploy) have no breakdown; show the total alone then.
const owedItems = (a: any): any[] => Array.isArray(a.owed_items) ? a.owed_items : [];
const query = (a: any) => new URLSearchParams(ranges.value[a.id]).toString();
async function history(a: any) {
    busy.value = true; error.value = '';
    try { statements.value[a.id] = (await FamilyApiService.get(`${base}/${a.id}?${query(a)}`)).data.data.statement; }
    catch { error.value = 'tuition_load_error'; }
    finally { busy.value = false; }
}
async function pay(a: any) {
    if (busy.value) return;
    const minor = tuitionMinor(amounts.value[a.id] ?? '', a.currency);
    if (minor === null || minor > a.balance_minor || minor < a.minimum_payment_minor) { error.value = 'tuition_payment_error'; return; }
    busy.value = true; error.value = '';
    try { const res = await FamilyApiService.post(`${base}/${a.id}/checkout`, { amount_minor: minor }); window.location.assign(res.data.data.url); }
    catch { error.value = 'tuition_payment_error'; busy.value = false; }
}
async function download(a: any) {
    busy.value = true; error.value = '';
    try { const url = await FamilyApiService.blobUrl(`${base}/${a.id}/statement?${query(a)}`); const link = document.createElement('a'); link.href = url; link.download = 'tuition-statement.pdf'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }
    catch { error.value = 'tuition_load_error'; }
    finally { busy.value = false; }
}
onMounted(async () => {
    try {
        accounts.value = (await FamilyApiService.get(base)).data.data.accounts;
        for (const a of accounts.value) { amounts.value[a.id] = decimal(Math.max(0, a.balance_minor), a.currency); ranges.value[a.id] = { from: a.default_from ?? a.school_today ?? new Date().toISOString().slice(0,10), to: a.school_today ?? new Date().toISOString().slice(0,10) }; await history(a); }
    } catch { error.value = 'tuition_load_error'; }
    finally { loaded.value = true; }
});
</script>
