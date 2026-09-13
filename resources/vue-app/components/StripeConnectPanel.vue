<template>
    <!--
        Whole panel disappears on a 403 (no `manage donations`, or the CRM gate
        is off): an admin who cannot act on the Stripe connection should not be
        shown a broken card about it.
    -->
    <section v-if="!forbidden" class="mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <h6 class="text-muted text-uppercase small mb-0">Payments — Stripe Connect</h6>
            <button
                v-if="!checking"
                class="btn btn-outline-secondary btn-sm"
                :disabled="refreshing"
                @click="refresh"
                title="Re-check the connection with Stripe"
            >
                <span v-if="refreshing" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="bi bi-arrow-clockwise me-1"></i>
                Refresh status
            </button>
        </div>

        <div class="border rounded p-3">
            <!-- First check: nothing to claim about the connection yet. -->
            <div v-if="checking" class="text-muted small">
                <span class="spinner-border spinner-border-sm me-2"></span>
                Checking the Stripe connection…
            </div>

            <!-- The check itself failed (non-403) — say so, offer a retry. -->
            <div v-else-if="statusError" class="small">
                <p class="text-danger mb-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    The Stripe connection could not be checked: {{ statusError }}
                </p>
                <button class="btn btn-outline-primary btn-sm" :disabled="refreshing" @click="refresh">
                    Try again
                </button>
            </div>

            <template v-else-if="connectStatus">
                <!--
                    0 — Form card payments go through another organisation's account
                    (DECISIONS.md 2026-09-15). Onboarding is refused while this is set, so
                    no Connect or Resume button is offered, and the holder's account id is
                    never on the wire.
                -->
                <div v-if="formsCardVia">
                    <p class="mb-2 fw-semibold">
                        Card payments for forms go through {{ formsCardHolderName }}
                    </p>
                    <div class="d-flex gap-2 flex-wrap mb-2">
                        <span v-if="formsCardVia.ready" class="badge bg-success-subtle text-success">
                            <i class="bi bi-check-circle me-1"></i>Ready for card payments
                        </span>
                        <span v-else class="badge bg-warning-subtle text-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>Card payments refused right now
                        </span>
                    </div>
                    <p class="text-muted small mb-0">
                        Manara has set this organisation up to take card payments on its forms through
                        {{ formsCardHolderName }}'s Stripe account. The money lands in that account,
                        families' card statements show {{ formsCardHolderName }}, and refunds and
                        disputes are handled in {{ formsCardHolderName }}'s Stripe dashboard. Donations
                        and other payments are not taken by card for this organisation, so there is nothing
                        to connect here.
                    </p>
                    <p v-if="!formsCardVia.ready" class="small text-danger mb-0 mt-2">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Forms cannot take card payments right now<template v-if="formsCardProblem">, because {{ formsCardProblem }}</template>.
                        Families can still pay the office where a form offers it. Ask
                        {{ formsCardHolderName }} or your Manara contact to fix it.
                    </p>
                </div>

                <!-- 1 — No connected account yet -->
                <div v-else-if="!connectStatus.stripe_account_id">
                    <p class="mb-1 fw-semibold">Online giving is not set up</p>
                    <p class="text-muted small mb-3">
                        Connect a Stripe account so donors can give by card. Stripe hosts the
                        payment form and the money settles in your own Stripe account — the
                        setup takes a few minutes and Stripe walks you through it.
                    </p>
                    <button class="btn btn-primary" :disabled="startingOnboarding" @click="beginOnboarding">
                        <span v-if="startingOnboarding" class="spinner-border spinner-border-sm me-1"></span>
                        Connect with Stripe
                    </button>
                </div>

                <!-- 2 — Account exists but cannot charge yet: onboarding unfinished -->
                <div v-else-if="!connectStatus.charges_enabled">
                    <p class="mb-1 fw-semibold">
                        <span class="badge bg-warning-subtle text-warning me-2">Onboarding incomplete</span>
                        Stripe setup is unfinished
                    </p>
                    <p class="text-muted small mb-3">
                        A Stripe account exists, but Stripe still needs details before it can
                        take donations. Resuming opens a fresh Stripe form where you left off.
                    </p>
                    <button class="btn btn-primary" :disabled="startingOnboarding" @click="beginOnboarding">
                        <span v-if="startingOnboarding" class="spinner-border spinner-border-sm me-1"></span>
                        Resume onboarding
                    </button>
                </div>

                <!-- 3 — Connected (payouts may still lag charges during Stripe review) -->
                <div v-else>
                    <p class="mb-2 fw-semibold">Connected to Stripe</p>
                    <div class="d-flex gap-2 flex-wrap mb-2">
                        <span class="badge bg-success-subtle text-success">
                            <i class="bi bi-check-circle me-1"></i>Charges enabled
                        </span>
                        <span
                            v-if="connectStatus.payouts_enabled"
                            class="badge bg-success-subtle text-success"
                        >
                            <i class="bi bi-check-circle me-1"></i>Payouts enabled
                        </span>
                        <span v-else class="badge bg-warning-subtle text-warning">
                            <i class="bi bi-hourglass-split me-1"></i>Payouts pending
                        </span>
                    </div>
                    <p v-if="!connectStatus.payouts_enabled" class="text-muted small mb-0">
                        Donations are being accepted, but Stripe has not enabled payouts yet —
                        this is normal while Stripe reviews a new account, and payouts usually
                        follow within a few days. The money collected is held safely in the
                        Stripe balance until then. Use “Refresh status” to check again.
                    </p>
                    <p v-else class="text-muted small mb-0">
                        Donations are being accepted and Stripe is paying the balance out.
                    </p>
                </div>

                <!--
                    The holder's side: the organisations whose form card payments land in THIS
                    account. The holder can stop one here (a revoke only; setting a link stays
                    with a SuperAdmin).
                -->
                <div v-if="formsCardFor.length" class="border-top pt-3 mt-3">
                    <p class="mb-1 fw-semibold">Other organisations taking form card payments through this account</p>
                    <p class="text-muted small mb-2">
                        Card payments on these organisations' forms land in this organisation's Stripe
                        account. Everyone who can see that Stripe account sees those payments, including
                        the family's email address, and refunds and disputes for them are handled in its
                        Stripe dashboard.
                    </p>
                    <ul class="list-unstyled mb-0">
                        <li
                            v-for="org in formsCardFor"
                            :key="org.id"
                            class="d-flex align-items-center justify-content-between flex-wrap gap-2 py-1"
                        >
                            <span>{{ org.name }}</span>
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm"
                                :disabled="revokingId !== null"
                                :aria-label="`Stop taking card payments for ${org.name}'s forms`"
                                @click="revoke(org)"
                            >
                                <span v-if="revokingId === org.id" class="spinner-border spinner-border-sm me-1"></span>
                                Stop
                            </button>
                        </li>
                    </ul>
                </div>

                <p v-if="revokeNotice" class="small text-success mb-0 mt-3" role="status">
                    <i class="bi bi-check-circle me-1"></i>
                    {{ revokeNotice }}
                </p>

                <p v-if="revokeError" class="small text-danger mb-0 mt-3" role="alert">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    {{ revokeError }}
                </p>

                <!-- The Stripe tab was opened: tell the admin how the loop closes. -->
                <p v-if="onboardingLaunched" class="small text-info mb-0 mt-3">
                    <i class="bi bi-box-arrow-up-right me-1"></i>
                    Stripe opened in a new tab. Finish the form there, then come back here
                    and press “Refresh status”.
                </p>

                <p v-if="onboardingError" class="small text-danger mb-0 mt-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    {{ onboardingError }}
                </p>
            </template>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeMount, ref } from 'vue';
import Swal from 'sweetalert2';
import { useConnectStore, isForbidden, envelopeMessage } from '@/stores/masjid/connectStore';
import { FormsCardOrg, formsCardProblemText } from '@/core/types/data/masjid-related/StripeConnect';

/**
 * Stripe Connect onboarding panel — the admin-portal replacement for a
 * developer running the onboarding script on the server.
 *
 * States, decided ONLY by the raw /connect/status payload:
 *   0. forms_card_via set              → form card payments go through another organisation;
 *                                        no onboarding is offered (the server 409s it)
 *   1. no stripe_account_id            → not connected, offer "Connect with Stripe"
 *   2. account but !charges_enabled    → onboarding unfinished, offer "Resume onboarding"
 *   3. charges_enabled                 → connected; payouts_enabled may still lag
 *      (Stripe review), which is stated as normal rather than left to read as broken.
 * forms_card_for adds, under any of them, the organisations charging through this one.
 */

const connectStore = useConnectStore();

// State
const checking = ref(true);            // very first status check, nothing rendered yet
const refreshing = ref(false);         // subsequent re-checks, current state stays visible
const startingOnboarding = ref(false);
const forbidden = ref(false);          // 403 → the panel renders nothing at all
const statusError = ref('');
const onboardingError = ref('');
const onboardingLaunched = ref(false);
const revokingId = ref<number | null>(null);
const revokeError = ref('');
const revokeNotice = ref('');

const connectStatus = computed(() => connectStore.connectStatus);
const formsCardVia = computed(() => connectStatus.value?.forms_card_via ?? null);
const formsCardFor = computed<FormsCardOrg[]>(() =>
    Array.isArray(connectStatus.value?.forms_card_for) ? connectStatus.value!.forms_card_for! : []);
const formsCardProblem = computed(() => formsCardProblemText(formsCardVia.value?.problem));
/** The server sends a null name only when the holder row is gone entirely. */
const formsCardHolderName = computed(() => formsCardVia.value?.holder.name || 'another organisation');

// Lifecycle
onBeforeMount(async () => {
    await loadStatus();
    checking.value = false;
});

// Methods
const loadStatus = async (): Promise<void> => {
    statusError.value = '';
    try {
        await connectStore.fetchStatus();
    } catch (e) {
        if (isForbidden(e)) {
            forbidden.value = true;
        } else {
            statusError.value = envelopeMessage(e);
        }
    }
};

const refresh = async (): Promise<void> => {
    refreshing.value = true;
    // A refresh that lands answers the "come back and refresh" instruction, so
    // the instruction (and any stale onboarding error) leaves with it.
    onboardingLaunched.value = false;
    onboardingError.value = '';
    revokeError.value = '';
    revokeNotice.value = '';
    await loadStatus();
    refreshing.value = false;
};

/**
 * Stop another organisation's form card payments going through this account
 * (DELETE .../connect/forms-card-for/{child_id}). Confirmed first, because only a
 * SuperAdmin can set it up again. A refusal is shown in the server's own words and never
 * hides the panel: the admin could read the status, so a 403 here is a message.
 */
const revoke = async (org: FormsCardOrg): Promise<void> => {
    if (revokingId.value !== null) return;

    revokeError.value = '';
    revokeNotice.value = '';

    const confirmed = await Swal.fire({
        icon: 'warning',
        title: `Stop taking card payments for ${org.name}?`,
        text: `New card payments on ${org.name}'s forms will be refused, and families will be sent to pay `
            + `the office where a form offers it. Card payments already made stay in this Stripe account, `
            + `and a card payment page opened in the last half hour can still be paid and recorded. Only `
            + `your Manara contact can set this up again.`,
        showCancelButton: true,
        confirmButtonText: 'Stop card payments',
        cancelButtonText: 'Keep them'
    });

    if (!confirmed.isConfirmed) return;

    revokingId.value = org.id;
    try {
        await connectStore.revokeFormsCardFor(org.id);
        revokeNotice.value = `Stopped. ${org.name}'s forms no longer take card payments through this account.`;
    } catch (e) {
        revokeError.value = envelopeMessage(e, `Could not stop card payments for ${org.name}. Please try again.`);
    } finally {
        revokingId.value = null;
    }

    // Read the list back from the server rather than removing the row by hand, so what is
    // shown is what was saved. A failed re-read says so in the panel.
    await loadStatus();
};

/**
 * Start (or resume) onboarding.
 *
 * The tab is opened SYNCHRONOUSLY inside the click and pointed at Stripe when
 * the server answers: an Account Link expires in minutes, so it must never sit
 * behind a popup-blocker prompt or a copy-paste step. If the tab could not be
 * opened at all, a direct window.open is attempted as a fallback before giving
 * up with a message — a retry mints a fresh link, so nothing is lost.
 */
const beginOnboarding = async (): Promise<void> => {
    onboardingError.value = '';
    onboardingLaunched.value = false;

    const tab = window.open('', '_blank');

    startingOnboarding.value = true;
    try {
        const url = await connectStore.startOnboarding();

        if (tab && !tab.closed) {
            tab.location.href = url;
        } else if (!window.open(url, '_blank')) {
            onboardingError.value =
                'The browser blocked the Stripe tab. Allow pop-ups for this site and try again.';
            startingOnboarding.value = false;
            return;
        }

        onboardingLaunched.value = true;
    } catch (e) {
        // The blank tab has no purpose if there is no link to give it.
        tab?.close();

        if (isForbidden(e)) {
            forbidden.value = true;
        } else {
            onboardingError.value = envelopeMessage(e);
        }
    } finally {
        startingOnboarding.value = false;
    }
};
</script>
