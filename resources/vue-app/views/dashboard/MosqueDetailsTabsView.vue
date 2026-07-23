<template>
    <div class="card border-0">
        <div class="card-header bg-white border-0">
            <div class="card-title fs-4 fw-semibold">
                Mosque Settings
            </div>
        </div>
        <div class="card-body p-0">
            <!-- Nav tabs -->
            <ul class="nav nav-tabs px-3 pt-3" id="mosqueDetailsTabs" role="tablist">
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
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="prayer-calculation-tab" data-bs-toggle="tab" data-bs-target="#prayer-calculation" type="button" role="tab" aria-controls="prayer-calculation" aria-selected="false">
                        <i class="bi bi-calculator me-2"></i>
                        Prayer Calculation
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="iqama-settings-tab" data-bs-toggle="tab" data-bs-target="#iqama-settings" type="button" role="tab" aria-controls="iqama-settings" aria-selected="false">
                        <i class="bi bi-clock me-2"></i>
                        Iqama Settings
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="jumaa-settings-tab" data-bs-toggle="tab" data-bs-target="#jumaa-settings" type="button" role="tab" aria-controls="jumaa-settings" aria-selected="false">
                        <i class="bi bi-calendar-event me-2"></i>
                        Jumaa Settings
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="theme-settings-tab" data-bs-toggle="tab" data-bs-target="#theme-settings" type="button" role="tab" aria-controls="theme-settings" aria-selected="false">
                        <i class="bi bi-palette me-2"></i>
                        Brand Studio
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
                <div class="tab-pane fade" id="prayer-calculation" role="tabpanel" aria-labelledby="prayer-calculation-tab">
                    <PrayerCalculationSettingsView />
                </div>
                <div class="tab-pane fade" id="iqama-settings" role="tabpanel" aria-labelledby="iqama-settings-tab">
                    <IqamaTimeSettingsView />
                </div>
                <div class="tab-pane fade" id="jumaa-settings" role="tabpanel" aria-labelledby="jumaa-settings-tab">
                    <JumaaSettingsView />
                </div>
                <div class="tab-pane fade" id="theme-settings" role="tabpanel" aria-labelledby="theme-settings-tab">
                    <ThemeSettingsView />
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

.tab-content {
    min-height: 400px;
}
</style>

