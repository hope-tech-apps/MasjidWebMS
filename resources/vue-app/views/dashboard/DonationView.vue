<template>
    <Form @submit="onSubmit" :validation-schema="validationSchema" class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
            <div class="card-title fs-4 fw-semibold">
                Donation Link Settings
            </div>
            <div class="card-toolbar">

            </div>
        </div>

        <div class="card-body w-100">
            <ColumnInputContainer name="donate_link_input" label="Donate Link" :show_error="true" class="w-100">
                <Field name="donate_link_input" type="text" v-model="donationLink" class="dashboard-input"
                    placeholder="https://example.website/donation/link" />
            </ColumnInputContainer>
        </div>

        <div class="card-footer bg-white border-0 d-flex align-items-center justify-content-end w-100">
            <LoadingButton type="submit" :is-loading="isLoading">
                Save Changes
            </LoadingButton>
        </div>
    </Form>
</template>

<script setup lang="ts">
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { Form, Field } from "vee-validate";
import { onBeforeMount, ref } from 'vue';
import { object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {
    await fetchAndSetDonationLink();
});

// Stores
const masjidStore = useMasjidStore();

// Form
const validationSchema = object().shape({
    donate_link_input: string().url().required()
});
const donationLink = ref<string>('');
const isLoading = ref<boolean>(false);

const fetchAndSetDonationLink = async () => {
    if (masjidStore.masjid?.id) {
        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/donation-link`)
            .then(res => {
                if (res.data?.status === 'success' && res.data?.data?.link) {
                    donationLink.value = res.data.data.link;
                }
            })
            .catch((e: AxiosError) => {
                console.log(e);
            });
    }
}

const onSubmit = () => {
    if (donationLink.value) {
        QSwal.fire('Warning', 'Are you sure? the donation link wll be updated !', 'warning')
            .then(async result => {
                if (!masjidStore.masjid?.id) {
                    MSwal.fire('Sorry', 'The masjid ID is not specified by the system!', 'error');
                } else if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('link', donationLink.value);
                    ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/donation-link`, formData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                MSwal.fire('Success', 'Donation link saved successfully.', 'success');
                            }
                        })
                        .catch((e: AxiosError) => {
                            if (e.response?.data) {
                                console.log(e.response.data);
                                MSwal.fire('Error', e.response.data.toString(), 'error');
                            }
                        })
                        .finally(async () => {
                            await fetchAndSetDonationLink();
                        });
                }
            })
    }
}
</script>