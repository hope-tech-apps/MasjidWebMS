<template>
    <section class="family-reenrolment" :dir="dir" :lang="lang">
        <h2 class="h5">{{ t('reenrol_title') }}</h2>
        <template v-for="campaign in campaigns" :key="campaign.id">
            <article v-for="child in children" :key="`${campaign.id}:${child.membership_id}`" class="card mb-3">
                <div class="card-body">
                    <h3 class="h6" dir="auto">{{ childName(child) }} · {{ campaign.year }}</h3>
                    <p v-if="errors[key(campaign, child)]" class="text-danger" role="alert">{{ t('reenrol_error') }}</p>
                    <template v-if="records[key(campaign, child)]">
                        <p class="small">{{ t('reenrol_next_grade') }}: <span dir="auto">{{ record(campaign, child).next_grade }}</span></p>
                        <p v-if="record(campaign, child).answer" class="small" role="status">
                            {{ t(record(campaign, child).answer === 'returning' ? 'reenrol_returning' : 'reenrol_not_returning') }}
                            · <span dir="auto">{{ record(campaign, child).recorded_by_office ? t('reenrol_office') : record(campaign, child).answered_by }}</span>
                            <time v-if="record(campaign, child).answered_at"> · {{ stamp(record(campaign, child).answered_at) }}</time>
                        </p>
                        <p v-if="!record(campaign, child).open" class="text-muted">{{ t('reenrol_closed') }}</p>
                        <div v-else class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-primary" :disabled="busy[key(campaign, child)]" @click="answer(campaign, child, 'returning')">{{ t('reenrol_returning') }}</button>
                            <button type="button" class="btn btn-outline-secondary" :disabled="busy[key(campaign, child)]" @click="answer(campaign, child, 'not_returning')">{{ t('reenrol_not_returning') }}</button>
                        </div>
                        <p v-if="record(campaign, child).answer === 'returning' && record(campaign, child).deposit" class="mt-3 mb-0">
                            {{ t('reenrol_deposit') }}: {{ t(record(campaign, child).deposit.paid ? 'reenrol_paid' : 'reenrol_unpaid') }}
                            <a v-if="!record(campaign, child).deposit.paid && record(campaign, child).open" class="btn btn-link" :href="record(campaign, child).deposit.url">{{ t('reenrol_pay') }}</a>
                        </p>
                    </template>
                    <button v-else-if="errors[key(campaign, child)]" class="btn btn-outline-secondary" @click="load">{{ t('cal_retry') }}</button>
                    <p v-else role="status">{{ t('cal_loading') }}</p>
                </div>
            </article>
        </template>
    </section>
</template>
<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import FamilyApiService from '@/core/services/FamilyApiService';
import { useFamilyLang } from '@/views/family/familyI18n';
const props = defineProps<{ masjidId: string; groupId: string; children: any[]; campaigns: any[] }>();
const { t, lang, dir, locale } = useFamilyLang();
const records = ref<Record<string, any>>({}); const errors = ref<Record<string, boolean>>({}); const busy = ref<Record<string, boolean>>({});
const key = (campaign: any, child: any) => `${campaign.id}:${child.membership_id}`;
const record = (campaign: any, child: any) => records.value[key(campaign, child)];
const childName = (child: any) => [child.contact?.first_name, child.contact?.last_name].filter(Boolean).join(' ');
const stamp = (value: string) => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
let generation = 0; let alive = true;
function url(campaign: any, child: any) { return `/api/family/masjids/${props.masjidId}/groups/${props.groupId}/members/${child.membership_id}/reenrolment/${campaign.id}`; }
async function load() {
    const run = ++generation; records.value = {}; errors.value = {}; busy.value = {};
    const jobs = props.campaigns.flatMap(c => props.children.map(child => ({ k: key(c, child), url: url(c, child) })));
    for (const job of jobs) {
        if (!alive || run !== generation) return;
        try { const r = await FamilyApiService.get(job.url); if (alive && run === generation) records.value[job.k] = r.data.data; }
        catch { if (alive && run === generation) errors.value[job.k] = true; }
    }
}
async function answer(campaign: any, child: any, answer: string) {
    const k = key(campaign, child); const run = generation; if (busy.value[k]) return;
    busy.value[k] = true; errors.value[k] = false; const target = url(campaign, child);
    try { const r = await FamilyApiService.put(target, { answer }); if (alive && run === generation) records.value[k] = r.data.data; }
    catch { if (alive && run === generation) errors.value[k] = true; }
    finally { if (alive && run === generation) busy.value[k] = false; }
}
watch(() => [props.masjidId, props.groupId, props.children, props.campaigns], load, { immediate: true });
onBeforeUnmount(() => { alive = false; ++generation; });
</script>
<style scoped>
.family-reenrolment, .card-body { min-width: 0; max-width: 100%; overflow-wrap: anywhere; text-align: start; }
</style>
