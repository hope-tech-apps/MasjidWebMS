<template>
    <Form :validation-schema="validationSchema" @submit="updateGeneralSettings" class="card border-0 py-4 px-3">
        <div class="card-header bg-white border-0">
            <div class="card-title fs-4 fw-semibold">
                General Settings
            </div>
        </div>

        <div class="card-body d-flex flex-column gap-5 overflow-auto">

            <!-- Logos Group -->
            <div class="d-flex flex-column gap-3">
                <span class="d-block fs-5 fw-semibold">Logos</span>
                <div class="d-flex flex-column gap-3 w-100">
                    <div class="d-flex flex-column w-100">
                        <!-- Header Logo -->
                        <ImageDraggableInput label="Header Logo"
                            @imageChange="(data: UploadedImageInfo) => onHeaderLogoInputChange(data)"
                            :current-image-src="oldHeaderLogoImage" type="photo" />
                        <Field type="file" v-model="settingsModel.headerLogoSrc" name="header_logo_image" class="d-none"></Field>
                        <div class="error-message">
                            <ErrorMessage name="header_logo_image" class="error-message" />
                        </div>
                    </div>
                    <div class="d-flex flex-column w-100">
                        <!-- Footer Logo -->
                        <ImageDraggableInput label="Footer Logo"
                            @imageChange="(data: UploadedImageInfo) => onFooterLogoInputChange(data)"
                            :current-image-src="oldFooterLogoImage" type="photo" />
                        <Field type="file" v-model="settingsModel.footerLogoSrc" name="footer_logo_image" class="d-none"></Field>
                        <div class="error-message">
                            <ErrorMessage name="footer_logo_image" class="error-message" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Copyright & Links Group -->
            <div class="d-flex flex-column gap-3">
                <span class="d-block fs-5 fw-semibold">Copyright & App Links</span>
                <div class="d-flex flex-column gap-3 w-100">
                    <ColumnInputContainer label="Copyright Text" name="copyright_text" :show_error="true" class="w-100">
                        <Field name="copyright_text" type="text" v-model="settingsModel.copyright_text"
                            class="dashboard-input"
                            placeholder="© 2024 Your Mosque Name. All rights reserved."></Field>
                    </ColumnInputContainer>
                    <ColumnInputContainer label="App Store Link" name="app_store_link" :show_error="true" class="w-100">
                        <Field name="app_store_link" type="url" v-model="settingsModel.app_store_link"
                            class="dashboard-input"
                            placeholder="https://apps.apple.com/..."></Field>
                    </ColumnInputContainer>
                    <ColumnInputContainer label="Google Play Link" name="google_play_link" :show_error="true" class="w-100">
                        <Field name="google_play_link" type="url" v-model="settingsModel.google_play_link"
                            class="dashboard-input"
                            placeholder="https://play.google.com/store/apps/..."></Field>
                    </ColumnInputContainer>
                </div>
            </div>

            <!-- API Keys Group -->
            <div class="d-flex flex-column gap-3">
                <span class="d-block fs-5 fw-semibold">API Keys</span>
                <div class="d-flex flex-column gap-3 w-100">
                    <ColumnInputContainer label="Google Maps API Key" name="google_maps_key" :show_error="true" class="w-100">
                        <Field name="google_maps_key" type="text" v-model="settingsModel.google_maps_key"
                            class="dashboard-input"
                            placeholder="AIza..."></Field>
                    </ColumnInputContainer>
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        This key is used for displaying maps in the mobile application.
                    </small>
                </div>
            </div>

        </div>

        <div class="card-footer bg-white border-0 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" :disabled="updateSettingsLoading">
                <span v-if="updateSettingsLoading" class="spinner-border spinner-border-sm me-2"></span>
                Save Changes
            </button>
        </div>
    </Form>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeMount } from 'vue';
import { Form, Field, ErrorMessage } from 'vee-validate';
import { object, string } from 'yup';
import { useMasjidStore } from '@/stores/masjidStore';
import ApiService from '@/core/services/ApiService';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import type { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import type { AxiosError } from 'axios';
import type { BackendResponseData } from '@/core/types/config/AxiosCustom';

// Stores
const masjidStore = useMasjidStore();

// Custom constants
const settingsModel = ref({
    copyright_text: '',
    app_store_link: '',
    google_play_link: '',
    google_maps_key: '',
    headerLogoSrc: undefined as string | undefined,
    footerLogoSrc: undefined as string | undefined
});
const headerLogoFile = ref<File | undefined>();
const footerLogoFile = ref<File | undefined>();
const updateSettingsLoading = ref<boolean>(false);

// Validation Schema
const validationSchema = object().shape({
    copyright_text: string().optional(),
    app_store_link: string().url().optional(),
    google_play_link: string().url().optional(),
    google_maps_key: string().optional(),
});

// Computed
const oldHeaderLogoImage = computed(() => masjidStore.masjid?.header_logo?.original_url ?? undefined);
const oldFooterLogoImage = computed(() => masjidStore.masjid?.footer_logo?.original_url ?? undefined);

// Functions
async function fetchGeneralSettings() {
    if (masjidStore.masjid) {
        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/general-settings`)
            .then(res => {
                if (res.data?.status === 'success' && res.data?.data) {
                    const data = res.data.data;
                    settingsModel.value.copyright_text = data.copyright_text || '';
                    settingsModel.value.app_store_link = data.app_store_link || '';
                    settingsModel.value.google_play_link = data.google_play_link || '';
                    settingsModel.value.google_maps_key = data.google_maps_key || '';
                }
            })
            .catch((e: AxiosError) => {
                console.log('Get general settings error: \n', e);
            });
    }
}

function onHeaderLogoInputChange(data: UploadedImageInfo) {
    headerLogoFile.value = data.file;
    settingsModel.value.headerLogoSrc = data.src;
}

function onFooterLogoInputChange(data: UploadedImageInfo) {
    footerLogoFile.value = data.file;
    settingsModel.value.footerLogoSrc = data.src;
}

async function updateGeneralSettings() {
    updateSettingsLoading.value = true;
    QSwal.fire("Question", 'Update general settings?', 'question')
        .then(async (result) => {
            if (result.isConfirmed && masjidStore.masjid) {
                const formData = new FormData();

                if (headerLogoFile.value) {
                    formData.append('header_logo', headerLogoFile.value);
                }
                if (footerLogoFile.value) {
                    formData.append('footer_logo', footerLogoFile.value);
                }

                formData.append('copyright_text', settingsModel.value.copyright_text);
                formData.append('app_store_link', settingsModel.value.app_store_link);
                formData.append('google_play_link', settingsModel.value.google_play_link);
                formData.append('google_maps_key', settingsModel.value.google_maps_key);

                await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/general-settings`, formData)
                    .then(res => {
                        if (res.data?.status === 'success') {
                            QSwal.fire("Success", "General settings updated successfully.", "success");
                            // Refresh masjid data
                            masjidStore.fetchMasjid();
                        } else {
                            QSwal.fire("Sorry", getMessageFromObj(res), "warning");
                        }
                    })
                    .catch((e: AxiosError<BackendResponseData>) => {
                        QSwal.fire(e.message, getMessageFromObj(e), "error");
                    })
                    .finally(() => {
                        updateSettingsLoading.value = false;
                    });
            } else {
                updateSettingsLoading.value = false;
            }
        });
}

// Lifecycle
onBeforeMount(async () => {
    await fetchGeneralSettings();
});
</script>

<style scoped>
.dashboard-input {
    width: 100%;
}
</style>


