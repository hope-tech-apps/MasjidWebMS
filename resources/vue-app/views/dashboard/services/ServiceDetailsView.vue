<template>
    <DataItemContainer v-if="service" :title="service.title"
        @edit-button-click="router.push(`/masjid/services/${route.params.service_id}/edit`)"
        @delete-button-click="deleteService" @archive-button-click="archiveService">
        <template #headerIcon>
            <div class="service-title-icon-container">
                <img v-if="service.icon" :src="service.icon.original_url" alt="" class="service-icon">
            </div>
            
        </template>
        <div class="d-flex flex-column align-items-start justify-content-start gap-3 w-100 overflow-auto">
            <div class="service-image-container">
                <img v-if="service.image" :src="service.image.original_url" alt="" class="service-image">
            </div>
            <p class="fs-6">
                <span v-html="service.description" class=""></span>
            </p>
        </div>
    </DataItemContainer>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import DataItemContainer from '@/components/DataItemContainer.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { Service } from '@/core/types/data/masjid-related/Service';
import { useServicesStore } from '@/stores/masjid/servicesStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { onBeforeMount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

onBeforeMount(async () => {

    if (route.params?.service_id) {
        servicesStore.fetchService(route.params.service_id as string, service)
    }

});

// Routing
const router = useRouter();
const route = useRoute();

// Stores
const masjidStore = useMasjidStore();
const servicesStore = useServicesStore();

// Custom constants
const service = ref<Service>();

// Functions
const deleteService = async () => {

    QSwal.fire("Warning", 'You are going to delete this service !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjidStore.masjid?.id && service.value?.id) {
                    await ApiService.delete(`/api/admin/masjids/${masjidStore.masjid.id}/services/${service.value.id}/`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Service deleted successfully.";
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
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/masjid/services`)
                                });
                            });
                        });
                }
            }
        })
}

const archiveService = async () => {

    QSwal.fire("Warning", 'This service will be archived and not returned with the services list !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjidStore.masjid?.id && service.value?.id) {
                    await ApiService.delete(`/api/admin/masjids/${masjidStore.masjid.id}/services/${service.value.id}/trash`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Service archived successfully.";
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
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/masjid/services`)
                                });
                            });
                        });
                }
            }
        })
}

</script>

<style scoped>
.service-image-container {
    height: auto;
    border-radius: .5rem;
    max-width: 500px;
    max-height: 500px;
    border: 1px solid var(--input-border);
}

.service-image {
    width: 100%;
    height: auto;
    object-fit: cover;
    border-radius: .5rem;
}

.service-title-icon-container {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 10px;
}

.service-title-icon-container .service-icon {
    width: 100%;
    height: 100%;
}

</style>