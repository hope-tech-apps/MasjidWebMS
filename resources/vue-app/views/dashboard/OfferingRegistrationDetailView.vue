<template>
    <div>
        <PageDataContainer :title="pageTitle" :hideButton="true">
            <template #headerButtons>
                <router-link
                    class="btn btn-outline-secondary"
                    :to="{ name: 'masjid.offeringDetail', params: { offeringId } }"
                >
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to the offering
                </router-link>
            </template>

            <div class="container w-100">
                <!-- Bootstrapping -->
                <div v-if="bootstrapping" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- Not found -->
                <div v-else-if="notFound" class="text-center py-5 text-muted">
                    <i class="bi bi-question-circle fs-1 d-block mb-3"></i>
                    <p class="mb-3">That registration no longer exists.</p>
                    <router-link
                        class="btn btn-outline-primary btn-sm"
                        :to="{ name: 'masjid.offeringDetail', params: { offeringId } }"
                    >
                        Back to the offering
                    </router-link>
                </div>

                <!-- Load failed -->
                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    {{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="bootstrap">Retry</button>
                </div>

                <div v-else-if="registration">
                    <!--
                        THE TWO STATE MACHINES, side by side and labelled as two.
                        The seat and the money move independently on purpose: a
                        declined card moves the payment to "past due" and never
                        touches the seat, because nothing may un-enrol a child for a
                        failed payment.
                    -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6 col-lg-3">
                            <div class="panel h-100">
                                <div class="panel-title">Seat</div>
                                <span class="badge fs-6" :class="registrationStatusBadge(registration.status)">
                                    <i :class="`bi ${registrationStatusIcon(registration.status)} me-1`"></i>
                                    {{ registrationStatusLabel(registration.status) }}
                                </span>
                                <p class="text-muted small mb-0 mt-2">
                                    {{ registrationStatusHint(registration.status) }}
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-3">
                            <div class="panel h-100">
                                <div class="panel-title">Payment</div>
                                <span class="badge fs-6" :class="paymentStatusBadge(registration.payment_status)">
                                    {{ paymentStatusLabel(registration.payment_status) }}
                                </span>
                                <p class="text-muted small mb-0 mt-2">
                                    {{ paymentStatusHint(registration.payment_status) }}
                                </p>
                            </div>
                        </div>

                        <!--
                            `adjusted_total_minor` — the amount Stripe was asked to
                            charge, snapshotted at sign-up and denominated by this
                            registration's own fee plan. The caption says what the
                            number means for this plan kind rather than calling it
                            "total", which would be wrong for a recurring plan.
                        -->
                        <div class="col-md-6 col-lg-3">
                            <div class="panel h-100">
                                <div class="panel-title">{{ registrationChargeLabel(registration) }}</div>
                                <div class="display-6 fw-bold font-monospace">{{ chargedText }}</div>
                                <p v-if="hasAid" class="text-muted small mb-0 mt-2">
                                    Before aid:
                                    <span class="text-decoration-line-through">{{ listTotalText }}</span>
                                </p>
                                <p v-else class="text-muted small mb-0 mt-2">
                                    Quoted when this sign-up was made and never restated since.
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-3">
                            <div class="panel h-100">
                                <div class="panel-title">Plan</div>
                                <div class="fw-semibold">{{ registration.fee_plan?.label || '—' }}</div>
                                <p class="text-muted small mb-0 mt-2">
                                    <template v-if="registration.fee_plan">
                                        {{ feePlanKindLabel(registration.fee_plan.kind) }} —
                                        {{ formatMinor(registration.fee_plan.amount_minor, registration.fee_plan.currency) }}
                                        {{ describePlanTerms(registration.fee_plan) }}
                                    </template>
                                    <template v-else>
                                        This registration's plan was not returned with it.
                                    </template>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-lg-7">
                            <!-- Payment ledger -->
                            <div class="panel mb-4">
                                <div class="panel-title">Payments</div>

                                <div v-if="payments.length === 0" class="text-muted small">
                                    No charge has been recorded against this registration yet.
                                </div>

                                <div v-else class="table-responsive">
                                    <table class="table table-sm align-middle mb-2">
                                        <thead>
                                            <tr>
                                                <th>Paid</th>
                                                <th class="text-end">Amount</th>
                                                <th class="text-end">Stripe fee</th>
                                                <th class="text-end">Net to you</th>
                                                <th class="text-center">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!--
                                                One row per Stripe charge, exactly as the webhook
                                                recorded it. `stripe_fee_minor` and `net_minor` are
                                                null unless Stripe's balance transaction came with
                                                the event; null renders as an em dash rather than
                                                a zero, because unknown is not the same as free.
                                            -->
                                            <tr v-for="payment in payments" :key="payment.id">
                                                <td class="small">{{ formatDateTime(payment.paid_at) }}</td>
                                                <td class="text-end font-monospace">
                                                    {{ ledgerAmount(payment.amount_minor) }}
                                                </td>
                                                <td class="text-end font-monospace text-muted">
                                                    {{ ledgerAmountOrDash(payment.stripe_fee_minor) }}
                                                </td>
                                                <td class="text-end font-monospace">
                                                    {{ ledgerAmountOrDash(payment.net_minor) }}
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge" :class="ledgerStatusBadge(payment.status)">
                                                        {{ ledgerStatusLabel(payment.status) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!--
                                    Deliberately no "paid so far" total. The API serves the
                                    individual charges and the payment state, not a sum, and a
                                    total added up in the browser would look exactly as
                                    authoritative as one Stripe computed while being able to
                                    disagree with it.
                                -->
                                <p class="text-muted small mb-0">
                                    Each row is one charge as Stripe reported it. There is no
                                    running total here on purpose: what has settled is what the
                                    rows above say, and the payment state beside it is the
                                    server's own answer.
                                </p>
                            </div>

                            <!-- Adjustments -->
                            <div class="panel">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="panel-title">Financial aid &amp; discounts</div>
                                    <button
                                        v-if="canGrantAid"
                                        class="btn btn-sm btn-outline-success"
                                        @click="openAidModal"
                                    >
                                        <i class="bi bi-plus-lg me-1"></i>Grant
                                    </button>
                                </div>

                                <div v-if="adjustments.length === 0" class="text-muted small mb-2">
                                    None granted.
                                </div>

                                <ul v-else class="list-unstyled mb-2">
                                    <li
                                        v-for="adjustment in adjustments"
                                        :key="adjustment.id"
                                        class="border-bottom py-2"
                                    >
                                        <div class="d-flex justify-content-between">
                                            <span class="fw-semibold">
                                                {{ adjustmentKindLabel(adjustment.kind) }}
                                            </span>
                                            <!-- The reduction's own unsigned magnitude, as stored. -->
                                            <span class="font-monospace">
                                                −{{ ledgerAmount(adjustment.amount_minor) }}
                                            </span>
                                        </div>
                                        <div v-if="adjustment.reason" class="small">{{ adjustment.reason }}</div>
                                        <div class="text-muted small">
                                            {{ adjustment.granted_by?.name || 'A former administrator' }}
                                            · {{ formatDateTime(adjustment.created_at) }}
                                        </div>
                                    </li>
                                </ul>

                                <p v-if="!canGrantAid" class="text-muted small mb-0">
                                    <i class="bi bi-lock me-1"></i>
                                    Aid can only be granted BEFORE a payment page is opened, because
                                    this system never moves money after the fact. Once checkout has
                                    started, a reduction has to be handled as a refund in your own
                                    Stripe dashboard.
                                </p>
                                <p v-else class="text-muted small mb-0">
                                    A grant reduces what this family is charged and is applied before
                                    they reach the payment page. Reducing the amount to zero confirms
                                    the seat with no charge at all.
                                </p>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <!-- Who -->
                            <div class="panel mb-4">
                                <div class="panel-title">Who</div>
                                <dl class="row mb-0 small">
                                    <dt class="col-4 text-muted fw-normal">Payer</dt>
                                    <dd class="col-8 mb-1">
                                        {{ contactName(registration.contact) }}
                                        <div v-if="registration.contact?.email" class="text-muted">
                                            {{ registration.contact.email }}
                                        </div>
                                        <div v-if="registration.contact?.phone" class="text-muted">
                                            {{ registration.contact.phone }}
                                        </div>
                                    </dd>

                                    <dt class="col-4 text-muted fw-normal">Registered</dt>
                                    <dd class="col-8 mb-1">{{ formatDateTime(registration.created_at) }}</dd>

                                    <dt class="col-4 text-muted fw-normal">People</dt>
                                    <dd class="col-8 mb-0">
                                        <ul v-if="registrants.length" class="list-unstyled mb-0">
                                            <li v-for="registrant in registrants" :key="registrant.id">
                                                {{ contactName(registrant.contact) }}
                                            </li>
                                        </ul>
                                        <span v-else class="text-muted">—</span>
                                    </dd>
                                </dl>
                                <p class="text-muted small mb-0 mt-2">
                                    One sign-up takes one seat, however many people it is for.
                                </p>
                            </div>

                            <!-- Stripe state -->
                            <div class="panel mb-4">
                                <div class="panel-title">Stripe</div>
                                <dl class="row mb-0 small">
                                    <dt class="col-5 text-muted fw-normal">Checkout session</dt>
                                    <dd class="col-7 mb-1 text-break font-monospace">
                                        {{ registration.stripe_checkout_session_id || '—' }}
                                    </dd>

                                    <dt class="col-5 text-muted fw-normal">Subscription</dt>
                                    <dd class="col-7 mb-1 text-break font-monospace">
                                        {{ registration.stripe_subscription_id || '—' }}
                                    </dd>

                                    <dt class="col-5 text-muted fw-normal">Schedule</dt>
                                    <dd class="col-7 mb-1 text-break font-monospace">
                                        {{ registration.stripe_subscription_schedule_id || '—' }}
                                    </dd>

                                    <dt class="col-5 text-muted fw-normal">Checkout closes</dt>
                                    <dd class="col-7 mb-0">
                                        {{ registration.checkout_expires_at ? formatDateTime(registration.checkout_expires_at) : '—' }}
                                    </dd>
                                </dl>
                                <p class="text-muted small mb-0 mt-2">
                                    Your organization is the merchant of record: these charges are on
                                    your own Stripe account, and refunds are made there.
                                </p>
                            </div>

                            <!-- Actions -->
                            <div class="panel">
                                <div class="panel-title">Actions</div>

                                <div class="d-grid gap-2">
                                    <button
                                        v-if="registration.status === 'waitlisted'"
                                        class="btn btn-outline-primary"
                                        :disabled="acting"
                                        @click="promote"
                                    >
                                        <span v-if="acting" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                        <i v-else class="bi bi-arrow-up-circle me-1"></i>
                                        Give this sign-up a seat
                                    </button>

                                    <button
                                        v-if="registration.status !== 'cancelled'"
                                        class="btn btn-outline-danger"
                                        :disabled="acting"
                                        @click="cancel"
                                    >
                                        <span v-if="acting" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                        <i v-else class="bi bi-x-circle me-1"></i>
                                        Cancel this registration
                                    </button>

                                    <div v-if="registration.status === 'cancelled'" class="text-muted small">
                                        This registration is cancelled and its seat has been released.
                                    </div>
                                </div>

                                <p class="text-muted small mb-0 mt-3">
                                    Promoting re-checks capacity, so it can never oversell the
                                    offering. Cancelling stops any future billing and releases the
                                    seat — it does not refund anything that already settled, and it
                                    does not remove anyone from
                                    {{ groupsTerm.toLowerCase() }} they belong to.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </PageDataContainer>

        <!-- Grant aid -->
        <Teleport to="body">
            <div
                v-if="showAidModal"
                class="modal fade show d-block"
                tabindex="-1"
                style="background: rgba(0,0,0,0.5);"
                @click.self="closeAidModal"
            >
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-heart me-2"></i>Grant a reduction</h5>
                            <button type="button" class="btn-close" @click="closeAidModal"></button>
                        </div>
                        <form @submit.prevent="submitAid">
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Kind <span class="text-danger">*</span></label>
                                        <select class="form-select" v-model="aidForm.kind" required>
                                            <option v-for="kind in adjustmentKinds" :key="kind" :value="kind">
                                                {{ adjustmentKindLabel(kind) }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Reduce by <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text text-uppercase">{{ currencyCode }}</span>
                                            <input
                                                type="text"
                                                inputmode="decimal"
                                                class="form-control font-monospace"
                                                :class="{ 'is-invalid': aidAmountError !== '' }"
                                                v-model="aidAmountInput"
                                                placeholder="50.00"
                                            >
                                        </div>
                                        <div v-if="aidAmountError" class="text-danger small mt-1">
                                            {{ aidAmountError }}
                                        </div>
                                        <div v-else-if="aidAmountMinor !== null" class="form-text">
                                            Takes off {{ ledgerAmount(aidAmountMinor) }} — sent as
                                            {{ aidAmountMinor }} {{ minorUnitNoun }}.
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Reason</label>
                                        <textarea class="form-control" rows="2" v-model.trim="aidForm.reason"></textarea>
                                        <div class="form-text">
                                            Kept on the record with your name, as the reason this family was
                                            charged less.
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="alert alert-light border small mb-0">
                                            <i class="bi bi-info-circle me-1"></i>
                                            This is a REDUCTION, never a surcharge, and it is applied before
                                            the family reaches the payment page. The new amount is recomputed
                                            by the server — the figure shown afterwards is its answer, not
                                            this screen's arithmetic.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="closeAidModal" :disabled="savingAid">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-success" :disabled="savingAid || aidAmountMinor === null">
                                    <span v-if="savingAid" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    Grant
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
import { ref, reactive, computed, onBeforeMount, watch } from 'vue';
import { useRoute } from 'vue-router';
import { AxiosError } from 'axios';
import PageDataContainer from '@/components/PageDataContainer.vue';
import {
    ADJUSTMENT_KINDS,
    AdjustmentKind,
    Registrant,
    Registration,
    RegistrationAdjustment,
    RegistrationPaymentRow
} from '@/core/types/data/masjid-related/Offering';
import { useOfferingsStore } from '@/stores/masjid/offeringsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useOfferingDisplay } from '@/composables/useOfferingDisplay';
import { currencyExponent, formatMinor, formatMinorOrDash, parseMajorToMinor } from '@/composables/useMinorUnits';
import { apiErrorText } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * One registration: who it is for, what it was quoted, what Stripe has actually
 * collected, and the three things an administrator may do to it.
 *
 * WHAT IS NOT HERE, AND WHY:
 *
 *  - no refund. v1 refunds are made by the organization in its own Stripe
 *    dashboard, where it is the merchant of record and bears the money; the API
 *    has no refund route at all.
 *  - no "mark as paid". `payment_status` is advanced by signature-verified
 *    webhooks — the record of what happened to the money — and a button that set
 *    it here would put this screen out of step with the account holding funds.
 *  - no paid-to-date total. The API serves each charge; it does not serve a sum,
 *    and adding one up here would be inventing a figure on a money screen.
 *  - no editing of the quoted amount. It is a snapshot of what this family
 *    agreed to pay. Reducing it is a grant, recorded with a reason and a name.
 */

// Routing
const route = useRoute();

// Stores
const offeringsStore = useOfferingsStore();
const masjidStore = useMasjidStore();

// Display helpers
const {
    feePlanKindLabel,
    registrationStatusLabel,
    registrationStatusBadge,
    registrationStatusIcon,
    registrationStatusHint,
    paymentStatusLabel,
    paymentStatusBadge,
    paymentStatusHint,
    ledgerStatusLabel,
    ledgerStatusBadge,
    adjustmentKindLabel,
    describePlanTerms,
    registrationChargeLabel,
    formatDateTime,
    contactName
} = useOfferingDisplay();

// State
const offeringId = Number(route.params.offeringId);
const registrationId = Number(route.params.registrationId);
const registration = ref<Registration | null>(null);
const bootstrapping = ref(true);
const notFound = ref(false);
const loadError = ref('');
const acting = ref(false);
const showAidModal = ref(false);
const savingAid = ref(false);
/** What the admin typed, as text. Never parsed to a float, never sent. */
const aidAmountInput = ref('');

const aidForm = reactive<{ kind: AdjustmentKind; reason: string }>({
    kind: 'aid',
    reason: ''
});

// Computed
const pageTitle = computed<string>(() =>
    registration.value ? contactName(registration.value.contact) : 'Registration'
);

const groupsTerm = computed<string>(() => masjidStore.term('groups'));

const payments = computed<RegistrationPaymentRow[]>(() => registration.value?.payments ?? []);

const adjustments = computed<RegistrationAdjustment[]>(() => registration.value?.adjustments ?? []);

const registrants = computed<Registrant[]>(() => registration.value?.registrants ?? []);

const adjustmentKinds: AdjustmentKind[] = ADJUSTMENT_KINDS;

/**
 * The denomination of every figure on this screen. It lives on the fee plan and
 * nowhere else, so if the plan did not come back, nothing gets a symbol.
 */
const currency = computed<string | null>(() => registration.value?.fee_plan?.currency ?? null);

const currencyCode = computed<string>(() => (currency.value || '').toUpperCase() || '—');

const minorUnitNoun = computed<string>(() =>
    currencyExponent(currency.value) === 2 ? 'cents' : 'minor units'
);

const chargedText = computed<string>(() =>
    registration.value && currency.value
        ? formatMinor(registration.value.adjusted_total_minor, currency.value)
        : '—'
);

const listTotalText = computed<string>(() =>
    registration.value && currency.value
        ? formatMinor(registration.value.list_total_minor, currency.value)
        : '—'
);

/** Both server numbers differ ⇒ a reduction was granted. Not itself a number. */
const hasAid = computed<boolean>(
    () => !!registration.value && registration.value.list_total_minor !== registration.value.adjusted_total_minor
);

/**
 * Whether a grant would be ACCEPTED — mirrored from
 * `RegistrationService::guardPreCheckout()` so the button is not offered on a
 * registration the server would refuse.
 *
 * This is a courtesy, not the boundary: the service enforces it again behind
 * this screen, and a refusal that arrives anyway is surfaced verbatim.
 */
const canGrantAid = computed<boolean>(() => {
    const row = registration.value;
    if (!row) return false;
    if (row.status === 'cancelled') return false;
    if (row.stripe_checkout_session_id || row.stripe_subscription_id || row.stripe_subscription_schedule_id) {
        return false;
    }

    return row.payment_status === 'none' || row.payment_status === 'awaiting';
});

const parsedAid = computed(() => parseMajorToMinor(aidAmountInput.value, currency.value));

const aidAmountMinor = computed<number | null>(() => (parsedAid.value.ok ? parsedAid.value.minor : null));

const aidAmountError = computed<string>(() => {
    if (aidAmountInput.value.trim() === '') return '';

    return parsedAid.value.ok ? '' : parsedAid.value.reason;
});

// Lifecycle
onBeforeMount(async () => {
    await bootstrap();
});

// Methods
const bootstrap = async () => {
    bootstrapping.value = true;
    notFound.value = false;
    loadError.value = '';
    try {
        registration.value = await offeringsStore.fetchRegistration(offeringId, registrationId);
        if (!registration.value) {
            notFound.value = true;
        }
    } catch (error) {
        // A cross-tenant or deleted id is a 404 MISS by design.
        if ((error as AxiosError)?.response?.status === 404) {
            notFound.value = true;
        } else {
            loadError.value = apiErrorText(error, 'Failed to load this registration.');
        }
    } finally {
        bootstrapping.value = false;
    }
};

/** Ledger and adjustment figures, denominated by this registration's plan. */
const ledgerAmount = (amountMinor: number): string =>
    currency.value ? formatMinor(amountMinor, currency.value) : '—';

const ledgerAmountOrDash = (amountMinor: number | null | undefined): string =>
    currency.value ? formatMinorOrDash(amountMinor, currency.value) : '—';

const openAidModal = () => {
    aidForm.kind = 'aid';
    aidForm.reason = '';
    aidAmountInput.value = '';
    showAidModal.value = true;
};

const closeAidModal = () => {
    showAidModal.value = false;
};

const submitAid = async () => {
    if (aidAmountMinor.value === null) return;

    savingAid.value = true;
    try {
        await offeringsStore.grantAdjustment(offeringId, registrationId, {
            kind: aidForm.kind,
            amount_minor: aidAmountMinor.value,
            reason: aidForm.reason
        });
        showAidModal.value = false;
        // Re-read the whole registration rather than patching it locally. The
        // grant re-derives the charged amount SERVER-SIDE (and confirms the seat
        // outright when the reduction takes it to zero), so the screen has to
        // show the server's answer — not this screen's idea of what it became.
        await bootstrap();
        Swal.fire({ icon: 'success', title: 'Granted', timer: 2000, showConfirmButton: false });
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Not granted',
            text: apiErrorText(error, 'Failed to grant the reduction.')
        });
    } finally {
        savingAid.value = false;
    }
};

const promote = async () => {
    const result = await Swal.fire({
        title: 'Give this sign-up a seat?',
        text: 'They come off the waitlist. If the offering is full, this is refused rather than overselling it.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, promote'
    });

    if (!result.isConfirmed) return;

    acting.value = true;
    try {
        await offeringsStore.promoteRegistration(offeringId, registrationId);
        await bootstrap();
        Swal.fire({ icon: 'success', title: 'Promoted', timer: 2000, showConfirmButton: false });
    } catch (error) {
        // The service's own sentence — "this offering is full", "not on the
        // waitlist" — is shown verbatim rather than flattened into a generic
        // failure, because it says which of those two happened.
        Swal.fire({
            icon: 'error',
            title: 'Not promoted',
            text: apiErrorText(error, 'Failed to promote this registration.')
        });
    } finally {
        acting.value = false;
    }
};

const cancel = async () => {
    const hasSubscription = !!registration.value?.stripe_subscription_id;

    const result = await Swal.fire({
        title: 'Cancel this registration?',
        text: hasSubscription
            ? 'The seat is released and the Stripe subscription is cancelled, so no further payments are taken. Payments that already settled are not refunded — do that in your Stripe dashboard.'
            : 'The seat is released. Payments that already settled are not refunded — do that in your Stripe dashboard.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, cancel it'
    });

    if (!result.isConfirmed) return;

    acting.value = true;
    try {
        const outcome = await offeringsStore.cancelRegistration(offeringId, registrationId);
        await bootstrap();

        // "Cancelled locally" and "cancelled everywhere" are different outcomes:
        // a Stripe failure is logged and not fatal server-side, so an admin who
        // is told only "cancelled" could walk away from a subscription that is
        // still billing.
        if (hasSubscription && !outcome.subscriptionCancelled) {
            Swal.fire({
                icon: 'warning',
                title: 'Seat released — check Stripe',
                text: 'The seat was released, but the Stripe subscription could not be cancelled from here. Cancel it in your Stripe dashboard so no further payments are taken.'
            });
            return;
        }

        Swal.fire({ icon: 'success', title: 'Cancelled', timer: 2000, showConfirmButton: false });
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Not cancelled',
            text: apiErrorText(error, 'Failed to cancel this registration.')
        });
    } finally {
        acting.value = false;
    }
};

// Lock body scroll while the modal is open
watch(showAidModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<style scoped>
.panel {
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 1rem 1.25rem;
    background-color: #fff;
}

.panel-title {
    font-size: 0.8125rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6c757d;
    font-weight: 600;
    margin-bottom: 0.75rem;
}
</style>
