<template>
    <PageDataContainer title="Photo Gallery" :paginationOptions="paginationOptions" @headerButtonClick="showPhotoModal"
        @pageChange="pageChange">
        <div class="d-flex flex-column gap-3 flex-md-row w-100 overflow-hidden">
            <div class="d-flex flex-column gap-3 w-100 w-md-66">
                <div class="d-flex flex-column gap-3 w-100 flex-md-row">
                    <div class="w-100 w-md-50 img-container img-a-container" :class="{ 'active-container': photos[0] }">
                        <img v-if="photos[0]" @click.prevent="showPhotoModal(photos[0])" :src="photos[0].original_url"
                            alt="photo-1" class="photo-gallery-img" />
                    </div>
                    <div class="w-100 w-md-50 img-container" :class="{ 'active-container': photos[1] }">
                        <img v-if="photos[1]" @click.prevent="showPhotoModal(photos[1])" :src="photos[1].original_url"
                            alt="photo-2" class="photo-gallery-img img-a-container" />
                    </div>
                </div>
                <div class="w-100 img-container img-b-container" :class="{ 'active-container': photos[2] }">
                    <img v-if="photos[2]" @click.prevent="showPhotoModal(photos[2])" :src="photos[2].original_url"
                        alt="photo-3" class="photo-gallery-img" />
                </div>
                <div class="d-flex flex-column gap-3 w-100 flex-md-row">
                    <div class="w-100 w-md-50 img-container" :class="{ 'active-container': photos[3] }">
                        <img v-if="photos[3]" @click.prevent="showPhotoModal(photos[3])" :src="photos[3].original_url"
                            alt="photo-4" class="photo-gallery-img img-a-container" />
                    </div>
                    <div class="w-100 w-md-50 img-container" :class="{ 'active-container': photos[4] }">
                        <img v-if="photos[4]" @click.prevent="showPhotoModal(photos[4])" :src="photos[4].original_url"
                            alt="photo-5" class="photo-gallery-img img-a-container" />
                    </div>
                </div>
                <div class="w-100 img-container img-b-container" :class="{ 'active-container': photos[5] }">
                    <img v-if="photos[5]" @click.prevent="showPhotoModal(photos[5])" :src="photos[5].original_url"
                        alt="photo-6" class="photo-gallery-img img-b-container" />
                </div>
            </div>
            <div class="d-flex flex-column gap-3 w-100 w-md-33">
                <div class="w-100 img-container img-c-container" :class="{ 'active-container': photos[6] }">
                    <img v-if="photos[6]" @click.prevent="showPhotoModal(photos[6])" :src="photos[6].original_url"
                        alt="photo-7" class="photo-gallery-img" />
                </div>
                <div class="w-100 img-container img-d-container" :class="{ 'active-container': photos[7] }">
                    <img v-if="photos[7]" @click.prevent="showPhotoModal(photos[7])" :src="photos[7].original_url"
                        alt="photo-8" class="photo-gallery-img" />
                </div>
            </div>
        </div>
    </PageDataContainer>
    <PhotoGalleryModal :modalId="'photo_gallery_modal_id'" :showPhoto="isShowModal" :photo="photoToShow"
        :action-loading-status="modalActionLoading" @submit="onSubmit" @image-change="imageModalChange">
        <!-- <template #control_buttons>
            <div class="">

            </div>
        </template> -->
    </PhotoGalleryModal>
</template>

<script setup lang="ts">
import PhotoGalleryModal from '@/components/modals/PhotoGalleryModal.vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { Media } from '@/core/types/data/Media';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { usePhotosGalleryStore } from '@/stores/masjid/photosGalleryStore';
import { computed, onBeforeMount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Modal } from 'bootstrap';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import { SweetAlertOptions } from 'sweetalert2';
import ApiService from '@/core/services/ApiService';
import { useMasjidStore } from '@/stores/masjidStore';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import { AxiosError } from 'axios';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';

// Lifecycle hooks
onBeforeMount(async () => {
    await photosGalleryStore.fetchMasjidGalleryPhotos();
});

onMounted(() => {
    photoModal.value = new Modal(document.getElementById('photo_gallery_modal_id') as HTMLElement);
});

// Routing
const router = useRouter()

// Elements
const photoModal = ref<Modal | null>(null);

// Stores
const photosGalleryStore = usePhotosGalleryStore();
const masjidStore = useMasjidStore();

// Computed
const photos = computed(() => {
    if (photosGalleryStore.galleryPhotosPaginated?.data) {
        return photosGalleryStore.galleryPhotosPaginated.data;
    } else {
        return [];
    }
});

// Custom constants
const paginationOptions = ref<PaginationOptions>({
    itemsTotal: photos.value.length,
    currentPage: 1,
    perPage: 8
});
const photoToShow = ref<Media | null>(null);
const isShowModal = ref<boolean>(false);
const modalActionLoading = ref<boolean>(false);
const modalImageFile = ref<File>();

// Methods
const imageModalChange = (data: UploadedImageInfo) => {
    modalImageFile.value = data.file;
}

const pageChange = async (data: PageChangeData) => {
    // Server pagination
    await photosGalleryStore.fetchMasjidGalleryPhotos(data.toPage).then(() => {
        paginationOptions.value.itemsTotal = photosGalleryStore.galleryPhotosPaginated?.total ?? 0;
        paginationOptions.value.currentPage = photosGalleryStore.galleryPhotosPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = photosGalleryStore.galleryPhotosPaginated?.per_page ?? 0;
    });
}

const showPhotoModal = (photo: Media | null = null) => {
    if (photo) {
        photoToShow.value = photo;
        isShowModal.value = true;
        photoModal.value?.show();
    } else {
        photoToShow.value = null;
        isShowModal.value = false;
        photoModal.value?.show();
    }
}

const onSubmit = async () => {

    let swalInstance: SweetAlertOptions = {
        title: 'Info',
        text: 'Nothing',
        icon: 'info'
    };
    let initialMessage = '';

    if (isShowModal.value) {
        initialMessage = 'Do you want to delete this photo?';
    } else {
        initialMessage = 'Do you want to add the selected photo to the gallery?';
    }

    modalActionLoading.value = true;


    QSwal.fire('Question', initialMessage, 'question')
        .then(async result => {
            if (result.isConfirmed) {
                if (!masjidStore.masjid?.id) {
                    swalInstance.title = 'Sorry';
                    swalInstance.text = 'Masjid ID not specified';
                    swalInstance.icon = 'error';
                } else {
                    if (isShowModal.value) {
                        if (!photoToShow.value?.id) {
                            swalInstance.title = 'Sorry';
                            swalInstance.text = 'Photo ID not specified';
                            swalInstance.icon = 'error';
                        } else {
                            await ApiService.delete(`/api/admin/masjids/${masjidStore.masjid.id}/gallery/${photoToShow.value.id}`)
                                .then(res => {
                                    if (res.data?.status === 'success') {
                                        swalInstance.title = 'Success';
                                        swalInstance.text = 'Photo deleted successfully';
                                        swalInstance.icon = 'success';
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
                                });
                        }
                    } else {
                        const formData = new FormData();
                        formData.append('image', modalImageFile.value ?? '');
                        await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/gallery`, formData)
                            .then(res => {
                                if (res.data?.status === 'success') {
                                    swalInstance.title = 'Success';
                                    swalInstance.text = 'Photo added successfully';
                                    swalInstance.icon = 'success';
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
                            });
                    }
                }
            }
        })
        .finally(() => {
            pageChange({ toPage: 1, indicies: null });
            modalActionLoading.value = false;
            MSwal.fire(swalInstance);
            photoModal.value?.hide();
        });
}

</script>

<style scoped>
.img-container {
    border-radius: 1rem;
    overflow: hidden;
    background-color: var(--input-border);
    min-height: 100px;
}

.img-container.active-container:hover {
    cursor: pointer;
    opacity: 0.8;
    transform: scale(0.975);
    transition: all 0.3s;
}

.photo-gallery-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 1rem;
}

.img-a-container {
    height: 200px;
}

.img-b-container {
    height: 300px;
}

.img-c-container {
    height: 65%;
}

.img-d-container {
    height: 35%;
}

@media (min-width: 768px) {
    .w-md-33 {
        width: 33% !important;
    }

    .w-md-50 {
        width: 50% !important;
    }

    .w-md-66 {
        width: 66% !important;
    }
}

@media (max-width: 768px) {

    .img-container,
    .img-a-container,
    .img-b-container,
    .img-c-container,
    .img-d-container {
        height: 300px;
    }
}
</style>