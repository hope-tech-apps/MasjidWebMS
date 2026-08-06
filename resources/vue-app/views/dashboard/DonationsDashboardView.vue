<template>
    <div>
        <PageDataContainer
            title="Giving Dashboard"
            :paginationOptions="paginationOptions"
            :hideButton="true"
            @pageChange="pageChange"
        >
            <template #headerButtons>
                <button
                    class="btn btn-outline-secondary"
                    :disabled="exporting || dateRangeInvalid"
                    @click="exportCsv"
                    title="Download every gift matching the current filters"
                >
                    <span v-if="exporting" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="bi bi-download me-1"></i>
                    Export CSV
                </button>
            </template>

            <div class="container w-100">
                <!-- First paint: nothing to show yet, not even a shape -->
                <div v-if="bootstrapping" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <div v-else-if="loadError" class="text-center py-5 text-muted">
                    <i class="bi bi-exclamation-triangle fs-1 d-block mb-3 text-warning"></i>
                    <p class="mb-2">The giving figures could not be loaded.</p>
                    <button class="btn btn-outline-primary btn-sm" @click="loadAll()">Try again</button>
                </div>

                <!--
                    A brand-new masjid has no gifts at all. That is a normal state, not a
                    broken page, so it gets a real explanation and the two ways to change
                    it rather than a grid of zeroes.
                -->
                <div v-else-if="neverReceivedAGift" class="text-center py-5">
                    <i class="bi bi-piggy-bank fs-1 d-block mb-3 text-muted"></i>
                    <h5 class="mb-2">No donations recorded yet</h5>
                    <p class="text-muted mb-4">
                        Once gifts start arriving — online or entered by hand — this page will show
                        what came in, which funds it went to, and who gave.
                    </p>
                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <router-link class="btn btn-success" :to="{ name: 'masjid.donations' }">
                            Record an offline gift
                        </router-link>
                        <router-link class="btn btn-outline-secondary" :to="{ name: 'masjid.funds' }">
                            Manage funds
                        </router-link>
                    </div>
                </div>

                <template v-else>
                    <!-- 1 — Header totals ------------------------------------------------ -->
                    <div class="d-flex align-items-baseline justify-content-between flex-wrap gap-2 mb-2">
                        <h6 class="text-muted text-uppercase small mb-0">Totals</h6>
                        <span v-if="statsMeta" class="text-muted small">
                            {{ statsMeta.currency }} · dated in {{ statsMeta.timezone }}
                        </span>
                    </div>

                    <!--
                        Filters intersect the buckets server-side, so with a date window applied
                        "all time" means "all of the window". Saying so beats letting a treasurer
                        wonder why all-time shrank. The fund is the exception — it narrows only
                        the feed and the export — so a fund picked on its own has to be disclosed
                        too, or these all-fund totals get read as that one fund's.
                    -->
                    <p v-if="headerScopeNote" class="small text-muted mb-3">
                        <i class="bi bi-funnel me-1"></i>
                        {{ headerScopeNote }}
                    </p>

                    <div class="row g-3 mb-4" :class="{ 'is-refreshing': statsLoading }">
                        <div v-for="bucket in buckets" :key="bucket.key" class="col-md-4">
                            <div class="metric-card h-100">
                                <div class="metric-label">{{ bucket.label }}</div>
                                <div class="metric-value tabular">{{ money(bucket.data.gross_cents) }}</div>
                                <div class="metric-net tabular">
                                    {{ money(bucket.data.net_cents) }} net
                                    <i
                                        class="bi bi-info-circle ms-1"
                                        title="Net of processing fees. Gifts that have not settled yet — and every offline gift — count at their full amount."
                                    ></i>
                                </div>
                                <dl class="metric-facts mb-0">
                                    <div>
                                        <dt>Donors</dt>
                                        <dd class="tabular">{{ bucket.data.donor_count }}</dd>
                                    </div>
                                    <div>
                                        <dt>Gifts</dt>
                                        <dd class="tabular">{{ bucket.data.gift_count }}</dd>
                                    </div>
                                    <div>
                                        <dt>Average gift</dt>
                                        <dd class="tabular">{{ money(bucket.data.average_gift_cents) }}</dd>
                                    </div>
                                </dl>
                                <!-- Explains the gap between donors and gifts before it reads as a bug. -->
                                <p v-if="bucket.data.anonymous_gift_count" class="metric-foot mb-0">
                                    {{ bucket.data.anonymous_gift_count }}
                                    {{ bucket.data.anonymous_gift_count === 1 ? 'gift has' : 'gifts have' }}
                                    no donor on record
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- 2 — Fund breakdown ----------------------------------------------- -->
                    <h6 class="text-muted text-uppercase small mb-2">By fund</h6>
                    <div v-if="!byFund.length" class="text-muted small border rounded p-3 mb-4">
                        No funds have been set up yet.
                        <router-link :to="{ name: 'masjid.funds' }">Add one</router-link>
                        so gifts can be designated.
                    </div>
                    <div v-else class="table-responsive mb-4" :class="{ 'is-refreshing': statsLoading }">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Fund</th>
                                    <th class="text-end">Gross</th>
                                    <th class="text-end">Net</th>
                                    <th class="text-end">Gifts</th>
                                    <th class="text-end">Donors</th>
                                    <th>Last gift</th>
                                    <th class="text-end">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in byFund" :key="row.fund_id">
                                    <td>
                                        <strong>{{ row.fund_name }}</strong>
                                        <span v-if="!row.is_active" class="badge bg-light text-muted ms-2">Inactive</span>
                                    </td>
                                    <td class="text-end tabular fw-semibold">{{ money(row.gross_cents) }}</td>
                                    <td class="text-end tabular">{{ money(row.net_cents) }}</td>
                                    <td class="text-end tabular">{{ row.gift_count }}</td>
                                    <td class="text-end tabular">{{ row.donor_count }}</td>
                                    <td>{{ formatDay(row.last_gift_at) }}</td>
                                    <td class="text-end">
                                        <router-link
                                            class="btn btn-sm btn-outline-primary"
                                            :to="{ name: 'masjid.fundDetail', params: { fundId: row.fund_id }, query: filterQuery }"
                                            :title="`View ${row.fund_name} in detail`"
                                        >
                                            View details
                                        </router-link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- 3 — Filters ------------------------------------------------------ -->
                    <h6 class="text-muted text-uppercase small mb-2">Filters</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="giving-from">Gifts from</label>
                            <input id="giving-from" type="date" class="form-control" v-model="fromDate" :max="toDate || undefined">
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="giving-to">Gifts to</label>
                            <input id="giving-to" type="date" class="form-control" v-model="toDate" :min="fromDate || undefined">
                        </div>
                        <div class="col-md-4 col-lg-2">
                            <label class="form-label small text-muted mb-1">Fund</label>
                            <select class="form-select" v-model="fundFilter">
                                <option value="">All funds</option>
                                <option v-for="fund in funds" :key="fund.id" :value="fund.id">{{ fund.name }}</option>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-2">
                            <label class="form-label small text-muted mb-1">Source</label>
                            <select class="form-select" v-model="sourceFilter">
                                <option value="">All sources</option>
                                <option value="stripe">Online</option>
                                <option value="offline">Offline</option>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-2">
                            <label class="form-label small text-muted mb-1">Status</label>
                            <select class="form-select" v-model="statusFilter">
                                <option value="">Received only</option>
                                <option v-for="s in statuses" :key="s" :value="s" class="text-capitalize">{{ s }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
                        <button class="btn btn-outline-secondary btn-sm" :disabled="!filtersApplied" @click="clearFilters">
                            Clear filters
                        </button>
                        <!--
                            The header defaults to succeeded because that is money actually
                            received; the feed defaults to everything so a stuck pending gift
                            is still visible. Two different defaults on one page needs saying —
                            and an explicit status collapses them onto one set, so the sentence
                            has to follow the filter rather than keep quoting the default.
                        -->
                        <span class="text-muted small">{{ statusScopeNote }}</span>
                    </div>

                    <!--
                        Named where the window is chosen, because that is where it gets misread.
                        The same from/to goes to the header, the feed and the export, and the
                        server reads all three as calendar days at the masjid.
                    -->
                    <p class="small text-muted mb-4">
                        <i class="bi bi-clock-history me-1"></i>
                        {{ windowScopeNote }}
                    </p>

                    <p v-if="dateRangeInvalid" class="text-danger small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        The “to” date cannot be earlier than the “from” date.
                    </p>

                    <!-- 4 — Recent gifts -------------------------------------------------- -->
                    <h6 class="text-muted text-uppercase small mb-2">Recent gifts</h6>

                    <div v-if="feedLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>

                    <div v-else-if="!donations.length" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                        <p class="mb-0">No gifts match these filters</p>
                        <button v-if="filtersApplied" class="btn btn-link btn-sm" @click="clearFilters">Clear the filters</button>
                    </div>

                    <div v-else class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Donor</th>
                                    <th>Fund</th>
                                    <th>Method</th>
                                    <th class="text-end">Amount</th>
                                    <th class="text-end">Net</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="donation in donations" :key="donation.id">
                                    <td>{{ giftDate(donation) }}</td>
                                    <td>
                                        <span v-if="donation.contact" class="fw-semibold">{{ donorName(donation) }}</span>
                                        <span v-else class="text-muted">— (general)</span>
                                    </td>
                                    <td>{{ donation.fund?.name ?? '—' }}</td>
                                    <td class="text-capitalize">{{ methodLabel(donation) }}</td>
                                    <td class="text-end tabular fw-semibold">
                                        {{ formatCents(donation.charged_amount, donation.currency) }}
                                    </td>
                                    <td class="text-end tabular text-muted">
                                        {{ formatCents(donation.net_amount ?? donation.charged_amount, donation.currency) }}
                                    </td>
                                    <td>
                                        <span class="badge text-capitalize" :class="statusClass(donation.status)">
                                            {{ donation.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeMount, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { Donation, DonationStatus } from '@/core/types/data/masjid-related/Donation';
import { Fund } from '@/core/types/data/masjid-related/Fund';
import {
    DonationBucketKey,
    DonationLedgerFilters,
    DonationSource,
    DonationStatsFilters
} from '@/core/types/data/masjid-related/DonationStats';
import { useDonationsStore } from '@/stores/masjid/donationsStore';
import { useDonationStatsStore } from '@/stores/masjid/donationStatsStore';
import { useFundsStore } from '@/stores/masjid/fundsStore';
import Swal from 'sweetalert2';

// Stores
const donationsStore = useDonationsStore();
const statsStore = useDonationStatsStore();
const fundsStore = useFundsStore();

// State
const bootstrapping = ref(true);
const statsLoading = ref(false);
const feedLoading = ref(false);
const exporting = ref(false);
const loadError = ref(false);
const funds = ref<Fund[]>([]);

const fromDate = ref('');
const toDate = ref('');
const fundFilter = ref<number | ''>('');
const sourceFilter = ref<DonationSource | ''>('');
const statusFilter = ref<DonationStatus | ''>('');

const statuses: DonationStatus[] = ['pending', 'succeeded', 'failed', 'refunded'];

const BUCKET_LABELS: Record<DonationBucketKey, string> = {
    this_month: 'This month',
    year_to_date: 'Year to date',
    all_time: 'All time'
};

// Computed
const summary = computed(() => statsStore.summary);
const byFund = computed(() => statsStore.byFund);
const statsMeta = computed(() => statsStore.statsMeta);
const donations = computed<Donation[]>(() => (donationsStore.donationsPaginated?.data as Donation[]) || []);

/** The three header cards in reading order, skipped entirely until the numbers land. */
const buckets = computed(() => {
    const data = summary.value;
    if (!data) return [];

    return (Object.keys(BUCKET_LABELS) as DonationBucketKey[]).map(key => ({
        key,
        label: BUCKET_LABELS[key],
        data: data[key]
    }));
});

const dateRangeInvalid = computed(() => !!fromDate.value && !!toDate.value && toDate.value < fromDate.value);

const filtersApplied = computed(() =>
    !!fromDate.value || !!toDate.value || fundFilter.value !== '' || !!sourceFilter.value || !!statusFilter.value
);

/** Everything the header actually responds to — the fund is not one of them. */
const statsFiltersApplied = computed(() =>
    !!fromDate.value || !!toDate.value || !!sourceFilter.value || !!statusFilter.value
);

/**
 * What the three header cards are scoped to, said out loud whenever it is not
 * simply "everything".
 *
 * The fund is deliberately missing from statsFiltersApplied — the header covers
 * every fund — which makes a fund chosen ON ITS OWN the one combination where
 * the cards and the feed under them describe different gifts. That is also the
 * likeliest single filter, so it gets its own sentence rather than falling
 * through to no disclosure at all.
 */
const headerScopeNote = computed<string>(() => {
    if (!statsFiltersApplied.value) {
        return fundFilter.value !== ''
            ? 'These totals cover every fund — the fund filter narrows only the feed and the export.'
            : '';
    }

    return fundFilter.value !== ''
        ? 'Narrowed by the filters below — except the fund, which narrows only the feed and the export.'
        : 'Narrowed by the filters below.';
});

/** Which gifts every figure on this page is counting, header and feed alike. */
const statusScopeNote = computed<string>(() =>
    statusFilter.value
        ? `Both the totals and the feed are limited to ${statusFilter.value} gifts.`
        : 'Totals count received gifts only. The feed lists every gift, whatever its status.'
);

/**
 * The date window names its timezone, because "Aug 1" is not one moment. The
 * server reads from/to as whole calendar days at the masjid (DonationMetrics),
 * so the masjid's zone — the one the totals are already labelled with — is the
 * honest thing to print. Before the first stats response there is no zone to
 * name, so the sentence stays true without one.
 */
const windowScopeNote = computed<string>(() => {
    const zone = statsMeta.value?.timezone;
    return `Dates count as whole calendar days in ${zone || "the masjid's timezone"} — one window covering the totals, the feed and the export.`;
});

/**
 * A brand-new masjid, distinguished from "your filters matched nothing". Read off
 * the unfiltered ledger rather than the summary, because the summary counts only
 * received gifts and a masjid whose first gift is still pending has not, in fact,
 * received nothing by accident.
 */
const neverReceivedAGift = computed(() =>
    !filtersApplied.value && donationsStore.donationsPaginated?.total === 0
);

/**
 * ONE window, built once and spread into both filter sets below.
 *
 * The header, the feed and the CSV all have to describe the same days or the
 * page contradicts itself, and two separately-typed pairs of from/to is exactly
 * how that drifts. windowScopeNote names the timezone the server reads them in.
 */
const dateWindow = computed<Pick<DonationLedgerFilters, 'from' | 'to'>>(() => ({
    from: fromDate.value,
    to: toDate.value
}));

const ledgerFilters = computed<DonationLedgerFilters>(() => ({
    ...dateWindow.value,
    fund_id: fundFilter.value,
    source: sourceFilter.value,
    status: statusFilter.value,
    // No donor search on this screen — the ledger page owns that job.
    search: ''
}));

const statsFilters = computed<DonationStatsFilters>(() => ({
    ...dateWindow.value,
    source: sourceFilter.value,
    status: statusFilter.value
}));

/** Carried into the fund detail page so it opens on the same window, not a fresh one. */
const filterQuery = computed<Record<string, string>>(() => {
    const query: Record<string, string> = {};
    if (fromDate.value) query.from = fromDate.value;
    if (toDate.value) query.to = toDate.value;
    if (sourceFilter.value) query.source = sourceFilter.value;
    if (statusFilter.value) query.status = statusFilter.value;
    return query;
});

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!donationsStore.donationsPaginated) return undefined;
    return {
        currentPage: donationsStore.donationsPaginated.current_page,
        itemsTotal: donationsStore.donationsPaginated.total,
        perPage: donationsStore.donationsPaginated.per_page
    };
});

// Lifecycle
onBeforeMount(async () => {
    // Best-effort: without the fund list the filter dropdown is empty, but the
    // figures and the feed still load.
    try {
        await fundsStore.fetchFunds();
        funds.value = fundsStore.funds;
    } catch (e) {
        funds.value = [];
    }

    await loadAll();
    bootstrapping.value = false;
});

// Re-fetch on any filter change. Debounced because the two date inputs are
// usually changed together, and a half-typed range would 422 on its own.
let filterTimer: ReturnType<typeof setTimeout> | null = null;
watch([fromDate, toDate, fundFilter, sourceFilter, statusFilter], () => {
    if (filterTimer) clearTimeout(filterTimer);
    filterTimer = setTimeout(() => loadAll(1), 300);
});

// Methods
const loadAll = async (page: number = 1) => {
    if (dateRangeInvalid.value) return;

    loadError.value = false;
    statsLoading.value = true;
    feedLoading.value = true;

    try {
        // In parallel: the header and the feed answer different questions and
        // neither needs the other's result.
        await Promise.all([
            statsStore.fetchStats(statsFilters.value),
            donationsStore.fetchLedger(ledgerFilters.value, page)
        ]);
    } catch (error) {
        loadError.value = true;
    } finally {
        statsLoading.value = false;
        feedLoading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    // Paging moves the feed only — the totals above describe the whole filtered
    // set and would be wrong if they tracked the page.
    if (dateRangeInvalid.value) return;

    feedLoading.value = true;
    try {
        await donationsStore.fetchLedger(ledgerFilters.value, data.toPage);
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load donations.' });
    } finally {
        feedLoading.value = false;
    }
};

const clearFilters = () => {
    fromDate.value = '';
    toDate.value = '';
    fundFilter.value = '';
    sourceFilter.value = '';
    statusFilter.value = '';
};

const exportCsv = async () => {
    if (dateRangeInvalid.value) return;

    exporting.value = true;
    try {
        await donationsStore.exportCsv(ledgerFilters.value);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not export the donations.' });
    } finally {
        exporting.value = false;
    }
};

// --- Formatting --------------------------------------------------------------

// Format integer minor units (cents) as a currency string. NEVER divide these in
// the display templates directly — always route through here.
const formatCents = (cents: number, currency: string = 'usd'): string => {
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: (currency || 'usd').toUpperCase()
        }).format((cents ?? 0) / 100);
    } catch (e) {
        // Fallback for an unexpected currency code.
        return `$${((cents ?? 0) / 100).toFixed(2)}`;
    }
};

/** Header and breakdown figures: one masjid books in one currency, named in meta. */
const money = (cents: number): string => formatCents(cents, statsMeta.value?.currency ?? 'usd');

const DATE_PARTS: Intl.DateTimeFormatOptions = { year: 'numeric', month: 'short', day: 'numeric' };

/**
 * A wall-calendar day — donated_at, last_gift_at. These carry no time of day at
 * all; the server sends them as UTC midnight, so they are read back in UTC. Any
 * other zone would slide a July 2 gift to July 1 west of it.
 */
const formatDay = (iso: string | null): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { ...DATE_PARTS, timeZone: 'UTC' });
};

/**
 * A real instant — created_at. A Stripe gift stamped 02:00 UTC happened on the
 * previous evening in New York, so rendering it in UTC dates it a day late under
 * a header that says America/New_York. Use the masjid's own zone, the same one
 * the totals and the date window are labelled with.
 */
const formatInstant = (iso: string | null): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;

    try {
        return d.toLocaleDateString(undefined, { ...DATE_PARTS, timeZone: statsMeta.value?.timezone || 'UTC' });
    } catch (e) {
        // An unrecognised IANA zone throws RangeError; a date one zone off beats a
        // blank column.
        return d.toLocaleDateString(undefined, { ...DATE_PARTS, timeZone: 'UTC' });
    }
};

/** The day a gift is booked on: its own donation date when it has one, else when it landed. */
const giftDate = (donation: any): string =>
    donation.donated_at ? formatDay(donation.donated_at) : formatInstant(donation.created_at);

const donorName = (donation: any): string => {
    const c = donation.contact;
    if (!c) return '';
    return [c.first_name, c.last_name].filter(Boolean).join(' ') || 'Donor';
};

// Online = Stripe (card via checkout); offline = the recorded payment method.
const methodLabel = (donation: any): string => {
    if (donation.source === 'offline') {
        return (donation.payment_method && donation.payment_method !== 'unknown')
            ? donation.payment_method.replace(/_/g, '/')
            : 'offline';
    }
    return 'card';
};

const statusClass = (status: DonationStatus): string => {
    switch (status) {
        case 'succeeded': return 'bg-success-subtle text-success';
        case 'pending': return 'bg-warning-subtle text-warning';
        case 'failed': return 'bg-danger-subtle text-danger';
        case 'refunded': return 'bg-secondary-subtle text-secondary';
        default: return 'bg-light text-muted';
    }
};
</script>

<style scoped>
/*
    Deliberately quieter than the gradient .stats-card used elsewhere: three of
    those side by side, each carrying five figures, buries the numbers under the
    decoration. The money is the content here.
*/
.metric-card {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.25rem;
    background: #fff;
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

.metric-net {
    font-size: 0.875rem;
    color: #6c757d;
    margin-top: 0.125rem;
}

.metric-facts {
    display: flex;
    gap: 1.5rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.metric-facts dt {
    font-size: 0.75rem;
    font-weight: 500;
    color: #6c757d;
}

.metric-facts dd {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 0;
}

.metric-foot {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.75rem;
}

/* Figures that stack in a column have to line up, so digits must be equal width. */
.tabular {
    font-variant-numeric: tabular-nums;
    font-feature-settings: 'tnum';
}

/*
    Refreshing after a filter change keeps the previous figures on screen rather
    than blanking them — replacing numbers with a spinner makes the page flash on
    every keystroke in a date field.
*/
.is-refreshing {
    opacity: 0.55;
    transition: opacity 0.15s ease;
}
</style>
