<template>
    <div>
        <PageDataContainer title="Shop" :hideButton="true" :paginationOptions="paginationOptions" @pageChange="pageChange">
            <template #headerButtons>
                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    :disabled="exporting || loading"
                    title="Download the sales matching the current tab and filters"
                    @click="downloadCsv"
                >
                    <span v-if="exporting" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    <i v-else class="bi bi-download me-1" aria-hidden="true"></i>
                    Download CSV
                </button>
            </template>

            <div class="container w-100">
                <ShopTabs active="pickup" />

                <p class="text-muted small mb-3">
                    Who has paid for what, and whether it has been handed over. Press Hand out as each item goes to its buyer; Undo
                    puts it back. Money that has gone back to the buyer, or is disputed, is never handed out.
                </p>

                <div v-if="loading && !shopStore.salesPaginated" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                </div>

                <div v-else-if="loadError" class="alert" :class="forbidden ? 'alert-warning' : 'alert-danger'" role="alert">
                    {{ loadError }}
                    <button v-if="!forbidden" class="btn btn-sm btn-outline-danger ms-3" @click="load(page)">Retry</button>
                </div>

                <template v-else>
                    <!-- ============================================ THE HEADER: units per product and size -->
                    <section aria-labelledby="shop-summary-heading" class="mb-4">
                        <h2 id="shop-summary-heading" class="h5">At a glance</h2>

                        <p v-if="totals.oversold_open > 0" class="alert alert-danger py-2 small" role="status" data-test="oversold-total">
                            <i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>
                            {{ totals.oversold_open }} {{ totals.oversold_open === 1 ? 'line is' : 'lines are' }} oversold and
                            {{ totals.oversold_open === 1 ? 'needs' : 'need' }} a decision: refund it or substitute the item.
                        </p>

                        <p v-if="!summary.length" class="text-muted small">Nothing has been sold yet.</p>
                        <div v-else class="table-responsive">
                            <table class="table table-sm align-middle">
                                <caption class="visually-hidden">Units by product and size, over every sale</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Product</th>
                                        <th scope="col">Size</th>
                                        <th scope="col" class="text-end">To hand out</th>
                                        <th scope="col" class="text-end">Collected</th>
                                        <th scope="col" class="text-end">Oversold, undecided</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in summary" :key="`${row.product_id}-${row.variant_id}-${row.product_name}-${row.variant_label}`">
                                        <td dir="auto">{{ row.product_name }}</td>
                                        <td dir="auto">{{ row.variant_label }}</td>
                                        <td class="text-end">{{ row.to_hand_out }}</td>
                                        <td class="text-end">{{ row.collected }}</td>
                                        <td class="text-end" :class="{ 'text-danger fw-semibold': (row.oversold_open ?? 0) > 0 }">{{ row.oversold_open ?? 0 }}</td>
                                    </tr>
                                </tbody>
                                <tfoot v-if="summary.length > 1">
                                    <tr class="fw-semibold">
                                        <td colspan="2">All products</td>
                                        <td class="text-end">{{ totals.to_hand_out }}</td>
                                        <td class="text-end">{{ totals.collected }}</td>
                                        <td class="text-end">{{ totals.oversold_open }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p v-if="summary.length" class="text-muted small mb-0">
                            Units are counted over every sale, whatever the tab or filters below. "Oversold, undecided" counts lines.
                        </p>
                    </section>

                    <!-- ============================================ THE LIST -->
                    <h2 class="h5">Sales</h2>

                    <ul class="nav nav-pills mb-3" aria-label="Which sales to show">
                        <li v-for="tab in SALE_TABS" :key="tab.state" class="nav-item">
                            <button type="button" class="nav-link" :class="{ active: filters.state === tab.state }"
                                :aria-pressed="filters.state === tab.state ? 'true' : 'false'" @click="chooseState(tab.state)">
                                {{ tab.label }}
                            </button>
                        </li>
                    </ul>

                    <form class="row g-2 align-items-end mb-3" role="search" @submit.prevent="applySearch">
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small text-muted mb-0" for="shop-filter-product">Product</label>
                            <select id="shop-filter-product" v-model="filters.product_id" class="form-select form-select-sm" @change="onProductChange">
                                <option value="">All products</option>
                                <option v-for="option in products" :key="option.id" :value="option.id">{{ option.name }}</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small text-muted mb-0" for="shop-filter-size">Size</label>
                            <select id="shop-filter-size" v-model="filters.variant_id" class="form-select form-select-sm" @change="reload">
                                <option value="">All sizes</option>
                                <option v-for="option in sizes" :key="option.id" :value="option.id">{{ option.label }}</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-0" for="shop-filter-search">Order number or buyer name</label>
                            <input id="shop-filter-search" v-model="searchDraft" type="search" class="form-control form-control-sm" maxlength="100" autocomplete="off">
                        </div>
                        <div class="col-md-2 d-flex gap-1">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
                            <button v-if="filtersActive" type="button" class="btn btn-sm btn-link" @click="clearFilters">Clear</button>
                        </div>
                    </form>

                    <div v-if="loading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                    </div>

                    <div v-else-if="!sales.length" class="text-center py-5 text-muted" data-test="empty">
                        <i class="bi bi-bag-check fs-1 d-block mb-3" aria-hidden="true"></i>
                        <p class="mb-0">{{ emptyText }}</p>
                    </div>

                    <div v-else class="table-responsive">
                        <table class="table align-middle">
                            <caption class="visually-hidden">Sales to hand out or already collected</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Order</th>
                                    <th scope="col">Paid</th>
                                    <th scope="col">Buyer</th>
                                    <th scope="col">Item</th>
                                    <th scope="col" class="text-end">Qty</th>
                                    <th scope="col" class="text-end">Total</th>
                                    <th scope="col">State</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="sale in sales" :key="sale.id">
                                    <tr :class="{ 'table-danger': statusOf(sale).oversoldOpen }" data-test="sale-row">
                                        <td class="fw-semibold text-nowrap">{{ sale.order_number }}</td>
                                        <td class="small">{{ paidAt(sale) }}</td>
                                        <td>
                                            <strong dir="auto">{{ sale.buyer_name || '—' }}</strong>
                                            <div v-if="sale.buyer_email" class="small">
                                                <a :href="`mailto:${sale.buyer_email}`" class="text-decoration-none">{{ sale.buyer_email }}</a>
                                            </div>
                                            <div v-if="sale.buyer_phone" class="small">
                                                <a :href="`tel:${sale.buyer_phone}`" class="text-decoration-none">{{ sale.buyer_phone }}</a>
                                            </div>
                                        </td>
                                        <td dir="auto">
                                            {{ sale.product_name }}
                                            <div class="small text-muted">{{ sale.variant_label }}</div>
                                        </td>
                                        <td class="text-end">{{ sale.quantity }}</td>
                                        <td class="text-end text-nowrap">{{ money(sale) }}</td>
                                        <td>
                                            <span class="badge" :class="stateClass(statusOf(sale).state)">{{ statusOf(sale).stateLabel }}</span>
                                            <span v-for="badge in statusOf(sale).badges" :key="badge.text" class="badge text-wrap text-start ms-1" :class="toneClass(badge.tone)">
                                                {{ badge.text }}
                                            </span>

                                            <div v-if="sale.collected_at" class="small text-muted mt-1">
                                                {{ paidWhen(sale.collected_at) }}<template v-if="sale.collected_by?.name"> · {{ sale.collected_by.name }}</template>
                                            </div>
                                            <div v-if="sale.resolution" class="small text-muted mt-1">
                                                {{ sale.resolution === 'refunded' ? 'Refunded' : 'Substituted' }}<template v-if="sale.resolved_by?.name"> by {{ sale.resolved_by.name }}</template><template v-if="sale.resolved_at"> · {{ paidWhen(sale.resolved_at) }}</template>
                                            </div>

                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                <button v-if="statusOf(sale).canCollect" type="button" class="btn btn-sm btn-success"
                                                    :disabled="busyId !== null" :aria-label="`Hand out order ${sale.order_number}`"
                                                    @click="collect(sale)">Hand out</button>
                                                <button v-if="statusOf(sale).canUndoCollect" type="button" class="btn btn-sm btn-link p-0"
                                                    :disabled="busyId !== null" :aria-label="`Undo hand out for order ${sale.order_number}`"
                                                    @click="uncollect(sale)">Undo</button>
                                                <button v-if="statusOf(sale).canUndoResolve" type="button" class="btn btn-sm btn-link p-0"
                                                    :disabled="busyId !== null" :aria-label="`Undo the oversold decision for order ${sale.order_number}`"
                                                    @click="unresolve(sale)">Undo decision</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <!-- The decision an oversold line is owed: it stays red until somebody makes it. -->
                                    <tr v-if="statusOf(sale).oversoldOpen" class="table-danger" data-test="oversold-banner">
                                        <td colspan="7" class="pt-0">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span class="text-danger-emphasis fw-semibold flex-grow-1" role="status">
                                                    <i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>
                                                    Oversold: refund it or substitute the item
                                                </span>
                                                <button type="button" class="btn btn-sm btn-outline-danger" :disabled="busyId !== null"
                                                    :aria-label="`Mark order ${sale.order_number} refunded`" @click="resolve(sale, 'refunded')">Mark refunded</button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" :disabled="busyId !== null"
                                                    :aria-label="`Mark order ${sale.order_number} substituted`" @click="resolve(sale, 'substituted')">Mark substituted</button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeMount, ref } from 'vue';
import Swal from 'sweetalert2';
import PageDataContainer from '@/components/PageDataContainer.vue';
import ShopTabs from './ShopTabs.vue';
import { formatMinor } from '@/composables/useMinorUnits';
import {
    blankSaleFilters, classifyFailure, formatWhen, productOptions, replaceSale, SALE_TABS, saleStatus, sizeOptions, summaryTotals
} from '@/core/helpers/shop';
import type { SaleStatus, Tone } from '@/core/helpers/shop';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import type { SaleFilters, SaleResolution, SaleState, ShopSale } from '@/core/types/data/masjid-related/Shop';
import { shopErrorReader, useShopStore } from '@/stores/masjid/shopStore';
import { useMasjidStore } from '@/stores/masjidStore';

/**
 * The pickup list: every paid line, whether it has been handed over, and the decision an oversold one
 * is owed.
 *
 * The header is the server's `meta.summary` in units, over every sale; the tabs and filters below move
 * the list and the CSV but never the header. Everything a line may do (hand out, undo, mark refunded or
 * substituted) comes from `saleStatus()` in one place, so a refunded or disputed order never offers
 * Hand out, and the server refuses it too (a 422 sentence, shown as it is). Each action answers with
 * the line as it now stands; the list is then read again, so the line moves between the tabs and the
 * header counts follow without a spinner.
 *
 * "Paid" is shown on the organisation's own clock (`masjid.timezone`), with the zone written out.
 */

const masjidStore = useMasjidStore();
const shopStore = useShopStore();

const filters = ref<SaleFilters>(blankSaleFilters());
const searchDraft = ref('');
const page = ref(1);

const loading = ref(true);
const loadError = ref('');
const forbidden = ref(false);
const exporting = ref(false);
const busyId = ref<number | null>(null);

/** A later request supersedes an earlier one: only the latest settles the screen. */
let loadSequence = 0;

const paginationOptions = ref<PaginationOptions>({ itemsTotal: 0, currentPage: 0, perPage: 25 });

const sales = computed<ShopSale[]>(() => (shopStore.salesPaginated?.data ?? []) as ShopSale[]);
const summary = computed(() => shopStore.salesMeta?.summary ?? []);
const totals = computed(() => summaryTotals(summary.value));
const products = computed(() => productOptions(summary.value));
const sizes = computed(() => sizeOptions(summary.value, filters.value.product_id));

const filtersActive = computed<boolean>(() =>
    filters.value.product_id !== '' || filters.value.variant_id !== '' || filters.value.search.trim() !== '');

const emptyText = computed<string>(() => {
    if (filtersActive.value) return 'No sales match these filters.';
    if (filters.value.state === 'to_hand_out') return 'Nothing is waiting to be handed out.';
    if (filters.value.state === 'collected') return 'Nothing has been handed out yet.';

    return 'Nothing has been sold yet.';
});

const statusOf = (sale: ShopSale): SaleStatus => saleStatus(sale);

const money = (sale: ShopSale): string => formatMinor(sale.total_minor, sale.currency);
const paidAt = (sale: ShopSale): string => formatWhen(sale.paid_at, masjidStore.masjid?.timezone);
const paidWhen = (iso: string | null): string => formatWhen(iso, masjidStore.masjid?.timezone);

const stateClass = (state: SaleStatus['state']): string => {
    if (state === 'collected') return 'bg-success-subtle text-success-emphasis';
    if (state === 'to_hand_out') return 'bg-primary-subtle text-primary-emphasis';

    return 'bg-secondary-subtle text-secondary-emphasis';
};

const toneClass = (tone: Tone): string => ({
    success: 'bg-success-subtle text-success-emphasis',
    warning: 'bg-warning-subtle text-warning-emphasis',
    danger: 'bg-danger-subtle text-danger-emphasis',
    secondary: 'bg-secondary-subtle text-secondary-emphasis',
    info: 'bg-info-subtle text-info-emphasis'
}[tone]);

const toast = (icon: 'success' | 'error', text: string) => {
    Swal.fire({ icon, text, timer: 2500, showConfirmButton: false, toast: true, position: 'top-end' });
};

const syncPagination = () => {
    paginationOptions.value.itemsTotal = shopStore.salesPaginated?.total ?? 0;
    paginationOptions.value.currentPage = shopStore.salesPaginated?.current_page ?? 0;
    paginationOptions.value.perPage = shopStore.salesPaginated?.per_page ?? 25;
};

/** `quiet`: read again behind the table that is on screen, with no spinner. */
async function load(toPage: number = 1, quiet: boolean = false) {
    const mine = ++loadSequence;

    if (!quiet) loading.value = true;
    loadError.value = '';
    forbidden.value = false;

    try {
        await shopStore.fetchSales(filters.value, toPage);
        if (mine !== loadSequence) return;

        page.value = toPage;
        syncPagination();
    } catch (error) {
        if (mine !== loadSequence) return;

        const failure = classifyFailure(error, 'The pickup list could not be loaded.', shopErrorReader);
        if (quiet) {
            toast('error', `The list could not be refreshed: ${failure.message}`);
        } else {
            forbidden.value = failure.kind === 'forbidden';
            loadError.value = failure.message;
        }
    } finally {
        if (mine === loadSequence) loading.value = false;
    }
}

const reload = () => load(1);
const pageChange = (data: PageChangeData) => load(data.toPage);

function chooseState(state: SaleState) {
    if (filters.value.state === state) return;
    filters.value.state = state;
    reload();
}

/** A size belongs to one product: choosing another product clears the size. */
function onProductChange() {
    filters.value.variant_id = '';
    reload();
}

function applySearch() {
    filters.value.search = searchDraft.value.trim();
    reload();
}

function clearFilters() {
    filters.value = { ...blankSaleFilters(), state: filters.value.state };
    searchDraft.value = '';
    reload();
}

// ------------------------------------------------------------------------------------------ actions

/** Run one row action: busy while it runs, the line as the server now has it in place, then a quiet refresh. */
async function run(sale: ShopSale, action: () => Promise<ShopSale>, failureTitle: string, fallback: string, done: string): Promise<boolean> {
    if (busyId.value !== null) return false;
    busyId.value = sale.id;

    try {
        const updated = await action();
        const rows = shopStore.salesPaginated?.data;
        if (shopStore.salesPaginated && rows) shopStore.salesPaginated.data = replaceSale(rows as ShopSale[], updated);

        toast('success', done);
        await load(page.value, true);

        return true;
    } catch (error) {
        // A refused hand-out carries a sentence (refunded or disputed order): shown as it is. The line
        // has usually moved since the list was read, so the list is read again.
        const failure = classifyFailure(error, fallback, shopErrorReader);
        await Swal.fire({ icon: 'error', title: failureTitle, text: failure.message });
        await load(page.value, true);

        return false;
    } finally {
        busyId.value = null;
    }
}

const collect = (sale: ShopSale) =>
    run(sale, () => shopStore.collectSale(sale.id), 'Not handed out', 'Could not mark this handed out.', `Handed out: ${sale.order_number}.`);

async function uncollect(sale: ShopSale) {
    const by = sale.collected_by?.name ? ` by ${sale.collected_by.name}` : '';

    const confirmed = await Swal.fire({
        title: `Undo the hand-out of ${sale.order_number}?`,
        text: `It was handed out at ${paidWhen(sale.collected_at)}${by}. Undoing removes ${by ? 'their name and the time' : 'the time'}, and it goes back on the list to hand out.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Undo hand out',
        cancelButtonText: 'Keep it handed out'
    });
    if (!confirmed.isConfirmed) return;

    await run(sale, () => shopStore.uncollectSale(sale.id), 'Not undone', 'Could not undo the hand-out.', `Hand-out undone: ${sale.order_number}.`);
}

async function resolve(sale: ShopSale, resolution: SaleResolution) {
    if (resolution === 'refunded') {
        // Recording a decision, not making it: nothing is refunded from here.
        const confirmed = await Swal.fire({
            title: `Mark ${sale.order_number} refunded?`,
            text: 'This records that you have refunded the buyer. It does not refund anything itself: do that in Stripe. The line is then not handed out.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Mark refunded',
            cancelButtonText: 'Cancel'
        });
        if (!confirmed.isConfirmed) return;
    }

    await run(
        sale,
        () => shopStore.resolveSale(sale.id, resolution),
        'Not recorded',
        'Could not record the decision.',
        resolution === 'refunded' ? `Marked refunded: ${sale.order_number}.` : `Marked substituted: ${sale.order_number}. It is still to hand out.`
    );
}

const unresolve = (sale: ShopSale) =>
    run(sale, () => shopStore.unresolveSale(sale.id), 'Not undone', 'Could not undo the decision.', `Decision undone: ${sale.order_number}.`);

// ------------------------------------------------------------------------------------------- the CSV

async function downloadCsv() {
    if (exporting.value) return;
    exporting.value = true;

    try {
        await shopStore.exportSalesCsv(filters.value);
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not download the pickup list.' });
    } finally {
        exporting.value = false;
    }
}

onBeforeMount(() => load(1));
</script>
