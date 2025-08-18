<template>
    <Form :validation-schema="validationSchema" @submit="onSubmit" class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
            <div class="card-title fs-4 fw-semibold">
                Notifications
            </div>
        </div>

        <div class="card-body w-100 d-flex flex-column gap-4">
            <ColumnInputContainer v-if="authStore.user?.type === 'SuperAdmin'" name="notification_masjid"
                label="Select Masjid" :show_error="true">
                <Field name="notification_masjid" type="text" v-model="notificationModel.masjid_id"
                    v-slot="{ field }">
                    <select v-bind="field" class="dashboard-input" :class="{'placeholder': !notificationModel.masjid_id}">
                        <option disabled selected hidden value="" class="" label="select masjid">
                            select masjid
                        </option>
                        <option v-for="masjid in masjids" :value="masjid.id" :label="masjid.name" />
                    </select>
                </Field>
            </ColumnInputContainer>
            <ColumnInputContainer name="notification_title" label="Notification Title" :show_error="true">
                <Field name="notification_title" type="text" v-model="notificationModel.title"
                    class="dashboard-input" placeholder="add title" />
            </ColumnInputContainer>
            <ColumnInputContainer name="notification_message" label="Notification Message" :show_error="true">
                <Field name="notification_message" as="textarea" v-model="notificationModel.message"
                    class="dashboard-input" placeholder="add notification message here" />
            </ColumnInputContainer>
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
import { computed, onBeforeMount, ref } from 'vue';
import { Form, Field } from 'vee-validate';
import { number, object, string } from 'yup';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import { SweetAlertOptions } from 'sweetalert2';
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import { useMasjidsStore } from '@/stores/super/masjidsStore';
import { useAuthStore } from '@/stores/authStore';
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';

// Lifecycle hooks
onBeforeMount(async () => {
    if (authStore.user?.type === 'SuperAdmin') {
        await masjidsStore.fetchMasjidsList();
    }
});

// Types
type NotificationEntry = {
    title: string;
    message: string;
    masjid_id: number | undefined;
}

// Stores
const authStore = useAuthStore();
const masjidStore = useMasjidStore();
const masjidsStore = useMasjidsStore();

// Custom constants
const isLoading = ref<boolean>(false);

// Computed
const masjids = computed(() => {
    return masjidsStore.masjids;
});

// Form
const validationSchema = object().shape({
    notification_title: string().required().label('Title'),
    notification_message: string().required().label('Message'),
    notification_masjid: (authStore.user?.type === 'SuperAdmin') ? number().required().label('Masjid') : string().nullable()
});
const notificationModel = ref<NotificationEntry>({
    title: '',
    message: '',
    masjid_id: undefined
});


// Functions

const onSubmit = async () => {

    isLoading.value = true;
    QSwal.fire("Question", 'Save the new notification?', 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                // Sed Request Sent Data
                const apiRequestData = new FormData();
                apiRequestData.append('title', notificationModel.value.title);
                apiRequestData.append('message', notificationModel.value.message);
                
                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                let masjidId = (authStore.user?.type === 'SuperAdmin') ? notificationModel.value.masjid_id : masjidStore.masjid?.id;

                if (masjidId) {

                    await ApiService.post(`/api/admin/masjids/${masjidId}/notifications`, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Notification saved successfully then broadcasted through the masjid channel.";
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
                            MSwal.fire(swalInstance);
                            isLoading.value = false;
                        });

                } else {

                    MSwal.fire('Sorry', 'Majid ID missed.', 'error');
                    isLoading.value = false;

                }

            } else {
                isLoading.value = false;
            }

        });
}

</script>

<style scoped>
textarea {
    height: 9rem;
}
</style>