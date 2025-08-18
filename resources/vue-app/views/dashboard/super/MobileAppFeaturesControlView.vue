<template>
    <div class="card py-4 px-3 w-100">
        <div class="card-header bg-white border-0 m-0">
            <div class="card-title fs-5 fw-bold">
                Features Control
            </div>
        </div>
        <div class="card-body container">
            <div class="row justify-content-center">
                <div class="col-12">
                    <div class="table-responsive bg-white">
                        <table class="table m-0">
                            <thead>
                                <tr>
                                    <th scope="col" class="th-border">Icon</th>
                                    <th scope="col" class="th-border">Feature</th>
                                    <th scope="col" class="th-border">Control</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="feature in features">
                                    <tr class="border-0">
                                        <td class="border-0">
                                            <img :src="feature.icon.original_url" alt="icon" />
                                        </td>
                                        <td class="border-0 fw-bold">
                                            {{ feature.name }}
                                        </td>
                                        <td class="border-0">
                                            <div class="form-check form-switch">
                                                <!-- Toggle Switch -->
                                                <input class="form-check-input bg-danger" type="checkbox"
                                                    @click.prevent="toggleFeatureAvailability(feature.id, !feature.pivot.is_available)"
                                                    :checked="feature.pivot.is_available ? true : false" />
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { masjid } from '@/core/types/data/Masjid';
import { useMobileAppFeaturesStore } from '@/stores/masjid/mobileAppFeaturesStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { computed, onBeforeMount } from 'vue';

// Lifecycle hooks
onBeforeMount(async () => {
    await featuresStore.fetchMobileAppFeatures();
});

// Stores
const featuresStore = useMobileAppFeaturesStore();
const masjidStore = useMasjidStore();

// Custom constants

// Computed
const features = computed(() => {
    return featuresStore.features;
});

// Function
const toggleFeatureAvailability = (id: number, availability: boolean) => {

    if (id) {
        QSwal.fire("Question", "Are you sure that you want to change this feature availability?", 'question')
            .then(async (result) => {
                if (result.isConfirmed) {

                    const apiRequestData = new URLSearchParams();
                    apiRequestData.append('is_available', availability ? "1" : "0");

                    let swalInstance: SweetAlertOptions = {
                        title: "Info",
                        text: "Nothing",
                        icon: "info"
                    };

                    if (masjidStore.masjid?.id) {
                        await ApiService.put(`/api/admin/masjids/${masjidStore.masjid.id}/features/${id}/`, apiRequestData)
                            .then(res => {
                                if (res.data.status === 'success') {
                                    swalInstance.title = "Success";
                                    swalInstance.text = "Feature availability toggled successfully.";
                                    swalInstance.icon = "success";
                                } else {
                                    swalInstance.title = "Sorry";
                                    swalInstance.text = getMessageFromObj(res);
                                    swalInstance.icon = "warning";
                                }
                            })
                            .catch((e: AxiosError<BackendResponseData>) => {
                                console.log(e);
                                swalInstance.title = e.message;
                                swalInstance.text = getMessageFromObj(e);
                                swalInstance.icon = "error";
                            })
                            .finally(async () => {
                                await featuresStore.fetchMobileAppFeatures().finally(() => {
                                    MSwal.fire(swalInstance);
                                });
                            });
                    } else {
                        MSwal.fire('Sorry', 'The masjid ID missed.', 'error');
                    }
                }
            })
    } else {
        MSwal.fire('Sorry', 'The feature ID missed.', 'error');
    }
}

</script>

<style scoped>
.th-border {
    border: none;
    border-bottom: 1px solid var(--input-border);
}

.form-check-input,
.form-check-input:focus {
    width: 4rem;
    height: 2rem;
    border: none;
    --bs-form-switch-bg: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23F3F8FB'/%3e%3ctext x='0' y='0.1' font-size='2.5' font-weight='bold' text-anchor='middle' alignment-baseline='middle' fill='black' style='font-family:Poppins, sans-serif;'%3eOff%3c/text%3e%3c/svg%3e");
}

.form-check-input:checked,
.form-check-input:checked:focus {
    --bs-form-switch-bg: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23F3F8FB'/%3e%3ctext x='0' y='0.1' font-size='2.5' font-weight='bold' text-anchor='middle' alignment-baseline='middle' fill='black' style='font-family:Poppins, sans-serif;'%3eOn%3c/text%3e%3c/svg%3e");
    background-color: var(--cgreen) !important;
}
</style>