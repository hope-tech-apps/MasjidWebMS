<template>
    <Form :validation-schema="validationSchema" @submit="updateMasjidDetails" class="card border-0 py-4 px-3">
        <div class="card-header bg-white border-0">
            <div class="card-title fs-4 fw-semibold">
                Mosque Details
            </div>
        </div>

        <div class="card-body d-flex flex-column gap-5 overflow-auto">

            <!-- Basic Info Group -->
            <div class="d-flex flex-column gap-3">
                <span class="d-block fs-5 fw-semibold">Basic Info</span>
                <div class="d-flex flex-column gap-3 w-100">
                    <!-- Logo Image Input -->
                    <div class="d-flex flex-column">
                        <Field name="logo_image" v-model="detailsModel.logoSrc" v-slot="{ field }">
                            <ImageDraggableInput
                                label="Logo"
                                :current-image-src="oldAvatarImage"
                                @image-change="onLogoChange"
                            />
                        </Field>
                        <ErrorMessage name="logo_image" class="error-message" />
                    </div>

                    <ColumnInputContainer label="Name" name="masjid_name" :show_error="true" class="w-100">
                        <Field name="masjid_name" type="text" v-model="detailsModel.name" class="dashboard-input"
                            placeholder="masjid name goes here"></Field>
                    </ColumnInputContainer>
                    <ColumnInputContainer label="Website Link" name="masjid_website_link" :show_error="true" class="w-100">
                        <Field name="masjid_website_link" type="text" v-model="detailsModel.website_link" class="dashboard-input"
                            placeholder="masjid name goes here"></Field>
                    </ColumnInputContainer>
                </div>
            </div>

            <!-- Contact Info Inputs Group -->
            <div class="d-flex flex-column gap-3">
                <span class="d-block fs-5 fw-semibold">Contact Info</span>
                <div class="d-flex flex-column flex-md-row gap-3 justify-content-md-between w-100">
                    <ColumnInputContainer label="Email Address" name="masjid_email" :show_error="true"
                        class="w-100 w-md-50">
                        <Field name="masjid_email" type="text" v-model="detailsModel.email" class="dashboard-input"
                            placeholder="example@example.com"></Field>
                    </ColumnInputContainer>
                    <ColumnInputContainer label="Phone Number" name="masjid_phone" :show_error="true"
                        class="w-100 w-md-50">
                        <Field name="masjid_phone" type="text" v-model="phone" v-slot="{ field }"
                            placeholder="+971 *** *** ****">
                            <vue-tel-input v-bind="field" v-model="phone"
                                @country-changed="(country: VueTelInputCountry) => { phone = applyCountryDialCode(phone, country) }"
                                class="dashboard-input">
                            </vue-tel-input>
                        </Field>
                    </ColumnInputContainer>
                </div>
            </div>

            <!-- Location Info Inputs Group -->
            <div class="d-flex flex-column gap-3">
                <span class="d-block fs-5 fw-semibold">Location Info</span>
                <div class="d-flex flex-column gap-3 w-100">
                    <ColumnInputContainer label="Timezone" name="masjid_timezone" :show_error="true" class="w-100">
                        <Field name="masjid_timezone" v-model="detailsModel.timezone" v-slot="{ value, handleChange }">
                            <SearchableSelect
                                v-if="timezones.length > 0"
                                :model-value="(value as string) ?? ''"
                                @update:model-value="(val: string) => { detailsModel.timezone = val; handleChange(val); }"
                                :options="timezones"
                                placeholder="Select timezone..."
                            />
                            <input
                                v-else
                                type="text"
                                class="dashboard-input"
                                placeholder="Loading timezones..."
                                disabled
                            />
                        </Field>
                    </ColumnInputContainer>
                    <div class="d-flex flex-column flex-md-row gap-3 justify-content-md-between w-100">
                        <ColumnInputContainer label="Latitude" name="masjid_latitude" :show_error="true"
                            class="w-100 w-md-50">
                            <Field name="masjid_latitude" type="number" step="any" v-model="detailsModel.latitude"
                                class="dashboard-input" placeholder="e.g., 25.2048"></Field>
                        </ColumnInputContainer>
                        <ColumnInputContainer label="Longitude" name="masjid_longitude" :show_error="true"
                            class="w-100 w-md-50">
                            <Field name="masjid_longitude" type="number" step="any" v-model="detailsModel.longitude"
                                class="dashboard-input" placeholder="e.g., 55.2708"></Field>
                        </ColumnInputContainer>
                    </div>
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Latitude and longitude are used for accurate prayer time calculations. You can find these coordinates using Google Maps.
                    </small>
                </div>
            </div>

            <!-- Social Media Inputs Group -->
            <div class="d-flex flex-column gap-3 w-100">
                <span class="d-block fs-5 fw-semibold">Social Media</span>
                <!-- First Inputs Group -->
                <div class="d-flex flex-column flex-md-row gap-3 justify-content-md-between w-100">
                    <ColumnInputContainer label="Facebook" name="facebook_link" :show_error="true"
                        class="w-100 w-md-50">
                        <Field name="facebook_link" type="text" v-model="detailsModel.facebook" class="dashboard-input"
                            placeholder="www.facebook.com/example"></Field>
                    </ColumnInputContainer>
                    <ColumnInputContainer label="Youtube" name="youtube_link" :show_error="true" class="w-100 w-md-50">
                        <Field name="youtube_link" type="text" v-model="detailsModel.youtube" class="dashboard-input"
                            placeholder="www.youtube.com/example"></Field>
                    </ColumnInputContainer>
                </div>
                <!-- Secon Inputs Group -->
                <div class="d-flex flex-column flex-md-row gap-3 justify-content-md-between w-100">
                    <ColumnInputContainer label="Instagram" name="instagram_link" :show_error="true"
                        class="w-100 w-md-50">
                        <Field name="instagram_link" type="text" v-model="detailsModel.instagram"
                            class="dashboard-input" placeholder="www.instagram.com/example"></Field>
                    </ColumnInputContainer>
                    <ColumnInputContainer label="WhatsApp" name="whatsapp_number" :show_error="true"
                        class="w-100 w-md-50">
                        <Field name="whatsapp_number" type="text" v-model="detailsModel.wahtsapp"
                            class="dashboard-input" placeholder="+971 *** *** ****">
                        </Field>
                    </ColumnInputContainer>
                </div>
            </div>

        </div>

        <div class="card-footer bg-white border-0 d-flex flex-row-reverse w-100">
            <LoadingButton type="submit" :is-loading="updateDetailsLoading">
                Save Changes
            </LoadingButton>
        </div>
    </Form>
</template>

<script setup lang="ts">
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import SearchableSelect from '@/components/form/SearchableSelect.vue';
import { useMasjidStore } from '@/stores/masjidStore';
import { computed, onBeforeMount, ref } from 'vue';
import { ErrorMessage, Field, Form, useForm } from 'vee-validate';
import { object, string, number } from 'yup';
import { VueTelInputCountry } from '@/core/types/elements/VueTelInput';
import { applyCountryDialCode } from '@/assets/ts/handleVueTelInput';
import { MasjidDetails, MasjidDetailsModel } from '@/core/types/data/custom/MasjidDetails';
import ApiService from '@/core/services/ApiService';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import { SweetAlertOptions } from 'sweetalert2';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import { AxiosError } from 'axios';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';

onBeforeMount(async () => {
    await fetchTimezones();
    await masjidStore.fetchMasjid()
        .finally(async () => {
            await fetchMasjidDetails();
        });
});

// stores
const masjidStore = useMasjidStore();

// Custom constants
const detailsModel = ref<MasjidDetailsModel & { logoSrc: string | undefined; }>({
    name: '',
    email: '',
    phone: '',
    facebook: '',
    youtube: '',
    instagram: '',
    wahtsapp: '',
    logoSrc: undefined,
    website_link: '',
    timezone: '',
    latitude: '',
    longitude: ''
});
const logoFile = ref<File | undefined>();
const phone = ref<string>('');
const timezones = ref<string[]>([]);

const validationSchema = object().shape({
    logo_image: string().required(),
    masjid_email: string().email().required(),
    masjid_phone: string()
        .matches(/^$|^\+?[0-9 ]+$/, "must have the curruent format: '+[digits and spaces only]'")
        .test(
            'min-length-8',
            'must be at least 8 digits',
            (value) => {
                if (value === null || value?.length === 0 || (value && value?.length >= 8)) {
                    return true;
                } else {
                    return false;
                }
            }).required(),
    masjid_timezone: string().required().label('Timezone'),
    masjid_latitude: number().required().min(-90).max(90).label('Latitude'),
    masjid_longitude: number().required().min(-180).max(180).label('Longitude'),
    facebook_link: string().url().optional(),
    youtube_link: string().url().optional(),
    instagram_link: string().url().optional(),
    whatsapp_number: string()
        .matches(/^$|^\+?[0-9 ]+$/, "must have the curruent format: '+[digits and spaces only]'")
        .test(
            'min-length-8',
            'must be at least 8 digits',
            (value) => {
                if (value === null || value?.length === 0 || (value && value?.length >= 8)) {
                    return true;
                } else {
                    return false;
                }
            }).required(),
});
const { setFieldValue } = useForm({ validationSchema: validationSchema });
const oldAvatarImage = computed(() => masjidStore.masjid?.logo?.original_url ?? undefined);
const updateDetailsLoading = ref<boolean>(false);

async function fetchTimezones() {
    try {
        const response = await ApiService.get('/api/admin/masjids/timezones');
        if (response.data.status === 'success' && Array.isArray(response.data.data)) {
            timezones.value = response.data.data;
        }
    } catch (error) {
        console.error('Error fetching timezones:', error);
    }
}

async function fetchMasjidDetails() {
    if (masjidStore.masjid) {
        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/details`)
            .then(res => {
                if (res.data?.status === 'success' && res.data?.data) {
                    let details = res.data.data as MasjidDetails;
                    detailsModel.value.name = details.name;
                    detailsModel.value.website_link = details.website_link;
                    detailsModel.value.email = details.email;
                    detailsModel.value.phone = details.phone;
                    phone.value = details.phone;
                    detailsModel.value.timezone = details.timezone || '';
                    detailsModel.value.latitude = details.latitude?.toString() || '';
                    detailsModel.value.longitude = details.longitude?.toString() || '';
                    if (details.social_media_links.length) {
                        detailsModel.value.facebook = details.social_media_links.find(elm => elm.type === 'Facebook')?.value ?? ""
                        detailsModel.value.youtube = details.social_media_links.find(elm => elm.type === 'YouTube')?.value ?? ""
                        detailsModel.value.instagram = details.social_media_links.find(elm => elm.type === 'Instagram')?.value ?? ""
                        detailsModel.value.wahtsapp = details.social_media_links.find(elm => elm.type === 'WhatsApp_Number')?.value ?? ""
                    }
                }
            })
            .catch(e => {
                console.log(e);
            });
    }
}

async function updateMasjidDetails() {

    QSwal.fire("Sure ?", "Update the mosque details?", "question")
        .then(async result => {
            if (result.isConfirmed && masjidStore.masjid) {
                updateDetailsLoading.value = true;
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                const formData = new FormData();
                if (logoFile.value) formData.append('logo', logoFile.value);
                formData.append('name', detailsModel.value.name);
                formData.append('website_link', detailsModel.value.website_link);
                formData.append('email', detailsModel.value.email);
                formData.append('phone', phone.value);
                formData.append('timezone', detailsModel.value.timezone);
                formData.append('latitude', detailsModel.value.latitude);
                formData.append('longitude', detailsModel.value.longitude);
                formData.append('facebook_url', detailsModel.value.facebook);
                formData.append('youtube_url', detailsModel.value.youtube);
                formData.append('instagram_url', detailsModel.value.instagram);
                formData.append('whatsapp_number', detailsModel.value.wahtsapp);

                await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/details`, formData)
                    .then(res => {
                        if (res.data?.status === 'success' && res.data?.data) {
                            let details = res.data.data as MasjidDetails;
                            detailsModel.value.email = details.email;
                            detailsModel.value.phone = details.phone;
                            phone.value = details.phone;
                            if (details.social_media_links.length) {
                                detailsModel.value.facebook = details.social_media_links.find(elm => elm.type === 'Facebook')?.value ?? ""
                                detailsModel.value.youtube = details.social_media_links.find(elm => elm.type === 'YouTube')?.value ?? ""
                                detailsModel.value.instagram = details.social_media_links.find(elm => elm.type === 'Instagram')?.value ?? ""
                                detailsModel.value.wahtsapp = details.social_media_links.find(elm => elm.type === 'WhatsApp_Number')?.value ?? ""
                            }
                            swalInstance.icon = "success";
                            swalInstance.title = "Success";
                            swalInstance.text = "Details updated successfully.";
                        } else {
                            swalInstance.icon = "warning";
                            swalInstance.title = getMessageFromObj(res);
                            swalInstance.text = "Unexpected response!";
                        }
                    })
                    .catch((e: AxiosError<BackendResponseData>) => {
                        console.log(e);
                        swalInstance.icon = "error";
                        swalInstance.title = e.message;
                        swalInstance.text = getMessageFromObj(e);
                    })
                    .finally(async () => {
                        await masjidStore.fetchMasjid();
                        MSwal.fire(swalInstance);
                        updateDetailsLoading.value = false;
                    });
            }
        });

}

const onLogoChange = (data: UploadedImageInfo) => {
    setFieldValue('logo_image', data.src);
    detailsModel.value.logoSrc = data.src;
    logoFile.value = data.file;
};

</script>
