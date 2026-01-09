<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <!-- Title -->
        <div class="card-header bg-white border-0">
            <div class="card-title  fs-4 fw-semibold">
                <span>About Us</span>
            </div>
        </div>

        <!-- Form Fields -->
        <div class="card-body d-flex flex-column gap-5 w-100">

            <div class="d-flex flex-column">
                <ImageDraggableInput label="About Us Image"
                    @imageChange="(data: UploadedImageInfo) => onImageInputChange(data, 'about')"
                    :current-image-src="oldAboutImage" type="photo" />
                <Field type="file" v-model="aboutImageSrc" name="about_image" class="d-none"></Field>
                <div class="error-message">
                    <ErrorMessage v-if="errorMessage" name="about_image">
                        {{ errorMessage }}
                    </ErrorMessage>
                </div>
            </div>

            <ColumnInputContainer label="About Us Description" name="about_description" :show_error="true"
                class="w-100 w-md-50">
                <Field name="about_description" as="textarea" v-model="about" class="dashboard-input"
                    placeholder="description goes here" maxlength="5000"></Field>
                <div class="text-end text-muted small mt-1">
                    {{ about?.length || 0 }} / 5000 characters
                </div>
            </ColumnInputContainer>

            <div class="light-top-border"></div>

            <div class="d-flex flex-column flex-md-row gap-5 justify-content-md-between">
                <div class="d-flex flex-column gap-4 w-100 w-md-50">
                    <div class="w-100 w-md-75 w-lg-50">
                        <ImageDraggableInput label="Our Mission Icon"
                            @imageChange="(data: UploadedImageInfo) => onImageInputChange(data, 'mission')"
                            :current-image-src="oldMissionIcon" type="icon" />
                        <Field type="file" v-model="missionIconSrc" name="mission_icon" class="d-none"></Field>
                        <div class="error-message">
                            <ErrorMessage v-if="errorMessage" name="mission_icon">
                                {{ errorMessage }}
                            </ErrorMessage>
                        </div>
                    </div>
                    <ColumnInputContainer label="Our Mission Description" name="mission_description" :show_error="true"
                        class="w-100 w-md-50">
                        <Field name="mission_description" as="textarea" v-model="mission" class="dashboard-input"
                            placeholder="description goes here" maxlength="5000"></Field>
                        <div class="text-end text-muted small mt-1">
                            {{ mission?.length || 0 }} / 5000 characters
                        </div>
                    </ColumnInputContainer>
                </div>

                <div class="separator d-none d-md-block"></div>
                <div class="light-top-border d-block d-md-none"></div>

                <div class="d-flex flex-column gap-4 w-100 w-md-50">
                    <div class="w-100 w-md-75 w-lg-50">
                        <ImageDraggableInput label="Our Vision Icon"
                            @imageChange="(data: UploadedImageInfo) => onImageInputChange(data, 'vision')"
                            :current-image-src="oldVisionIcon" type="icon" />
                        <Field type="file" v-model="visionIconSrc" name="vision_icon" class="d-none"></Field>
                        <div class="error-message">
                            <ErrorMessage v-if="errorMessage" name="vision_icon">
                                {{ errorMessage }}
                            </ErrorMessage>
                        </div>
                    </div>
                    <ColumnInputContainer label="Our Vision Description" name="vision_description" :show_error="true"
                        class="w-100 w-md-50">
                        <Field name="vision_description" as="textarea" v-model="vision" class="dashboard-input"
                            placeholder="description goes here" maxlength="5000"></Field>
                        <div class="text-end text-muted small mt-1">
                            {{ vision?.length || 0 }} / 5000 characters
                        </div>
                    </ColumnInputContainer>
                </div>
            </div>

        </div>

        <!-- Form Footer -->
        <div class="card-footer d-flex align-items-center justify-content-end w-100 bg-white border-0">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span>Save Changes</span>
            </LoadingButton>
            <!-- <button type="submit" class="btn btn-primary">submit</button> -->
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
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { MasjidAboutUs } from '@/core/types/data/masjid-related/MasjidAboutUs';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { useServicesStore } from '@/stores/masjid/servicesStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field, ErrorMessage, useForm, useField } from 'vee-validate';
import { computed, onBeforeMount, ref, toRefs, watch } from 'vue';
import { useRoute } from 'vue-router';
import { object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {
    await fetchAboutUsInfo();
});

// Html refs

// Routing
const route = useRoute();

// Stores
const masjidStore = useMasjidStore();
const servicesStore = useServicesStore();

// Custom types
type AboutUsEntry = {
    about: string;
    mission: string;
    vision: string;
    aboutImageSrc: string | undefined;
    missionIconSrc: string | undefined;
    visionIconSrc: string | undefined;
};

// Custom constants
const aboutUs = ref<MasjidAboutUs>();
const entryModel = ref<AboutUsEntry>({
    about: '',
    mission: '',
    vision: '',
    aboutImageSrc: '',
    missionIconSrc: '',
    visionIconSrc: ''
});
const isLoading = ref(false);

// Form
const formValidationSchema = computed(() => {
    // If aboutUs exists (editing), images are optional
    // If aboutUs doesn't exist (creating), images are required
    const isEditing = !!aboutUs.value;

    return object().shape({
        about_description: string().required('About description is required').max(5000, 'About description must not exceed 5000 characters'),
        mission_description: string().required('Mission description is required').max(5000, 'Mission description must not exceed 5000 characters'),
        vision_description: string().required('Vision description is required').max(5000, 'Vision description must not exceed 5000 characters'),
        about_image: isEditing ? string() : string().required('About image is required'),
        mission_icon: isEditing ? string() : string().required('Mission icon is required'),
        vision_icon: isEditing ? string() : string().required('Vision icon is required')
    });
});

const { setFieldValue } = useForm({ validationSchema: formValidationSchema });
const { errorMessage } = useField('service_image');
const { about, mission, vision, aboutImageSrc, missionIconSrc, visionIconSrc } = toRefs<AboutUsEntry>(entryModel.value);
const aboutImageFile = ref<File | undefined>(undefined);
const missionIconFile = ref<File | undefined>(undefined);
const visionIconFile = ref<File | undefined>(undefined);

// Computed
const oldAboutImage = computed(() => aboutUs.value?.about_image?.original_url ?? undefined);
const oldMissionIcon = computed(() => aboutUs.value?.mission_icon?.original_url ?? undefined);
const oldVisionIcon = computed(() => aboutUs.value?.vision_icon?.original_url ?? undefined);

// Watch
watch(() => aboutUs.value, () => {
    if (aboutUs.value) {
        entryModel.value.about = aboutUs.value.about;
        entryModel.value.mission = aboutUs.value.mission;
        entryModel.value.vision = aboutUs.value.vision;
        entryModel.value.aboutImageSrc = aboutUs.value?.about_image?.original_url ?? undefined;
        entryModel.value.missionIconSrc = aboutUs.value?.mission_icon?.original_url ?? undefined;
        entryModel.value.visionIconSrc = aboutUs.value?.vision_icon?.original_url ?? undefined;

        // Set field values for validation when editing
        if (aboutUs.value?.about_image?.original_url) {
            setFieldValue('about_image', aboutUs.value.about_image.original_url);
        }
        if (aboutUs.value?.mission_icon?.original_url) {
            setFieldValue('mission_icon', aboutUs.value.mission_icon.original_url);
        }
        if (aboutUs.value?.vision_icon?.original_url) {
            setFieldValue('vision_icon', aboutUs.value.vision_icon.original_url);
        }
    }
});

// Functions
const fetchAboutUsInfo = async () => {
    if (masjidStore.masjid?.id) {
        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/about`)
            .then(res => {
                if (res.data.status === 'success' && res.data?.data as MasjidAboutUs) {
                    aboutUs.value = res.data.data;
                }
            })
            .catch((e: AxiosError) => {
                console.log(e);
            });
    }
};

const onImageInputChange = (data: UploadedImageInfo, key: 'about' | 'mission' | 'vision') => {
    if (key === 'about') {
        setFieldValue('about_image', data.src);
        aboutImageSrc.value = data.src;
        aboutImageFile.value = data.file;
    } else if (key === 'mission') {
        setFieldValue('mission_icon', data.src);
        missionIconSrc.value = data.src;
        missionIconFile.value = data.file;
    } else if (key === 'vision') {
        setFieldValue('vision_icon', data.src);
        visionIconSrc.value = data.src;
        visionIconFile.value = data.file;
        console.log(visionIconFile.value);
    }
};

const onSubmit = async () => {

    isLoading.value = true;
    let swalInstance: SweetAlertOptions = {
        title: "Info",
        text: "Nothing",
        icon: "info"
    };

    QSwal.fire("Questions", "Update About Us data?", 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                const apiRequestData = new FormData();
                apiRequestData.append('about', about.value);
                apiRequestData.append('mission', mission.value);
                apiRequestData.append('vision', vision.value);
                if (aboutImageFile.value) {
                    apiRequestData.append('about_image', aboutImageFile.value);
                }
                if (missionIconFile.value) {
                    apiRequestData.append('mission_icon', missionIconFile.value);
                }
                if (visionIconFile.value) {
                    apiRequestData.append('vision_icon', visionIconFile.value);
                }

                if (masjidStore.masjid?.id) {
                    await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/about`, apiRequestData)
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
                            await servicesStore.fetchMasjidServicesPaginated(1);
                        });
                } else {
                    swalInstance.title = "Error";
                    swalInstance.text = "The masjid ID is not specified by the system!";
                    swalInstance.icon = "error";
                }
            }
        })
        .finally(() => {
            isLoading.value = false;
            MSwal.fire(swalInstance);
        });
}

</script>

<style scoped>
.separator {
    border-left: 1px solid var(--input-border);
    height: auto;
    margin: 0;
}

.light-top-border {
    border-top: 1px solid var(--input-border);
}
</style>
