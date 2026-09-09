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
 * Read-only apart from the send itself, which the composer view posts directly
 * (it builds a FormData with an image, exactly like the announcement form).
 * There is no update or delete: a broadcast is a record of something that has
 * already left the building.
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

    return {
        broadcastsPaginated,
        fetchBroadcastsPaginated,
    }
})
