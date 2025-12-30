<template>
    <Form :validation-schema="validationSchema" @submit="onSubmit" class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
            <div class="card-title fs-4 fw-semibold">
                Prayer Calculation Settings
            </div>
        </div>

        <div class="card-body w-100">
            <div class="d-flex flex-column gap-4">
                <!-- Prayer Calculation Method -->
                <div class="container">
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label fw-semibold">Calculation Method</label>
                            <Field as="select" name="method" v-model="settingsModel.method" class="dashboard-input">
                                <option value="">Select a method...</option>
                                <option v-for="method in options.methods" :key="method.value" :value="method.value">
                                    {{ method.label }}
                                </option>
                            </Field>
                            <ErrorMessage name="method" class="error-message" />
                        </div>

                        <!-- Madhab -->
                        <div class="col-12 mb-3">
                            <label class="form-label fw-semibold">Madhab (Asr Calculation)</label>
                            <Field as="select" name="madhab" v-model="settingsModel.madhab" class="dashboard-input">
                                <option value="">Select a madhab...</option>
                                <option v-for="madhab in options.madhabs" :key="madhab.value" :value="madhab.value">
                                    {{ madhab.label }}
                                </option>
                            </Field>
                            <ErrorMessage name="madhab" class="error-message" />
                        </div>

                        <!-- High Latitude Rule -->
                        <div class="col-12 mb-3">
                            <label class="form-label fw-semibold">High Latitude Rule</label>
                            <Field as="select" name="high_latitude_rule" v-model="settingsModel.high_latitude_rule" class="dashboard-input">
                                <option value="">Select a rule...</option>
                                <option v-for="rule in options.high_latitude_rules" :key="rule.value" :value="rule.value">
                                    {{ rule.label }}
                                </option>
                            </Field>
                            <ErrorMessage name="high_latitude_rule" class="error-message" />
                            <small class="text-muted">
                                Used for locations at higher latitudes where Fajr and Isha times may not occur.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer bg-white border-0 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" :disabled="isLoading">
                <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
                Save Settings
            </button>
        </div>
    </Form>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed } from 'vue';
import { Form, Field, ErrorMessage } from 'vee-validate';
import { object, string } from 'yup';
import { useMasjidStore } from '@/stores/masjidStore';
import ApiService from '@/core/services/ApiService';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import type { AxiosError } from 'axios';
import type { BackendResponseData } from '@/core/types/config/AxiosCustom';
import type { PrayerCalculationSetting, PrayerCalculationOptions } from '@/core/types/data/masjid-related/PrayerCalculationSetting';

// Stores
const masjidStore = useMasjidStore();

// State
const isLoading = ref<boolean>(false);
const settingsModel = ref({
    method: '',
    madhab: '',
    high_latitude_rule: ''
});
const options = ref<PrayerCalculationOptions>({
    methods: [],
    madhabs: [],
    high_latitude_rules: []
});

// Validation
const validationSchema = computed(() => {
    return object().shape({
        method: string().required().label('Calculation Method'),
        madhab: string().required().label('Madhab'),
        high_latitude_rule: string().required().label('High Latitude Rule')
    });
});

// Lifecycle
onBeforeMount(async () => {
    await fetchOptions();
    await fetchSettings();
});

// Methods
const fetchOptions = async () => {
    try {
        const response = await ApiService.get('/api/admin/masjids/prayer-calculation/options');
        if (response.data.status === 'success') {
            options.value = response.data.data;
        }
    } catch (error) {
        console.error('Error fetching options:', error);
    }
};

const fetchSettings = async () => {
    try {
        const response = await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/prayer-calculation`);
        if (response.data.status === 'success' && response.data.data) {
            const data: PrayerCalculationSetting = response.data.data;
            settingsModel.value.method = data.method;
            settingsModel.value.madhab = data.madhab;
            settingsModel.value.high_latitude_rule = data.high_latitude_rule;
        }
    } catch (error) {
        console.error('Error fetching settings:', error);
    }
};

const onSubmit = async () => {
    isLoading.value = true;
    QSwal.fire("Question", 'Save prayer calculation settings?', 'question')
        .then(async (result) => {
            if (result.isConfirmed) {
                await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/prayer-calculation`, settingsModel.value)
                    .then(res => {
                        if (res.data.status === 'success') {
                            QSwal.fire("Success", "Prayer calculation settings saved successfully.", "success");
                        } else {
                            QSwal.fire("Sorry", getMessageFromObj(res), "warning");
                        }
                    })
                    .catch((e: AxiosError<BackendResponseData>) => {
                        QSwal.fire(e.message, getMessageFromObj(e), "error");
                    })
                    .finally(() => {
                        isLoading.value = false;
                    });
            } else {
                isLoading.value = false;
            }
        });
};
</script>

