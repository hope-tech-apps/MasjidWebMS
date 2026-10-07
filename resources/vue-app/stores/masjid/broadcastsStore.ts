import { defineStore } from "pinia"
import { ref } from "vue"
import { Broadcast } from "@/core/types/data/masjid-related/Broadcast"
import { useMasjidStore } from "../masjidStore"
import ApiService from "@/core/services/ApiService"
import { AxiosResponse } from "axios"
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData"

/**
 * The unified publish composer's history.
 *
 * The composer posts the send directly. An unstarted scheduled send can be cancelled, even when overdue;
 * the server decides whether it is still waiting and returns its updated audit row.
 */
export const useBroadcastsStore = defineStore('broadcastsStore', () => {

    const broadcastsPaginated = ref<PaginatedData<Broadcast>>()

    const masjidStore = useMasjidStore()

    async function fetchBroadcastsPaginated(page: number = 1) {
        if (!masjidStore.masjid?.id) return
        if (broadcastsPaginated.value) {
            broadcastsPaginated.value.data = []
        }
        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/broadcasts?page=${page}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    broadcastsPaginated.value = res.data.data
                }
            })
            .catch((e: Error) => {
                console.log('Fetch broadcasts error: ', e)
            })
    }

    async function cancelBroadcast(id: number) {
        if (!masjidStore.masjid?.id) throw new Error('Select an organisation before cancelling a broadcast.')
        const res = await ApiService.post(`/api/admin/masjids/${masjidStore.masjid.id}/broadcasts/${id}/cancel`, {})
        return res.data
    }

    return {
        broadcastsPaginated,
        fetchBroadcastsPaginated,
        cancelBroadcast,
    }
})
