import { defineStore } from "pinia"
import { Ref, ref } from "vue"
import { Event } from "@/core/types/data/masjid-related/Event"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";

export const useEventsStore = defineStore('eventsStore', () => {

    // Constants
    const eventsPaginated = ref<PaginatedData<Event>>();

    // Stores
    const masjidStore = useMasjidStore();

    async function fetchMasjidEventsPaginated(page:number=1) {
        if(masjidStore.masjid?.id) {
            if(eventsPaginated.value) {
                eventsPaginated.value.data = [];
            }
            await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/events?page=${page}`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        eventsPaginated.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log('Fetch masjid events error: ',e)
                });
        }
    }

    async function fetchEvent(id:number|string, eventTemp:Ref<Event|undefined>) {
        if(masjidStore.masjid?.id) {
            await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/events/${id}/`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        eventTemp.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log('Fetch masjid events error: ',e)
                });
        }
    }

    return { eventsPaginated, fetchMasjidEventsPaginated, fetchEvent }
})