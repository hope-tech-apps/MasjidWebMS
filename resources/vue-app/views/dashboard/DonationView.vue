<template>
    <Form @submit="onSubmit" :validation-schema="validationSchema" class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
            <div class="card-title fs-4 fw-semibold">
                Donation Link Settings
            </div>
            <div class="card-toolbar">

            </div>
        </div>

        <div class="card-body d-flex flex-column gap-5 w-100">

            <!-- Donation Image -->
            <div class="d-flex flex-column">
                <ImageDraggableInput label="Donation Image"
                    @imageChange="(data: UploadedImageInfo) => onImageInputChange(data)"
                    :current-image-src="oldImage" type="photo" />
                <Field type="file" v-model="imageSrc" name="donation_image" class="d-none"></Field>
                <div class="error-message">
                    <ErrorMessage name="donation_image" />
                </div>
            </div>

            <!-- Donation Title -->
            <ColumnInputContainer name="donation_title_input" label="Donation Title" :show_error="true" class="w-100">
                <Field name="donation_title_input" type="text" v-model="donationTitle" class="dashboard-input"
                    placeholder="e.g. Support Our Masjid" />
            </ColumnInputContainer>

            <!-- Donation Description -->
            <ColumnInputContainer name="donation_message_input" label="Donation Description" :show_error="true"
                class="w-100">
                <Field name="donation_message_input" as="textarea" v-model="donationMessage" class="dashboard-input"
                    placeholder="description goes here" maxlength="255"></Field>
                <div class="text-end text-muted small mt-1">
                    {{ donationMessage?.length || 0 }} / 255 characters
                </div>
            </ColumnInputContainer>

            <!-- Donation Link -->
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
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { DonationLink } from '@/core/types/data/masjid-related/DonationLink';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field, ErrorMessage, useForm } from "vee-validate";
import { computed, onBeforeMount, ref } from 'vue';
import { object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {
    await fetchAndSetDonationLink();
});

// Stores
const masjidStore = useMasjidStore();

// Custom constants
const donationLinkData = ref<DonationLink>();
const donationLink = ref<string>('');
const donationTitle = ref<string>('');
const donationMessage = ref<string>('');
const imageSrc = ref<string | undefined>('');
const imageFile = ref<File | undefined>(undefined);
const isLoading = ref<boolean>(false);

// Form
const validationSchema = computed(() => {
    // Image is required on first setup, optional once one already exists.
    const hasImage = !!donationLinkData.value?.image?.original_url;
    return object().shape({
        donation_title_input: string().required('Donation title is required').max(255),
        donation_message_input: string().required('Donation description is required').max(255),
        donate_link_input: string().url().required('Donation link is required'),
        donation_image: hasImage ? string() : string().required('Donation image is required')
    });
});

const { setFieldValue } = useForm({ validationSchema: validationSchema });

// Computed
const oldImage = computed(() => donationLinkData.value?.image?.original_url ?? undefined);

// Functions
const onImageInputChange = (data: UploadedImageInfo) => {
    setFieldValue('donation_image', data.src);
    imageSrc.value = data.src;
    imageFile.value = data.file;
};

const fetchAndSetDonationLink = async () => {
    if (masjidStore.masjid?.id) {
        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/donation-link`)
            .then(res => {
                if (res.data?.status === 'success' && res.data?.data) {
                    const data = res.data.data as DonationLink;
                    donationLinkData.value = data;
                    donationLink.value = data.link ?? '';
                    donationTitle.value = data.title ?? '';
                    donationMessage.value = data.message ?? '';
                    if (data.image?.original_url) {
                        imageSrc.value = data.image.original_url;
                        setFieldValue('donation_image', data.image.original_url);
                    }
                }
            })
            .catch((e: AxiosError) => {
                console.log(e);
            });
    }
}

const onSubmit = () => {
    QSwal.fire('Warning', 'Are you sure? the donation details will be updated !', 'warning')
        .then(async result => {
            if (!masjidStore.masjid?.id) {
                MSwal.fire('Sorry', 'The masjid ID is not specified by the system!', 'error');
            } else if (result.isConfirmed) {
                isLoading.value = true;
                let swalInstance: SweetAlertOptions = {
                    title: 'Info',
                    text: 'Nothing',
                    icon: 'info'
                };

                const formData = new FormData();
                formData.append('link', donationLink.value);
                formData.append('title', donationTitle.value);
                formData.append('message', donationMessage.value);
                if (imageFile.value) {
                    formData.append('image', imageFile.value);
                }

                await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/donation-link`, formData)
                    .then(res => {
                        if (res.data.status === 'success') {
                            swalInstance.title = 'Success';
                            swalInstance.text = 'Donation details saved successfully.';
                            swalInstance.icon = 'success';
                        } else {
                            swalInstance.title = 'Sorry';
                            swalInstance.text = getMessageFromObj(res);
                            swalInstance.icon = 'warning';
                        }
                    })
                    .catch((e: AxiosError<BackendResponseData>) => {
                        console.log(e);
                        swalInstance.title = e.response?.status === 422 ? 'Validation Error' : e.message;
                        swalInstance.text = getMessageFromObj(e);
                        swalInstance.icon = 'error';
                    })
                    .finally(async () => {
                        await fetchAndSetDonationLink();
                        isLoading.value = false;
                        MSwal.fire(swalInstance);
                    });
            }
        })
}
</script>
