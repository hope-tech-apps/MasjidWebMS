<template>
    <div class="mb-3">
        <h3 class="text-uppercase text-muted small">{{ t('bucks_section') }}</h3>

        <!-- A failed read is said out loud. It is never drawn as a balance of 0:
             "nothing yet" is a sentence about the child, and a parent who reads it
             when the truth is "we could not ask" has been told something the school
             never said. -->
        <p v-if="failed" class="text-danger small">{{ t('bucks_failed') }}</p>

        <template v-else-if="loaded">
            <div class="d-flex justify-content-between align-items-baseline">
                <span class="small fw-semibold">{{ t('bucks_balance') }}</span>
                <span class="fw-semibold" dir="ltr">{{ balance }}</span>
            </div>
            <p class="text-muted small mb-2">{{ t('bucks_explain') }} {{ rateText }}</p>

            <p v-if="!entries.length" class="text-muted small">{{ t('bucks_none') }}</p>
            <ul v-else class="list-unstyled mb-2">
                <li v-for="e in entries" :key="e.id" class="d-flex gap-2 align-items-baseline">
                    <span class="badge" :class="e.amount < 0 ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis'"
                          dir="ltr">{{ signed(e.amount) }}</span>
                    <span class="small" dir="auto">
                        {{ line(e) }}
                        <span class="text-muted">· {{ when(e.occurred_at) }}</span>
                        <span v-if="e.is_reversed" class="badge bg-secondary-subtle text-secondary ms-1">{{ t('bucks_undone') }}</span>
                    </span>
                </li>
            </ul>
            <button v-if="hasMore" type="button" class="btn btn-link btn-sm p-0 text-decoration-none" :disabled="moreBusy" @click="loadMore">
                {{ t('bucks_more') }}
            </button>
        </template>
    </div>
</template>

<script setup lang="ts">
import FamilyApiService, { rowsOf } from '@/core/services/FamilyApiService';
import { useFamilyLang } from '@/views/family/familyI18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * One child's Manara Bucks on the class screen (T-003.4): the balance and the history, read
 * from the child's own endpoint (the ward edge decides, on the server). READ-ONLY: a parent
 * cannot spend or reverse anything, and there is no other child's balance anywhere near this
 * component, no rank and no comparison.
 *
 * Everything numeric is the server's. The only words that are the school's are the prize's
 * title, drawn exactly as it was written (and never translated by the chrome); the rest is
 * the portal's own table in the parent's language. A response that arrives after the screen
 * is gone (the parent switched school) is dropped, so it can never paint into a screen now
 * labelled for another school.
 */
const props = defineProps<{ base: string; memberId: number }>();

const { t, locale } = useFamilyLang();

const loaded = ref(false);
const failed = ref(false);
const balance = ref(0);
const pointsPerBuck = ref(1);
const entries = ref<any[]>([]);
const page = ref(1);
const lastPage = ref(1);
const moreBusy = ref(false);
let alive = true;
onBeforeUnmount(() => { alive = false; });

const hasMore = computed(() => page.value < lastPage.value);
const rateText = computed(() => pointsPerBuck.value === 1 ? t('bucks_rate_one') : t('bucks_rate_many', String(pointsPerBuck.value)));

const signed = (n: number) => `${n > 0 ? '+' : ''}${n}`;
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(locale.value, { month: 'short', day: 'numeric' }) : '');

/** What a line was for: the kind in the parent's language, with the week or the prize's own title. */
function line(e: any): string {
    const kind = t(`bucks_kind_${e.kind}`);

    if (e.kind === 'redeemed' || e.kind === 'reversal') return e.prize_title ? `${kind}: ${e.prize_title}` : kind;
    if ((e.kind === 'earned' || e.kind === 'adjusted') && e.week_start) {
        const d = new Date(`${String(e.week_start).slice(0, 10)}T00:00:00Z`);
        const day = d.toLocaleDateString(locale.value, { month: 'short', day: 'numeric', timeZone: 'UTC' });

        return `${kind} · ${t('bucks_week_of', day)}`;
    }

    return kind;
}

async function fetchPage(n: number) {
    const res = await FamilyApiService.get(`${props.base}/members/${props.memberId}/bucks?page=${n}&per_page=25`);
    if (!alive) return null;

    return res;
}

async function load() {
    try {
        const res = await fetchPage(1);
        if (!res) return;
        balance.value = Number(res.data?.meta?.balance ?? 0);
        pointsPerBuck.value = Number(res.data?.meta?.points_per_buck ?? 1) || 1;
        entries.value = rowsOf(res.data?.data);
        page.value = Number(res.data?.data?.current_page ?? 1);
        lastPage.value = Number(res.data?.data?.last_page ?? 1);
        loaded.value = true;
    } catch {
        if (alive) failed.value = true;
    }
}

async function loadMore() {
    if (moreBusy.value || !hasMore.value) return;
    moreBusy.value = true;
    try {
        const res = await fetchPage(page.value + 1);
        if (!res) return;
        entries.value = entries.value.concat(rowsOf(res.data?.data));
        page.value = Number(res.data?.data?.current_page ?? page.value + 1);
        lastPage.value = Number(res.data?.data?.last_page ?? lastPage.value);
    } catch {
        if (alive) failed.value = true;
    } finally {
        moreBusy.value = false;
    }
}

onMounted(load);
</script>
