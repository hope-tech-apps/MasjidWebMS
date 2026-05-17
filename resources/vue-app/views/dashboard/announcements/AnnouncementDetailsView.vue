<template>
    <DataItemContainer v-if="announcement" :title="announcement.title"
        @edit-button-click="router.push(`/masjid/announcements/${route.params.announcement_id}/edit`)"
        @delete-button-click="deleteAnnouncement" @archive-button-click="archiveAnnouncement">
        <div class="d-flex flex-column align-items-start justify-content-start gap-3 w-100 overflow-auto">
            <div class="announcement-image-container">
                <img v-if="announcement.image" :src="announcement.image.original_url" alt="" class="announcement-image">
            </div>
            <p class="fs-6">
                <SafeHtml :html="announcement.details" tag="span" />
            </p>
            <p class="fs-6">
                <SafeHtml :html="announcement.text" tag="span" />
            </p>
        </div>
    </DataItemContainer>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import DataItemContainer from '@/components/DataItemContainer.vue';
import SafeHtml from '@/components/common/SafeHtml.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { Announcement } from '@/core/types/data/masjid-related/Announcement';
import { useAnnouncementsStore } from '@/stores/masjid/announcementsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { onBeforeMount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

onBeforeMount(async () => {

    if (route.params?.announcement_id) {
        announcementsStore.fetchAnnouncement(route.params.announcement_id as string, announcement)
    }

});

// Routing
const router = useRouter();
const route = useRoute();

// Stores
const masjidStore = useMasjidStore();
const announcementsStore = useAnnouncementsStore();

// Custom constants
const announcement = ref<Announcement>();

// Functions
const deleteAnnouncement = async () => {

    QSwal.fire("Warning", 'You are going to delete this announcement !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjidStore.masjid?.id && announcement.value?.id) {
                    await ApiService.delete(`/api/admin/masjids/${masjidStore.masjid.id}/announcements/${announcement.value.id}/`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Announcement deleted successfully.";
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
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/masjid/announcements`)
                                });
                            });
                        });
                }
            }
        })
}

const archiveAnnouncement = async () => {

    QSwal.fire("Warning", 'This announcement will be archived and not returned with the announcements list !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjidStore.masjid?.id && announcement.value?.id) {
                    await ApiService.delete(`/api/admin/masjids/${masjidStore.masjid.id}/announcements/${announcement.value.id}/trash`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Announcement archived successfully.";
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
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/masjid/announcements`)
                                });
                            });
                        });
                }
            }
        })
}

</script>

<style scoped>
.announcement-image-container {
    width: 100%;
    max-width: 500px;
    height: 400px;
    border-radius: .5rem;
    border: 1px solid var(--input-border);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background-color: #f8f9fa;
}

.announcement-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: .5rem;
}
</style>
