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
                                    <!--
                                        The GIFT's own designation, read off the row. Never
                                        donation.fund.type: zakat given to a general fund is the
                                        common case and a sadaqah gift into the zakat fund is the
                                        other one, so a badge derived from the bucket would label
                                        the wrong money in both directions (.claude/rules/zakat.md).
                                    -->
                                    <span v-if="donation.is_zakat" class="badge bg-info-subtle text-info ms-2" :title="zakatBadgeTitle(donation)">Zakat</span>
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
                                    <!--
                                        The receipt affordance, in the only two states that carry an
                                        action. A gift that CANNOT be receipted from here — a Stripe
                                        gift (the webhook issues those), one that has not succeeded,
                                        one whose fund the org set not to issue receipts — gets no
                                        button at all and its reason from the details modal. A
                                        disabled button explains nothing and invites a support ticket.
                                    -->
                                    <button
                                        v-if="donation.receipt"
                                        class="btn btn-sm btn-outline-secondary ms-1"
                                        :disabled="isReceiptBusy(donation.id)"
                                        :title="`Download receipt #${donation.receipt.serial_number}`"
                                        @click="downloadReceipt(donation)"
                                    >
                                        <span v-if="isReceiptBusy(donation.id)" class="spinner-border spinner-border-sm"></span>
                                        <i v-else class="bi bi-file-earmark-pdf"></i>
                                    </button>
                                    <button
                                        v-else-if="canIssueReceipt(donation)"
                                        class="btn btn-sm btn-outline-success ms-1"
                                        :disabled="isReceiptBusy(donation.id)"
                                        title="Issue tax receipt"
                                        @click="issueReceipt(donation)"
                                    >
                                        <span v-if="isReceiptBusy(donation.id)" class="spinner-border spinner-border-sm"></span>
                                        <i v-else class="bi bi-receipt"></i>
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
                                    <h6 class="text-muted mb-1">Zakat</h6>
                                    <!--
                                        Says WHY, not just whether. A treasurer auditing the
                                        restricted pot has to be able to tell a donor's own
                                        declaration from an inference the platform made off the
                                        fund's type, because those two carry different weight if
                                        the designation is ever questioned.
                                    -->
                                    <p class="mb-0">{{ zakatReason(selectedDonation) }}</p>
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
                                    <div class="row align-items-end">
                                        <div class="col-md-5">
                                            <h6 class="text-muted mb-1">Serial Number</h6>
                                            <p class="mb-0">#{{ selectedDonation.receipt.serial_number }}</p>
                                        </div>
                                        <div class="col-md-4">
                                            <h6 class="text-muted mb-1">Eligible Amount</h6>
                                            <p class="mb-0">{{ formatCents(selectedDonation.receipt.eligible_amount, selectedDonation.receipt.currency) }}</p>
                                        </div>
                                        <div class="col-md-3 text-md-end mt-3 mt-md-0">
                                            <button
                                                class="btn btn-sm btn-outline-secondary"
                                                :disabled="isReceiptBusy(selectedDonation.id)"
                                                @click="downloadReceipt(selectedDonation)"
                                            >
                                                <span v-if="isReceiptBusy(selectedDonation.id)" class="spinner-border spinner-border-sm me-1"></span>
                                                <i v-else class="bi bi-download me-1"></i>
                                                Download receipt
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!--
                                Every reason a gift has no receipt, said out loud. "No receipt
                                issued." on its own left the treasurer unable to tell a decision
                                they still have to make from a state they cannot change, so each
                                branch below either offers the action or names what is blocking it.
                                The Stripe and not-succeeded sentences mirror the server's own
                                refusals so the two can never tell the admin different things.
                            -->
                            <template v-else-if="selectedDonation.source === 'offline' && selectedDonation.status === 'succeeded' && selectedDonation.fund?.receiptable !== false">
                                <p class="mb-2">
                                    <strong>No receipt issued yet.</strong>
                                    Issuing one takes the next serial in this masjid's gap-free sequence and cannot be
                                    undone — do it once the money has cleared. The donor, fund, amount and date are
                                    frozen from then on.
                                </p>
                                <button
                                    class="btn btn-success btn-sm"
                                    :disabled="isReceiptBusy(selectedDonation.id)"
                                    @click="issueReceipt(selectedDonation)"
                                >
                                    <span v-if="isReceiptBusy(selectedDonation.id)" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="bi bi-receipt me-1"></i>
                                    Issue receipt
                                </button>
                            </template>
                            <p v-else-if="selectedDonation.source !== 'offline'" class="text-muted mb-0">
                                Receipts for card gifts are issued automatically when Stripe confirms the payment.
                            </p>
                            <p v-else-if="selectedDonation.status !== 'succeeded'" class="text-muted mb-0">
                                This gift is not marked succeeded, so it cannot be receipted yet.
                            </p>
                            <p v-else class="text-muted mb-0">
                                The {{ selectedDonation.fund?.name ?? 'chosen' }} fund is set not to issue tax receipts,
                                so no receipt can be issued for this gift.
                            </p>
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
                                <!--
                                    NOTHING IS PRE-SELECTED HERE. The picker used to open on
                                    "cash" with no placeholder, so an untouched control read as a
                                    choice — and "Issue tax receipt" SNAPSHOTS payment_method onto
                                    the receipt row (ReceiptService::issueFor), where per its own
                                    docblock it is the only evidence the document rests on. A
                                    $5,000 cheque nobody stated a method for booked as cash, lost
                                    its cheque number (the server nulls it for any non-check
                                    method) and printed "Cash" on the donor's tax document, which
                                    a later edit can no longer correct. Same discipline the zakat
                                    box already follows: pre-fill nothing the staff member did not
                                    say.
                                -->
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small text-muted">Method <span v-if="!editingId">*</span></label>
                                    <select class="form-select text-capitalize" v-model="offlineForm.payment_method">
                                        <option value="">Select…</option>
                                        <option v-for="m in methods" :key="m" :value="m" class="text-capitalize">{{ m }}</option>
                                    </select>
                                    <!--
                                        On the EDIT path a blank method means "never recorded" and
                                        the request OMITS the key, so the stored value is left
                                        exactly as it is (UpdateOfflineDonationRequest is
                                        `sometimes` throughout). Demanding a choice here instead
                                        would block a note-only correction on a legacy gift —
                                        precisely what that FormRequest's method heal exists to
                                        prevent — while still writing a method nobody stated.
                                    -->
                                    <div v-if="editingId && !offlineForm.payment_method" class="form-text">
                                        Not recorded. Leave it blank to keep it that way, or state how the money arrived.
                                    </div>
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
                                <!--
                                    Zakat is the GIVER's restriction on this gift, so it is asked
                                    as its own question rather than inferred from the fund at
                                    display time. The fund's type only PRE-FILLS the box (matching
                                    ZakatDesignation::fundDefault); the moment staff touch it, the
                                    answer is theirs and is sent explicitly.
                                -->
                                <div class="col-12 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="offline-zakat"
                                            v-model="offlineForm.is_zakat" @change="zakatTouched = true">
                                        <label class="form-check-label" for="offline-zakat">This gift is zakat</label>
                                    </div>
                                    <div class="form-text">
                                        Ask the giver — the fund does not decide it. Leave unticked if they did not say
                                        and the fund is not your zakat fund.
                                    </div>
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
/**
 * The gifts whose receipt is being issued or downloaded, so each row's own button
 * spins and nothing else on the page is disabled while it is in flight.
 *
 * A SET, not a single id. With one shared id, starting gift B while gift A was
 * still posting re-enabled B's button the moment A's request finished — its
 * `finally` cleared the flag it no longer owned — and a second "Issue receipt"
 * POST could then fire for a gift whose first one had not come back. The server
 * is idempotent so no second serial is minted, but the guard this ref exists to
 * be has to actually hold per row.
 */
const receiptBusy = ref<Set<number>>(new Set());

/** Whether THIS gift has a receipt request outstanding (never merely "some gift does"). */
const isReceiptBusy = (donationId: number): boolean => receiptBusy.value.has(donationId);

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
const offlineForm = ref<any>({ amount: '', payment_method: '', check_number: '', fund_id: '', donated_at: '', note: '', is_zakat: false });
const offlineDonor = ref<any>(null);
const offlineDonorSearch = ref('');
const offlineDonorResults = ref<any[]>([]);
const donorSearched = ref(false);
const editingId = ref<number | null>(null);
const editingReceipt = ref<string | null>(null);
let donorTimer: any = null;

/**
 * --- The zakat box's three pieces of state -----------------------------------
 *
 * `zakatTouched` — whether staff answered the question at all. Untouched, the
 * request omits `zakat` entirely, which is NOT the same as sending false: the
 * server then records the fund's default with `fund_default` provenance instead
 * of an admin attestation nobody made, and on the edit path an always-sent value
 * would stamp SOURCE_ADMIN over a donor's own declaration.
 *
 * `zakatFollowsFund` — whether the shown value is still only the fund's default
 * and may therefore be re-derived when the fund changes. False as soon as there
 * is a real answer behind it (staff touched it, or the gift being edited carries
 * `donor`/`admin` provenance), which mirrors the server's own `$inferred` branch
 * in DonationsController::update.
 *
 * `zakatPrefilledFor` — the fund the box was last pre-filled for, so re-opening
 * the modal (which reassigns the whole form and so fires the fund watcher) does
 * not read as the admin changing the fund and overwrite a stored designation.
 */
const zakatTouched = ref(false);
const zakatFollowsFund = ref(true);
const zakatPrefilledFor = ref<number | string | ''>('');

/** Today where the MASJID is, not where UTC is. `toISOString()` is a UTC date,
 *  so every gift entered after ~8pm Eastern was pre-filled with TOMORROW. */
const todayLocal = () => new Date().toLocaleDateString('en-CA');

const oMasjidId = () => authStore.dashboardMasjidId ?? masjidStore.masjid?.id;
/**
 * What may be saved.
 *
 * The method is required when RECORDING a gift — a receipt issued later freezes
 * it onto a tax document, so it has to be something the staff member actually
 * stated. It is NOT required when EDITING: a blank there means the method was
 * never captured (a legacy or imported row), the key is omitted from the request
 * and the stored value stands. Requiring it on that path would leave a treasurer
 * with a disabled Save on a gift they only wanted to fix the note of.
 */
const offlineValid = computed(() =>
    !!offlineForm.value.amount
    && !!offlineForm.value.fund_id
    && !!offlineForm.value.donated_at
    && (!!editingId.value || !!offlineForm.value.payment_method));

const openOffline = () => {
    editingId.value = null; editingReceipt.value = null;
    offlineForm.value = { amount: '', payment_method: '', check_number: '', fund_id: '', donated_at: todayLocal(), note: '', is_zakat: false };
    offlineDonor.value = null; offlineDonorSearch.value = ''; offlineDonorResults.value = []; donorSearched.value = false;
    // Nobody has said anything about a brand-new gift, so the box is free to
    // follow whichever fund gets picked until staff answer for themselves.
    zakatTouched.value = false; zakatFollowsFund.value = true; zakatPrefilledFor.value = '';
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
        // What was RECORDED, or blank. A method the picker does not offer
        // ('unknown' on an imported row) is shown as blank rather than silently
        // rewritten: the select cannot display it, and re-submitting it converted
        // the gift's method to 'other' on an edit that was about something else
        // (UpdateOfflineDonationRequest::prepareForValidation). Blank now means
        // "not stated", the key is omitted, and the stored value is untouched.
        payment_method: methods.includes(donation.payment_method) ? donation.payment_method : '',
        check_number: donation.check_number || '',
        fund_id: donation.fund_id || donation.fund?.id || '',
        donated_at: (donation.donated_at || donation.created_at || '').slice(0, 10),
        note: donation.note || '',
        // The gift's RECORDED designation, never re-derived from its fund — the
        // edit form must show what the ledger, the export and the donor's own
        // answer say, or a save would quietly rewrite it.
        is_zakat: !!donation.is_zakat,
    };
    zakatTouched.value = false;
    // Only a designation that is itself just the old fund's default may move when
    // the fund is changed out from under it. `donor` and `admin` are answers
    // somebody gave, and stay put. (is_zakat false always reads as inferred here,
    // matching the server: an explicit "not zakat" stores no source to tell it
    // apart from "never asked".)
    zakatFollowsFund.value = !donation.zakat_source || donation.zakat_source === 'fund_default';
    zakatPrefilledFor.value = offlineForm.value.fund_id;
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

/**
 * Pre-fill the zakat box from the chosen fund's type — and only pre-fill it.
 *
 * This is ZakatDesignation::fundDefault run on an INPUT, which is the one place
 * the fund is allowed anywhere near the designation: it stands in for an answer
 * until somebody gives one. It never touches a gift that already has an answer
 * behind it, and no displayed designation anywhere else on this screen is
 * computed this way (.claude/rules/zakat.md).
 */
watch(() => offlineForm.value.fund_id, (fundId) => {
    // Re-opening the modal reassigns the whole form; that is not a fund change.
    if (fundId === zakatPrefilledFor.value) return;
    zakatPrefilledFor.value = fundId;

    if (zakatTouched.value || !zakatFollowsFund.value) return;

    const fund = funds.value.find(f => f.id === Number(fundId));
    offlineForm.value.is_zakat = fund?.type === 'zakat';
});
const submitOffline = async () => {
    if (!offlineValid.value) return;
    const p = new URLSearchParams();
    p.append('amount', offlineForm.value.amount);
    // Only when a method was actually chosen. An empty one is only reachable on
    // the edit path (offlineValid demands one to record a gift), and omitting the
    // key is how the server is told to leave the recorded method alone — sending
    // the empty string would 422 the whole save on the `in:` rule.
    if (offlineForm.value.payment_method) p.append('payment_method', offlineForm.value.payment_method);
    if (offlineForm.value.payment_method === 'check') p.append('check_number', offlineForm.value.check_number ?? '');
    p.append('fund_id', String(offlineForm.value.fund_id));
    p.append('donated_at', offlineForm.value.donated_at);
    // THE DESIGNATION, and only when staff actually answered. An untouched box is
    // "nobody said": omitting the key lets ZakatDesignation record the fund's
    // default under `fund_default` rather than an admin attestation, and keeps an
    // edit from stamping SOURCE_ADMIN over a donor's own declaration.
    //
    // '1'/'0', never 'true'/'false'. This body is form-encoded and the server's
    // rule is `boolean`, which rejects those two strings — a 422 that would take
    // the whole gift down with it, amount included.
    if (zakatTouched.value) p.append('zakat', offlineForm.value.is_zakat ? '1' : '0');
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

// --- Zakat -------------------------------------------------------------------

/**
 * WHY a gift is zakat, not merely that it is.
 *
 * A treasurer auditing the restricted pot needs the giver's own declaration to
 * be distinguishable from an inference the platform made off the fund's type;
 * the three answers carry different weight if a designation is ever questioned.
 *
 * Read from the row and nothing else. A designation whose source is missing or
 * unrecognised is still a designation, so it reports a bare "Yes" — saying "No"
 * would understate money the org owes its recipients.
 */
const ZAKAT_REASONS: Record<string, string> = {
    donor: 'the giver said so',
    fund_default: "from the fund's type",
    admin: 'recorded by staff'
};

/** The details modal's answer: whether, and on whose word. */
const zakatReason = (donation: any): string => {
    if (!donation?.is_zakat) return 'No';
    const reason = ZAKAT_REASONS[donation.zakat_source];
    return reason ? `Yes — ${reason}` : 'Yes';
};

/** The same provenance as the ledger badge's tooltip, so a treasurer scanning
 *  rows does not have to open each gift to see whose word it rests on. */
const zakatBadgeTitle = (donation: any): string => {
    const reason = ZAKAT_REASONS[donation?.zakat_source];
    return reason ? `Zakat — ${reason}` : 'Zakat';
};

// --- Tax receipts ------------------------------------------------------------

const orgName = computed(() => masjidStore.masjid?.name || 'this masjid');

/**
 * Whether the "Issue receipt" affordance belongs on this gift at all. The three
 * refusals mirror the server's: a Stripe gift's receipt is the webhook's, an
 * unsucceeded gift has no cleared money behind it, and a fund the org set
 * non-receiptable issues none.
 *
 * `!== false` rather than a truthiness test: a row that arrived without its fund
 * relation must not lose the button on a flag nobody sent — the server refuses
 * with a named reason, which is a better outcome than a silently missing action.
 */
const canIssueReceipt = (donation: any): boolean =>
    donation.source === 'offline'
    && donation.status === 'succeeded'
    && donation.fund?.receiptable !== false;

/**
 * Issue the receipt, after saying plainly what that spends.
 *
 * A serial is irreversible and the four facts the document states are frozen
 * from that moment, so the treasurer is told BOTH before the click rather than
 * discovering the freeze the next time they try to fix a typo. The dialog does
 * not name the next number: the sequence is allocated under a lock and a
 * client-side guess would be wrong under concurrency.
 */
const issueReceipt = async (donation: any) => {
    const confirm = await Swal.fire({
        icon: 'warning',
        title: 'Issue the next receipt?',
        text: `This takes the next serial in ${orgName.value}'s gap-free receipt sequence and produces a tax `
            + `document for the donor. It cannot be undone, and the donor, fund, amount and date on this gift `
            + `are frozen once it is issued.`,
        showCancelButton: true,
        confirmButtonText: 'Yes, issue it',
        confirmButtonColor: '#2f9e57',
        cancelButtonText: 'Not yet'
    });
    if (!confirm.isConfirmed) return;

    receiptBusy.value.add(donation.id);
    try {
        // `created` is the server's 201-vs-200, and the two are different news on
        // the one screen where the serial sequence is what is being audited. A
        // colleague issuing from another tab (or this row being stale) means the
        // server returns the receipt that already existed at 200 — saying "issued"
        // there tells the treasurer a serial was consumed when none was.
        const { receipt, created } = await donationsStore.issueReceipt(donation.id);
        await refreshRow(donation.id);
        Swal.fire(created
            ? { icon: 'success', title: 'Receipt issued', text: `Receipt #${receipt.serial_number} issued.` }
            : {
                icon: 'info',
                title: 'Already receipted',
                text: `This gift already had receipt #${receipt.serial_number}, so no new serial was taken.`
            });
    } catch (e: any) {
        // Never retried automatically: a request that failed at the network layer
        // may still have committed a serial. The server is idempotent, so the
        // treasurer clicking again is safe — but that has to be their decision.
        // The server names which rule refused — a Stripe gift, an unsucceeded one,
        // a non-receiptable fund — and that sentence is the whole value of the
        // error. An axios error's own `message` is "Request failed with status
        // code 422", so it is used only when there was no response at all.
        const data = e?.response?.data;
        Swal.fire({
            icon: 'error',
            title: 'Not issued',
            text: data?.message || (e?.response ? '' : e?.message) || 'The receipt could not be issued.'
        });
    } finally {
        receiptBusy.value.delete(donation.id);
    }
};

/** Re-hand a donor the copy they lost. Issues nothing — the PDF is rendered from
 *  the receipt row that already exists. */
const downloadReceipt = async (donation: any) => {
    receiptBusy.value.add(donation.id);
    try {
        await donationsStore.downloadReceiptPdf(donation);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not download the receipt.' });
    } finally {
        receiptBusy.value.delete(donation.id);
    }
};

/**
 * Re-read one gift so BOTH places it can be on screen show the receipt that now
 * exists — the ledger row and the open details modal. They are separate objects
 * (the modal swaps the row for the fuller record from the show endpoint), so
 * patching only the one that was clicked leaves the other offering to issue a
 * receipt that has already been issued.
 *
 * Patched in place rather than reloading the ledger, which would throw the
 * treasurer back to page one; and re-read rather than guessed locally, because
 * the serial and the eligible amount are the server's to state.
 */
const refreshRow = async (donationId: number) => {
    try {
        const full = await donationsStore.fetchDonation(donationId);
        if (!full) return;

        const row = donations.value.find(d => d.id === donationId);
        if (row) Object.assign(row, full);

        if (selectedDonation.value?.id === donationId) selectedDonation.value = full;
    } catch (e) {
        // The receipt exists either way; a stale row beats a blanked one.
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
