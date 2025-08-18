import ApiService from "@/core/services/ApiService";
import { MasjidDashboardRoute, SuperDashboardRoute } from "@/core/types/config/SystemRoutes";
import { DashboardSearchResultData, DashboardSearchResultRecord, DATA_GENERAL_KEYS, DATA_TO_SHOW_KEYS, RESULT_TITLE_MAP } from "@/core/types/data/custom/DashboardSearch";
import { AxiosResponse } from "axios";
import { defineStore } from "pinia";
import { Ref, ref } from "vue";
import { useMasjidStore } from "@/stores/masjidStore";

export const useDashboardSearchStore = defineStore('dashboardSearchStore', () => {

    // Constants

    // Stores
    const masjidStore = useMasjidStore();

    // Functions
    async function fetchMasjidSearchData(like: string, temp: Ref<DashboardSearchResultData | undefined>) {
        if (masjidStore.masjid?.id) {
            temp.value = undefined;
            await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/search?search_for=${like}`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        temp.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log('Fetch dashboard search results error: ', e)
                });
        }
    }

    async function fetchSuperSearchData(like: string, temp: Ref<DashboardSearchResultData | undefined>) {
        temp.value = undefined;
        await ApiService.get(`/api/admin/search?search_for=${like}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    temp.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.log('Fetch dashboard search results error: ', e)
            });
    }

    const mapResultsDataRecords = (masjidDashboardResultsData: DashboardSearchResultData) => {
        let masjidDashboardResults: DashboardSearchResultRecord[] = [];
        for (let key in masjidDashboardResultsData) {
            const records = masjidDashboardResultsData[key as keyof DashboardSearchResultData];
            if (records && records.length) {
                records.forEach(record => {
                    masjidDashboardResults.push({
                        data_id: record.id,
                        url: getResultUrl(key as keyof DashboardSearchResultData, record?.id),
                        title: RESULT_TITLE_MAP[key as keyof DashboardSearchResultData],
                        data: record
                    });
                });
            }
        }
        return masjidDashboardResults;
    }

    const getResultUrl = (key: keyof DashboardSearchResultData, id: number | undefined): SuperDashboardRoute | MasjidDashboardRoute => {

        let url: SuperDashboardRoute | MasjidDashboardRoute = '/masjid';

        if (DATA_TO_SHOW_KEYS.includes(key)) {
            if (id) {
                switch (key) {
                    case 'announcements':
                        url = `/masjid/announcements/${id}`;
                        break;
                    case 'services':
                        url = `/masjid/services/${id}`;
                        break;
                    case 'azkar':
                        url = `/azkar/${id}`;
                        break;
                    case 'hadith':
                        url = `/hadith/${id}`;
                        break;
                    case 'tasbih':
                        url = `/tasabih/${id}`;
                        break;
                    case 'users':
                        url = `/dashboard/super/users/${id}`;
                        break;
                    case 'masjids':
                        url = `/dashboard/super/masjids/${id}`;
                        break;
                    default:
                        url = '/masjid';
                        break;
                }
            }
        } else if (DATA_GENERAL_KEYS.includes(key)) {
            switch (key) {
                case 'masjidAbout':
                    url = `/masjid/about`;
                    break;
                case 'socialMediaLinks':
                    url = `/masjid/details`;
                    break;
                default:
                    url = '/masjid';
                    break;
            }
        }

        return url;
    }

    return {
        fetchMasjidSearchData,
        fetchSuperSearchData,
        mapResultsDataRecords
    };

});