import { defineStore } from "pinia"
import { Ref, ref } from "vue"
import { SplashAnnouncement } from "@/core/types/data/masjid-related/SplashAnnouncement"
import { useMasjidStore } from "../masjidStore"
import ApiService from "@/core/services/ApiService"
import { AxiosResponse } from "axios"
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData"

/**
 * Mirrors announcementsStore in shape so the views feel consistent.
 * Single masjid scope — all calls hang off useMasjidStore().masjid.id.
 */
export const useSplashAnnouncementsStore = defineStore('splashAnnouncementsStore', () => {

    const splashesPaginated = ref<PaginatedData<SplashAnnouncement>>()

    const masjidStore = useMasjidStore()

    async function fetchSplashesPaginated(page: number = 1) {
        if (!masjidStore.masjid?.id) return
        if (splashesPaginated.value) {
            splashesPaginated.value.data = []
        }
        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/splash-announcements?page=${page}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    splashesPaginated.value = res.data.data
                }
            })
            .catch((e: Error) => {
                console.log('Fetch splashes error: ', e)
            })
    }

    async function fetchSplash(id: number | string, target: Ref<SplashAnnouncement | undefined>) {
        if (!masjidStore.masjid?.id) return
        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/splash-announcements/${id}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    target.value = res.data.data
                }
            })
            .catch((e: Error) => {
                console.log('Fetch splash error: ', e)
            })
    }

    async function deleteSplash(id: number | string) {
        if (!masjidStore.masjid?.id) return
        await ApiService.delete(`/api/admin/masjids/${masjidStore.masjid.id}/splash-announcements/${id}`)
    }

    return {
        splashesPaginated,
        fetchSplashesPaginated,
        fetchSplash,
        deleteSplash,
    }
})
