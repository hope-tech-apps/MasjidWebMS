import { defineStore } from "pinia"
import { Ref, ref } from "vue"
import { Service } from "@/core/types/data/masjid-related/Service"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";

export const useServicesStore = defineStore('servicesStore', () => {

    // Constants
    const servicesPaginated = ref<PaginatedData<Service>>();

    // Stores
    const masjidStore = useMasjidStore();

    async function fetchMasjidServicesPaginated(page: number = 1) {

        if (masjidStore.masjid?.id) {
            if (servicesPaginated.value) {
                servicesPaginated.value.data = [];
            }
            await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/services?page=${page}`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        servicesPaginated.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log('Fetch masjid services error: ', e)
                });
        }
    }

    async function fetchService(id: number | string, serviceTemp: Ref<Service | undefined>) {
        if (masjidStore.masjid?.id) {
            await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/services/${id}/`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        serviceTemp.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log('Fetch masjid services error: ', e)
                });
        }
    }

    return { servicesPaginated, fetchMasjidServicesPaginated, fetchService }
})