<template>
    <div>
        <PageDataContainer :title="pageTitle" :hideButton="true">
            <template #headerButtons>
                <router-link class="btn btn-outline-secondary" :to="{ name: 'masjid.appointmentRequests' }">
                    <i class="bi bi-arrow-left me-1"></i>
                    Appointment Requests
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
                    <p class="mb-3">That appointment request no longer exists.</p>
                    <router-link class="btn btn-outline-primary btn-sm" :to="{ name: 'masjid.appointmentRequests' }">
                        Back to the queue
                    </router-link>
                </div>

                <!-- Load failed for a reason other than a missing row -->
                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    {{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="bootstrap">Retry</button>
                </div>

                <div v-else-if="request">
                    <!-- Header -->
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="badge" :class="statusBadgeClass(request.status)">
                            <i :class="`bi ${statusIcon(request.status)} me-1`"></i>
                            {{ statusLabel(request.status) }}
                        </span>
                        <span class="text-muted small">
                            Submitted {{ formatDateTime(request.created_at) }} ({{ timeAgo(request.created_at) }})
                        </span>
                    </div>
                    <p class="text-muted small mb-4">
                        <i class="bi bi-shield-lock me-1"></i>
                        Date of birth, reason for visit and staff notes are encrypted and
                        readable only by staff who can see your {{ directoryTerm }}.
                    </p>

                    <div class="row g-4">
                        <!-- The request itself -->
                        <div class="col-lg-7">
                            <div class="card border mb-4">
                                <div class="card-header bg-white fw-semibold">
                                    <i class="bi bi-person-vcard me-2"></i>The request
                                </div>
                                <div class="card-body">
                                    <dl class="row mb-0">
                                        <dt class="col-sm-4 text-muted fw-normal">Applicant</dt>
                                        <dd class="col-sm-8 fw-semibold">{{ request.applicant_name }}</dd>

                                        <dt class="col-sm-4 text-muted fw-normal">Phone</dt>
                                        <dd class="col-sm-8">
                                            <a :href="`tel:${request.phone}`" class="text-decoration-none">
                                                {{ request.phone }}
                                            </a>
                                        </dd>

                                        <dt class="col-sm-4 text-muted fw-normal">Email</dt>
                                        <dd class="col-sm-8">
                                            <a v-if="request.email" :href="`mailto:${request.email}`" class="text-decoration-none">
                                                {{ request.email }}
                                            </a>
                                            <span v-else class="text-muted">Not given</span>
                                        </dd>

                                        <dt class="col-sm-4 text-muted fw-normal">Date of birth</dt>
                                        <dd class="col-sm-8">{{ formatDate(request.date_of_birth) }}</dd>

                                        <dt class="col-sm-4 text-muted fw-normal">Availability</dt>
                                        <dd class="col-sm-8">
                                            <span v-if="request.preferred_window">{{ request.preferred_window }}</span>
                                            <span v-else class="text-muted">No preference given</span>
                                        </dd>

                                        <dt class="col-sm-4 text-muted fw-normal">Reason for visit</dt>
                                        <dd class="col-sm-8 mb-0">
                                            <div class="reason-body">{{ request.reason }}</div>
                                        </dd>
                                    </dl>
                                </div>
                            </div>

                            <!-- Notes thread -->
                            <AppointmentRequestNotesPanel
                                :requestId="requestId"
                                :notes="notes"
                                @added="reload"
                            />
                        </div>

                        <!-- Triage + history -->
                        <div class="col-lg-5">
                            <div class="card border mb-4">
                                <div class="card-header bg-white fw-semibold">
                                    <i class="bi bi-signpost-split me-2"></i>Triage
                                </div>
                                <div class="card-body">
                                    <!--
                                        Every status is offered at all times, in the
                                        server's own order. Triage here is a LABEL,
                                        not a state machine: the backend accepts any
                                        status from any status on purpose, because a
                                        closed request reopens the moment the person
                                        calls back. Do not add ordering rules the API
                                        does not have.
                                    -->
                                    <div class="d-grid gap-2">
                                        <button
                                            v-for="status in statuses"
                                            :key="status"
                                            type="button"
                                            class="btn text-start"
                                            :class="status === request.status ? 'btn-success' : 'btn-outline-secondary'"
                                            :disabled="savingStatus"
                                            @click="setStatus(status)"
                                        >
                                            <i :class="`bi ${statusIcon(status)} me-2`"></i>
                                            {{ statusLabel(status) }}
                                            <i v-if="status === request.status" class="bi bi-check2 ms-2"></i>
                                        </button>
                                    </div>
                                    <div v-if="statusError" class="text-danger small mt-3">
                                        <i class="bi bi-exclamation-triangle me-1"></i>{{ statusError }}
                                    </div>
                                    <div class="form-text mt-3">
                                        Any of these can be set at any time — reopen a closed
                                        request by choosing another label.
                                    </div>
                                </div>
                            </div>

                            <div class="card border">
                                <div class="card-header bg-white fw-semibold">
                                    <i class="bi bi-clock-history me-2"></i>History
                                </div>
                                <div class="card-body">
                                    <ul class="timeline mb-0">
                                        <li v-for="(entry, index) in history" :key="index">
                                            <div class="small text-muted">{{ formatDateTime(entry.at) }}</div>
                                            <div>{{ entry.label }}</div>
                                            <div v-if="entry.by" class="small text-muted">{{ entry.by }}</div>
                                        </li>
                                    </ul>
                                    <!--
                                        Said plainly because a clinic will otherwise
                                        assume it: individual status changes are NOT
                                        recorded anywhere. The row keeps only when it
                                        last changed, so the notes are the history.
                                    -->
                                    <div class="form-text mt-3">
                                        Status changes are not recorded individually — only when
                                        the request last changed. Write a note for anything that
                                        needs to be remembered.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeMount } from 'vue';
import { useRoute } from 'vue-router';
import { AxiosError } from 'axios';
import PageDataContainer from '@/components/PageDataContainer.vue';
import AppointmentRequestNotesPanel from './appointment-requests/AppointmentRequestNotesPanel.vue';
import {
    APPOINTMENT_REQUEST_STATUSES,
    AppointmentRequest,
    AppointmentRequestNote,
    AppointmentRequestStatus
} from '@/core/types/data/masjid-related/AppointmentRequest';
import { useAppointmentRequestsStore } from '@/stores/masjid/appointmentRequestsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useAppointmentRequestDisplay } from '@/composables/useAppointmentRequestDisplay';
import { apiErrorText } from '@/core/services/ApiErrors';

/**
 * One appointment request: everything the clinic holds about this contact, the
 * triage control, and the notes that are its working history.
 *
 * This is the ONLY screen that shows the encrypted fields — the queue endpoint
 * does not even select them. So this component is the whole disclosure surface,
 * and it carries the whole discipline with it: no console call, no analytics
 * call, nothing about the request in the URL beyond its id, and errors rendered
 * as messages rather than as objects. See .claude/rules/appointments.md.
 */

type HistoryEntry = { at: string; label: string; by?: string };

// Routing
const route = useRoute();

// Stores
const appointmentRequestsStore = useAppointmentRequestsStore();
const masjidStore = useMasjidStore();

// Display helpers
const {
    statusLabel, statusBadgeClass, statusIcon, formatDateTime, formatDate, timeAgo
} = useAppointmentRequestDisplay();

// State
const requestId = Number(route.params.requestId);
const request = ref<AppointmentRequest | null>(null);
const bootstrapping = ref(true);
const notFound = ref(false);
const loadError = ref('');
const savingStatus = ref(false);
const statusError = ref('');

// Computed
/**
 * The page names the person, because that is what an operator is looking at.
 * "Appointment Request" is the FEATURE's name and stays authored — what comes
 * from the terminology pack is who the tenant's people are, below.
 */
const pageTitle = computed<string>(() => request.value?.applicant_name || 'Appointment Request');

const notes = computed<AppointmentRequestNote[]>(() => request.value?.notes ?? []);

const statuses = computed<AppointmentRequestStatus[]>(
    () => appointmentRequestsStore.requestsMeta?.statuses ?? APPOINTMENT_REQUEST_STATUSES
);

/** "contacts" for a clinic, "congregants" for a masjid — never hardcoded. */
const directoryTerm = computed<string>(() => masjidStore.term('members').toLowerCase());

/**
 * What the data can honestly say happened, newest first: the submission, the
 * last change to the row, and every note. There is no per-transition audit
 * trail on the backend, so nothing here pretends there is one.
 */
const history = computed<HistoryEntry[]>(() => {
    if (!request.value) return [];

    const entries: HistoryEntry[] = notes.value.map((note) => ({
        at: note.created_at,
        label: 'Staff note added',
        by: note.author?.name || 'A former staff member'
    }));

    if (request.value.updated_at && request.value.updated_at !== request.value.created_at) {
        entries.push({
            at: request.value.updated_at,
            label: `Last changed — now ${statusLabel(request.value.status).toLowerCase()}`
        });
    }

    entries.push({
        at: request.value.created_at,
        label: request.value.source === 'web'
            ? 'Submitted through the public appointment form'
            : `Submitted (${request.value.source})`
    });

    return entries.sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime());
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
        request.value = await appointmentRequestsStore.fetchRequest(requestId);
        if (!request.value) notFound.value = true;
    } catch (error) {
        // A cross-tenant or deleted id is a 404 MISS by design, not a failure to
        // report as an error — see .claude/rules/tenant-scoping.md.
        if ((error as AxiosError)?.response?.status === 404) {
            notFound.value = true;
        } else {
            loadError.value = apiErrorText(error, 'Failed to load this appointment request.');
        }
    } finally {
        bootstrapping.value = false;
    }
};

/** Re-read after a write, so notes_count, updated_at and the thread stay truthful. */
const reload = async () => {
    try {
        const fresh = await appointmentRequestsStore.fetchRequest(requestId);
        if (fresh) request.value = fresh;
    } catch (error) {
        loadError.value = apiErrorText(error, 'Failed to refresh this appointment request.');
    }
};

const setStatus = async (status: AppointmentRequestStatus) => {
    if (!request.value || status === request.value.status || savingStatus.value) return;

    savingStatus.value = true;
    statusError.value = '';
    try {
        await appointmentRequestsStore.updateStatus(requestId, status);
        await reload();
    } catch (error) {
        // Inline, next to the control that failed: a toast would leave the
        // buttons showing a status the server never accepted.
        statusError.value = apiErrorText(error, 'Failed to update the status.');
    } finally {
        savingStatus.value = false;
    }
};
</script>

<style scoped>
.reason-body {
    white-space: pre-wrap;
}

.timeline {
    list-style: none;
    padding-left: 1.25rem;
    margin: 0;
}

.timeline li {
    position: relative;
    padding-bottom: 1rem;
    border-left: 2px solid #e9ecef;
    padding-left: 1rem;
    margin-left: 0.25rem;
}

.timeline li:last-child {
    border-left-color: transparent;
    padding-bottom: 0;
}

.timeline li::before {
    content: '';
    position: absolute;
    left: -0.4rem;
    top: 0.35rem;
    width: 0.65rem;
    height: 0.65rem;
    border-radius: 50%;
    background-color: #adb5bd;
}
</style>
