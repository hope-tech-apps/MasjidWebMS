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
                    <div class="d-flex flex-column w-100">
                        <!-- Image -->
                        <ImageDraggableInput label="Masjid Logo"
                            @imageChange="(data: UploadedImageInfo) => onImageInputChange(data)"
                            :current-image-src="oldAvatarImage" type="photo" />
                        <Field type="file" v-model="detailsModel.logoSrc" name="logo_image" class="d-none"></Field>
                        <div class="error-message">
                            <ErrorMessage name="logo_image" class="error-message" />
                        </div>
                    </div>
                    <div class="d-flex flex-column w-100">
                        <!-- Footer Logo -->
                        <ImageDraggableInput label="Masjid Footer Logo"
                            @imageChange="(data: UploadedImageInfo) => onFooterLogoInputChange(data)"
                            :current-image-src="oldFooterLogoImage" type="photo" />
                        <Field type="file" v-model="detailsModel.footerLogoSrc" name="footer_logo_image" class="d-none"></Field>
                        <div class="error-message">
                            <ErrorMessage name="footer_logo_image" class="error-message" />
                        </div>
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
                            <vue-tel-input v-bind="field" v-model="phone" @country-changed="(country: VueTelInputCountry) => {
                                if (!phone)
                                    phone = `+${country.dialCode} `
                            }" class="dashboard-input">
                            </vue-tel-input>
                        </Field>
                    </ColumnInputContainer>
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
import { useMasjidStore } from '@/stores/masjidStore';
import { computed, onBeforeMount, ref } from 'vue';
import { ErrorMessage, Field, Form, useForm } from 'vee-validate';
import { object, string } from 'yup';
import { VueTelInputCountry } from '@/core/types/elements/VueTelInput';
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
    await masjidStore.fetchMasjid()
        .finally(async () => {
            await fetchMasjidDetails();
        });
});

// stores
const masjidStore = useMasjidStore();

// Custom constants
const detailsModel = ref<MasjidDetailsModel & { logoSrc: string | undefined; footerLogoSrc: string | undefined; }>({
    name: '',
    email: '',
    phone: '',
    facebook: '',
    youtube: '',
    instagram: '',
    wahtsapp: '',
    logoSrc: undefined,
    footerLogoSrc: undefined,
    website_link: ''
});
const logoFile = ref<File | undefined>();
const footerLogoFile = ref<File | undefined>();
const phone = ref<string>('');

const validationSchema = object().shape({
    logo_image: string().required(),
    footer_logo_image: string().required(),
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
const oldFooterLogoImage = computed(() => masjidStore.masjid?.footer_logo?.original_url ?? undefined);
const updateDetailsLoading = ref<boolean>(false);

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
                if (footerLogoFile.value) formData.append('footer_logo', footerLogoFile.value);
                formData.append('name', detailsModel.value.name);
                formData.append('website_link', detailsModel.value.website_link);
                formData.append('email', detailsModel.value.email);
                formData.append('phone', phone.value);
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

const onImageInputChange = (data: UploadedImageInfo) => {
    setFieldValue('logo_image', data.src);
    detailsModel.value.logoSrc = data.src;
    logoFile.value = data.file;
};

const onFooterLogoInputChange = (data: UploadedImageInfo) => {
    setFieldValue('footer_logo_image', data.src);
    detailsModel.value.footerLogoSrc = data.src;
    footerLogoFile.value = data.file;
};

</script>
