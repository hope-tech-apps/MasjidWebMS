import { defineStore } from "pinia"
import { Ref, ref } from "vue"
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { Hadith } from "@/core/types/data/Hadith";

export const useHadithStore = defineStore('hadithStore', () => {

    // Constants
    const hadithsPaginated = ref<PaginatedData<Hadith>>();

    async function fetchHadithsPaginated(page: number = 1) {

        if (hadithsPaginated.value?.data) {
            hadithsPaginated.value.data = [];
        }

        await ApiService.get(`/api/admin/hadiths?page=${page}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    console.log('data: ', res.data.data);
                    
                    hadithsPaginated.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.log('Fetch hadiths error: ', e)
            });
    }

    async function fetchHadith(id: number | string, hadithTemp: Ref<Hadith|undefined>) {
        await ApiService.get(`/api/admin/hadiths/${id}/`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    hadithTemp.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.log('Fetch hadith error: ', e)
            });
    }

    return { hadithsPaginated, fetchHadithsPaginated, fetchHadith }
})