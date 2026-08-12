<template>
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h6 class="mb-1">Registrations</h6>
                <p class="text-muted small mb-0">
                    Sign-ups arrive from the public form and are advanced by Stripe.
                    Nothing on this screen creates one.
                </p>
            </div>
            <!-- Two server-rendered views of the same filtered set. -->
            <div class="btn-group btn-group-sm" role="group">
                <button
                    type="button"
                    class="btn"
                    :class="view === 'registrations' ? 'btn-success' : 'btn-outline-secondary'"
                    @click="setView('registrations')"
                >
                    <i class="bi bi-receipt me-1"></i>By sign-up
                </button>
                <button
                    type="button"
                    class="btn"
                    :class="view === 'registrants' ? 'btn-success' : 'btn-outline-secondary'"
                    @click="setView('registrants')"
                >
                    <i class="bi bi-person-lines-fill me-1"></i>By person
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="row g-3 mb-3">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search</label>
                <input
                    type="text"
                    class="form-control"
                    placeholder="Payer name or email…"
                    v-model="searchQuery"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Seat</label>
                <select class="form-select" v-model="filters.status" @change="loadData(1)">
                    <option value="">Any</option>
                    <option v-for="status in statuses" :key="status" :value="status">
                        {{ registrationStatusLabel(status) }}
                    </option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Payment</label>
                <select class="form-select" v-model="filters.payment_status" @change="loadData(1)">
                    <option value="">Any</option>
                    <option v-for="status in paymentStatuses" :key="status" :value="status">
                        {{ paymentStatusLabel(status) }}
                    </option>
                </select>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <!-- Error -->
        <div v-else-if="loadError" class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            {{ loadError }}
            <button class="btn btn-sm btn-outline-danger ms-3" @click="loadData(currentPage)">Retry</button>
        </div>

        <!-- Empty: filters -->
        <div v-else-if="rows.length === 0 && filtersActive" class="text-center py-5 text-muted">
            <i class="bi bi-funnel fs-1 d-block mb-3"></i>
            <p class="mb-3">No registrations match this filter.</p>
            <button class="btn btn-sm btn-outline-secondary" @click="clearFilters">Clear filters</button>
        </div>

        <!-- Empty: nothing has arrived -->
        <div v-else-if="rows.length === 0" class="text-center py-5">
            <i class="bi bi-people fs-1 d-block mb-3 text-muted"></i>
            <h6 class="mb-2">Nobody has registered yet</h6>
            <!--
                This used to read "when somebody completes the sign-up form on your
                public site, their registration appears here". That sentence is how a
                registrar loses a term's enrolments: the only thing they can actually
                publish is the intake form as a page section, and submitting it creates
                a FormResponse — never a Registration. No seat is taken, no fee plan is
                applied, no charge is made, and this tab keeps saying nobody registered
                while the school believes it has enrolled a class. Say what is true.
            -->
            <p class="text-muted mb-0 mx-auto empty-copy">
                Registrations arrive through the registration API, and appear here with
                who is paying, which plan they chose, and what Stripe has confirmed.
            </p>
            <p class="text-muted small mb-0 mx-auto empty-copy mt-2">
                The public sign-up page is not live yet. Publishing this offering's
                sign-up form as a page section collects <em>form responses</em>, which
                do not take a seat or charge anyone — they will not show up here.
            </p>
        </div>

        <!-- By sign-up -->
        <div v-else-if="view === 'registrations'" class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Payer</th>
                        <th class="text-center">People</th>
                        <th>Plan</th>
                        <th class="text-end">Charged</th>
                        <th class="text-center">Seat</th>
                        <th class="text-center">Payment</th>
                        <th>Stripe</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="registration in registrationRows" :key="registration.id">
                        <td>
                            <router-link
                                class="fw-semibold text-decoration-none"
                                :to="detailRoute(registration.id)"
                            >
                                {{ contactName(registration.contact) }}
                            </router-link>
                            <div v-if="registration.contact?.email" class="text-muted small">
                                {{ registration.contact.email }}
                            </div>
                        </td>
                        <td class="text-center">{{ registration.registrants_count ?? '—' }}</td>
                        <td>
                            <div>{{ registration.fee_plan?.label || '—' }}</div>
                            <div class="text-muted small">
                                {{ registration.fee_plan ? feePlanKindLabel(registration.fee_plan.kind) : '' }}
                            </div>
                        </td>
                        <!--
                            `adjusted_total_minor` — the amount Stripe was actually asked
                            to charge, snapshotted when this person signed up, denominated
                            by their own fee plan. The label under it says what that
                            number MEANS for their plan kind, because it is a single
                            charge for a one-time plan, the whole commitment for an
                            installment plan, and one interval for a recurring one.

                            `list_total_minor` is shown only when aid moved the price, so
                            an admin can see both numbers the server holds. The reduction
                            itself is NOT subtracted here — each adjustment is listed with
                            its own amount on the registration's own page.
                        -->
                        <td class="text-end">
                            <span class="fw-semibold font-monospace">
                                {{ chargedText(registration) }}
                            </span>
                            <div class="text-muted small">{{ registrationChargeLabel(registration) }}</div>
                            <div
                                v-if="registration.list_total_minor !== registration.adjusted_total_minor"
                                class="text-muted small"
                            >
                                before aid:
                                <span class="text-decoration-line-through">
                                    {{ listTotalText(registration) }}
                                </span>
                            </div>
                        </td>
                        <td class="text-center">
                            <span
                                class="badge"
                                :class="registrationStatusBadge(registration.status)"
                                :title="registrationStatusHint(registration.status)"
                            >
                                {{ registrationStatusLabel(registration.status) }}
                            </span>
                        </td>
                        <td class="text-center">
                            <span
                                class="badge"
                                :class="paymentStatusBadge(registration.payment_status)"
                                :title="paymentStatusHint(registration.payment_status)"
                            >
                                {{ paymentStatusLabel(registration.payment_status) }}
                            </span>
                        </td>
                        <td class="small">
                            <div v-if="registration.stripe_subscription_id">
                                <i class="bi bi-arrow-repeat me-1"></i>Subscription
                            </div>
                            <div v-else-if="registration.stripe_checkout_session_id">
                                <i class="bi bi-credit-card me-1"></i>Checkout opened
                            </div>
                            <div v-else class="text-muted">No Stripe leg</div>
                            <div
                                v-if="registration.checkout_expires_at && registration.status === 'pending'"
                                class="text-muted"
                            >
                                holds until {{ formatDateTime(registration.checkout_expires_at) }}
                            </div>
                        </td>
                        <td class="text-end">
                            <router-link
                                class="btn btn-sm btn-outline-primary"
                                :to="detailRoute(registration.id)"
                                title="Open"
                            >
                                <i class="bi bi-box-arrow-in-right"></i>
                            </router-link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- By person -->
        <div v-else class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Contact</th>
                        <th>Plan</th>
                        <th class="text-center">Seat</th>
                        <th class="text-center">Payment</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="registrant in registrantRows" :key="registrant.id">
                        <td class="fw-semibold">{{ contactName(registrant.contact) }}</td>
                        <td class="small">
                            <div v-if="registrant.contact?.email">{{ registrant.contact.email }}</div>
                            <div v-if="registrant.contact?.phone" class="text-muted">{{ registrant.contact.phone }}</div>
                            <span v-if="!registrant.contact?.email && !registrant.contact?.phone" class="text-muted">—</span>
                        </td>
                        <td>{{ registrant.registration?.fee_plan?.label || '—' }}</td>
                        <td class="text-center">
                            <span
                                v-if="registrant.registration"
                                class="badge"
                                :class="registrationStatusBadge(registrant.registration.status)"
                            >
                                {{ registrationStatusLabel(registrant.registration.status) }}
                            </span>
                            <span v-else class="text-muted">—</span>
                        </td>
                        <td class="text-center">
                            <span
                                v-if="registrant.registration"
                                class="badge"
                                :class="paymentStatusBadge(registrant.registration.payment_status)"
                            >
                                {{ paymentStatusLabel(registrant.registration.payment_status) }}
                            </span>
                            <span v-else class="text-muted">—</span>
                        </td>
                        <td class="text-end">
                            <router-link
                                v-if="registrant.registration_id"
                                class="btn btn-sm btn-outline-primary"
                                :to="detailRoute(registrant.registration_id)"
                                title="Open the sign-up"
                            >
                                <i class="bi bi-box-arrow-in-right"></i>
                            </router-link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="text-muted small mb-0">
                One row per person. A guardian who registered three children is one
                sign-up and three people, and they share one seat.
            </p>
        </div>

        <div v-if="paginationOptions" class="d-flex justify-content-center mt-3">
            <Pagination :options="paginationOptions" @pageChange="pageChange" />
        </div>

        <!--
            Said once, at the bottom of the list, because the absence of the two
            buttons an operator expects is a design decision rather than an
            oversight.
        -->
        <div class="alert alert-light border small mt-4 mb-0">
            <i class="bi bi-shield-check me-1"></i>
            Payment states come from Stripe's webhooks, which are the record of what
            actually happened to the money. There is deliberately no "mark as paid"
            and no refund button here: refunds are made in your own Stripe dashboard,
            where your organization holds the funds.
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onBeforeMount, watch } from 'vue';
import Pagination from '@/components/partials/Pagination.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import {
    REGISTRATION_PAYMENT_STATUSES,
    REGISTRATION_STATUSES,
    Registrant,
    Registration,
    RegistrationFilters,
    RegistrationPaymentStatus,
    RegistrationStatus,
    RegistrationView
} from '@/core/types/data/masjid-related/Offering';
import { useOfferingsStore } from '@/stores/masjid/offeringsStore';
import { useOfferingDisplay } from '@/composables/useOfferingDisplay';
import { formatMinor } from '@/composables/useMinorUnits';
import { apiErrorText } from '@/core/services/ApiErrors';

/**
 * The roster of one offering, in whichever of the two server views the operator
 * asked for.
 *
 * READ-ONLY BY CONSTRUCTION. Registrations are created by the public endpoints
 * and advanced by signature-verified Stripe webhooks; the backend exposes no
 * store or update route for them at all. The three writes that DO exist (grant
 * aid, promote off the waitlist, cancel) each act on one registration and live
 * on that registration's own page, where there is room to say what they do.
 *
 * WHAT THIS TABLE DELIBERATELY DOES NOT SHOW: an amount-paid-to-date column. The
 * list endpoint does not serve the payment ledger, and adding up succeeded
 * charges in the browser would put a number on a money screen that no server
 * ever computed — one that would silently disagree with Stripe the moment a
 * refund or a partial capture happened outside this app. `payment_status` is the
 * server's own answer to "have they finished paying", and the per-charge ledger
 * is one click away on the registration page.
 */

const props = defineProps<{ offeringId: number | string }>();

// Stores
const offeringsStore = useOfferingsStore();

// Display helpers
const {
    feePlanKindLabel,
    registrationStatusLabel,
    registrationStatusBadge,
    registrationStatusHint,
    paymentStatusLabel,
    paymentStatusBadge,
    paymentStatusHint,
    registrationChargeLabel,
    formatDateTime,
    contactName
} = useOfferingDisplay();

// State
const loading = ref(false);
const loadError = ref('');
const searchQuery = ref('');
const view = ref<RegistrationView>('registrations');
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const filters = reactive<RegistrationFilters>({
    status: '',
    payment_status: '',
    search: ''
});

// Computed
const rows = computed<(Registration | Registrant)[]>(
    () => (offeringsStore.registrationsPaginated?.data as (Registration | Registrant)[]) || []
);

const registrationRows = computed<Registration[]>(() => rows.value as Registration[]);
const registrantRows = computed<Registrant[]>(() => rows.value as Registrant[]);

/** Both status vocabularies as the SERVER states them; the literals are cold-load fallbacks. */
const statuses = computed<RegistrationStatus[]>(
    () => offeringsStore.offeringsMeta?.registration_statuses ?? REGISTRATION_STATUSES
);

const paymentStatuses = computed<RegistrationPaymentStatus[]>(
    () => offeringsStore.offeringsMeta?.payment_statuses ?? REGISTRATION_PAYMENT_STATUSES
);

const currentPage = computed<number>(() => offeringsStore.registrationsPaginated?.current_page || 1);

const filtersActive = computed<boolean>(
    () => filters.status !== '' || filters.payment_status !== '' || filters.search !== ''
);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!offeringsStore.registrationsPaginated) return undefined;
    return {
        currentPage: offeringsStore.registrationsPaginated.current_page,
        itemsTotal: offeringsStore.registrationsPaginated.total,
        perPage: offeringsStore.registrationsPaginated.per_page
    };
});

// Lifecycle
onBeforeMount(async () => {
    await loadData();
});

// Debounced search
watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        filters.search = searchQuery.value.trim();
        await loadData(1);
    }, 500);
});

// Methods
const loadData = async (page: number = 1) => {
    loading.value = true;
    loadError.value = '';
    try {
        await offeringsStore.fetchRegistrations(props.offeringId, page, filters, view.value);
    } catch (error) {
        loadError.value = apiErrorText(error, 'Failed to load the registrations.');
    } finally {
        loading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    if (data.toPage === currentPage.value) return;
    await loadData(data.toPage);
};

const setView = async (next: RegistrationView) => {
    if (view.value === next) return;
    view.value = next;
    await loadData(1);
};

const clearFilters = async () => {
    filters.status = '';
    filters.payment_status = '';
    filters.search = '';
    searchQuery.value = '';
    await loadData(1);
};

const detailRoute = (registrationId: number) => ({
    name: 'masjid.offeringRegistrationDetail',
    params: { offeringId: props.offeringId, registrationId }
});

/**
 * The charged amount, denominated by the registration's OWN fee plan.
 *
 * Currency lives on the fee plan and nowhere else, so a registration whose plan
 * did not come back on the payload gets an em dash rather than a figure with a
 * guessed currency symbol in front of it.
 */
const chargedText = (registration: Registration): string =>
    registration.fee_plan
        ? formatMinor(registration.adjusted_total_minor, registration.fee_plan.currency)
        : '—';

const listTotalText = (registration: Registration): string =>
    registration.fee_plan
        ? formatMinor(registration.list_total_minor, registration.fee_plan.currency)
        : '—';
</script>

<style scoped>
.empty-copy {
    max-width: 34rem;
}
</style>
