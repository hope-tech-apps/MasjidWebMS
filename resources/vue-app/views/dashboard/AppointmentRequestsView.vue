<template>
    <div>
        <PageDataContainer
            title="Appointment Requests"
            :hideButton="true"
            :paginationOptions="paginationOptions"
            @pageChange="pageChange"
        >
            <template #headerButtons>
                <button class="btn btn-outline-secondary" @click="loadData(currentPage)" :disabled="loading">
                    <span v-if="loading" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <i v-else class="bi bi-arrow-clockwise me-1"></i>
                    Refresh
                </button>
            </template>

            <div class="container w-100">
                <!-- Status filter: one tab per status the SERVER states, plus "All".
                     Counts are the whole queue, so they do not move when a filter
                     is applied. -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="d-flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="btn btn-sm status-tab"
                                :class="filters.status === '' ? 'btn-success' : 'btn-outline-secondary'"
                                @click="setStatusFilter('')"
                            >
                                All
                                <span class="badge rounded-pill ms-1 tab-count">{{ totalCount }}</span>
                            </button>
                            <button
                                v-for="status in statuses"
                                :key="status"
                                type="button"
                                class="btn btn-sm status-tab"
                                :class="filters.status === status ? 'btn-success' : 'btn-outline-secondary'"
                                @click="setStatusFilter(status)"
                            >
                                <i :class="`bi ${statusIcon(status)} me-1`"></i>
                                {{ statusLabel(status) }}
                                <span class="badge rounded-pill ms-1 tab-count">{{ countFor(status) }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Search + sort -->
                <div class="row g-3 mb-4">
                    <div class="col-md-7 col-lg-6">
                        <div class="search-box">
                            <i class="bi bi-search search-icon"></i>
                            <input
                                type="text"
                                class="search-input"
                                placeholder="Search by name, phone, or email..."
                                v-model="searchQuery"
                            >
                            <button
                                v-if="searchQuery"
                                class="search-clear-btn"
                                @click="searchQuery = ''"
                                type="button"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <!--
                            Said out loud because it is a deliberate limit, not an
                            oversight: reason for visit and date of birth are
                            encrypted at rest, so they cannot be searched — and
                            they are not in this list at all.
                        -->
                        <div class="form-text">
                            Reason for visit and date of birth are encrypted, so they are
                            not searchable and appear only on a request's own page.
                        </div>
                    </div>
                    <div class="col-md-5 col-lg-4">
                        <label class="form-label small text-muted mb-1">Order</label>
                        <select class="form-select" v-model="filters.sort" @change="loadData(1)">
                            <option value="newest">Newest first</option>
                            <option value="oldest">Oldest first — longest waiting</option>
                        </select>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- Error State -->
                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    {{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="loadData(currentPage)">Retry</button>
                </div>

                <!-- Empty: filters are hiding everything -->
                <div v-else-if="requests.length === 0 && filtersActive" class="text-center py-5 text-muted">
                    <i class="bi bi-funnel fs-1 d-block mb-3"></i>
                    <p class="mb-3">No requests match this filter.</p>
                    <button class="btn btn-sm btn-outline-secondary" @click="clearFilters">Clear filters</button>
                </div>

                <!--
                    Empty: nothing has ever arrived. This is the first screen a new
                    clinic sees, so it says what WILL appear here and where it comes
                    from, rather than a bare "no results".
                -->
                <div v-else-if="requests.length === 0" class="text-center py-5">
                    <i class="bi bi-calendar-plus fs-1 d-block mb-3 text-muted"></i>
                    <h6 class="mb-2">No appointment requests yet</h6>
                    <p class="text-muted mb-2 mx-auto empty-copy">
                        When someone submits the appointment form on your public site, it
                        arrives here — their name, how to reach them, when they are
                        available and why they are asking — instead of an email inbox.
                    </p>
                    <p class="text-muted small mb-0 mx-auto empty-copy">
                        Only staff who can see your {{ directoryTerm }} can open one, and
                        you can work each request through {{ statusSentence }} without
                        anything leaving this dashboard.
                    </p>
                </div>

                <!-- The queue -->
                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Contact</th>
                                <th>Availability</th>
                                <th class="text-center">Status</th>
                                <th>Submitted</th>
                                <th class="text-center">Notes</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="request in requests" :key="request.id">
                                <td>
                                    <router-link
                                        class="fw-semibold text-decoration-none"
                                        :to="{ name: 'masjid.appointmentRequestDetail', params: { requestId: request.id } }"
                                    >
                                        {{ request.applicant_name }}
                                    </router-link>
                                </td>
                                <td>
                                    <div>{{ request.phone }}</div>
                                    <div v-if="request.email" class="text-muted small">{{ request.email }}</div>
                                </td>
                                <td>
                                    <span v-if="request.preferred_window">{{ request.preferred_window }}</span>
                                    <span v-else class="text-muted">No preference given</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge" :class="statusBadgeClass(request.status)">
                                        {{ statusLabel(request.status) }}
                                    </span>
                                </td>
                                <td>
                                    <div>{{ formatDateTime(request.created_at) }}</div>
                                    <div class="text-muted small">{{ timeAgo(request.created_at) }}</div>
                                </td>
                                <td class="text-center">
                                    <span v-if="request.notes_count" class="badge bg-light text-dark border">
                                        {{ request.notes_count }}
                                    </span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td class="text-end">
                                    <router-link
                                        class="btn btn-sm btn-outline-primary"
                                        :to="{ name: 'masjid.appointmentRequestDetail', params: { requestId: request.id } }"
                                        title="Open"
                                    >
                                        <i class="bi bi-box-arrow-in-right"></i>
                                    </router-link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onBeforeMount, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import {
    APPOINTMENT_REQUEST_STATUSES,
    AppointmentRequestFilters,
    AppointmentRequestQueueRow,
    AppointmentRequestStatus
} from '@/core/types/data/masjid-related/AppointmentRequest';
import { useAppointmentRequestsStore } from '@/stores/masjid/appointmentRequestsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useAppointmentRequestDisplay } from '@/composables/useAppointmentRequestDisplay';
import { apiErrorText } from '@/core/services/ApiErrors';

/**
 * The operator's triage queue: what replaces a clinic's plaintext email inbox.
 *
 * Two rules shape this screen rather than taste:
 *
 *  - LEAST DISCLOSURE. The list endpoint does not serve `reason` or
 *    `date_of_birth`, so this table cannot show them even by accident — they are
 *    read on the one-person detail screen, one deliberate click at a time. The
 *    hint under the search box says so, because an operator who cannot find a
 *    "search by reason" box deserves to know why it does not exist.
 *  - NOTHING IS LOGGED. No console call, no analytics call anywhere in this
 *    feature: an Axios error object carries the response body, and here that
 *    body is a person's health contact. Errors render as text through
 *    `apiErrorText`. See .claude/rules/appointments.md.
 *
 * Filter state lives in this component and NOT in the route query — a search
 * term here is a patient's name, and the address bar is written to browser
 * history.
 */

// Stores
const appointmentRequestsStore = useAppointmentRequestsStore();
const masjidStore = useMasjidStore();

// Display helpers
const { statusLabel, statusBadgeClass, statusIcon, formatDateTime, timeAgo } = useAppointmentRequestDisplay();

// State
const loading = ref(false);
const loadError = ref('');
const searchQuery = ref('');
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const filters = reactive<AppointmentRequestFilters>({
    status: '',
    search: '',
    sort: 'newest'
});

// Computed
const requests = computed<AppointmentRequestQueueRow[]>(
    () => (appointmentRequestsStore.requestsPaginated?.data as AppointmentRequestQueueRow[]) || []
);

/** The triage vocabulary as the SERVER states it; the literal list is a cold-load fallback. */
const statuses = computed<AppointmentRequestStatus[]>(
    () => appointmentRequestsStore.requestsMeta?.statuses ?? APPOINTMENT_REQUEST_STATUSES
);

/** Whom this tenant's directory holds — "Contacts" for a clinic, "Congregants" for a masjid. */
const directoryTerm = computed<string>(() => masjidStore.term('members').toLowerCase());

/** "new, contacted, scheduled and closed" — built from the server's set, never hardcoded. */
const statusSentence = computed<string>(() => {
    const labels = statuses.value.map((status) => statusLabel(status).toLowerCase());
    if (labels.length < 2) return labels.join('');

    return `${labels.slice(0, -1).join(', ')} and ${labels[labels.length - 1]}`;
});

const statusCounts = computed<Partial<Record<AppointmentRequestStatus, number>>>(
    () => appointmentRequestsStore.requestsMeta?.status_counts ?? {}
);

const countFor = (status: AppointmentRequestStatus): number => statusCounts.value[status] ?? 0;

/** The whole queue, summed from the same counts the tabs use. */
const totalCount = computed<number>(
    () => statuses.value.reduce((sum, status) => sum + countFor(status), 0)
);

const currentPage = computed<number>(() => appointmentRequestsStore.requestsPaginated?.current_page || 1);

const filtersActive = computed<boolean>(() => filters.status !== '' || filters.search !== '');

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!appointmentRequestsStore.requestsPaginated) return undefined;
    return {
        currentPage: appointmentRequestsStore.requestsPaginated.current_page,
        itemsTotal: appointmentRequestsStore.requestsPaginated.total,
        perPage: appointmentRequestsStore.requestsPaginated.per_page
    };
});

// Lifecycle
onBeforeMount(async () => {
    await loadData();
});

// Debounced search — one request per pause, not per keystroke.
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
        await appointmentRequestsStore.fetchRequests(page, filters);
    } catch (error) {
        // apiErrorText reads the message out of the envelope; the error object
        // itself is never printed anywhere.
        loadError.value = apiErrorText(error, 'Failed to load the appointment requests.');
    } finally {
        loading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    if (data.toPage === currentPage.value) return;
    await loadData(data.toPage);
};

const setStatusFilter = async (status: AppointmentRequestStatus | '') => {
    if (filters.status === status) return;
    filters.status = status;
    await loadData(1);
};

const clearFilters = async () => {
    filters.status = '';
    filters.search = '';
    searchQuery.value = '';
    await loadData(1);
};
</script>

<style scoped>
/* Filter tabs */
.status-tab {
    display: inline-flex;
    align-items: center;
}

.status-tab .tab-count {
    background-color: rgba(0, 0, 0, 0.08);
    color: inherit;
    font-weight: 600;
}

.status-tab.btn-success .tab-count {
    background-color: rgba(255, 255, 255, 0.25);
    color: #fff;
}

.empty-copy {
    max-width: 34rem;
}

/* Search Box — the shared idiom from GroupsView */
.search-box {
    position: relative;
    width: 100%;
}

.search-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 1.1rem;
    pointer-events: none;
    z-index: 1;
}

.search-input {
    width: 100%;
    padding: 0.75rem 2.75rem 0.75rem 2.75rem;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s ease;
    background-color: #f8f9fa;
}

.search-input:focus {
    outline: none;
    border-color: #667eea;
    background-color: white;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.search-input::placeholder {
    color: #adb5bd;
}

.search-clear-btn {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #6c757d;
    font-size: 0.875rem;
    cursor: pointer;
    padding: 0.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.search-clear-btn:hover {
    background-color: #e9ecef;
    color: #495057;
}
</style>
