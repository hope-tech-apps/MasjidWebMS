<template>
    <Form :validation-schema="validationSchema" @submit="onSubmit" class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
            <div class="card-title fs-4 fw-semibold">
                Iqama Times Settings
            </div>
        </div>

        <div class="card-body w-100">
            <div class="d-flex flex-column gap-4">
                <!-- Iqama Type Selection -->
                <div class="container">
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label fw-semibold">Iqama Calculation Type</label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="iqamaType"
                                        id="minutesAfterAdhan"
                                        value="minutes_after_adhan"
                                        v-model="iqamaType"
                                    >
                                    <label class="form-check-label" for="minutesAfterAdhan">
                                        Minutes After Adhan
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="iqamaType"
                                        id="specificTimeRanges"
                                        value="specific_time_ranges"
                                        v-model="iqamaType"
                                    >
                                    <label class="form-check-label" for="specificTimeRanges">
                                        Specific Time Ranges
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Show Iqama Times Flag -->
                        <div class="col-12 mb-3">
                            <div class="form-check form-switch">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="showIqamaTimes"
                                    v-model="showIqamaTimes"
                                >
                                <label class="form-check-label" for="showIqamaTimes">
                                    Show Iqama Times in Mobile App
                                </label>
                            </div>
                            <small class="text-muted">
                                When enabled, iqama times will be displayed in the mobile application.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Minutes After Adhan Mode -->
                <div class="container" v-if="iqamaType === 'minutes_after_adhan'">
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
                                            <tr v-for="key in SALAH_KEYS" :key="key" class="border-0">
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

                <!-- Specific Time Ranges Mode -->
                <div class="container" v-if="iqamaType === 'specific_time_ranges'">
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-info mb-4">
                                <i class="bi bi-info-circle"></i>
                                <strong>Note:</strong> Add date ranges for each salah to specify when different iqama times should be used.
                                For example, you can set different iqama times for winter and summer months.
                            </div>

                            <div v-for="salah in SALAH_KEYS" :key="salah" class="mb-4">
                                <h5 class="text-capitalize mb-3 fw-bold">{{ salah }} Iqama Times</h5>

                                <div v-if="timeRanges[salah].length === 0" class="alert alert-warning">
                                    No time ranges added yet. Click the button below to add a time range.
                                </div>

                                <div v-for="(range, index) in timeRanges[salah]" :key="`${salah}-${index}`" class="card mb-3 p-3 shadow-sm">
                                    <div class="row g-3 align-items-end">
                                        <!-- Date Range Picker -->
                                        <div class="col-md-5">
                                            <label class="form-label fw-semibold">Date Range</label>
                                            <VueDatePicker
                                                v-model="range.dateRange"
                                                range
                                                :enable-time-picker="false"
                                                :time-config="{ enableTimePicker: false }"
                                                format="yyyy-MM-dd"
                                                placeholder="Select date range"
                                                @update:model-value="updateDateRange(salah, index)"
                                                auto-apply
                                            />
                                            <Field
                                                :name="`${salah}_range_${index}_date_range`"
                                                type="hidden"
                                                v-model="range.date_range"
                                            />
                                            <ErrorMessage :name="`${salah}_range_${index}_date_range`" class="error-message text-danger small" />
                                        </div>

                                        <!-- Iqama Time -->
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Iqama Time</label>
                                            <Field
                                                :name="`${salah}_range_${index}_specific_time`"
                                                type="time"
                                                v-model="range.specific_time"
                                                class="form-control"
                                            />
                                            <ErrorMessage :name="`${salah}_range_${index}_specific_time`" class="error-message text-danger small" />
                                        </div>

                                        <!-- Remove Button -->
                                        <div class="col-md-3">
                                            <button
                                                type="button"
                                                class="btn btn-danger btn-sm w-100"
                                                @click="removeTimeRange(salah, index)"
                                            >
                                                <i class="bi bi-trash"></i> Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    @click="addTimeRange(salah)"
                                >
                                    <i class="bi bi-plus-circle"></i> Add Time Range for {{ salah }}
                                </button>
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
import { IqamaTimeSetting, IqamaType } from '@/core/types/data/masjid-related/IqamaTimeSetting';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { computed, onBeforeMount, ref, watch } from 'vue';
import { Form, Field, ErrorMessage } from 'vee-validate';
import { number, object, string, date } from 'yup';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import { SweetAlertOptions } from 'sweetalert2';
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

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

type TimeRange = {
    dateRange: Date[] | null;  // Array of [startDate, endDate] for the picker
    date_range: string;  // Format: "YYYY-MM-DD to YYYY-MM-DD" for validation
    start_date: string;
    end_date: string;
    specific_time: string;
}

type TimeRangesModel = {
    fajr: TimeRange[];
    dhuhr: TimeRange[];
    asr: TimeRange[];
    maghrib: TimeRange[];
    isha: TimeRange[];
}

// Stores
const masjidStore = useMasjidStore();

// Custom constants
const iqamaTimeSetting = ref<IqamaTimeSetting>();
const SALAH_KEYS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'] as const;
const isLoading = ref<boolean>(false);
const iqamaType = ref<IqamaType>('minutes_after_adhan');
const showIqamaTimes = ref<boolean>(true);

// Form
const validationSchema = computed(() => {
    if (iqamaType.value === 'minutes_after_adhan') {
        return object().shape({
            fajr_iqama_setting: number().positive().required().label('Fajr iqama setting'),
            dhuhr_iqama_setting: number().positive().required().label('Dhuhr iqama setting'),
            asr_iqama_setting: number().positive().required().label('Asr iqama setting'),
            maghrib_iqama_setting: number().positive().required().label('Maghrib iqama setting'),
            isha_iqama_setting: number().positive().required().label('Isha iqama setting')
        });
    } else {
        // Dynamic validation for time ranges
        const schema: any = {};
        SALAH_KEYS.forEach(salah => {
            timeRanges.value[salah].forEach((range, index) => {
                schema[`${salah}_range_${index}_date_range`] = string()
                    .required()
                    .test(
                        'is-valid-date-range',
                        'Invalid date range format. Use: YYYY-MM-DD to YYYY-MM-DD',
                        function(value) {
                            if (!value) return false;
                            const regex = /^(\d{4}-\d{2}-\d{2})\s+to\s+(\d{4}-\d{2}-\d{2})$/;
                            const match = value.match(regex);
                            if (!match) return false;

                            const startDate = new Date(match[1]);
                            const endDate = new Date(match[2]);

                            // Check if dates are valid
                            if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) return false;

                            // Check if end date is after or equal to start date
                            return endDate >= startDate;
                        }
                    )
                    .label('Date Range');
                schema[`${salah}_range_${index}_specific_time`] = string().required().label('Iqama Time');
            });
        });
        return object().shape(schema);
    }
});

const settingsModel = ref<SettingsModel>({
    fajr: 0,
    dhuhr: 0,
    asr: 0,
    maghrib: 0,
    isha: 0
});

const timeRanges = ref<TimeRangesModel>({
    fajr: [],
    dhuhr: [],
    asr: [],
    maghrib: [],
    isha: []
});

watch(() => iqamaTimeSetting.value, (newValue) => {
    if (newValue) {
        console.log('Loading iqama settings:', newValue);

        // Set iqama type
        iqamaType.value = newValue.iqama_type || 'minutes_after_adhan';

        // Set show iqama times flag
        showIqamaTimes.value = newValue.show_iqama_times ?? true;

        // Load minutes after adhan settings
        settingsModel.value.fajr = newValue.fajr || 0;
        settingsModel.value.dhuhr = newValue.dhuhr || 0;
        settingsModel.value.asr = newValue.asr || 0;
        settingsModel.value.maghrib = newValue.maghrib || 0;
        settingsModel.value.isha = newValue.isha || 0;

        // Reset time ranges first
        timeRanges.value = {
            fajr: [],
            dhuhr: [],
            asr: [],
            maghrib: [],
            isha: []
        };

        // Load time ranges if they exist
        if (newValue.time_ranges && Array.isArray(newValue.time_ranges) && newValue.time_ranges.length > 0) {
            console.log('Loading time ranges:', newValue.time_ranges);

            // Group time ranges by salah
            newValue.time_ranges.forEach(range => {
                if (range.salah && timeRanges.value[range.salah]) {
                    const startDate = range.start_date || '';
                    const endDate = range.end_date || '';
                    const dateRange = startDate && endDate ? `${startDate} to ${endDate}` : '';

                    // Create Date objects for the picker
                    let dateRangeArray: Date[] | null = null;
                    if (startDate && endDate) {
                        dateRangeArray = [new Date(startDate), new Date(endDate)];
                    }

                    timeRanges.value[range.salah].push({
                        dateRange: dateRangeArray,
                        date_range: dateRange,
                        start_date: startDate,
                        end_date: endDate,
                        specific_time: range.specific_time || ''
                    });
                }
            });

            console.log('Loaded time ranges:', timeRanges.value);
        }
    }
}, { immediate: true, deep: true })

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

const addTimeRange = (salah: typeof SALAH_KEYS[number]) => {
    timeRanges.value[salah].push({
        dateRange: null,
        date_range: '',
        start_date: '',
        end_date: '',
        specific_time: ''
    });
}

const removeTimeRange = (salah: typeof SALAH_KEYS[number], index: number) => {
    timeRanges.value[salah].splice(index, 1);
}

const formatDate = (date: Date): string => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

const updateDateRange = (salah: typeof SALAH_KEYS[number], index: number) => {
    const range = timeRanges.value[salah][index];

    if (range.dateRange && Array.isArray(range.dateRange) && range.dateRange.length === 2) {
        const [startDate, endDate] = range.dateRange;

        range.start_date = formatDate(startDate);
        range.end_date = formatDate(endDate);
        range.date_range = `${range.start_date} to ${range.end_date}`;

        console.log(`Updated date range for ${salah}[${index}]:`, range.start_date, 'to', range.end_date);
    } else {
        range.start_date = '';
        range.end_date = '';
        range.date_range = '';
    }
}

const onSubmit = async () => {
    isLoading.value = true;
    QSwal.fire("Question", 'Change iqama settings?', 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                // Prepare Request Data
                const apiRequestData: any = {
                    iqama_type: iqamaType.value,
                    show_iqama_times: showIqamaTimes.value
                };

                if (iqamaType.value === 'minutes_after_adhan') {
                    SALAH_KEYS.forEach(k => {
                        apiRequestData[k] = settingsModel.value[k as keyof SettingsModel];
                    });
                } else {
                    // Prepare time ranges - only include non-empty ranges
                    const allTimeRanges: any[] = [];
                    SALAH_KEYS.forEach(salah => {
                        timeRanges.value[salah].forEach(range => {
                            // Only add if all fields are filled
                            if (range.start_date && range.end_date && range.specific_time) {
                                allTimeRanges.push({
                                    salah: salah,
                                    start_date: range.start_date,
                                    end_date: range.end_date,
                                    specific_time: range.specific_time
                                });
                            }
                        });
                    });
                    apiRequestData.time_ranges = allTimeRanges;
                }

                console.log('Submitting iqama settings:', apiRequestData);

                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjidStore.masjid?.id) {

                    await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/iqama`, apiRequestData)
                        .then(res => {
                            console.log('Save response:', res.data);
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
                            console.error('Save error:', e);
                            console.error('Error response:', e.response?.data);
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
    width: 6rem;
    height: 2rem;
    padding-top: .1rem;
    font-size: 1rem;
}

.error-message {
    font-weight: 400;
}
</style>
