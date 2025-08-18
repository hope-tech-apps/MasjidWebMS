import { defineStore } from "pinia"
import { Ref, ref } from "vue"
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { Hadith } from "@/core/types/data/Hadith";
import { Tasbih } from "@/core/types/data/Tasabih";

export const useTasabihStore = defineStore('tasabihStore', () => {

    // Constants
    const tasabihPaginated = ref<PaginatedData<Tasbih>>();

    async function fetchTasabihPaginated(page: number = 1) {

        if (tasabihPaginated.value?.data) {
            tasabihPaginated.value.data = [];
        }

        await ApiService.get(`/api/admin/tasabih?page=${page}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    console.log('data: ', res.data.data);
                    
                    tasabihPaginated.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.log('Fetch tasabih error: ', e)
            });
            
    }

    async function fetchTasbih(id: number | string, tasbihTemp: Ref<Tasbih|undefined>) {
        await ApiService.get(`/api/admin/tasabih/${id}/`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    tasbihTemp.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.log('Fetch tasbih error: ', e)
            });
    }

    return { tasabihPaginated, fetchTasabihPaginated, fetchTasbih }
})