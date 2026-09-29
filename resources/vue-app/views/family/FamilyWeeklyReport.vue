<template>
    <div :dir="dir" :lang="lang" class="weekly-report">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
            <router-link :to="`/family/${masjidId}/classes/${groupId}`"
                         class="text-decoration-none small d-inline-flex align-items-center gap-1">
                <i :class="backIcon"></i>{{ t('weekly_report_back') }}
            </router-link>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1"
                        :disabled="loading || !!error" @click="printReport">
                    <i class="bi bi-printer"></i>{{ t('weekly_report_print') }}
                </button>
                <FamilyLangPicker />
            </div>
        </div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border text-success"></span></div>

        <!-- A failed read is said out loud. It is never drawn as a week with no
             points: "nothing was recorded" is a sentence about the child, and a
             parent who reads it when the truth is "we could not ask" has been told
             something the school never said. -->
        <div v-else-if="error" class="alert alert-danger">{{ t(error) }}</div>

        <template v-else-if="group">
            <h1 class="h4 mb-0" dir="auto">{{ group.name }}</h1>
            <p class="text-muted mb-3">{{ t('weekly_report_open') }}</p>

            <!-- THE WEEK IN VIEW, with its neighbours. The dates and the neighbours are
                 the server's (App\Support\PointsWeek, on the SCHOOL's clock): this page
                 never works a week out from the parent's own clock, whose zone is not
                 the school's. "Next" is disabled on the week in progress rather than
                 offered as a link to a week that has not happened. -->
            <div v-if="week" class="d-flex justify-content-between align-items-center mb-3 gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary no-print" :aria-label="t('weekly_report_prev')"
                        :title="t('weekly_report_prev')" @click="goTo(week.previous)">
                    <i :class="prevIcon"></i>
                </button>
                <span class="fw-semibold text-center">{{ weekLabel }}</span>
                <button type="button" class="btn btn-sm btn-outline-secondary no-print" :aria-label="t('weekly_report_next')"
                        :title="t('weekly_report_next')" :disabled="week.is_current" @click="goTo(week.next)">
                    <i :class="nextIcon"></i>
                </button>
            </div>

            <div v-for="child in group.children" :key="child.membership_id" class="card border-0 shadow-sm mb-3 report-child">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <PersonAvatar
                            :avatar="child.contact?.avatar"
                            :first-name="child.contact?.first_name"
                            :last-name="child.contact?.last_name"
                            :size="48" />
                        <h2 class="h6 mb-0 flex-grow-1" dir="auto">{{ childName(child) }}</h2>
                        <div v-if="reports[child.membership_id]?.summary" class="align-end">
                            <div class="fs-4 fw-semibold lh-1" dir="ltr">{{ signedPoints(reports[child.membership_id].summary.totals?.points) }}</div>
                            <div class="text-muted small">{{ week?.is_current ? t('points_this_week') : t('weekly_report_points') }}</div>
                        </div>
                    </div>

                    <p v-if="reports[child.membership_id] === null" class="text-danger small mb-0">
                        {{ t('weekly_report_load_error') }}
                    </p>

                    <template v-else-if="reports[child.membership_id]">
                        <p v-if="!reports[child.membership_id].awards.length" class="text-muted small mb-0">
                            {{ t('weekly_report_none') }}
                        </p>

                        <template v-else>
                            <!-- Positives first, then negatives (the picker's own order),
                                 exactly as the server sorted the summary. -->
                            <ul class="list-unstyled mb-3">
                                <li v-for="row in reports[child.membership_id].summary.by_skill" :key="`${row.polarity}-${row.skill_label}`"
                                    class="d-flex justify-content-between align-items-baseline small py-1 border-bottom">
                                    <span dir="auto">{{ row.skill_label }}<span class="text-muted"> × {{ row.awards }}</span></span>
                                    <span class="badge" dir="ltr"
                                          :class="row.polarity === 'negative' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis'">
                                        {{ signedPoints(row.points) }}
                                    </span>
                                </li>
                            </ul>

                            <h3 class="text-uppercase text-muted small">{{ t('points_history') }}</h3>
                            <p v-if="reports[child.membership_id].truncated" class="text-muted small">
                                {{ t('weekly_report_truncated', String(reports[child.membership_id].awards.length)) }}
                            </p>
                            <ul class="list-unstyled mb-0">
                                <li v-for="a in reports[child.membership_id].awards" :key="a.id" class="d-flex gap-2 align-items-baseline">
                                    <span class="badge" dir="ltr"
                                          :class="a.polarity === 'negative' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis'">
                                        {{ awardPointsLabel(a) }}
                                    </span>
                                    <span class="small" dir="auto">
                                        {{ a.skill_label }}
                                        <span class="text-muted">· {{ when(a.awarded_at) }}</span>
                                        <span v-if="a.note" class="text-muted"> — {{ a.note }}</span>
                                    </span>
                                </li>
                            </ul>
                        </template>
                    </template>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import FamilyApiService, { rowsOf } from '@/core/services/FamilyApiService';
import { awardPointsLabel } from '@/core/helpers/behaviorSkills';
import { signedPoints, weekFromQuery, weekRangeLabel, weeklyReportOn } from '@/core/helpers/pointsWeek';
import { useFamilyStore } from '@/stores/familyStore';
import { useFamilyLang } from '@/views/family/familyI18n';
import FamilyLangPicker from '@/views/family/FamilyLangPicker.vue';
import { beginClassRun, loadWeeklyReportFor } from '@/views/family/familyClassRun';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * The printable weekly points report (T-003.3): what the Friday email links to.
 *
 * The email carries no child's name and no figure (owner, B5), so this page is where the
 * week is READ, and it asks nothing the parent could not already ask from the class
 * screen: the same ward-edge-gated `/awards` and `/awards/summary` endpoints, with
 * `?week=`. There is no class-wide call and no other family's child anywhere on it;
 * each card is one of THIS parent's own children (the class payload's `children`).
 *
 * The figures are the server's, on the school's clock. Nothing is summed here, no week
 * is worked out here, and a failed read is a sentence saying so, never a zero.
 */
const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();
const { lang, isRtl, dir, locale, t } = useFamilyLang();

const masjidId = computed(() => String(route.params.masjidId));
const groupId = computed(() => String(route.params.groupId));
const base = computed(() => `/api/family/masjids/${masjidId.value}/groups/${groupId.value}`);

const backIcon = computed(() => (isRtl.value ? 'bi bi-arrow-right' : 'bi bi-arrow-left'));
// A week to the left is the EARLIER one in a left-to-right page and the later one in a
// right-to-left page, so the glyphs are chosen, not flipped by CSS (Bootstrap Icons
// have no logical variants).
const prevIcon = computed(() => (isRtl.value ? 'bi bi-chevron-right' : 'bi bi-chevron-left'));
const nextIcon = computed(() => (isRtl.value ? 'bi bi-chevron-left' : 'bi bi-chevron-right'));

const group = ref<any>(null);
const loading = ref(true);
/** An i18n KEY, never a sentence: a parent who switches language sees it in the new one. */
const error = ref<string>('');
/** The week in view, as the server described it (its dates, neighbours and whether it is the current one). */
const week = ref<any>(null);
/** Per child: `undefined` = loading, `null` = the read failed, else the summary and the week's awards. */
const reports = ref<Record<string, { summary: any; awards: any[]; truncated: boolean } | null>>({});

const weekLabel = computed(() => (week.value ? weekRangeLabel(week.value.start, week.value.end, locale.value) : ''));

const childName = (child: any) =>
    [child?.contact?.first_name, child?.contact?.last_name].filter(Boolean).join(' ') || t('student');

const when = (iso: string | null) => {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(locale.value, { month: 'short', day: 'numeric', year: 'numeric' });
};

let unmounted = false;

const fail = (e: any) => {
    if (familyStore.handleAuthFailure(e, masjidId.value)) {
        router.replace(`/family/${masjidId.value}/sign-in`);
        return true;
    }
    return false;
};

const beginRun = () => beginClassRun({
    masjidId: () => masjidId.value,
    groupId: () => groupId.value,
    base: () => base.value,
    unmounted: () => unmounted,
    fail,
});

/** Read every one of this parent's children for `weekParam` ('current' or a week's first day). */
const loadWeek = async (run: ReturnType<typeof beginRun>, weekParam: string) => {
    reports.value = {};
    await loadWeeklyReportFor(run, group.value?.children ?? [], weekParam, {
        setReport: (id, value) => { reports.value[id] = value; },
        setWeek: (value) => { week.value = value; },
    });
};

const goTo = async (start: string) => {
    await loadWeek(beginRun(), start);
};

// Print only the report. The navbar belongs to the layout above this view, so it is
// hidden through a class on <body> that exists only while this page is mounted.
const printReport = () => window.print();

onMounted(async () => {
    document.body.classList.add('family-report-page');
    const run = beginRun();

    try {
        const res = await FamilyApiService.get(run.base);
        if (run.stale()) return;
        group.value = res.data?.data ?? null;
        // No report where the school has not turned it on: nothing was emailed about it, and
        // the awards endpoints would refuse its weeks. Back to the class screen, silently.
        if (!weeklyReportOn(group.value)) {
            router.replace(`/family/${masjidId.value}/classes/${groupId.value}`);
            return;
        }
        // The week the emailed link named (?week=), else the week in progress: a parent who opens
        // Friday's report on Sunday must still see the week it was about.
        await loadWeek(run, weekFromQuery(route.query.week) ?? 'current');
    } catch (e: any) {
        if (run.stale()) return;
        if (!fail(e)) error.value = 'weekly_report_load_error';
    } finally {
        loading.value = false;
    }
});

onBeforeUnmount(() => {
    unmounted = true;
    document.body.classList.remove('family-report-page');
});
</script>

<style>
/* The print sheet: the report alone, on white. Global (not scoped) because the navbar
   is the layout's; safe because it is keyed on a body class this view adds and removes. */
@media print {
    body.family-report-page .navbar,
    body.family-report-page .no-print { display: none !important; }
    body.family-report-page { background: #fff !important; }
    body.family-report-page .report-child { box-shadow: none !important; border: 1px solid #ddd !important; break-inside: avoid; }
}
</style>

<style scoped>
/* Logical, not physical: this app loads the LTR build of Bootstrap, where .text-end is
   `text-align: right` and would pin the figure to the wrong edge of an Arabic card. */
.align-end { text-align: end; }
</style>
