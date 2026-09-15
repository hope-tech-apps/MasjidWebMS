<template>
    <div class="card border-0">
        <div class="card-header bg-white border-0">
            <div class="card-title fs-4 fw-semibold">
                {{ masjidStore.term('organization') }} Settings
            </div>
        </div>
        <div class="card-body p-0">
            <!-- Nav tabs -->
            <ul ref="tabList" class="nav nav-tabs px-3 pt-3" id="mosqueDetailsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="basic-info-tab" data-bs-toggle="tab" data-bs-target="#basic-info" type="button" role="tab" aria-controls="basic-info" aria-selected="true">
                        <i class="bi bi-info-circle me-2"></i>
                        Basic Info
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="general-settings-tab" data-bs-toggle="tab" data-bs-target="#general-settings" type="button" role="tab" aria-controls="general-settings" aria-selected="false">
                        <i class="bi bi-gear me-2"></i>
                        General Settings
                    </button>
                </li>
                <!--
                    The prayer tabs follow the `prayer_times` module. They mount only once the
                    organisation has loaded (each view fetches in onBeforeMount and needs its id),
                    and for its own administrators only while the module is on. A SuperAdmin keeps
                    them, marked Off, with the notice at the top of each pane.
                -->
                <template v-if="showPrayerTabs">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="prayer-calculation-tab" data-bs-toggle="tab" data-bs-target="#prayer-calculation" type="button" role="tab" aria-controls="prayer-calculation" aria-selected="false">
                            <i class="bi bi-calculator me-2"></i>
                            Prayer Calculation
                            <span v-if="prayerOff" class="badge text-bg-warning ms-2">Off<span class="visually-hidden"> (switched off for this organisation)</span></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="iqama-settings-tab" data-bs-toggle="tab" data-bs-target="#iqama-settings" type="button" role="tab" aria-controls="iqama-settings" aria-selected="false">
                            <i class="bi bi-clock me-2"></i>
                            Iqama Settings
                            <span v-if="prayerOff" class="badge text-bg-warning ms-2">Off<span class="visually-hidden"> (switched off for this organisation)</span></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="jumaa-settings-tab" data-bs-toggle="tab" data-bs-target="#jumaa-settings" type="button" role="tab" aria-controls="jumaa-settings" aria-selected="false">
                            <i class="bi bi-calendar-event me-2"></i>
                            Jumaa Settings
                            <span v-if="prayerOff" class="badge text-bg-warning ms-2">Off<span class="visually-hidden"> (switched off for this organisation)</span></span>
                        </button>
                    </li>
                </template>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="theme-settings-tab" data-bs-toggle="tab" data-bs-target="#theme-settings" type="button" role="tab" aria-controls="theme-settings" aria-selected="false">
                        <i class="bi bi-palette me-2"></i>
                        Brand Studio
                    </button>
                </li>
                <!--
                    Stripe Connect: always for a school or community organisation, and for a
                    masjid while the Giving Dashboard that normally holds it is switched off.
                    Connect is never behind the giving switch: lunch orders, program fees and
                    form card payments need it. See showsOnlinePaymentsTab(). A LINKED program
                    org (forms_card_via) sees the link's status here and no onboarding button.
                -->
                <li v-if="showOnlinePayments" class="nav-item" role="presentation">
                    <button class="nav-link" id="online-payments-tab" data-bs-toggle="tab" data-bs-target="#online-payments" type="button" role="tab" aria-controls="online-payments" aria-selected="false">
                        <i class="bi bi-credit-card me-2"></i>
                        Online payments
                    </button>
                </li>
            </ul>

            <!-- Tab panes -->
            <div class="tab-content p-3" id="mosqueDetailsTabContent">
                <div class="tab-pane fade show active" id="basic-info" role="tabpanel" aria-labelledby="basic-info-tab">
                    <MosqueDetailsView />
                </div>
                <div class="tab-pane fade" id="general-settings" role="tabpanel" aria-labelledby="general-settings-tab">
                    <GeneralSettingsView />
                </div>
                <template v-if="showPrayerTabs">
                    <div class="tab-pane fade" id="prayer-calculation" role="tabpanel" aria-labelledby="prayer-calculation-tab">
                        <SwitchedOffNotice v-if="prayerOff" class="mb-3" :org-name="masjidStore.masjid?.name" :detail="PRAYER_OFF_DETAIL" />
                        <PrayerCalculationSettingsView />
                    </div>
                    <div class="tab-pane fade" id="iqama-settings" role="tabpanel" aria-labelledby="iqama-settings-tab">
                        <SwitchedOffNotice v-if="prayerOff" class="mb-3" :org-name="masjidStore.masjid?.name" :detail="PRAYER_OFF_DETAIL" />
                        <IqamaTimeSettingsView />
                    </div>
                    <div class="tab-pane fade" id="jumaa-settings" role="tabpanel" aria-labelledby="jumaa-settings-tab">
                        <SwitchedOffNotice v-if="prayerOff" class="mb-3" :org-name="masjidStore.masjid?.name" :detail="PRAYER_OFF_DETAIL" />
                        <JumaaSettingsView />
                    </div>
                </template>
                <div class="tab-pane fade" id="theme-settings" role="tabpanel" aria-labelledby="theme-settings-tab">
                    <ThemeSettingsView />
                </div>
                <div v-if="showOnlinePayments" class="tab-pane fade" id="online-payments" role="tabpanel" aria-labelledby="online-payments-tab">
                    <StripeConnectPanel explain-forbidden />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import MosqueDetailsView from './MosqueDetailsView.vue';
import GeneralSettingsView from './GeneralSettingsView.vue';
import PrayerCalculationSettingsView from './PrayerCalculationSettingsView.vue';
import IqamaTimeSettingsView from './IqamaTimeSettingsView.vue';
import JumaaSettingsView from './JumaaSettingsView.vue';
import ThemeSettingsView from './ThemeSettingsView.vue';
import StripeConnectPanel from '@/components/StripeConnectPanel.vue';
import SwitchedOffNotice from '@/components/dashboard/SwitchedOffNotice.vue';
import { moduleIsOff, showsOnlinePaymentsTab } from '@/core/access/orgAccess';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { Tab } from 'bootstrap';
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

// Stores
const authStore = useAuthStore();
const masjidStore = useMasjidStore();

// Routing
const route = useRoute();

const PRAYER_OFF_DETAIL = 'its administrators do not see the Prayer Calculation, Iqama and Jumaa tabs. '
    + 'The website and apps keep showing prayer times from the last saved settings.';

const loaded = computed<boolean>(() => !!masjidStore.masjid?.id);
const isSuper = computed<boolean>(() => authStore.user?.type === 'SuperAdmin');

/** An explicit `modules_off` entry only: an older payload keeps every tab. */
const prayerOff = computed<boolean>(() => moduleIsOff(masjidStore.masjid, 'prayer_times'));
const showPrayerTabs = computed<boolean>(() => loaded.value && (isSuper.value || !prayerOff.value));

const showOnlinePayments = computed<boolean>(() =>
    loaded.value && showsOnlinePaymentsTab(masjidStore.masjid, masjidStore.orgType));

// Html refs
const tabList = ref<HTMLElement | null>(null);

/**
 * Open the tab a link names in its hash (FormBuilder sends an admin to
 * #online-payments). Watched on the organisation, not onMounted: the link opens in a
 * new browser tab, which boots cold with no organisation loaded, and the tab it names
 * only renders once one is. Once per visit, so switching tabs by hand is never undone.
 */
let hashHandled = false;

watch(() => masjidStore.masjid?.id, async (id) => {
    if (!id || hashHandled) return;
    hashHandled = true;
    if (!route.hash) return;

    await nextTick();

    const button = Array.from(tabList.value?.querySelectorAll<HTMLElement>('button[data-bs-toggle="tab"]') ?? [])
        .find(candidate => candidate.getAttribute('data-bs-target') === route.hash);

    if (button) Tab.getOrCreateInstance(button).show();
}, { immediate: true });
</script>

<style scoped>
.nav-tabs .nav-link {
    color: #6c757d;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 0.75rem 1.5rem;
}

.nav-tabs .nav-link:hover {
    border-color: transparent;
    border-bottom-color: #dee2e6;
}

.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-color: transparent;
    border-bottom-color: #0d6efd;
    background-color: transparent;
}

.nav-tabs .nav-link:focus-visible {
    outline: 2px solid #0d6efd;
    outline-offset: 2px;
}

.tab-content {
    min-height: 400px;
}
</style>

