import { defineStore } from "pinia"
import { Ref, ref } from "vue"
import { Announcement } from "@/core/types/data/masjid-related/Announcement"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";

export const useAnnouncementsStore = defineStore('announcementsStore', () => {

    // Constants
    const announcementsPaginated = ref<PaginatedData<Announcement>>();

    // Stores
    const masjidStore = useMasjidStore();

    async function fetchMasjidAnnouncementsPaginated(page:number=1) {
        if(masjidStore.masjid?.id) {
            if(announcementsPaginated.value) {
                announcementsPaginated.value.data = [];
            }
            await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/announcements?page=${page}`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        announcementsPaginated.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log('Fetch masjid announcements error: ',e)
                });
        }
    }

    async function fetchAnnouncement(id:number|string, announcementTemp:Ref<Announcement|undefined>) {
        if(masjidStore.masjid?.id) {
            await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/announcements/${id}/`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        announcementTemp.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log('Fetch masjid announcements error: ',e)
                });
        }
    }

    return { announcementsPaginated, fetchMasjidAnnouncementsPaginated, fetchAnnouncement }
})