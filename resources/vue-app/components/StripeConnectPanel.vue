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
                <!-- 1 — No connected account yet -->
                <div v-if="!connectStatus.stripe_account_id">
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
import { useConnectStore, isForbidden, envelopeMessage } from '@/stores/masjid/connectStore';

/**
 * Stripe Connect onboarding panel — the admin-portal replacement for a
 * developer running the onboarding script on the server.
 *
 * Three states, decided ONLY by the raw /connect/status payload:
 *   1. no stripe_account_id            → not connected, offer "Connect with Stripe"
 *   2. account but !charges_enabled    → onboarding unfinished, offer "Resume onboarding"
 *   3. charges_enabled                 → connected; payouts_enabled may still lag
 *      (Stripe review), which is stated as normal rather than left to read as broken.
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

const connectStatus = computed(() => connectStore.connectStatus);

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
    await loadStatus();
    refreshing.value = false;
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
