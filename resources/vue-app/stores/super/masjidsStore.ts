import ApiService from "@/core/services/ApiService";
import { Masjid } from "@/core/types/data/Masjid";
import { AxiosError, AxiosResponse } from "axios";
import { defineStore } from "pinia";
import { Ref, ref } from "vue";

export const useMasjidsStore = defineStore('masjidsStore', () => {

    const masjids = ref<Array<Masjid>>();

    async function fetchMasjidsList() {
        await ApiService.get('/api/admin/masjids')
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    masjids.value = res.data.data;
                }
            })
            .catch((error: AxiosError) => {
                console.log(error);
            })
    }

    async function fetchMasjid(id: string, masjidTemp: Ref<Masjid|undefined>) {
        if (id) {
            await ApiService.get(`/api/admin/masjids/${id}/`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        masjidTemp.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log(e)
                });
        }
    }

    return { masjids, fetchMasjidsList, fetchMasjid }
    
})