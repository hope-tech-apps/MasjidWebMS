<template>
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h6 class="mb-1">Fee plans</h6>
                <p class="text-muted small mb-0">
                    How this offering may be paid for. Every amount is shown in the
                    currency the plan is stored in.
                </p>
            </div>
            <button class="btn btn-success btn-sm" @click="openCreateModal">
                <i class="bi bi-plus-lg me-1"></i>Add fee plan
            </button>
        </div>

        <!--
            Said once, at the top, because it is the single most surprising rule on
            this screen and the reason there is no edit button on any row.
        -->
        <div class="alert alert-light border small">
            <i class="bi bi-lock me-1"></i>
            <strong>A fee plan can never be edited.</strong>
            Registrations record the price they were quoted at the moment somebody
            signed up, so changing a live plan would restate what a family already
            agreed to pay. To change a price, deactivate the plan and add a new one:
            everyone already registered keeps the price they were given.
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
            <button class="btn btn-sm btn-outline-danger ms-3" @click="loadPlans">Retry</button>
        </div>

        <!-- Empty -->
        <div v-else-if="plans.length === 0" class="text-center py-5">
            <i class="bi bi-cash-coin fs-1 d-block mb-3 text-muted"></i>
            <h6 class="mb-2">No fee plans yet</h6>
            <p class="text-muted mb-0 mx-auto empty-copy">
                Until this offering has at least one active fee plan, nobody can
                register for it — there is nothing for the sign-up page to charge. Add
                a free plan if it costs nothing.
            </p>
        </div>

        <!-- Plans -->
        <div v-else class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Type</th>
                        <th class="text-end">Amount per charge</th>
                        <th>Terms</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="plan in plans" :key="plan.id" :class="{ 'text-muted': !plan.is_active }">
                        <td>
                            <div class="fw-semibold">{{ plan.label }}</div>
                            <div class="text-muted small">Added {{ formatDateTime(plan.created_at) }}</div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border">{{ feePlanKindLabel(plan.kind) }}</span>
                        </td>
                        <!--
                            The plan's own `amount_minor`, in its own currency, and the
                            currency code beside it so a mixed-currency organization is
                            never guessing which one a figure is in.

                            For an installment plan this is ONE payment. The full
                            commitment (amount x count) is a number the server computes
                            when somebody actually registers and does not serve here, so
                            this screen prints the two figures it was given side by side
                            rather than a third one of its own.
                        -->
                        <td class="text-end">
                            <span class="fw-semibold font-monospace">
                                {{ formatMinor(plan.amount_minor, plan.currency) }}
                            </span>
                            <div class="text-muted small text-uppercase">{{ plan.currency }}</div>
                        </td>
                        <td>{{ describePlanTerms(plan) }}</td>
                        <td class="text-center">
                            <span v-if="plan.is_active" class="badge bg-success-subtle text-success">Active</span>
                            <span v-else class="badge bg-secondary-subtle text-secondary">Deactivated</span>
                        </td>
                        <td class="text-end">
                            <button
                                v-if="plan.is_active"
                                class="btn btn-sm btn-outline-danger"
                                @click="confirmDeactivate(plan)"
                                title="Deactivate"
                            >
                                <i class="bi bi-slash-circle me-1"></i>Deactivate
                            </button>
                            <span v-else class="text-muted small">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="text-muted small mb-0">
                Deactivated plans are kept forever rather than deleted: registrations
                point at them and read the currency of their price through them.
            </p>
        </div>

        <!-- Add plan -->
        <Teleport to="body">
            <div
                v-if="showFormModal"
                class="modal fade show d-block"
                tabindex="-1"
                style="background: rgba(0,0,0,0.5);"
                @click.self="closeFormModal"
            >
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Add fee plan</h5>
                            <button type="button" class="btn-close" @click="closeFormModal"></button>
                        </div>
                        <form @submit.prevent="submitForm">
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Name <span class="text-danger">*</span></label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            v-model.trim="form.label"
                                            placeholder="Standard, Monthly — 9 payments, Sibling rate…"
                                            required
                                        >
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Type <span class="text-danger">*</span></label>
                                        <!-- The money vocabulary as the SERVER states it. -->
                                        <select class="form-select" v-model="form.kind" @change="onKindChange" required>
                                            <option v-for="kind in planKinds" :key="kind" :value="kind">
                                                {{ feePlanKindLabel(kind) }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Currency <span class="text-danger">*</span></label>
                                        <input
                                            type="text"
                                            class="form-control text-uppercase font-monospace"
                                            maxlength="3"
                                            v-model.trim="currencyInput"
                                            :disabled="isFree"
                                            required
                                        >
                                        <div class="form-text">Three-letter code, e.g. USD or CAD.</div>
                                    </div>

                                    <div v-if="!isFree" class="col-md-6">
                                        <label class="form-label">
                                            {{ isSubscription ? 'Amount per charge' : 'Amount' }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text text-uppercase">{{ form.currency }}</span>
                                            <input
                                                type="text"
                                                inputmode="decimal"
                                                class="form-control font-monospace"
                                                :class="{ 'is-invalid': amountError !== '' }"
                                                v-model="amountInput"
                                                placeholder="150.00"
                                            >
                                        </div>
                                        <!--
                                            The integer that is actually sent, shown before it is
                                            sent. All money in this system is an integer number of
                                            minor units; printing it here means an admin can see
                                            that "150" became 15000 and not 150 or 1500.
                                        -->
                                        <div v-if="amountError" class="text-danger small mt-1">{{ amountError }}</div>
                                        <div v-else-if="parsedAmountMinor !== null" class="form-text">
                                            Charges <strong>{{ formatMinor(parsedAmountMinor, form.currency) }}</strong>
                                            — sent as {{ parsedAmountMinor }}
                                            {{ minorUnitNoun }}.
                                        </div>
                                        <div v-else class="form-text">
                                            Type the amount the way you would say it, e.g. 150 or 150.00.
                                        </div>
                                    </div>

                                    <div v-if="isSubscription" class="col-md-6">
                                        <label class="form-label">Billed <span class="text-danger">*</span></label>
                                        <select class="form-select" v-model="form.billing_interval" required>
                                            <option value="" disabled>Choose…</option>
                                            <option v-for="interval in intervals" :key="interval" :value="interval">
                                                {{ intervalAdverb(interval) }}
                                            </option>
                                        </select>
                                    </div>

                                    <div v-if="isInstallment" class="col-md-6">
                                        <label class="form-label">Number of payments <span class="text-danger">*</span></label>
                                        <input
                                            type="number"
                                            min="2"
                                            step="1"
                                            class="form-control"
                                            v-model="installmentCountInput"
                                            required
                                        >
                                        <div class="form-text">
                                            At least two — a single payment is a one-time plan.
                                        </div>
                                    </div>

                                    <div v-if="isFree" class="col-12">
                                        <div class="alert alert-light border small mb-0">
                                            <i class="bi bi-info-circle me-1"></i>
                                            A free plan charges nothing and never opens a payment page.
                                            Registrations on it are confirmed straight away.
                                        </div>
                                    </div>

                                    <div v-if="isInstallment" class="col-12">
                                        <div class="alert alert-light border small mb-0">
                                            <i class="bi bi-info-circle me-1"></i>
                                            The amount above is charged
                                            {{ form.billing_interval ? intervalAdverb(form.billing_interval) : 'each period' }},
                                            {{ installmentCountInput ? `${installmentCountInput} times` : 'a set number of times' }}.
                                            Payment dates, retries after a declined card, and reminders
                                            are handled by Stripe.
                                        </div>
                                    </div>

                                    <div v-if="isRecurring" class="col-12">
                                        <div class="alert alert-light border small mb-0">
                                            <i class="bi bi-info-circle me-1"></i>
                                            A recurring plan is open-ended: it keeps billing until somebody
                                            cancels it. There is no final payment and therefore no total.
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="fee_plan_is_active" v-model="form.is_active">
                                            <label class="form-check-label" for="fee_plan_is_active">
                                                Offer this plan on the sign-up page
                                            </label>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="alert alert-warning small mb-0">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            Check the amount before saving. A fee plan cannot be edited
                                            afterwards — a wrong one has to be deactivated and replaced.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="closeFormModal" :disabled="saving">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-success" :disabled="saving || !canSubmit">
                                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    Add plan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeMount, watch } from 'vue';
import {
    BILLING_INTERVALS,
    BillingInterval,
    FEE_PLAN_KINDS,
    FeePlan,
    FeePlanKind,
    FeePlanPayload
} from '@/core/types/data/masjid-related/Offering';
import { useOfferingsStore } from '@/stores/masjid/offeringsStore';
import { useOfferingDisplay } from '@/composables/useOfferingDisplay';
import { currencyExponent, formatMinor, parseMajorToMinor } from '@/composables/useMinorUnits';
import { apiErrorText } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * The prices of one offering. This is the money-entry surface, so two things are
 * done deliberately rather than conveniently:
 *
 *  1. THE ADMIN TYPES MAJOR UNITS, THE APP SENDS MINOR UNITS, AND BOTH ARE ON
 *     SCREEN. What they type ("150.00") is converted once, by
 *     `parseMajorToMinor`, digit by digit off the decimal string — never
 *     `Number(x) * 100`, which is how 49.99 becomes 4998.999999999999. The
 *     integer that will actually be sent is printed under the field before they
 *     press save. An amount with more decimal places than the currency has is
 *     REFUSED rather than rounded.
 *  2. THERE IS NO EDIT. Fee plans are immutable once created because
 *     registrations snapshot their price from them; the only writes are create
 *     and deactivate, and the API refuses an edit with a 422. Saying so up front
 *     is cheaper than an admin discovering it after a semester has sold.
 */

const props = defineProps<{ offeringId: number | string }>();
const emit = defineEmits<{ changed: [] }>();

// Stores
const offeringsStore = useOfferingsStore();

// Display helpers
const { feePlanKindLabel, describePlanTerms, intervalAdverb, formatDateTime } = useOfferingDisplay();

// State
const loading = ref(false);
const saving = ref(false);
const loadError = ref('');
const showFormModal = ref(false);
/** What the admin typed, as text. Never parsed to a float, never sent. */
const amountInput = ref('');
const installmentCountInput = ref('');

const emptyForm = (): FeePlanPayload => ({
    kind: 'one_time',
    amount_minor: 0,
    currency: 'usd',
    billing_interval: '',
    installment_count: null,
    label: '',
    is_active: true
});
const form = ref<FeePlanPayload>(emptyForm());

// Computed
const plans = computed<FeePlan[]>(() => offeringsStore.feePlans || []);

/** The money vocabularies as the SERVER states them; the literals are cold-load fallbacks. */
const planKinds = computed<FeePlanKind[]>(
    () => offeringsStore.offeringsMeta?.fee_plan_kinds ?? FEE_PLAN_KINDS
);

const intervals = computed<BillingInterval[]>(
    () => offeringsStore.offeringsMeta?.billing_intervals ?? BILLING_INTERVALS
);

const isFree = computed<boolean>(() => form.value.kind === 'free');
const isInstallment = computed<boolean>(() => form.value.kind === 'installment');
const isRecurring = computed<boolean>(() => form.value.kind === 'recurring');
const isSubscription = computed<boolean>(() => isInstallment.value || isRecurring.value);

/** Bound separately so the stored value stays lowercase while the field shows caps. */
const currencyInput = computed<string>({
    get: () => form.value.currency.toUpperCase(),
    set: (value: string) => {
        form.value.currency = String(value ?? '').trim().toLowerCase().slice(0, 3);
    }
});

/** "cents" for a 2-decimal currency, the neutral phrase otherwise. */
const minorUnitNoun = computed<string>(() =>
    currencyExponent(form.value.currency) === 2 ? 'cents' : 'minor units'
);

/**
 * The parse of what was typed. `null` when the field is empty or unusable —
 * which is also what disables the save button, so an unparsed amount can never
 * be submitted as 0.
 */
const parsedAmount = computed(() => parseMajorToMinor(amountInput.value, form.value.currency));

const parsedAmountMinor = computed<number | null>(() =>
    parsedAmount.value.ok ? parsedAmount.value.minor : null
);

/** Silent while the field is untouched; the parser's own sentence otherwise. */
const amountError = computed<string>(() => {
    if (isFree.value) return '';
    if (amountInput.value.trim() === '') return '';

    return parsedAmount.value.ok ? '' : parsedAmount.value.reason;
});

const parsedInstallmentCount = computed<number | null>(() => {
    const raw = installmentCountInput.value.trim();
    if (raw === '') return null;
    const parsed = Number(raw);

    return Number.isInteger(parsed) && parsed >= 2 ? parsed : null;
});

const canSubmit = computed<boolean>(() => {
    if (!form.value.label) return false;
    if (form.value.currency.length !== 3) return false;

    if (isFree.value) return true;

    // A paid plan must charge MORE than zero: the server rejects a 0 amount on a
    // paid kind outright, because a $0 Stripe session is forbidden by design and
    // the free plan is what "costs nothing" means here.
    if (parsedAmountMinor.value === null || parsedAmountMinor.value <= 0) return false;

    if (isSubscription.value && !form.value.billing_interval) return false;
    if (isInstallment.value && parsedInstallmentCount.value === null) return false;

    return true;
});

// Lifecycle
onBeforeMount(async () => {
    await loadPlans();
});

// Methods
const loadPlans = async () => {
    loading.value = true;
    loadError.value = '';
    try {
        await offeringsStore.fetchFeePlans(props.offeringId);
    } catch (error) {
        loadError.value = apiErrorText(error, 'Failed to load the fee plans.');
    } finally {
        loading.value = false;
    }
};

const openCreateModal = () => {
    form.value = emptyForm();
    // Start on a kind the server actually offers.
    if (planKinds.value.length > 0 && !planKinds.value.includes(form.value.kind)) {
        form.value.kind = planKinds.value[0];
    }
    // Inherit the currency already in use on this offering rather than assuming
    // USD — it is a value the server gave us, and a second plan in a different
    // currency is almost always a typo.
    const inUse = plans.value.find((plan) => plan.is_active) ?? plans.value[0];
    if (inUse?.currency) form.value.currency = inUse.currency;

    amountInput.value = '';
    installmentCountInput.value = '';
    showFormModal.value = true;
};

const closeFormModal = () => {
    showFormModal.value = false;
};

/**
 * Clear the fields the new kind does not have.
 *
 * This is not cosmetic: StoreFeePlanRequest marks `billing_interval` and
 * `installment_count` PROHIBITED for the kinds that do not use them, so leaving
 * a stale value behind would turn a valid plan into a 422. A free plan's amount
 * is cleared for the same reason — the server requires exactly 0 there.
 */
const onKindChange = () => {
    if (!isSubscription.value) form.value.billing_interval = '';
    if (!isInstallment.value) installmentCountInput.value = '';
    if (isFree.value) amountInput.value = '';
};

const submitForm = async () => {
    if (!canSubmit.value) return;

    // Assemble the payload from the PARSED values only. The text the admin typed
    // never travels.
    const payload: FeePlanPayload = {
        kind: form.value.kind,
        amount_minor: isFree.value ? 0 : (parsedAmountMinor.value as number),
        currency: form.value.currency,
        billing_interval: isSubscription.value ? form.value.billing_interval : '',
        installment_count: isInstallment.value ? parsedInstallmentCount.value : null,
        label: form.value.label,
        is_active: form.value.is_active
    };

    saving.value = true;
    try {
        await offeringsStore.createFeePlan(props.offeringId, payload);
        showFormModal.value = false;
        await loadPlans();
        // The offering header shows its plans too.
        emit('changed');
        Swal.fire({ icon: 'success', title: 'Plan added', timer: 2000, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to add the plan.') });
    } finally {
        saving.value = false;
    }
};

/**
 * "Deactivate", never "delete", because that is exactly what the endpoint does
 * and why: the row has to survive so registrations that reference it keep
 * resolving — including the currency their price is denominated in.
 */
const confirmDeactivate = async (plan: FeePlan) => {
    const result = await Swal.fire({
        title: 'Deactivate this plan?',
        text: `"${plan.label}" will no longer be offered to new registrations. Everyone already registered keeps the price they were quoted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, deactivate'
    });

    if (!result.isConfirmed) return;

    try {
        await offeringsStore.deactivateFeePlan(props.offeringId, plan.id);
        await loadPlans();
        emit('changed');
        Swal.fire({ icon: 'success', title: 'Deactivated', timer: 2000, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to deactivate the plan.') });
    }
};

// Lock body scroll while the modal is open
watch(showFormModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<style scoped>
.empty-copy {
    max-width: 34rem;
}

.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
