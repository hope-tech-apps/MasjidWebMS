import ApiService from "@/core/services/ApiService";
import { MasjidAdmin } from "@/core/types/data/Admin";
import { User } from "@/core/types/data/User";
import { AxiosError, AxiosResponse } from "axios";
import { defineStore } from "pinia";
import { Ref, ref } from "vue";

export const useUsersStore = defineStore('usersStore', () => {

    const users = ref<User[]>();

    async function fetchUsersList() {
        users.value = [];
        await ApiService.get('/api/admin/users')
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    users.value = res.data.data;
                }
            })
            .catch((error: AxiosError) => {
                console.log(error);
            })
    }

    async function fetchUser(id: string, userTemp: Ref<User | undefined>) {
        userTemp.value = undefined;
        if (id) {
            await ApiService.get(`/api/admin/users/${id}/`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        userTemp.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log(e)
                });
        }
    }

    async function fetchMasjidAdmins(usersTemp: Ref<MasjidAdmin[] | undefined>) {
        usersTemp.value = [];
        await ApiService.get('/api/admin/admins/masjid/available')
            .then(res => {
                if (res.data?.status === 'success' && res.data?.data) {
                    usersTemp.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.log(e);
            });
    }

    return { users, fetchUsersList, fetchUser, fetchMasjidAdmins }

})