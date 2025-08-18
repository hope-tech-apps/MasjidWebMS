import ApiService from "@/core/services/ApiService"
import { SystemRoute } from "@/core/types/config/SystemRoutes"
import { Masjid } from "@/core/types/data/Masjid"
import { AxiosResponse } from "axios"
import { defineStore } from "pinia"
import { ref } from "vue"
import { useAuthStore } from "./authStore"
import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants"

export const useMasjidStore = defineStore('masjidStore', () => {

    // Constants
    const masjid = ref<Masjid | null>()

    // Stores
    const authStore = useAuthStore();

    async function fetchMasjid(id: number | string | null = null): Promise<SystemRoute | void> {

        let idToFetch = id;

        if ((!idToFetch) && authStore.isAuthenticated) {
            if(authStore.dashboardMasjidId) {
                idToFetch = authStore.dashboardMasjidId;
            } else {
                idToFetch = localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);
            }
        }

        if (idToFetch) {
            await ApiService.get(`/api/admin/masjids/${idToFetch}/`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        masjid.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log(e)
                });
        }
    }

    return { masjid, fetchMasjid }
})