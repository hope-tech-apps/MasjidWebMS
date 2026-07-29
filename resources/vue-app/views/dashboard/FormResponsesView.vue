<template>
    <div>
        <PageDataContainer
            title="Form Responses"
            :paginationOptions="paginationOptions"
            :hideButton="true"
            @pageChange="pageChange"
        >
            <template #headerButtons>
                <button
                    class="btn btn-outline-secondary"
                    :disabled="!selectedFormId || exporting || dateRangeInvalid"
                    @click="exportCsv"
                    title="Download the responses matching the current filters"
                >
                    <span v-if="exporting" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="bi bi-download me-1"></i>
                    Export CSV
                </button>
            </template>

            <div class="container w-100">
                <!-- Still resolving which forms this masjid has -->
                <div v-if="!bootstrapped" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <!-- No forms at all -->
                <div v-else-if="!formOptions.length" class="text-center py-5 text-muted">
                    <i class="bi bi-ui-checks fs-1 d-block mb-3"></i>
                    <p class="mb-0">No forms yet</p>
                    <p class="small mb-0">Add a form section to a page to start collecting responses.</p>
                </div>

                <template v-else>
                    <!-- Form picker — only worth showing once there is a choice to make -->
                    <div v-if="formOptions.length > 1" class="row mb-4">
                        <div class="col-md-6 col-lg-5">
                            <label class="form-label small text-muted mb-1">Form</label>
                            <select class="form-select" v-model.number="selectedFormId">
                                <option v-for="form in formOptions" :key="form.id" :value="form.id">
                                    {{ form.name }} ({{ form.response_count }})
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label small text-muted mb-1" for="responses-search">Search</label>
                            <!--
                                flex-nowrap is load-bearing: .input-group defaults to
                                flex-wrap: wrap, so in a narrow column the trailing clear
                                button drops onto its own line and reads as a small empty
                                box floating under the field. Keep the group on one line.
                            -->
                            <div class="input-group flex-nowrap">
                                <span class="input-group-text bg-white" aria-hidden="true"><i class="bi bi-search"></i></span>
                                <input
                                    id="responses-search"
                                    type="search"
                                    class="form-control"
                                    placeholder="Name, email or phone…"
                                    v-model="searchQuery"
                                >
                                <button
                                    v-if="searchQuery"
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    @click="searchQuery = ''"
                                    title="Clear search"
                                    aria-label="Clear search"
                                >
                                    <!-- Icon-only, so it needs the aria-label above: without
                                         it a screen reader announces an unnamed button. -->
                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-2">
                            <label class="form-label small text-muted mb-1">Status</label>
                            <select class="form-select" v-model="statusFilter">
                                <option value="">All statuses</option>
                                <option v-for="s in statuses" :key="s" :value="s" class="text-capitalize">{{ s }}</option>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-2">
                            <label class="form-label small text-muted mb-1">Submitted from</label>
                            <input type="date" class="form-control" v-model="fromDate" :max="toDate || undefined">
                        </div>
                        <div class="col-md-6 col-lg-2">
                            <label class="form-label small text-muted mb-1">Submitted to</label>
                            <input type="date" class="form-control" v-model="toDate" :min="fromDate || undefined">
                        </div>
                        <div class="col-md-6 col-lg-2 d-flex align-items-end">
                            <button
                                class="btn btn-outline-secondary w-100"
                                :disabled="!filtersApplied"
                                @click="clearFilters"
                            >
                                Clear filters
                            </button>
                        </div>
                    </div>

                    <p v-if="dateRangeInvalid" class="text-danger small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        The “to” date cannot be earlier than the “from” date.
                    </p>

                    <!-- Summary -->
                    <div v-if="meta" class="alert alert-light border d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                        <span>
                            <strong>{{ meta.form.name }}</strong>
                            · {{ paginationOptions?.itemsTotal ?? 0 }} response{{ (paginationOptions?.itemsTotal ?? 0) === 1 ? '' : 's' }} shown
                        </span>
                        <span class="text-muted small">
                            {{ meta.form.response_count }} total<span v-if="meta.form.capacity"> of {{ meta.form.capacity }} places</span>
                        </span>
                    </div>

                    <!-- Loading State -->
                    <div v-if="loading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div v-else-if="responses.length === 0" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                        <p class="mb-0">{{ filtersApplied ? 'No responses match these filters' : 'No responses yet' }}</p>
                    </div>

                    <!-- Responses Table -->
                    <div v-else class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th
                                        v-for="column in TABLE_COLUMNS"
                                        :key="column.label"
                                        :class="column.class"
                                        :aria-sort="ariaSort(column.sort)"
                                    >
                                        <button
                                            v-if="isSortable(column.sort)"
                                            type="button"
                                            class="sort-header"
                                            :class="{ active: sort === column.sort }"
                                            @click="toggleSort(column.sort as FormResponseSortColumn)"
                                            :title="`Sort by ${column.label}`"
                                        >
                                            <span>{{ column.label }}</span>
                                            <i class="bi sort-icon" :class="sortIcon(column.sort)"></i>
                                        </button>
                                        <span v-else>{{ column.label }}</span>
                                    </th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="response in responses" :key="response.id">
                                    <td>{{ formatDateTime(response.submitted_at) }}</td>
                                    <td>
                                        <strong>{{ response.respondent_name || '—' }}</strong>
                                    </td>
                                    <td>
                                        <a v-if="response.respondent_email" :href="`mailto:${response.respondent_email}`" class="text-decoration-none">
                                            {{ response.respondent_email }}
                                        </a>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td>
                                        <a v-if="response.respondent_phone" :href="`tel:${response.respondent_phone}`" class="text-decoration-none">
                                            {{ response.respondent_phone }}
                                        </a>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="text-center">{{ response.entry_count }}</td>
                                    <td class="text-end">{{ formatAmount(response.amount_due) }}</td>
                                    <td>
                                        <span class="badge text-capitalize" :class="statusClass(response.status)">
                                            {{ response.status }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" @click="openDetail(response)" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" @click="confirmDelete(response)" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </PageDataContainer>

        <!-- Response Details Modal -->
        <Teleport to="body">
            <div v-if="showDetailModal && selectedResponse" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="closeDetail">
                <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-card-checklist me-2"></i>
                                {{ selectedResponse.respondent_name || 'Response' }}
                            </h5>
                            <button type="button" class="btn-close" @click="closeDetail"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Summary -->
                            <div class="row mb-4">
                                <div class="col-md-3">
                                    <h6 class="text-muted mb-1">Submitted</h6>
                                    <p class="mb-0">{{ formatDateTime(selectedResponse.submitted_at) }}</p>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="text-muted mb-1">Entries</h6>
                                    <p class="mb-0">{{ selectedResponse.entry_count }}</p>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="text-muted mb-1">Amount due</h6>
                                    <p class="mb-0">{{ formatAmount(selectedResponse.amount_due) }}</p>
                                </div>
                                <div class="col-md-3">
                                    <h6 class="text-muted mb-1">Status</h6>
                                    <p class="mb-0">
                                        <span class="badge text-capitalize" :class="statusClass(selectedResponse.status)">
                                            {{ selectedResponse.status }}
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <!-- Contact -->
                            <h6 class="text-muted text-uppercase small mb-3">Contact</h6>
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Email</h6>
                                    <p class="mb-0">
                                        <a v-if="selectedResponse.respondent_email" :href="`mailto:${selectedResponse.respondent_email}`">{{ selectedResponse.respondent_email }}</a>
                                        <span v-else class="text-muted">Not provided</span>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-1">Phone</h6>
                                    <p class="mb-0">
                                        <a v-if="selectedResponse.respondent_phone" :href="`tel:${selectedResponse.respondent_phone}`">{{ selectedResponse.respondent_phone }}</a>
                                        <span v-else class="text-muted">Not provided</span>
                                    </p>
                                </div>
                            </div>

                            <!-- Full submission. List rows carry no answers, so this waits
                                 on the single-response fetch. -->
                            <h6 class="text-muted text-uppercase small mb-3">Submission</h6>

                            <div v-if="detailLoading" class="text-center py-4">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>

                            <template v-else-if="detail">
                                <!-- Answers to the non-repeating questions -->
                                <dl v-if="flatColumns.length" class="row mb-4">
                                    <template v-for="column in flatColumns" :key="column.key">
                                        <dt class="col-sm-4 text-muted fw-normal small">{{ column.label }}</dt>
                                        <dd class="col-sm-8" style="white-space: pre-wrap;">{{ displayValue(detail.data?.[column.field]) }}</dd>
                                    </template>
                                </dl>

                                <!-- The repeatable section (attendees, guests, …) -->
                                <template v-for="group in repeatableGroups" :key="group.sectionId">
                                    <h6 class="text-muted mb-2">{{ group.title }}</h6>
                                    <div class="table-responsive mb-4">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width: 3rem;">#</th>
                                                    <th v-for="column in group.columns" :key="column.key">{{ column.label }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(entry, index) in repeatableRows(group.sectionId)" :key="index">
                                                    <td class="text-muted">{{ index + 1 }}</td>
                                                    <td v-for="column in group.columns" :key="column.key">
                                                        {{ displayValue(entry?.[column.field]) }}
                                                    </td>
                                                </tr>
                                                <tr v-if="!repeatableRows(group.sectionId).length">
                                                    <td :colspan="group.columns.length + 1" class="text-center text-muted py-3">No entries</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </template>

                                <!-- Safety net: a form whose schema produced no columns (or
                                     answers saved under a question since removed) still has
                                     to be readable. -->
                                <dl v-if="!columns.length" class="row mb-4">
                                    <template v-for="[key, value] in rawEntries" :key="key">
                                        <dt class="col-sm-4 text-muted fw-normal small">{{ key }}</dt>
                                        <dd class="col-sm-8" style="white-space: pre-wrap;">{{ displayValue(value) }}</dd>
                                    </template>
                                    <dd v-if="!rawEntries.length" class="col-12 text-muted mb-0">Nothing was submitted.</dd>
                                </dl>
                            </template>

                            <p v-else class="text-muted">Could not load the full submission.</p>

                            <!-- Triage. Only these two fields are editable — the answers
                                 themselves are a record of what somebody agreed to. -->
                            <h6 class="text-muted text-uppercase small mb-3">Admin</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label small text-muted mb-1">Status</label>
                                    <select class="form-select text-capitalize" v-model="editStatus">
                                        <option v-for="s in statuses" :key="s" :value="s" class="text-capitalize">{{ s }}</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small text-muted mb-1">Notes</label>
                                    <!-- maxlength mirrors the API's admin_notes rule so a long
                                         note is stopped here rather than coming back as a 422. -->
                                    <textarea class="form-control" rows="3" maxlength="5000" v-model="editNotes" placeholder="Internal notes — not shown to the respondent"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="closeDetail" :disabled="saving">Close</button>
                            <button type="button" class="btn btn-success" @click="saveDetail" :disabled="saving || !hasEdits">
                                <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                Save changes
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed, watch, nextTick } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import {
    FormOption,
    FormResponseColumn,
    FormResponseDetail,
    FormResponseFilters,
    FormResponseRow,
    FormResponseSortColumn,
    FormResponseSortDirection,
    FormResponseStatus,
    FormResponsesMeta
} from '@/core/types/data/masjid-related/Form';
import { useFormResponsesStore } from '@/stores/masjid/formResponsesStore';
import { LOCAL_STORAGE_KEYS } from '@/core/constants/appConfigConstants';
import Swal from 'sweetalert2';

// Store
const formResponsesStore = useFormResponsesStore();

// The table's fixed columns. These are the denormalised identity/summary columns on the
// row itself — the schema's own questions are NOT columns here, because list rows carry
// no answers (they live behind the single-response fetch, see the detail modal).
const TABLE_COLUMNS: { label: string; sort: FormResponseSortColumn | null; class?: string }[] = [
    { label: 'Submitted', sort: 'submitted_at' },
    { label: 'Name', sort: 'respondent_name' },
    { label: 'Email', sort: 'respondent_email' },
    { label: 'Phone', sort: null },
    { label: 'Entries', sort: 'entry_count', class: 'text-center' },
    { label: 'Amount due', sort: 'amount_due', class: 'text-end' },
    { label: 'Status', sort: 'status' }
];

// The direction a column should start in when it is first clicked: newest/biggest first
// for dates and numbers, A–Z for text.
const DEFAULT_DIRECTIONS: Record<FormResponseSortColumn, FormResponseSortDirection> = {
    submitted_at: 'desc',
    respondent_name: 'asc',
    respondent_email: 'asc',
    status: 'asc',
    entry_count: 'desc',
    amount_due: 'desc'
};

const FALLBACK_STATUSES: FormResponseStatus[] = ['new', 'confirmed', 'waitlisted', 'cancelled'];
const FALLBACK_SORTABLE: FormResponseSortColumn[] = [
    'submitted_at', 'respondent_name', 'respondent_email', 'status', 'entry_count', 'amount_due'
];

// State
const loading = ref(false);
const saving = ref(false);
const exporting = ref(false);
const bootstrapped = ref(false);
const selectedFormId = ref<number | null>(null);

// Filters — every one of these is applied by the server.
const searchQuery = ref('');
const statusFilter = ref<FormResponseStatus | ''>('');
const fromDate = ref('');
const toDate = ref('');
const sort = ref<FormResponseSortColumn>('submitted_at');
const direction = ref<FormResponseSortDirection>('desc');
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

// Set while this component (rather than the admin) is assigning filter refs, so a bulk
// change costs one request instead of one per watcher. Always cleared after nextTick(),
// by which point the pre-flush watcher callbacks have already run and bailed out.
let suppressFilterWatchers = false;

// Detail modal
const showDetailModal = ref(false);
const detailLoading = ref(false);
const selectedResponse = ref<FormResponseRow | FormResponseDetail | null>(null);
const detail = ref<FormResponseDetail | null>(null);
const editStatus = ref<FormResponseStatus>('new');
const editNotes = ref('');

// Computed
const formOptions = computed<FormOption[]>(() => formResponsesStore.formOptions);
const meta = computed<FormResponsesMeta | undefined>(() => formResponsesStore.responsesMeta);
const responses = computed<FormResponseRow[]>(() => (formResponsesStore.responsesPaginated?.data as FormResponseRow[]) || []);
const statuses = computed<FormResponseStatus[]>(() => meta.value?.statuses ?? FALLBACK_STATUSES);
const sortableColumns = computed<FormResponseSortColumn[]>(() => meta.value?.sortable ?? FALLBACK_SORTABLE);
const columns = computed<FormResponseColumn[]>(() => meta.value?.columns ?? []);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!formResponsesStore.responsesPaginated) return undefined;
    return {
        currentPage: formResponsesStore.responsesPaginated.current_page,
        itemsTotal: formResponsesStore.responsesPaginated.total,
        perPage: formResponsesStore.responsesPaginated.per_page
    };
});

const filters = computed<FormResponseFilters>(() => ({
    q: searchQuery.value,
    status: statusFilter.value,
    from: fromDate.value,
    to: toDate.value,
    sort: sort.value,
    direction: direction.value
}));

const filtersApplied = computed(() =>
    !!searchQuery.value || !!statusFilter.value || !!fromDate.value || !!toDate.value);

// The API rejects to < from with a 422; catch it here so the admin gets an explanation
// instead of a validation error.
const dateRangeInvalid = computed(() => !!fromDate.value && !!toDate.value && toDate.value < fromDate.value);

const flatColumns = computed<FormResponseColumn[]>(() => columns.value.filter(c => !c.repeatable));

// The repeatable section's columns, regrouped under the section they came from. There is
// at most one such section per form, but grouping by the key prefix keeps this honest if
// that ever changes.
const repeatableGroups = computed(() => {
    const groups = new Map<string, { sectionId: string; title: string; columns: FormResponseColumn[] }>();

    columns.value.filter(c => c.repeatable).forEach((column) => {
        const sectionId = column.key.split('.')[0];
        if (!groups.has(sectionId)) {
            groups.set(sectionId, { sectionId, title: column.section || sectionId, columns: [] });
        }
        groups.get(sectionId)?.columns.push(column);
    });

    return Array.from(groups.values());
});

const rawEntries = computed<[string, any][]>(() => Object.entries(detail.value?.data ?? {}));

const hasEdits = computed(() =>
    !!selectedResponse.value &&
    (editStatus.value !== selectedResponse.value.status ||
        editNotes.value !== (selectedResponse.value.admin_notes ?? '')));

// Lifecycle
onBeforeMount(async () => {
    await bootstrap();
});

// masjidStore.masjid is null on the first paint of a hard refresh — DashboardLayout
// fetches it asynchronously — so boot again once an id actually lands, otherwise the
// screen would sit empty until the admin navigated away and back.
watch(() => formResponsesStore.masjidId(), async (id, previousId) => {
    if (id && !previousId) await bootstrap();
});

// Debounced search, 500ms.
watch(searchQuery, () => {
    if (suppressFilterWatchers) return;
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        await loadData(1);
    }, 500);
});

// The select/date filters re-fetch immediately.
watch([statusFilter, fromDate, toDate], async () => {
    if (suppressFilterWatchers) return;
    await loadData(1);
});

// Switching forms means a different schema and a different result set.
watch(selectedFormId, async () => {
    if (suppressFilterWatchers) return;
    await loadData(1);
});

// Methods
const bootstrap = async () => {
    if (!formResponsesStore.masjidId()) return;

    try {
        const options = await formResponsesStore.fetchFormOptions();
        if (options.length) {
            // Assign quietly, then load once below — letting the watcher fire as well
            // would mean two identical requests on every first paint.
            suppressFilterWatchers = true;
            selectedFormId.value = options[0].id;
            await nextTick();
            suppressFilterWatchers = false;
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load forms.' });
    } finally {
        // Reveal the screen either way: with no forms it shows the empty state, and on a
        // failure it shows that rather than an endless spinner.
        bootstrapped.value = true;
    }

    if (selectedFormId.value) await loadData(1);
};

const loadData = async (page: number = 1) => {
    if (!selectedFormId.value || dateRangeInvalid.value) return;

    loading.value = true;
    try {
        await formResponsesStore.fetchResponses(selectedFormId.value, filters.value, page);
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load responses.' });
    } finally {
        loading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    await loadData(data.toPage);
};

const clearFilters = async () => {
    // Resetting four refs would otherwise wake both the debounced search watcher and
    // the filter watcher — two requests for one click. Silence them and re-fetch once.
    if (searchTimeout) clearTimeout(searchTimeout);
    suppressFilterWatchers = true;
    searchQuery.value = '';
    statusFilter.value = '';
    fromDate.value = '';
    toDate.value = '';
    await nextTick();
    suppressFilterWatchers = false;

    await loadData(1);
};

// --- Sorting -----------------------------------------------------------------
// Sorting drives the server's sort/direction params and re-fetches from page 1. It must
// NEVER reorder the array in place: the list is server-paginated, so a client-side sort
// would order the 25 rows on screen out of 300 and look like it had worked.
const isSortable = (column: FormResponseSortColumn | null): boolean =>
    !!column && sortableColumns.value.includes(column);

const toggleSort = async (column: FormResponseSortColumn) => {
    if (sort.value === column) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = column;
        direction.value = DEFAULT_DIRECTIONS[column] ?? 'asc';
    }
    await loadData(1);
};

const sortIcon = (column: FormResponseSortColumn | null): string => {
    if (sort.value !== column) return 'bi-arrow-down-up inactive';
    return direction.value === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill';
};

const ariaSort = (column: FormResponseSortColumn | null): 'ascending' | 'descending' | 'none' | undefined => {
    if (!isSortable(column)) return undefined;
    if (sort.value !== column) return 'none';
    return direction.value === 'asc' ? 'ascending' : 'descending';
};

// --- Detail modal ------------------------------------------------------------
const openDetail = async (response: FormResponseRow) => {
    // Show immediately with the row data, then hydrate with the full submission.
    selectedResponse.value = response;
    detail.value = null;
    editStatus.value = response.status;
    editNotes.value = response.admin_notes ?? '';
    showDetailModal.value = true;

    if (!selectedFormId.value) return;

    detailLoading.value = true;
    try {
        const full = await formResponsesStore.fetchResponse(selectedFormId.value, response.id);
        if (full) {
            detail.value = full;
            selectedResponse.value = full;
        }
    } catch (e) {
        detail.value = null;
    } finally {
        detailLoading.value = false;
    }
};

const closeDetail = () => {
    showDetailModal.value = false;
    selectedResponse.value = null;
    detail.value = null;
};

const saveDetail = async () => {
    if (!selectedFormId.value || !selectedResponse.value) return;

    saving.value = true;
    try {
        await formResponsesStore.updateResponse(selectedFormId.value, selectedResponse.value.id, {
            status: editStatus.value,
            admin_notes: editNotes.value
        });
        closeDetail();
        // Re-fetch rather than patching the row: when the list is sorted or filtered by
        // status, a saved row may no longer belong on this page.
        await loadData(paginationOptions.value?.currentPage || 1);
        Swal.fire({ icon: 'success', title: 'Saved!', text: 'Response updated.', timer: 2000, showConfirmButton: false });
    } catch (error: any) {
        Swal.fire({ icon: 'error', title: 'Error!', text: validationText(error, 'Failed to update the response.') });
    } finally {
        saving.value = false;
    }
};

const confirmDelete = async (response: FormResponseRow) => {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text: `Delete the response from ${response.respondent_name || 'this respondent'}? This cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    });

    if (!result.isConfirmed || !selectedFormId.value) return;

    try {
        await formResponsesStore.deleteResponse(selectedFormId.value, response.id);
        await loadData(paginationOptions.value?.currentPage || 1);
        Swal.fire({ icon: 'success', title: 'Deleted!', text: 'The response has been removed.', timer: 2000, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to delete the response.' });
    }
};

// --- CSV export --------------------------------------------------------------
const exportCsv = async () => {
    const id = formResponsesStore.masjidId();
    if (!id || !selectedFormId.value || dateRangeInvalid.value) return;

    exporting.value = true;
    try {
        // Blob fetch (not the JSON ApiService) so the Authorization header carries and
        // the browser downloads the file. The query comes from the same builder the list
        // uses, so the export can only ever contain what is on screen.
        const token = localStorage.getItem(LOCAL_STORAGE_KEYS.token);
        const query = formResponsesStore.buildResponsesQuery(filters.value);
        const resp = await fetch(`/api/admin/masjids/${id}/forms/${selectedFormId.value}/responses/export?${query}`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'text/csv' }
        });
        if (!resp.ok) throw new Error('csv');

        const blob = await resp.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = csvFilename(resp.headers.get('Content-Disposition'));
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not export the responses.' });
    } finally {
        exporting.value = false;
    }
};

/** Prefer the name the server chose; fall back to the form's own name. */
const csvFilename = (contentDisposition: string | null): string => {
    const match = contentDisposition?.match(/filename="?([^";]+)"?/i);
    if (match?.[1]) return match[1];

    const name = (meta.value?.form.name || 'form').replace(/[^A-Za-z0-9]+/g, '-').toLowerCase();
    return `${name}-responses-${new Date().toISOString().slice(0, 10)}.csv`;
};

// --- Formatting --------------------------------------------------------------
const formatDateTime = (iso: string | null): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
    });
};

// amount_due is a decimal:2 column, so it arrives as a DOLLAR string ("150.00") — unlike
// the Donation *_amount fields, it is NOT integer cents. Do not divide by 100.
// The form's own fee currency lives in forms.settings.fee.currency, which this endpoint's
// meta does not carry, so the display currency is USD.
const formatAmount = (amount: string | null): string => {
    if (amount === null || amount === undefined || amount === '') return '—';

    const value = Number(amount);
    if (Number.isNaN(value)) return String(amount);

    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(value);
    } catch (e) {
        return `$${value.toFixed(2)}`;
    }
};

/** Render one submitted answer: booleans as Yes/No, multi-selects joined, blanks dashed. */
const displayValue = (value: any): string => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (Array.isArray(value)) {
        const joined = value.filter(v => v !== null && v !== undefined && v !== '').join(', ');
        return joined || '—';
    }
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};

const repeatableRows = (sectionId: string): Record<string, any>[] => {
    const rows = detail.value?.data?.[sectionId];
    return Array.isArray(rows) ? rows : [];
};

const statusClass = (status: FormResponseStatus): string => {
    switch (status) {
        case 'confirmed': return 'bg-success-subtle text-success';
        case 'new': return 'bg-primary-subtle text-primary';
        case 'waitlisted': return 'bg-warning-subtle text-warning';
        case 'cancelled': return 'bg-secondary-subtle text-secondary';
        default: return 'bg-light text-muted';
    }
};

/** Flatten a 422 {status:'failed', data:{field:[msg]}} body into one readable line. */
const validationText = (error: any, fallback: string): string => {
    const data = error?.response?.data?.data;
    if (data && typeof data === 'object') return Object.values(data).flat().join(' ');
    return error?.response?.data?.message ?? error?.message ?? fallback;
};

// Lock body scroll while the modal is open
watch(showDetailModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<style scoped>
.card {
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

/* Sortable column header — a real button so it is keyboard reachable. */
.sort-header {
    background: none;
    border: 0;
    padding: 0;
    font: inherit;
    color: inherit;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    cursor: pointer;
    white-space: nowrap;
}

.sort-header:hover,
.sort-header.active {
    color: #667eea;
}

.sort-header .sort-icon {
    font-size: 0.7rem;
}

/* The idle double-arrow says "you can sort by this" without competing with the
   column that is actually applied. */
.sort-header .sort-icon.inactive {
    opacity: 0.35;
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
