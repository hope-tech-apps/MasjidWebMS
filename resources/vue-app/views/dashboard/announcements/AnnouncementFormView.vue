<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <!-- Title -->
        <div class="card-header bg-white border-0">
            <div class="card-title  fs-4 fw-semibold">
                <span v-if="isEditForm">Edit Announcement</span>
                <span v-else>Add New Announcement</span>
            </div>
        </div>

        <!-- Form Fields -->
        <div class="card-body d-flex flex-column gap-5 w-100">

            <div class="d-flex flex-column gap-5 flex-md-row justify-content-md-between">
                <ColumnInputContainer label="Start Date" name="announcement_start_date" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="announcement_start_date" type="date" v-model="startDate" class="dashboard-input"
                        placeholder="title goes here"></Field>
                </ColumnInputContainer>
                <ColumnInputContainer label="End Date" name="announcement_end_date" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="announcement_end_date" type="date" v-model="endDate" class="dashboard-input"
                        placeholder="title goes here"></Field>
                </ColumnInputContainer>
            </div>

            <div class="d-flex flex-column">
                <ImageDraggableInput label="Announcement Image" @imageChange="onImageInputChange"
                    :current-image-src="oldImage" type="photo" />
                <Field type="file" v-model="imageSrc" name="announcement_image" class="d-none"></Field>
                <div class="error-message">
                    <ErrorMessage name="announcement_image" />
                </div>
            </div>

            <ColumnInputContainer label="Announcement Title" name="announcement_title" :show_error="true"
                class="w-100 w-md-50">
                <Field name="announcement_title" type="text" v-model="announcementTitle" class="dashboard-input"
                    placeholder="title goes here"></Field>
            </ColumnInputContainer>

            <ColumnInputContainer label="Announcement Details" name="announcement_description" :show_error="true"
                class="w-100 w-md-50">
                <Field name="announcement_description" as="textarea" v-model="announcementDesc" class="dashboard-input"
                    placeholder="description goes here"></Field>
            </ColumnInputContainer>

            <ColumnInputContainer label="Announcement Text" name="announcement_text" :show_error="true"
                class="w-100 w-md-50">
                <Field name="announcement_text" as="textarea" v-model="announcementText" class="dashboard-input"
                    placeholder="text goes here"></Field>
            </ColumnInputContainer>

        </div>

        <!-- Form Footer -->
        <div class="card-footer d-flex align-items-center justify-content-end w-100 bg-white border-0">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span v-if="isEditForm">Update Announcement</span>
                <span v-else>Add New</span>
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
import { Announcement } from '@/core/types/data/masjid-related/Announcement';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { useAnnouncementsStore } from '@/stores/masjid/announcementsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field, ErrorMessage, useForm } from 'vee-validate';
import { computed, onBeforeMount, ref, toRefs, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {

    if (route.params?.announcement_id) {
        announcementId.value = route.params.announcement_id as string;
        isEditForm.value = true;
        announcementsStore.fetchAnnouncement(route.params.announcement_id as string, announcement)
    } else {
        announcementId.value = '';
        isEditForm.value = false;
    }

});

// Html refs

// Routing
const route = useRoute();
const router = useRouter();

// Stores
const masjidStore = useMasjidStore();
const announcementsStore = useAnnouncementsStore();

// Custom types
type AnnouncementEntry = {
    title: string;
    description: string;
    text: string;
    imageSrc: string | undefined;
    startDate: string;
    endDate: string;
};

// Custom constants
const isEditForm = ref(false);
const announcementId = ref<string>('');
const announcement = ref<Announcement>();
const entryModel = ref<AnnouncementEntry>({ title: "", description: "", text: "", imageSrc: "", startDate: "", endDate: "" });
const isLoading = ref(false);

// Form
const formValidationSchema = object().shape({
    announcement_title: string().required(),
    announcement_description: string().required(),
    announcement_text: string().required(),
    announcement_image: string().required(),
    announcement_start_date: string().required(),
    announcement_end_date: string().test('is-grater', 'end date must be after start date', (value, context) => {
        if (value) {
            let startDate = new Date(context.parent.announcement_start_date);
            let endDate = new Date(value);
            return startDate < endDate;
        }
    }).required()
});
const { setFieldValue } = useForm({ validationSchema: formValidationSchema });
const { title: announcementTitle, description: announcementDesc, text: announcementText, imageSrc, startDate, endDate } = toRefs<AnnouncementEntry>(entryModel.value);
const imageFile = ref<File | undefined>(undefined);

// Computed
const oldImage = computed(() => {
    return announcement.value?.image?.original_url ?? '';
});

// Watch
watch(() => announcement.value, () => {
    if (announcement.value) {
        announcementTitle.value = announcement.value.title;
        announcementDesc.value = announcement.value.details;
        announcementText.value = announcement.value.text;
        startDate.value = announcement.value.start_date;
        endDate.value = announcement.value.end_date;
        imageSrc.value = announcement.value.image?.original_url;
    }
});

// Functions
const onImageInputChange = (data: UploadedImageInfo) => {
    setFieldValue('announcement_image', data.src);
    imageSrc.value = data.src;
    imageFile.value = data.file;
    console.log(imageFile.value)
};

const onSubmit = async () => {

    isLoading.value = true;
    let swalMessage = isEditForm.value ? "Update the announcement data?" : "Create a new announcement?";
    QSwal.fire("Questions", swalMessage, 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                const apiRequestData = new FormData();
                apiRequestData.append('title', announcementTitle.value);
                apiRequestData.append('details', announcementDesc.value);
                apiRequestData.append('text', announcementText.value);
                apiRequestData.append('start_date', startDate.value);
                apiRequestData.append('end_date', endDate.value);
                if (imageFile.value) {
                    apiRequestData.append('image', imageFile.value);
                }

                // define
                let apiEndpoint: BackendApiRoute | '' = '';
                if (masjidStore.masjid?.id) {
                    if (isEditForm.value) {
                        apiEndpoint = `/api/admin/masjids/${masjidStore.masjid.id}/announcements/${announcementId.value}/`;
                    } else {
                        apiEndpoint = `/api/admin/masjids/${masjidStore.masjid.id}/announcements`;
                    }
                }

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

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
                        await announcementsStore.fetchMasjidAnnouncementsPaginated(1).finally(() => {
                            MSwal.fire(swalInstance).finally(() => {
                                if (isEditForm.value) {
                                    router.push(`/masjid/announcements/${announcementId.value}`).finally(() => {
                                        isLoading.value = false;
                                    });
                                } else {
                                    router.push('/masjid/announcements').finally(() => {
                                        isLoading.value = false;
                                    });
                                }
                            });
                        });
                    });
            } else {
                isLoading.value = false;
            }
        })
}

</script>

<style scoped>
.image-input-group {
    display: flex;
    flex-direction: column;
    align-items: start;
    justify-content: start;
    gap: .5rem;
}

.image-input-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1.5rem;
    width: 100%;
    border: 1px dashed var(--cgreen);
    padding-top: 3rem;
    padding-bottom: 3rem;
    border-radius: .5rem;
}

.image-input-container.on-drag {
    opacity: .5;
}

.image-input-container.on-drag .allow-drag {
    visibility: hidden !important;
}

.image-input-container .prevent-drag {
    display: none !important;
}

.image-input-container.show-prevent .allow-drag {
    display: none !important;
}

.image-input-container.show-prevent .prevent-drag {
    display: flex !important;
}

.image-input-note {
    font-size: .85rem;
}

.image-preview {
    width: 100%;
    border-radius: 1rem;
    overflow: hidden;
    position: relative;
}

.image-preview .remove-image {
    position: absolute;
    right: 2rem;
    top: 2rem;
}
</style>
