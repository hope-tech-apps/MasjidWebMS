<template>
    <div class="card border-0 py-4 px-3 w-100">

        <!-- Title -->
        <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between gap-2">
            <div class="card-title fs-4 fw-semibold mb-0">
                Two-step sign-in
            </div>
            <span v-if="store.isEnabled" class="badge bg-success-subtle text-success">
                <i class="bi bi-shield-lock-fill me-1" aria-hidden="true"></i>
                On since {{ formatDate(store.enabledSince) }}
            </span>
            <span v-else class="badge bg-secondary-subtle text-secondary">
                <i class="bi bi-shield-slash me-1" aria-hidden="true"></i>
                Off
            </span>
        </div>

        <div class="card-body d-flex flex-column gap-3 w-100">

            <!-- Any server refusal, inline. Never a popup: the user is mid-code
                 and a modal they must dismiss costs them the 30 seconds the code
                 had left. -->
            <div v-if="store.errorMessage" class="alert alert-danger mb-0" role="alert">
                {{ store.errorMessage }}
            </div>

            <!-- ============ STATE: the codes, shown once ============ -->
            <div v-if="store.recoveryCodes.length" class="d-flex flex-column gap-3">
                <!--
                    "The only way back in" was true when this was written and is
                    no longer quite true: a platform administrator can clear a
                    second factor for somebody who has lost both
                    (TwoFactorController::resetForUser). Saying so here does not
                    soften the warning — it is a slow, recorded act that needs
                    another human to verify who you are, and the copy says that
                    plainly so nobody treats it as a reason to skip the printer.
                -->
                <div class="alert alert-warning mb-0" role="alert">
                    <strong>Save these now.</strong>
                    Each code works once, and they are the only way back in if you lose your phone.
                    They will not be shown again. If you lose both, a platform administrator has to
                    clear two-step sign-in for you by hand, after confirming who you are.
                </div>

                <div class="border rounded p-3 bg-light">
                    <div class="row row-cols-1 row-cols-sm-2 g-2 font-monospace">
                        <div v-for="code in store.recoveryCodes" :key="code" class="col">
                            {{ code }}
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyCodes()">
                        <i class="bi bi-clipboard me-1" aria-hidden="true"></i>
                        {{ copiedLabel }}
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="printCodes()">
                        <i class="bi bi-printer me-1" aria-hidden="true"></i> Print
                    </button>
                </div>

                <div class="form-check">
                    <input id="two_factor_codes_saved" v-model="codesSaved" class="form-check-input" type="checkbox" />
                    <label class="form-check-label" for="two_factor_codes_saved">
                        I have saved these codes
                    </label>
                </div>

                <div>
                    <button type="button" class="btn btn-success" :disabled="!codesSaved" @click="closeCodes()">
                        Done
                    </button>
                </div>
            </div>

            <!-- ============ STATE: enrolling (QR on screen) ============ -->
            <div v-else-if="store.qrCode" class="d-flex flex-column gap-3">
                <p class="mb-0">
                    Scan this with Google Authenticator, Microsoft Authenticator, 1Password,
                    or any authenticator app.
                </p>

                <div class="d-flex flex-column flex-sm-row align-items-start gap-3">
                    <img :src="store.qrCode" alt="Two-step sign-in QR code" width="200" height="200"
                        class="border rounded p-2 bg-white" />

                    <div class="d-flex flex-column gap-2">
                        <div class="text-muted small">Can't scan? Type this key into your app:</div>
                        <div class="font-monospace border rounded p-2 bg-light text-break">{{ store.secret }}</div>
                        <div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" @click="copySecret()">
                                <i class="bi bi-clipboard me-1" aria-hidden="true"></i> {{ copiedSecretLabel }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-column gap-2" style="max-width: 20rem;">
                    <label class="form-label mb-0" for="two_factor_confirm_code">
                        Enter the 6-digit code from your app
                    </label>
                    <input id="two_factor_confirm_code" v-model="confirmCode" class="dashboard-input font-monospace"
                        inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456"
                        @keyup.enter="onConfirm()" />
                </div>

                <div class="text-muted small">
                    Starting again gives you a new key and invalidates the code you just scanned,
                    so finish here if you can.
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <LoadingButton type="button" classes="btn-success" :is-loading="store.isLoading"
                        @click="onConfirm()">
                        Confirm
                    </LoadingButton>
                    <button type="button" class="btn btn-outline-secondary" :disabled="store.isLoading"
                        @click="store.reset()">
                        Cancel
                    </button>
                </div>
            </div>

            <!-- ============ STATE: on ============ -->
            <div v-else-if="store.isEnabled" class="d-flex flex-column gap-3">
                <p class="mb-0">
                    Signing in to this account asks for your password and then a 6-digit code
                    from your authenticator app.
                </p>

                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" @click="openPrompt('regenerate')">
                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
                        View / regenerate recovery codes
                    </button>
                    <button type="button" class="btn btn-outline-danger" @click="openPrompt('disable')">
                        <i class="bi bi-shield-slash me-1" aria-hidden="true"></i>
                        Turn off
                    </button>
                </div>

                <!-- Both destructive-ish actions need proof of possession, so
                     they share one prompt. -->
                <div v-if="prompt" class="border rounded p-3 d-flex flex-column gap-2" style="max-width: 26rem;">
                    <label class="form-label mb-0" for="two_factor_prompt_code">
                        {{ prompt === 'disable'
                            ? 'Enter a 6-digit code, or one of your recovery codes, to turn two-step sign-in off'
                            : 'Enter a 6-digit code from your app' }}
                    </label>
                    <input id="two_factor_prompt_code" v-model="promptCode" class="dashboard-input font-monospace"
                        inputmode="text" autocomplete="one-time-code" maxlength="32" placeholder="123456"
                        @keyup.enter="submitPrompt()" />
                    <p v-if="prompt === 'regenerate'" class="text-muted small mb-0">
                        This replaces your existing recovery codes. The old ones stop working.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <LoadingButton type="button"
                            :classes="prompt === 'disable' ? 'btn-danger' : 'btn-success'"
                            :is-loading="store.isLoading" @click="submitPrompt()">
                            {{ prompt === 'disable' ? 'Turn off two-step sign-in' : 'Generate new codes' }}
                        </LoadingButton>
                        <button type="button" class="btn btn-outline-secondary" :disabled="store.isLoading"
                            @click="closePrompt()">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>

            <!-- ============ STATE: off ============ -->
            <div v-else class="d-flex flex-column gap-3">
                <p class="mb-0">
                    Two-step sign-in is off. When it is on, signing in also asks for a 6-digit code
                    from an app on your phone.
                </p>
                <div>
                    <LoadingButton type="button" classes="btn-success" :is-loading="store.isLoading"
                        @click="store.enroll()">
                        Turn on two-step sign-in
                    </LoadingButton>
                </div>
            </div>

        </div>
    </div>
</template>

<script setup lang="ts">
import LoadingButton from '@/components/form/LoadingButton.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import { useTwoFactorStore } from '@/stores/twoFactorStore';
import { onBeforeUnmount, ref } from 'vue';

/**
 * Per-account two-step sign-in, on the profile screen.
 *
 * Four faces, in the order a person meets them: OFF -> enrolling (QR + key +
 * one code to prove the app works) -> the recovery codes, once -> ON.
 *
 * Two things this component is careful about, because they are the two ways a
 * screen like this hurts somebody:
 *
 * 1. The recovery codes are shown EXACTLY ONCE and live only in memory. There is
 *    no re-read call and no local copy — asking to see them again generates a
 *    new set and retires the old one. The "Done" button is disabled until the
 *    user ticks that they have saved them, because the codes disappear when this
 *    panel closes and there is no undo.
 * 2. Nothing here can leave an account half-protected. The secret is not live
 *    until a code is confirmed, so closing the browser mid-enrolment leaves
 *    sign-in exactly as it was; and the server refuses to re-enroll over a
 *    CONFIRMED enrollment, so this panel cannot be used to strip a second factor
 *    without proving possession of it.
 */

// Stores
const store = useTwoFactorStore();

// Custom constants
const confirmCode = ref<string>('');
const codesSaved = ref<boolean>(false);
const prompt = ref<'' | 'disable' | 'regenerate'>('');
const promptCode = ref<string>('');
const copiedLabel = ref<string>('Copy');
const copiedSecretLabel = ref<string>('Copy key');

// Lifecycle hooks
onBeforeUnmount(() => {
    // Leaving the screen takes the codes with it — they were only ever in
    // memory. Say nothing here; the warning beside them already did.
    store.reset();
});

// Functions
async function onConfirm(): Promise<void> {
    if (!confirmCode.value) return;

    const ok = await store.confirm(confirmCode.value);
    confirmCode.value = '';

    if (ok) {
        codesSaved.value = false;
    }
}

function openPrompt(which: 'disable' | 'regenerate'): void {
    prompt.value = which;
    promptCode.value = '';
    store.errorMessage = '';
}

function closePrompt(): void {
    prompt.value = '';
    promptCode.value = '';
}

async function submitPrompt(): Promise<void> {
    if (!promptCode.value) return;

    if (prompt.value === 'regenerate') {
        const ok = await store.regenerateRecoveryCodes(promptCode.value);
        promptCode.value = '';
        if (ok) {
            codesSaved.value = false;
            closePrompt();
        }
        return;
    }

    const confirmed = await QSwal.fire(
        'Turn off two-step sign-in?',
        'Your account will be protected by its password alone.',
        'question',
    );

    if (!confirmed.isConfirmed) return;

    const ok = await store.disable(promptCode.value);
    promptCode.value = '';

    if (ok) {
        closePrompt();
        MSwal.fire('Done', 'Two-step sign-in is off for this account.', 'success');
    }
}

function closeCodes(): void {
    store.recoveryCodes = [];
    codesSaved.value = false;
}

async function copyCodes(): Promise<void> {
    copiedLabel.value = await copy(store.recoveryCodes.join('\n')) ? 'Copied' : 'Press Ctrl+C';
}

async function copySecret(): Promise<void> {
    copiedSecretLabel.value = await copy(store.secret) ? 'Copied' : 'Press Ctrl+C';
}

/** Clipboard write, false when the browser refuses (insecure context, denied). */
async function copy(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        return false;
    }
}

/**
 * Print the codes.
 *
 * Written straight into a blank window rather than fetched from anywhere: these
 * strings must never travel through a URL, a query string or a download the
 * server can see. Nothing leaves the page.
 */
function printCodes(): void {
    const sheet = window.open('', '_blank', 'width=480,height=600');
    if (!sheet) return;

    const body = sheet.document.createElement('body');
    const heading = sheet.document.createElement('h3');
    heading.textContent = 'Two-step sign-in recovery codes';
    body.appendChild(heading);

    const note = sheet.document.createElement('p');
    note.textContent = 'Each code works once. Keep this somewhere only you can reach.';
    body.appendChild(note);

    const list = sheet.document.createElement('pre');
    list.style.fontSize = '16px';
    list.textContent = store.recoveryCodes.join('\n');
    body.appendChild(list);

    sheet.document.body.replaceWith(body);
    sheet.focus();
    sheet.print();
}

function formatDate(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    return isNaN(d.getTime())
        ? iso
        : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
</script>
