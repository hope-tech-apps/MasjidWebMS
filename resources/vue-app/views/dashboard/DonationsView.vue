<template>
    <div>
        <PageDataContainer
            title="Donations"
            :paginationOptions="paginationOptions"
            :hideButton="true"
            @pageChange="pageChange"
        >
            <div class="container w-100">
                <!-- Filters -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4 col-lg-3">
                        <label class="form-label small text-muted mb-1">Status</label>
                        <select class="form-select" v-model="statusFilter">
                            <option value="">All statuses</option>
                            <option v-for="s in statuses" :key="s" :value="s" class="text-capitalize">{{ s }}</option>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-3">
                        <label class="form-label small text-muted mb-1">Fund</label>
                        <select class="form-select" v-model="fundFilter">
                            <option value="">All funds</option>
                            <option v-for="fund in funds" :key="fund.id" :value="fund.id">{{ fund.name }}</option>
                        </select>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else-if="donations.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                    <p>No donations found</p>
                </div>

                <!-- Donations Table -->
                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Amount</th>
                                <th>Fund</th>
                                <th>Method</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="donation in donations" :key="donation.id">
                                <td>
                                    <strong>{{ formatCents(donation.charged_amount, donation.currency) }}</strong>
                                </td>
                                <td>
                                    <span v-if="donation.fund">{{ donation.fund.name }}</span>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td>
                                    <span class="text-capitalize">{{ methodLabel(donation) }}</span>
                                </td>
                                <td>{{ formatDate(donation.donated_at || donation.created_at) }}</td>
                                <td>
                                    <span class="badge text-capitalize" :class="statusClass(donation.status)">
                                        {{ donation.status }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" @click="viewDonation(donation)" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </PageDataContainer>

        <!-- View Details Modal -->
        <Teleport to="body">
            <div v-if="showViewModal && selectedDonation" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showViewModal = false">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-receipt me-2"></i>
                                Donation Details
                            </h5>
                            <button type="button" class="btn-close" @click="showViewModal = false"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Amounts -->
                            <h6 class="text-muted text-uppercase small mb-3">Amounts</h6>
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-1">Intended</h6>
                                    <p class="mb-0">{{ formatCents(selectedDonation.intended_amount, selectedDonation.currency) }}</p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-1">Charged</h6>
                                    <p class="mb-0">{{ formatCents(selectedDonation.charged_amount, selectedDonation.currency) }}</p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-1">Donor Covered Fees</h6>
                                    <p class="mb-0">
                                        <span v-if="selectedDonation.donor_covers_fees" class="badge bg-success-subtle text-success">Yes</span>
                                        <span v-else class="badge bg-light text-muted">No</span>
                                    </p>
                                </div>
                            </div>
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-1">Net Amount</h6>
                                    <p class="mb-0">{{ selectedDonation.net_amount !== null ? formatCents(selectedDonation.net_amount, selectedDonation.currency) : '—' }}</p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-1">Stripe Fee</h6>
                                    <p class="mb-0">{{ selectedDonation.stripe_fee_amount !== null ? formatCents(selectedDonation.stripe_fee_amount, selectedDonation.currency) : '—' }}</p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-1">Application Fee</h6>
                                    <p class="mb-0">{{ selectedDonation.application_fee_amount !== null ? formatCents(selectedDonation.application_fee_amount, selectedDonation.currency) : '—' }}</p>
                                </div>
                            </div>

                            <!-- Meta -->
                            <h6 class="text-muted text-uppercase small mb-3">Details</h6>
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-1">Fund</h6>
                                    <p class="mb-0">{{ selectedDonation.fund?.name ?? '—' }}</p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-1">Status</h6>
                                    <p class="mb-0">
                                        <span class="badge text-capitalize" :class="statusClass(selectedDonation.status)">{{ selectedDonation.status }}</span>
                                    </p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-1">Date</h6>
                                    <p class="mb-0">{{ formatDate(selectedDonation.created_at) }}</p>
                                </div>
                            </div>

                            <!-- Stripe identifiers (read-only) -->
                            <h6 class="text-muted text-uppercase small mb-3">Stripe Identifiers</h6>
                            <div class="card bg-light mb-4">
                                <div class="card-body">
                                    <dl class="row mb-0 small font-monospace">
                                        <dt class="col-sm-4 text-muted">Payment Intent</dt>
                                        <dd class="col-sm-8 text-break">{{ selectedDonation.stripe_payment_intent_id ?? '—' }}</dd>
                                        <dt class="col-sm-4 text-muted">Checkout Session</dt>
                                        <dd class="col-sm-8 text-break">{{ selectedDonation.stripe_checkout_session_id ?? '—' }}</dd>
                                        <dt class="col-sm-4 text-muted">Charge</dt>
                                        <dd class="col-sm-8 text-break mb-0">{{ selectedDonation.stripe_charge_id ?? '—' }}</dd>
                                    </dl>
                                </div>
                            </div>

                            <!-- Receipt -->
                            <h6 class="text-muted text-uppercase small mb-3">Tax Receipt</h6>
                            <div v-if="selectedDonation.receipt" class="card border-success-subtle">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="text-muted mb-1">Serial Number</h6>
                                            <p class="mb-0">#{{ selectedDonation.receipt.serial_number }}</p>
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="text-muted mb-1">Eligible Amount</h6>
                                            <p class="mb-0">{{ formatCents(selectedDonation.receipt.eligible_amount, selectedDonation.receipt.currency) }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-muted mb-0">No receipt issued.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="showViewModal = false">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { Donation, DonationStatus } from '@/core/types/data/masjid-related/Donation';
import { Fund } from '@/core/types/data/masjid-related/Fund';
import { useDonationsStore } from '@/stores/masjid/donationsStore';
import { useFundsStore } from '@/stores/masjid/fundsStore';
import Swal from 'sweetalert2';

// Stores
const donationsStore = useDonationsStore();
const fundsStore = useFundsStore();

// State
const loading = ref(false);
const statusFilter = ref<DonationStatus | ''>('');
const fundFilter = ref<number | ''>('');
const showViewModal = ref(false);
const selectedDonation = ref<Donation | null>(null);
const funds = ref<Fund[]>([]);
const statuses: DonationStatus[] = ['pending', 'succeeded', 'failed', 'refunded'];

// Computed
const donations = computed<Donation[]>(() => (donationsStore.donationsPaginated?.data as Donation[]) || []);

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
    // Load the fund list for the filter dropdown (best-effort — failure just
    // leaves the fund filter empty, the donations list still loads).
    try {
        await fundsStore.fetchFunds();
        funds.value = fundsStore.funds;
    } catch (e) {
        funds.value = [];
    }
    await loadData();
});

// Re-fetch when either filter changes.
watch([statusFilter, fundFilter], async () => {
    await loadData(1);
});

// Methods
const loadData = async (page: number = 1) => {
    loading.value = true;
    try {
        await donationsStore.fetchDonations(page, statusFilter.value, fundFilter.value);
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load donations.' });
    } finally {
        loading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    await loadData(data.toPage);
};

const viewDonation = async (donation: Donation) => {
    // Open immediately with the row data, then hydrate with the full record
    // (which includes the receipt) from the show endpoint.
    selectedDonation.value = donation;
    showViewModal.value = true;
    try {
        const full = await donationsStore.fetchDonation(donation.id);
        if (full) selectedDonation.value = full;
    } catch (e) {
        // Keep the row-level data already shown.
    }
};

// Format integer minor units (cents) as a currency string. NEVER divide these
// in the display templates directly — always route through here.
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

const formatDate = (iso: string): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

// Online = Stripe (card via checkout); offline = the recorded payment method
// (cash/check/zelle/…). Falls back to a dash when neither is set.
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

// Lock body scroll while the modal is open
watch(showViewModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<style scoped>
.card {
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

/* Modal */
.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
