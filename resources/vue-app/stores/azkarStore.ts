import { defineStore } from "pinia"
import { Ref, ref } from "vue"
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { AzkarCategory, Zikr } from "@/core/types/data/Azkar";

export const useAzkarStore = defineStore('azkarStore', () => {

    // Constants
    const azkarPaginated = ref<PaginatedData<Zikr>>();
    const categories = ref<AzkarCategory[]>();

    async function fetchAzkarPaginated(page: number = 1) {

        if (azkarPaginated.value?.data) {
            azkarPaginated.value.data = [];
        }

        await ApiService.get(`/api/admin/azkar?page=${page}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    console.log('data: ', res.data.data);

                    azkarPaginated.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.log('Fetch azkar error: ', e)
            });

    }

    async function fetchZikr(id: number | string, zikrTemp: Ref<Zikr | undefined>) {
        await ApiService.get(`/api/admin/azkar/${id}/`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    zikrTemp.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.log('Fetch zikr error: ', e)
            });
    }

    async function fetchAzkarCategories() {

        if (categories.value) {
            categories.value = [];
        }

        await ApiService.get(`/api/admin/azkar/categories`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    console.log('data: ', res.data.data);

                    categories.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.log('Fetch azkar error: ', e)
            });
        
    }

    return { azkarPaginated, categories, fetchAzkarPaginated, fetchZikr, fetchAzkarCategories }
})