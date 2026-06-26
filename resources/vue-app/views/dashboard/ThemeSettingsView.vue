<template>
    <Form :validation-schema="validationSchema" @submit="onSubmit" class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
            <div class="card-title fs-4 fw-semibold">
                Theme Settings
            </div>
        </div>

        <div class="card-body w-100">
            <p class="text-muted">
                These colors drive the look of the masjid's website and mobile apps. Leave a field
                blank to fall back to the app's built-in default.
            </p>
            <div class="d-flex flex-column gap-4">
                <div class="container">
                    <div class="row">
                        <!-- Primary -->
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label fw-semibold">Primary Color</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color"
                                    :value="settingsModel.primary_color || '#000000'"
                                    @input="settingsModel.primary_color = ($event.target as HTMLInputElement).value" />
                                <Field name="primary_color" v-model="settingsModel.primary_color"
                                    class="dashboard-input" placeholder="#01b151" />
                            </div>
                            <ErrorMessage name="primary_color" class="error-message" />
                        </div>

                        <!-- Secondary -->
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label fw-semibold">Secondary Color</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color"
                                    :value="settingsModel.secondary_color || '#000000'"
                                    @input="settingsModel.secondary_color = ($event.target as HTMLInputElement).value" />
                                <Field name="secondary_color" v-model="settingsModel.secondary_color"
                                    class="dashboard-input" placeholder="#1b1b2e" />
                            </div>
                            <ErrorMessage name="secondary_color" class="error-message" />
                        </div>

                        <!-- Accent -->
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label fw-semibold">Accent Color</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color"
                                    :value="settingsModel.accent_color || '#000000'"
                                    @input="settingsModel.accent_color = ($event.target as HTMLInputElement).value" />
                                <Field name="accent_color" v-model="settingsModel.accent_color"
                                    class="dashboard-input" placeholder="#ffba63" />
                            </div>
                            <ErrorMessage name="accent_color" class="error-message" />
                        </div>

                        <!-- Background -->
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label fw-semibold">Background Color</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color"
                                    :value="settingsModel.background_color || '#ffffff'"
                                    @input="settingsModel.background_color = ($event.target as HTMLInputElement).value" />
                                <Field name="background_color" v-model="settingsModel.background_color"
                                    class="dashboard-input" placeholder="#f3f8fb" />
                            </div>
                            <ErrorMessage name="background_color" class="error-message" />
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
import type { ThemeSetting } from '@/core/types/data/masjid-related/ThemeSetting';

// Stores
const masjidStore = useMasjidStore();

// State
const isLoading = ref<boolean>(false);
const settingsModel = ref({
    primary_color: '',
    secondary_color: '',
    accent_color: '',
    background_color: ''
});

// Validation — a hex color (#RGB, #RRGGBB or #RRGGBBAA), or empty to fall back.
const hexRule = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/;
const validationSchema = computed(() => {
    return object().shape({
        primary_color: string().nullable().matches(hexRule, { excludeEmptyString: true, message: 'Must be a hex color, e.g. #01b151' }).label('Primary Color'),
        secondary_color: string().nullable().matches(hexRule, { excludeEmptyString: true, message: 'Must be a hex color, e.g. #1b1b2e' }).label('Secondary Color'),
        accent_color: string().nullable().matches(hexRule, { excludeEmptyString: true, message: 'Must be a hex color, e.g. #ffba63' }).label('Accent Color'),
        background_color: string().nullable().matches(hexRule, { excludeEmptyString: true, message: 'Must be a hex color, e.g. #f3f8fb' }).label('Background Color')
    });
});

// Lifecycle
onBeforeMount(async () => {
    await fetchSettings();
});

// Methods
const fetchSettings = async () => {
    try {
        const response = await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/theme`);
        if (response.data.status === 'success' && response.data.data) {
            const data: ThemeSetting = response.data.data;
            settingsModel.value.primary_color = data.primary_color ?? '';
            settingsModel.value.secondary_color = data.secondary_color ?? '';
            settingsModel.value.accent_color = data.accent_color ?? '';
            settingsModel.value.background_color = data.background_color ?? '';
        }
    } catch (error) {
        console.error('Error fetching settings:', error);
    }
};

const onSubmit = async () => {
    isLoading.value = true;
    QSwal.fire("Question", 'Save theme settings?', 'question')
        .then(async (result) => {
            if (result.isConfirmed) {
                await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/theme`, settingsModel.value)
                    .then(res => {
                        if (res.data.status === 'success') {
                            QSwal.fire("Success", "Theme settings saved successfully.", "success");
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
