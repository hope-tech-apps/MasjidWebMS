<template>
    <div>
        <PageDataContainer
            :title="pageTitle"
            :paginationOptions="paginationOptions"
            :hideButton="true"
            @pageChange="pageChange"
        >
            <template #headerButtons>
                <router-link class="btn btn-outline-secondary" :to="{ name: 'masjid.donationsDashboard', query: filterQuery }">
                    <i class="bi bi-arrow-left me-1"></i>
                    Dashboard
                </router-link>
                <button
                    class="btn btn-outline-secondary"
                    :disabled="exporting || dateRangeInvalid || notFound"
                    @click="exportCsv"
                    title="Download this fund's gifts matching the current filters"
                >
                    <span v-if="exporting" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="bi bi-download me-1"></i>
                    Export CSV
                </button>
            </template>

            <div class="container w-100">
                <div v-if="bootstrapping" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <div v-else-if="notFound" class="text-center py-5 text-muted">
                    <i class="bi bi-question-circle fs-1 d-block mb-3"></i>
                    <p class="mb-3">That fund no longer exists.</p>
                    <router-link class="btn btn-outline-primary btn-sm" :to="{ name: 'masjid.funds' }">
                        Back to funds
                    </router-link>
                </div>

                <div v-else-if="loadError" class="text-center py-5 text-muted">
                    <i class="bi bi-exclamation-triangle fs-1 d-block mb-3 text-warning"></i>
                    <p class="mb-2">This fund's figures could not be loaded.</p>
                    <button class="btn btn-outline-primary btn-sm" @click="loadAll()">Try again</button>
                </div>

                <template v-else>
                    <!-- What kind of fund this is: it changes whether gifts are receiptable. -->
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-4">
                        <span v-if="fund" class="badge bg-secondary text-capitalize">{{ fund.type }}</span>
                        <span v-if="fund?.receiptable" class="badge bg-success-subtle text-success">Tax receiptable</span>
                        <span v-else-if="fund" class="badge bg-light text-muted">Not receiptable</span>
                        <span v-if="fund && !fund.is_active" class="badge bg-danger-subtle text-danger">Inactive</span>
                        <span v-if="statsMeta" class="text-muted small ms-auto">
                            {{ statsMeta.currency }} · dated in {{ statsMeta.timezone }}
                        </span>
                    </div>

                    <!-- 1 — This fund over the selected window --------------------------- -->
                    <div class="d-flex align-items-baseline justify-content-between flex-wrap gap-2 mb-2">
                        <h6 class="text-muted text-uppercase small mb-0">{{ windowLabel }}</h6>
                        <!--
                            Follows the status filter. Fixed text here would sit above refunded
                            money calling it received.
                        -->
                        <span class="text-muted small">{{ statusScopeNote }}</span>
                    </div>

                    <div class="row g-3 mb-4" :class="{ 'is-refreshing': statsLoading }">
                        <div class="col-md-6 col-lg-3">
                            <div class="metric-card h-100">
                                <div class="metric-label">Gross</div>
                                <div class="metric-value tabular">{{ money(row?.gross_cents ?? 0) }}</div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="metric-card h-100">
                                <div class="metric-label">Net</div>
                                <div class="metric-value tabular">{{ money(row?.net_cents ?? 0) }}</div>
                                <div class="metric-foot">
                                    After processing fees. Unsettled and offline gifts count in full.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="metric-card h-100">
                                <div class="metric-label">Gifts</div>
                                <div class="metric-value tabular">{{ row?.gift_count ?? 0 }}</div>
                                <div class="metric-foot">
                                    Average {{ money(averageGiftCents) }}
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="metric-card h-100">
                                <div class="metric-label">Donors</div>
                                <div class="metric-value tabular">{{ row?.donor_count ?? 0 }}</div>
                                <div class="metric-foot">
                                    Last gift {{ formatDay(row?.last_gift_at ?? null) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2 — Filters (the fund itself is pinned by the route) -------------- -->
                    <h6 class="text-muted text-uppercase small mb-2">Filters</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="fund-from">Gifts from</label>
                            <input id="fund-from" type="date" class="form-control" v-model="fromDate" :max="toDate || undefined">
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="fund-to">Gifts to</label>
                            <input id="fund-to" type="date" class="form-control" v-model="toDate" :min="fromDate || undefined">
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small text-muted mb-1">Source</label>
                            <select class="form-select" v-model="sourceFilter">
                                <option value="">All sources</option>
                                <option value="stripe">Online</option>
                                <option value="offline">Offline</option>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small text-muted mb-1">Status</label>
                            <select class="form-select" v-model="statusFilter">
                                <option value="">Received only</option>
                                <option v-for="s in statuses" :key="s" :value="s" class="text-capitalize">{{ s }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <button class="btn btn-outline-secondary btn-sm" :disabled="!filtersApplied" @click="clearFilters">
                            Clear filters
                        </button>
                    </div>

                    <p v-if="dateRangeInvalid" class="text-danger small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        The “to” date cannot be earlier than the “from” date.
                    </p>

                    <!-- 3 — This fund's gifts -------------------------------------------- -->
                    <h6 class="text-muted text-uppercase small mb-2">Gifts to this fund</h6>

                    <div v-if="feedLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>

                    <div v-else-if="!donations.length" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                        <p class="mb-0">
                            {{ filtersApplied ? 'No gifts match these filters' : 'This fund has not received a gift yet' }}
                        </p>
                        <button v-if="filtersApplied" class="btn btn-link btn-sm" @click="clearFilters">Clear the filters</button>
                    </div>

                    <div v-else class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Donor</th>
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
import { useRoute } from 'vue-router';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { Donation, DonationStatus } from '@/core/types/data/masjid-related/Donation';
import { Fund } from '@/core/types/data/masjid-related/Fund';
import {
    DonationLedgerFilters,
    DonationSource,
    DonationStatsFilters
} from '@/core/types/data/masjid-related/DonationStats';
import { useDonationsStore } from '@/stores/masjid/donationsStore';
import { useDonationStatsStore } from '@/stores/masjid/donationStatsStore';
import { useFundsStore } from '@/stores/masjid/fundsStore';
import Swal from 'sweetalert2';

// Stores
const route = useRoute();
const donationsStore = useDonationsStore();
const statsStore = useDonationStatsStore();
const fundsStore = useFundsStore();

// State
const bootstrapping = ref(true);
const statsLoading = ref(false);
const feedLoading = ref(false);
const exporting = ref(false);
const loadError = ref(false);
const notFound = ref(false);
const fund = ref<Fund | null>(null);

const fundId = Number(route.params.fundId);

// The window the dashboard was showing when "view details" was clicked, so the
// page opens on the same question rather than resetting to all time.
const fromDate = ref(queryString('from'));
const toDate = ref(queryString('to'));
const sourceFilter = ref<DonationSource | ''>(queryString('source') as DonationSource | '');
const statusFilter = ref<DonationStatus | ''>(queryString('status') as DonationStatus | '');

const statuses: DonationStatus[] = ['pending', 'succeeded', 'failed', 'refunded'];

// Computed
const statsMeta = computed(() => statsStore.statsMeta);
const donations = computed<Donation[]>(() => (donationsStore.donationsPaginated?.data as Donation[]) || []);

/** This fund's line out of the breakdown. Absent means the fund is gone. */
const row = computed(() => statsStore.byFund.find(r => r.fund_id === fundId));

const pageTitle = computed(() => fund.value?.name ?? row.value?.fund_name ?? 'Fund');

/**
 * Averaged here rather than server-side: the breakdown reports totals per fund,
 * and dividing two integers it already returned is not re-deriving an amount.
 * Integer cents, floored, to match how the header buckets average.
 */
const averageGiftCents = computed(() => {
    const gifts = row.value?.gift_count ?? 0;
    return gifts > 0 ? Math.floor((row.value?.gross_cents ?? 0) / gifts) : 0;
});

const dateRangeInvalid = computed(() => !!fromDate.value && !!toDate.value && toDate.value < fromDate.value);

const filtersApplied = computed(() =>
    !!fromDate.value || !!toDate.value || !!sourceFilter.value || !!statusFilter.value
);

const windowLabel = computed(() => {
    if (fromDate.value && toDate.value) return `${formatDay(fromDate.value)} – ${formatDay(toDate.value)}`;
    if (fromDate.value) return `Since ${formatDay(fromDate.value)}`;
    if (toDate.value) return `Up to ${formatDay(toDate.value)}`;
    return 'All time';
});

/**
 * Which gifts the cards are counting. A blank filter means the server's own
 * default — money actually received — and anything else has to be named, or a
 * refunded total sits under a label claiming it came in.
 */
const STATUS_SCOPE_LABELS: Record<DonationStatus, string> = {
    succeeded: 'Received gifts only',
    pending: 'Pending gifts only',
    failed: 'Failed gifts only',
    refunded: 'Refunded gifts only'
};

const statusScopeNote = computed<string>(() => {
    const status = statusFilter.value || 'succeeded';
    // A status this map has not caught up with names itself rather than falling
    // back to the received wording — the whole point is not to call money received
    // when it was not.
    return STATUS_SCOPE_LABELS[status] ?? `${status} gifts only`;
});

/** ONE window for the cards, the gift list and the CSV — see the dashboard's note. */
const dateWindow = computed<Pick<DonationLedgerFilters, 'from' | 'to'>>(() => ({
    from: fromDate.value,
    to: toDate.value
}));

const ledgerFilters = computed<DonationLedgerFilters>(() => ({
    ...dateWindow.value,
    fund_id: fundId,
    source: sourceFilter.value,
    status: statusFilter.value,
    search: ''
}));

// The breakdown covers every fund; this page reads one row out of it, so the
// fund is not part of the stats query.
const statsFilters = computed<DonationStatsFilters>(() => ({
    ...dateWindow.value,
    source: sourceFilter.value,
    status: statusFilter.value
}));

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
    // A hand-edited URL would otherwise send fund_id=NaN and come back a 422,
    // which reads as "something broke" rather than "no such fund".
    if (!Number.isInteger(fundId)) {
        notFound.value = true;
        bootstrapping.value = false;
        return;
    }

    // Best-effort: the fund's own record only supplies the name and the badges.
    // A failure here still leaves the figures and the gift list, titled from the
    // breakdown row.
    try {
        fund.value = await fundsStore.fetchFund(fundId);
    } catch (e) {
        fund.value = null;
    }

    await loadAll();

    // Every fund appears in the breakdown, including ones sitting at $0 — so a
    // missing row means this id is not a fund of this masjid, not an empty one.
    notFound.value = !loadError.value && !row.value;

    bootstrapping.value = false;
});

let filterTimer: ReturnType<typeof setTimeout> | null = null;
watch([fromDate, toDate, sourceFilter, statusFilter], () => {
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
        await Promise.all([
            statsStore.fetchByFund(statsFilters.value),
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
    sourceFilter.value = '';
    statusFilter.value = '';
};

const exportCsv = async () => {
    if (dateRangeInvalid.value) return;

    exporting.value = true;
    try {
        // ledgerFilters pins fund_id, so the CSV covers this fund only — the same
        // set the table below is showing.
        await donationsStore.exportCsv(ledgerFilters.value);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not export the donations.' });
    } finally {
        exporting.value = false;
    }
};

// --- Formatting --------------------------------------------------------------

/** Route query values are string | string[]; only the single-value form is meaningful here. */
function queryString(key: string): string {
    const value = route.query[key];
    return typeof value === 'string' ? value : '';
}

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

const money = (cents: number): string => formatCents(cents, statsMeta.value?.currency ?? 'usd');

const DATE_PARTS: Intl.DateTimeFormatOptions = { year: 'numeric', month: 'short', day: 'numeric' };

/**
 * A wall-calendar day — donated_at, last_gift_at, and the filter dates the admin
 * typed. No time of day to lose: the server sends these as UTC midnight, so they
 * are read back in UTC. Any other zone slides a July 2 gift to July 1 west of it.
 */
const formatDay = (iso: string | null): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { ...DATE_PARTS, timeZone: 'UTC' });
};

/**
 * A real instant — created_at. A Stripe gift stamped 02:00 UTC happened the
 * previous evening in New York, so UTC would date it a day late under a header
 * that names the masjid's zone. Render it in that zone instead.
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

.metric-foot {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 0.5rem;
}

/* Figures that stack in a column have to line up, so digits must be equal width. */
.tabular {
    font-variant-numeric: tabular-nums;
    font-feature-settings: 'tnum';
}

/* Keep the previous figures readable while a filter change is in flight. */
.is-refreshing {
    opacity: 0.55;
    transition: opacity 0.15s ease;
}
</style>
