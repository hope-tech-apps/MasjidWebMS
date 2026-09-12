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
                    v-if="paymentEnabled"
                    class="btn btn-outline-secondary me-2"
                    :disabled="!selectedFormId"
                    @click="showStaffCodes = true"
                    title="Add, revoke and reset the staff cash codes on this form"
                >
                    <i class="bi bi-key me-1" aria-hidden="true"></i>
                    Staff codes
                </button>
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
                            <label class="form-label small text-muted mb-1" for="responses-form">Form</label>
                            <select id="responses-form" class="form-select" v-model.number="selectedFormId">
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
                                    ref="searchInput"
                                    type="search"
                                    class="form-control"
                                    placeholder="Name, email, phone or #number"
                                    aria-describedby="responses-search-help"
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
                            <span id="responses-search-help" class="visually-hidden">
                                Type a hash sign and a number to find a registration by the number on its receipt.
                            </span>
                        </div>
                        <div class="col-md-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="responses-status">Status</label>
                            <select id="responses-status" class="form-select" v-model="statusFilter">
                                <option value="">All statuses</option>
                                <option v-for="s in statuses" :key="s" :value="s" class="text-capitalize">{{ s }}</option>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="responses-from">Submitted from</label>
                            <input id="responses-from" type="date" class="form-control" v-model="fromDate" :max="toDate || undefined">
                        </div>
                        <div class="col-md-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="responses-to">Submitted to</label>
                            <input id="responses-to" type="date" class="form-control" v-model="toDate" :min="fromDate || undefined">
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

                    <!-- The door's filters: only on a form set up to take payment -->
                    <div v-if="paymentEnabled" class="row g-3 mb-3 align-items-end">
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="responses-payment">Payment</label>
                            <select id="responses-payment" class="form-select" v-model="paymentFilter">
                                <option value="">Any payment</option>
                                <option v-for="option in paymentFilterOptions" :key="option" :value="option">
                                    {{ PAYMENT_FILTER_LABELS[option] ?? option }}
                                </option>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="responses-collected">Collected</label>
                            <select id="responses-collected" class="form-select" v-model="collectedFilter">
                                <option value="">Collected or not</option>
                                <option value="no">Not collected yet</option>
                                <option value="yes">Collected</option>
                            </select>
                        </div>
                        <div v-if="staffCodeOptions.length" class="col-md-4 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="responses-holder">Entered with code of</label>
                            <select id="responses-holder" class="form-select" v-model="staffCodeFilter">
                                <option value="">Anyone</option>
                                <option v-for="code in staffCodeOptions" :key="code.id" :value="code.id">
                                    {{ code.holder_name }} (code …{{ code.code_hint }}){{ code.revoked ? ', revoked' : '' }}
                                </option>
                            </select>
                        </div>
                        <div class="col-md-12 col-lg-3">
                            <button
                                type="button"
                                class="btn w-100"
                                :class="doorMode ? 'btn-success' : 'btn-outline-success'"
                                :aria-pressed="doorMode"
                                @click="toggleDoorMode"
                            >
                                <i class="bi bi-door-open me-1" aria-hidden="true"></i>
                                {{ doorMode ? 'At the door: on' : 'At the door' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="doorMode && paymentEnabled" class="alert alert-success py-2 small mb-3" role="status">
                        <strong>At the door.</strong>
                        With the search box empty, this shows registrations that are paid, or have nothing to pay,
                        and have not collected yet. A search (name, email, phone or #number) shows every
                        registration that matches, including unpaid ones and ones already collected, so nobody is
                        registered twice. Cancelled registrations are flagged and cannot be marked collected.
                    </div>

                    <p v-if="dateRangeInvalid" class="text-danger small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        The “to” date cannot be earlier than the “from” date.
                    </p>

                    <!-- Which question are we answering? -->
                    <div class="btn-group mb-3" role="group" aria-label="View mode">
                        <button
                            type="button"
                            class="btn"
                            :class="viewMode === 'submissions' ? 'btn-primary' : 'btn-outline-primary'"
                            :aria-pressed="viewMode === 'submissions'"
                            @click="switchView('submissions')"
                        >
                            Registrations
                        </button>
                        <button
                            type="button"
                            class="btn"
                            :class="viewMode === 'attendees' ? 'btn-primary' : 'btn-outline-primary'"
                            :aria-pressed="viewMode === 'attendees'"
                            @click="switchView('attendees')"
                        >
                            Everyone attending
                        </button>
                        <!--
                            Manara Insights. Hidden without the entitlement, but that gate is
                            cosmetic: masjidStore.masjid is null on the first paint of a hard
                            refresh, and the server's 403 is the real boundary.
                        -->
                        <button
                            v-if="assistantEnabled"
                            type="button"
                            class="btn"
                            :class="viewMode === 'summary' ? 'btn-primary' : 'btn-outline-primary'"
                            :aria-pressed="viewMode === 'summary'"
                            @click="switchView('summary')"
                        >
                            Summary
                        </button>
                    </div>

                    <!-- Roster head count: the numbers an organiser reads first -->
                    <div
                        v-if="viewMode === 'attendees' && rosterSummary"
                        class="alert alert-light border d-flex flex-wrap gap-3 align-items-center mb-3"
                    >
                        <span>
                            <strong>{{ rosterSummary.people }}</strong>
                            {{ rosterSummary.people === 1 ? 'person' : 'people' }}
                            across {{ rosterSummary.submissions }}
                            {{ rosterSummary.submissions === 1 ? 'registration' : 'registrations' }}
                        </span>
                        <span
                            v-for="b in rosterSummary.breakdowns"
                            :key="b.field"
                            class="text-muted small"
                        >
                            {{ b.label }}:
                            <template v-for="(o, i) in b.options" :key="o.value">
                                {{ o.label }} {{ o.count }}<span v-if="i < b.options.length - 1"> · </span>
                            </template>
                        </span>
                        <span v-if="rosterMeta?.form?.capacity" class="text-muted small ms-auto">
                            capacity {{ rosterMeta.form.capacity }}
                        </span>
                    </div>

                    <!-- Summary -->
                    <div v-if="viewMode === 'submissions' && meta" class="alert alert-light border d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                        <span>
                            <strong>{{ meta.form.name }}</strong>
                            · {{ paginationOptions?.itemsTotal ?? 0 }} response{{ (paginationOptions?.itemsTotal ?? 0) === 1 ? '' : 's' }} shown
                        </span>
                        <span class="text-muted small">
                            {{ meta.form.response_count }} total<span v-if="meta.form.capacity"> of {{ meta.form.capacity }} places</span>
                        </span>
                    </div>

                    <!-- Cash by staff member: what each person should hand in. Counted over the filters
                         above except the door preset's own (cashFilters), and it says which apply. -->
                    <div v-if="paymentEnabled && viewMode === 'submissions'" class="card mb-3">
                        <div class="card-header bg-white d-flex flex-wrap gap-2 justify-content-between align-items-center">
                            <button
                                type="button"
                                class="btn btn-link p-0 text-decoration-none fw-semibold text-body"
                                :aria-expanded="cashOpen"
                                aria-controls="responses-cash-by-staff"
                                @click="toggleCash"
                            >
                                <i class="bi me-1" :class="cashOpen ? 'bi-chevron-down' : 'bi-chevron-right'" aria-hidden="true"></i>
                                Cash by staff member
                            </button>
                            <button
                                v-if="cashOpen"
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                :disabled="cashLoading"
                                @click="loadCashTotals"
                            >
                                <span v-if="cashLoading" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                                Refresh
                            </button>
                        </div>

                        <div v-if="cashOpen" id="responses-cash-by-staff" class="card-body">
                            <div v-if="cashLoading && !cashTotals" class="text-center py-3" role="status">
                                <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                                Adding up the cash…
                            </div>

                            <div v-else-if="cashError" class="alert alert-danger py-2 small mb-0" role="alert">{{ cashError }}</div>

                            <template v-else-if="cashTotals">
                                <p class="small mb-2" :class="cashFiltersApplied ? 'text-warning-emphasis' : 'text-muted'">
                                    <template v-if="cashFiltersApplied">
                                        <i class="bi bi-funnel me-1" aria-hidden="true"></i>
                                        <strong>Filtered:</strong> {{ cashFilterSummary.join('; ') }}. Clear filters to count all the cash.
                                    </template>
                                    <template v-else>Counting all the cash on this form.</template>
                                    <template v-if="doorMode">
                                        The door's own filters and its search are not applied here.
                                    </template>
                                </p>
                                <p class="small text-muted">
                                    Cash held is what each person should hand in. Cash taken and then cancelled is shown
                                    apart and is not in "Cash held". Code uses are the code's lifetime count and ignore
                                    the filters.
                                </p>

                                <div v-if="!cashTotals.holders.length" class="text-muted small">No cash has been taken.</div>

                                <div v-else class="table-responsive">
                                    <table class="table table-sm align-middle mb-2">
                                        <caption class="visually-hidden">Cash by staff member</caption>
                                        <thead>
                                            <tr>
                                                <th scope="col">Staff member</th>
                                                <th scope="col" class="text-end">Entries</th>
                                                <th scope="col" class="text-end">Code uses</th>
                                                <th scope="col" class="text-end">Cash held</th>
                                                <th scope="col" class="text-end">Taken, then cancelled</th>
                                                <th scope="col" class="text-end">Total taken</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="holder in cashTotals.holders" :key="`${holder.kind}-${holder.staff_code_id ?? holder.user_id ?? 'none'}`">
                                                <td>
                                                    <strong>{{ holder.holder_name }}</strong>
                                                    <span v-if="holder.revoked" class="badge bg-secondary ms-1">Revoked</span>
                                                    <div class="small text-muted">
                                                        <template v-if="holder.kind === 'code'">Code ends …{{ holder.code_hint }}</template>
                                                        <template v-else-if="holder.kind === 'admin'">Took cash at the table</template>
                                                        <template v-else>Cash with no holder recorded</template>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    {{ holder.submissions }}
                                                    <div class="small text-muted">{{ holder.people }} {{ holder.people === 1 ? 'person' : 'people' }}</div>
                                                </td>
                                                <td class="text-end">
                                                    <template v-if="holder.use_count !== null">
                                                        {{ holder.use_count }}
                                                        <div v-if="usesDiffer(holder)" class="small text-warning-emphasis">
                                                            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Differs from entries
                                                        </div>
                                                    </template>
                                                    <span v-else class="text-muted">—</span>
                                                </td>
                                                <td class="text-end fw-semibold">{{ money(holder.cash_minor, cashTotals.currency) }}</td>
                                                <td class="text-end">
                                                    <template v-if="holder.cancelled_submissions">
                                                        {{ money(holder.cancelled_cash_minor, cashTotals.currency) }}
                                                        <div class="small text-muted">{{ holder.cancelled_submissions }} cancelled</div>
                                                    </template>
                                                    <span v-else class="text-muted">—</span>
                                                </td>
                                                <td class="text-end">{{ money(holder.taken_minor, cashTotals.currency) }}</td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr class="fw-semibold">
                                                <th scope="row">Total</th>
                                                <td class="text-end">{{ cashTotals.totals.submissions }}</td>
                                                <td></td>
                                                <td class="text-end">{{ money(cashTotals.totals.cash_minor, cashTotals.currency) }}</td>
                                                <td class="text-end">{{ money(cashTotals.totals.cancelled_cash_minor, cashTotals.currency) }}</td>
                                                <td class="text-end">{{ money(cashTotals.totals.taken_minor, cashTotals.currency) }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <p class="small text-muted mb-0">
                                    Also paid, over the same filters:
                                    by card {{ money(cashTotals.other_paid.online.total_minor, cashTotals.currency) }}
                                    ({{ cashTotals.other_paid.online.submissions }}),
                                    paid elsewhere {{ money(cashTotals.other_paid.external.total_minor, cashTotals.currency) }}
                                    ({{ cashTotals.other_paid.external.submissions }}).
                                </p>
                            </template>
                        </div>
                    </div>

                    <!-- Loading State -->
                    <div v-if="loading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>

                    <!-- Empty State. Not in Summary: the summary answers "how many" itself,
                         and `responses` there is whatever the list last read. -->
                    <div v-else-if="viewMode !== 'summary' && responses.length === 0" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                        <p class="mb-0">{{ filtersApplied ? 'No responses match these filters' : 'No responses yet' }}</p>
                    </div>

                    <!-- Registrations table: one row per submission -->
                    <div v-else-if="viewMode === 'submissions'" class="table-responsive">
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
                                    <th v-if="paymentEnabled">Payment</th>
                                    <th v-if="paymentEnabled">Collected</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="response in responses"
                                    :key="response.id"
                                    :class="{ 'row-cancelled': paymentEnabled && isCancelled(response) }"
                                >
                                    <td>{{ formatDateTime(response.submitted_at) }}</td>
                                    <td>
                                        <strong>{{ response.respondent_name || '—' }}</strong>
                                        <div class="small text-muted">#{{ response.id }}</div>
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

                                    <!-- Payment: the badge, who holds cash, and the two ways to settle by hand -->
                                    <td v-if="paymentEnabled" class="payment-cell">
                                        <span class="badge" :class="paymentBadgeClass(response)">{{ paymentLabel(response) }}</span>
                                        <div v-if="paymentDetail(response)" class="small text-muted">{{ paymentDetail(response) }}</div>
                                        <div v-if="response.payment_state === 'paid' && response.total_minor !== null && response.total_minor !== undefined" class="small text-muted">
                                            {{ money(response.total_minor, response.currency) }}
                                        </div>
                                        <div v-else-if="unpaidButOwesNothing(response)" class="small text-muted">
                                            Owed nothing when submitted: cancel it and register again at the current price.
                                        </div>
                                        <div v-else-if="response.payment_state === 'unpaid'" class="small text-muted">owes {{ owedLabel(response) }}</div>
                                        <div v-if="cardPageStarted(response)" class="small text-warning-emphasis">
                                            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Card payment started
                                        </div>
                                        <div v-if="canTakePayment(response)" class="d-flex flex-wrap gap-1 mt-1">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-success"
                                                :disabled="busyRowId !== null"
                                                :aria-label="`Take cash for registration #${response.id}`"
                                                @click="takeCash(response)"
                                            >
                                                Take cash
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-secondary"
                                                :disabled="busyRowId !== null"
                                                :aria-label="`Mark registration #${response.id} paid (external)`"
                                                @click="markPaidExternal(response)"
                                            >
                                                Mark paid (external)
                                            </button>
                                        </div>
                                    </td>

                                    <!-- Collected: handed out at the table, stamped by the first press -->
                                    <td v-if="paymentEnabled">
                                        <template v-if="response.collected_at">
                                            <span class="badge bg-success-subtle text-success-emphasis">Collected</span>
                                            <div class="small text-muted">
                                                {{ formatDateTime(response.collected_at) }}<template v-if="response.collected_by?.name"> · {{ response.collected_by.name }}</template>
                                            </div>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-link p-0"
                                                :disabled="busyRowId !== null"
                                                :aria-label="`Undo collected for registration #${response.id}`"
                                                @click="uncollect(response)"
                                            >
                                                Undo
                                            </button>
                                        </template>
                                        <span
                                            v-else-if="isCancelled(response)"
                                            class="badge text-wrap text-start bg-danger-subtle text-danger-emphasis border border-danger-subtle"
                                        >
                                            <i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Cancelled: do not hand out
                                        </span>
                                        <template v-else-if="canCollect(response)">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-success"
                                                :disabled="busyRowId !== null"
                                                :aria-label="`Mark registration #${response.id} collected`"
                                                @click="collect(response)"
                                            >
                                                Mark collected
                                            </button>
                                            <div class="small text-muted">For {{ response.entry_count }} {{ response.entry_count === 1 ? 'person' : 'people' }}</div>
                                        </template>
                                        <span v-else class="small text-muted">Not paid yet</span>
                                    </td>

                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <button
                                                class="btn btn-outline-primary"
                                                @click="openDetail(response)"
                                                title="View Details"
                                                :aria-label="`View details of registration #${response.id}`"
                                            >
                                                <i class="bi bi-eye" aria-hidden="true"></i>
                                            </button>
                                            <!-- A money row is never deleted (only cancelled). Still focusable and
                                                 clickable, so the reason can be read rather than guessed. -->
                                            <button
                                                class="btn btn-outline-danger"
                                                :class="{ 'opacity-50': isMoneyRow(response) }"
                                                :aria-disabled="isMoneyRow(response) ? 'true' : undefined"
                                                :title="isMoneyRow(response) ? DELETE_REFUSED : 'Delete'"
                                                :aria-label="isMoneyRow(response) ? `Delete is not available: ${DELETE_REFUSED}` : `Delete registration #${response.id}`"
                                                @click="confirmDelete(response)"
                                            >
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Everyone attending: one row per PERSON -->
                    <div v-if="viewMode === 'attendees' && !loading" class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th
                                        v-for="col in rosterColumns"
                                        :key="col.key"
                                        :class="{ 'cursor-pointer': rosterSortable.includes(col.key) }"
                                        :aria-sort="ariaSort(col.key as any)"
                                        @click="rosterSortable.includes(col.key) && toggleSort(col.key)"
                                    >
                                        {{ col.label }}
                                        <i v-if="rosterSortable.includes(col.key)" class="bi sort-icon" :class="sortIcon(col.key as any)"></i>
                                    </th>
                                    <th
                                        :aria-sort="ariaSort('respondent_name')"
                                        class="cursor-pointer"
                                        @click="toggleSort('respondent_name')"
                                    >
                                        Registered by
                                        <i class="bi sort-icon" :class="sortIcon('respondent_name')"></i>
                                    </th>
                                    <th>Contact</th>
                                    <th
                                        :aria-sort="ariaSort('status')"
                                        class="cursor-pointer"
                                        @click="toggleSort('status')"
                                    >
                                        Status
                                        <i class="bi sort-icon" :class="sortIcon('status')"></i>
                                    </th>
                                    <th v-if="paymentEnabled">Payment</th>
                                    <th v-if="paymentEnabled">Collected</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!rosterRows.length">
                                    <td :colspan="rosterColumns.length + 3 + (paymentEnabled ? 2 : 0)" class="text-center text-muted py-4">
                                        No one is registered yet.
                                    </td>
                                </tr>
                                <tr
                                    v-for="(row, i) in rosterRows"
                                    :key="`${row.response_id}-${row.entry_index}-${i}`"
                                    :class="{ 'table-warning': row.incomplete }"
                                >
                                    <td v-for="col in rosterColumns" :key="col.key">
                                        <span v-if="row.values[col.key] !== null && row.values[col.key] !== ''">
                                            {{ row.values[col.key] }}
                                        </span>
                                        <span v-else-if="row.incomplete" class="text-muted small fst-italic">
                                            no attendees listed
                                        </span>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td>{{ row.registered_by || '—' }}</td>
                                    <td class="small">
                                        <a v-if="row.registrant_email" :href="`mailto:${row.registrant_email}`">{{ row.registrant_email }}</a>
                                        <span v-if="row.registrant_email && row.registrant_phone" class="text-muted"> · </span>
                                        <a v-if="row.registrant_phone" :href="`tel:${row.registrant_phone}`">{{ row.registrant_phone }}</a>
                                        <span v-if="!row.registrant_email && !row.registrant_phone" class="text-muted">—</span>
                                    </td>
                                    <td>
                                        <span class="badge text-capitalize" :class="statusClass(row.status)">{{ row.status }}</span>
                                    </td>
                                    <td v-if="paymentEnabled" class="small">{{ row.payment || '—' }}</td>
                                    <td v-if="paymentEnabled" class="small">{{ row.collected_at ? formatDateTime(row.collected_at) : 'Not yet' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!--
                        Summary (Manara Insights). Every figure here is printed exactly as the
                        server computed it. Percentages set a bar's width and are labelled as
                        percentages; no COUNT is re-derived in the browser, because two answers
                        to "how many people chose this" on one screen is worse than none.
                    -->
                    <div v-if="viewMode === 'summary' && !loading">
                        <!--
                            The server applies only the search, status and date filters here,
                            so with a door filter on it would describe a different set from the
                            table. Refuse rather than disagree.
                        -->
                        <div v-if="doorFiltersApplied" class="alert alert-warning" role="status">
                            <p class="mb-2">
                                <i class="bi bi-funnel me-1" aria-hidden="true"></i>
                                <strong>The summary is not shown while the door's filters are on.</strong>
                            </p>
                            <p class="mb-2 small">
                                The payment, collected and staff-code filters narrow the table but not the
                                summary, so the two would print different head counts for the same screen.
                                The search, status and date filters do apply.
                            </p>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="clearDoorFilters">
                                Clear the door's filters
                            </button>
                        </div>

                        <div
                            v-else-if="insightsError"
                            class="alert mb-0"
                            :class="insightsRefused ? 'alert-info' : 'alert-danger'"
                            :role="insightsRefused ? 'status' : 'alert'"
                        >
                            <i
                                class="bi me-1"
                                :class="insightsRefused ? 'bi-info-circle' : 'bi-exclamation-triangle'"
                                aria-hidden="true"
                            ></i>{{ insightsError }}
                        </div>

                        <template v-else-if="insights">
                            <!-- Head count: the numbers an organiser reads first -->
                            <div class="alert alert-light border d-flex flex-wrap gap-4 align-items-center mb-3">
                                <span>
                                    <strong>{{ insights.totals.responses }}</strong>
                                    {{ insights.totals.responses === 1 ? 'registration' : 'registrations' }}
                                </span>
                                <span>
                                    <strong>{{ insights.totals.entries }}</strong>
                                    {{ insights.totals.entries === 1 ? 'person' : 'people' }}
                                </span>
                                <span v-if="insights.totals.responses > 1" class="text-muted small">
                                    {{ insights.totals.average_entries_per_response }} people per registration on average
                                </span>
                                <span class="text-muted small ms-auto">
                                    <template v-if="insightsMeta?.filtered">Counting the registrations matching the filters above.</template>
                                    <template v-else>Counting every registration on this form.</template>
                                </span>
                            </div>

                            <!-- Money. Owed, not received: "Cash by staff member" is the other question. -->
                            <div v-if="summaryChargesFees" class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <div class="card h-100">
                                        <div class="card-body">
                                            <div class="fs-4 fw-semibold">{{ dollars(insights.totals.amount_due_total) }}</div>
                                            <div class="small text-muted">Fees these registrations were charged</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card h-100">
                                        <div class="card-body">
                                            <div class="fs-4 fw-semibold">{{ dollars(insights.totals.amount_due_outstanding) }}</div>
                                            <div class="small text-muted">
                                                Owed by registrations not yet confirmed (new or waitlisted)
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <p class="small text-muted mb-0">
                                        Both figures are what was charged, not what has been received — a confirmed
                                        registration that has not paid is not in the second one.<template v-if="paymentEnabled">
                                        For money actually taken, read “Cash by staff member” on the Registrations
                                        view.</template>
                                    </p>
                                </div>
                            </div>

                            <!-- Capacity. Counts the WHOLE form, so it does not move with the filters. -->
                            <div v-if="insights.capacity" class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2">
                                        <span class="fw-semibold">Places</span>
                                        <span class="small text-muted">
                                            {{ insights.capacity.responses }} of {{ insights.capacity.capacity }} taken
                                        </span>
                                    </div>
                                    <div v-if="insights.capacity.percent_full !== null" class="progress" style="height: 12px;">
                                        <div
                                            class="progress-bar"
                                            :class="insights.capacity.remaining === 0 ? 'bg-danger' : 'bg-success'"
                                            role="progressbar"
                                            :style="{ width: `${insights.capacity.percent_full}%` }"
                                            :aria-valuenow="insights.capacity.percent_full"
                                            aria-valuemin="0"
                                            aria-valuemax="100"
                                            :aria-label="`${insights.capacity.percent_full}% of places taken`"
                                        ></div>
                                    </div>
                                    <p class="small text-muted mb-0 mt-2">
                                        <template v-if="insights.capacity.capacity === 0">
                                            This form is set to zero places, so it is not taking registrations.
                                        </template>
                                        <template v-else-if="insights.capacity.remaining === 0">
                                            Full: no places left.
                                        </template>
                                        <template v-else>
                                            {{ insights.capacity.remaining }}
                                            {{ insights.capacity.remaining === 1 ? 'place' : 'places' }} left.
                                        </template>
                                        Places count the whole form, not the filtered set above.
                                    </p>
                                </div>
                            </div>

                            <div v-if="insights.totals.responses === 0" class="text-center py-5 text-muted">
                                <i class="bi bi-bar-chart fs-1 d-block mb-3" aria-hidden="true"></i>
                                <p class="mb-0">
                                    {{ insightsMeta?.filtered ? 'No registrations match these filters' : 'No registrations yet' }}
                                </p>
                                <p class="small mb-0">There is nothing to summarise until somebody submits the form.</p>
                            </div>

                            <template v-else>
                                <div class="row g-3 mb-3">
                                    <!-- By status: for a camp, registrations and people differ -->
                                    <div class="col-lg-5">
                                        <div class="card h-100">
                                            <div class="card-header bg-white fw-semibold">By status</div>
                                            <div class="card-body">
                                                <table class="table table-sm align-middle mb-0">
                                                    <caption class="visually-hidden">Registrations and people by status</caption>
                                                    <thead>
                                                        <tr>
                                                            <th scope="col">Status</th>
                                                            <th scope="col" class="text-end">Registrations</th>
                                                            <th scope="col" class="text-end">People</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr v-for="row in insights.by_status" :key="row.status">
                                                            <td>
                                                                <span class="badge text-capitalize" :class="statusClass(row.status)">{{ row.status }}</span>
                                                            </td>
                                                            <td class="text-end" :class="{ 'text-muted': row.responses === 0 }">{{ row.responses }}</td>
                                                            <td class="text-end" :class="{ 'text-muted': row.entries === 0 }">{{ row.entries }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Timeline: "has registration stalled", not precision -->
                                    <div class="col-lg-7">
                                        <div class="card h-100">
                                            <div class="card-header bg-white fw-semibold">Registrations per day</div>
                                            <div class="card-body">
                                                <div class="insight-timeline" role="list">
                                                    <div
                                                        v-for="point in insights.timeline"
                                                        :key="point.date"
                                                        class="insight-timeline-day"
                                                        role="listitem"
                                                        :aria-label="`${timelineDate(point.date)}: ${point.responses} ${point.responses === 1 ? 'registration' : 'registrations'}, ${point.entries} ${point.entries === 1 ? 'person' : 'people'}`"
                                                    >
                                                        <span class="insight-timeline-count" aria-hidden="true">{{ point.responses }}</span>
                                                        <span
                                                            class="insight-timeline-bar"
                                                            aria-hidden="true"
                                                            :style="{ height: barHeight(point.responses) }"
                                                        ></span>
                                                        <span class="insight-timeline-date" aria-hidden="true">{{ timelineDate(point.date) }}</span>
                                                    </div>
                                                </div>
                                                <p v-if="insights.timeline.length === 1" class="small text-muted mb-0 mt-2">
                                                    Everything so far arrived on one day.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- One card per summarised question -->
                                <div v-if="insights.breakdowns.length" class="row g-3">
                                    <div v-for="breakdown in insights.breakdowns" :key="`${breakdown.section}-${breakdown.field}`" class="col-md-6">
                                        <div class="card h-100">
                                            <div class="card-header bg-white">
                                                <div class="fw-semibold">{{ breakdown.label }}</div>
                                                <div v-if="breakdown.section" class="small text-muted">{{ breakdown.section }}</div>
                                            </div>
                                            <div class="card-body">
                                                <p class="small text-muted">
                                                    {{ breakdown.answered }} answered
                                                    <template v-if="breakdown.type === 'checkboxGroup'">
                                                        · people could pick more than one, so the shares can add up to more than 100%
                                                    </template>
                                                </p>

                                                <template v-if="breakdown.type === 'number'">
                                                    <p class="mb-3">
                                                        <span class="me-3">lowest <strong>{{ breakdown.min }}</strong></span>
                                                        <span class="me-3">average <strong>{{ breakdown.average }}</strong></span>
                                                        <span>highest <strong>{{ breakdown.max }}</strong></span>
                                                    </p>
                                                    <div v-for="bucket in breakdown.buckets" :key="bucket.label" class="mb-2">
                                                        <div class="d-flex justify-content-between small">
                                                            <span>{{ bucket.label }}</span>
                                                            <span class="text-muted">{{ bucket.count }} ({{ share(bucket.count, breakdown.answered) }}%)</span>
                                                        </div>
                                                        <div class="progress" style="height: 8px;">
                                                            <div
                                                                class="progress-bar"
                                                                role="progressbar"
                                                                :style="{ width: `${barWidth(bucket.count, breakdown.answered)}%` }"
                                                                :aria-valuenow="barWidth(bucket.count, breakdown.answered)"
                                                                aria-valuemin="0"
                                                                aria-valuemax="100"
                                                                :aria-label="`${bucket.label}: ${bucket.count} of ${breakdown.answered}`"
                                                            ></div>
                                                        </div>
                                                    </div>
                                                </template>

                                                <template v-else>
                                                    <p v-if="!breakdown.options.length" class="small text-muted mb-0">
                                                        This question has no options on record to count against.
                                                    </p>
                                                    <!--
                                                        Everybody left it blank. Said, rather than
                                                        drawn as a stack of empty bars under a count
                                                        that seems to disagree with them: the figure
                                                        above counts everyone the question was PUT
                                                        to, which for an optional dropdown is not
                                                        the same as everyone who picked something.
                                                    -->
                                                    <p v-else-if="nobodyChoseAnOption(breakdown.options)" class="small text-muted mb-2">
                                                        Nobody chose an option here. The number above counts everyone this
                                                        question was put to, not everyone who answered it.
                                                    </p>
                                                    <!-- Keyed by position too: a field's options are authored copy and
                                                         two of them can share a blank value. -->
                                                    <div v-for="(option, oi) in breakdown.options" :key="`${option.value}-${oi}`" class="mb-2">
                                                        <div class="d-flex justify-content-between small">
                                                            <span>{{ option.label }}</span>
                                                            <span class="text-muted">{{ option.count }} ({{ share(option.count, breakdown.answered) }}%)</span>
                                                        </div>
                                                        <div class="progress" style="height: 8px;">
                                                            <div
                                                                class="progress-bar"
                                                                role="progressbar"
                                                                :style="{ width: `${barWidth(option.count, breakdown.answered)}%` }"
                                                                :aria-valuenow="barWidth(option.count, breakdown.answered)"
                                                                aria-valuemin="0"
                                                                aria-valuemax="100"
                                                                :aria-label="`${option.label}: ${option.count} of ${breakdown.answered}`"
                                                            ></div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Why a question may be missing from the cards above -->
                                <p class="small text-muted mt-3 mb-0">
                                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                                    <template v-if="insights.breakdowns.length">
                                        A question is only summarised once at least three people have answered it, so
                                        nobody can be identified from a total. Written answers are never summarised.
                                    </template>
                                    <template v-else>
                                        No answers are summarised for this form yet. Only multiple-choice and number
                                        questions are ever summarised — written answers such as names, allergies and
                                        medical notes are never read — and a question appears here only once at least
                                        three people have answered it, so nobody can be identified from a total.
                                    </template>
                                </p>
                            </template>

                            <!-- The server's own words about what it reads. Printed verbatim. -->
                            <p class="small text-muted mt-3 mb-0">{{ insights.privacy_note }}</p>
                        </template>

                        <!--
                            THE TERMINAL STATE. Every branch above states a positive fact —
                            a refusal, a failure, a payload — and without this one the panel
                            renders NOTHING whenever the summary is null and no error was
                            ever set. An admin who has just pressed Summary then reads a
                            blank white area, and a blank reads as broken data rather than
                            as a state; it is the same failure the merge picker was already
                            taught ("a blank is the thing the operator reads straight past").

                            Three ways to get here, and they do not deserve one sentence:

                            1. The date range is back to front. loadData() returns at its
                               `dateRangeInvalid` guard BEFORE it reaches loadInsights(), so
                               no request is made, no error is worded, and nothing is stale
                               — it simply never ran. The fix is in the filter row above,
                               so this points there rather than offering a button that
                               would hit the same guard. Switching forms while the range is
                               invalid lands here too: the watcher clears the error and
                               reloads, the reload refuses, and the form-id check on
                               `insights` nulls the previous form's summary — correctly,
                               since printing it under a different form's name is worse.

                            2. This masjid is not bound yet (fetchInsights returns early
                               with no id), so nothing was asked and nothing failed.

                            3. Anything else that left the slice empty without throwing.

                            2 and 3 are recoverable by simply asking again, so they get the
                            button instead of an apology.
                        -->
                        <div v-else class="alert alert-secondary mb-0" role="status">
                            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                            <template v-if="dateRangeInvalid">
                                The summary was not loaded, because the “to” date is earlier than the
                                “from” date. Correct the dates above and it will load.
                            </template>
                            <template v-else>
                                The summary has not been loaded yet.
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary ms-2"
                                    @click="loadData(1)"
                                >
                                    Load the summary
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </PageDataContainer>

        <!-- Response Details Modal -->
        <Teleport to="body">
            <div
                v-if="showDetailModal && selectedResponse"
                ref="detailRoot"
                class="modal fade show d-block"
                role="dialog"
                aria-modal="true"
                aria-labelledby="response-detail-title"
                tabindex="-1"
                style="background: rgba(0,0,0,0.5);"
                @click.self="closeDetail"
                @keydown="onDetailKeydown"
            >
                <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 id="response-detail-title" class="modal-title">
                                <i class="bi bi-card-checklist me-2" aria-hidden="true"></i>
                                {{ selectedResponse.respondent_name || 'Response' }}
                                <span class="text-muted fw-normal small">#{{ selectedResponse.id }}</span>
                            </h5>
                            <button ref="detailCloseButton" type="button" class="btn-close" aria-label="Close registration details" @click="closeDetail"></button>
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

                            <!-- Payment and the door, on a form set up to take payment -->
                            <template v-if="paymentEnabled">
                                <h6 class="text-muted text-uppercase small mb-3">Payment</h6>
                                <dl class="row mb-2">
                                    <dt class="col-sm-4 text-muted fw-normal small">Registration no.</dt>
                                    <dd class="col-sm-8">#{{ selectedResponse.id }}</dd>

                                    <dt class="col-sm-4 text-muted fw-normal small">Payment</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge" :class="paymentBadgeClass(selectedResponse)">{{ paymentLabel(selectedResponse) }}</span>
                                        <span v-if="paymentDetail(selectedResponse)" class="small text-muted ms-1">{{ paymentDetail(selectedResponse) }}</span>
                                        <div v-if="cardPageStarted(selectedResponse)" class="small text-warning-emphasis mt-1">
                                            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                            <template v-if="!isCancelled(selectedResponse)">
                                                A card payment page was opened for this registration. "Take cash" and
                                                "Mark paid (external)" check with Stripe first and close it if it is still
                                                open. If they have just paid by card, nothing is recorded.
                                            </template>
                                            <template v-else>
                                                A card payment page was opened for this registration. "Check its card
                                                payment page is closed" asks Stripe, and closes it if it is still open.
                                            </template>
                                        </div>
                                        <div v-if="unpaidButOwesNothing(selectedResponse)" class="small text-muted mt-1">
                                            This registration owed nothing when it was submitted, so it cannot be paid or
                                            marked collected here. To charge it, cancel it and register again at the
                                            current price.
                                        </div>
                                    </dd>

                                    <template v-if="(selectedResponse.fee_covered_minor ?? 0) > 0">
                                        <dt class="col-sm-4 text-muted fw-normal small">Card fee covered</dt>
                                        <dd class="col-sm-8">{{ money(selectedResponse.fee_covered_minor ?? 0, selectedResponse.currency) }}</dd>
                                    </template>

                                    <template v-if="selectedResponse.payment_state === 'paid' && selectedResponse.total_minor !== null && selectedResponse.total_minor !== undefined">
                                        <dt class="col-sm-4 text-muted fw-normal small">Total paid</dt>
                                        <dd class="col-sm-8">{{ money(selectedResponse.total_minor, selectedResponse.currency) }}</dd>
                                    </template>

                                    <template v-if="selectedResponse.paid_at">
                                        <dt class="col-sm-4 text-muted fw-normal small">Paid at</dt>
                                        <dd class="col-sm-8">{{ formatDateTime(selectedResponse.paid_at) }}</dd>
                                    </template>

                                    <template v-if="selectedResponse.marked_paid_by?.name">
                                        <dt class="col-sm-4 text-muted fw-normal small">Marked paid by</dt>
                                        <dd class="col-sm-8">{{ selectedResponse.marked_paid_by.name }}</dd>
                                    </template>

                                    <dt class="col-sm-4 text-muted fw-normal small">Collected</dt>
                                    <dd class="col-sm-8">
                                        <template v-if="selectedResponse.collected_at">
                                            {{ formatDateTime(selectedResponse.collected_at) }}<template v-if="selectedResponse.collected_by?.name"> by {{ selectedResponse.collected_by.name }}</template>
                                        </template>
                                        <span v-else class="text-muted">Not yet</span>
                                    </dd>

                                    <template v-if="selectedResponse.status_changed_by?.name">
                                        <dt class="col-sm-4 text-muted fw-normal small">Status last changed</dt>
                                        <dd class="col-sm-8">
                                            {{ formatDateTime(selectedResponse.status_changed_at ?? null) }} by {{ selectedResponse.status_changed_by.name }}
                                        </dd>
                                    </template>
                                </dl>

                                <div class="d-flex flex-wrap gap-2 mb-4">
                                    <button
                                        v-if="canCollect(selectedResponse)"
                                        type="button"
                                        class="btn btn-sm btn-success"
                                        :disabled="busyRowId !== null"
                                        @click="collect(selectedResponse)"
                                    >
                                        Mark collected
                                    </button>
                                    <button
                                        v-if="selectedResponse.collected_at"
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        :disabled="busyRowId !== null"
                                        @click="uncollect(selectedResponse)"
                                    >
                                        Undo collected
                                    </button>
                                    <button
                                        v-if="canTakePayment(selectedResponse)"
                                        type="button"
                                        class="btn btn-sm btn-outline-success"
                                        :disabled="busyRowId !== null"
                                        @click="takeCash(selectedResponse)"
                                    >
                                        Take cash
                                    </button>
                                    <button
                                        v-if="canTakePayment(selectedResponse)"
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        :disabled="busyRowId !== null"
                                        @click="markPaidExternal(selectedResponse)"
                                    >
                                        Mark paid (external)
                                    </button>
                                    <!-- The same check whatever an earlier answer said: a closed page's id
                                         stays on the row, so only asking Stripe can tell. A cancel that could
                                         not close the page offers to close it again in its own answer
                                         (showTriageAnswer()). -->
                                    <button
                                        v-if="canReclose(selectedResponse)"
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        :disabled="busyRowId !== null"
                                        @click="recloseCardPage(selectedResponse)"
                                    >
                                        Check its card payment page is closed
                                    </button>
                                </div>
                            </template>

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

                                <!-- Uploaded files. Fetched with the bearer token rather than
                                     linked: these sit on a private disk with no public URL. -->
                                <template v-if="detail.attachments?.length">
                                    <h6 class="text-muted mb-2">Attachments</h6>
                                    <ul class="list-group list-group-flush mb-4">
                                        <li
                                            v-for="attachment in detail.attachments"
                                            :key="attachment.id"
                                            class="list-group-item d-flex align-items-center justify-content-between px-0"
                                        >
                                            <span class="text-truncate me-3">
                                                <span class="d-block">{{ attachment.file_name }}</span>
                                                <small class="text-muted">{{ attachmentLabel(attachment) }}</small>
                                            </span>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary flex-shrink-0"
                                                :disabled="downloadingAttachmentId === attachment.id"
                                                @click="downloadAttachment(attachment)"
                                            >
                                                {{ downloadingAttachmentId === attachment.id ? 'Downloading…' : 'Download' }}
                                            </button>
                                        </li>
                                    </ul>
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
                                    <label class="form-label small text-muted mb-1" for="response-edit-status">Status</label>
                                    <select id="response-edit-status" class="form-select text-capitalize" v-model="editStatus">
                                        <option v-for="s in statuses" :key="s" :value="s" class="text-capitalize">{{ s }}</option>
                                    </select>
                                </div>
                                <div
                                    v-if="paymentEnabled && isMoneyRow(selectedResponse) && editStatus === 'cancelled' && selectedResponse.status !== 'cancelled'"
                                    class="col-md-8 small text-muted d-flex align-items-end"
                                >
                                    Cancelling keeps this registration and its payment on record. Cash moves to its holder's
                                    "cancelled" column; a card payment is refunded in Stripe, not here.
                                </div>
                                <div class="col-12">
                                    <label class="form-label small text-muted mb-1" for="response-edit-notes">Notes</label>
                                    <!-- maxlength mirrors the API's admin_notes rule so a long
                                         note is stopped here rather than coming back as a 422. -->
                                    <textarea id="response-edit-notes" class="form-control" rows="3" maxlength="5000" v-model="editNotes" placeholder="Internal notes — not shown to the respondent"></textarea>
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

        <FormStaffCodesModal
            v-if="selectedFormId"
            :show="showStaffCodes"
            :form-id="selectedFormId"
            :form-name="formMeta?.form?.name ?? null"
            @close="showStaffCodes = false"
            @changed="staffCodesChanged = true"
        />
    </div>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed, watch, nextTick } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import FormStaffCodesModal from '@/components/forms/FormStaffCodesModal.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import {
    FormCashHolder,
    FormCashTotals,
    FormCollectedFilter,
    FormInsightOption,
    FormInsights,
    FormInsightsMeta,
    FormOption,
    FormPaymentFilter,
    FormResponseActionResult,
    FormResponseAttachment,
    FormResponseColumn,
    FormResponseDetail,
    FormResponseFilters,
    FormResponseRow,
    FormResponseSortColumn,
    FormResponseSortDirection,
    FormResponseStatus,
    FormResponsesMeta,
    FormResponseUpdatePayload,
    FormRosterColumn,
    FormRosterMeta,
    FormRosterSummary,
    formatMinorAmount
} from '@/core/types/data/masjid-related/Form';
import { useFormResponsesStore } from '@/stores/masjid/formResponsesStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { LOCAL_STORAGE_KEYS } from '@/core/constants/appConfigConstants';
import { serverMessage } from '@/core/helpers/serverMessage';
import { trapTab } from '@/core/helpers/focusTrap';
import Swal from 'sweetalert2';

// Store
const formResponsesStore = useFormResponsesStore();
const masjidStore = useMasjidStore();

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

/**
 * The payment filter in plain words. `online` is every card registration, paid or not
 * (the filter is the method), so it says so.
 */
const PAYMENT_FILTER_LABELS: Record<FormPaymentFilter, string> = {
    paid: 'Paid',
    unpaid: 'Not paid yet',
    settled: 'Paid, or nothing to pay',
    cash: 'Cash',
    online: 'Card (paid or not)',
    external: 'Paid (external)'
};
const FALLBACK_PAYMENT_FILTERS: FormPaymentFilter[] = ['paid', 'unpaid', 'settled', 'cash', 'online', 'external'];

/** FormResponsesController::destroy()'s refusal, word for word. */
const DELETE_REFUSED = 'A registration with a payment is never deleted. Cancel it instead, and add a note.';

// State
const loading = ref(false);
const saving = ref(false);
const exporting = ref(false);
const bootstrapped = ref(false);
const selectedFormId = ref<number | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);

// Filters — every one of these is applied by the server.
const searchQuery = ref('');
const statusFilter = ref<FormResponseStatus | ''>('');
const fromDate = ref('');
const toDate = ref('');
const paymentFilter = ref<FormPaymentFilter | ''>('');
const collectedFilter = ref<FormCollectedFilter | ''>('');
const staffCodeFilter = ref<number | ''>('');
const sort = ref<FormResponseSortColumn>('submitted_at');
const direction = ref<FormResponseSortDirection>('desc');
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

/**
 * "At the door". With the search box empty: registrations paid (or owing nothing) and not
 * collected yet, the ones still to serve. While searching: EVERY match, so an unpaid card
 * payer is found and flagged, and a family already served shows its "Collected" stamp.
 * Hidden, either would be registered, and paid for, a second time.
 */
const doorMode = ref(false);

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
const detailRoot = ref<HTMLElement | null>(null);
const detailCloseButton = ref<HTMLButtonElement | null>(null);
// Whatever opened the detail gets focus back when it closes.
let detailReturnFocus: HTMLElement | null = null;

// The door
const busyRowId = ref<number | null>(null);
const showStaffCodes = ref(false);
const staffCodesChanged = ref(false);

// Cash by staff member
const cashOpen = ref(false);
const cashLoading = ref(false);
const cashTotals = ref<FormCashTotals | null>(null);
const cashError = ref('');

/**
 * Manara Insights. A 403 is not a fault — it means the masjid has not bought the tier —
 * so it is worded and shown in blue; anything else is a real failure and stays red.
 */
const insightsError = ref('');
const insightsRefused = ref(false);
const ASSISTANT_REFUSED = 'Summaries are part of Manara Assistant. Ask your Manara contact to switch it on.';

/**
 * Which question the screen is answering.
 *  'submissions' — who filled in the form (one row per submission)
 *  'attendees'   — who is actually coming (one row per PERSON)
 *  'summary'     — what the answers add up to (Manara Insights; no rows at all)
 * For a camp the first two are different numbers: one parent registering four people is
 * one submission and four attendees, and a coordinator needs the second one to run
 * check-in. The third shares this screen rather than living on its own route because its
 * whole claim is that it describes the set the filter row above is showing.
 */
const viewMode = ref<'submissions' | 'attendees' | 'summary'>('submissions');

// Computed
const formOptions = computed<FormOption[]>(() => formResponsesStore.formOptions);
const meta = computed<FormResponsesMeta | undefined>(() => formResponsesStore.responsesMeta);
const responses = computed<FormResponseRow[]>(() => (formResponsesStore.responsesPaginated?.data as FormResponseRow[]) || []);
const statuses = computed<FormResponseStatus[]>(() => meta.value?.statuses ?? FALLBACK_STATUSES);
const sortableColumns = computed<FormResponseSortColumn[]>(() => meta.value?.sortable ?? FALLBACK_SORTABLE);
const columns = computed<FormResponseColumn[]>(() => meta.value?.columns ?? []);

const rosterMeta = computed<FormRosterMeta | undefined>(() => formResponsesStore.rosterMeta);

/**
 * The meta that describes the form on screen, for its door filters and payment block. The
 * list and the roster each send them for the form they were read for, but a form switch
 * re-reads only the view on screen, so the other may still describe the last form. The
 * view on screen is asked first, as the fresher.
 */
const formMeta = computed<FormResponsesMeta | FormRosterMeta | null>(() => {
    const candidates = viewMode.value === 'attendees' ? [rosterMeta.value, meta.value] : [meta.value, rosterMeta.value];
    return candidates.find(candidate => candidate?.form?.id === selectedFormId.value) ?? null;
});

/** The payment block, only when it describes the form on screen. */
const paymentMeta = computed(() => formMeta.value?.payment ?? null);

/**
 * Form::hasPaymentSettings(). Every payment column, filter and action is gated on it: a
 * fee form that never took payment (the camp) keeps the screen it always had.
 */
const paymentEnabled = computed(() => !!paymentMeta.value?.enabled);
const paymentFilterOptions = computed<FormPaymentFilter[]>(() => formMeta.value?.payment_filters ?? FALLBACK_PAYMENT_FILTERS);
const staffCodeOptions = computed(() => paymentMeta.value?.codes ?? []);

// --- attendee roster ---
const rosterRows = computed<any[]>(() => (formResponsesStore.rosterPaginated?.data as any[]) || []);
const rosterColumns = computed<FormRosterColumn[]>(() => rosterMeta.value?.columns ?? []);
const rosterSummary = computed<FormRosterSummary | undefined>(() => rosterMeta.value?.summary);
/** Roster columns are sortable too — the server orders the flattened rows. */
const rosterSortable = computed<string[]>(() => rosterMeta.value?.sortable ?? []);

// --- Manara Insights ---
const insightsMeta = computed<FormInsightsMeta | null>(() => formResponsesStore.insightsMeta);

/**
 * Only ever the summary of the form on screen. The payload names the form it describes,
 * so a summary left over from the previous pick can never be printed under the new one's
 * name — the same reason formMeta() checks the form id.
 */
const insights = computed<FormInsights | null>(() =>
    insightsMeta.value?.form?.id === selectedFormId.value ? formResponsesStore.insights : null);

/**
 * The Assistant entitlement, which is the tier Insights are sold in. Cosmetic on purpose:
 * masjidStore.masjid is null on the first paint of a hard refresh, so this hides the
 * button a moment longer than it needs to, and the server's 403 is the real boundary.
 */
const assistantEnabled = computed(() => !!masjidStore.masjid?.assistant_enabled);

/**
 * The door's own filters. The insights endpoint accepts them and then ignores them
 * (FormInsightsController applies q / status / from / to only), so while one is on, the
 * summary would describe a different set of registrations from the table the admin was
 * just looking at. The panel refuses to render rather than quietly disagreeing; when the
 * server routes insights through FormResponsesController::query() this guard, and the
 * refusal it shows, both come out.
 */
const doorFiltersApplied = computed(() =>
    !!paymentFilter.value || !!collectedFilter.value || staffCodeFilter.value !== '');

/**
 * Whether the summary shows money at all. Read from the figures rather than from
 * paymentEnabled: a fee form that never switched card payment on (the camp) still records
 * amount_due and still shows an Amount due column in the table, and after a form switch
 * made from this view the list's payment meta describes the previous form, so gating on it
 * would blank the money on a form that plainly charges.
 */
const summaryChargesFees = computed(() =>
    (insights.value?.totals.amount_due_total ?? 0) > 0 ||
    (insights.value?.totals.amount_due_outstanding ?? 0) > 0);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    // The summary is one payload, never paginated. PageDataContainer hides the pager
    // entirely when this is undefined, which is what a page-less view wants.
    if (viewMode.value === 'summary') return undefined;

    const source = viewMode.value === 'attendees'
        ? formResponsesStore.rosterPaginated
        : formResponsesStore.responsesPaginated;

    if (!source) return undefined;
    return {
        currentPage: source.current_page,
        itemsTotal: source.total,
        perPage: source.per_page
    };
});

const filters = computed<FormResponseFilters>(() => ({
    q: searchQuery.value,
    status: statusFilter.value,
    from: fromDate.value,
    to: toDate.value,
    payment: paymentFilter.value,
    collected: collectedFilter.value,
    staff_code_id: staffCodeFilter.value,
    sort: sort.value,
    direction: direction.value
}));

const filtersApplied = computed(() =>
    !!searchQuery.value || !!statusFilter.value || !!fromDate.value || !!toDate.value ||
    !!paymentFilter.value || !!collectedFilter.value || staffCodeFilter.value !== '');

// The API rejects to < from with a 422; catch it here so the admin gets an explanation
// instead of a validation error.
const dateRangeInvalid = computed(() => !!fromDate.value && !!toDate.value && toDate.value < fromDate.value);

/**
 * The filters the cash totals are counted over: the list's, less the door preset's own
 * (paid, not collected yet) and its search. Cash walk-ups are marked collected as their
 * bracelets go out, so counted over the door's list, each holder's "Cash held" at hand-in
 * would leave out nearly everything they took.
 */
const cashFilters = computed<FormResponseFilters>(() =>
    doorMode.value ? { ...filters.value, q: '', payment: '', collected: '' } : filters.value);

/** The filters narrowing the cash totals, in words, so a filtered total never passes for the whole. */
const cashFilterSummary = computed<string[]>(() => {
    const applied = cashFilters.value;
    const parts: string[] = [];

    if (applied.q.trim()) parts.push(`matching "${applied.q.trim()}"`);
    if (applied.status) parts.push(`status ${applied.status}`);
    if (applied.from) parts.push(`submitted from ${applied.from}`);
    if (applied.to) parts.push(`submitted to ${applied.to}`);
    if (applied.payment) parts.push(`payment: ${(PAYMENT_FILTER_LABELS[applied.payment] ?? applied.payment).toLowerCase()}`);
    if (applied.collected) parts.push(applied.collected === 'no' ? 'not collected yet' : 'collected');
    if (applied.staff_code_id !== '' && applied.staff_code_id !== null && applied.staff_code_id !== undefined) {
        const code = staffCodeOptions.value.find(option => option.id === Number(applied.staff_code_id));
        parts.push(code ? `entered with ${code.holder_name}'s code` : 'one staff code');
    }

    return parts;
});

const cashFiltersApplied = computed(() => cashFilterSummary.value.length > 0);

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

// Debounced search, 500ms. At the door, the payment and collected filters follow the search box.
watch(searchQuery, () => {
    if (suppressFilterWatchers) return;
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        if (doorMode.value) await applyDoorFilters();
        await reloadAll();
    }, 500);
});

// A payment, collected or status filter picked by hand ends the door preset: the list is
// the admin's own from then on.
watch([statusFilter, paymentFilter, collectedFilter], () => {
    if (suppressFilterWatchers) return;
    doorMode.value = false;
});

// The select/date filters re-fetch immediately.
watch([statusFilter, fromDate, toDate, paymentFilter, collectedFilter, staffCodeFilter], async () => {
    if (suppressFilterWatchers) return;
    await reloadAll();
});

// Switching forms means a different schema and a different result set. Another form's
// payment filters and code holders mean nothing here, and a filter whose control is
// hidden (a form with no payment) would narrow the list with nothing on screen to say so.
watch(selectedFormId, async () => {
    if (suppressFilterWatchers) return;

    await quietly(() => {
        paymentFilter.value = '';
        collectedFilter.value = '';
        staffCodeFilter.value = '';
        doorMode.value = false;
    });
    cashTotals.value = null;
    insightsError.value = '';
    insightsRefused.value = false;

    await reloadAll();
});

// The codes dialog changed a holder: the list's meta carries the holder filter's options.
watch(showStaffCodes, async (open) => {
    if (!open && staffCodesChanged.value) {
        staffCodesChanged.value = false;
        await loadData(paginationOptions.value?.currentPage || 1);
    }
});

// Methods
/** Assign filter refs without each watcher firing its own request. */
const quietly = async (assign: () => void) => {
    suppressFilterWatchers = true;
    assign();
    await nextTick();
    suppressFilterWatchers = false;
};

const bootstrap = async () => {
    if (!formResponsesStore.masjidId()) return;

    try {
        const options = await formResponsesStore.fetchFormOptions();
        if (options.length) {
            // Assign quietly, then load once below — letting the watcher fire as well
            // would mean two identical requests on every first paint.
            await quietly(() => {
                selectedFormId.value = options[0].id;
            });
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
        if (viewMode.value === 'summary') {
            await loadInsights();
        } else if (viewMode.value === 'attendees') {
            await formResponsesStore.fetchRoster(selectedFormId.value, filters.value, page);
        } else {
            await formResponsesStore.fetchResponses(selectedFormId.value, filters.value, page);
        }
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: serverMessage(error, 'Failed to load responses.') });
    } finally {
        loading.value = false;
    }
};

/**
 * The summary, over the same filters. It shares loadData()'s debounce rather than adding
 * a second one: this is an unpaginated read of every matching response, so one request
 * per keystroke on a festival form is the heaviest thing on the screen.
 *
 * Errors are worded here instead of thrown, so a masjid without the Assistant tier reads
 * an explanation rather than a red failure dialog.
 */
const loadInsights = async () => {
    if (!selectedFormId.value) return;

    // Nothing is fetched while a door filter is on: the endpoint would answer about a set
    // the admin is not looking at, and the panel refuses to show that.
    if (doorFiltersApplied.value) {
        insightsError.value = '';
        return;
    }

    insightsError.value = '';
    insightsRefused.value = false;

    try {
        await formResponsesStore.fetchInsights(selectedFormId.value, filters.value);
    } catch (error: any) {
        insightsRefused.value = error?.response?.status === 403;
        insightsError.value = insightsRefused.value
            ? ASSISTANT_REFUSED
            : serverMessage(error, 'Could not put the summary together.');
    }
};

/** The list from page 1, and the cash totals when they are open: both follow the filters. */
const reloadAll = async () => {
    await loadData(1);
    if (cashOpen.value) await loadCashTotals();
};

/**
 * Flip between the questions. The filters are deliberately shared, but the sort key is
 * reset because the views sort by different things — an attendee column means nothing to
 * the submission list, and vice versa, and the summary sorts nothing at all.
 */
const switchView = async (mode: 'submissions' | 'attendees' | 'summary') => {
    if (viewMode.value === mode) return;

    viewMode.value = mode;
    sort.value = undefined as unknown as FormResponseSortColumn;
    direction.value = 'desc';

    await loadData(1);
};

/**
 * Clear only the door's three filters, from the summary's refusal. Everything else the
 * admin set (the search, the status, the dates) is left alone: those the summary honours,
 * and clearing them would answer a question they did not ask.
 */
const clearDoorFilters = async () => {
    await quietly(() => {
        paymentFilter.value = '';
        collectedFilter.value = '';
        staffCodeFilter.value = '';
        doorMode.value = false;
    });

    await reloadAll();
};

const pageChange = async (data: PageChangeData) => {
    await loadData(data.toPage);
};

const clearFilters = async () => {
    // Resetting the refs would otherwise wake both the debounced search watcher and the
    // filter watcher — several requests for one click. Silence them and re-fetch once.
    if (searchTimeout) clearTimeout(searchTimeout);
    await quietly(() => {
        searchQuery.value = '';
        statusFilter.value = '';
        fromDate.value = '';
        toDate.value = '';
        paymentFilter.value = '';
        collectedFilter.value = '';
        staffCodeFilter.value = '';
        doorMode.value = false;
    });

    await reloadAll();
};

// --- The door preset ---------------------------------------------------------

/**
 * The door preset's payment and collected filters, which follow the search box: the
 * registrations still to serve while it is empty, every match while searching. A search
 * narrowed to "not collected" would hide a family already served, and they would be
 * registered, and pay, a second time.
 */
const doorFilters = (): { payment: FormPaymentFilter | ''; collected: FormCollectedFilter | '' } =>
    searchQuery.value.trim() ? { payment: '', collected: '' } : { payment: 'settled', collected: 'no' };

const applyDoorFilters = async () => {
    const wanted = doorFilters();

    if (paymentFilter.value !== wanted.payment || collectedFilter.value !== wanted.collected) {
        await quietly(() => {
            paymentFilter.value = wanted.payment;
            collectedFilter.value = wanted.collected;
        });
    }
};

const toggleDoorMode = async () => {
    if (searchTimeout) clearTimeout(searchTimeout);

    if (doorMode.value) {
        await quietly(() => {
            doorMode.value = false;
            paymentFilter.value = '';
            collectedFilter.value = '';
        });
    } else {
        const wanted = doorFilters();

        await quietly(() => {
            doorMode.value = true;
            statusFilter.value = '';
            paymentFilter.value = wanted.payment;
            collectedFilter.value = wanted.collected;

            // The door works the registrations table, where its actions are.
            if (viewMode.value !== 'submissions') {
                viewMode.value = 'submissions';
                sort.value = 'submitted_at';
                direction.value = 'desc';
            }
        });
    }

    await reloadAll();

    if (doorMode.value) searchInput.value?.focus();
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

// --- Payment reading ---------------------------------------------------------

const isCancelled = (row: FormResponseRow): boolean => row.status === 'cancelled';

/** A registration with a payment leg: never deleted, only cancelled. */
const isMoneyRow = (row: FormResponseRow): boolean => !!row.payment_method;

/** The badge: Unpaid / Paid by card / Paid in cash / Paid (external), or nothing owed. */
const paymentLabel = (row: FormResponseRow): string => {
    if (row.payment_state === 'unpaid') return 'Unpaid';
    if (row.payment_state !== 'paid') return 'Nothing to pay';

    switch (row.payment_method) {
        case 'online': return 'Paid by card';
        case 'cash': return 'Paid in cash';
        case 'external': return 'Paid (external)';
        default: return 'Paid';
    }
};

/** Whose cash it is, or who marked it paid: the line under the badge. */
const paymentDetail = (row: FormResponseRow): string | null => {
    if (row.payment_state !== 'paid') return null;

    if (row.payment_method === 'cash') {
        if (row.staff_code?.holder_name) return `held by ${row.staff_code.holder_name}`;
        if (row.marked_paid_by?.name) return `taken at the table by ${row.marked_paid_by.name}`;
        return null;
    }

    if (row.payment_method === 'external' && row.marked_paid_by?.name) {
        return `marked by ${row.marked_paid_by.name}`;
    }

    return null;
};

const paymentBadgeClass = (row: FormResponseRow): string => {
    if (row.payment_state === 'paid') return 'bg-success-subtle text-success-emphasis';
    if (row.payment_state === 'unpaid') return 'bg-warning-subtle text-warning-emphasis';
    return 'bg-light text-muted';
};

/**
 * An unpaid card registration whose Stripe payment page was opened at some point. Worded
 * as something that happened, never as "open": a page lives 30 minutes, and a closed
 * page's id stays on the row, so the server cannot say whether it is open now.
 */
const cardPageStarted = (row: FormResponseRow): boolean => row.payment_state === 'unpaid' && row.card_page_opened === true;

/** What an unpaid row owes: the cents snapshot, else the legacy dollar amount. */
const owedLabel = (row: FormResponseRow): string =>
    row.amount_due_minor !== null && row.amount_due_minor !== undefined
        ? money(row.amount_due_minor, row.currency)
        : formatAmount(row.amount_due);

/**
 * Whether the row owes more than nothing, read the way the door's refusal reads it
 * (FormResponse::owedMinor(), where `<= 0` is "nothing to pay"): the cents snapshot, else
 * the legacy dollar amount, and neither one recorded reads as nothing.
 */
const owesMoney = (row: FormResponseRow): boolean =>
    row.amount_due_minor !== null && row.amount_due_minor !== undefined
        ? Number(row.amount_due_minor) > 0
        : Number(row.amount_due ?? 0) > 0;

/**
 * "Take cash" and "Mark paid (external)": an unpaid registration that owes something. Both
 * answer "This registration has nothing to pay." for one that owes nothing, so neither is
 * offered there, and unpaidButOwesNothing() says what to do instead.
 */
const canTakePayment = (row: FormResponseRow): boolean =>
    paymentEnabled.value && row.payment_state === 'unpaid' && !isCancelled(row) && owesMoney(row);

/**
 * Unpaid, yet owing nothing: submitted while the form had no price, and read as unpaid
 * because the form charges now. It can be neither paid nor marked collected, so the way to
 * charge it is to cancel it and register again at the current price.
 */
const unpaidButOwesNothing = (row: FormResponseRow): boolean =>
    paymentEnabled.value && row.payment_state === 'unpaid' && !isCancelled(row) && !owesMoney(row);

const canCollect = (row: FormResponseRow): boolean =>
    paymentEnabled.value && !row.collected_at && !isCancelled(row) && row.settled === true;

/** A cancelled card registration whose page is on record: saying "cancelled" again checks it is closed. */
const canReclose = (row: FormResponseRow): boolean => isCancelled(row) && cardPageStarted(row);

const personName = (row: FormResponseRow): string => row.respondent_name || `registration #${row.id}`;

// --- The door's actions ------------------------------------------------------

/**
 * One door action on one row: the answer replaces the row where it stands, rather than
 * re-fetching the page, so a mistaken "Mark collected" can be undone on the spot even
 * when the list is filtered to rows not yet collected. A refusal shows in the server's
 * own words ("Not paid yet.", "Do not take a second payment.").
 */
const runRowAction = async (
    row: FormResponseRow,
    action: (formId: number) => Promise<FormResponseActionResult>,
    failureTitle: string,
    fallback: string
): Promise<FormResponseActionResult | null> => {
    if (!selectedFormId.value || busyRowId.value !== null) return null;

    busyRowId.value = row.id;

    try {
        const result = await action(selectedFormId.value);
        applyRow(result.data);
        await keepFocusInDetail();
        return result;
    } catch (error: any) {
        // A refusal (422) or an unconfirmed Stripe close (503) usually means the row moved
        // since the list was read: paid by card a minute ago, cancelled at the next table.
        // Show it as it now stands first, so "already paid by card" never sits beside
        // "Unpaid" and a Take cash button.
        const status = error?.response?.status;
        if (status === 422 || status === 503) await refreshRow(row.id);
        await keepFocusInDetail();

        Swal.fire({ icon: 'error', title: failureTitle, text: serverMessage(error, fallback) });
        return null;
    } finally {
        busyRowId.value = null;
    }
};

/**
 * An action can remove the button that had focus (Take cash, once paid). Inside the open
 * detail, focus then goes back to the dialog rather than to the page under its backdrop.
 */
const keepFocusInDetail = async () => {
    await nextTick();

    const root = detailRoot.value;
    if (showDetailModal.value && root && !root.contains(document.activeElement)) root.focus();
};

/** Re-read one row and put it in place. Best effort: on failure the row stays as it was. */
const refreshRow = async (responseId: number) => {
    if (!selectedFormId.value) return;

    try {
        const fresh = await formResponsesStore.fetchResponse(selectedFormId.value, responseId);
        if (fresh) applyRow(fresh);
    } catch (e) {
        // The refusal the caller shows next is what matters.
    }
};

/** Put a row as the server now has it into the list, and into the open detail modal. */
const applyRow = (updated: FormResponseDetail) => {
    // List rows stay light: no answers, no attachments.
    const row: Record<string, any> = { ...updated };
    delete row.data;
    delete row.attachments;

    const page = formResponsesStore.responsesPaginated;
    if (page?.data) {
        const index = page.data.findIndex(candidate => candidate.id === updated.id);
        if (index >= 0) page.data[index] = { ...page.data[index], ...row } as FormResponseRow;
    }

    if (selectedResponse.value?.id === updated.id) {
        selectedResponse.value = { ...selectedResponse.value, ...updated };
        if (detail.value) detail.value = { ...detail.value, ...updated };
    }
};

const collect = async (row: FormResponseRow) => {
    const result = await runRowAction(
        row,
        formId => formResponsesStore.collectResponse(formId, row.id),
        'Not marked collected',
        'Could not mark this registration collected.'
    );

    if (result) toast('success', result.message ?? 'Marked collected.');
};

const uncollect = async (row: FormResponseRow) => {
    // Undoing erases who handed the bracelets out and when (FormResponse::uncollect()), and
    // puts the row back on the door's list, so it is never one stray tap.
    const when = row.collected_at ? ` at ${formatDateTime(row.collected_at)}` : '';
    const who = row.collected_by?.name ? ` by ${row.collected_by.name}` : '';

    const confirmed = await Swal.fire({
        title: `Undo the collection of #${row.id}?`,
        text: `It was marked collected${when}${who}. Undoing removes ${who ? 'their name and the time' : 'the time'}, and #${row.id} can be handed out again.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Undo collection',
        cancelButtonText: 'Keep it collected'
    });

    if (!confirmed.isConfirmed) return;

    const result = await runRowAction(
        row,
        formId => formResponsesStore.uncollectResponse(formId, row.id),
        'Not undone',
        'Could not undo the collection.'
    );

    if (result) toast('success', result.message ?? 'Collection undone.');
};

/**
 * The emails a payment by hand sends (FormResponsesController::settleByHand(), then
 * FormNotifier::submitted()): the payer's paid receipt and, for a card registration whose
 * coordinators were not told at submit, the coordinators' notice too.
 */
const settleEmailsLine = (row: FormResponseRow): string =>
    'If they gave an email address and this form sends confirmations, they are emailed a paid receipt, with the group link if the form has one.' +
    (row.payment_method === 'online' ? ' The coordinators are emailed about this registration too.' : '');

const takeCash = async (row: FormResponseRow) => {
    const owed = owedLabel(row);

    const confirmed = await Swal.fire({
        title: `Take cash for #${row.id}?`,
        text: `Collect ${owed} in cash from ${personName(row)}. This first closes any card payment page still open for this registration. If Stripe says they have just paid by card, nothing is recorded and you are told so. ${settleEmailsLine(row)}`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: `Record ${owed} in cash`,
        cancelButtonText: 'Cancel'
    });

    if (!confirmed.isConfirmed) return;

    const result = await runRowAction(
        row,
        formId => formResponsesStore.takeCash(formId, row.id),
        'Nothing was recorded',
        'Could not record the cash.'
    );

    if (result) {
        toast('success', result.message ?? 'Cash recorded.');
        refreshCashIfOpen();
    }
};

const markPaidExternal = async (row: FormResponseRow) => {
    const owed = owedLabel(row);

    const confirmed = await Swal.fire({
        title: `Mark #${row.id} paid (external)?`,
        text: `Only when ${personName(row)} has already paid ${owed} somewhere else, such as the Wix payment page. It is recorded as paid (external) and marked by you, after any card payment page still open for this registration is closed. ${settleEmailsLine(row)}`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Mark paid (external)',
        cancelButtonText: 'Cancel'
    });

    if (!confirmed.isConfirmed) return;

    const result = await runRowAction(
        row,
        formId => formResponsesStore.markPaidExternal(formId, row.id),
        'Nothing was recorded',
        'Could not mark this registration paid.'
    );

    if (result) {
        toast('success', result.message ?? 'Marked paid.');
        refreshCashIfOpen();
    }
};

/**
 * Say "cancelled" again: the server asks Stripe about a cancelled registration's card page,
 * closes it if it is still open, and answers with what it found (`card_page`).
 */
const recloseCardPage = async (row: FormResponseRow) => {
    const result = await runRowAction(
        row,
        formId => formResponsesStore.updateResponse(formId, row.id, { status: 'cancelled' }),
        'The card payment page was not checked',
        'Could not check the card payment page.'
    );

    if (result) await showTriageAnswer(result, 'check');
};

/** How the screen answers a triage save or a card page check. */
type TriageAnswer = {
    icon: 'success' | 'warning';
    title: string;
    text: string;
    /** A plain save with nothing to say: a short note that closes itself. */
    quiet: boolean;
    /** Offer "Close its card payment page again", which says "cancelled" once more. */
    retry: boolean;
};

/** The one answer the server leaves to the screen to word (card_page 'unchecked'). */
const CARD_PAGE_UNCHECKED = 'Cancelled, but this organisation has no Stripe account on record, so its card payment '
    + 'page could not be checked or closed, and it may still take a payment. Look for one in its Stripe dashboard, '
    + 'and refund it there if it should not stand.';

/**
 * The words for an answer. A request saying "cancelled" is answered with `card_page`, and
 * that alone decides what is said and whether closing the page again is offered: never
 * what this screen remembers of an earlier answer, which a payment landing in between
 * would make wrong. The server's own message is used wherever it sends one.
 */
const triageAnswer = (result: FormResponseActionResult, asked: 'save' | 'check'): TriageAnswer => {
    const told = (icon: 'success' | 'warning', text: string, retry = false): TriageAnswer => ({
        icon,
        title: icon === 'warning' ? 'Check this registration' : (asked === 'check' ? 'Checked' : 'Saved'),
        text,
        quiet: false,
        retry
    });
    const plainSave: TriageAnswer = { icon: 'success', title: 'Saved!', text: 'Response updated.', quiet: true, retry: false };

    switch (result.card_page) {
        case 'closed':
            return told('success', result.message ?? 'Cancelled, and its card payment page is closed.');
        case 'paid_on_stripe':
            return told('warning', result.message
                ?? 'This registration has been paid by card, and cancelling does not refund it. Refund it in Stripe if it should not stand.');
        case 'unconfirmed':
            return told('warning', result.message
                ?? 'Cancelled, but its card payment page could not be closed, so it may still take a payment.', true);
        case 'unchecked':
            return told('warning', CARD_PAGE_UNCHECKED);
        case 'none':
            if (result.message) return told(result.warning ? 'warning' : 'success', result.message);
            return asked === 'check' ? told('success', 'No card payment page is open for this registration.') : plainSave;
        default:
            // Not a cancel: another status, or notes alone.
            return result.message ? told(result.warning ? 'warning' : 'success', result.message) : plainSave;
    }
};

/**
 * Show an answer. A warning the admin must act on stays until dismissed, and a close
 * Stripe did not confirm offers to close the page again, right there.
 */
const showTriageAnswer = async (result: FormResponseActionResult, asked: 'save' | 'check' = 'save') => {
    const answer = triageAnswer(result, asked);

    if (answer.quiet) {
        Swal.fire({ icon: answer.icon, title: answer.title, text: answer.text, timer: 2000, showConfirmButton: false });
        return;
    }

    const choice = await Swal.fire({
        icon: answer.icon,
        title: answer.title,
        text: answer.text,
        showCancelButton: answer.retry,
        confirmButtonText: answer.retry ? 'Close its card payment page again' : 'OK',
        cancelButtonText: 'Not now'
    });

    if (answer.retry && choice.isConfirmed) await recloseCardPage(result.data);
};

// --- Cash by staff member ----------------------------------------------------

const toggleCash = async () => {
    cashOpen.value = !cashOpen.value;
    if (cashOpen.value) await loadCashTotals();
};

const loadCashTotals = async () => {
    if (!selectedFormId.value || !paymentEnabled.value || dateRangeInvalid.value) return;

    cashLoading.value = true;
    cashError.value = '';

    try {
        cashTotals.value = await formResponsesStore.fetchCashTotals(selectedFormId.value, cashFilters.value);
    } catch (error) {
        cashError.value = serverMessage(error, 'Could not add up the cash.');
    } finally {
        cashLoading.value = false;
    }
};

const refreshCashIfOpen = () => {
    if (cashOpen.value) loadCashTotals();
};

/**
 * Counted over no filters, every cash entry a code made is in the totals, cancelled or
 * not, so its lifetime use count should equal its entries. A difference is worth a look.
 */
const usesDiffer = (holder: FormCashHolder): boolean =>
    !cashFiltersApplied.value &&
    holder.kind === 'code' &&
    holder.use_count !== null &&
    holder.use_count !== holder.submissions + holder.cancelled_submissions;

// --- Detail modal ------------------------------------------------------------
const openDetail = async (response: FormResponseRow) => {
    detailReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    // Show immediately with the row data, then hydrate with the full submission.
    selectedResponse.value = response;
    detail.value = null;
    editStatus.value = response.status;
    editNotes.value = response.admin_notes ?? '';
    showDetailModal.value = true;

    await nextTick();
    detailCloseButton.value?.focus();

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

    // Back to what opened it, while that is still on the page (a save re-reads the list).
    const returnTo = detailReturnFocus;
    detailReturnFocus = null;
    if (returnTo) nextTick(() => { if (returnTo.isConnected) returnTo.focus(); });
};

/** Escape closes the detail (not mid-save); Tab and Shift+Tab stay inside it. */
const onDetailKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        event.stopPropagation();
        if (!saving.value) closeDetail();
        return;
    }

    trapTab(event, detailRoot.value);
};

const saveDetail = async () => {
    if (!selectedFormId.value || !selectedResponse.value) return;

    // Only what changed: re-sending an unchanged "cancelled" is not a no-op (it retries
    // closing a card page), so that has its own button.
    const payload: FormResponseUpdatePayload = {};
    if (editStatus.value !== selectedResponse.value.status) payload.status = editStatus.value;
    if (editNotes.value !== (selectedResponse.value.admin_notes ?? '')) payload.admin_notes = editNotes.value;
    if (!Object.keys(payload).length) return;

    saving.value = true;
    let result: FormResponseActionResult | null = null;
    try {
        result = await formResponsesStore.updateResponse(selectedFormId.value, selectedResponse.value.id, payload);
        closeDetail();
        // Re-fetch rather than patching the row: when the list is sorted or filtered by
        // status, a saved row may no longer belong on this page. A cancelled cash row also
        // moves to its holder's "cancelled" column.
        await reloadAfterSave();
    } catch (error: any) {
        Swal.fire({ icon: 'error', title: 'Error!', text: serverMessage(error, 'Failed to update the response.') });
    } finally {
        saving.value = false;
    }

    // Once `saving` has cleared: an answer offering to close the card page again waits on
    // the admin, and that close is a request of its own.
    if (result) await showTriageAnswer(result, 'save');
};

const reloadAfterSave = async () => {
    await loadData(paginationOptions.value?.currentPage || 1);
    refreshCashIfOpen();
};

const confirmDelete = async (response: FormResponseRow) => {
    if (isMoneyRow(response)) {
        Swal.fire({ icon: 'info', title: 'This registration cannot be deleted', text: DELETE_REFUSED });
        return;
    }

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
        Swal.fire({ icon: 'error', title: 'Not deleted', text: serverMessage(error, 'Failed to delete the response.') });
    }
};

// --- Attachments -------------------------------------------------------------
const downloadingAttachmentId = ref<number | null>(null);

/** "PDF · 240 KB" — enough to tell a scan from a document before opening it. */
const attachmentLabel = (attachment: FormResponseAttachment): string => {
    const kb = Math.max(1, Math.round(attachment.size_bytes / 1024));
    const size = kb < 1024 ? `${kb} KB` : `${(kb / 1024).toFixed(1)} MB`;
    const type = attachment.mime_type.split('/').pop()?.split('.').pop()?.toUpperCase() ?? 'FILE';

    return `${type} · ${size}`;
};

/**
 * Same blob fetch the CSV export uses, and for the same reason: the file lives on a
 * private disk behind auth:sanctum + admin + tenant, so the Authorization header has
 * to travel with the request. A plain link would 401.
 */
const downloadAttachment = async (attachment: FormResponseAttachment) => {
    downloadingAttachmentId.value = attachment.id;
    try {
        const token = localStorage.getItem(LOCAL_STORAGE_KEYS.token);
        const resp = await fetch(attachment.download_url, {
            headers: { Authorization: `Bearer ${token}` }
        });
        if (!resp.ok) throw new Error('attachment');

        const url = URL.createObjectURL(await resp.blob());
        const a = document.createElement('a');
        a.href = url;
        a.download = attachment.file_name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not download the attachment.' });
    } finally {
        downloadingAttachmentId.value = null;
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
        // Export whatever is on screen: the submission list, or the attendee check-in
        // sheet. Exporting the other one would be a quiet trap.
        const path = viewMode.value === 'attendees' ? 'responses/roster/export' : 'responses/export';
        const resp = await fetch(`/api/admin/masjids/${id}/forms/${selectedFormId.value}/${path}?${query}`, {
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

/** The money leg's *_minor fields are integer CENTS, unlike amount_due. */
const money = (minor: number | null | undefined, currency: string | null | undefined): string =>
    formatMinorAmount(minor, currency || 'usd');

// --- Summary formatting ------------------------------------------------------
// Everything printed in the summary panel is the server's own figure. What follows turns
// those figures into a width or a label; none of it re-counts anything.

/**
 * The insights totals are DOLLARS — FormInsights sums the decimal `amount_due` column,
 * not the `*_minor` cents fields — so they go through the dollar formatter, never
 * formatMinorAmount. That also means they can differ from the cash panel on a form whose
 * rows carry both column generations; the panel says which question each one answers.
 */
const dollars = (amount: number | null | undefined): string =>
    amount === null || amount === undefined ? '—' : formatAmount(String(amount));

/**
 * One count as a percentage of the people who answered that question. `answered` is never
 * below three on a breakdown the server sent (a smaller group is suppressed outright), but
 * the zero guard stays: a division by zero would print NaN% on a real screen.
 */
const share = (count: number, answered: number): number =>
    answered > 0 ? Math.round((count / answered) * 100) : 0;

/**
 * The bar's width. Capped at 100 because a "choose any" question lets one person pick
 * several options, so its counts can add up to more than the number who answered.
 */
const barWidth = (count: number, answered: number): number => Math.min(100, share(count, answered));

/**
 * A choice question that everybody left blank — every option counted zero.
 *
 * The server counts `answered` as the number of submissions that CARRIED the question,
 * blank value included (FormInsights::choiceBreakdown takes `count($values)`), so an
 * optional dropdown nobody filled in comes back as "12 answered" above twelve empty
 * bars. That is a card which looks like a bug in the figures rather than the plain fact
 * that nobody chose anything, and it is precisely the "question nobody answered" case.
 *
 * This is not a re-derived COUNT — it prints no number and contradicts none. It reads the
 * server's own zeros and says what they mean.
 */
const nobodyChoseAnOption = (options: FormInsightOption[]): boolean =>
    options.length > 0 && options.every(option => option.count === 0);

/**
 * The busiest day in the timeline, purely to scale the bars against each other. It is a
 * drawing constant, never shown as a figure — the counts printed are the server's.
 */
const timelinePeak = computed<number>(() => {
    const points = insights.value?.timeline ?? [];
    return points.reduce((peak, point) => Math.max(peak, point.responses), 0);
});

/** A single day, or a day nobody registered on, must still draw something visible. */
const barHeight = (responses: number): string => {
    const peak = timelinePeak.value;
    if (peak <= 0) return '2px';

    return `${Math.max(2, Math.round((responses / peak) * 100))}%`;
};

/** A timeline day. 'unknown' is the server's word for a response with no submitted_at. */
const timelineDate = (date: string): string => {
    if (date === 'unknown') return 'Not recorded';

    // 'YYYY-MM-DD' parsed as-is would be read as UTC midnight and print the day before in
    // the Americas, so the parts are handed to the Date constructor directly.
    const [year, month, day] = date.split('-').map(Number);
    if (!year || !month || !day) return date;

    return new Date(year, month - 1, day).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
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
        // The -emphasis text colours: plain text-success on success-subtle is 3.5:1, under
        // AA, and text-warning on warning-subtle is far worse.
        case 'confirmed': return 'bg-success-subtle text-success-emphasis';
        case 'new': return 'bg-primary-subtle text-primary-emphasis';
        case 'waitlisted': return 'bg-warning-subtle text-warning-emphasis';
        case 'cancelled': return 'bg-secondary-subtle text-secondary-emphasis';
        default: return 'bg-light text-muted';
    }
};

const toast = (icon: 'success' | 'error', text: string) => {
    Swal.fire({ icon, text, timer: 2500, showConfirmButton: false, toast: true, position: 'top-end' });
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

/* The summary's registrations-per-day bars. Bootstrap has no chart, and a chart library
   would be a new dependency for one row of rectangles, so this is flexbox: the bars are
   sized as a percentage of a fixed-height track, and the whole row scrolls sideways
   rather than squeezing a festival's sixty days into the card's width. */
.insight-timeline {
    display: flex;
    align-items: flex-end;
    gap: 0.35rem;
    height: 140px;
    overflow-x: auto;
    padding-bottom: 0.25rem;
}

/* Grid rather than a column flexbox so the middle row is a definite height: the bar's
   percentage height then scales against the track alone, and the count above it and the
   date below it keep their space instead of being pushed out by a tall bar. */
.insight-timeline-day {
    display: grid;
    grid-template-rows: auto 1fr auto;
    justify-items: center;
    height: 100%;
    min-width: 2.4rem;
    flex: 1 0 auto;
}

.insight-timeline-count {
    font-size: 0.7rem;
    color: #5c636a;
    line-height: 1;
    margin-bottom: 0.15rem;
}

.insight-timeline-bar {
    display: block;
    align-self: end;
    width: 100%;
    max-width: 1.6rem;
    background-color: #667eea;
    border-radius: 2px 2px 0 0;
}

.insight-timeline-date {
    font-size: 0.65rem;
    color: #5c636a;
    white-space: nowrap;
    margin-top: 0.25rem;
}

/* A cancelled registration at the door reads as set aside, not as one to serve. */
.row-cancelled > td {
    background-color: #f8f9fa;
    /* #6c757d on #f8f9fa is 4.45:1, just under AA; this is 5.8:1. */
    color: #5c636a;
}

.payment-cell {
    min-width: 11rem;
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
