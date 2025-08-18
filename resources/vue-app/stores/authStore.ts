import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants";
import ApiService from "@/core/services/ApiService";
import { SystemRoute } from "@/core/types/config/SystemRoutes";
import { Admin } from "@/core/types/data/Admin";
import { AxiosError, AxiosResponse } from "axios";
import { defineStore } from "pinia";
import { ref } from "vue";
import { useMasjidStore } from "@/stores/masjidStore";
import { MSwal } from "@/core/plugins/SweetAlerts2";
import { getMessageFromObj } from "@/assets/ts/swalMethods";
import { BackendResponseData } from "@/core/types/config/AxiosCustom";

export const useAuthStore = defineStore('authStore', () => {

    // Constants
    const user = ref<Admin | null>(null);
    const isAuthenticated = ref<boolean>(false);
    const token = ref<string | null>(null);
    const dashboardMasjidId = ref<number | string | null>(null);

    // Stores
    const masjidStore = useMasjidStore();

    async function authenticate() {
        if (token.value && user.value?.id) {
            localStorage.setItem(LOCAL_STORAGE_KEYS.token, token.value);
            isAuthenticated.value = true;
            ApiService.setHeader(token.value);
        } else {
            removeAuth();
        }
    }

    function removeAuth() {
        localStorage.removeItem(LOCAL_STORAGE_KEYS.token);
        localStorage.removeItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);
        isAuthenticated.value = false;
        user.value = null;
        token.value = null;
        dashboardMasjidId.value = null;
        masjidStore.masjid = null;
        ApiService.setHeader();
    }

    function saveDashboardMasjidId(id: number | string) {
        dashboardMasjidId.value = id;
        localStorage.setItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id, (id + ''));
    }

    async function login(email: string, password: string): Promise<SystemRoute | void> {

        const formdata = new FormData();
        formdata.append('email', email);
        formdata.append('password', password);

        await ApiService.post('/api/admin/login', formdata)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    user.value = res.data?.data?.user ?? null;
                    token.value = res.data?.data?.token ?? "";
                } else {
                    MSwal.fire('Sorry', getMessageFromObj(res), 'error');
                }
            })
            .catch((error: AxiosError<BackendResponseData>) => {
                console.log(error);
                MSwal.fire('Sorry', getMessageFromObj(error), 'error');
            })
            .finally(() => {
                authenticate()
            });

    }

    async function fetchAuthUser(): Promise<SystemRoute | void> {
        await ApiService.get('/api/admin/user')
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    user.value = res.data.data;
                    if (user.value?.type === 'SuperAdmin') {
                        let expectedMasjidId = localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id)
                        if (expectedMasjidId)
                            dashboardMasjidId.value = parseInt(expectedMasjidId);
                    } else if (user.value?.type === 'MasjidAdmin' && user.value.masjid?.id) {
                        saveDashboardMasjidId(user.value.masjid.id);
                    }
                }
            })
            .catch((e: Error) => {
                console.log(e);
                throw e;
            });
    }

    async function logout(): Promise<SystemRoute | void> {
        await ApiService.post('/api/admin/logout', null)
            .finally(() => {
                removeAuth()
            });
    }

    return { user, isAuthenticated, token, dashboardMasjidId, login, fetchAuthUser, authenticate, logout, removeAuth, saveDashboardMasjidId }
})