<template>
    <div class="staff-codes-panel">
        <p class="text-muted small mb-3">
            Each staff member gets their own secret code. On this form's public page, the code lets
            them record a walk-up entry paid in cash, and that cash is counted against them. A code
            belongs to the first phone that uses it.
        </p>

        <div v-if="loading" class="text-center py-4" role="status">
            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
            Loading staff codes…
        </div>

        <div v-else-if="loadError" class="alert alert-danger d-flex flex-wrap align-items-center gap-2" role="alert">
            <span>{{ loadError }}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="load()">Try again</button>
        </div>

        <template v-else-if="meta">
            <div v-if="!meta.staff_codes_enabled" class="alert alert-warning py-2 small" role="status">
                Staff codes are switched off on this form, or it has no price, so a code records nothing
                yet. Switch on "Staff cash codes" in the form's Payment settings to use them.
            </div>

            <p class="small mb-3">
                <i class="bi bi-clock me-1" aria-hidden="true"></i>
                Times are in <strong>{{ meta.timezone }}</strong> time.
                <template v-if="meta.timezone_assumed">
                    This organisation has no timezone set, so New York time is assumed.
                </template>
            </p>

            <!-- ------------------------------------------------------ add a code -->
            <form class="card card-body mb-3" novalidate @submit.prevent="issue">
                <h6 class="mb-2">Add a code</h6>

                <div class="row g-2 align-items-start">
                    <div class="col-md-5">
                        <label class="form-label small mb-1" :for="`${uid}-holder`">Staff member's name</label>
                        <input
                            :id="`${uid}-holder`"
                            ref="holderInput"
                            v-model="newHolder"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': !!issueErrors.holder_name }"
                            maxlength="120"
                            autocomplete="off"
                            required
                            :aria-invalid="issueErrors.holder_name ? 'true' : undefined"
                            :aria-describedby="issueErrors.holder_name ? `${uid}-holder-error` : undefined"
                            @input="issueErrors.holder_name = ''"
                        />
                        <div v-if="issueErrors.holder_name" :id="`${uid}-holder-error`" class="invalid-feedback d-block">
                            {{ issueErrors.holder_name }}
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small mb-1" :for="`${uid}-expiry`">
                            {{ meta.default_expires_at ? 'Last day it works (optional)' : 'Last day it works' }}
                        </label>
                        <input
                            :id="`${uid}-expiry`"
                            v-model="newExpiryDate"
                            type="date"
                            class="form-control"
                            :class="{ 'is-invalid': !!issueErrors.expires_at }"
                            :min="todayOnMasjidClock"
                            :required="!meta.default_expires_at"
                            :aria-invalid="issueErrors.expires_at ? 'true' : undefined"
                            :aria-describedby="`${uid}-expiry-help` + (issueErrors.expires_at ? ` ${uid}-expiry-error` : '')"
                            @input="issueErrors.expires_at = ''"
                        />
                        <div v-if="issueErrors.expires_at" :id="`${uid}-expiry-error`" class="invalid-feedback d-block">
                            {{ issueErrors.expires_at }}
                        </div>
                    </div>

                    <div class="col-md-3">
                        <span class="form-label small mb-1 d-none d-md-block" aria-hidden="true">&nbsp;</span>
                        <button type="submit" class="btn btn-primary w-100" :disabled="issuing">
                            <span v-if="issuing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                            Add code
                        </button>
                    </div>
                </div>

                <p :id="`${uid}-expiry-help`" class="small text-muted mb-0 mt-2">
                    <template v-if="meta.default_expires_at">
                        Left blank, a code stops working at {{ formatInstant(meta.default_expires_at) }}, the end of
                        this form's event day ({{ formatDay(meta.event_date) }}).
                    </template>
                    <template v-else>
                        This form has no event date, so choose the last day each code works, or set the event
                        date in the form's Payment settings and save it.
                    </template>
                    A chosen day ends at midnight, {{ meta.timezone }} time.
                </p>
            </form>

            <!-- --------------------------------------------------------- the codes -->
            <div v-if="!codes.length" class="text-center text-muted py-3">No staff codes yet.</div>

            <div v-else class="table-responsive">
                <table class="table table-sm align-middle">
                    <caption class="visually-hidden">Staff codes on this form</caption>
                    <thead>
                        <tr>
                            <th scope="col">Holder</th>
                            <th scope="col">Expires</th>
                            <th scope="col" class="text-end">Uses</th>
                            <th scope="col">Last used</th>
                            <th scope="col">Phone</th>
                            <th scope="col" class="text-end">Cash</th>
                            <th scope="col">State</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="code in codes" :key="code.id" :class="{ 'text-muted': !code.usable }">
                            <td>
                                <strong>{{ code.holder_name }}</strong>
                                <div class="small text-muted">Code ends …{{ code.code_hint }}</div>
                            </td>
                            <td class="small">
                                {{ formatInstant(code.expires_at) }}
                            </td>
                            <td class="text-end">{{ code.use_count }}</td>
                            <td class="small">{{ code.last_used_at ? formatInstant(code.last_used_at) : 'Never' }}</td>
                            <td class="small">
                                <template v-if="code.device_bound">
                                    {{ code.bound_device_hint ? `Phone …${code.bound_device_hint}` : 'A phone' }}
                                    <span v-if="code.bound_at" class="text-muted">since {{ formatInstant(code.bound_at) }}</span>
                                </template>
                                <span v-else-if="code.binding_count > 0" class="text-muted">
                                    No phone now: the next phone to enter it claims it
                                </span>
                                <span v-else class="text-muted">Not claimed yet</span>
                                <!-- binding_count counts CLAIMS (FormStaffCode::bindToDevice()), never
                                     releases. More than one means the code has changed hands. -->
                                <div v-if="code.binding_count > 1" class="fw-semibold">
                                    <i class="bi bi-phone me-1" aria-hidden="true"></i>Used from {{ code.binding_count }} phones so far
                                </div>
                                <div v-if="code.binding_released_at" class="text-muted">
                                    Last reset {{ formatInstant(code.binding_released_at) }}<template v-if="code.binding_released_by?.name">
                                        by {{ code.binding_released_by.name }}</template>
                                </div>
                            </td>
                            <td class="text-end small">
                                <strong>{{ money(code.cash_minor) }}</strong>
                                <div class="text-muted">{{ code.submissions }} {{ code.submissions === 1 ? 'entry' : 'entries' }}</div>
                                <div v-if="code.cancelled_submissions" class="text-muted">
                                    plus {{ money(code.cancelled_cash_minor) }} cancelled ({{ code.cancelled_submissions }})
                                </div>
                            </td>
                            <td class="small">
                                <template v-if="code.revoked_at">
                                    <span class="badge bg-secondary">Revoked</span>
                                    <div class="text-muted">
                                        {{ formatInstant(code.revoked_at) }}<template v-if="code.revoked_by?.name"> by {{ code.revoked_by.name }}</template>
                                    </div>
                                </template>
                                <span v-else-if="code.expired" class="badge bg-warning text-dark">Expired</span>
                                <span v-else class="badge bg-success">Active</span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                    <button
                                        v-if="code.device_bound && !code.revoked_at"
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        :disabled="busyId === code.id"
                                        :aria-label="`Reset the phone for ${code.holder_name}'s code`"
                                        @click="resetDevice(code)"
                                    >
                                        Reset phone
                                    </button>
                                    <button
                                        v-if="!code.revoked_at"
                                        type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        :disabled="busyId === code.id"
                                        :aria-label="`Revoke ${code.holder_name}'s code`"
                                        @click="revoke(code)"
                                    >
                                        Revoke
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ------------------------------------------------------ lockouts -->
            <div class="border-top pt-3 mt-2">
                <h6 class="mb-1">Wrong-code lockouts</h6>
                <p class="small text-muted mb-2">
                    After too many wrong codes, a phone is locked out for a while, and so is the whole form if
                    many phones get it wrong. Clearing lets every phone on this form try again at once.
                </p>
                <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="clearing" @click="clearLockout">
                    <span v-if="clearing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    Clear lockouts
                </button>
            </div>
        </template>

        <!-- ------------------------------------------- the plaintext, shown once -->
        <Teleport to="body">
            <!-- Tab stays inside it, and Escape does nothing: the code is gone once this
                 closes, so only "Done" closes it. -->
            <div
                v-if="issued"
                ref="revealRoot"
                class="modal d-block staff-code-reveal"
                role="alertdialog"
                aria-modal="true"
                :aria-labelledby="`${uid}-reveal-title`"
                :aria-describedby="`${uid}-reveal-warning`"
                tabindex="-1"
                @keydown="onRevealKeydown"
            >
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 :id="`${uid}-reveal-title`" class="modal-title">
                                <i class="bi bi-key me-2" aria-hidden="true"></i>Code for {{ issued.holder_name }}
                            </h5>
                        </div>
                        <div class="modal-body">
                            <div :id="`${uid}-reveal-warning`" class="alert alert-warning" role="alert">
                                <strong>This code will not be shown again.</strong>
                                Copy it now and give it only to {{ issued.holder_name }}. If it is lost, revoke it
                                and add a new one.
                            </div>

                            <label class="form-label small text-muted mb-1" :for="`${uid}-plain`">Staff code</label>
                            <input
                                :id="`${uid}-plain`"
                                class="form-control form-control-lg font-monospace text-center staff-code-plain"
                                :value="issued.code"
                                readonly
                                @focus="($event.target as HTMLInputElement).select()"
                            />

                            <p class="small text-muted mt-2 mb-0">
                                Works until {{ formatInstant(issued.expires_at) }}. It belongs to the first phone
                                that enters it.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <span class="me-auto small" :class="copyState === 'failed' ? 'text-danger' : 'text-success'" role="status" aria-live="polite">
                                {{ copyState === 'copied' ? 'Copied.' : copyState === 'failed' ? 'Could not copy. Select the code and copy it by hand.' : '' }}
                            </span>
                            <button ref="copyButton" type="button" class="btn btn-outline-primary" @click="copyCode">
                                <i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copy code
                            </button>
                            <button type="button" class="btn btn-primary" @click="dismissIssued">
                                Done, I have copied it
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import Swal from 'sweetalert2';
import { useFormsStore } from '@/stores/masjid/formsStore';
import {
    FormStaffCode,
    FormStaffCodeIssued,
    FormStaffCodesMeta,
    formatMinorAmount
} from '@/core/types/data/masjid-related/Form';
import { serverFieldErrors, serverMessage } from '@/core/helpers/serverMessage';
import { trapTab } from '@/core/helpers/focusTrap';

/**
 * The staff codes on one form (FormStaffCodesController): list, add, revoke, reset a
 * code's phone, and clear the wrong-code lockouts.
 *
 * The plaintext code is in the add answer and nowhere else, so it is shown ONCE, in its
 * own dialog that closes only on "Done", and is dropped from memory when it does. The
 * digest and the whole bound-phone id never reach the browser.
 *
 * Every instant is stated on the masjid's clock with its timezone, because a code's end
 * is an instant somebody at the gate will hit: the server answers in the masjid's zone
 * (meta.timezone) and says when that zone was assumed.
 */
const props = defineProps<{
    formId: number;
}>();

const emit = defineEmits<{
    /** A code was added, revoked or released: a parent's list of holders may be stale. */
    changed: [];
    /**
     * True while the shown-once code is on screen. A dialog hosting this panel must not
     * close then (Escape, its Close buttons, its backdrop): closing unmounts the panel, and
     * the plaintext with it, before anyone has copied it.
     */
    revealing: [value: boolean];
}>();

const formsStore = useFormsStore();

// Unique per mounted panel, so label/for pairs never collide with another panel's.
const uid = `staff-codes-${Math.random().toString(36).slice(2, 8)}`;

const loading = ref(false);
const loadError = ref('');
const codes = ref<FormStaffCode[]>([]);
const meta = ref<FormStaffCodesMeta | null>(null);

const newHolder = ref('');
const newExpiryDate = ref('');
const issuing = ref(false);
const issueErrors = ref<{ holder_name: string; expires_at: string }>({ holder_name: '', expires_at: '' });

const issued = ref<FormStaffCodeIssued | null>(null);
const copyState = ref<'' | 'copied' | 'failed'>('');

const busyId = ref<number | null>(null);
const clearing = ref(false);

const holderInput = ref<HTMLInputElement | null>(null);
const copyButton = ref<HTMLButtonElement | null>(null);
const revealRoot = ref<HTMLElement | null>(null);

watch(issued, value => emit('revealing', value !== null));

/** "YYYY-MM-DD" today on the masjid's clock: the earliest day a code may end on. */
const todayOnMasjidClock = computed(() => {
    try {
        // en-CA formats a date as YYYY-MM-DD.
        return new Intl.DateTimeFormat('en-CA', { timeZone: meta.value?.timezone || undefined }).format(new Date());
    } catch (e) {
        return new Date().toISOString().slice(0, 10);
    }
});

onMounted(() => load());

/**
 * Read the list. `quiet` keeps the current table on screen while it refreshes, so an
 * action does not blank the panel.
 */
const load = async (quiet = false) => {
    if (!quiet) loading.value = true;
    loadError.value = '';

    try {
        const answer = await formsStore.fetchStaffCodes(props.formId);
        codes.value = answer.codes;
        meta.value = answer.meta;
    } catch (error: any) {
        if (!quiet) {
            loadError.value = serverMessage(error, 'Could not load the staff codes.');
        } else {
            toast('error', serverMessage(error, 'Could not refresh the staff codes. Reload to see the latest.'));
        }
    } finally {
        loading.value = false;
    }
};

const issue = async () => {
    issueErrors.value = { holder_name: '', expires_at: '' };

    const holder = newHolder.value.trim();

    if (!holder) {
        issueErrors.value.holder_name = "Enter the staff member's name.";
    }

    if (!meta.value?.default_expires_at && !newExpiryDate.value) {
        issueErrors.value.expires_at = 'Choose the last day this code works.';
    }

    if (issueErrors.value.holder_name || issueErrors.value.expires_at) return;

    issuing.value = true;

    try {
        const answer = await formsStore.issueStaffCode(props.formId, holder, newExpiryDate.value || null);

        issued.value = answer.code;
        copyState.value = '';
        newHolder.value = '';
        newExpiryDate.value = '';

        emit('changed');

        await nextTick();
        copyButton.value?.focus();

        // Behind the dialog, so the list is current when it closes.
        await load(true);
    } catch (error: any) {
        const fields = serverFieldErrors(error);

        if (fields.holder_name || fields.expires_at) {
            issueErrors.value = {
                holder_name: (fields.holder_name ?? []).join(' '),
                expires_at: (fields.expires_at ?? []).join(' ')
            };
        } else {
            Swal.fire({ icon: 'error', title: 'The code was not added', text: serverMessage(error, 'Could not add the code.') });
        }
    } finally {
        issuing.value = false;
    }
};

const copyCode = async () => {
    if (!issued.value) return;

    try {
        await navigator.clipboard.writeText(issued.value.code);
        copyState.value = 'copied';
    } catch (e) {
        copyState.value = 'failed';
    }
};

/** Tab and Shift+Tab stay inside the shown-once dialog; Escape is swallowed, not obeyed. */
const onRevealKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        return;
    }

    trapTab(event, revealRoot.value);
};

/** Close the shown-once dialog and let the plaintext go. */
const dismissIssued = async () => {
    issued.value = null;
    copyState.value = '';

    await nextTick();
    holderInput.value?.focus();
};

const revoke = async (code: FormStaffCode) => {
    const confirmed = await Swal.fire({
        title: `Revoke ${code.holder_name}'s code?`,
        text: 'It stops working at once, on every phone. It stays on this list with the cash it recorded. This cannot be undone: add a new code if they need one again.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Revoke code',
        cancelButtonText: 'Keep it'
    });

    if (!confirmed.isConfirmed) return;

    busyId.value = code.id;

    try {
        const answer = await formsStore.revokeStaffCode(props.formId, code.id);
        replace(answer.code);
        toast('success', answer.message || 'The code has been revoked.');
        emit('changed');
    } catch (error: any) {
        Swal.fire({ icon: 'error', title: 'The code was not revoked', text: serverMessage(error, 'Could not revoke the code.') });
    } finally {
        busyId.value = null;
    }
};

const resetDevice = async (code: FormStaffCode) => {
    const confirmed = await Swal.fire({
        title: `Let another phone use ${code.holder_name}'s code?`,
        text: `Do this when ${code.holder_name} has changed phones. The next phone to enter the code claims it, and the old phone stops working. This is recorded on the code.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Reset phone',
        cancelButtonText: 'Cancel'
    });

    if (!confirmed.isConfirmed) return;

    busyId.value = code.id;

    try {
        const answer = await formsStore.resetStaffCodeDevice(props.formId, code.id);
        replace(answer.code);
        toast('success', answer.message || 'The next phone to enter this code will claim it.');
        emit('changed');
    } catch (error: any) {
        Swal.fire({ icon: 'error', title: 'The phone was not reset', text: serverMessage(error, 'Could not reset the phone.') });
    } finally {
        busyId.value = null;
    }
};

const clearLockout = async () => {
    clearing.value = true;

    try {
        const message = await formsStore.clearStaffCodeLockout(props.formId);
        toast('success', message || 'Staff code lockouts on this form have been cleared.');
    } catch (error: any) {
        Swal.fire({ icon: 'error', title: 'Lockouts were not cleared', text: serverMessage(error, 'Could not clear the lockouts.') });
    } finally {
        clearing.value = false;
    }
};

const replace = (updated: FormStaffCode) => {
    const index = codes.value.findIndex(code => code.id === updated.id);
    if (index >= 0) codes.value[index] = updated;
};

const toast = (icon: 'success' | 'error', text: string) => {
    Swal.fire({ icon, text, timer: 2500, showConfirmButton: false, toast: true, position: 'top-end' });
};

const money = (minor: number) => formatMinorAmount(minor, 'usd');

/** An instant on the masjid's clock, with its zone's short name: "Oct 18, 2026, 12:00 AM EDT". */
const formatInstant = (iso: string | null): string => {
    if (!iso) return '—';

    const date = new Date(iso);
    if (isNaN(date.getTime())) return iso;

    try {
        return new Intl.DateTimeFormat(undefined, {
            timeZone: meta.value?.timezone || undefined,
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            timeZoneName: 'short'
        }).format(date);
    } catch (e) {
        return date.toLocaleString();
    }
};

/** A calendar day ("2026-10-17") as words, with no timezone shift. */
const formatDay = (day: string | null): string => {
    if (!day) return '—';

    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day);
    if (!match) return day;

    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12));

    return new Intl.DateTimeFormat(undefined, {
        timeZone: 'UTC',
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    }).format(date);
};
</script>

<style scoped>
/* Above the codes dialog (1057), below SweetAlert2 (1060) so its confirmations show on top. */
.modal.staff-code-reveal {
    z-index: 1058;
    background: rgba(0, 0, 0, 0.5);
}

.staff-code-plain {
    letter-spacing: 0.2em;
    font-size: 1.75rem;
}
</style>
