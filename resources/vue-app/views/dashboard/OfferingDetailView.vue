<template>
    <div>
        <PageDataContainer :title="pageTitle" :hideButton="true">
            <template #headerButtons>
                <router-link class="btn btn-outline-secondary" :to="{ name: 'masjid.offerings' }">
                    <i class="bi bi-arrow-left me-1"></i>
                    {{ offeringLabel }}
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
                    <p class="mb-3">That entry no longer exists.</p>
                    <router-link class="btn btn-outline-primary btn-sm" :to="{ name: 'masjid.offerings' }">
                        Back to the list
                    </router-link>
                </div>

                <!-- Load failed for a reason other than a missing row -->
                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    {{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="bootstrap">Retry</button>
                </div>

                <div v-else-if="offering">
                    <!-- Header -->
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span class="badge bg-light text-dark border">{{ offeringKindLabel(offering.kind) }}</span>
                        <!-- is_open: is_active alone claimed "Open for registration" on offerings whose window had closed -->
                <span v-if="offering.is_open" class="badge bg-success-subtle text-success">Open for registration</span>
                <span v-else-if="offering.closed_reason === 'not_yet_open'" class="badge bg-info-subtle text-info">Opens later</span>
                <span v-else-if="offering.closed_reason === 'closed'" class="badge bg-secondary-subtle text-secondary">Registration window closed</span>
                        <span v-else class="badge bg-secondary-subtle text-secondary">Closed</span>
                        <span class="text-muted small font-monospace">{{ offering.slug }}</span>
                    </div>

                    <div class="row g-3 mb-4">
                        <!--
                            SEATS. Every one of these three numbers is served by the
                            endpoint (`meta.seats`) — including `remaining`, which is
                            the server's own subtraction. It is null, not 0, when
                            capacity is unlimited, because "how many are left" is then
                            genuinely unanswerable rather than none.
                        -->
                        <div class="col-md-6 col-lg-4">
                            <div class="panel h-100">
                                <div class="panel-title">
                                    <i class="bi bi-person-check me-1"></i>Seats
                                </div>
                                <div class="d-flex align-items-baseline gap-2">
                                    <span class="display-6 fw-bold">{{ seats?.taken ?? offering.registration_count }}</span>
                                    <span class="text-muted">
                                        of {{ capacityText }}
                                    </span>
                                </div>
                                <div class="text-muted small mt-1">
                                    <template v-if="seats && seats.remaining !== null">
                                        {{ seats.remaining }} remaining
                                    </template>
                                    <template v-else>
                                        Unlimited capacity — no seat limit is enforced.
                                    </template>
                                </div>
                                <div class="text-muted small mt-2">
                                    Counts sign-ups that currently hold a place. Cancelled
                                    sign-ups have given their seat back.
                                </div>
                            </div>
                        </div>

                        <!-- Per-status counts, exactly as the server grouped them. -->
                        <div class="col-md-6 col-lg-4">
                            <div class="panel h-100">
                                <div class="panel-title">
                                    <i class="bi bi-list-check me-1"></i>Sign-ups
                                </div>
                                <div v-if="statusCountEntries.length" class="d-flex flex-column gap-1">
                                    <div
                                        v-for="entry in statusCountEntries"
                                        :key="entry.status"
                                        class="d-flex justify-content-between align-items-center"
                                    >
                                        <span class="badge" :class="registrationStatusBadge(entry.status)">
                                            <i :class="`bi ${registrationStatusIcon(entry.status)} me-1`"></i>
                                            {{ registrationStatusLabel(entry.status) }}
                                        </span>
                                        <span class="fw-semibold">{{ entry.count }}</span>
                                    </div>
                                </div>
                                <div v-else class="text-muted small">No sign-ups yet.</div>
                            </div>
                        </div>

                        <!-- Where intake comes from and where the roster goes. -->
                        <div class="col-lg-4">
                            <div class="panel h-100">
                                <div class="panel-title">
                                    <i class="bi bi-diagram-3 me-1"></i>Wiring
                                </div>
                                <dl class="row mb-0 small">
                                    <dt class="col-5 text-muted fw-normal">Sign-up form</dt>
                                    <dd class="col-7 mb-1">{{ offering.intake_form?.name || `#${offering.intake_form_id}` }}</dd>

                                    <dt class="col-5 text-muted fw-normal">Roster {{ groupsTerm.toLowerCase() }}</dt>
                                    <dd class="col-7 mb-1">
                                        <router-link
                                            v-if="offering.group"
                                            :to="{ name: 'masjid.groupDetail', params: { groupId: offering.group.id } }"
                                        >
                                            {{ offering.group.name }}
                                        </router-link>
                                        <span v-else class="text-muted">None</span>
                                    </dd>

                                    <dt class="col-5 text-muted fw-normal">Opens</dt>
                                    <dd class="col-7 mb-1">
                                        {{ offering.opens_at ? formatDateTime(offering.opens_at) : 'anytime' }}
                                    </dd>

                                    <dt class="col-5 text-muted fw-normal">Closes</dt>
                                    <dd class="col-7 mb-0">
                                        {{ offering.closes_at ? formatDateTime(offering.closes_at) : 'never' }}
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li v-for="tab in tabs" :key="tab.key" class="nav-item">
                            <button
                                type="button"
                                class="nav-link"
                                :class="{ active: activeTab === tab.key }"
                                @click="activeTab = tab.key"
                            >
                                <i :class="`bi ${tab.icon} me-1`"></i>
                                {{ tab.label }}
                            </button>
                        </li>
                    </ul>

                    <!--
                        Mounted only while active (v-if, not v-show): the roster is a
                        page of people and their money, and mounting both panels up
                        front would fetch a list nobody asked to see.
                    -->
                    <OfferingFeePlansTab
                        v-if="activeTab === 'plans'"
                        :offeringId="offeringId"
                        @changed="bootstrap"
                    />

                    <OfferingRegistrationsTab
                        v-else-if="activeTab === 'registrations'"
                        :offeringId="offeringId"
                    />
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
import OfferingFeePlansTab from './offerings/OfferingFeePlansTab.vue';
import OfferingRegistrationsTab from './offerings/OfferingRegistrationsTab.vue';
import {
    Offering,
    OfferingSeats,
    RegistrationStatus,
    REGISTRATION_STATUSES
} from '@/core/types/data/masjid-related/Offering';
import { useOfferingsStore } from '@/stores/masjid/offeringsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useOfferingDisplay } from '@/composables/useOfferingDisplay';
import { apiErrorText } from '@/core/services/ApiErrors';

/**
 * One offering: how full it is, how it is wired, what it costs, and who signed
 * up.
 *
 * Every number in the header comes from the SHOW endpoint's own `meta` —
 * `seats.capacity/taken/remaining` and `registrations_by_status`. None of them
 * is recomputed here, including `remaining`: a seat count the browser derived
 * would look exactly as authoritative as the one the database took a row lock
 * to maintain, and the two would drift the moment a webhook landed.
 */

type TabKey = 'plans' | 'registrations';

// Routing
const route = useRoute();

// Stores
const offeringsStore = useOfferingsStore();
const masjidStore = useMasjidStore();

// Display helpers
const {
    offeringKindLabel,
    registrationStatusLabel,
    registrationStatusBadge,
    registrationStatusIcon,
    formatDateTime
} = useOfferingDisplay();

// State
const offeringId = Number(route.params.offeringId);
const offering = ref<Offering | null>(null);
const bootstrapping = ref(true);
const notFound = ref(false);
const loadError = ref('');
const activeTab = ref<TabKey>('plans');

const tabs: { key: TabKey; label: string; icon: string }[] = [
    { key: 'plans', label: 'Fee Plans', icon: 'bi-cash-coin' },
    { key: 'registrations', label: 'Registrations', icon: 'bi-people' }
];

// Computed
const offeringLabel = computed<string>(
    () => offeringsStore.offeringsMeta?.offering_label || masjidStore.term('programs')
);

const groupsTerm = computed<string>(() => masjidStore.term('groups'));

const pageTitle = computed<string>(() => offering.value?.name || offeringLabel.value);

/** Served by SHOW only; the fallback keeps the panel honest before it arrives. */
const seats = computed<OfferingSeats | null>(() => offeringsStore.offeringsMeta?.seats ?? null);

const capacityText = computed<string>(() => {
    const capacity = seats.value ? seats.value.capacity : offering.value?.capacity ?? null;

    return capacity === null ? 'unlimited' : String(capacity);
});

/**
 * The server's per-status counts, ordered by the server's own status list so
 * the rows do not reshuffle between loads. A status the server counted but did
 * not list still shows up — it is data we were given.
 */
const statusCountEntries = computed<{ status: RegistrationStatus; count: number }[]>(() => {
    const counts = offeringsStore.offeringsMeta?.registrations_by_status;
    if (!counts) return [];

    const order = offeringsStore.offeringsMeta?.registration_statuses ?? REGISTRATION_STATUSES;
    const seen = new Set<string>();
    const entries: { status: RegistrationStatus; count: number }[] = [];

    order.forEach((status) => {
        seen.add(status);
        entries.push({ status, count: counts[status] ?? 0 });
    });

    Object.keys(counts).forEach((status) => {
        if (seen.has(status)) return;
        entries.push({
            status: status as RegistrationStatus,
            count: counts[status as RegistrationStatus] ?? 0
        });
    });

    return entries;
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
        offering.value = await offeringsStore.fetchOffering(offeringId);
        if (!offering.value) {
            notFound.value = true;
        }
    } catch (error) {
        // A cross-tenant or deleted id is a 404 MISS by design, not a failure to
        // report as an error — see .claude/rules/tenant-scoping.md.
        if ((error as AxiosError)?.response?.status === 404) {
            notFound.value = true;
        } else {
            loadError.value = apiErrorText(error, 'Failed to load this offering.');
        }
    } finally {
        bootstrapping.value = false;
    }
};
</script>

<style scoped>
.nav-tabs .nav-link {
    color: #6c757d;
}

.nav-tabs .nav-link.active {
    color: #198754;
    font-weight: 600;
}

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
