<template>
    <div class="impact-report">
        <PageDataContainer title="Impact Report" :hideButton="true">
            <template #headerButtons>
                <!--
                    Print, not export. .claude/rules/impact-metrics.md rules a
                    PDF/CSV export out on purpose: an export has to carry every
                    figure's definition and its period header or it is worth
                    less than the JSON, and when one is built it reuses
                    App\Services\Receipts' DomPDF path rather than growing a
                    second one. The browser's own print dialog gives a funder a
                    PDF today, and the @media print block below makes sure the
                    definitions travel on the page.
                -->
                <button
                    class="btn btn-outline-secondary no-print"
                    :disabled="bootstrapping || !!loadError"
                    title="Print this report, or save it as a PDF"
                    @click="print"
                >
                    <i class="bi bi-printer me-1" aria-hidden="true"></i>
                    Print
                </button>
            </template>

            <div class="container w-100">

                <!-- 1 — Letterhead ------------------------------------------------------
                     What makes this a document rather than a dashboard: who it is
                     about, what window it covers, which clock the dates are in, and
                     when it was produced. Printed at the top of every page.
                -->
                <header class="letterhead">
                    <h2 class="letterhead-org">{{ organizationName }}</h2>
                    <p class="letterhead-title">Impact Report</p>
                    <p v-if="reportMeta" class="letterhead-scope mb-0">{{ periodSentence }}</p>
                </header>

                <!-- 2 — The reporting period -------------------------------------------
                     Screen only. A funder reads the letterhead sentence above, which
                     says the same thing in words.
                -->
                <section class="filters no-print" aria-label="Reporting period">
                    <div class="row g-3 align-items-end">
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="impact_from">From</label>
                            <input
                                id="impact_from"
                                v-model="fromDate"
                                type="date"
                                class="form-control"
                            />
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="impact_to">To</label>
                            <input
                                id="impact_to"
                                v-model="toDate"
                                type="date"
                                class="form-control"
                            />
                        </div>
                        <div class="col-lg-6 d-flex flex-wrap gap-2">
                            <!--
                                Disabled until the first response names the
                                organisation's clock. The presets are computed in
                                that zone, and `timezone` falls back to UTC before
                                meta arrives — so a click in the second the page is
                                loading gives a New York org "This year" starting on
                                UTC's today, which around local midnight on
                                31 December is the WRONG YEAR. The dates the report
                                is filed under must never be a race with the request.
                            -->
                            <button
                                v-for="preset in presets"
                                :key="preset.label"
                                type="button"
                                class="btn btn-outline-secondary btn-sm"
                                :disabled="bootstrapping"
                                @click="applyPreset(preset)"
                            >
                                {{ preset.label }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-link btn-sm text-decoration-none"
                                :disabled="!fromDate && !toDate"
                                @click="clearDates"
                            >
                                Clear dates
                            </button>
                        </div>
                    </div>

                    <p v-if="dateRangeInvalid" class="text-danger small mb-0 mt-2">
                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                        The “to” date cannot be earlier than the “from” date.
                    </p>

                    <!--
                        The presets are computed in the ORGANIZATION's timezone, not the
                        browser's. An admin in Karachi pulling "this year" for a New York
                        masjid must get the masjid's year, or the report's first and last
                        days are off by one against every figure inside it.
                    -->
                    <p v-else-if="reportMeta" class="text-muted small mb-0 mt-2">
                        Dates are read in {{ reportMeta.timezone }}, the organisation's own clock.
                    </p>
                </section>

                <!--
                    3 — The small-number caution.

                    THIS BANNER is screen only, and deliberately: it is advice to the
                    admin who is about to press Print ("widen the range first"), which
                    is an instruction nobody holding the paper can act on. The
                    per-figure caution on the card itself is a different thing — a
                    statement ABOUT the figure — and it prints, because the reader who
                    most needs to know a count is about one person is the one holding
                    the document.

                    The server applies NO cell suppression here (unlike
                    FormInsights::MIN_GROUP_FOR_BREAKDOWN), because every metric is
                    org-wide. But the date controls above can narrow a flow metric to a
                    single day, and "1 appointment request on 4 March" is a sentence
                    about one identifiable person. Nothing is hidden from the admin —
                    they can already open the intake queue and read the name — but they
                    are told before the page leaves the building.
                -->
                <div
                    v-if="smallCountLabels.length"
                    class="alert alert-warning small no-print"
                    role="status"
                >
                    <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>
                    <strong>Small numbers on this page.</strong>
                    {{ smallCountLabels.join(', ') }}
                    {{ smallCountLabels.length === 1 ? 'is' : 'are' }}
                    below {{ SMALL_COUNT_FLOOR }}. A figure that small, especially over a short
                    date range, can point at one identifiable person. Widen the range before
                    sharing or printing this report outside the organisation.
                </div>

                <!-- First paint: nothing to show yet, not even a shape -->
                <div v-if="bootstrapping" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <div v-else-if="loadError" class="text-center py-5 text-muted">
                    <i class="bi bi-exclamation-triangle fs-1 d-block mb-3 text-warning" aria-hidden="true"></i>
                    <p class="mb-2">The impact figures could not be loaded.</p>
                    <button class="btn btn-outline-primary btn-sm" @click="load()">Try again</button>
                </div>

                <!--
                    Nothing selected for this window. A grid of zeroes would read as
                    "we did nothing", which is a different claim from "the window you
                    asked about holds no records" — the DonationsDashboardView
                    precedent.
                -->
                <div v-else-if="!metrics.length" class="text-center py-5">
                    <i class="bi bi-clipboard-data fs-1 d-block mb-3 text-muted" aria-hidden="true"></i>
                    <h5 class="mb-2">No figures yet for this period</h5>
                    <p class="text-muted mb-4">
                        Nothing this organisation records falls inside the dates you asked for.
                    </p>
                    <button
                        v-if="fromDate || toDate"
                        class="btn btn-outline-secondary no-print"
                        @click="clearDates"
                    >
                        Clear dates
                    </button>
                </div>

                <template v-else>
                    <!-- 4 — The figures, grouped by what they mean about time ---------- -->
                    <section
                        v-for="group in groupedMetrics"
                        :key="group.basis"
                        class="metric-group"
                        :class="{ 'is-refreshing': refreshing }"
                    >
                        <h6 class="group-heading">{{ group.heading }}</h6>
                        <p class="group-explainer">{{ group.explainer }}</p>

                        <div class="row g-3">
                            <div v-for="metric in group.metrics" :key="metric.key" class="col-md-6 col-xl-4">
                                <article class="metric-card h-100">
                                    <div class="metric-label">{{ metric.label }}</div>

                                    <!--
                                        `formatted` verbatim. A money metric's `value` is an
                                        integer in MINOR UNITS and the server is the only place
                                        it is divided; dividing it here is how this page and the
                                        giving dashboard start disagreeing about the same gift
                                        (.claude/rules/impact-metrics.md, "Money").
                                    -->
                                    <div class="metric-value tabular">{{ metric.formatted }}</div>

                                    <p class="metric-window">{{ windowSentence(metric) }}</p>

                                    <!--
                                        PRINTED, unlike the banner above. The banner is advice
                                        to the admin standing in front of the screen ("widen
                                        the range before you share this"); THIS is a statement
                                        about the figure itself, and the reader who most needs
                                        it is the one holding the paper — the funder reading
                                        "1" has no other way to know the count is about one
                                        identifiable person. Same reasoning as the print rule
                                        that forces every definition open: a caveat that
                                        travels only on screen is not a caveat on the document.
                                    -->
                                    <p v-if="isSmallCount(metric)" class="metric-caution">
                                        <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>
                                        Fewer than {{ SMALL_COUNT_FLOOR }} — small enough to point at
                                        a specific person.
                                    </p>

                                    <!--
                                        The definition is the deliverable, not the number. Folded
                                        away on screen so twenty of them do not bury the figures,
                                        and force-expanded by the print rules below so a funder
                                        never receives a count without knowing what was counted.
                                    -->
                                    <button
                                        type="button"
                                        class="btn btn-link p-0 definition-toggle no-print"
                                        :aria-expanded="isOpen(metric.key)"
                                        :aria-controls="`definition_${metric.key}`"
                                        @click="toggleDefinition(metric.key)"
                                    >
                                        <i
                                            class="bi me-1"
                                            :class="isOpen(metric.key) ? 'bi-chevron-down' : 'bi-chevron-right'"
                                            aria-hidden="true"
                                        ></i>
                                        Definition
                                    </button>

                                    <div
                                        :id="`definition_${metric.key}`"
                                        class="definition"
                                        :class="{ 'is-open': isOpen(metric.key) }"
                                    >
                                        <p class="definition-text mb-1">{{ metric.provenance.definition }}</p>
                                        <p class="definition-source mb-0">
                                            Source: {{ metric.provenance.source }}
                                        </p>
                                    </div>

                                    <!--
                                        The ONLY bridge to the published `impact_stats` section,
                                        and it is a human one by design: an admin copies a figure
                                        and decides, on the page it appears on, whether to publish
                                        it. There is no write path from this screen to
                                        `page_sections` and there must never be one — a published
                                        funder figure that changes because a row was inserted this
                                        morning is exactly what the T-020 boundary forbids.
                                    -->
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary copy-btn no-print"
                                        @click="copyFigure(metric)"
                                    >
                                        <i
                                            class="bi me-1"
                                            :class="copiedKey === metric.key ? 'bi-check2' : 'bi-clipboard'"
                                            aria-hidden="true"
                                        ></i>
                                        {{ copiedKey === metric.key ? 'Copied' : 'Copy figure' }}
                                    </button>

                                    <!--
                                        A copy that did not happen must never look like one
                                        that did. Without this the button is unchanged, and
                                        the admin pastes whatever was on the clipboard
                                        BEFORE into the grant application — the
                                        wrong-number-under-the-letterhead failure the rest
                                        of this screen exists to prevent.
                                    -->
                                    <p
                                        v-if="copyFailedKey === metric.key"
                                        class="copy-error no-print mb-0"
                                        role="alert"
                                    >
                                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                        Could not copy — this browser did not allow it. Select the
                                        figure above and copy it by hand.
                                    </p>
                                </article>
                            </div>
                        </div>
                    </section>
                </template>

                <!-- 5 — What is NOT here, and why ---------------------------------------
                     A reader has to be able to tell "we did not ask" from "the answer
                     was zero". Printed, because a funder reading a report with no money
                     figure in it deserves to know one was withheld rather than absent.
                -->
                <section v-if="!bootstrapping && !loadError && omitted.length" class="omitted">
                    <h6 class="group-heading">Not included in this report</h6>
                    <ul class="omitted-list">
                        <li v-for="entry in omitted" :key="entry.key">
                            <strong>{{ labelFor(entry.key) }}</strong> — {{ omissionSentence(entry.reason) }}
                        </li>
                    </ul>
                </section>

                <!-- 6 — The standing footnote -------------------------------------------
                     The T-020 boundary said out loud, on screen and on paper: what this
                     page counts is not what the website publishes, and the two answer
                     different questions on purpose.
                -->
                <footer class="report-footnote">
                    <p class="mb-0">
                        These are counts drawn from the records this organisation holds. They are not the
                        figures published on your website — those are edited by hand on the page they
                        appear on.
                    </p>
                </footer>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeMount, onBeforeUnmount, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import {
    ImpactMetric,
    ImpactMetricBasis,
    ImpactReportFilters,
    impactMetricLabel,
    impactMetricLabels
} from '@/core/types/data/masjid-related/ImpactReport';
import { useImpactReportStore } from '@/stores/masjid/impactReportStore';
import { useMasjidStore } from '@/stores/masjidStore';

/**
 * The impact report — the screen a grant application or a funder report is
 * assembled from, over App\Support\ImpactMetrics (T-024).
 *
 * This page is read by people OUTSIDE the organisation, so the constraints on
 * it are stricter than a dashboard's, and they are constraints on what may be
 * ADDED as much as on what is rendered today. From
 * .claude/rules/impact-metrics.md and the T-043f spec:
 *
 *  - NO individual person, ever. No names, emails, phones, addresses, contact
 *    ids, donor names, registrant names, credential holders, children. Every
 *    field in the payload is an org-wide aggregate and there is deliberately no
 *    drill-down link from any card. Do not add one.
 *  - NO per-group, per-class or per-program split. That is precisely where a
 *    count of one becomes a named child, and it would need .claude/rules/groups.md
 *    applied first.
 *  - NO appointment `reason` and no `date_of_birth`: encrypted at rest, never
 *    queried, and the aggregates never read them.
 *  - NO figure the platform cannot support — volunteer hours, attendance,
 *    visits delivered, the dollar value of donated services. The rule forbids
 *    stubbing or estimating them; each definition already says what its data
 *    cannot answer, which is the honest reply to a funder who asks.
 *  - NO write path to `page_sections`. No "publish to website", not even
 *    "prefill". The only bridge is Copy figure, and copying is an editorial act
 *    a human performs on the page the figure would be published on.
 *  - NO CSV/PDF export. Print is the interim on purpose — see the Print button.
 *
 * Every number on the page carries a sentence saying what it counts: the window
 * it covers under the figure, and `provenance.definition` verbatim behind a
 * disclosure that the print rules force open. A funder reading "412" without
 * knowing whether that is visits or people is worse off than one reading
 * nothing.
 */

// Stores
const reportStore = useImpactReportStore();
const masjidStore = useMasjidStore();

// State
const bootstrapping = ref(true);
const refreshing = ref(false);
const loadError = ref(false);

const fromDate = ref('');
const toDate = ref('');

/** Which definitions the admin has unfolded. Print expands them all regardless. */
const openDefinitions = ref<Set<string>>(new Set());

/** The card whose figure was just copied, so only that button says "Copied". */
const copiedKey = ref<string | null>(null);

/** The card whose copy FAILED. Keyed rather than a page-level flag so the
 *  message sits beside the button that failed, on the figure it is about. */
const copyFailedKey = ref<string | null>(null);
let copiedTimer: ReturnType<typeof setTimeout> | null = null;
let filterTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * The house's own suppression threshold, borrowed rather than invented:
 * FormInsights::MIN_GROUP_FOR_BREAKDOWN is 3, on the reasoning that a
 * distribution with very few contributors identifies people. ImpactMetrics
 * applies no threshold of its own because its figures are org-wide, so this is
 * a caution to the admin about sharing, not a suppression — see the banner.
 */
const SMALL_COUNT_FLOOR = 3;

/**
 * The three time bases, in the order they are read, with the difference stated
 * in plain words. This is the whole point of the `basis` field: a stock figure
 * printed under a date range it does not cover is a false statement, and the
 * heading is what stops a reader making it.
 */
const BASIS_ORDER: ImpactMetricBasis[] = ['period', 'as_of', 'current'];

const BASIS_COPY: Record<ImpactMetricBasis, { heading: string; explainer: string }> = {
    period: {
        heading: 'Over this period',
        explainer: 'Things that happened between these dates.'
    },
    as_of: {
        heading: 'As at a date',
        explainer: 'A standing count on one date, not a total for the window.'
    },
    current: {
        heading: 'As of today',
        explainer: 'Read from a live setting the platform keeps no history for, so it describes '
            + 'today even when you asked about an earlier year.'
    }
};

const DATE_PARTS: Intl.DateTimeFormatOptions = { year: 'numeric', month: 'short', day: 'numeric' };

// Computed
const metrics = computed<ImpactMetric[]>(() => reportStore.metrics);
const omitted = computed(() => reportStore.omitted);
const reportMeta = computed(() => reportStore.reportMeta);

const organizationName = computed<string>(() => masjidStore.masjid?.name || 'This organisation');

/** The clock every date on this page is read in. Falls back to UTC before the
 *  first response lands, and if the server ever sends a zone Intl refuses. */
const timezone = computed<string>(() => reportMeta.value?.timezone || 'UTC');

const dateRangeInvalid = computed<boolean>(
    () => !!fromDate.value && !!toDate.value && toDate.value < fromDate.value
);

const filters = computed<ImpactReportFilters>(() => ({
    from: fromDate.value,
    to: toDate.value
}));

/**
 * The letterhead sentence. Two shapes, because "all time" is a real answer and
 * an empty date range must not be left to the reader to interpret.
 */
const periodSentence = computed<string>(() => {
    const meta = reportMeta.value;
    if (!meta) return '';

    const generated = `Generated ${formatInstant(meta.generated_at)}.`;
    const from = meta.period?.from ?? null;
    const to = meta.period?.to ?? null;

    if (!from && !to) {
        return `This report covers all data held, with no date limit. Dated in ${meta.timezone}, `
            + `amounts in ${meta.currency}. ${generated}`;
    }

    const window = from && to
        ? `${formatDay(from)} – ${formatDay(to)}`
        : (from ? `from ${formatDay(from)} onwards` : `up to ${formatDay(to)}`);

    return `Figures for ${window}, dated in ${meta.timezone}, amounts in ${meta.currency}. ${generated}`;
});

/** The cards, in three named groups. A basis with no metrics is not rendered. */
const groupedMetrics = computed(() =>
    BASIS_ORDER
        .map((basis) => ({
            basis,
            heading: BASIS_COPY[basis].heading,
            explainer: BASIS_COPY[basis].explainer,
            metrics: metrics.value.filter((metric) => metric.basis === basis)
        }))
        .filter((group) => group.metrics.length > 0)
);

/** Labels for the omitted keys, in the tenant's vocabulary, as the server's are. */
const omittedLabels = computed<Record<string, string>>(
    () => impactMetricLabels(masjidStore.term('groups'), masjidStore.term('programs'))
);

/** The figures small enough to be about one person, named so the banner can list them. */
const smallCountLabels = computed<string[]>(
    () => metrics.value.filter(isSmallCount).map((metric) => metric.label)
);

/**
 * The date presets, computed in the ORGANISATION's timezone rather than the
 * browser's. "This year" for a New York masjid must start on the masjid's
 * 1 January, whatever clock the admin is sitting under; otherwise the report's
 * header and its figures disagree by a day at each end.
 */
const presets = computed(() => {
    const today = todayInOrgTimezone();
    const year = Number(today.slice(0, 4));

    return [
        { label: 'This year', from: `${year}-01-01`, to: today },
        { label: 'Last year', from: `${year - 1}-01-01`, to: `${year - 1}-12-31` },
        { label: 'Last 12 months', from: shiftMonths(today, -12), to: today }
    ];
});

// Watchers
watch([fromDate, toDate], () => {
    if (filterTimer) clearTimeout(filterTimer);
    filterTimer = setTimeout(() => load(), 300);
});

// Lifecycle hooks
onBeforeMount(async () => {
    // The dashboard chrome lives OUTSIDE this component, so a scoped rule
    // cannot reach it and the sidebar would print down the left of every page.
    // A body class, added here and removed on the way out, keeps the one global
    // print rule this view needs from applying to any other screen.
    document.body.classList.add('printing-impact-report');

    await load();
});

onBeforeUnmount(() => {
    document.body.classList.remove('printing-impact-report');
    if (filterTimer) clearTimeout(filterTimer);
    if (copiedTimer) clearTimeout(copiedTimer);
});

// Methods
async function load(): Promise<void> {
    // The server 422s an inverted range anyway; not asking keeps the previous
    // figures on screen instead of replacing them with an error the admin
    // caused by typing the second date first.
    if (dateRangeInvalid.value) return;

    loadError.value = false;
    refreshing.value = true;

    try {
        await reportStore.fetchReport(filters.value);
    } catch (error) {
        loadError.value = true;
    } finally {
        refreshing.value = false;
        bootstrapping.value = false;
    }
}

function applyPreset(preset: { from: string; to: string }): void {
    fromDate.value = preset.from;
    toDate.value = preset.to;
}

function clearDates(): void {
    fromDate.value = '';
    toDate.value = '';
}

function print(): void {
    window.print();
}

function isOpen(key: string): boolean {
    return openDefinitions.value.has(key);
}

function toggleDefinition(key: string): void {
    // Replaced rather than mutated: a Set mutated in place is not a reactive
    // change, and the chevron would stay put until something else re-rendered.
    const next = new Set(openDefinitions.value);
    next.has(key) ? next.delete(key) : next.add(key);
    openDefinitions.value = next;
}

/**
 * A count low enough that the figure is about identifiable people. Money is
 * excluded: an amount names nobody, and the donor COUNT beside it is the figure
 * that would.
 */
function isSmallCount(metric: ImpactMetric): boolean {
    return metric.unit === 'count' && metric.value > 0 && metric.value < SMALL_COUNT_FLOOR;
}

/** The window THIS figure covers, under the figure — never the window asked for. */
function windowSentence(metric: ImpactMetric): string {
    const { from, to, as_of } = metric.period;

    if (metric.basis === 'current') {
        return 'Counted as the platform holds it today; it does not vary with the dates above.';
    }

    if (metric.basis === 'as_of') {
        return as_of ? `Standing count on ${formatDay(as_of)}.` : 'Standing count.';
    }

    if (from && to) return `Counted between ${formatDay(from)} and ${formatDay(to)}.`;
    if (from) return `Counted from ${formatDay(from)} onwards.`;
    if (to) return `Counted up to ${formatDay(to)}.`;

    return 'Counted over all the records held, with no date limit.';
}

function labelFor(key: string): string {
    return impactMetricLabel(key, omittedLabels.value);
}

/** The two omission reasons, in words an admin can act on. */
function omissionSentence(reason: string): string {
    if (reason === 'requires_view_donations_permission') {
        return 'Money figures are not shown because your account cannot view donations. '
            + 'Ask an administrator for the Donations permission.';
    }

    if (reason === 'not_in_default_set_and_no_data') {
        return `Not shown: this organisation has no data for it, and it is not one of the figures `
            + `usually asked of a ${masjidStore.organizationLabel}.`;
    }

    // A newer server with a reason this build does not know. Saying that beats
    // a blank line, which reads as a rendering bug rather than an omission.
    return 'Not shown; this version of the dashboard cannot explain why.';
}

/**
 * Copy the FORMATTED figure — the only string safe to reuse. `value` is minor
 * units for money, and pasting "630000" into a web page is how a $6,300 claim
 * becomes a $630,000 one.
 *
 * A FAILURE has to be said out loud. `navigator.clipboard` is undefined on any
 * non-secure origin (staging over http, an IP address) and `writeText()`
 * rejects when the permission is denied; both land in the catch, and "not
 * copied" is visually identical to "not clicked" — so a silent catch means the
 * admin believes the figure is on the clipboard and pastes the PREVIOUS
 * clipboard contents into a funder document. The message stays up until the
 * next attempt rather than timing out like the "Copied" flash, because it is
 * read after the paste, not before.
 */
async function copyFigure(metric: ImpactMetric): Promise<void> {
    if (copiedTimer) clearTimeout(copiedTimer);

    try {
        await navigator.clipboard.writeText(metric.formatted);
        copiedKey.value = metric.key;
        copyFailedKey.value = null;
        copiedTimer = setTimeout(() => (copiedKey.value = null), 2000);
    } catch (e) {
        console.error('Copy impact figure error: ', e);
        copiedKey.value = null;
        copyFailedKey.value = metric.key;
    }
}

/**
 * Today as a yyyy-mm-dd wall-calendar day in the ORGANISATION's timezone.
 * en-CA renders ISO order, which is what the date inputs and the server both
 * take; an IANA zone Intl refuses throws RangeError, and a day computed in UTC
 * beats a preset that does nothing.
 */
function todayInOrgTimezone(): string {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone.value,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).format(new Date());
    } catch (e) {
        return new Date().toISOString().slice(0, 10);
    }
}

/**
 * Shift a yyyy-mm-dd day by whole months, clamping to the end of the target
 * month. Done on the string parts rather than with Date arithmetic so it stays
 * in the organisation's calendar: constructing a local Date from "2026-09-12"
 * and stepping back twelve months reintroduces the browser's zone at both ends.
 */
function shiftMonths(day: string, months: number): string {
    const [y, m, d] = day.split('-').map(Number);
    const total = (y * 12) + (m - 1) + months;
    const year = Math.floor(total / 12);
    const month = (total % 12) + 1;
    const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();

    return `${year}-${String(month).padStart(2, '0')}-${String(Math.min(d, lastDay)).padStart(2, '0')}`;
}

/**
 * A wall-calendar day the server sent as yyyy-mm-dd. Read back in UTC: any
 * other zone slides a 1 January boundary to 31 December west of it, and the
 * letterhead would then contradict the period it is describing.
 */
function formatDay(iso: string | null): string {
    if (!iso) return '—';

    const parsed = new Date(`${iso}T00:00:00Z`);

    return isNaN(parsed.getTime())
        ? iso
        : parsed.toLocaleDateString(undefined, { ...DATE_PARTS, timeZone: 'UTC' });
}

/**
 * A real instant — `generated_at`, which the server stamps with the
 * organisation's offset. Rendered in the organisation's own zone so the
 * "generated" line agrees with the zone named beside it.
 */
function formatInstant(iso: string | null): string {
    if (!iso) return '—';

    const parsed = new Date(iso);
    if (isNaN(parsed.getTime())) return iso;

    const options: Intl.DateTimeFormatOptions = {
        ...DATE_PARTS,
        hour: 'numeric',
        minute: '2-digit'
    };

    try {
        return parsed.toLocaleString(undefined, { ...options, timeZone: timezone.value });
    } catch (e) {
        // An unrecognised IANA zone throws RangeError; an instant one zone off
        // beats a blank generated-on line on a document someone files.
        return parsed.toLocaleString(undefined, { ...options, timeZone: 'UTC' });
    }
}
</script>

<style scoped>
/*
    Quieter than the gradient .stats-card used elsewhere, for the same reason
    DonationsDashboardView is: this page is the numbers, and twenty decorated
    tiles bury them. It also has to survive being printed in black and white.
*/
.letterhead {
    border-bottom: 2px solid #212529;
    padding-bottom: 0.75rem;
    margin-bottom: 1.25rem;
}

.letterhead-org {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.125rem;
}

.letterhead-title {
    font-size: 0.8125rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6c757d;
    margin-bottom: 0.5rem;
}

.letterhead-scope {
    font-size: 0.875rem;
    color: #343a40;
}

.filters {
    background: #f8f9fa;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1.25rem;
}

.metric-group {
    margin-bottom: 1.75rem;
}

.group-heading {
    font-size: 0.8125rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6c757d;
    margin-bottom: 0.125rem;
}

/* The sentence that keeps a stock figure from being read as a flow. */
.group-explainer {
    font-size: 0.8125rem;
    color: #6c757d;
    margin-bottom: 0.75rem;
}

.metric-card {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.25rem;
    background: #fff;
    display: flex;
    flex-direction: column;
}

.metric-label {
    font-size: 0.8125rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6c757d;
    margin-bottom: 0.5rem;
}

.metric-value {
    font-size: 1.875rem;
    font-weight: 700;
    line-height: 1.1;
}

.metric-window {
    font-size: 0.75rem;
    color: #6c757d;
    margin: 0.375rem 0 0.5rem;
}

.metric-caution {
    font-size: 0.75rem;
    color: #a15c07;
    margin-bottom: 0.5rem;
}

.definition-toggle {
    font-size: 0.8125rem;
    text-decoration: none;
    align-self: flex-start;
}

.definition {
    display: none;
    margin-top: 0.5rem;
}

.definition.is-open {
    display: block;
}

.definition-text {
    font-size: 0.8125rem;
    color: #343a40;
}

.definition-source {
    font-size: 0.75rem;
    color: #6c757d;
}

.copy-btn {
    margin-top: auto;
    align-self: flex-start;
    padding-top: 0.75rem;
}

/* Same amber as .metric-caution: both say "do not trust what you are looking
   at without reading this". */
.copy-error {
    font-size: 0.75rem;
    color: #a15c07;
    margin-top: 0.5rem;
}

.omitted {
    border-top: 1px solid #e5e7eb;
    padding-top: 1rem;
    margin-top: 0.5rem;
}

.omitted-list {
    font-size: 0.8125rem;
    color: #343a40;
    margin-bottom: 0;
    padding-left: 1.1rem;
}

.omitted-list li {
    margin-bottom: 0.375rem;
}

.report-footnote {
    border-top: 1px solid #e5e7eb;
    margin-top: 1.25rem;
    padding-top: 0.75rem;
    font-size: 0.75rem;
    color: #6c757d;
}

/* Figures that stack in a column have to line up, so digits must be equal width. */
.tabular {
    font-variant-numeric: tabular-nums;
    font-feature-settings: 'tnum';
}

/* Refreshing after a date change keeps the previous figures on screen rather
   than blanking them — replacing numbers with a spinner makes the page flash
   on every keystroke in a date field. */
.is-refreshing {
    opacity: 0.55;
    transition: opacity 0.15s ease;
}

@media print {
    /* The controls are how the window was chosen; the letterhead already says
       which window it was, in words. */
    .no-print {
        display: none !important;
    }

    /* Every definition travels with its figure. This is the rule's "a metric
       definition is the deliverable, not the number": a funder holding a page
       of counts with no definitions is worse off than one holding nothing. */
    .definition {
        display: block !important;
    }

    /* A figure split across a page break is a figure someone misreads. */
    .metric-card,
    .metric-group,
    .omitted {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .metric-card {
        border: 1px solid #999;
    }

    /* Kept whole at the top of the document. It is NOT repeated per sheet:
       running headers need `position: running()`, which no browser print
       engine implements, and the table-thead trick that does work would put
       the report's identity inside a layout table. The letterhead block
       therefore stays one unit, and the footnote below repeats the boundary. */
    .letterhead {
        break-after: avoid;
        page-break-after: avoid;
        break-inside: avoid;
        page-break-inside: avoid;
    }

    /* A print fired mid-refresh must not hand a funder a page of greyed-out
       figures that look like a rendering fault. */
    .is-refreshing {
        opacity: 1;
    }
}
</style>

<style>
/*
    NOT scoped, on purpose: the dashboard sidebar, header and footer are
    rendered by DashboardLayout, outside this component, so a scoped selector
    cannot reach them — and there is no dashboard-wide print stylesheet, which
    means they WOULD print down the side of a funder's report.

    Guarded by a body class this view adds on mount and removes on unmount, so
    the one global rule it needs cannot follow the admin to another screen after
    the module has been loaded. EnvironmentRibbon.vue is the repo's only other
    @media print block and hides its own chrome the same way.
*/
@media print {
    body.printing-impact-report #dashboard_aside,
    body.printing-impact-report #dashboard_header,
    body.printing-impact-report #dashboard_footer {
        display: none !important;
    }

    body.printing-impact-report #header_main_container {
        margin: 0 !important;
        padding: 0 !important;
    }

    body.printing-impact-report #dashboard_main {
        margin: 0 !important;
        padding: 0 !important;
    }

    /* The card chrome PageDataContainer draws is screen furniture; on paper it
       just costs a margin the printer already provides. */
    body.printing-impact-report .impact-report .card {
        border: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
    }
}
</style>
