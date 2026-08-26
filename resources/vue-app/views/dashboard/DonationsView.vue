<template>
    <div>
        <PageDataContainer
            title="Donations"
            :paginationOptions="paginationOptions"
            :buttonProps="{ title: 'Record gift', type: 'button', class: 'btn btn-success', disabled: false }"
            @headerButtonClick="openOffline"
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
                    <div class="col-md-4 col-lg-4">
                        <label class="form-label small text-muted mb-1">Search donor</label>
                        <input type="text" class="form-control" v-model="searchQuery"
                            placeholder="Name or email…" @keyup.enter="loadData(1)" />
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
                                <th>Donor</th>
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
                                    <span v-if="donation.contact" class="fw-semibold">{{ donorName(donation) }}</span>
                                    <span v-else class="text-muted">— (general)</span>
                                </td>
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
                                    <!-- Stripe gifts have no edit affordance: the payment record owns them. -->
                                    <button v-if="donation.source === 'offline'" class="btn btn-sm btn-outline-secondary ms-1" @click="openEdit(donation)" title="Edit gift">
                                        <i class="bi bi-pencil"></i>
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
                                    <h6 class="text-muted mb-1">Donor</h6>
                                    <p class="mb-0">{{ selectedDonation.contact ? donorName(selectedDonation) : '— (general)' }}</p>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-1">Method</h6>
                                    <p class="mb-0 text-capitalize">{{ methodLabel(selectedDonation) }}<span v-if="selectedDonation.check_number" class="text-muted text-lowercase"> · #{{ selectedDonation.check_number }}</span></p>
                                </div>
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
                                    <!-- The GIFT date, matching the ledger column. This showed
                                         created_at, so an imported or back-dated gift displayed
                                         the day it was typed in. -->
                                    <p class="mb-0">{{ formatDate(selectedDonation.donated_at || selectedDonation.created_at) }}</p>
                                </div>
                                <div v-if="selectedDonation.note" class="col-12 mt-3">
                                    <h6 class="text-muted mb-1">Note</h6>
                                    <p class="mb-0">{{ selectedDonation.note }}</p>
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

        <!-- Record an offline gift -->
        <Teleport to="body">
            <div v-if="showOffline" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" @click.self="showOffline=false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ editingId ? 'Edit gift' : 'Record an offline gift' }}</h5>
                            <button type="button" class="btn-close" @click="showOffline=false"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small">For cash, check, Zelle, Venmo, PayPal or Square donations recorded by hand.</p>
                            <div v-if="editingId && editingReceipt" class="alert alert-warning py-2 small">
                                Receipt <strong>#{{ editingReceipt }}</strong> has been issued for this gift, so the donor,
                                fund, amount and date are locked — the donor is holding a tax document that states them.
                                The note, method and cheque number can still be corrected.
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted">Donor (optional)</label>
                                <input class="form-control" v-model="offlineDonorSearch" @input="searchDonors" :placeholder="offlineDonor ? '' : 'Search a member, or leave blank for general'">
                                <div v-if="offlineDonor" class="form-text">Selected: <strong>{{ offlineDonor.first_name }} {{ offlineDonor.last_name }}</strong> <a href="#" @click.prevent="offlineDonor=null">change</a></div>
                                <div v-else-if="offlineDonorResults.length" class="list-group mt-1" style="max-height:22vh; overflow-y:auto;">
                                    <button v-for="m in offlineDonorResults" :key="m.id" type="button" class="list-group-item list-group-item-action" @click="pickDonor(m)">{{ m.first_name }} {{ m.last_name }} <small class="text-muted">{{ m.email||'' }}</small></button>
                                </div>
                                <!-- The state that did not exist: a search that found nobody used to
                                     render nothing at all, which looks exactly like "still typing". -->
                                <div v-else-if="donorSearched && offlineDonorSearch.trim()" class="form-text text-success">
                                    <i class="bi bi-plus-circle"></i> No existing member matches — <strong>{{ offlineDonorSearch.trim() }}</strong> will be added as a new donor.
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Amount ($) *</label>
                                    <input class="form-control" type="number" step="0.01" v-model="offlineForm.amount">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Method *</label>
                                    <select class="form-select text-capitalize" v-model="offlineForm.payment_method">
                                        <option v-for="m in methods" :key="m" :value="m" class="text-capitalize">{{ m }}</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-2" v-if="offlineForm.payment_method === 'check'">
                                    <label class="form-label small text-muted">Check #</label>
                                    <input class="form-control" v-model="offlineForm.check_number" placeholder="1234">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Fund *</label>
                                    <select class="form-select" v-model="offlineForm.fund_id">
                                        <option value="">Select…</option>
                                        <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }}</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Date *</label>
                                    <input class="form-control" type="date" v-model="offlineForm.donated_at">
                                </div>
                                <div class="col-12 mb-2">
                                    <label class="form-label small text-muted">Note</label>
                                    <input class="form-control" v-model="offlineForm.note">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" @click="showOffline=false">Cancel</button>
                            <button class="btn btn-success" :disabled="!offlineValid || savingOffline" @click="submitOffline">
                                <span v-if="savingOffline" class="spinner-border spinner-border-sm"></span><span v-else>{{ editingId ? 'Save changes' : 'Record gift' }}</span>
                            </button>
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
import { useMasjidStore } from '@/stores/masjidStore';
import { useAuthStore } from '@/stores/authStore';
import ApiService from '@/core/services/ApiService';
import Swal from 'sweetalert2';

// Stores
const donationsStore = useDonationsStore();
const fundsStore = useFundsStore();

// State
const loading = ref(false);
const statusFilter = ref<DonationStatus | ''>('');
const fundFilter = ref<number | ''>('');
const searchQuery = ref('');
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
        await donationsStore.fetchDonations(page, statusFilter.value, fundFilter.value, searchQuery.value.trim());
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load donations.' });
    } finally {
        loading.value = false;
    }
};

// --- Offline gift entry ---
const masjidStore = useMasjidStore();
const authStore = useAuthStore();
const methods = ['cash', 'check', 'zelle', 'venmo', 'paypal', 'square', 'credit', 'giftcard', 'other'];
const showOffline = ref(false);
const savingOffline = ref(false);
const offlineForm = ref<any>({ amount: '', payment_method: 'cash', check_number: '', fund_id: '', donated_at: '', note: '' });
const offlineDonor = ref<any>(null);
const offlineDonorSearch = ref('');
const offlineDonorResults = ref<any[]>([]);
const donorSearched = ref(false);
const editingId = ref<number | null>(null);
const editingReceipt = ref<string | null>(null);
let donorTimer: any = null;

/** Today where the MASJID is, not where UTC is. `toISOString()` is a UTC date,
 *  so every gift entered after ~8pm Eastern was pre-filled with TOMORROW. */
const todayLocal = () => new Date().toLocaleDateString('en-CA');

const oMasjidId = () => authStore.dashboardMasjidId ?? masjidStore.masjid?.id;
const offlineValid = computed(() => !!offlineForm.value.amount && !!offlineForm.value.fund_id && !!offlineForm.value.donated_at && !!offlineForm.value.payment_method);

const openOffline = () => {
    editingId.value = null; editingReceipt.value = null;
    offlineForm.value = { amount: '', payment_method: 'cash', check_number: '', fund_id: '', donated_at: todayLocal(), note: '' };
    offlineDonor.value = null; offlineDonorSearch.value = ''; offlineDonorResults.value = []; donorSearched.value = false;
    showOffline.value = true;
};

/** Reopen a recorded gift for correction. Same form, same validation, same
 *  submit — an edit screen that drifts from the entry screen is how the two
 *  stop agreeing about what a gift is. */
const openEdit = (donation: any) => {
    editingId.value = donation.id;
    editingReceipt.value = donation.receipt?.serial_number ?? null;
    offlineForm.value = {
        amount: ((donation.charged_amount ?? 0) / 100).toFixed(2),
        payment_method: donation.payment_method || 'cash',
        check_number: donation.check_number || '',
        fund_id: donation.fund_id || donation.fund?.id || '',
        donated_at: (donation.donated_at || donation.created_at || '').slice(0, 10),
        note: donation.note || '',
    };
    offlineDonor.value = donation.contact ?? null;
    offlineDonorSearch.value = donation.contact
        ? `${donation.contact.first_name ?? ''} ${donation.contact.last_name ?? ''}`.trim()
        : '';
    offlineDonorResults.value = []; donorSearched.value = false;
    showOffline.value = true;
};
const searchDonors = () => {
    // Editing the text after picking somebody used to leave the PICKED contact
    // attached: the box could read "Ahmed Khan" while the gift booked to Ahmad
    // Fais. Typing away from the selection now detaches it.
    if (offlineDonor.value) {
        const picked = `${offlineDonor.value.first_name ?? ''} ${offlineDonor.value.last_name ?? ''}`.trim();
        if (offlineDonorSearch.value.trim() !== picked) offlineDonor.value = null;
    }
    donorSearched.value = false;
    clearTimeout(donorTimer);
    donorTimer = setTimeout(async () => {
        const q = offlineDonorSearch.value.trim();
        if (!q) { offlineDonorResults.value = []; donorSearched.value = false; return; }
        try {
            const res = await ApiService.get(`/api/admin/masjids/${oMasjidId()}/contacts?search=${encodeURIComponent(q)}&per_page=8` as any);
            offlineDonorResults.value = res.data?.data?.data || [];
        } catch {
            // A failed lookup must not read as "no such member" — the name is
            // still sent and the server decides.
            offlineDonorResults.value = [];
        }
        donorSearched.value = true;
    }, 300);
};
const pickDonor = (m: any) => { offlineDonor.value = m; offlineDonorResults.value = []; offlineDonorSearch.value = `${m.first_name} ${m.last_name}`; };
const submitOffline = async () => {
    if (!offlineValid.value) return;
    const p = new URLSearchParams();
    p.append('amount', offlineForm.value.amount);
    p.append('payment_method', offlineForm.value.payment_method);
    if (offlineForm.value.payment_method === 'check') p.append('check_number', offlineForm.value.check_number ?? '');
    p.append('fund_id', String(offlineForm.value.fund_id));
    p.append('donated_at', offlineForm.value.donated_at);
    // THE DONOR. A picked contact wins; otherwise whatever is in the box is sent
    // as a NAME and the server finds-or-creates the contact. The one thing that
    // must never happen again is the typed name going nowhere.
    if (offlineDonor.value) p.append('contact_id', String(offlineDonor.value.id));
    else if (offlineDonorSearch.value.trim()) p.append('donor_name', offlineDonorSearch.value.trim());
    else if (editingId.value) p.append('contact_id', '');   // explicitly back to general
    p.append('note', offlineForm.value.note ?? '');
    savingOffline.value = true;
    try {
        const base = `/api/admin/masjids/${oMasjidId()}/donations`;
        if (editingId.value) await ApiService.put(`${base}/${editingId.value}` as any, p);
        else await ApiService.post(base as any, p);
        const edited = !!editingId.value;
        showOffline.value = false;
        await loadData(1);
        Swal.fire({
            icon: 'success',
            title: edited ? 'Saved' : 'Recorded',
            text: edited ? 'The gift was updated.' : 'The gift was added.',
        });
    } catch (e: any) {
        // Say what the server actually refused — an ambiguous donor name and a
        // receipt-locked field are both things the admin can act on.
        const data = e?.response?.data;
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: data?.message || data?.errors?.donor_name?.[0] || 'Could not save the gift.',
        });
    } finally { savingOffline.value = false; }
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
    // Date-only fields (donated_at) are serialized as UTC midnight; render in UTC
    // so a July 2 date doesn't display as July 1 in timezones behind UTC.
    return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' });
};

// Donor's First Last (or the business name). Falls back gracefully.
const donorName = (donation: any): string => {
    const c = donation.contact;
    if (!c) return '';
    return [c.first_name, c.last_name].filter(Boolean).join(' ') || 'Donor';
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
