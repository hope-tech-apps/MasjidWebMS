<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <!-- Title -->
        <div class="card-header bg-white border-0">
            <div class="card-title  fs-4 fw-semibold">
                <span v-if="isEditForm">Edit Service</span>
                <span v-else>Add New Service</span>
            </div>
        </div>

        <!-- Form Fields -->
        <div class="card-body d-flex flex-column gap-5 w-100">

            <div class="d-flex flex-column gap-5 flex-md-row justify-content-md-between">
                <div class="w-100 w-md-75 w-lg-50">
                    <ImageDraggableInput label="Service Icon" @imageChange="onIconInputChange"
                        :current-image-src="oldIcon" type="icon" />
                    <Field type="file" v-model="imageSrc" name="service_icon" class="d-none"></Field>
                    <div class="error-message">
                        <ErrorMessage v-if="errorMessage" name="service_icon">
                            {{ errorMessage }}
                        </ErrorMessage>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-column">
                <ImageDraggableInput label="Service Image" @imageChange="onImageInputChange"
                    :current-image-src="oldImage" type="photo" />
                <Field type="file" v-model="imageSrc" name="service_image" class="d-none"></Field>
                <div class="error-message">
                    <ErrorMessage v-if="errorMessage" name="service_image">
                        {{ errorMessage }}
                    </ErrorMessage>
                </div>
            </div>

            <ColumnInputContainer label="Service Title" name="service_title" :show_error="true" class="w-100 w-md-50">
                <Field name="service_title" type="text" v-model="serviceTitle" class="dashboard-input"
                    placeholder="title goes here"></Field>
            </ColumnInputContainer>

            <ColumnInputContainer label="Service Description" name="service_description" :show_error="true"
                class="w-100 w-md-50">
                <Field name="service_description" as="textarea" v-model="serviceDesc" class="dashboard-input"
                    placeholder="description goes here"></Field>
            </ColumnInputContainer>

        </div>

        <!-- Form Footer -->
        <div class="card-footer d-flex align-items-center justify-content-end w-100 bg-white border-0">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span v-if="isEditForm">Update Service</span>
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
import { Service } from '@/core/types/data/masjid-related/Service';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { useServicesStore } from '@/stores/masjid/servicesStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field, ErrorMessage, useForm, useField } from 'vee-validate';
import { computed, onBeforeMount, ref, toRefs, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {

    if (route.params?.service_id) {
        serviceId.value = route.params.service_id as string;
        isEditForm.value = true;
        servicesStore.fetchService(route.params.service_id as string, service)
    } else {
        serviceId.value = '';
        isEditForm.value = false;
    }

});

// Html refs

// Routing
const route = useRoute();
const router = useRouter();

// Stores
const masjidStore = useMasjidStore();
const servicesStore = useServicesStore();

// Custom types
type ServiceEntry = {
    title: string;
    description: string;
    imageSrc: string | undefined;
    iconSrc: string | undefined;
};

// Custom constants
const isEditForm = ref(false);
const serviceId = ref<string>('');
const service = ref<Service>();
const entryModel = ref<ServiceEntry>({ title: "", description: "", imageSrc: "", iconSrc: "" });
const isLoading = ref(false);

// Form
const formValidationSchema = object().shape({
    service_title: string().required(),
    service_description: string().required(),
    service_image: string().required(),
    service_icon: string().required()
});
const { handleSubmit, setFieldValue, validate } = useForm({ validationSchema: formValidationSchema });
const { value: service_image, errorMessage } = useField('service_image');
const { title: serviceTitle, description: serviceDesc, imageSrc, iconSrc } = toRefs<ServiceEntry>(entryModel.value);
const imageFile = ref<File | undefined>(undefined);
const iconFile = ref<File | undefined>(undefined);

// Computed
const oldImage = computed(() => {
    return service.value?.image?.original_url ?? '';
});
const oldIcon = computed(() => {
    return service.value?.icon?.original_url ?? '';
});

watch(() => service.value, () => {
    if (service.value) {
        serviceTitle.value = service.value.title;
        serviceDesc.value = service.value.description;
        imageSrc.value = service.value.image?.original_url;
        iconSrc.value = service.value.icon?.original_url;
    }
});

// Functions
const onImageInputChange = (data: UploadedImageInfo) => {
    setFieldValue('service_image', data.src);
    imageSrc.value = data.src;
    imageFile.value = data.file;
};

const onIconInputChange = (data: UploadedImageInfo) => {
    setFieldValue('service_image', data.src);
    iconSrc.value = data.src;
    iconFile.value = data.file;
};

const onSubmit = async () => {

    isLoading.value = true;
    let swalMessage = isEditForm.value ? "Update the service data?" : "Create a new service?";
    QSwal.fire("Questions", swalMessage, 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                const apiRequestData = new FormData();
                apiRequestData.append('title', serviceTitle.value);
                apiRequestData.append('description', serviceDesc.value);
                if (imageFile.value) {
                    apiRequestData.append('image', imageFile.value);
                }
                if (iconFile.value) {
                    apiRequestData.append('icon', iconFile.value);
                }

                // define
                let apiEndpoint: BackendApiRoute | '' = '';
                if (masjidStore.masjid?.id) {
                    if (isEditForm.value) {
                        apiEndpoint = `/api/admin/masjids/${masjidStore.masjid.id}/services/${serviceId.value}/`;
                    } else {
                        apiEndpoint = `/api/admin/masjids/${masjidStore.masjid.id}/services`;
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
                        await servicesStore.fetchMasjidServicesPaginated(1).finally(() => {
                            MSwal.fire(swalInstance).finally(() => {
                                if (isEditForm.value) {
                                    router.push(`/masjid/services/${serviceId.value}`).finally(() => {
                                        isLoading.value = false;
                                    });
                                } else {
                                    router.push('/masjid/services').finally(() => {
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