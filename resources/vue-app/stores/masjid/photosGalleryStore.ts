import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { Media } from "@/core/types/data/Media";

export const usePhotosGalleryStore = defineStore('photosGalleryStore', () => {

    // Constants
    const galleryPhotosPaginated = ref<PaginatedData<Media>>();

    // Stores
    const masjidStore = useMasjidStore();

    async function fetchMasjidGalleryPhotos(page: number = 1) {
        if(galleryPhotosPaginated.value?.data) {
            galleryPhotosPaginated.value.data = [];
        }
        if (masjidStore.masjid?.id) {
            await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/gallery/?page=${page}`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data as PaginatedData<Media>) {
                        galleryPhotosPaginated.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log('Fetch masjid gallery error: ', e)
                });
        }
    }

    return { galleryPhotosPaginated, fetchMasjidGalleryPhotos }
})