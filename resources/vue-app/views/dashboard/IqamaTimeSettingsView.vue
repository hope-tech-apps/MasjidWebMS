<template>
    <Form :validation-schema="validationSchema" @submit="onSubmit" class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
            <div class="card-title fs-4 fw-semibold">
                Iqama Times Settings
            </div>
        </div>

        <div class="card-body w-100">
            <div class="d-flex flex-column gap-4">
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-12">
                            <div class="table-responsive bg-white">
                                <table class="table m-0">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="th-border">Salah</th>
                                            <th scope="col" class="th-border">Iqama After - Minutes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template v-if="iqamaTimeSetting">
                                            <tr v-for="key in SALAH_KEYS" class="border-0">
                                                <td class="border-0 text-capitalize">
                                                    {{ key }}
                                                </td>
                                                <td class="border-0 fw-bold">
                                                    <div class="d-flex flex-column">
                                                        <Field :name="`${key}_iqama_setting`" type="number"
                                                            v-model="settingsModel[key as keyof SettingsModel]"
                                                            class="dashboard-input" />
                                                        <ErrorMessage :name="`${key}_iqama_setting`"
                                                            class="error-message" />
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
        </div>

        <div class="card-footer bg-white border-0 d-flex justify-content-end">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                Save Changes
            </LoadingButton>
        </div>
    </Form>
</template>

<script setup lang="ts">
import LoadingButton from '@/components/form/LoadingButton.vue';
import ApiService from '@/core/services/ApiService';
import { IqamaTimeSetting } from '@/core/types/data/masjid-related/IqamaTimeSetting';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { onBeforeMount, ref, toRefs, watch } from 'vue';
import { Form, Field, ErrorMessage } from 'vee-validate';
import { number, object } from 'yup';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import { SweetAlertOptions } from 'sweetalert2';
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';

// Lifecycle hooks
onBeforeMount(async () => {
    await fetchIqamaSettings();
});

// Types
type SettingsModel = {
    fajr: number;
    dhuhr: number;
    asr: number;
    maghrib: number;
    isha: number;
}

// Stores
const masjidStore = useMasjidStore();

// Custom constants
const iqamaTimeSetting = ref<IqamaTimeSetting>();
const SALAH_KEYS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];
const isLoading = ref<boolean>(false);

// Form
const validationSchema = object().shape({
    fajr_iqama_setting: number().positive().required().label('Fajr iqama setting'),
    dhuhr_iqama_setting: number().positive().required().label('Dhuhr iqama setting'),
    asr_iqama_setting: number().positive().required().label('Asr iqama setting'),
    maghrib_iqama_setting: number().positive().required().label('Maghrib iqama setting'),
    isha_iqama_setting: number().positive().required().label('Isha iqama setting')
});
const settingsModel = ref<SettingsModel>({
    fajr: 0,
    dhuhr: 0,
    asr: 0,
    maghrib: 0,
    isha: 0
});

watch(() => iqamaTimeSetting.value, () => {
    if (iqamaTimeSetting.value) {
        settingsModel.value.fajr = iqamaTimeSetting.value.fajr;
        settingsModel.value.dhuhr = iqamaTimeSetting.value.dhuhr;
        settingsModel.value.asr = iqamaTimeSetting.value.asr;
        settingsModel.value.maghrib = iqamaTimeSetting.value.maghrib;
        settingsModel.value.isha = iqamaTimeSetting.value.isha;
    }
})

// Functions
const fetchIqamaSettings = async () => {
    if (masjidStore.masjid?.id) {
        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/iqama`)
            .then(res => {
                if (res.data?.status === 'success' && res.data?.data) {
                    iqamaTimeSetting.value = res.data.data;
                }
            })
            .catch((e: AxiosError) => {
                console.log('Get iqama setting error: \n', e);
            });
    }
}

const onSubmit = async () => {
    isLoading.value = true;
    QSwal.fire("Question", 'Change iqama settings?', 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                // Sed Request Sent Data
                const apiRequestData = new FormData();

                SALAH_KEYS.forEach(k => {
                    apiRequestData.append(k, settingsModel.value[k as keyof SettingsModel] + '');
                });

                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjidStore.masjid?.id) {

                    await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/iqama`, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Iqama settings changed successfully.";
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
                            await fetchIqamaSettings().finally(() => {
                                MSwal.fire(swalInstance);
                            });
                            isLoading.value = false;
                        });
                } else {
                    MSwal.fire('Sorry', 'Majid ID missed.', 'error');
                    isLoading.value = false;
                }
            } else {
                isLoading.value = false;
            }

        })
}

</script>

<style scoped>
.dashboard-input {
    width: 6rem;
    height: 2rem;
    padding-top: .1rem;
    font-size: 1rem;
}

.error-message {
    font-weight: 400;
}
</style>