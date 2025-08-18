import ApiService from "@/core/services/ApiService";
import { Masjid } from "@/core/types/data/Masjid";
import { MobileAppFeature } from "@/core/types/data/MobileAppFeature";
import { AxiosError, AxiosResponse } from "axios";
import { defineStore } from "pinia";
import { ref } from "vue";
import { useMasjidStore } from "../masjidStore";

export const useMobileAppFeaturesStore = defineStore('mobileAppFeaturesStore', () => {

    const features = ref<MobileAppFeature[]>();

    const masjidStore = useMasjidStore();

    async function fetchMobileAppFeatures() {
        features.value = [];
        if (masjidStore.masjid?.id) {
            await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/features`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        features.value = res.data.data;
                    }
                })
                .catch((error: AxiosError) => {
                    console.log(error);
                });
        }
    }

    return { features, fetchMobileAppFeatures }

})