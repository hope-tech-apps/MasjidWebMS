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
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Jumaa Shifts: richer per-khutbah entries (time + khateeb + khutbah title).
                     Optional; when empty the apps fall back to the Athans times above. -->
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div class="fs-5 fw-semibold">Jumaa Shifts (Khateeb details)</div>
                                <button type="button" @click.prevent="addShift"
                                    class="btn btn-primary btn-sm add-ref-btn">
                                    + Add Shift
                                </button>
                            </div>
                            <p class="text-muted small mb-3">
                                Add each khutbah with its Khateeb and topic. Leave empty to use the Athans times above.
                            </p>

                            <div v-if="shifts.length" class="d-flex flex-column gap-3">
                                <div v-for="(shift, i) in shifts" :key="i"
                                    class="border rounded p-3 bg-white">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="fw-semibold">Shift #{{ i + 1 }}</div>
                                        <button type="button" @click.prevent="removeShift(i)"
                                            class="btn btn-danger btn-sm add-ref-btn">
                                            - Remove
                                        </button>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-3">
                                            <ColumnInputContainer :label="`Time`"
                                                :name="`shift_${i + 1}_time`" :show_error="true">
                                                <div class="d-flex flex-column">
                                                    <Field :name="`shift_${i + 1}_time`" type="time"
                                                        v-model="shift.time" class="dashboard-input" />
                                                    <ErrorMessage :name="`shift_${i + 1}_time`"
                                                        class="error-message" />
                                                </div>
                                            </ColumnInputContainer>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <ColumnInputContainer :label="`Khateeb Name`"
                                                :name="`shift_${i + 1}_khateeb_name`" :show_error="true">
                                                <div class="d-flex flex-column">
                                                    <Field :name="`shift_${i + 1}_khateeb_name`" type="text"
                                                        v-model="shift.khateeb_name" class="dashboard-input"
                                                        placeholder="e.g. Imam Ahmad" maxlength="255" />
                                                    <ErrorMessage :name="`shift_${i + 1}_khateeb_name`"
                                                        class="error-message" />
                                                </div>
                                            </ColumnInputContainer>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <ColumnInputContainer :label="`Khateeb Title`"
                                                :name="`shift_${i + 1}_khateeb_title`" :show_error="true">
                                                <div class="d-flex flex-column">
                                                    <Field :name="`shift_${i + 1}_khateeb_title`" type="text"
                                                        v-model="shift.khateeb_title" class="dashboard-input"
                                                        placeholder="e.g. Head Imam" maxlength="255" />
                                                    <ErrorMessage :name="`shift_${i + 1}_khateeb_title`"
                                                        class="error-message" />
                                                </div>
                                            </ColumnInputContainer>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <ColumnInputContainer :label="`Khutbah Title`"
                                                :name="`shift_${i + 1}_khutbah_title`" :show_error="true">
                                                <div class="d-flex flex-column">
                                                    <Field :name="`shift_${i + 1}_khutbah_title`" type="text"
                                                        v-model="shift.khutbah_title" class="dashboard-input"
                                                        placeholder="e.g. Patience in Islam" maxlength="255" />
                                                    <ErrorMessage :name="`shift_${i + 1}_khutbah_title`"
                                                        class="error-message" />
                                                </div>
                                            </ColumnInputContainer>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="text-muted small fst-italic">
                                No shifts configured.
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
import { JumaaSetting, JumaaShift } from '@/core/types/data/masjid-related/JumaaSetting';

// Lifecycle hooks
onBeforeMount(async () => {
    await fetchJumaaSettings();
});

// Types
type SettingsModel = {
    athans: string[];
    shifts: JumaaShift[];
}

const emptyShift = (): JumaaShift => ({
    time: '',
    khateeb_name: '',
    khateeb_title: '',
    khutbah_title: '',
});

// Stores
const masjidStore = useMasjidStore();

// Custom constants
const jumaaSetting = ref<JumaaSetting>();
const isLoading = ref<boolean>(false);

const numberOfAthans = ref<number>(0);

// Computed
const athansFieldsNumber = computed(() => {
    return numberOfAthans.value;
});

// Form
const isValidTime = (value?: string) =>
    (!value) || /^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(value); // Validates HH:MM format

const validationSchema = computed(() => {
    let additionalValidation: any = {};
    if (athans.value?.length > 0) {
        athans.value.forEach((a, i) => {
            additionalValidation[`athan_${i + 1}_input`] = string()
                .test(
                    'is-valid-time',
                    'Invalid time format (use HH:MM)',
                    (value) => isValidTime(value)
                ).label('Jumaa Athan')
        });
    }
    if (shifts.value?.length > 0) {
        shifts.value.forEach((s, i) => {
            additionalValidation[`shift_${i + 1}_time`] = string()
                .required('Shift time is required')
                .test(
                    'is-valid-time',
                    'Invalid time format (use HH:MM)',
                    (value) => isValidTime(value)
                ).label('Shift Time');
            additionalValidation[`shift_${i + 1}_khateeb_name`] = string().nullable().max(255).label('Khateeb Name');
            additionalValidation[`shift_${i + 1}_khateeb_title`] = string().nullable().max(255).label('Khateeb Title');
            additionalValidation[`shift_${i + 1}_khutbah_title`] = string().nullable().max(255).label('Khutbah Title');
        });
    }
    return object().shape({
        ...additionalValidation
    });
});
const settingsModel = ref<SettingsModel>({
    athans: [],
    shifts: []
});

const { athans, shifts } = toRefs(settingsModel.value);

watch(() => jumaaSetting.value, () => {
    if (jumaaSetting.value) {
        settingsModel.value.athans = jumaaSetting.value.athans?.length ? jumaaSetting.value.athans : [];
        settingsModel.value.shifts = jumaaSetting.value.shifts?.length
            ? jumaaSetting.value.shifts.map(s => ({
                time: s.time ?? '',
                khateeb_name: s.khateeb_name ?? '',
                khateeb_title: s.khateeb_title ?? '',
                khutbah_title: s.khutbah_title ?? '',
            }))
            : [];
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

const addShift = () => {
    shifts.value.push(emptyShift());
}

const removeShift = (index: number) => {
    shifts.value.splice(index, 1);
}

const onSubmit = async () => {
    isLoading.value = true;
    QSwal.fire("Question", 'Change Jumaa settings ?', 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                // Sed Request Sent Data
                const apiRequestData = new FormData();

                if (athans.value?.length > 0) {
                    athans.value.forEach((a, i) => {
                        apiRequestData.append(`athans[${i}]`, a);
                    });
                }

                // Richer per-khutbah shifts. Sent alongside athans; the backend
                // normalizes empty metadata to null and stores null when no shifts.
                if (shifts.value?.length > 0) {
                    shifts.value.forEach((s, i) => {
                        apiRequestData.append(`shifts[${i}][time]`, s.time ?? '');
                        apiRequestData.append(`shifts[${i}][khateeb_name]`, s.khateeb_name ?? '');
                        apiRequestData.append(`shifts[${i}][khateeb_title]`, s.khateeb_title ?? '');
                        apiRequestData.append(`shifts[${i}][khutbah_title]`, s.khutbah_title ?? '');
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
