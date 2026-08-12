<template>
    <div>
        <PageDataContainer
            :title="offeringLabel"
            :paginationOptions="paginationOptions"
            :buttonProps="{ title: 'Add New', type: 'button', class: 'btn btn-success', disabled: false }"
            @headerButtonClick="openCreateModal"
            @pageChange="pageChange"
        >
            <div class="container w-100">
                <!-- Stats -->
                <div class="row mb-4">
                    <div class="col-md-4 col-lg-3">
                        <div class="stats-card">
                            <div class="stats-icon">
                                <i class="bi bi-mortarboard-fill"></i>
                            </div>
                            <div class="stats-content">
                                <div class="stats-label">Total {{ offeringLabel }}</div>
                                <div class="stats-value">{{ paginationOptions?.itemsTotal || 0 }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-lg-5">
                        <div class="search-box">
                            <i class="bi bi-search search-icon"></i>
                            <input
                                type="text"
                                class="search-input"
                                placeholder="Search by name or slug..."
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
                    </div>
                    <div class="col-md-3 col-lg-3">
                        <label class="form-label small text-muted mb-1">Kind</label>
                        <!-- Options come from the server's Offering::KINDS, never a local copy. -->
                        <select class="form-select" v-model="filters.kind" @change="loadData(1)">
                            <option value="">Every kind</option>
                            <option v-for="kind in kinds" :key="kind" :value="kind">
                                {{ offeringKindLabel(kind) }}
                            </option>
                        </select>
                    </div>
                    <div class="col-md-3 col-lg-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="offerings_active_only"
                                v-model="filters.active_only"
                                @change="loadData(1)"
                            >
                            <!--
                                "Active", not "Open for registration". The server filter is
                                scopeActive() — `where('is_active', true)` — which says nothing
                                about the opens_at/closes_at window, so this switch happily keeps
                                an offering whose window has closed. Labelling it "open" made the
                                filter agree with a badge that was also wrong; the badge now reads
                                is_open, and this label now says what it actually filters on.
                            -->
                            <label class="form-check-label" for="offerings_active_only">
                                Active only
                            </label>
                        </div>
                    </div>
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
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="loadData(currentPage)">Retry</button>
                </div>

                <!-- Empty: filters are hiding everything -->
                <div v-else-if="offerings.length === 0 && filtersActive" class="text-center py-5 text-muted">
                    <i class="bi bi-funnel fs-1 d-block mb-3"></i>
                    <p class="mb-3">Nothing matches this filter.</p>
                    <button class="btn btn-sm btn-outline-secondary" @click="clearFilters">Clear filters</button>
                </div>

                <!-- Empty: nothing has ever been created -->
                <div v-else-if="offerings.length === 0" class="text-center py-5">
                    <i class="bi bi-mortarboard fs-1 d-block mb-3 text-muted"></i>
                    <h6 class="mb-2">No {{ offeringLabel.toLowerCase() }} yet</h6>
                    <p class="text-muted mb-2 mx-auto empty-copy">
                        An offering is one thing people can register for — a semester, a
                        camp, an admission round, a membership year. It carries the sign-up
                        form families fill in, an optional {{ groupsTerm.toLowerCase() }} its
                        roster is written into, and how many seats there are.
                    </p>
                    <p class="text-muted small mb-0 mx-auto empty-copy">
                        Prices live on its fee plans, which you add after creating it.
                    </p>
                </div>

                <!-- The list -->
                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Kind</th>
                                <th>Pricing</th>
                                <th class="text-center">Seats</th>
                                <th class="text-center">Sign-ups</th>
                                <th>Registration window</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="offering in offerings" :key="offering.id">
                                <td>
                                    <router-link
                                        class="fw-semibold text-decoration-none"
                                        :to="{ name: 'masjid.offeringDetail', params: { offeringId: offering.id } }"
                                    >
                                        {{ offering.name }}
                                    </router-link>
                                    <div class="text-muted small font-monospace">{{ offering.slug }}</div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        {{ offeringKindLabel(offering.kind) }}
                                    </span>
                                </td>
                                <td>
                                    <!--
                                        One chip per ACTIVE plan, each printing that
                                        plan's own `amount_minor` in its own currency.
                                        Deactivated plans are counted, not priced: they
                                        are kept forever so in-flight registrations
                                        resolve, but they are not what anything costs
                                        today.
                                    -->
                                    <div v-if="activePlans(offering).length" class="d-flex flex-wrap gap-1">
                                        <span
                                            v-for="plan in activePlans(offering).slice(0, 3)"
                                            :key="plan.id"
                                            class="badge bg-light text-dark border fw-normal"
                                            :title="plan.label"
                                        >
                                            <span class="fw-semibold">{{ formatMinor(plan.amount_minor, plan.currency) }}</span>
                                            <span class="text-muted ms-1">{{ describePlanTerms(plan) }}</span>
                                        </span>
                                        <span v-if="activePlans(offering).length > 3" class="badge bg-light text-muted border fw-normal">
                                            +{{ activePlans(offering).length - 3 }} more
                                        </span>
                                    </div>
                                    <span v-else class="text-muted small">
                                        <i class="bi bi-exclamation-circle me-1"></i>No active fee plan
                                    </span>
                                </td>
                                <!--
                                    THE SEAT COUNTER, not the number of sign-ups. It counts
                                    registrations that currently HOLD a seat; a cancelled one
                                    has given its seat back and is not in here. Capacity is
                                    checked against this number, so it is the one that
                                    answers "is this class full?".
                                -->
                                <td class="text-center" :title="seatsTitle">
                                    <span class="fw-semibold">{{ offering.registration_count }}</span>
                                    <span class="text-muted"> / {{ offering.capacity === null ? '∞' : offering.capacity }}</span>
                                </td>
                                <!-- Every registration row ever attached, cancelled included. -->
                                <td class="text-center text-muted" title="Every sign-up ever attached to this offering, cancelled and waitlisted included.">
                                    {{ offering.registrations_count ?? '—' }}
                                </td>
                                <td class="small">
                                    <div>
                                        <span class="text-muted">Opens</span>
                                        {{ offering.opens_at ? formatDateTime(offering.opens_at) : 'anytime' }}
                                    </div>
                                    <div>
                                        <span class="text-muted">Closes</span>
                                        {{ offering.closes_at ? formatDateTime(offering.closes_at) : 'never' }}
                                    </div>
                                </td>
                                <td class="text-center">
                                    <!-- is_open, not is_active: a closed window still has is_active=true and used to show a green Open badge -->
                                    <span v-if="offering.is_open" class="badge bg-success-subtle text-success">Open</span>
                                    <span v-else-if="offering.closed_reason === 'not_yet_open'" class="badge bg-info-subtle text-info">Scheduled</span>
                                    <span v-else-if="offering.closed_reason === 'closed'" class="badge bg-secondary-subtle text-secondary">Window closed</span>
                                    <span v-else class="badge bg-secondary-subtle text-secondary">Closed</span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <router-link
                                            class="btn btn-outline-primary"
                                            :to="{ name: 'masjid.offeringDetail', params: { offeringId: offering.id } }"
                                            title="Open"
                                        >
                                            <i class="bi bi-box-arrow-in-right"></i>
                                        </router-link>
                                        <button class="btn btn-outline-secondary" @click="openEditModal(offering)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" @click="confirmDelete(offering)" title="Remove">
                                            <i class="bi bi-archive"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p class="text-muted small mt-2 mb-0">
                        <strong>Seats</strong> counts sign-ups that currently hold a place, which
                        is what capacity is measured against. <strong>Sign-ups</strong> counts
                        every registration ever attached, cancelled ones included.
                    </p>
                </div>
            </div>
        </PageDataContainer>

        <!-- Create / Edit -->
        <Teleport to="body">
            <div
                v-if="showFormModal"
                class="modal fade show d-block"
                tabindex="-1"
                style="background: rgba(0,0,0,0.5);"
                @click.self="closeFormModal"
            >
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-mortarboard me-2"></i>
                                {{ isEditForm ? 'Edit' : 'Add New' }}
                            </h5>
                            <button type="button" class="btn-close" @click="closeFormModal"></button>
                        </div>
                        <form @submit.prevent="submitForm">
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <label class="form-label">Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" v-model.trim="form.name" @input="syncSlug" required>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Kind <span class="text-danger">*</span></label>
                                        <select class="form-select" v-model="form.kind" required>
                                            <option v-for="kind in kinds" :key="kind" :value="kind">
                                                {{ offeringKindLabel(kind) }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Slug <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control font-monospace" v-model.trim="form.slug" required>
                                        <div class="form-text">
                                            Lowercase letters, numbers and hyphens. This identifies the
                                            offering to the registration API, so changing it on a live
                                            offering breaks any link already shared.
                                            <br>
                                            <!--
                                                Deliberately NOT "the public address people register at".
                                                Nothing in this application publishes a registration page:
                                                the only intake is POST /api/v1/offerings/{slug}/register,
                                                and no section type, page or public route calls it. An
                                                admin told this slug was a public address reasonably
                                                publishes the intake FORM instead — which writes a
                                                FormResponse and never a Registration, so no seat is taken
                                                and no money moves while every screen agrees nothing
                                                happened.
                                            -->
                                            <span class="text-warning-emphasis">
                                                Note: the public sign-up page for offerings is not live yet.
                                                Registrations reach this screen through the registration API,
                                                not by publishing the sign-up form on a page.
                                            </span>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Sign-up form <span class="text-danger">*</span></label>
                                        <select class="form-select" v-model="form.intake_form_id" required>
                                            <option :value="null" disabled>Choose a form…</option>
                                            <option v-for="option in formOptions" :key="option.id" :value="option.id">
                                                {{ option.name }}{{ option.is_active ? '' : ' (inactive)' }}
                                            </option>
                                        </select>

                                        <!--
                                            An empty picker used to be a dead end: this field is required and
                                            Add stays disabled without it, so a freshly onboarded organisation
                                            with no forms yet could fill in every other field and never learn
                                            why the button would not enable. Nothing on screen said so, and a
                                            FAILED fetch looked identical to "you have none".
                                        -->
                                        <div v-if="formOptionsFailed" class="alert alert-warning py-2 px-3 mt-2 mb-0 small">
                                            Your sign-up forms could not be loaded, so this list is empty —
                                            it does not mean you have none. Close this window and try again.
                                        </div>
                                        <div v-else-if="formOptions.length === 0" class="alert alert-info py-2 px-3 mt-2 mb-0 small">
                                            You do not have a sign-up form yet, and one is required before an
                                            offering can be created. Build one under
                                            <strong>Web Pages Management</strong>: open or add a page, add a
                                            <strong>Form</strong> section, and save. It will appear here.
                                        </div>

                                        <div v-else class="form-text">
                                            Required: every registration is validated against this form's
                                            questions and stored as one of its responses.
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Roster {{ groupsTerm.toLowerCase() }}</label>
                                        <input
                                            type="text"
                                            class="form-control form-control-sm mb-2"
                                            placeholder="Type to search…"
                                            v-model="groupSearch"
                                        >
                                        <select class="form-select" v-model="form.group_id">
                                            <option :value="null">None — collect money only</option>
                                            <!--
                                                The already-attached group, when the current
                                                search does not contain it. Without this option
                                                the select would render blank while still
                                                holding the id, and an admin could not tell
                                                whether a roster was attached at all.
                                            -->
                                            <option
                                                v-if="attachedGroupOption"
                                                :value="attachedGroupOption.id"
                                            >
                                                {{ attachedGroupOption.name }}
                                            </option>
                                            <option v-for="group in groupChoices" :key="group.id" :value="group.id">
                                                {{ group.name }}
                                            </option>
                                        </select>
                                        <div class="form-text">
                                            Confirmed registrants are added to this
                                            {{ groupsTerm.toLowerCase() }}. Leave it empty for an offering
                                            that has no roster.
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Capacity</label>
                                        <input type="number" min="0" step="1" class="form-control" v-model="capacityInput">
                                        <div class="form-text">Empty means unlimited.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Opens</label>
                                        <input type="datetime-local" class="form-control" v-model="form.opens_at">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Closes</label>
                                        <input type="datetime-local" class="form-control" v-model="form.closes_at">
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="offering_is_active" v-model="form.is_active">
                                            <label class="form-check-label" for="offering_is_active">
                                                Open for registration
                                            </label>
                                        </div>
                                        <div class="form-text">
                                            Turning this off stops new sign-ups and leaves every existing
                                            registration exactly as it is.
                                        </div>
                                    </div>

                                    <div v-if="isEditForm" class="col-12">
                                        <div class="alert alert-light border small mb-0">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Lowering capacity below the seats already taken is allowed: it stops
                                            new sign-ups without revoking anyone's place. Seats taken cannot be
                                            edited from here at all.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="closeFormModal" :disabled="saving">
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="btn btn-success"
                                    :disabled="saving || !form.name || !form.slug || form.intake_form_id === null"
                                >
                                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    {{ isEditForm ? 'Save Changes' : 'Add' }}
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
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import {
    OFFERING_KINDS,
    Offering,
    OfferingFilters,
    OfferingGroupRef,
    OfferingKind,
    OfferingPayload,
    FeePlan
} from '@/core/types/data/masjid-related/Offering';
import { Group } from '@/core/types/data/masjid-related/Group';
import { FormOption } from '@/core/types/data/masjid-related/Form';
import { useOfferingsStore } from '@/stores/masjid/offeringsStore';
import { useFormsStore } from '@/stores/masjid/formsStore';
import { useGroupsStore } from '@/stores/masjid/groupsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useOfferingDisplay } from '@/composables/useOfferingDisplay';
import { formatMinor } from '@/composables/useMinorUnits';
import { apiErrorText } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * Offerings — everything this organization sells a place in.
 *
 * NO PRICE IS SET OR EDITED ON THIS SCREEN. An offering carries no money of its
 * own; its fee plans do, they are immutable once created, and they live one
 * level down (OfferingFeePlansTab). That split is the same one the backend
 * makes: offerings take the contacts permissions, anything that decides what
 * somebody is charged takes the donations permissions.
 *
 * What an offering is CALLED is the tenant's vocabulary — "Programs" for a
 * masjid or a school, "Services" for a community org. It comes from the server's
 * `meta.offering_label`, with `masjidStore.term('programs')` covering the cold
 * load before the first response. Nothing here hardcodes any of the three.
 */

// Stores
const offeringsStore = useOfferingsStore();
const formsStore = useFormsStore();
const groupsStore = useGroupsStore();
const masjidStore = useMasjidStore();

// Display helpers
const { offeringKindLabel, describePlanTerms, formatDateTime } = useOfferingDisplay();

// State
const loading = ref(false);
const saving = ref(false);
const loadError = ref('');
const searchQuery = ref('');
const showFormModal = ref(false);
const isEditForm = ref(false);
const editingId = ref<number | null>(null);
/** Only auto-derive the slug while the admin has not typed one of their own. */
const slugTouched = ref(false);
const groupSearch = ref('');
/** The group already attached when editing, so it stays selectable off-search. */
const attachedGroup = ref<OfferingGroupRef | null>(null);
let searchTimeout: ReturnType<typeof setTimeout> | null = null;
let groupSearchTimeout: ReturnType<typeof setTimeout> | null = null;

const filters = reactive<OfferingFilters>({
    search: '',
    kind: '',
    active_only: false
});

const emptyForm = (): OfferingPayload => ({
    name: '',
    slug: '',
    kind: 'program',
    intake_form_id: null,
    group_id: null,
    capacity: null,
    opens_at: '',
    closes_at: '',
    is_active: true
});
const form = ref<OfferingPayload>(emptyForm());

/**
 * Capacity is bound through a string so an emptied box means "unlimited" (null)
 * rather than 0 — which would be a full offering nobody can join.
 */
const capacityInput = computed<string>({
    get: () => (form.value.capacity === null ? '' : String(form.value.capacity)),
    set: (value: string) => {
        const trimmed = String(value ?? '').trim();
        if (trimmed === '') {
            form.value.capacity = null;
            return;
        }
        const parsed = Number(trimmed);
        form.value.capacity = Number.isInteger(parsed) && parsed >= 0 ? parsed : null;
    }
});

// Computed
/** The tenant's own word for an offering; the server states it, the pack covers the cold load. */
const offeringLabel = computed<string>(
    () => offeringsStore.offeringsMeta?.offering_label || masjidStore.term('programs')
);

/** What this tenant calls a group — "Classrooms", "Halaqat", "Teams". */
const groupsTerm = computed<string>(() => masjidStore.term('groups'));

const offerings = computed<Offering[]>(
    () => (offeringsStore.offeringsPaginated?.data as Offering[]) || []
);

/** The kind vocabulary as the SERVER states it; the literal list is a cold-load fallback. */
const kinds = computed<OfferingKind[]>(
    () => offeringsStore.offeringsMeta?.kinds ?? OFFERING_KINDS
);

const formOptions = computed<FormOption[]>(() => formsStore.formOptions || []);

const groupChoices = computed<Group[]>(
    () => (groupsStore.groupsPaginated?.data as Group[]) || []
);

/**
 * Keep an already-attached group selectable even when the current search does
 * not contain it.
 *
 * The LIST endpoint does not eager-load the `group` relation (only `show`
 * does), so when the modal is opened from a row we may hold the id without the
 * name. That case still gets an option — labelled by id — because a select that
 * silently renders blank while holding a value is how a roster gets detached by
 * accident.
 */
const attachedGroupOption = computed<{ id: number; name: string } | null>(() => {
    const id = form.value.group_id;
    if (id === null) return null;
    if (groupChoices.value.some((group) => group.id === id)) return null;

    const named = attachedGroup.value;

    return {
        id,
        name: named && named.id === id ? named.name : `Currently attached (#${id})`
    };
});

const seatsTitle = 'Sign-ups currently holding a place, out of capacity. Capacity is measured against this number.';

const currentPage = computed<number>(() => offeringsStore.offeringsPaginated?.current_page || 1);

const filtersActive = computed<boolean>(
    () => filters.search !== '' || filters.kind !== '' || filters.active_only
);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!offeringsStore.offeringsPaginated) return undefined;
    return {
        currentPage: offeringsStore.offeringsPaginated.current_page,
        itemsTotal: offeringsStore.offeringsPaginated.total,
        perPage: offeringsStore.offeringsPaginated.per_page
    };
});

// Lifecycle
// Whether the form picker is empty because this tenant HAS no forms, or because
// the request for them failed. Without this the two are visually identical — an
// empty select — and the second one reads as the first, so an admin concludes
// they must go build a form when in fact the page simply could not ask. That
// ambiguity is the silent-failure shape this codebase keeps producing.
const formOptionsFailed = ref(false);

onBeforeMount(async () => {
    await loadData();
    // The pickers the create form needs. Not fatal: a failure leaves the list
    // usable and only the modal short-handed — but it MUST be distinguishable
    // from "you have no forms yet", which the modal now says out loud.
    try {
        formOptionsFailed.value = false;
        await formsStore.fetchFormOptions();
    } catch (e) {
        formOptionsFailed.value = true;
    }
});

// Debounced search — one request per pause, not per keystroke.
watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        filters.search = searchQuery.value.trim();
        await loadData(1);
    }, 500);
});

watch(groupSearch, () => {
    if (groupSearchTimeout) clearTimeout(groupSearchTimeout);
    groupSearchTimeout = setTimeout(async () => {
        await loadGroups(groupSearch.value.trim());
    }, 400);
});

// Methods
const loadData = async (page: number = 1) => {
    loading.value = true;
    loadError.value = '';
    try {
        await offeringsStore.fetchOfferings(page, filters);
    } catch (error) {
        loadError.value = apiErrorText(error, `Failed to load ${offeringLabel.value.toLowerCase()}.`);
    } finally {
        loading.value = false;
    }
};

const loadGroups = async (search: string = '') => {
    try {
        await groupsStore.fetchGroups(1, search);
    } catch (error) {
        // A group is optional; a failed picker must not block creating an offering.
    }
};

const pageChange = async (data: PageChangeData) => {
    if (data.toPage === currentPage.value) return;
    await loadData(data.toPage);
};

const clearFilters = async () => {
    filters.search = '';
    filters.kind = '';
    filters.active_only = false;
    searchQuery.value = '';
    await loadData(1);
};

/** Only the plans that are still sellable. Deactivated rows are kept forever. */
const activePlans = (offering: Offering): FeePlan[] =>
    (offering.fee_plans || []).filter((plan) => plan.is_active);

/** A URL-safe slug from the name, matching the server's `^[a-z0-9]+(-[a-z0-9]+)*$`. */
const slugify = (value: string): string =>
    value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

const syncSlug = () => {
    if (!slugTouched.value) form.value.slug = slugify(form.value.name);
};

/** ISO 8601 from the API -> the value a datetime-local input understands. */
const toDateTimeLocal = (iso: string | null): string => (iso ? iso.slice(0, 16) : '');

const openCreateModal = async () => {
    isEditForm.value = false;
    editingId.value = null;
    slugTouched.value = false;
    attachedGroup.value = null;
    groupSearch.value = '';
    form.value = emptyForm();
    // Default to the kind vocabulary the server actually offers, so a kind that
    // was removed in PHP cannot be pre-selected here.
    if (kinds.value.length > 0 && !kinds.value.includes(form.value.kind)) {
        form.value.kind = kinds.value[0];
    }
    showFormModal.value = true;
    await loadGroups();
};

const openEditModal = async (offering: Offering) => {
    isEditForm.value = true;
    editingId.value = offering.id;
    slugTouched.value = true;
    attachedGroup.value = offering.group ?? null;
    groupSearch.value = '';
    form.value = {
        name: offering.name ?? '',
        slug: offering.slug ?? '',
        kind: offering.kind ?? 'program',
        intake_form_id: offering.intake_form_id ?? null,
        group_id: offering.group_id ?? null,
        capacity: offering.capacity ?? null,
        opens_at: toDateTimeLocal(offering.opens_at),
        closes_at: toDateTimeLocal(offering.closes_at),
        is_active: offering.is_active
    };
    showFormModal.value = true;
    await loadGroups();
};

const closeFormModal = () => {
    showFormModal.value = false;
};

const submitForm = async () => {
    if (!form.value.name || !form.value.slug || form.value.intake_form_id === null) return;
    saving.value = true;
    try {
        if (isEditForm.value && editingId.value !== null) {
            await offeringsStore.updateOffering(editingId.value, form.value);
        } else {
            await offeringsStore.createOffering(form.value);
        }
        showFormModal.value = false;
        await loadData(isEditForm.value ? currentPage.value : 1);
        Swal.fire({
            icon: 'success',
            title: isEditForm.value ? 'Saved!' : 'Added!',
            timer: 2000,
            showConfirmButton: false
        });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to save.') });
    } finally {
        saving.value = false;
    }
};

/**
 * "Remove", and the endpoint REFUSES while anybody still holds a live place —
 * pending, confirmed or waitlisted. That refusal comes back as a 422 carrying
 * the server's own sentence, which is shown verbatim rather than translated
 * into a generic failure: it names the non-destructive alternative (close the
 * offering instead), and paraphrasing it would lose that.
 */
const confirmDelete = async (offering: Offering) => {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text: `Remove "${offering.name}"? This is refused while anyone still holds a place in it.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, remove'
    });

    if (!result.isConfirmed) return;

    try {
        await offeringsStore.deleteOffering(offering.id);
        await loadData(currentPage.value);
        Swal.fire({ icon: 'success', title: 'Removed', timer: 2000, showConfirmButton: false });
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Not removed',
            text: apiErrorText(error, 'Failed to remove this offering.')
        });
    }
};

// Lock body scroll while the modal is open
watch(showFormModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<style scoped>
/* Stats Card */
.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 1.5rem;
    color: white;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}

.stats-icon {
    width: 60px;
    height: 60px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
}

.stats-content {
    flex: 1;
}

.stats-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.stats-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
}

.empty-copy {
    max-width: 36rem;
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

/* Modal */
.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
