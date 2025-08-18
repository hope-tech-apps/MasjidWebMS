<template>
    <Form :validation-schema="validationSchema" @submit="onSubmit" class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
            <div class="card-title fs-4 fw-semibold">
                Jumaa Salah Settings
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
                                            <th scope="col" class="th-border">Setting</th>
                                            <th scope="col" class="th-border">Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="border-0">
                                            <td class="border-0 text-capitalize">
                                                Athans
                                            </td>
                                            <td class="border-0 fw-bold">
                                                <!-- References Inputs -->
                                                <div class="d-flex gap-2">
                                                    <template v-if="athansFieldsNumber">
                                                        <div class="d-flex flex-column gap-2">
                                                            <ColumnInputContainer v-for="x in athansFieldsNumber"
                                                                :label="`Reference ${x} Title`"
                                                                :name="`athan_${x}_input`" :show_error="true"
                                                                class="w-100 w-md-25">
                                                                <div class="d-flex flex-column">
                                                                    <Field :name="`athan_${x}_input`" type="time"
                                                                        v-model="athans[x - 1]" class="dashboard-input"
                                                                        :placeholder="`athan ${x} title goes here`">
                                                                    </Field>
                                                                    <ErrorMessage :name="`athan_${x}_input`"
                                                                        class="error-message" />
                                                                </div>
                                                            </ColumnInputContainer>
                                                        </div>
                                                    </template>
                                                    <div class="d-flex gap-2">
                                                        <button type="button"
                                                            @click.prevent="changeAthanFieldsNumber('add')"
                                                            class="btn btn-primary btn-sm align-self-end add-ref-btn">
                                                            + Add
                                                        </button>
                                                        <button type="button"
                                                            @click.prevent="changeAthanFieldsNumber('remove')"
                                                            class="btn btn-danger btn-sm align-self-end add-ref-btn"
                                                            :disabled="athansFieldsNumber <= 0"
                                                            v-if="athansFieldsNumber > 0">
                                                            - Remove
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr class="border-0">
                                            <td class="border-0 text-capitalize">
                                                Iqama
                                            </td>
                                            <td class="border-0 fw-bold">
                                                <div class="d-flex flex-column">
                                                    <Field :name="`iqama_setting`" type="time"
                                                        v-model="settingsModel.iqama" class="dashboard-input" />
                                                    <ErrorMessage name="iqama_setting" class="error-message" />
                                                </div>
                                            </td>
                                        </tr>
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
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { computed, onBeforeMount, ref, toRefs, watch } from 'vue';
import { Form, Field, ErrorMessage } from 'vee-validate';
import { object, string } from 'yup';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import { SweetAlertOptions } from 'sweetalert2';
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { JumaaSetting } from '@/core/types/data/masjid-related/JumaaSetting';

// Lifecycle hooks
onBeforeMount(async () => {
    await fetchJumaaSettings();
});

// Types
type SettingsModel = {
    iqama: string;
    athans: string[];
}

// Stores
const masjidStore = useMasjidStore();

// Custom constants
const jumaaSetting = ref<JumaaSetting>();
const SETTING_KEYS = ['iqama'];
const isLoading = ref<boolean>(false);

const numberOfAthans = ref<number>(0);

// Computed
const athansFieldsNumber = computed(() => {
    return numberOfAthans.value;
});

// Convert HH:MM to total minutes for comparison
const toMinutes = (time: string) => {
    const [h, m] = time.split(':').map(Number);
    return h * 60 + m;
};

// Form
const primaryValidation = {
    iqama_setting: string()
        .test(
            'is-valid-time',
            'Invalid time format (use HH:MM)',
            (value) => (!value) || /^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(value) // Validates HH:MM format
        ).label('Jumaa Iqama')
};
const validationSchema = computed(() => {
    let additionalValidation: any = {};
    if (athans.value?.length > 0) {
        athans.value.forEach((a, i) => {
            additionalValidation[`athan_${i + 1}_input`] = string()
                .test(
                    'is-valid-time',
                    'Invalid time format (use HH:MM)',
                    (value) => (!value) || /^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(value) // Validates HH:MM format
                ).test(
                    'is-before-iqama',
                    'Athan time must be before iqama time',
                    function (value, context) { // Use function() to access parent values
                        if (!value || !context.parent.iqama_setting) return true;
                        return toMinutes(value) < toMinutes(context.parent.iqama_setting);
                    }
                ).label('Jumaa Athan')
        });
    }
    return object().shape({
        ...primaryValidation,
        ...additionalValidation
    });
});
const settingsModel = ref<SettingsModel>({
    iqama: "",
    athans: []
});

const { athans } = toRefs(settingsModel.value);

watch(() => jumaaSetting.value, () => {
    console.log(jumaaSetting.value);
    if (jumaaSetting.value) {
        settingsModel.value.iqama = jumaaSetting.value.iqama.slice(0, 5);
        settingsModel.value.athans = jumaaSetting.value.athans?.length ? jumaaSetting.value.athans : [];
    }
    numberOfAthans.value = settingsModel.value.athans?.length || 0;
})

// Functions
const fetchJumaaSettings = async () => {
    if (masjidStore.masjid?.id) {
        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/jumaa`)
            .then(res => {
                if (res.data?.status === 'success' && res.data?.data) {
                    jumaaSetting.value = res.data.data;
                }
            })
            .catch((e: AxiosError) => {
                console.log('Get jumaa setting error: \n', e);
            });
    }
}

const changeAthanFieldsNumber = (action: 'add' | 'remove') => {
    if (action === 'add') {
        numberOfAthans.value++;
        athans.value.push("");
    } else if (action === 'remove' && numberOfAthans.value > 0) {
        numberOfAthans.value--;
        athans.value.pop();
    }
}

const onSubmit = async () => {
    isLoading.value = true;
    QSwal.fire("Question", 'Change Jumaa settings ?', 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                // Sed Request Sent Data
                const apiRequestData = new FormData();

                SETTING_KEYS.forEach(k => {
                    apiRequestData.append(k, settingsModel.value[k as keyof SettingsModel] + '');
                });

                if (athans.value?.length > 0) {
                    athans.value.forEach((a, i) => {
                        apiRequestData.append(`athans[${i}]`, a);
                    });
                }

                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjidStore.masjid?.id) {

                    await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/jumaa`, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Jumaa settings changed successfully.";
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
                            await fetchJumaaSettings().finally(() => {
                                MSwal.fire(swalInstance);
                            });
                            isLoading.value = false;
                        });
                } else {
                    MSwal.fire('Sorry', 'Masjid ID missed.', 'error');
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
    width: 10rem;
    height: 2.2rem;
    padding-top: .1rem;
    font-size: 1rem;
}

.error-message {
    font-weight: 400;
}
</style>