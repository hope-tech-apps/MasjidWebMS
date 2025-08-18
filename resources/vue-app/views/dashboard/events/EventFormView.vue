<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <!-- Title -->
        <div class="card-header bg-white border-0">
            <div class="card-title  fs-4 fw-semibold">
                <span v-if="isEditForm">Edit Event</span>
                <span v-else>Add New Event</span>
            </div>
        </div>

        <!-- Form Fields -->
        <div class="card-body d-flex flex-column gap-5 w-100">

            <!-- Inputs: title, place -->
            <div class="d-flex flex-column gap-5 flex-md-row justify-content-md-between">
                <ColumnInputContainer label="Title" name="title_input" :show_error="true" class="w-100 w-md-50">
                    <Field name="title_input" type="text" v-model="title" class="dashboard-input"
                        placeholder="title goes here"></Field>
                </ColumnInputContainer>
                <ColumnInputContainer label="Place" name="place_input" :show_error="true" class="w-100 w-md-50">
                    <Field name="place_input" type="text" v-model="place" class="dashboard-input"
                        placeholder="place goes here"></Field>
                </ColumnInputContainer>
            </div>

            <!-- Dates Inputs: start, end -->
            <div class="d-flex flex-column gap-5 flex-md-row justify-content-md-between">
                <ColumnInputContainer label="Start Date" name="start_date_input" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="start_date_input" type="date" v-model="start_date" class="dashboard-input"
                        placeholder="start date goes here"></Field>
                </ColumnInputContainer>
                <ColumnInputContainer label="End Date" name="end_date_input" :show_error="true" class="w-100 w-md-50">
                    <Field name="end_date_input" type="date" v-model="end_date" class="dashboard-input"
                        placeholder="end date goes here"></Field>
                </ColumnInputContainer>
            </div>

            <!-- Times Inputs: start, end -->
            <div class="d-flex flex-column gap-5 flex-md-row justify-content-md-between">
                <ColumnInputContainer label="Start Time" name="start_time_input" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="start_time_input" type="time" v-model="start_time" class="dashboard-input"
                        placeholder="start time goes here"></Field>
                </ColumnInputContainer>
                <ColumnInputContainer label="End Time" name="end_time_input" :show_error="true" class="w-100 w-md-50">
                    <Field name="end_time_input" type="time" v-model="end_time" class="dashboard-input"
                        placeholder="end time goes here"></Field>
                </ColumnInputContainer>
            </div>

            <!-- Details Input -->
            <ColumnInputContainer label="Details" name="details_input" :show_error="true" class="w-100">
                <Field name="details_input" as="textarea" v-model="details" class="dashboard-input"
                    placeholder="details goes here"></Field>
            </ColumnInputContainer>


            <!-- Link Input -->
            <ColumnInputContainer label="Link" name="link_input" :show_error="true" class="w-100 w-md-50">
                <Field name="link_input" type="text" v-model="link" class="dashboard-input reference-input"
                    placeholder="link goes here"></Field>
            </ColumnInputContainer>

        </div>

        <!-- Form Footer -->
        <div class="card-footer d-flex align-items-center justify-content-end w-100 bg-white border-0">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span v-if="isEditForm">Update Event</span>
                <span v-else>Add New</span>
            </LoadingButton>
        </div>

    </Form>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { Event } from '@/core/types/data/masjid-related/Event';
import { useEventsStore } from '@/stores/masjid/eventsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field } from 'vee-validate';
import { onBeforeMount, ref, toRefs, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { date, object, string, ref as yupRef } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {

    if (route.params?.event_id) {
        eventId.value = route.params.event_id as string;
        isEditForm.value = true;
        await eventStore.fetchEvent(route.params.event_id as string, event)
    } else {
        eventId.value = '';
        isEditForm.value = false;
    }

});

// Html refs

// Routing
const route = useRoute();
const router = useRouter();

// Stores
const eventStore = useEventsStore();
const masjidStore = useMasjidStore();

// Custom types
type EventEntry = {
    title: string;
    details: string;
    place: string;
    link: string;
    start_date: string;
    end_date: string;
    start_time: string;
    end_time: string;
};

// Custom constants
const isEditForm = ref(false);
const eventId = ref<string>('');
const event = ref<Event>();
const entryModel = ref<EventEntry>({
    title: '',
    details: '',
    place: '',
    link: '',
    start_date: '',
    end_date: '',
    start_time: '',
    end_time: ''
});

const {
    title,
    details,
    place,
    link,
    start_date,
    end_date,
    start_time,
    end_time
} = toRefs<EventEntry>(entryModel.value);

const isLoading = ref(false);

const TODAY = ref(new Date());

// Form
const formValidationSchema = object().shape({
    place_input: string().required().label('Place'),
    title_input: string().required().label('Title'),
    details_input: string().required().label('Details'),
    link_input: string().optional().label('Link'),
    start_date_input: date().required().min(TODAY.value.toISOString().split('T')[0]).label('Start Date'),
    end_date_input: date().required().min(yupRef('start_date_input')).label('End Date'),
    start_time_input: string().required()
        .test(
            'is-valid-time',
            'Invalid time format (use HH:MM)',
            (value) => /^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(value) // Validates HH:MM format
        ).label('Start Time'),
    end_time_input: string().required()
        .test(
            'is-valid-time',
            'Invalid time format (use HH:MM)',
            (value) => /^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(value) // Validates HH:MM format
        ).label('End Time')
        // .test(
        //     'is-after-start',
        //     'End time must be after start time',
        //     function (value, context) { // Use function() to access parent values
        //         if (!value || !context.parent.start_time_input) return true;

        //         // Convert HH:MM to total minutes for comparison
        //         const toMinutes = (time:string) => {
        //             const [h, m] = time.split(':').map(Number);
        //             return h * 60 + m;
        //         };

        //         return toMinutes(value) > toMinutes(context.parent.start_time_input);
        //     }
        // ).label('End Time')
});

// Computed

// Watch
watch([() => event.value, () => end_time.value], () => {
    if (event.value) {
        title.value = event.value.title,
            details.value = event.value.details,
            place.value = event.value.place,
            link.value = event.value.link,
            start_date.value = event.value.start.split(' ')[0],
            end_date.value = event.value.end.split(' ')[0],
            start_time.value = event.value.start.split(' ')[1].slice(0, 5),
            end_time.value = event.value.end.split(' ')[1].slice(0, 5)
    }
    console.log(end_time.value);

})

// Functions
const onSubmit = async () => {
    isLoading.value = true;
    let swalMessage = isEditForm.value ? "Update the event data?" : "Create a new event?";
    QSwal.fire("Question", swalMessage, 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                // Sed Request Sent Data
                let apiRequestData: FormData | URLSearchParams;

                if (isEditForm.value) {
                    apiRequestData = new URLSearchParams();
                } else {
                    apiRequestData = new FormData();
                }

                apiRequestData.append(`title`, title.value);
                apiRequestData.append(`details`, details.value);
                apiRequestData.append(`place`, place.value);
                apiRequestData.append(`link`, link.value);
                apiRequestData.append(`start`, `${start_date.value} ${start_time.value}`);
                apiRequestData.append(`end`, `${end_date.value} ${end_time.value}`);

                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };
                let apiEndpoint: BackendApiRoute | '' = '';
                if (masjidStore.masjid?.id) {
                    if (isEditForm.value) {
                        apiEndpoint = `/api/admin/masjids/${masjidStore.masjid.id}/events/${eventId.value}/`;
                    } else {
                        apiEndpoint = `/api/admin/masjids/${masjidStore.masjid.id}/events`;
                    }
                }
                if (isEditForm.value) {
                    await ApiService.put(apiEndpoint as BackendApiRoute, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Data changed successfully.";
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
                            await eventStore.fetchEvent(route.params.event_id as string, event).finally(() => {
                                MSwal.fire(swalInstance).finally(() => {
                                    router.push(`/masjid/events/${eventId.value}`);
                                });
                            });
                            isLoading.value = false;
                        });
                } else {
                    await ApiService.post(apiEndpoint as BackendApiRoute, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Data changed successfully.";
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
                            await eventStore.fetchMasjidEventsPaginated(1).finally(() => {
                                MSwal.fire(swalInstance).finally(() => {
                                    router.push('/masjid/events');
                                });
                            });
                            isLoading.value = false;
                        });
                }

            } else {
                isLoading.value = false;
            }
        })
}

</script>

<style scoped></style>